<?php
/**
 * Tests for OptionImporter — denylist enforcement and normal option import.
 *
 * Uses pre_http_request to mock SourceClient responses. The source URL
 * 'https://93.184.216.34' (example.com's IP) passes SourceClient's IP
 * validation without a real DNS lookup.
 */

use HBMigrator\Destination\OptionImporter;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_OptionImporter extends WP_UnitTestCase {

	private int $mid;
	private int $jid;

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();

		$this->mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'testkey', null );
		$this->jid = MigrationRegistry::create_site_job(
			$this->mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/'
		);
		MigrationRegistry::update_migration_status( $this->mid, 'running' );
		MigrationRegistry::update_site_job( $this->jid, [ 'dest_blog_id' => get_current_blog_id() ] );
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
		delete_option( 'hbm_test_imported_option' );
	}

	private function mock_options_response( array $options, bool $has_more = false ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $options, $has_more ) {
			if ( false !== strpos( $url, '/options' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [ 'options' => $options, 'has_more' => $has_more ] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	public function test_denylist_options_are_not_written(): void {
		$this->mock_options_response( [
			'auth_key'    => 'evil-secret',
			'admin_email' => 'attacker@evil.com',
			'siteurl'     => 'https://attacker.com',
			'home'        => 'https://attacker.com',
		] );

		OptionImporter::process( $this->jid, 0, 0 );

		$this->assertNotSame( 'evil-secret', get_option( 'auth_key' ) );
		$this->assertNotSame( 'attacker@evil.com', get_option( 'admin_email' ) );
		$this->assertNotSame( 'https://attacker.com', get_option( 'siteurl' ) );
		$this->assertNotSame( 'https://attacker.com', get_option( 'home' ) );
	}

	public function test_regular_options_are_imported(): void {
		$this->mock_options_response( [
			'hbm_test_imported_option' => 'hello-from-source',
		] );

		OptionImporter::process( $this->jid, 0, 0 );

		$this->assertSame( 'hello-from-source', get_option( 'hbm_test_imported_option' ) );
	}

	public function test_denylist_and_regular_options_coexist_in_same_response(): void {
		$this->mock_options_response( [
			'auth_key'                 => 'evil-secret',
			'hbm_test_imported_option' => 'safe-value',
		] );

		OptionImporter::process( $this->jid, 0, 0 );

		$this->assertNotSame( 'evil-secret', get_option( 'auth_key' ) );
		$this->assertSame( 'safe-value', get_option( 'hbm_test_imported_option' ) );
	}

	public function test_serialized_option_value_is_imported_correctly(): void {
		// OptionImporter calls unserialize with allowed_classes:false before update_option()
		// so the stored value is the deserialized array, not a raw serialized string.
		$stored_as_string  = serialize( [ 'key' => 'value', 'num' => 42 ] );
		$this->mock_options_response( [ 'hbm_test_imported_option' => $stored_as_string ] );

		OptionImporter::process( $this->jid, 0, 0 );

		$retrieved = get_option( 'hbm_test_imported_option' );
		$this->assertIsArray( $retrieved );
		$this->assertSame( 'value', $retrieved['key'] );
		$this->assertSame( 42, $retrieved['num'] );
	}
}
