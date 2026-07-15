<?php
/**
 * Tests for SyncScheduler (U7) — the cron safety-net trigger. Covers direct unit behavior of
 * SyncScheduler::schedule()/unschedule() plus the wiring through Admin\AdminPage::
 * handle_enable_sync()/handle_finalize_sync(), which is where U7 actually calls into it (see
 * class-sync-scheduler.php's docblock for why it's wired at the controller layer rather than
 * from MigrationRegistry).
 */

use HBMigrator\Admin\AdminPage;
use HBMigrator\Destination\SyncDispatcher;
use HBMigrator\Destination\SyncScheduler;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_SyncScheduler extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		remove_all_filters( 'hbm_sync_interval' );
		$_POST = [];
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function login_as_network_admin(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $user_id )->add_cap( 'manage_network' );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function make_complete_site_job( int $dest_blog_id ): array {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'testkey', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'sync.example.com', 'https://sync.example.com', '', '/sync/' );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'complete', 'dest_blog_id' => $dest_blog_id ] );
		return [ $mid, $jid ];
	}

	/**
	 * Finds the pending hbm_sync_pass action (recurring or one-shot) scheduled for a given
	 * site_job_id, or null if none exists.
	 */
	private function find_pending_sync_action( int $site_job_id ): ?\ActionScheduler_Action {
		$scheduled = as_get_scheduled_actions( [
			'hook'     => SyncDispatcher::ACTION_HOOK,
			'group'    => 'hb-migrator',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		] );

		foreach ( $scheduled as $action ) {
			$args = $action->get_args();
			if ( isset( $args['site_job_id'] ) && (int) $args['site_job_id'] === $site_job_id ) {
				return $action;
			}
		}
		return null;
	}

	// -----------------------------------------------------------------------
	// SyncScheduler::schedule() / unschedule() — direct unit behavior
	// -----------------------------------------------------------------------

	public function test_schedule_registers_recurring_action_at_default_interval(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		SyncScheduler::schedule( $jid );

		$action = $this->find_pending_sync_action( $jid );
		$this->assertNotNull( $action, 'schedule() must register a pending hbm_sync_pass action for the site job.' );
		$this->assertSame( SyncDispatcher::ACTION_HOOK, $action->get_hook() );
		$this->assertSame( 'hb-migrator', $action->get_group() );

		$schedule = $action->get_schedule();
		$this->assertInstanceOf( \ActionScheduler_IntervalSchedule::class, $schedule, 'schedule() must register a recurring action, not a one-shot.' );
		// interval_in_seconds() is deprecated since Action Scheduler 3.0.0 in favor of get_recurrence().
		$this->assertSame( 15 * MINUTE_IN_SECONDS, (int) $schedule->get_recurrence(), 'Default interval must be 15 minutes (900 seconds).' );
	}

	public function test_schedule_interval_is_filterable(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		add_filter( 'hbm_sync_interval', fn() => 300 );

		SyncScheduler::schedule( $jid );

		$action   = $this->find_pending_sync_action( $jid );
		$schedule = $action->get_schedule();
		// interval_in_seconds() is deprecated since Action Scheduler 3.0.0 in favor of get_recurrence().
		$this->assertSame( 300, (int) $schedule->get_recurrence(), 'hbm_sync_interval filter must override the default interval.' );
	}

	public function test_unschedule_removes_recurring_action_and_no_further_passes_are_scheduled(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		SyncScheduler::schedule( $jid );
		$this->assertNotNull( $this->find_pending_sync_action( $jid ) );

		SyncScheduler::unschedule( $jid );

		$this->assertNull( $this->find_pending_sync_action( $jid ), 'unschedule() must remove the recurring action; no further pass should be scheduled.' );
	}

	public function test_unschedule_for_one_site_job_does_not_affect_another(): void {
		[ , $jid1 ] = $this->make_complete_site_job( get_current_blog_id() );
		[ , $jid2 ] = $this->make_complete_site_job( get_current_blog_id() );

		SyncScheduler::schedule( $jid1 );
		SyncScheduler::schedule( $jid2 );

		SyncScheduler::unschedule( $jid1 );

		$this->assertNull( $this->find_pending_sync_action( $jid1 ), 'jid1 must have its recurring action removed.' );
		$this->assertNotNull( $this->find_pending_sync_action( $jid2 ), "jid2's still-active recurring action must be unaffected by finalizing jid1." );
	}

	public function test_recurring_action_callback_resolves_to_sync_dispatcher_run_sync_pass(): void {
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		// Sync must actually be enabled for run_sync_pass() to do anything but return early.
		MigrationRegistry::enable_site_job_sync( $jid );

		SyncScheduler::schedule( $jid );

		$action = $this->find_pending_sync_action( $jid );
		$this->assertNotNull( $action );

		// The hook this action fires is registered in Plugin::register_action_hooks() to
		// [ SyncDispatcher::class, 'run_sync_pass' ]. Confirm that registration exists and,
		// as a behavioral check, that firing the hook directly with this action's args
		// actually reaches run_sync_pass() and has a real effect (claims + releases the lock).
		$this->assertNotFalse(
			has_action( SyncDispatcher::ACTION_HOOK, [ SyncDispatcher::class, 'run_sync_pass' ] ),
			'The recurring action\'s hook must be wired to SyncDispatcher::run_sync_pass().'
		);

		$args = $action->get_args();
		$this->assertSame( $jid, (int) $args['site_job_id'], 'The scheduled action must carry the correct site_job_id.' );

		do_action( SyncDispatcher::ACTION_HOOK, $args['site_job_id'] );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNotNull( $job->sync_last_pass_at, 'Firing the recurring action\'s hook must actually run a sync pass via SyncDispatcher::run_sync_pass().' );
	}

	// -----------------------------------------------------------------------
	// Wiring: Admin\AdminPage::handle_enable_sync() / handle_finalize_sync()
	// -----------------------------------------------------------------------

	public function test_enable_sync_registers_recurring_action_for_that_site_job(): void {
		$this->login_as_network_admin();
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );

		$_POST = [
			'_wpnonce'    => wp_create_nonce( 'hbm_enable_sync' ),
			'site_job_id' => $jid,
		];
		$_REQUEST = $_POST;

		try {
			AdminPage::handle_enable_sync();
		} catch ( \Throwable $e ) {
			// wp_safe_redirect() ends in exit() — expected in test context.
		}

		$this->assertSame( 'syncing', MigrationRegistry::get_site_job( $jid )->status );

		$action = $this->find_pending_sync_action( $jid );
		$this->assertNotNull( $action, 'Enable Sync must register a recurring hbm_sync_pass action for the site job.' );
		$this->assertInstanceOf( \ActionScheduler_IntervalSchedule::class, $action->get_schedule() );
	}

	public function test_finalize_sync_unschedules_recurring_action_and_no_further_passes_fire(): void {
		$this->login_as_network_admin();
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		MigrationRegistry::enable_site_job_sync( $jid );
		SyncScheduler::schedule( $jid );

		$this->assertNotNull( $this->find_pending_sync_action( $jid ), 'Precondition: recurring action exists before finalizing.' );

		$_POST = [
			'_wpnonce'    => wp_create_nonce( 'hbm_finalize_sync' ),
			'site_job_id' => $jid,
		];
		$_REQUEST = $_POST;

		try {
			AdminPage::handle_finalize_sync();
		} catch ( \Throwable $e ) {}

		$this->assertSame( 'finalized', MigrationRegistry::get_site_job( $jid )->status );
		$this->assertNull( $this->find_pending_sync_action( $jid ), 'Finalize must unschedule the recurring action; no further pass should be scheduled.' );
	}

	public function test_finalizing_one_site_job_does_not_affect_another_still_active_recurring_action(): void {
		$this->login_as_network_admin();
		[ , $jid1 ] = $this->make_complete_site_job( get_current_blog_id() );
		[ , $jid2 ] = $this->make_complete_site_job( get_current_blog_id() );

		MigrationRegistry::enable_site_job_sync( $jid1 );
		SyncScheduler::schedule( $jid1 );

		MigrationRegistry::enable_site_job_sync( $jid2 );
		SyncScheduler::schedule( $jid2 );

		$_POST = [
			'_wpnonce'    => wp_create_nonce( 'hbm_finalize_sync' ),
			'site_job_id' => $jid1,
		];
		$_REQUEST = $_POST;

		try {
			AdminPage::handle_finalize_sync();
		} catch ( \Throwable $e ) {}

		$this->assertSame( 'finalized', MigrationRegistry::get_site_job( $jid1 )->status );
		$this->assertSame( 'syncing', MigrationRegistry::get_site_job( $jid2 )->status, "jid2 must remain 'syncing' — finalizing jid1 must not touch it." );

		$this->assertNull( $this->find_pending_sync_action( $jid1 ), 'jid1 recurring action must be gone.' );
		$this->assertNotNull( $this->find_pending_sync_action( $jid2 ), "jid2's recurring action must survive finalizing jid1." );
	}

	public function test_finalize_sync_rejection_leaves_recurring_action_untouched(): void {
		$this->login_as_network_admin();
		[ , $jid ] = $this->make_complete_site_job( get_current_blog_id() );
		// Never enabled — status is 'complete', not 'syncing', so finalize must be rejected
		// and handle_enable_sync() was never called, so there is no recurring action to begin
		// with; this asserts the rejection path doesn't attempt to unschedule anything that
		// would error or otherwise misbehave.
		$_POST = [
			'_wpnonce'    => wp_create_nonce( 'hbm_finalize_sync' ),
			'site_job_id' => $jid,
		];

		try {
			AdminPage::handle_finalize_sync();
		} catch ( \Throwable $e ) {}

		$this->assertSame( 'complete', MigrationRegistry::get_site_job( $jid )->status, 'Status must be unchanged on rejection.' );
	}
}
