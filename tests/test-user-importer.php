<?php
/**
 * Tests for UserImporter user conflict policy behaviour.
 */

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
}
