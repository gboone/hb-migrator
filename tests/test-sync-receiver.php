<?php
/**
 * Tests for Destination\SyncReceiver::notify() — U8's inbound webhook endpoint.
 *
 * Auth is two-layered (plan "Key Technical Decisions"): ApiAuth::verify_request() gates the
 * route itself via permission_callback — tested here with a full REST dispatch, mirroring
 * tests/test-destination-preflight.php's "no auth" pattern. This class's own hash_equals()
 * check against the per-site-job sync_webhook_token is exercised by calling notify()
 * directly, mirroring tests/test-migration-receiver.php's direct-call style for cancel().
 */

use HBMigrator\Destination\SyncDispatcher;
use HBMigrator\Destination\SyncReceiver;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_SyncReceiver extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return array{0:int,1:string} [ site_job_id, sync_webhook_token ]
	 */
	private function make_syncing_job(): array {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [
			'status'       => 'complete',
			'dest_blog_id' => get_current_blog_id(),
		] );
		MigrationRegistry::enable_site_job_sync( $jid );
		$job = MigrationRegistry::get_site_job( $jid );
		return [ $jid, $job->sync_webhook_token ];
	}

	private function notify_request( int $site_job_id, string $token ): \WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $site_job_id . '/sync-notify' );
		$req->set_param( 'site_job_id', $site_job_id );
		$req->set_param( 'sync_webhook_token', $token );
		return SyncReceiver::notify( $req );
	}

	private function pending_actions_for( int $site_job_id ): array {
		$scheduled = as_get_scheduled_actions( [
			'hook'     => SyncDispatcher::ACTION_HOOK,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		] );
		return array_values( array_filter( $scheduled, function ( $action ) use ( $site_job_id ) {
			$args = $action->get_args();
			return isset( $args['site_job_id'] ) && (int) $args['site_job_id'] === $site_job_id;
		} ) );
	}

	// -------------------------------------------------------------------------
	// Route registration + ApiAuth layer
	// -------------------------------------------------------------------------

	public function test_sync_notify_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . HBM_API_NAMESPACE . '/destination/site-jobs/(?P<site_job_id>\d+)/sync-notify', $routes );
	}

	public function test_sync_notify_endpoint_with_no_auth_returns_error(): void {
		[ $jid, $token ] = $this->make_syncing_job();

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $jid . '/sync-notify' );
		$req->set_param( 'sync_webhook_token', $token );
		// No Authorization header — ApiAuth::verify_request() should fail via permission_callback,
		// before this class's own sync_webhook_token check is ever reached.
		$response = rest_get_server()->dispatch( $req );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertEmpty( $this->pending_actions_for( $jid ), 'A rejected request must have zero side effects.' );
	}

	public function test_sync_notify_endpoint_with_valid_auth_and_token_succeeds(): void {
		[ $jid, $token ] = $this->make_syncing_job();

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $jid . '/sync-notify' );
		$req->set_param( 'sync_webhook_token', $token );
		$req->set_header( 'Authorization', 'Bearer ' . \HBMigrator\ApiAuth::get_or_create_key() );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $this->pending_actions_for( $jid ) );
	}

	// -------------------------------------------------------------------------
	// sync_webhook_token IDOR guard — a valid destination API key alone must not be enough.
	// -------------------------------------------------------------------------

	public function test_notify_returns_404_for_unknown_site_job(): void {
		$response = $this->notify_request( 999999, 'anything' );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_notify_returns_403_when_token_missing(): void {
		[ $jid ] = $this->make_syncing_job();
		$response = $this->notify_request( $jid, '' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->pending_actions_for( $jid ) );
	}

	public function test_notify_returns_403_when_token_wrong(): void {
		[ $jid ] = $this->make_syncing_job();
		$response = $this->notify_request( $jid, 'not-the-right-token' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->pending_actions_for( $jid ) );
	}

	// -------------------------------------------------------------------------
	// status guard — rejected once a job isn't 'syncing', even with a correct token on file.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provide_non_syncing_statuses
	 */
	public function test_notify_returns_403_for_non_syncing_job( string $status ): void {
		[ $jid, $token ] = $this->make_syncing_job();
		MigrationRegistry::update_site_job( $jid, [ 'status' => $status ] );

		$response = $this->notify_request( $jid, $token );

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->pending_actions_for( $jid ) );
	}

	public function provide_non_syncing_statuses(): array {
		return [
			'complete'  => [ 'complete' ],  // sync never enabled for this attempt
			'finalized' => [ 'finalized' ], // sync ended — cannot be re-triggered
			'failed'    => [ 'failed' ],
			'cancelled' => [ 'cancelled' ],
		];
	}

	// -------------------------------------------------------------------------
	// Enqueue-not-inline (plan: "does not call SyncDispatcher::run_sync_pass() inline")
	// -------------------------------------------------------------------------

	public function test_notify_enqueues_async_pass_and_responds_without_waiting(): void {
		[ $jid, $token ] = $this->make_syncing_job();

		$response = $this->notify_request( $jid, $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['status'] ?? null );

		// notify() must not itself have run a pass inline — no lock activity, no pass outcome
		// recorded. Only SyncDispatcher::run_sync_pass(), invoked later via the enqueued
		// action, does that.
		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNull( $job->sync_locked_at );
		$this->assertNull( $job->sync_last_pass_at );

		$this->assertNotEmpty( $this->pending_actions_for( $jid ), 'A continuation must be enqueued via as_enqueue_async_action() targeting SyncDispatcher::ACTION_HOOK.' );
	}

	public function test_notify_enqueues_for_the_correct_site_job_id_not_a_hardcoded_one(): void {
		[ $jid1, $token1 ] = $this->make_syncing_job();
		[ $jid2, ] = $this->make_syncing_job();

		$this->notify_request( $jid1, $token1 );

		$this->assertNotEmpty( $this->pending_actions_for( $jid1 ) );
		$this->assertEmpty( $this->pending_actions_for( $jid2 ), 'notify() for one site job must not enqueue a pass for another.' );
	}

	// -------------------------------------------------------------------------
	// Lock already held — U2's dispatcher, not this receiver, is responsible for the skip.
	// -------------------------------------------------------------------------

	/**
	 * U8's receiver always enqueues on a valid, syncing, correctly-tokened call — it never
	 * itself inspects the lock (SyncDispatcher::run_sync_pass() re-checks status and claims
	 * the lock only when the enqueued action actually runs). This test proves that division
	 * of responsibility: a call arriving while U2's lock is already held still enqueues
	 * cleanly at the receiver layer (no error, no deadlock); MigrationRegistry::claim_sync_lock()
	 * — the same guard SyncDispatcher::run_sync_pass() calls — is what actually reports the
	 * lock as unavailable, exactly as U2's own dispatcher tests already establish.
	 */
	public function test_notify_still_enqueues_cleanly_when_lock_already_held(): void {
		[ $jid, $token ] = $this->make_syncing_job();

		// Simulate another in-flight pass holding a fresh lock (U2's claim_sync_lock()).
		MigrationRegistry::update_site_job( $jid, [ 'sync_locked_at' => current_time( 'mysql', true ) ] );

		$response = $this->notify_request( $jid, $token );

		$this->assertSame( 200, $response->get_status(), 'The receiver enqueues regardless of lock state — it does not itself check the lock.' );
		$this->assertNotEmpty( $this->pending_actions_for( $jid ) );

		// Confirm the lock is in fact still held, i.e. the dispatcher (not the receiver)
		// is the one that will skip this pass once the enqueued action runs.
		$this->assertFalse(
			MigrationRegistry::claim_sync_lock( $jid, 10 ),
			'Lock is still held — SyncDispatcher::run_sync_pass() must skip this pass when it eventually runs, not the receiver.'
		);
	}
}
