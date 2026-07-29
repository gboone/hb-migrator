<?php
/**
 * Tests for U1: the hbm_audit_report custom post type and the AuditReport storage class.
 * See docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U1. Audit report
 * storage: custom post type and lifecycle".
 */

use HBMigrator\Destination\AuditReport;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_Audit_Report extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'wp_insert_post_data' );
	}

	/**
	 * Creates a real migration + site job row via MigrationRegistry, mirroring the convention
	 * used in tests/test-term-importer.php / tests/test-migration-receiver.php, so
	 * get_or_create_for_site_job()'s migration_id lookup (used for the staged-entries copy) has
	 * a real row to resolve.
	 */
	private function make_site_job( array $overrides = [] ): int {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );
		if ( $overrides ) {
			MigrationRegistry::update_site_job( $jid, $overrides );
		}
		return $jid;
	}

	// -------------------------------------------------------------------------
	// get_or_create_for_site_job(): happy path + lazy creation
	// -------------------------------------------------------------------------

	public function test_get_or_create_creates_exactly_one_post_on_first_call(): void {
		$jid = $this->make_site_job();

		$count_before = self::count_report_posts();
		$post_id      = AuditReport::get_or_create_for_site_job( $jid );

		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( $count_before + 1, self::count_report_posts() );
	}

	public function test_get_or_create_returns_same_post_id_on_second_call(): void {
		$jid = $this->make_site_job();

		$first  = AuditReport::get_or_create_for_site_job( $jid );
		$second = AuditReport::get_or_create_for_site_job( $jid );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, self::count_report_posts_for_site_job( $jid ) );
	}

	public function test_a_site_job_with_no_activity_has_no_report_post(): void {
		$jid = $this->make_site_job();
		$this->assertSame( 0, self::count_report_posts_for_site_job( $jid ) );
	}

	// -------------------------------------------------------------------------
	// record(): lazy creation trigger + multiple rows per key
	// -------------------------------------------------------------------------

	public function test_record_before_any_report_exists_triggers_creation(): void {
		$jid = $this->make_site_job();

		AuditReport::record( $jid, 'site_job', [ 'type' => 'write', 'action' => 'created', 'object' => 'term' ] );

		$this->assertSame( 1, self::count_report_posts_for_site_job( $jid ) );
	}

	public function test_multiple_record_calls_with_same_meta_key_produce_distinct_rows(): void {
		$jid = $this->make_site_job();

		AuditReport::record( $jid, 'site_job', [ 'type' => 'write', 'action' => 'created', 'object_id' => 1 ] );
		AuditReport::record( $jid, 'site_job', [ 'type' => 'write', 'action' => 'created', 'object_id' => 2 ] );
		AuditReport::record( $jid, 'site_job', [ 'type' => 'write', 'action' => 'created', 'object_id' => 3 ] );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );

		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_write', false );
		restore_current_blog();

		$this->assertCount( 3, $rows, 'Three record() calls under the same meta key must produce three distinct postmeta rows, not one overwritten value.' );

		$object_ids = array_map( static fn( $row ) => $row['object_id'], $rows );
		sort( $object_ids );
		$this->assertSame( [ 1, 2, 3 ], $object_ids );
	}

	public function test_record_preserves_backslashes_in_entry_data(): void {
		// add_metadata() (wp-includes/meta.php, called by add_post_meta()) unconditionally
		// wp_unslash()'s the meta_value before storing it — even data that was never slashed in
		// the first place — so a raw source field containing a backslash (a Windows-style file
		// path, an escaped character in post content, etc.) is silently corrupted unless the
		// caller counters that with wp_slash() first. append_entry() must do this internally so
		// no caller of record()/record_for_migration() needs to know about it.
		$jid = $this->make_site_job();
		$raw = 'C:\Windows\Path and a \' quote';

		AuditReport::record( $jid, 'site_job', [ 'type' => 'write', 'post_title' => $raw ] );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_write', false );
		restore_current_blog();

		$this->assertSame( $raw, $rows[0]['post_title'], 'Backslashes in entry data must survive the postmeta round-trip.' );
	}

	public function test_record_stores_scope_as_part_of_entry_data(): void {
		$jid = $this->make_site_job();

		AuditReport::record( $jid, 'site_job', [ 'type' => 'write', 'action' => 'created' ] );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_write', false );
		restore_current_blog();

		$this->assertSame( 'site_job', $rows[0]['scope'] );
	}

	// -------------------------------------------------------------------------
	// Integration: report is genuinely created on the primary site, even when the caller is
	// currently switched to a subsite.
	// -------------------------------------------------------------------------

	public function test_report_is_created_on_primary_site_even_from_subsite_context(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$jid = $this->make_site_job();

		$sub_id = self::factory()->blog->create();
		switch_to_blog( $sub_id );

		try {
			$post_id = AuditReport::get_or_create_for_site_job( $jid );
			$this->assertGreaterThan( 0, $post_id );
		} finally {
			restore_current_blog();
		}

		// Verify directly on the primary site, independent of AuditReport's own lookup.
		$main_site_id = get_main_site_id();
		switch_to_blog( $main_site_id );
		$post = get_post( $post_id );
		restore_current_blog();

		$this->assertNotNull( $post, 'Report post must exist on the primary site.' );
		$this->assertSame( AuditReport::POST_TYPE, $post->post_type );

		wp_delete_site( $sub_id );
	}

	// -------------------------------------------------------------------------
	// delete_for_site_job()
	// -------------------------------------------------------------------------

	public function test_delete_for_site_job_with_no_report_is_a_safe_noop(): void {
		$jid = $this->make_site_job();

		// No report has been created for this job — deleting must not error or throw.
		AuditReport::delete_for_site_job( $jid );

		$this->assertSame( 0, self::count_report_posts_for_site_job( $jid ) );
	}

	public function test_delete_for_site_job_removes_the_report_post(): void {
		$jid     = $this->make_site_job();
		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		$this->assertGreaterThan( 0, $post_id );

		AuditReport::delete_for_site_job( $jid );

		$this->assertSame( 0, self::count_report_posts_for_site_job( $jid ) );
		switch_to_blog( get_main_site_id() );
		$this->assertNull( get_post( $post_id ) );
		restore_current_blog();
	}

	// -------------------------------------------------------------------------
	// get_report_post_id_for_site_job(): U1 non-creating lookup (see
	// docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, R1).
	// -------------------------------------------------------------------------

	public function test_get_report_post_id_returns_correct_id_for_existing_report(): void {
		$jid     = $this->make_site_job();
		$post_id = AuditReport::get_or_create_for_site_job( $jid );

		$this->assertSame( $post_id, AuditReport::get_report_post_id_for_site_job( $jid ) );
	}

	public function test_get_report_post_id_returns_null_and_creates_nothing_when_no_report_exists(): void {
		$jid = $this->make_site_job();

		$count_before = self::count_report_posts();
		$result       = AuditReport::get_report_post_id_for_site_job( $jid );
		$count_after  = self::count_report_posts();

		$this->assertNull( $result, 'A site job with no trail-worthy event must resolve to null, not fabricate a report.' );
		$this->assertSame( $count_before, $count_after, 'get_report_post_id_for_site_job() must never create a report post as a side effect.' );
	}

	public function test_get_report_post_id_returns_null_after_report_was_deleted(): void {
		$jid = $this->make_site_job();
		AuditReport::get_or_create_for_site_job( $jid );

		AuditReport::delete_for_site_job( $jid );

		$this->assertNull(
			AuditReport::get_report_post_id_for_site_job( $jid ),
			'A deleted report must never resolve to a stale/wrong post ID.'
		);
	}

	// -------------------------------------------------------------------------
	// record_for_migration(): staging + copy-on-first-creation
	// -------------------------------------------------------------------------

	public function test_staged_migration_entry_appears_in_sibling_site_job_report_on_creation(): void {
		$mid  = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$jid1 = MigrationRegistry::create_site_job( $mid, 1, 'a.example.com', 'https://93.184.216.34', '', '/a/' );
		$jid2 = MigrationRegistry::create_site_job( $mid, 2, 'b.example.com', 'https://93.184.216.34', '', '/b/' );

		// Staged before either site job has a report — simulates MigrationReceiver::begin()'s
		// source/sites listing and UserImporter's network-user actions running before any
		// site-job-specific stage exists.
		AuditReport::record_for_migration( $mid, 'migration', [ 'type' => 'request', 'path' => 'source/sites' ] );

		$post_id_1 = AuditReport::get_or_create_for_site_job( $jid1 );
		$post_id_2 = AuditReport::get_or_create_for_site_job( $jid2 );

		switch_to_blog( get_main_site_id() );
		$rows_1 = get_post_meta( $post_id_1, '_hbm_audit_request', false );
		$rows_2 = get_post_meta( $post_id_2, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows_1 );
		$this->assertSame( 'migration', $rows_1[0]['scope'] );
		$this->assertSame( 'source/sites', $rows_1[0]['path'] );

		$this->assertCount( 1, $rows_2, 'Staged migration-level entry must be copied into every sibling site job report, not just the first.' );
		$this->assertSame( 'migration', $rows_2[0]['scope'] );
	}

	// -------------------------------------------------------------------------
	// Custom post type registration: show_ui override + capability gating.
	// -------------------------------------------------------------------------

	public function test_post_type_is_registered_with_expected_visibility_flags(): void {
		$pto = get_post_type_object( AuditReport::POST_TYPE );

		$this->assertNotNull( $pto, 'hbm_audit_report must be registered.' );
		$this->assertFalse( $pto->public );
		$this->assertTrue( $pto->show_ui, 'show_ui must be explicitly true — otherwise there is no edit-post.php screen at all.' );
		$this->assertFalse( $pto->show_in_menu );
		$this->assertFalse( $pto->rewrite );
		$this->assertFalse( $pto->show_in_rest );
	}

	public function test_capable_user_can_reach_edit_screen_capability_checks(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'manage_network only exists in multisite.' );
		}

		$jid     = $this->make_site_job();
		$post_id = AuditReport::get_or_create_for_site_job( $jid );

		$super_admin_id = self::factory()->user->create();
		grant_super_admin( $super_admin_id );

		switch_to_blog( get_main_site_id() );
		wp_set_current_user( $super_admin_id );

		$this->assertTrue( current_user_can( 'read_post', $post_id ), 'A capable (super admin) user must be able to read the report post.' );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ), 'A capable (super admin) user must be able to edit the report post.' );

		$pto = get_post_type_object( AuditReport::POST_TYPE );
		$this->assertTrue( current_user_can( $pto->cap->edit_posts ), 'A capable user must be able to reach the post type\'s list/edit screen.' );

		restore_current_blog();
	}

	public function test_incapable_user_is_denied_read_edit_and_list_access(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'manage_network only exists in multisite.' );
		}

		$jid     = $this->make_site_job();
		$post_id = AuditReport::get_or_create_for_site_job( $jid );

		$regular_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		// Site (not network) administrators do not have manage_network unless also granted
		// super admin — confirm the precondition so a false-negative test failure can't hide
		// behind an accidental super-admin grant.
		$this->assertFalse( user_can( $regular_id, 'manage_network' ) );

		switch_to_blog( get_main_site_id() );
		wp_set_current_user( $regular_id );

		$this->assertFalse( current_user_can( 'read_post', $post_id ), 'A user without manage_network must not be able to read another site job\'s report.' );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ), 'A user without manage_network must not be able to edit the report.' );

		$pto = get_post_type_object( AuditReport::POST_TYPE );
		$this->assertFalse( current_user_can( $pto->cap->edit_posts ), 'A user without manage_network must not be able to reach the post type\'s list/edit screen.' );

		restore_current_blog();
	}

	// -------------------------------------------------------------------------
	// Critical, non-obvious behavior: internal failures never propagate out of record().
	// -------------------------------------------------------------------------

	public function test_record_swallows_an_internal_failure_and_never_throws(): void {
		$jid = $this->make_site_job();

		$log_file  = tempnam( sys_get_temp_dir(), 'hbm_audit_test_log' );
		$prev_log  = ini_set( 'error_log', $log_file );
		$exception_message = 'forced failure for AuditReport containment test';

		$thrower = static function ( $data ) use ( $exception_message ) {
			throw new \RuntimeException( $exception_message );
		};
		add_filter( 'wp_insert_post_data', $thrower );

		try {
			// This call reaches wp_insert_post() internally (no report exists yet for $jid),
			// which is where the forced failure above fires. If record() let the exception
			// escape, PHPUnit would report this test as an error rather than a failure/pass —
			// the mere fact that execution continues past this call already proves containment.
			AuditReport::record( $jid, 'site_job', [ 'type' => 'write', 'action' => 'created' ] );
		} finally {
			remove_filter( 'wp_insert_post_data', $thrower );
			ini_set( 'error_log', $prev_log );
		}

		$this->assertSame( 0, self::count_report_posts_for_site_job( $jid ), 'No report post should exist — creation was aborted by the forced failure.' );

		$log_contents = file_get_contents( $log_file );
		unlink( $log_file );

		$this->assertStringContainsString(
			$exception_message,
			$log_contents,
			'The failure must be observable via the logged message even though it never propagated to the caller.'
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function count_report_posts(): int {
		switch_to_blog( get_main_site_id() );
		$count = count( get_posts( [
			'post_type'      => AuditReport::POST_TYPE,
			'post_status'    => 'private',
			'numberposts'    => -1,
			'fields'         => 'ids',
		] ) );
		restore_current_blog();
		return $count;
	}

	private static function count_report_posts_for_site_job( int $site_job_id ): int {
		switch_to_blog( get_main_site_id() );
		$count = count( get_posts( [
			'post_type'      => AuditReport::POST_TYPE,
			'post_status'    => 'private',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'meta_key'       => '_hbm_audit_site_job_id',
			'meta_value'     => $site_job_id,
		] ) );
		restore_current_blog();
		return $count;
	}
}
