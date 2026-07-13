<?php

namespace HBMigrator\Source;

/**
 * Source-side wiring for U8's webhook trigger. Hooks into content events that should nudge
 * destination into an immediate sync pass (R9), plus the two comment-only events that are
 * the *sole* path for edits/moderation-status changes to reach destination at all — cron
 * (U7) has no modified-timestamp column to poll for comments, per the plan's "Key Technical
 * Decisions" ("Comment edits and moderation-status changes are webhook-only").
 *
 * Two distinct problems, two distinct handlers, per the plan's "Approach": save_post also
 * fires for autosave and revision-save events, which are not user-intended publish actions
 * and must never trigger a webhook call at all — guarded with wp_is_post_autosave() /
 * wp_is_post_revision(), the standard WordPress pattern for exactly this problem. Separately,
 * a genuine save's multiple save_post firings within the same PHP request (the revision
 * insert, then the actual post update) are coalesced into a single webhook call via a short
 * debounce window, implemented with wp_schedule_single_event() — cancelled and rescheduled
 * on every new qualifying event for the same site, so the delay resets rather than stacking.
 *
 * ---
 * Token-delivery gap (closed by this unit, not specified by the plan text itself):
 *
 * The webhook call this class fires needs the destination's per-site-job sync_webhook_token
 * (U1) and the site_job_id it belongs to, but "Enable Sync" (U1's AdminPage::handle_enable_sync())
 * is a destination-only admin action with no existing channel telling source it happened —
 * source's own status-poll loop (SiteIndex::proxy_migration_status(), assets/js/admin.js)
 * stops entirely once the initial migration reaches 'complete' (see admin.js's
 * `if (allDone) return`), well before "Enable Sync" is ever clicked.
 *
 * Closed with the most minimal mechanism consistent with the plan's existing patterns:
 * AdminPage::handle_enable_sync() (destination) now calls a new source-side endpoint,
 * POST /source/sync-webhook-token (registered in SourceEndpoints::register_routes(),
 * handled by self::receive_token() below), delivering { blog_id, site_job_id,
 * sync_webhook_token }. That call is authenticated with the migration's own
 * source_api_key — the SAME credential every existing destination-to-source call already
 * uses via SourceClient — deliberately reusing the trust the two installs already share
 * rather than inventing a new bearer token. Delivery is best-effort (failure does not block
 * Enable Sync itself; only the webhook's low-latency path depends on it, cron (U7) does not).
 * receive_token() stores { site_job_id, token } per-blog via update_blog_option(), and
 * get_sync_config() below reads it back for the blog the triggering hook fired in.
 */
class SyncWebhook {

	/**
	 * Hook a scheduled debounce callback fires under. Registered here (not in
	 * Plugin::register_action_hooks(), which is exclusively for the destination-side
	 * Action Scheduler hooks) because this is a plain wp_schedule_single_event() callback,
	 * not an Action Scheduler action — this plugin's source side has no existing scheduled-
	 * dispatch infrastructure to register this under, so self::init() owns its own hook.
	 */
	public const SEND_HOOK = 'hbm_send_sync_webhook';

	/**
	 * A genuine save's multiple save_post firings (revision insert, then the actual post
	 * update) happen within the same PHP request, milliseconds apart — a few seconds is
	 * long enough to coalesce those into one call while still being "near-immediate" for R9.
	 */
	private const DEBOUNCE_SECONDS = 5;

	public static function init(): void {
		add_action( 'save_post', [ self::class, 'on_save_post' ] );
		add_action( 'wp_insert_comment', [ self::class, 'on_new_comment' ] );
		add_action( 'edit_comment', [ self::class, 'on_comment_changed' ] );
		add_action( 'transition_comment_status', [ self::class, 'on_transition_comment_status' ], 10, 3 );
		add_action( 'add_attachment', [ self::class, 'on_add_attachment' ] );
		add_action( self::SEND_HOOK, [ self::class, 'send_webhook' ] );
	}

	// -----------------------------------------------------------------------
	// Content-event hooks
	// -----------------------------------------------------------------------

	public static function on_save_post( int $post_id ): void {
		// Autosave and revision-save events are not user-intended publish actions — the
		// standard WordPress guard for excluding them entirely, not just debouncing them.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::debounce();
	}

	public static function on_new_comment( int $comment_id ): void {
		self::debounce();
	}

