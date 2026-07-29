<?php

namespace HBMigrator\Destination;

use HBMigrator\ApiAuth;
use HBMigrator\MigrationRegistry;
use HBMigrator\SourceClient;

/**
 * U8's inbound endpoint: source-side content-event hooks (Source\SyncWebhook) call this to
 * nudge destination into an immediate sync pass rather than waiting for U7's cron tick.
 *
 * Two-layer auth, per plan "Key Technical Decisions" ("The webhook receiver requires a
 * per-site-job token, not just the shared destination API key"): ApiAuth::verify_request()
 * (the same Bearer-token check every other endpoint in this plugin uses) gates the request
 * first via permission_callback, then this class's own hash_equals() check against the
 * per-site-job sync_webhook_token (U1) gates which site_job_id the caller may act on —
 * mirrors MigrationReceiver::cancel()'s status_token + hash_equals() IDOR guard
 * (includes/destination/class-migration-receiver.php), the exact precedent for this shape:
 * a valid destination API key alone must not be enough to trigger or probe sync passes for
 * a site job the caller doesn't own.
 *
 * Enqueues async work rather than running the pass inline — mirrors MigrationReceiver::begin()'s
 * enqueue-then-respond pattern — so a large or slow sync pass never times out this HTTP
 * request or leaves the source-side scheduled callback waiting on our response.
 *
 * ---
 * Code-review addition: enable_sync() / finalize_sync() give "Enable Sync" and "Finalize &
 * Stop Sync" a REST equivalent alongside sync-notify above. Every other cross-install
 * mutation in this plugin (begin, cancel) already has a REST endpoint; these two lifecycle
 * transitions were previously reachable only via the wp-admin form buttons in
 * Admin\AdminPage::handle_enable_sync() / handle_finalize_sync(). Both new routes are gated
 * by the standard ApiAuth::verify_request() check alone — unlike sync-notify's extra
 * sync_webhook_token layer, these are operator/script-initiated same-install destination
 * actions, not calls from an unattended external source-side hook, so the plan's rationale
 * for sync-notify's second token (an externally-triggered call needs per-resource scoping
 * beyond the shared API key) does not apply here: the shared destination API key is the same
 * trust boundary MigrationReceiver::begin()/cancel() already rely on for equivalent actions.
 * Each callback calls the same MigrationRegistry methods the admin handlers call and
 * replicates their full side-effect chain (SyncScheduler schedule/unschedule,
 * deliver_sync_webhook_token()) so a REST-triggered call behaves identically to a
 * button-triggered one — only the wp-admin-specific nonce/redirect plumbing is left out.
 */
class SyncReceiver {

