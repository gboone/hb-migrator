<?php

namespace HBMigrator\Destination;

use HBMigrator\ApiAuth;
use HBMigrator\MigrationRegistry;

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
}
