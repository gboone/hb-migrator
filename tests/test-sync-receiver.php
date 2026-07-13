<?php
/**
 * Tests for Destination\SyncReceiver — U8's inbound webhook endpoint (notify()), plus the
 * code-review addition of REST equivalents for "Enable Sync" / "Finalize & Stop Sync"
 * (enable_sync() / finalize_sync()), which previously had no programmatic path — only the
 * wp-admin form buttons in Admin\AdminPage::handle_enable_sync() / handle_finalize_sync().
 *
 * Auth is two-layered for notify() (plan "Key Technical Decisions"): ApiAuth::verify_request()
 * gates the route itself via permission_callback — tested here with a full REST dispatch,
 * mirroring tests/test-destination-preflight.php's "no auth" pattern. This class's own
 * hash_equals() check against the per-site-job sync_webhook_token is exercised by calling
 * notify() directly, mirroring tests/test-migration-receiver.php's direct-call style for
 * cancel(). enable_sync()/finalize_sync() use ApiAuth::verify_request() alone (see
 * class-sync-receiver.php's docblock for why the extra per-resource token notify() requires
 * does not apply to these two operator/script-initiated routes) — tested with the same
 * route-registration + REST-dispatch pattern for the auth layer, then direct calls (mirroring
 * tests/test-admin-page.php's handle_enable_sync()/handle_finalize_sync() coverage) for the
 * business-rule and side-effect-parity assertions.
 */

