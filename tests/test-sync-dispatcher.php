<?php
/**
 * Tests for SyncDispatcher::run_sync_pass() (U2) — the single entry point both the cron
 * poll (U7) and the webhook receiver (U8) will call, and its atomic lock-claim guard
 * against a webhook pass and a cron pass running concurrently for the same site job.
 *
 * U3-U6's real stages don't exist yet, so these tests exercise the dispatcher against
 * stub stages implementing SyncStageInterface, injected via the `hbm_sync_stages` filter.
 */

use HBMigrator\Destination\SyncDispatcher;
use HBMigrator\Destination\SyncStageInterface;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

/**
 * Records every site_job_id it was called with and returns a fixed value each time.
 */
class Test_SyncDispatcher_StubStage implements SyncStageInterface {

	public array $calls = [];

	private bool $return_value;

	public function __construct( bool $return_value = false ) {
		$this->return_value = $return_value;
	}

	public function process( int $site_job_id ): bool {
		$this->calls[] = $site_job_id;
		return $this->return_value;
	}
}

/**
 * Always throws — used to exercise the dispatcher's caught-exception path.
 */
class Test_SyncDispatcher_ThrowingStage implements SyncStageInterface {

	public function process( int $site_job_id ): bool {
		throw new \RuntimeException( 'stub stage failure' );
	}
}

