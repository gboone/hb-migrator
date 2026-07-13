<?php
/**
 * Tests for MigrationRegistry's sync lifecycle: complete_migration()'s deferred
 * credential/IdMap cleanup, enable_site_job_sync(), and finalize_site_job_sync().
 *
 * Non-sync MigrationRegistry coverage (create/get/complete/cancel migration, site jobs,
 * IdMap) already lives in tests/test-checkpoint.php's Test_MigrationRegistry class — this
 * file uses a distinct class name to avoid a duplicate-class fatal.
 */

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_MigrationRegistry_Sync extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	/**
	 * Creates a migration with one 'complete' site job carrying an IdMap row, so tests can
	 * assert on whether that row and the migration's credential survive various transitions.
	 */
	private function make_complete_job( int $dest_blog_id ): array {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'supersecretkey', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [
			'status'       => 'complete',
			'dest_blog_id' => $dest_blog_id,
		] );
		IdMap::set( $jid, 'post', 1, 2 );
		return [ $mid, $jid ];
	}

	// -----------------------------------------------------------------------
	// complete_migration() — deferred credential/IdMap cleanup
	// -----------------------------------------------------------------------

	public function test_complete_migration_defers_cleanup_when_job_sync_capable(): void {
		[ $mid, $jid ] = $this->make_complete_job( get_current_blog_id() );

		$this->assertTrue( MigrationRegistry::complete_migration( $mid ) );

		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( 'supersecretkey', $migration->source_api_key, 'Credential must survive completion when a job could still enter sync.' );
		$this->assertSame( 2, IdMap::get( $jid, 'post', 1 ), 'IdMap must survive completion when a job could still enter sync.' );
	}

	public function test_complete_migration_cleans_up_immediately_when_no_job_sync_capable(): void {
		// dest_blog_id points at a subsite that doesn't exist, so the job can never pass
		// Enable Sync's live-subsite check — it is not "sync capable".
		[ $mid, $jid ] = $this->make_complete_job( 999999 );

		$this->assertTrue( MigrationRegistry::complete_migration( $mid ) );

		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( '', $migration->source_api_key, 'Credential must still be wiped immediately when no job could ever sync — matches pre-sync behavior.' );
		$this->assertNull( IdMap::get( $jid, 'post', 1 ), 'IdMap must still be cleaned up immediately when no job could ever sync.' );
	}

	// -----------------------------------------------------------------------
	// enable_site_job_sync()
	// -----------------------------------------------------------------------

	public function test_enable_site_job_sync_transitions_complete_to_syncing(): void {
		[ , $jid ] = $this->make_complete_job( get_current_blog_id() );

		$this->assertTrue( MigrationRegistry::enable_site_job_sync( $jid ) );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'syncing', $job->status );
		$this->assertNotNull( $job->sync_enabled_at );
		$this->assertNotEmpty( $job->sync_webhook_token );
		$this->assertSame( 64, strlen( $job->sync_webhook_token ), 'Token must be bin2hex(random_bytes(32)) — 64 hex chars.' );
	}

	/**
	 * @dataProvider provide_non_complete_statuses
	 */
	public function test_enable_site_job_sync_rejects_non_complete_statuses( string $status ): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => $status, 'dest_blog_id' => get_current_blog_id() ] );

		$this->assertFalse( MigrationRegistry::enable_site_job_sync( $jid ) );
		$this->assertSame( $status, MigrationRegistry::get_site_job( $jid )->status );
	}

	public function provide_non_complete_statuses(): array {
		return [
			'pending'   => [ 'pending' ],
			'running'   => [ 'running' ],
			'failed'    => [ 'failed' ],
			'cancelled' => [ 'cancelled' ],
			// Sync cannot be re-enabled once finalized — this is the terminal-state guard.
			'finalized' => [ 'finalized' ],
		];
	}

	// -----------------------------------------------------------------------
	// finalize_site_job_sync()
	// -----------------------------------------------------------------------

	public function test_finalize_site_job_sync_transitions_syncing_to_finalized_and_cleans_up(): void {
		[ $mid, $jid ] = $this->make_complete_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid );

		$this->assertTrue( MigrationRegistry::finalize_site_job_sync( $jid ) );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'finalized', $job->status );
		$this->assertNotNull( $job->sync_finalized_at );

		$this->assertNull( IdMap::get( $jid, 'post', 1 ), 'IdMap for this job must be cleaned up on finalize.' );

		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( '', $migration->source_api_key, 'Credential must be wiped once no job on the migration could still need it.' );
	}

	public function test_finalize_site_job_sync_rejects_non_syncing_job(): void {
		[ , $jid ] = $this->make_complete_job( get_current_blog_id() );
		// Status is 'complete', not 'syncing' — Finalize was never preceded by Enable Sync.
		$this->assertFalse( MigrationRegistry::finalize_site_job_sync( $jid ) );
		$this->assertSame( 'complete', MigrationRegistry::get_site_job( $jid )->status );
	}

	public function test_finalize_site_job_sync_preserves_shared_credential_when_sibling_job_still_syncing(): void {
		$mid  = MigrationRegistry::create_migration( 'https://source.example.com', 'sharedkey', null );
		$jid1 = MigrationRegistry::create_site_job( $mid, 1, 'a.example.com', 'https://a.example.com', '', '/a/' );
		$jid2 = MigrationRegistry::create_site_job( $mid, 2, 'b.example.com', 'https://b.example.com', '', '/b/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid1, [ 'status' => 'complete', 'dest_blog_id' => get_current_blog_id() ] );
		MigrationRegistry::update_site_job( $jid2, [ 'status' => 'complete', 'dest_blog_id' => get_current_blog_id() ] );

		MigrationRegistry::enable_site_job_sync( $jid1 );
		MigrationRegistry::enable_site_job_sync( $jid2 );

		$this->assertTrue( MigrationRegistry::finalize_site_job_sync( $jid1 ) );

		// jid2 is still 'syncing' — the migration-wide shared credential must survive.
		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( 'sharedkey', $migration->source_api_key );

		// jid1's own IdMap rows are still cleaned up even though the credential is preserved.
		$this->assertNull( IdMap::get( $jid1, 'post', 1 ) );
	}

	// -----------------------------------------------------------------------
	// get_syncable_site_jobs()
	// -----------------------------------------------------------------------

	public function test_get_syncable_site_jobs_returns_only_complete_and_syncing(): void {
		$mid  = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid1 = MigrationRegistry::create_site_job( $mid, 1, 'complete.example.com', 'https://complete.example.com', '', '/complete/' );
		$jid2 = MigrationRegistry::create_site_job( $mid, 2, 'syncing.example.com', 'https://syncing.example.com', '', '/syncing/' );
		$jid3 = MigrationRegistry::create_site_job( $mid, 3, 'failed.example.com', 'https://failed.example.com', '', '/failed/' );

		MigrationRegistry::update_site_job( $jid1, [ 'status' => 'complete' ] );
		MigrationRegistry::update_site_job( $jid2, [ 'status' => 'syncing' ] );
		MigrationRegistry::update_site_job( $jid3, [ 'status' => 'failed' ] );

		$ids = array_map( fn( $job ) => (int) $job->id, MigrationRegistry::get_syncable_site_jobs() );

		$this->assertContains( $jid1, $ids );
		$this->assertContains( $jid2, $ids );
		$this->assertNotContains( $jid3, $ids );
	}
}
