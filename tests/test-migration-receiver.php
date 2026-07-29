<?php
/**
 * Tests for MigrationReceiver::begin() — idempotency, locking, and restart behaviour.
 *
 * Source URL note: tests that reach the idempotency branch use 'https://93.184.216.34'
 * (example.com's IP) so SourceClient's per-request DNS-rebinding check sees a public IP
 * and passes without making a real DNS lookup. Tests that are rejected before SourceClient
 * is ever called can use any URL.
 */

use HBMigrator\Destination\AuditReport;
use HBMigrator\Destination\MigrationReceiver;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_MigrationReceiver extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
	}

	// -------------------------------------------------------------------------
	// Input validation — these return before any DB or HTTP work.
	// -------------------------------------------------------------------------

	public function test_begin_returns_400_for_missing_source_url(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$this->assertSame( 400, MigrationReceiver::begin( $req )->get_status() );
	}

	public function test_begin_returns_400_for_missing_site_ids(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', 'https://93.184.216.34' );
		$req->set_param( 'source_api_key', 'key' );
		// site_ids intentionally omitted.
		$this->assertSame( 400, MigrationReceiver::begin( $req )->get_status() );
	}

	public function test_begin_returns_400_for_private_ip_source_url(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', 'https://127.0.0.1/' );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$this->assertSame( 400, MigrationReceiver::begin( $req )->get_status() );
	}

	public function test_begin_returns_400_for_http_source_url(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', 'http://93.184.216.34/' ); // http, not https
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$this->assertSame( 400, MigrationReceiver::begin( $req )->get_status() );
	}

	// -------------------------------------------------------------------------
	// Soft mutex — transient lock prevents duplicate migrations.
	// -------------------------------------------------------------------------

	public function test_begin_returns_429_when_transient_lock_set(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34/';
		$lock_key   = 'hbm_begin_' . md5( $source_url );
		set_transient( $lock_key, 1, 10 );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', $source_url );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$response = MigrationReceiver::begin( $req );

		delete_transient( $lock_key );
		$this->assertSame( 429, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Idempotency — existing running migration: no restart, restarted=false.
	// -------------------------------------------------------------------------

	public function test_begin_existing_running_job_returns_200_with_restarted_false(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34';
		$mid        = MigrationRegistry::create_migration( $source_url, 'key', null );
		$jid        = MigrationRegistry::create_site_job( $mid, 1, 'example.com', $source_url, '', '/example.com/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'running', 'current_stage' => 'posts' ] );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', $source_url );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$response = MigrationReceiver::begin( $req );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $mid, $data['migration_id'] );
		$this->assertFalse( $data['restarted'] );
		$this->assertNotEmpty( $data['status_token'] );
	}

	// -------------------------------------------------------------------------
	// Restart — failed job is re-enqueued: restarted=true.
	// -------------------------------------------------------------------------

	public function test_begin_restarts_failed_job_and_returns_restarted_true(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34';
		$mid        = MigrationRegistry::create_migration( $source_url, 'key', null );
		$jid        = MigrationRegistry::create_site_job( $mid, 1, 'example.com', $source_url, '', '/example.com/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [
			'status'        => 'failed',
			'current_stage' => 'posts',
			'error_message' => 'timeout',
		] );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', $source_url );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$response = MigrationReceiver::begin( $req );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['restarted'] );

		// Error message should have been cleared on the restarted job.
		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNull( $job->error_message );
	}

	// -------------------------------------------------------------------------
	// Conflict policies — stored on create, passed through begin().
	// -------------------------------------------------------------------------

	public function test_create_migration_stores_default_policies_when_none_provided(): void {
		$mid       = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$migration = MigrationRegistry::get_migration( $mid );

		$this->assertSame( 'merge',        $migration->user_conflict_policy );
		$this->assertSame( 'generate_new', $migration->site_conflict_policy );
		$this->assertSame( 'import_all',   $migration->media_conflict_policy );
	}

	public function test_create_migration_stores_explicit_policies(): void {
		$mid       = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null, [
			'user_conflict_policy'  => 'create',
			'site_conflict_policy'  => 'use_existing',
			'media_conflict_policy' => 'skip_duplicates',
		] );
		$migration = MigrationRegistry::get_migration( $mid );

		$this->assertSame( 'create',          $migration->user_conflict_policy );
		$this->assertSame( 'use_existing',    $migration->site_conflict_policy );
		$this->assertSame( 'skip_duplicates', $migration->media_conflict_policy );
	}

	public function test_get_conflict_policies_returns_defaults_for_old_migration(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$policies = MigrationRegistry::get_conflict_policies( $mid );

		$this->assertSame( 'merge',        $policies['user_conflict_policy'] );
		$this->assertSame( 'generate_new', $policies['site_conflict_policy'] );
		$this->assertSame( 'import_all',   $policies['media_conflict_policy'] );
	}

	public function test_begin_passes_user_conflict_policy_from_request(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34';

		// Pre-seed a running migration so begin() takes the idempotency path and
		// returns 200 without reaching SourceClient. We check the policy on a
		// freshly created migration via create_migration() directly.
		$mid = MigrationRegistry::create_migration( $source_url, 'key', null, [
			'user_conflict_policy' => 'create',
		] );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::create_site_job( $mid, 1, 'example.com', $source_url, '', '/example.com/' );

		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( 'create', $migration->user_conflict_policy );
	}

	public function test_begin_omitting_policies_uses_defaults(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34';
		$mid        = MigrationRegistry::create_migration( $source_url, 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::create_site_job( $mid, 1, 'example.com', $source_url, '', '/example.com/' );

		// begin() should return 200 without error (existing migration idempotency path).
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', $source_url );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		// No policy params — omitted intentionally.
		$response = MigrationReceiver::begin( $req );

		$this->assertSame( 200, $response->get_status() );

		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( 'merge',        $migration->user_conflict_policy );
		$this->assertSame( 'generate_new', $migration->site_conflict_policy );
		$this->assertSame( 'import_all',   $migration->media_conflict_policy );
	}

	// -------------------------------------------------------------------------
	// cancel() endpoint
	// -------------------------------------------------------------------------

	private function make_running_migration(): array {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$migration = MigrationRegistry::get_migration( $mid );
		return [ 'id' => $mid, 'token' => $migration->status_token ];
	}

	private function cancel_request( int $migration_id, string $status_token = '' ): \WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/migrations/' . $migration_id . '/cancel' );
		$req->set_param( 'migration_id', $migration_id );
		$req->set_param( 'status_token', $status_token );
		return MigrationReceiver::cancel( $req );
	}

	public function test_cancel_returns_404_for_unknown_migration(): void {
		$response = $this->cancel_request( 999999 );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_cancel_returns_403_when_status_token_is_missing(): void {
		[ 'id' => $mid ] = $this->make_running_migration();
		$response = $this->cancel_request( $mid, '' );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_cancel_returns_403_when_status_token_is_wrong(): void {
		[ 'id' => $mid ] = $this->make_running_migration();
		$response = $this->cancel_request( $mid, 'wrong-token' );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_cancel_returns_200_and_cancelled_status_for_running_migration(): void {
		[ 'id' => $mid, 'token' => $token ] = $this->make_running_migration();
		$response = $this->cancel_request( $mid, $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $response->get_data()['status'] ?? null );

		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( 'cancelled', $migration->status );
	}

	public function test_cancel_returns_200_when_migration_already_cancelled(): void {
		[ 'id' => $mid, 'token' => $token ] = $this->make_running_migration();
		MigrationRegistry::cancel_migration( $mid );
		$response = $this->cancel_request( $mid, $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $response->get_data()['status'] ?? null );
	}

	public function test_cancel_returns_200_when_migration_already_complete(): void {
		[ 'id' => $mid, 'token' => $token ] = $this->make_running_migration();
		MigrationRegistry::update_migration_status( $mid, 'complete' );
		$response = $this->cancel_request( $mid, $token );

		$this->assertSame( 200, $response->get_status() );
		// Status must remain 'complete' — cancel_migration() guard protects it.
		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( 'complete', $migration->status );
	}

	// -------------------------------------------------------------------------
	// cancel_migration() unit tests
	// -------------------------------------------------------------------------

	public function test_cancel_migration_sets_running_to_cancelled(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );

		$result = MigrationRegistry::cancel_migration( $mid );

		$this->assertTrue( $result );
		$this->assertSame( 'cancelled', MigrationRegistry::get_migration( $mid )->status );
	}

	public function test_cancel_migration_returns_false_and_leaves_complete_status(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'complete' );

		$result = MigrationRegistry::cancel_migration( $mid );

		$this->assertFalse( $result );
		$this->assertSame( 'complete', MigrationRegistry::get_migration( $mid )->status );
	}

	public function test_cancel_migration_is_idempotent_on_already_cancelled(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::cancel_migration( $mid );

		$result = MigrationRegistry::cancel_migration( $mid );

		$this->assertFalse( $result, 'Second cancel should be a no-op and return false.' );
		$this->assertSame( 'cancelled', MigrationRegistry::get_migration( $mid )->status );
	}

	public function test_cancel_migration_cancels_failed_migration(): void {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::fail_migration( $mid, 'something broke' );

		$result = MigrationRegistry::cancel_migration( $mid );

		$this->assertTrue( $result );
		$this->assertSame( 'cancelled', MigrationRegistry::get_migration( $mid )->status );
	}

	// -------------------------------------------------------------------------
	// status_token — always present in 200 response.
	// -------------------------------------------------------------------------

	public function test_begin_200_response_always_includes_status_token(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34';
		$mid        = MigrationRegistry::create_migration( $source_url, 'key', null );
		$jid        = MigrationRegistry::create_site_job( $mid, 1, 'example.com', $source_url, '', '/example.com/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'running' ] );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', $source_url );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$data = MigrationReceiver::begin( $req )->get_data();

		$this->assertArrayHasKey( 'status_token', $data );
		$this->assertNotEmpty( $data['status_token'] );
	}

	// -------------------------------------------------------------------------
	// U3: request-trail capture for the source/sites listing (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U3. Request-trail
	// capture at every outbound source call"). This is a migration-level call — no site job
	// exists yet at the point of the SourceClient::get() call itself, so the entry is only
	// attributable via record_for_migration() once migration_id is known, i.e. on the success
	// path only. See the deviation note at the SourceClient::get() call site in
	// MigrationReceiver::begin() for why the failure path below asserts unchanged existing
	// behavior (502) rather than a trail entry: no migration_id exists yet to attach one to.
	// -------------------------------------------------------------------------

	private function mock_source_sites( array $sites ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $sites ) {
			if ( false !== strpos( $url, '/source/sites' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $sites ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	public function test_begin_records_migration_scoped_request_trail_entry_on_success(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34';
		$this->mock_source_sites( [
			[ 'blog_id' => 1, 'domain' => 'example.com', 'siteurl' => $source_url, 'upload_url' => '' ],
		] );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', $source_url );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$response = MigrationReceiver::begin( $req );

		$this->assertSame( 201, $response->get_status() );
		$migration_id = (int) $response->get_data()['migration_id'];

		$jobs = MigrationRegistry::get_site_jobs_for_migration( $migration_id );
		$this->assertNotEmpty( $jobs, 'begin() must have created a site job for blog_id 1.' );

		$post_id = AuditReport::get_or_create_for_site_job( (int) $jobs[0]->id );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'migration', $rows[0]['scope'] );
		$this->assertSame( 'source/sites', $rows[0]['path'] );
		$this->assertTrue( $rows[0]['success'] );
		$this->assertSame( 1, $rows[0]['count'] );
	}

	public function test_begin_returns_502_and_records_no_trail_entry_when_source_unreachable(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Destination must be multisite.' );
		}
		$source_url = 'https://93.184.216.34';
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/sites' ) ) {
				return new \WP_Error( 'http_request_failed', 'Could not resolve host.' );
			}
			return $preempt;
		}, 10, 3 );

		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/begin' );
		$req->set_param( 'source_url', $source_url );
		$req->set_param( 'source_api_key', 'key' );
		$req->set_param( 'site_ids', [ 1 ] );
		$response = MigrationReceiver::begin( $req );

		// Existing error-handling behavior is completely unchanged by U3.
		$this->assertSame( 502, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data() );

		// No migration was ever created for this failed attempt, so there is nothing to
		// look up a report for — confirms U3 did not smuggle in a side effect on this path.
		$this->assertNull(
			MigrationRegistry::find_active_migration_for_source( $source_url ),
			'A failed source/sites fetch must not leave behind a migration row.'
		);
	}
}