	public static function on_comment_changed( int $comment_id ): void {
		self::debounce();
	}

	public static function on_transition_comment_status( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( $new_status === $old_status ) {
			return;
		}
		self::debounce();
	}

	public static function on_add_attachment( int $attachment_id ): void {
		self::debounce();
	}

	// -----------------------------------------------------------------------
	// Debounce
	// -----------------------------------------------------------------------

	/**
	 * Cancels any pending scheduled webhook call for the current blog and reschedules it
	 * DEBOUNCE_SECONDS out, so a burst of qualifying events collapses into a single call —
	 * every new event resets the delay rather than stacking additional scheduled callbacks.
	 * A no-op when sync hasn't been enabled (no token on file) for this blog, so the hooks
	 * in init() are safe to register unconditionally on every install.
	 */
	private static function debounce(): void {
		$blog_id = get_current_blog_id();

		if ( ! self::get_sync_config( $blog_id ) ) {
			return;
		}

		$args     = [ 'blog_id' => $blog_id ];
		$existing = wp_next_scheduled( self::SEND_HOOK, $args );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::SEND_HOOK, $args );
		}
		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::SEND_HOOK, $args );
	}

	/**
	 * The debounce callback: fires the actual HTTP call to destination's sync-notify
	 * endpoint (SyncReceiver::notify()). Runs from the scheduled event, not inline in the
	 * triggering hook, so a slow or failed HTTP call never blocks the request that edited
	 * a post or added a comment.
	 */
	public static function send_webhook( int $blog_id ): void {
		$config = self::get_sync_config( $blog_id );
		if ( ! $config ) {
			return;
		}

		$dest_url = get_site_option( 'hbm_dest_url', '' );
		$dest_key = get_site_option( 'hbm_dest_key', '' );
		if ( ! $dest_url || ! $dest_key ) {
			return;
		}

		$url = trailingslashit( $dest_url ) . 'wp-json/' . HBM_API_NAMESPACE
			. '/destination/site-jobs/' . (int) $config['site_job_id'] . '/sync-notify';

		wp_remote_post( $url, [
			'headers'   => [
				'Authorization' => 'Bearer ' . $dest_key,
				'Content-Type'  => 'application/json',
			],
			'body'      => wp_json_encode( [ 'sync_webhook_token' => $config['token'] ] ),
			'timeout'   => 10,
			'sslverify' => true,
		] );
		// Fire-and-forget: the receiver enqueues async work and responds immediately (U8),
		// and this call itself already runs from a scheduled event rather than inline in a
		// user-facing request, so there is nothing useful to do with the response here.
	}

	// -----------------------------------------------------------------------
	// Token delivery — receiving end of the gap closed above.
	// -----------------------------------------------------------------------

	/**
	 * Handles POST /source/sync-webhook-token. Registered in
	 * SourceEndpoints::register_routes() alongside every other source-side route, gated by
	 * the same ApiAuth::verify_request() Bearer-token check every other source endpoint
	 * uses — the caller (destination) authenticates with the migration's source_api_key,
	 * the exact credential destination already holds and already uses for every other
	 * source-fetching call in this plugin.
	 */
	public static function receive_token( \WP_REST_Request $request ): \WP_REST_Response {
		$blog_id     = (int) $request->get_param( 'blog_id' );
		$site_job_id = (int) $request->get_param( 'site_job_id' );
		$token       = sanitize_text_field( (string) $request->get_param( 'sync_webhook_token' ) );

		if ( ! $blog_id || ! get_site( $blog_id ) || ! $site_job_id || ! $token ) {
			return new \WP_REST_Response( [ 'error' => 'blog_id, site_job_id, and sync_webhook_token are required.' ], 400 );
		}

		update_blog_option( $blog_id, 'hbm_sync_config', [
			'site_job_id' => $site_job_id,
			'token'       => $token,
		] );

		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	/**
	 * @return array{site_job_id:int,token:string}|null Null when sync hasn't been enabled
	 *                                                   (or the token hasn't arrived yet) for
	 *                                                   this blog.
	 */
	private static function get_sync_config( int $blog_id ): ?array {
		$config = get_blog_option( $blog_id, 'hbm_sync_config' );
		if ( empty( $config['site_job_id'] ) || empty( $config['token'] ) ) {
			return null;
		}
		return $config;
	}
}