use HBMigrator\Destination\SyncDispatcher;
use HBMigrator\Destination\SyncReceiver;
use HBMigrator\Destination\SyncScheduler;
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

	/**
	 * A 'complete' site job, not yet syncing — the precondition enable_sync() acts on.
	 * Mirrors tests/test-admin-page.php's and tests/test-sync-scheduler.php's identical helper.
	 *
	 * @return array{0:int,1:int} [ migration_id, site_job_id ]
	 */
	private function make_complete_site_job( int $dest_blog_id ): array {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'testkey', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'sync.example.com', 'https://sync.example.com', '', '/sync/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'complete', 'dest_blog_id' => $dest_blog_id ] );
		return [ $mid, $jid ];
	}

	/**
	 * Builds a bare request carrying only `site_job_id` — enable_sync()/finalize_sync() only
	 * ever read that one param, so a single helper covers direct calls to both.
	 */
	private function job_request( int $site_job_id ): \WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $site_job_id . '/enable-sync' );
		$req->set_param( 'site_job_id', $site_job_id );
		return $req;
	}

	/**
	 * Intercepts the outbound HTTP call deliver_sync_webhook_token() makes to source's
	 * /source/sync-webhook-token endpoint, mirroring tests/test-sync-webhook.php's
	 * send_webhook() mock. $code lets callers simulate either a confirmed successful delivery
	 * (200) or a failed one (e.g. 500) without a real source install. $captured is populated
	 * with the decoded request body, by reference, so callers can assert on what was sent.
	 */
	private function mock_token_delivery_endpoint( int $code, ?array &$captured = null ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $code, &$captured ) {
			if ( false === strpos( $url, '/source/sync-webhook-token' ) ) {
				return $preempt;
			}
			$captured = json_decode( $args['body'], true );
			return [
				'response' => [ 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Error' ],
				'body'     => wp_json_encode( 200 === $code ? [ 'status' => 'ok' ] : [ 'error' => 'boom' ] ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );
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

	// -------------------------------------------------------------------------
	// enable_sync() — route registration + ApiAuth layer
	// -------------------------------------------------------------------------

	public function test_enable_sync_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . HBM_API_NAMESPACE . '/destination/site-jobs/(?P<site_job_id>\d+)/enable-sync', $routes );
	}

	public function test_enable_sync_endpoint_with_no_auth_returns_error(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $jid . '/enable-sync' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertSame( 'complete', MigrationRegistry::get_site_job( $jid )->status, 'A rejected request must have zero side effects.' );
	}

	public function test_enable_sync_endpoint_with_valid_auth_transitions_job(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $jid . '/enable-sync' );
		$req->set_header( 'Authorization', 'Bearer ' . \HBMigrator\ApiAuth::get_or_create_key() );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'syncing', $response->get_data()['status'] ?? null );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'syncing', $job->status );
		$this->assertNotEmpty( $job->sync_webhook_token );
	}

	// -------------------------------------------------------------------------
	// enable_sync() — business rules, matching Admin\AdminPage::handle_enable_sync() exactly.
	// -------------------------------------------------------------------------

	public function test_enable_sync_returns_404_for_unknown_site_job(): void {
		$response = SyncReceiver::enable_sync( $this->job_request( 999999 ) );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * @dataProvider provide_non_complete_statuses
	 */
	public function test_enable_sync_returns_400_for_non_complete_job( string $status ): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		MigrationRegistry::update_site_job( $jid, [ 'status' => $status ] );

		$response = SyncReceiver::enable_sync( $this->job_request( $jid ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $status, MigrationRegistry::get_site_job( $jid )->status, 'Status must be unchanged on rejection.' );
	}

	public function provide_non_complete_statuses(): array {
		return [
			'pending'   => [ 'pending' ],
			'running'   => [ 'running' ],
			'failed'    => [ 'failed' ],
			'cancelled' => [ 'cancelled' ],
			'syncing'   => [ 'syncing' ],
			'finalized' => [ 'finalized' ], // sync cannot be re-enabled once finalized
		];
	}

	public function test_enable_sync_returns_400_when_destination_subsite_missing(): void {
		// dest_blog_id = 0 — no destination subsite was ever recorded / it no longer resolves.
		[ , $jid ] = $this->make_complete_site_job( 0 );

		$response = SyncReceiver::enable_sync( $this->job_request( $jid ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'complete', MigrationRegistry::get_site_job( $jid )->status, 'Status must be unchanged when destination subsite does not resolve.' );
	}

	public function test_enable_sync_returns_400_when_destination_subsite_deleted(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Simulating a deleted subsite requires multisite.' );
		}

		$sub_id = self::factory()->blog->create();
		[ , $jid ] = $this->make_complete_site_job( $sub_id );

		// Soft-delete, matching TermImporter::process()'s dest_site/deleted check and
		// Admin\AdminPage::handle_enable_sync()'s identical guard.
		wp_update_site( $sub_id, [ 'deleted' => 1 ] );

		$response = SyncReceiver::enable_sync( $this->job_request( $jid ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'complete', MigrationRegistry::get_site_job( $jid )->status, 'Status must be unchanged when destination subsite was deleted.' );
	}

	// -------------------------------------------------------------------------
	// enable_sync() — side-effect parity with Admin\AdminPage::handle_enable_sync(): U7's
	// cron safety net and U8's token delivery must both fire, exactly as the button does.
	// -------------------------------------------------------------------------

	public function test_enable_sync_registers_recurring_sync_action(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		$response = SyncReceiver::enable_sync( $this->job_request( $jid ) );

		$this->assertSame( 200, $response->get_status() );
		$actions = $this->pending_actions_for( $jid );
		$this->assertNotEmpty( $actions, 'enable_sync() must register the U7 cron safety-net action, mirroring Admin\AdminPage::handle_enable_sync().' );
		$this->assertInstanceOf( \ActionScheduler_IntervalSchedule::class, $actions[0]->get_schedule(), 'Must be a recurring action, not a one-shot.' );
	}

	public function test_enable_sync_delivers_webhook_token_to_source_and_records_delivery_timestamp(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		$captured = null;
		$this->mock_token_delivery_endpoint( 200, $captured );

		$response = SyncReceiver::enable_sync( $this->job_request( $jid ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $captured, 'enable_sync() must deliver the fresh sync_webhook_token to source, mirroring Admin\AdminPage::deliver_sync_webhook_token().' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( $job->sync_webhook_token, $captured['sync_webhook_token'] ?? null );
		$this->assertSame( (int) $jid, (int) ( $captured['site_job_id'] ?? 0 ) );
		$this->assertNotNull( $job->sync_webhook_token_delivered_at, 'Confirmed successful delivery (source responded 200) must stamp sync_webhook_token_delivered_at.' );
	}

	public function test_enable_sync_leaves_delivery_timestamp_null_when_source_delivery_fails(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		$captured = null;
		$this->mock_token_delivery_endpoint( 500, $captured );

		$response = SyncReceiver::enable_sync( $this->job_request( $jid ) );

		// Enable Sync itself must still succeed — token delivery is best-effort and must not
		// block the transition (cron's U7 safety net does not depend on this token at all).
		$this->assertSame( 200, $response->get_status() );
		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'syncing', $job->status );
		$this->assertNull( $job->sync_webhook_token_delivered_at, 'A failed delivery must leave the timestamp null, not silently marked delivered.' );
	}

	// -------------------------------------------------------------------------
	// finalize_sync() — route registration + ApiAuth layer
	// -------------------------------------------------------------------------

	public function test_finalize_sync_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . HBM_API_NAMESPACE . '/destination/site-jobs/(?P<site_job_id>\d+)/finalize-sync', $routes );
	}

	public function test_finalize_sync_endpoint_with_no_auth_returns_error(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $jid . '/finalize-sync' );
		$response = rest_get_server()->dispatch( $req );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertSame( 'syncing', MigrationRegistry::get_site_job( $jid )->status, 'A rejected request must have zero side effects.' );
	}

	public function test_finalize_sync_endpoint_with_valid_auth_transitions_job(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/site-jobs/' . $jid . '/finalize-sync' );
		$req->set_header( 'Authorization', 'Bearer ' . \HBMigrator\ApiAuth::get_or_create_key() );
		$response = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'finalized', $response->get_data()['status'] ?? null );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'finalized', $job->status );
		$this->assertNotNull( $job->sync_finalized_at );
	}

	// -------------------------------------------------------------------------
	// finalize_sync() — business rules, matching Admin\AdminPage::handle_finalize_sync().
	// -------------------------------------------------------------------------

	public function test_finalize_sync_returns_404_for_unknown_site_job(): void {
		$response = SyncReceiver::finalize_sync( $this->job_request( 999999 ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_finalize_sync_returns_400_when_job_not_syncing(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		// Never enabled — status is 'complete', not 'syncing'.

		$response = SyncReceiver::finalize_sync( $this->job_request( $jid ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'complete', MigrationRegistry::get_site_job( $jid )->status, 'Status must be unchanged on rejection.' );
	}

	// -------------------------------------------------------------------------
	// finalize_sync() — side-effect parity: U7's cron safety net must be unscheduled exactly
	// as Admin\AdminPage::handle_finalize_sync() does.
	// -------------------------------------------------------------------------

	public function test_finalize_sync_unschedules_recurring_sync_action(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid );
		SyncScheduler::schedule( $jid );
		$this->assertNotEmpty( $this->pending_actions_for( $jid ), 'Precondition: recurring action exists before finalizing.' );

		$response = SyncReceiver::finalize_sync( $this->job_request( $jid ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( $this->pending_actions_for( $jid ), 'finalize_sync() must unschedule the recurring action, mirroring Admin\AdminPage::handle_finalize_sync().' );
	}

	public function test_finalizing_one_site_job_does_not_unschedule_another(): void {
		[ , $jid1 ] = $this->make_complete_site_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid1 );
		SyncScheduler::schedule( $jid1 );

		[ , $jid2 ] = $this->make_complete_site_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid2 );
		SyncScheduler::schedule( $jid2 );

		$response = SyncReceiver::finalize_sync( $this->job_request( $jid1 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( $this->pending_actions_for( $jid1 ) );
		$this->assertNotEmpty( $this->pending_actions_for( $jid2 ), "jid2's recurring action must survive finalizing jid1." );
	}
}
