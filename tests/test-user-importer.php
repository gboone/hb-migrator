<?php
/**
 * Tests for UserImporter user conflict policy behaviour.
 */

use HBMigrator\Destination\AuditReport;
use HBMigrator\Destination\UserImporter;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_User_Importer extends WP_UnitTestCase {

	private int $migration_id;

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
	}

	private function make_migration( array $policies = [] ): int {
		$mid = MigrationRegistry::create_migration(
			'https://93.184.216.34',
			'key',
			null,
			$policies
		);
		MigrationRegistry::update_migration_status( $mid, 'running' );
		return $mid;
	}

	private function mock_users( array $users ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $users ) {
			if ( false !== strpos( $url, '/source/users' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $users ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	private function make_source_user( array $overrides = [] ): array {
		return array_merge( [
			'source_user_id'  => 99,
			'user_login'      => 'importeduser',
			'user_email'      => 'importeduser@example.test',
			'display_name'    => 'Imported User',
			'user_registered' => '2024-01-01 00:00:00',
			'user_url'        => '',
			'first_name'      => '',
			'last_name'       => '',
			'description'     => '',
			'site_roles'      => [],
		], $overrides );
	}

	// -------------------------------------------------------------------------
	// Policy: merge (default behaviour)
	// -------------------------------------------------------------------------

	public function test_merge_policy_maps_to_existing_user_when_email_matches(): void {
		$existing_id = wp_insert_user( [
			'user_login' => 'existing-merge-user',
			'user_email' => 'merge-conflict@example.test',
			'user_pass'  => 'password',
		] );
		$this->assertNotInstanceOf( \WP_Error::class, $existing_id );

		$mid = $this->make_migration( [ 'user_conflict_policy' => 'merge' ] );
		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 10, 'user_email' => 'merge-conflict@example.test' ] ),
			// Empty second page signals end of pagination.
		] );
		// Second page returns empty.
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, 'offset=100' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		UserImporter::process( $mid, 0, 0 );

		$mapped_id = IdMap::get( IdMap::NETWORK, 'user', 10 );
		$this->assertSame( $existing_id, $mapped_id, 'Merge policy must map source user to existing destination user.' );

		wp_delete_user( $existing_id );
	}

	public function test_merge_policy_creates_new_user_when_email_does_not_match(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'merge' ] );
		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 20, 'user_email' => 'new-unique-email@example.test', 'user_login' => 'newuniqueuser' ] ),
		] );

		UserImporter::process( $mid, 0, 0 );

		$mapped_id = IdMap::get( IdMap::NETWORK, 'user', 20 );
		$this->assertNotNull( $mapped_id );
		$created_user = get_user_by( 'id', $mapped_id );
		$this->assertNotFalse( $created_user );
		$this->assertSame( 'new-unique-email@example.test', $created_user->user_email );

		wp_delete_user( $mapped_id );
	}

	// -------------------------------------------------------------------------
	// Policy: create
	// -------------------------------------------------------------------------

	public function test_create_policy_creates_new_user_even_when_email_already_exists(): void {
		$existing_id = wp_insert_user( [
			'user_login' => 'existing-create-user',
			'user_email' => 'create-conflict@example.test',
			'user_pass'  => 'password',
		] );
		$this->assertNotInstanceOf( \WP_Error::class, $existing_id );

		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );
		$this->mock_users( [
			$this->make_source_user( [
				'source_user_id' => 30,
				'user_email'     => 'create-conflict@example.test',
				'user_login'     => 'importcreateuser',
			] ),
		] );

		UserImporter::process( $mid, 0, 0 );

		$mapped_id = IdMap::get( IdMap::NETWORK, 'user', 30 );
		$this->assertNotNull( $mapped_id, 'Create policy must produce a new user.' );
		$this->assertNotSame( $existing_id, $mapped_id, 'Must be a different user than the existing one.' );

		$new_user = get_user_by( 'id', $mapped_id );
		$this->assertNotFalse( $new_user );
		$this->assertNotSame( 'create-conflict@example.test', $new_user->user_email, 'New user must have modified email.' );

		$original = get_user_meta( $mapped_id, 'hbm_original_email', true );
		$this->assertSame( 'create-conflict@example.test', $original, 'hbm_original_email must store the original email.' );

		wp_delete_user( $existing_id );
		wp_delete_user( $mapped_id );
	}

	public function test_create_policy_uses_original_email_when_no_conflict(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );
		$this->mock_users( [
			$this->make_source_user( [
				'source_user_id' => 40,
				'user_email'     => 'nocreate-conflict@example.test',
				'user_login'     => 'nocreateconflict',
			] ),
		] );

		UserImporter::process( $mid, 0, 0 );

		$mapped_id = IdMap::get( IdMap::NETWORK, 'user', 40 );
		$this->assertNotNull( $mapped_id );
		$new_user = get_user_by( 'id', $mapped_id );
		$this->assertNotFalse( $new_user );
		$this->assertSame( 'nocreate-conflict@example.test', $new_user->user_email, 'When no email conflict, original email should be used.' );

		wp_delete_user( $mapped_id );
	}

	// -------------------------------------------------------------------------
	// Email suppression during import
	// -------------------------------------------------------------------------

	public function test_pre_wp_mail_filter_active_during_user_registration(): void {
		$suppressed = false;

		// user_register fires inside wp_insert_user — check if suppress filter is live then.
		add_action( 'user_register', function () use ( &$suppressed ) {
			$result     = apply_filters( 'pre_wp_mail', null, [] );
			$suppressed = ( $result instanceof \WP_Error );
		} );

		$mid = $this->make_migration();
		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 201, 'user_email' => 'suppress-check@example.test', 'user_login' => 'suppresscheckuser' ] ),
		] );

		UserImporter::process( $mid, 0, 0 );

		$this->assertTrue( $suppressed, 'pre_wp_mail must be filtered during user_register action.' );

		wp_delete_user( IdMap::get( IdMap::NETWORK, 'user', 201 ) );
	}

	public function test_pre_wp_mail_filter_removed_after_process_completes(): void {
		$mid = $this->make_migration();
		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 202, 'user_email' => 'cleanupcheck@example.test', 'user_login' => 'cleanupcheckuser' ] ),
		] );

		UserImporter::process( $mid, 0, 0 );

		$result = apply_filters( 'pre_wp_mail', null, [] );
		$this->assertNull( $result, 'pre_wp_mail suppress filter must be removed after process() returns normally.' );

		wp_delete_user( IdMap::get( IdMap::NETWORK, 'user', 202 ) );
	}

	public function test_user_creation_still_succeeds_with_suppression_active(): void {
		$mid = $this->make_migration();
		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 203, 'user_email' => 'suppress-succeed@example.test', 'user_login' => 'suppresssucceeduser' ] ),
		] );

		UserImporter::process( $mid, 0, 0 );

		$mapped = IdMap::get( IdMap::NETWORK, 'user', 203 );
		$this->assertNotNull( $mapped, 'User must still be created even though mail is suppressed.' );
		$this->assertNotFalse( get_user_by( 'id', $mapped ) );

		wp_delete_user( $mapped );
	}

	public function test_create_policy_retries_with_counter_when_modified_email_also_conflicts(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );

		// Insert a user with the expected first modified email so the loop must go to +imported2.
		$source_domain   = '93.184.216.34';
		$first_modified  = 'retryuser+imported@' . $source_domain;
		$blocking_id     = wp_insert_user( [
			'user_login' => 'retryuser-orig',
			'user_email' => 'retryuser-conflict@example.test',
			'user_pass'  => 'password',
		] );
		$modified_block  = wp_insert_user( [
			'user_login' => 'retryuser-mod',
			'user_email' => $first_modified,
			'user_pass'  => 'password',
		] );
		$this->assertNotInstanceOf( \WP_Error::class, $blocking_id );
		$this->assertNotInstanceOf( \WP_Error::class, $modified_block );

		$this->mock_users( [
			$this->make_source_user( [
				'source_user_id' => 50,
				'user_email'     => 'retryuser-conflict@example.test',
				'user_login'     => 'retryuser',
			] ),
		] );

		UserImporter::process( $mid, 0, 0 );

		$mapped_id = IdMap::get( IdMap::NETWORK, 'user', 50 );
		$this->assertNotNull( $mapped_id );
		$new_user  = get_user_by( 'id', $mapped_id );
		$this->assertNotFalse( $new_user );
		// Should have used retryuser+imported2@{domain} (or higher).
		$this->assertStringContainsString( 'imported2', $new_user->user_email );

		wp_delete_user( $blocking_id );
		wp_delete_user( $modified_block );
		wp_delete_user( $mapped_id );
	}

	// -------------------------------------------------------------------------
	// U3: request-trail capture for UserImporter's source/users listing (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U3. Request-trail
	// capture at every outbound source call"). UserImporter runs once per migration, before
	// any site-job-specific stage, so the entry is recorded via record_for_migration()
	// (scope: migration) and copied into every sibling site job's report on first creation.
	// -------------------------------------------------------------------------

	private function mock_empty_second_page(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, 'offset=100' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	public function test_process_records_migration_scoped_request_trail_entry_on_success(): void {
		$mid = $this->make_migration();
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 500, 'user_email' => 'audit-trail-500@example.test', 'user_login' => 'audittrail500' ] ),
		] );
		$this->mock_empty_second_page();

		UserImporter::process( $mid, 0, 0 );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'migration', $rows[0]['scope'] );
		$this->assertSame( 'source/users', $rows[0]['path'] );
		$this->assertTrue( $rows[0]['success'] );
		$this->assertSame( 1, $rows[0]['count'] );

		wp_delete_user( IdMap::get( IdMap::NETWORK, 'user', 500 ) );
	}

	public function test_process_records_failed_request_trail_entry_when_source_unreachable(): void {
		$mid = $this->make_migration();
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/users' ) ) {
				return [
					'response' => [ 'code' => 500, 'message' => 'Internal Server Error' ],
					'body'     => '',
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		UserImporter::process( $mid, 0, 0 );

		// Existing error-handling behavior is unchanged by U3: a retryable failure at
		// attempt 0 schedules a retry rather than failing the migration outright.
		$migration = MigrationRegistry::get_migration( $mid );
		$this->assertSame( 'running', $migration->status );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'migration', $rows[0]['scope'] );
		$this->assertFalse( $rows[0]['success'] );
		$this->assertArrayHasKey( 'error', $rows[0] );
	}

	public function test_staged_request_trail_entry_copies_into_every_sibling_site_job(): void {
		$mid  = $this->make_migration();
		$jid1 = MigrationRegistry::create_site_job( $mid, 1, 'a.example.com', 'https://93.184.216.34', '', '/a/' );
		$jid2 = MigrationRegistry::create_site_job( $mid, 2, 'b.example.com', 'https://93.184.216.34', '', '/b/' );

		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 777, 'user_email' => 'audit-sibling-777@example.test', 'user_login' => 'auditsibling777' ] ),
		] );
		$this->mock_empty_second_page();

		UserImporter::process( $mid, 0, 0 );

		$post_id_1 = AuditReport::get_or_create_for_site_job( $jid1 );
		$post_id_2 = AuditReport::get_or_create_for_site_job( $jid2 );

		switch_to_blog( get_main_site_id() );
		$rows_1 = get_post_meta( $post_id_1, '_hbm_audit_request', false );
		$rows_2 = get_post_meta( $post_id_2, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows_1 );
		$this->assertSame( 'source/users', $rows_1[0]['path'] );
		$this->assertCount( 1, $rows_2, 'UserImporter\'s migration-level request-trail entry must be copied into every sibling site job report, not just the first.' );
		$this->assertSame( 'source/users', $rows_2[0]['path'] );

		wp_delete_user( IdMap::get( IdMap::NETWORK, 'user', 777 ) );
	}

	// -------------------------------------------------------------------------
	// U4: write-action trail for users (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U4. Write-action trail:
	// terms, users, options"). UserImporter runs once per migration, before any site-job-specific
	// stage, so entries are recorded via AuditReport::record_for_migration() (scope: migration).
	// -------------------------------------------------------------------------

	private function get_write_rows( int $jid ): array {
		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_write', false );
		restore_current_blog();
		return $rows;
	}

	public function test_process_records_created_outcome_for_newly_created_user(): void {
		$mid = $this->make_migration();
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 601, 'user_email' => 'audit-write-created@example.test', 'user_login' => 'auditwritecreated' ] ),
		] );
		$this->mock_empty_second_page();

		UserImporter::process( $mid, 0, 0 );

		$rows      = $this->get_write_rows( $jid );
		$user_rows = array_values( array_filter( $rows, fn( $r ) => 'user' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( 1, $user_rows );
		$this->assertSame( 'created', $user_rows[0]['outcome'] );
		$this->assertSame( 601, $user_rows[0]['source_id'] );
		$this->assertArrayHasKey( 'dest_id', $user_rows[0] );

		wp_delete_user( IdMap::get( IdMap::NETWORK, 'user', 601 ) );
	}

	public function test_process_records_merged_outcome_for_existing_user_matched_by_email(): void {
		$existing_id = wp_insert_user( [
			'user_login' => 'audit-write-merge-existing',
			'user_email' => 'audit-write-merge@example.test',
			'user_pass'  => 'password',
		] );
		$this->assertNotInstanceOf( \WP_Error::class, $existing_id );

		$mid = $this->make_migration( [ 'user_conflict_policy' => 'merge' ] );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$this->mock_users( [
			$this->make_source_user( [ 'source_user_id' => 602, 'user_email' => 'audit-write-merge@example.test' ] ),
		] );
		$this->mock_empty_second_page();

		UserImporter::process( $mid, 0, 0 );

		$rows      = $this->get_write_rows( $jid );
		$user_rows = array_values( array_filter( $rows, fn( $r ) => 'user' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( 1, $user_rows );
		$this->assertSame( 'merged', $user_rows[0]['outcome'], 'A user matched to an existing destination user must be recorded as "merged", not "created".' );
		$this->assertSame( 602, $user_rows[0]['source_id'] );
		$this->assertSame( $existing_id, $user_rows[0]['dest_id'] );

		wp_delete_user( $existing_id );
	}

	public function test_process_records_failed_outcome_when_wp_insert_user_fails(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'merge' ] );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		// sanitize_user( ..., true ) strips every character from a login made entirely of
		// disallowed characters, leaving an empty string — wp_insert_user() then fails with
		// 'empty_user_login', the existing is_wp_error() branch this loop already handles.
		$this->mock_users( [
			$this->make_source_user( [
				'source_user_id' => 603,
				'user_email'     => 'audit-write-fail@example.test',
				'user_login'     => '###',
			] ),
		] );
		$this->mock_empty_second_page();

		UserImporter::process( $mid, 0, 0 );

		$rows      = $this->get_write_rows( $jid );
		$user_rows = array_values( array_filter( $rows, fn( $r ) => 'user' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( 1, $user_rows );
		$this->assertSame( 'failed', $user_rows[0]['outcome'] );
		$this->assertSame( 603, $user_rows[0]['source_id'] );
		$this->assertArrayHasKey( 'error', $user_rows[0] );

		$this->assertNull( IdMap::get( IdMap::NETWORK, 'user', 603 ), 'A failed user creation must not be mapped in IdMap.' );
	}

	// -------------------------------------------------------------------------
	// R7 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U5. UserImporter
	// batched staged writes"): the per-user staged write-trail entry write is now batched via
	// AuditReport::record_batch_for_migration() instead of one AuditReport::record_for_migration()
	// round-trip per user. These tests confirm the batching preserves the exact same final
	// staged-entry set as the old per-user-immediate behavior, and — the genuinely new failure
	// mode this refactor introduces — that a mid-batch exception does not lose any already-
	// accumulated entry.
	// -------------------------------------------------------------------------

	/**
	 * Builds $count source users with unique, non-colliding emails/logins, all under the
	 * 'create' policy so every user reaches wp_insert_user() (no 'merge' short-circuit before
	 * it) — needed so a counter hooked to user_register fires exactly once per user, in order,
	 * making a forced-exception offset deterministic.
	 */
	private function make_users_batch( int $count, int $start_source_id ): array {
		$users = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$sid     = $start_source_id + $i;
			$users[] = $this->make_source_user( [
				'source_user_id' => $sid,
				'user_email'     => "batch-user-{$sid}@example.test",
				'user_login'     => "batchuser{$sid}",
			] );
		}
		return $users;
	}

	private function cleanup_users_by_source_ids( array $source_ids ): void {
		foreach ( $source_ids as $sid ) {
			$uid = IdMap::get( IdMap::NETWORK, 'user', $sid );
			if ( $uid ) {
				wp_delete_user( $uid );
			}
		}
	}

	/**
	 * Error path (test-first, per the U5 Execution note): a forced exception partway through a
	 * batch — after some users have already been fully processed and accumulated, but before the
	 * periodic flush threshold (25) is reached — must not lose those already-accumulated entries.
	 * This is the one genuinely new failure mode this refactor introduces: today's (pre-U5)
	 * per-user-immediate-write had no equivalent risk, since each user's write was already its
	 * own atomic, already-durable round-trip by the time any later user's processing could throw.
	 *
	 * Forces the exception via a counter on the user_register action (fired synchronously inside
	 * wp_insert_user(), before that call returns) — throwing on the 3rd call means users 1 and 2
	 * were already fully processed (their entries pushed onto the in-loop accumulator) by the
	 * time the exception fires, while user 3 itself never gets an entry recorded at all.
	 */
	public function test_exception_mid_batch_flushes_already_accumulated_entries(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$start_source_id = 8000;
		$this->mock_users( $this->make_users_batch( 5, $start_source_id ) );

		$register_count = 0;
		$thrower         = function () use ( &$register_count ) {
			$register_count++;
			if ( 3 === $register_count ) {
				throw new \RuntimeException( 'forced mid-batch failure for U5 exception-path flush test' );
			}
		};
		add_action( 'user_register', $thrower );

		try {
			UserImporter::process( $mid, 0, 0 );
		} finally {
			remove_action( 'user_register', $thrower );
		}

		// Sanity: the forced exception did not accidentally fail to fire, and did not let the
		// third user's own creation slip through as if nothing happened.
		$this->assertNull(
			IdMap::get( IdMap::NETWORK, 'user', $start_source_id + 2 ),
			'The user whose wp_insert_user() call triggered the forced exception must not be mapped.'
		);

		$rows       = $this->get_write_rows( $jid );
		$user_rows  = array_values( array_filter( $rows, fn( $r ) => 'user' === ( $r['object_type'] ?? null ) ) );
		$source_ids = array_map( fn( $r ) => $r['source_id'], $user_rows );

		$this->assertContains(
			$start_source_id,
			$source_ids,
			'The 1st user, fully processed before the forced exception, must not be lost from the staging option.'
		);
		$this->assertContains(
			$start_source_id + 1,
			$source_ids,
			'The 2nd user, fully processed before the forced exception, must not be lost from the staging option.'
		);

		$this->cleanup_users_by_source_ids( [ $start_source_id, $start_source_id + 1 ] );
	}

	/**
	 * Happy path: processing a number of users that is an exact multiple of the flush threshold
	 * (25) produces the same final staged-entry set as today's per-user-immediate behavior would
	 * have — a before/after equivalence check. 50 users means the periodic flush fires exactly
	 * twice (at 25 and 50), with nothing left for an end-of-loop flush to pick up.
	 */
	public function test_batch_multiple_of_flush_threshold_produces_same_final_entry_set(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$start_source_id = 9000;
		$count           = 50;
		$this->mock_users( $this->make_users_batch( $count, $start_source_id ) );
		$this->mock_empty_second_page();

		UserImporter::process( $mid, 0, 0 );

		$rows      = $this->get_write_rows( $jid );
		$user_rows = array_values( array_filter( $rows, fn( $r ) => 'user' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( $count, $user_rows, 'Every one of the 50 users must have exactly one staged write-trail entry.' );

		$source_ids = array_map( fn( $r ) => $r['source_id'], $user_rows );
		sort( $source_ids );
		$expected = range( $start_source_id, $start_source_id + $count - 1 );
		$this->assertSame( $expected, $source_ids, 'Every user must be represented exactly once, none lost, none duplicated.' );

		foreach ( $user_rows as $row ) {
			$this->assertSame( 'created', $row['outcome'] );
		}

		$this->cleanup_users_by_source_ids( $expected );
	}

	/**
	 * Edge case: a partial batch (not a multiple of the flush threshold — 17 users against a
	 * threshold of 25) must still flush every accumulated entry via the unconditional
	 * end-of-loop flush, not just the first 0 (the periodic threshold is never reached).
	 */
	public function test_partial_batch_below_flush_threshold_still_flushes_all_entries(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$start_source_id = 9500;
		$count           = 17;
		$this->mock_users( $this->make_users_batch( $count, $start_source_id ) );
		$this->mock_empty_second_page();

		UserImporter::process( $mid, 0, 0 );

		$rows      = $this->get_write_rows( $jid );
		$user_rows = array_values( array_filter( $rows, fn( $r ) => 'user' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount(
			$count,
			$user_rows,
			'All 17 accumulated entries must be present — not silently dropped because the periodic threshold (25) was never reached.'
		);

		$source_ids = array_map( fn( $r ) => $r['source_id'], $user_rows );
		sort( $source_ids );
		$expected = range( $start_source_id, $start_source_id + $count - 1 );
		$this->assertSame( $expected, $source_ids );

		$this->cleanup_users_by_source_ids( $expected );
	}

	/**
	 * Integration: a self-chained UserImporter::process() run spanning multiple invocations
	 * (100 users so the periodic Action-Scheduler continuation path fires — see the
	 * "count( $users ) >= 100" circuit-breaker check — followed by a second, smaller invocation
	 * simulating the next self-chained batch) must not lose or duplicate any user's staged entry
	 * across that invocation boundary.
	 */
	public function test_self_chained_invocations_do_not_lose_or_duplicate_entries_across_boundary(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$first_start  = 10000;
		$second_start = 20000;

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $first_start, $second_start ) {
			if ( false !== strpos( $url, 'offset=0' ) && false === strpos( $url, 'offset=100' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $this->make_users_batch( 100, $first_start ) ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			if ( false !== strpos( $url, 'offset=100' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $this->make_users_batch( 10, $second_start ) ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		// First invocation: 100 users triggers the periodic self-enqueue continuation path
		// (count($users) >= 100) rather than falling through to "all done".
		UserImporter::process( $mid, 0, 0 );

		// Second invocation: simulates the self-chained continuation Action Scheduler would have
		// enqueued (offset + 100), processing the remaining 10 users through to completion.
		UserImporter::process( $mid, 100, 0 );

		$rows       = $this->get_write_rows( $jid );
		$user_rows  = array_values( array_filter( $rows, fn( $r ) => 'user' === ( $r['object_type'] ?? null ) ) );
		$source_ids = array_map( fn( $r ) => $r['source_id'], $user_rows );
		sort( $source_ids );

		$expected = array_merge(
			range( $first_start, $first_start + 99 ),
			range( $second_start, $second_start + 9 )
		);
		$this->assertSame(
			$expected,
			$source_ids,
			'Every user across both self-chained invocations must be represented exactly once — none lost, none duplicated at the invocation boundary.'
		);

		$this->cleanup_users_by_source_ids( $expected );
	}

	/**
	 * Verification (call-count spy): batching must produce measurably fewer update_site_option()
	 * calls than the old per-user-immediate behavior would have. 50 users at a flush threshold
	 * of 25 means exactly 2 batched write-entry flushes (at the 25th and 50th user), plus the one
	 * pre-existing, unbatched record_for_migration() call for the "source/users request
	 * succeeded" trail entry (recorded once per process() call, outside U5's scope — see
	 * process()'s own request-trail block) — 3 total, vs. 51 (1 request-trail + 50 per-user)
	 * under the old per-user-immediate scheme.
	 */
	public function test_batching_produces_fewer_update_site_option_calls_than_per_user_would(): void {
		$mid = $this->make_migration( [ 'user_conflict_policy' => 'create' ] );
		MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/' );

		$start_source_id = 11000;
		$count           = 50;
		$this->mock_users( $this->make_users_batch( $count, $start_source_id ) );
		$this->mock_empty_second_page();

		$option_key    = 'hbm_audit_staged_' . $mid;
		$update_calls  = 0;
		$spy           = function ( $value, $old_value, $option ) use ( $option_key, &$update_calls ) {
			if ( $option === $option_key ) {
				$update_calls++;
			}
			return $value;
		};
		add_filter( 'pre_update_site_option_' . $option_key, $spy, 10, 3 );

		try {
			UserImporter::process( $mid, 0, 0 );
		} finally {
			remove_filter( 'pre_update_site_option_' . $option_key, $spy, 10 );
		}

		$this->assertSame(
			3,
			$update_calls,
			'50 users at flush threshold 25 must produce exactly 3 update_site_option() calls (1 request-trail + 2 batched user-entry flushes), far fewer than the 51 the old per-user-immediate scheme would have made.'
		);

		$this->cleanup_users_by_source_ids( range( $start_source_id, $start_source_id + $count - 1 ) );
	}
}
