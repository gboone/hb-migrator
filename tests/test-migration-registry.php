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
	// finalize_site_job_sync() — lock-race guard (P0)
	//
	// finalize_site_job_sync() must not proceed with its status transition/cleanup while
	// sync_locked_at indicates an in-flight pass — otherwise Finalize could delete a job's
	// IdMap out from under a running SyncDispatcher pass, causing PostImporter/CommentImporter
	// (which use IdMap::get() to decide insert-vs-update) to re-insert already-migrated rows
	// as duplicates. The chosen mechanism mirrors claim_sync_lock()'s own staleness
	// comparison: a FRESH lock (age < FINALIZE_LOCK_STALENESS_MINUTES) blocks finalize; a
	// STALE lock (age >= threshold, i.e. an abandoned lock from a process that died mid-pass)
	// does NOT block finalize, since claim_sync_lock() would reclaim it as abandoned anyway.
	// This is a deliberate choice, not "any lock blocks regardless of staleness" — see
	// FINALIZE_LOCK_STALENESS_MINUTES's docblock in class-migration-registry.php.
	// -----------------------------------------------------------------------

	public function test_finalize_site_job_sync_rejects_when_fresh_lock_held(): void {
		[ , $jid ] = $this->make_complete_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid );

		// Simulate a sync pass currently in flight: claim_sync_lock() just set this a moment ago.
		// Matches the fresh-lock convention test-sync-dispatcher.php and test-sync-receiver.php use.
		MigrationRegistry::update_site_job( $jid, [ 'sync_locked_at' => current_time( 'mysql', true ) ] );

		$this->assertFalse( MigrationRegistry::finalize_site_job_sync( $jid ), 'Finalize must be rejected while a fresh lock is held.' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'syncing', $job->status, 'Job must remain syncing, not be finalized, while the lock is held.' );
		$this->assertNull( $job->sync_finalized_at );
		$this->assertSame( 2, IdMap::get( $jid, 'post', 1 ), 'IdMap must not be wiped while the in-flight pass could still be using it.' );

		// Once the lock clears (pass completes/releases), finalize can be retried and succeeds.
		MigrationRegistry::update_site_job( $jid, [ 'sync_locked_at' => null ] );
		$this->assertTrue( MigrationRegistry::finalize_site_job_sync( $jid ), 'Finalize must succeed once the lock clears.' );
		$this->assertSame( 'finalized', MigrationRegistry::get_site_job( $jid )->status );
	}

	public function test_finalize_site_job_sync_stale_lock_does_not_block(): void {
		global $wpdb;
		if ( $wpdb instanceof \WP_SQLite_DB ) {
			$this->markTestSkipped( 'finalize_site_job_sync() uses MySQL-only NOW() - INTERVAL syntax — no SQLite equivalent.' );
		}

		[ $mid, $jid ] = $this->make_complete_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid );

		// Older than FINALIZE_LOCK_STALENESS_MINUTES (10) — an abandoned lock from a process
		// that died mid-pass (PHP timeout, OOM) without reaching release_sync_lock(). Uses the
		// same 15-minute margin test-sync-dispatcher.php's staleness test uses for its analogous
		// claim_sync_lock() check.
		$stale = gmdate( 'Y-m-d H:i:s', time() - ( 15 * MINUTE_IN_SECONDS ) );
		MigrationRegistry::update_site_job( $jid, [ 'sync_locked_at' => $stale ] );

		$this->assertTrue( MigrationRegistry::finalize_site_job_sync( $jid ), 'A stale lock must not block finalize.' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'finalized', $job->status );
		$this->assertNotNull( $job->sync_finalized_at );
		$this->assertNull( IdMap::get( $jid, 'post', 1 ), 'Cleanup must still run once finalize is allowed to proceed.' );

		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( '', $migration->source_api_key );
	}

	// -----------------------------------------------------------------------
	// complete_migration() — sibling-job completion gate (P1)
	//
	// Once any site job on a migration legitimately leaves 'complete' (enables sync), it can
	// never become 'complete' again ('syncing' -> 'finalized' is one-way). complete_migration()
	// must treat 'syncing'/'finalized' siblings as "done with their pipeline" for both (a) the
	// gate that lets the migration reach 'complete', and (b) whether cleanup should proceed —
	// while still not letting a job stuck at 'complete' that can NEVER sync (deleted
	// destination) permanently block either.
	// -----------------------------------------------------------------------

	/**
	 * Builds a two-site-job migration in 'running' status, both jobs 'complete', so tests can
	 * drive one job into syncing/finalized and observe complete_migration()'s gate and
	 * maybe_wipe_migration_credential()'s sibling check.
	 */
	private function make_two_job_migration( int $dest_blog_id_a, int $dest_blog_id_b ): array {
		$mid  = MigrationRegistry::create_migration( 'https://source.example.com', 'sharedkey', null );
		$jidA = MigrationRegistry::create_site_job( $mid, 1, 'a.example.com', 'https://a.example.com', '', '/a/' );
		$jidB = MigrationRegistry::create_site_job( $mid, 2, 'b.example.com', 'https://b.example.com', '', '/b/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jidA, [ 'status' => 'complete', 'dest_blog_id' => $dest_blog_id_a ] );
		MigrationRegistry::update_site_job( $jidB, [ 'status' => 'complete', 'dest_blog_id' => $dest_blog_id_b ] );
		IdMap::set( $jidA, 'post', 1, 2 );
		IdMap::set( $jidB, 'post', 1, 2 );
		return [ $mid, $jidA, $jidB ];
	}

	public function test_complete_migration_reaches_complete_when_sibling_already_syncing(): void {
		[ $mid, $jidA, $jidB ] = $this->make_two_job_migration( get_current_blog_id(), get_current_blog_id() );

		// jobA already had "Enable Sync" clicked before jobB's own pipeline finished — its
		// status is 'syncing', never 'complete' again.
		MigrationRegistry::enable_site_job_sync( $jidA );
		$this->assertSame( 'syncing', MigrationRegistry::get_site_job( $jidA )->status );

		// jobB now finishes its own pipeline (mirrors SearchReplace::finalize()'s call pattern:
		// mark the job complete, then ask complete_migration() to check all siblings).
		MigrationRegistry::update_site_job( $jidB, [ 'status' => 'complete' ] );

		$this->assertTrue(
			MigrationRegistry::complete_migration( $mid ),
			'Migration must reach complete once every job is either complete, syncing, or finalized — not stuck forever because a sibling left "complete" for "syncing".'
		);
		$this->assertSame( 'complete', MigrationRegistry::get_migration( $mid )->status );
	}

	public function test_complete_migration_still_blocked_by_genuinely_incomplete_sibling(): void {
		// Regression guard: the relaxed gate must still block on a job that is actually
		// unfinished (not complete/syncing/finalized), e.g. still running or failed.
		[ $mid, $jidA, $jidB ] = $this->make_two_job_migration( get_current_blog_id(), get_current_blog_id() );
		MigrationRegistry::update_site_job( $jidA, [ 'status' => 'running' ] );
		MigrationRegistry::update_site_job( $jidB, [ 'status' => 'complete' ] );

		$this->assertFalse( MigrationRegistry::complete_migration( $mid ) );
		$this->assertSame( 'running', MigrationRegistry::get_migration( $mid )->status );
	}

	public function test_complete_migration_permanently_stuck_complete_job_does_not_block_other_jobs_cleanup(): void {
		// jobA's destination subsite was deleted — it is permanently stuck at 'complete' and
		// can never become sync-capable. jobB has a live destination and will go on to sync.
		[ $mid, $jidA, $jidB ] = $this->make_two_job_migration( 999999, get_current_blog_id() );

		$this->assertTrue( MigrationRegistry::complete_migration( $mid ) );
		$this->assertSame( 'complete', MigrationRegistry::get_migration( $mid )->status );

		// jobA can never sync — its own cleanup must proceed immediately, exactly like
		// pre-sync behavior, rather than waiting on a window that will never open.
		$this->assertNull( IdMap::get( $jidA, 'post', 1 ), "jobA's IdMap must be cleaned up immediately — it can never become sync-capable." );

		// jobB is still sync-capable at this point, so the shared credential and jobB's own
		// IdMap must both survive completion.
		$this->assertSame( 'sharedkey', MigrationRegistry::get_migration( $mid )->source_api_key );
		$this->assertSame( 2, IdMap::get( $jidB, 'post', 1 ) );

		// jobB now enables and finalizes sync.
		MigrationRegistry::enable_site_job_sync( $jidB );
		MigrationRegistry::finalize_site_job_sync( $jidB );

		// jobA being permanently stuck at 'complete' must NOT block the credential wipe once
		// jobB (the only job that could still need it) has finalized — this is the bug: the
		// old sibling check treated bare 'complete' status as "might still need it" forever.
		$this->assertSame(
			'',
			MigrationRegistry::get_migration( $mid )->source_api_key,
			"A permanently non-sync-capable sibling stuck at 'complete' must not block the credential wipe indefinitely."
		);
		$this->assertNull( IdMap::get( $jidB, 'post', 1 ) );
	}

	public function test_complete_migration_multi_job_no_sync_matches_original_behavior(): void {
		// Regression guard: a multi-job migration where no job ever enables sync, and none
		// are sync-capable (destinations deleted), must still get the original immediate
		// cleanup for every job — the pre-sync-era behavior this method had before U1/this fix.
		[ $mid, $jidA, $jidB ] = $this->make_two_job_migration( 999999, 999999 );

		$this->assertTrue( MigrationRegistry::complete_migration( $mid ) );
		$this->assertSame( 'complete', MigrationRegistry::get_migration( $mid )->status );
		$this->assertSame( '', MigrationRegistry::get_migration( $mid )->source_api_key );
		$this->assertNull( IdMap::get( $jidA, 'post', 1 ) );
		$this->assertNull( IdMap::get( $jidB, 'post', 1 ) );
	}

	public function test_complete_migration_multi_job_both_sync_capable_defers_cleanup(): void {
		// Regression guard: a multi-job migration where every job is complete and sync-capable
		// but none has enabled sync yet must still defer cleanup for all of them, matching the
		// single-job behavior already covered by test_complete_migration_defers_cleanup_when_job_sync_capable().
		[ $mid, $jidA, $jidB ] = $this->make_two_job_migration( get_current_blog_id(), get_current_blog_id() );

		$this->assertTrue( MigrationRegistry::complete_migration( $mid ) );
		$this->assertSame( 'complete', MigrationRegistry::get_migration( $mid )->status );
		$this->assertSame( 'sharedkey', MigrationRegistry::get_migration( $mid )->source_api_key );
		$this->assertSame( 2, IdMap::get( $jidA, 'post', 1 ) );
		$this->assertSame( 2, IdMap::get( $jidB, 'post', 1 ) );
	}

	// -----------------------------------------------------------------------
	// cancel_migration() — as_unschedule_all_actions() must actually unschedule by group
	// (code review fix): the original call passed the group name as the $hook parameter
	// (as_unschedule_all_actions( 'hb-migrator' )), which matches as_unschedule_all_actions(
	// $hook, $args = [], $group = '' )'s FIRST parameter, not the group. Since no scheduled
	// action actually has a hook literally named "hb-migrator", that call always silently
	// unscheduled nothing. The fix passes '' for $hook and 'hb-migrator' for $group, which
	// routes into ActionScheduler_Store::cancel_actions_by_group() — see
	// lib/action-scheduler/functions.php.
	// -----------------------------------------------------------------------

	public function test_cancel_migration_unschedules_pending_group_actions(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );

		as_enqueue_async_action( 'hbm_import_posts', [ 'site_job_id' => 1, 'last_id' => 0, 'attempt' => 0 ], 'hb-migrator' );
		as_enqueue_async_action( 'hbm_import_media', [ 'site_job_id' => 1, 'offset' => 0, 'attempt' => 0 ], 'hb-migrator' );

		$before = as_get_scheduled_actions( [
			'group'    => 'hb-migrator',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		] );
		$this->assertNotEmpty( $before, 'Precondition: pending actions exist in the hb-migrator group.' );

		$this->assertTrue( MigrationRegistry::cancel_migration( $mid ) );

		$after = as_get_scheduled_actions( [
			'group'    => 'hb-migrator',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		] );
		$this->assertEmpty( $after, 'cancel_migration() must unschedule every pending action in the hb-migrator group — this is the bug: the old call site never actually did.' );
	}

	public function test_cancel_migration_no_op_does_not_touch_unrelated_pending_actions(): void {
		// Migration already 'complete' — cancel_migration()'s UPDATE affects 0 rows (rows_affected
		// = 0), so $cancelled is false and the as_unschedule_all_actions() call must never run at
		// all. Regression guard: fixing the parameter order must not turn this into an
		// unconditional group-wide unschedule regardless of whether cancellation actually happened.
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'complete' );

		as_enqueue_async_action( 'hbm_import_posts', [ 'site_job_id' => 1, 'last_id' => 0, 'attempt' => 0 ], 'hb-migrator' );

		$this->assertFalse( MigrationRegistry::cancel_migration( $mid ) );

		$after = as_get_scheduled_actions( [
			'group'    => 'hb-migrator',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		] );
		$this->assertNotEmpty( $after, 'A no-op cancel (migration already complete) must not unschedule unrelated pending actions.' );
	}

	// -----------------------------------------------------------------------
	// get_syncing_site_jobs_for_migration()
	// -----------------------------------------------------------------------

	public function test_get_syncing_site_jobs_for_migration_returns_only_syncing_jobs_for_that_migration(): void {
		$mid1 = MigrationRegistry::create_migration( 'https://source-a.example.com', 'key', null );
		$jid1 = MigrationRegistry::create_site_job( $mid1, 1, 'a.example.com', 'https://a.example.com', '', '/a/' );
		$jid2 = MigrationRegistry::create_site_job( $mid1, 2, 'b.example.com', 'https://b.example.com', '', '/b/' );
		MigrationRegistry::update_site_job( $jid1, [ 'status' => 'syncing' ] );
		MigrationRegistry::update_site_job( $jid2, [ 'status' => 'complete' ] );

		// A second migration's syncing job must never leak into the first migration's results.
		$mid2 = MigrationRegistry::create_migration( 'https://source-b.example.com', 'key', null );
		$jid3 = MigrationRegistry::create_site_job( $mid2, 3, 'c.example.com', 'https://c.example.com', '', '/c/' );
		MigrationRegistry::update_site_job( $jid3, [ 'status' => 'syncing' ] );

		$ids = array_map( fn( $job ) => (int) $job->id, MigrationRegistry::get_syncing_site_jobs_for_migration( $mid1 ) );

		$this->assertSame( [ $jid1 ], $ids );
	}

	public function test_get_syncing_site_jobs_for_migration_returns_empty_array_when_none_syncing(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'a.example.com', 'https://a.example.com', '', '/a/' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'complete' ] );

		$this->assertSame( [], MigrationRegistry::get_syncing_site_jobs_for_migration( $mid ) );
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