class Test_SyncDispatcher extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		remove_all_filters( 'hbm_sync_stages' );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function make_syncing_job(): int {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [
			'status'         => 'complete',
			'dest_blog_id'   => get_current_blog_id(),
		] );
		MigrationRegistry::enable_site_job_sync( $jid );
		return $jid;
	}

	private function inject_stages( array $stages ): void {
		add_filter( 'hbm_sync_stages', function () use ( $stages ) {
			return $stages;
		} );
	}

	// -----------------------------------------------------------------------
	// Happy path
	// -----------------------------------------------------------------------

	public function test_unlocked_syncing_job_claims_lock_runs_all_stages_and_releases(): void {
		$jid = $this->make_syncing_job();

		$posts    = new Test_SyncDispatcher_StubStage( false );
		$media    = new Test_SyncDispatcher_StubStage( false );
		$comments = new Test_SyncDispatcher_StubStage( false );

		$this->inject_stages( [ 'posts' => $posts, 'media' => $media, 'comments' => $comments ] );

		SyncDispatcher::run_sync_pass( $jid );

		$this->assertSame( [ $jid ], $posts->calls );
		$this->assertSame( [ $jid ], $media->calls );
		$this->assertSame( [ $jid ], $comments->calls );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNull( $job->sync_locked_at, 'Lock must be released after a successful pass.' );
		$this->assertNotNull( $job->sync_last_pass_at, 'sync_last_pass_at must be recorded.' );
		$this->assertNull( $job->sync_last_error, 'No error occurred — sync_last_error must be clear.' );
		$this->assertSame( 'syncing', $job->status, 'A successful pass does not change status.' );
	}

	// -----------------------------------------------------------------------
	// Concurrency guard
	// -----------------------------------------------------------------------

	public function test_fresh_lock_held_by_another_attempt_causes_zero_row_claim_and_skip(): void {
		$jid = $this->make_syncing_job();

		// Simulate another in-flight pass holding a fresh lock.
		MigrationRegistry::update_site_job( $jid, [ 'sync_locked_at' => current_time( 'mysql', true ) ] );
		$locked_at_before = MigrationRegistry::get_site_job( $jid )->sync_locked_at;

		$stage = new Test_SyncDispatcher_StubStage( false );
		$this->inject_stages( [ 'posts' => $stage ] );

		SyncDispatcher::run_sync_pass( $jid );

		$this->assertSame( [], $stage->calls, 'Stages must not run when the lock claim fails.' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( $locked_at_before, $job->sync_locked_at, 'A skipped pass must not touch the lock it did not claim.' );
		$this->assertNull( $job->sync_last_pass_at, 'A skipped pass must not record a pass outcome.' );
	}

	/**
	 * Can't simulate a true hardware race in PHPUnit, but this is the property that
	 * actually matters: the atomic UPDATE's WHERE clause must prevent a second claim once
	 * the first has already set the timestamp. A SELECT-then-UPDATE implementation would
	 * fail this test because both claims would see the lock unclaimed before either wrote it.
	 */
	public function test_two_sequential_claims_only_one_succeeds(): void {
		$jid = $this->make_syncing_job();

		$first  = MigrationRegistry::claim_sync_lock( $jid, 10 );
		$second = MigrationRegistry::claim_sync_lock( $jid, 10 );

		$this->assertTrue( $first, 'The first claim must succeed.' );
		$this->assertFalse( $second, 'The second claim must fail — the first already set sync_locked_at.' );
	}

	public function test_two_different_site_jobs_do_not_interfere(): void {
		$jid1 = $this->make_syncing_job();
		$jid2 = $this->make_syncing_job();

		$this->assertTrue( MigrationRegistry::claim_sync_lock( $jid1, 10 ) );
		$this->assertTrue( MigrationRegistry::claim_sync_lock( $jid2, 10 ), 'A lock on one site job must not block a claim on another.' );

		// Confirm both dispatcher runs actually proceed independently.
		MigrationRegistry::release_sync_lock( $jid1 );
		MigrationRegistry::release_sync_lock( $jid2 );

		$stage1 = new Test_SyncDispatcher_StubStage( false );
		$this->inject_stages( [ 'posts' => $stage1 ] );

		SyncDispatcher::run_sync_pass( $jid1 );
		$this->assertSame( [ $jid1 ], $stage1->calls );

		$job2 = MigrationRegistry::get_site_job( $jid2 );
		$this->assertNull( $job2->sync_locked_at, 'jid2 must be unaffected by jid1 activity.' );
	}

	public function test_stale_lock_is_reclaimed(): void {
		global $wpdb;
		if ( $wpdb instanceof \WP_SQLite_DB ) {
			$this->markTestSkipped( 'claim_sync_lock() uses MySQL-only NOW() - INTERVAL syntax — no SQLite equivalent.' );
		}

		$jid = $this->make_syncing_job();

		// Simulate a lock left behind by a process killed mid-pass, well past the
		// 10-minute staleness threshold SyncDispatcher uses.
		$stale = gmdate( 'Y-m-d H:i:s', time() - ( 15 * MINUTE_IN_SECONDS ) );
		MigrationRegistry::update_site_job( $jid, [ 'sync_locked_at' => $stale ] );

		$stage = new Test_SyncDispatcher_StubStage( false );
		$this->inject_stages( [ 'posts' => $stage ] );

		SyncDispatcher::run_sync_pass( $jid );

		$this->assertSame( [ $jid ], $stage->calls, 'A stale lock must be reclaimed, not treated as still held.' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNull( $job->sync_locked_at, 'Lock must be released again after the reclaimed pass completes.' );
	}

	// -----------------------------------------------------------------------
	// Status guard
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider provide_non_syncing_statuses
	 */
	public function test_job_not_syncing_is_a_no_op( string $status ): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => $status, 'dest_blog_id' => get_current_blog_id() ] );

		$stage = new Test_SyncDispatcher_StubStage( false );
		$this->inject_stages( [ 'posts' => $stage ] );

		SyncDispatcher::run_sync_pass( $jid );

		$this->assertSame( [], $stage->calls, 'No stage should run for a non-syncing job.' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( $status, $job->status, 'Status must be untouched.' );
		$this->assertNull( $job->sync_locked_at, 'A no-op must have zero side effects on the lock.' );
		$this->assertNull( $job->sync_last_pass_at, 'A no-op must have zero side effects on pass bookkeeping.' );
	}

	public function provide_non_syncing_statuses(): array {
		return [
			'pending'   => [ 'pending' ],
			'running'   => [ 'running' ],
			'complete'  => [ 'complete' ],
			'failed'    => [ 'failed' ],
			'cancelled' => [ 'cancelled' ],
			'finalized' => [ 'finalized' ],
		];
	}

	// -----------------------------------------------------------------------
	// Failure handling
	// -----------------------------------------------------------------------

	public function test_stage_throwing_releases_lock_leaves_status_syncing_and_records_error(): void {
		$jid = $this->make_syncing_job();

		$before = new Test_SyncDispatcher_StubStage( false );
		$throws = new Test_SyncDispatcher_ThrowingStage();
		$after  = new Test_SyncDispatcher_StubStage( false );

		// A later stage should not run once an earlier one throws — process() propagates
		// out of the foreach immediately, straight to the catch block.
		$this->inject_stages( [ 'posts' => $before, 'media' => $throws, 'comments' => $after ] );

		SyncDispatcher::run_sync_pass( $jid );

		$this->assertSame( [ $jid ], $before->calls, 'Stages before the throwing one must still run.' );
		$this->assertSame( [], $after->calls, 'Stages after the throwing one must not run.' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'syncing', $job->status, 'A sync-pass failure is non-terminal — status must stay syncing, not failed.' );
		$this->assertNull( $job->sync_locked_at, 'Lock must be released even when a stage throws.' );
		$this->assertNotNull( $job->sync_last_pass_at, 'A failed pass still records that a pass was attempted.' );
		$this->assertStringContainsString( 'stub stage failure', $job->sync_last_error );
	}

	// -----------------------------------------------------------------------
	// Self-requeue on remaining work
	// -----------------------------------------------------------------------

	public function test_stage_reporting_remaining_work_self_requeues_instead_of_looping(): void {
		$jid = $this->make_syncing_job();

		$stage = new Test_SyncDispatcher_StubStage( true ); // always reports "more work"
		$this->inject_stages( [ 'posts' => $stage ] );

		SyncDispatcher::run_sync_pass( $jid );

		// Exactly one call within this single run_sync_pass() invocation — the dispatcher
		// must not loop internally past a stage's own row budget.
		$this->assertSame( [ $jid ], $stage->calls );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNull( $job->sync_locked_at, 'Lock must be released even when more work remains.' );
		$this->assertSame( 'syncing', $job->status );

		$scheduled = as_get_scheduled_actions( [
			'hook'     => SyncDispatcher::ACTION_HOOK,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 20,
		] );

		$found = false;
		foreach ( $scheduled as $action ) {
			$args = $action->get_args();
			if ( isset( $args['site_job_id'] ) && (int) $args['site_job_id'] === $jid ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'A continuation must be self-requeued via as_enqueue_async_action().' );
	}
}