	public static function register_routes(): void {
		$ns   = HBM_API_NAMESPACE;
		$auth = fn( \WP_REST_Request $r ) => ApiAuth::verify_request( $r );

		register_rest_route( $ns, '/destination/site-jobs/(?P<site_job_id>\d+)/sync-notify', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'notify' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/destination/site-jobs/(?P<site_job_id>\d+)/enable-sync', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'enable_sync' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/destination/site-jobs/(?P<site_job_id>\d+)/finalize-sync', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'finalize_sync' ],
			'permission_callback' => $auth,
		] );
	}

	public static function notify( \WP_REST_Request $request ): \WP_REST_Response {
		$site_job_id = (int) $request->get_param( 'site_job_id' );
		$job         = $site_job_id ? MigrationRegistry::get_site_job( $site_job_id ) : null;

		if ( ! $job ) {
			return new \WP_REST_Response( [ 'error' => 'Site job not found.' ], 404 );
		}

		// IDOR guard — see class docblock. Checked before the status check below so a
		// caller without the right token learns nothing about the job's current state.
		$provided_token = sanitize_text_field( (string) $request->get_param( 'sync_webhook_token' ) );
		if ( empty( $job->sync_webhook_token ) || ! hash_equals( (string) $job->sync_webhook_token, $provided_token ) ) {
			return new \WP_REST_Response( [ 'error' => 'Forbidden.' ], 403 );
		}

		if ( 'syncing' !== $job->status ) {
			// Not yet enabled, already finalized, or cancelled mid-way — reject explicitly
			// rather than silently accepting (plan U8 test scenario: "A webhook call for a
			// site job that isn't 'syncing' ... is rejected by the receiver").
			return new \WP_REST_Response( [ 'error' => 'Site job is not currently syncing.' ], 403 );
		}

		// Do NOT call SyncDispatcher::run_sync_pass() inline — enqueue and respond
		// immediately (plan "Key Technical Decisions": "the webhook receiver enqueues async
		// work rather than running a pass inline"). SyncDispatcher::run_sync_pass() itself
		// re-checks status and the lock, so a call that races a cron-triggered pass already
		// holding the lock is skipped cleanly by the dispatcher, not by this receiver.
		as_enqueue_async_action( SyncDispatcher::ACTION_HOOK, [ 'site_job_id' => $site_job_id ], 'hb-migrator' );

		return new \WP_REST_Response( [ 'status' => 'queued' ], 200 );
	}

	/**
	 * REST equivalent of Admin\AdminPage::handle_enable_sync() — see class docblock. Rejects
	 * exactly the same cases that handler does, in the same order, so the two paths are
	 * indistinguishable in behavior: unknown job (404), job not 'complete' (400), destination
	 * subsite missing/deleted (400), and a status change racing this request (409 — the REST
	 * analogue of the handler's redirect-with-sync_error for the same race). On success,
	 * replicates the handler's full side-effect chain: MigrationRegistry::enable_site_job_sync()
	 * transitions the job, SyncScheduler::schedule() registers the U7 cron safety net, and
	 * deliver_sync_webhook_token() pushes the fresh token to source exactly as the button does.
	 */
	public static function enable_sync( \WP_REST_Request $request ): \WP_REST_Response {
		$site_job_id = (int) $request->get_param( 'site_job_id' );
		$job         = $site_job_id ? MigrationRegistry::get_site_job( $site_job_id ) : null;

		if ( ! $job ) {
			return new \WP_REST_Response( [ 'error' => 'Site job not found.' ], 404 );
		}

		if ( 'complete' !== $job->status ) {
			return new \WP_REST_Response( [ 'error' => 'Sync can only be enabled for a site job whose initial migration is complete.' ], 400 );
		}

		// Mirrors TermImporter::process()'s dest_site/deleted check and
		// Admin\AdminPage::handle_enable_sync()'s identical guard.
		$dest_site = $job->dest_blog_id ? get_site( (int) $job->dest_blog_id ) : null;
		if ( ! $dest_site || (int) $dest_site->deleted ) {
			return new \WP_REST_Response( [
				'error' => sprintf( 'Cannot enable sync: the destination subsite for %s no longer exists.', $job->source_domain ),
			], 400 );
		}

		if ( ! MigrationRegistry::enable_site_job_sync( $site_job_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Sync could not be enabled — the site job status changed before this request completed.' ], 409 );
		}

		// U7: register the cron safety-net pass now that the job is actually 'syncing' — same
		// call Admin\AdminPage::handle_enable_sync() makes right after the same transition.
		SyncScheduler::schedule( $site_job_id );
		self::deliver_sync_webhook_token( $site_job_id );

		$job = MigrationRegistry::get_site_job( $site_job_id );
		return new \WP_REST_Response( [
			'status'          => $job->status,
			'site_job_id'     => $site_job_id,
			'sync_enabled_at' => $job->sync_enabled_at,
		], 200 );
	}

	/**
	 * REST equivalent of Admin\AdminPage::handle_finalize_sync() — see class docblock. Same
	 * rejection shape as enable_sync() above: unknown job (404), job not 'syncing' (400), and
	 * the finalize-specific race MigrationRegistry::finalize_site_job_sync() itself guards
	 * against — an in-flight sync pass still holding a fresh lock (409, the REST analogue of
	 * the handler's redirect-with-sync_error for the same case; see that method's docblock).
	 * On success, replicates the handler's side effect: SyncScheduler::unschedule() stops the
	 * U7 cron safety net for this job (an in-flight pass, if any, is not aborted — only future
	 * scheduling).
	 */
	public static function finalize_sync( \WP_REST_Request $request ): \WP_REST_Response {
		$site_job_id = (int) $request->get_param( 'site_job_id' );
		$job         = $site_job_id ? MigrationRegistry::get_site_job( $site_job_id ) : null;

		if ( ! $job ) {
			return new \WP_REST_Response( [ 'error' => 'Site job not found.' ], 404 );
		}

		if ( 'syncing' !== $job->status ) {
			return new \WP_REST_Response( [ 'error' => 'Sync can only be finalized for a site job that is currently syncing.' ], 400 );
		}

		if ( ! MigrationRegistry::finalize_site_job_sync( $site_job_id ) ) {
			return new \WP_REST_Response( [ 'error' => 'Sync could not be finalized — the site job status changed before this request completed.' ], 409 );
		}

		// U7: stop the cron safety-net pass — same call Admin\AdminPage::handle_finalize_sync()
		// makes right after the same transition. Does not abort an in-flight pass.
		SyncScheduler::unschedule( $site_job_id );

		// U8: sync is finalized for this site job, so its audit report is no longer needed.
		AuditReport::delete_for_site_job( $site_job_id );

		$job = MigrationRegistry::get_site_job( $site_job_id );
		return new \WP_REST_Response( [
			'status'            => $job->status,
			'site_job_id'       => $site_job_id,
			'sync_finalized_at' => $job->sync_finalized_at,
		], 200 );
	}

	/**
	 * Best-effort push of this job's freshly-generated sync_webhook_token to source (U8's
	 * token-delivery gap — see Source\SyncWebhook's class docblock for the full rationale).
	 * Public and canonical: both enable_sync() above (the REST-triggered path) and
	 * Admin\AdminPage::handle_enable_sync() (the wp-admin button path) call this same method,
	 * so the two Enable Sync entry points can't drift on this side effect the way an earlier,
	 * admin-page-only copy of this logic did before a code-review pass consolidated them.
	 *
	 * On confirmed successful delivery (SourceClient::post() returns without throwing — i.e.
	 * source's receive_token() responded with HTTP 200) this stamps
	 * sync_webhook_token_delivered_at on the site job. Source and destination are separate
	 * WordPress installs communicating only over this REST call (see
	 * MigrationReceiver/SourceClient's cross-install credential handling), so this timestamp
	 * can only be written from the destination side that made the call and observed the
	 * outcome — NOT from Source\SyncWebhook::receive_token(), which runs on the source install
	 * against its own local (and, for this site_job_id, unrelated) hbm_site_jobs table. Without
	 * this, a delivery failure silently degraded a site job to cron-only sync (U7's 15-minute
	 * safety net still runs) with no signal beyond the error_log() call below; an operator or a
	 * future admin-page display can now tell "token confirmed delivered" apart from "never
	 * confirmed" for a given site job. Failure remains non-fatal and does not block sync being
	 * enabled: cron does not depend on this token at all, only the webhook's low-latency path
	 * does.
	 */
	public static function deliver_sync_webhook_token( int $site_job_id ): void {
		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || empty( $job->sync_webhook_token ) ) {
			return;
		}

		$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
		if ( ! $migration || empty( $migration->source_url ) || empty( $migration->source_api_key ) ) {
			return;
		}

		try {
			SourceClient::post( $migration->source_url, $migration->source_api_key, 'source/sync-webhook-token', [
				'blog_id'            => (int) $job->source_blog_id,
				'site_job_id'        => $site_job_id,
				'sync_webhook_token' => $job->sync_webhook_token,
			] );
			MigrationRegistry::update_site_job( $site_job_id, [
				'sync_webhook_token_delivered_at' => current_time( 'mysql', true ),
			] );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: failed to deliver sync_webhook_token to source for site job ' . $site_job_id . ': ' . $e->getMessage() );
		}
	}
}
