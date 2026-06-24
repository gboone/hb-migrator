<?php
/**
 * Tests for Admin\AdminPage and SearchReplace (v2).
 */

use HBMigrator\Admin\AdminPage;
use HBMigrator\Destination\SearchReplace;

class Test_Admin_Page extends WP_UnitTestCase {

	public function tear_down(): void {
		parent::tear_down();
		delete_site_option( 'hbm_dest_url' );
		delete_site_option( 'hbm_dest_key' );
		delete_site_option( 'hbm_active_migration' );
		delete_site_option( 'hbm_migration_history' );
		remove_all_filters( 'pre_http_request' );
	}

	// -------------------------------------------------------------------------
	// render_page() — pre-flight button is present when config is saved.
	// -------------------------------------------------------------------------

	public function test_render_page_shows_preflight_button_when_config_saved(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'render_page() calls get_sites() — requires multisite.' );
		}

		update_site_option( 'hbm_dest_url', 'https://93.184.216.34' );
		update_site_option( 'hbm_dest_key', 'test-key' );

		ob_start();
		AdminPage::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="hbm-preflight-btn"', $html );
	}

	public function test_render_page_does_not_show_direct_start_migration_submit(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'render_page() calls get_sites() — requires multisite.' );
		}

		update_site_option( 'hbm_dest_url', 'https://93.184.216.34' );
		update_site_option( 'hbm_dest_key', 'test-key' );

		ob_start();
		AdminPage::render_page();
		$html = ob_get_clean();

		// The direct submit button should be inside the hidden start form, not as the primary action.
		$this->assertStringContainsString( 'id="hbm-preflight-start"', $html );
	}

	// -------------------------------------------------------------------------
	// handle_start_migration() — conflict policies passed to destination.
	// -------------------------------------------------------------------------

	public function test_handle_start_migration_passes_user_conflict_policy_to_destination(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'handle_start_migration() requires multisite network.' );
		}

		update_site_option( 'hbm_dest_url', 'https://93.184.216.34' );
		update_site_option( 'hbm_dest_key', 'test-key' );

		$captured_body = null;
		add_filter( 'pre_http_request', function ( $preempt, $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return [
				'response' => [ 'code' => 201, 'message' => 'Created' ],
				'body'     => wp_json_encode( [ 'migration_id' => 1, 'status_token' => 'tok' ] ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 2 );

		$_POST = [
			'_wpnonce'            => wp_create_nonce( 'hbm_start_migration' ),
			'site_ids'            => [ 1 ],
			'user_conflict_policy' => 'create',
		];

		// Call handle_start_migration() without the exit — we test the request body directly.
		try {
			AdminPage::handle_start_migration();
		} catch ( \Throwable $e ) {
			// wp_safe_redirect() may throw in test context — that's fine.
		}

		$_POST = [];

		$this->assertIsArray( $captured_body, 'Should have made an HTTP request to destination.' );
		$this->assertSame( 'create', $captured_body['user_conflict_policy'] ?? null );
	}

	public function test_handle_start_migration_uses_default_policies_when_omitted(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'handle_start_migration() requires multisite network.' );
		}

		update_site_option( 'hbm_dest_url', 'https://93.184.216.34' );
		update_site_option( 'hbm_dest_key', 'test-key' );

		$captured_body = null;
		add_filter( 'pre_http_request', function ( $preempt, $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return [
				'response' => [ 'code' => 201, 'message' => 'Created' ],
				'body'     => wp_json_encode( [ 'migration_id' => 2, 'status_token' => 'tok' ] ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 2 );

		$_POST = [
			'_wpnonce' => wp_create_nonce( 'hbm_start_migration' ),
			'site_ids' => [ 1 ],
			// No policy fields.
		];

		try {
			AdminPage::handle_start_migration();
		} catch ( \Throwable $e ) {
			// wp_safe_redirect() may throw.
		}

		$_POST = [];

		$this->assertIsArray( $captured_body );
		$this->assertSame( 'merge',        $captured_body['user_conflict_policy']  ?? null );
		$this->assertSame( 'generate_new', $captured_body['site_conflict_policy']  ?? null );
		$this->assertSame( 'import_all',   $captured_body['media_conflict_policy'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Migration history — handle_start_migration() stores started_at
	// -------------------------------------------------------------------------

	public function test_handle_start_migration_stores_started_at(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'handle_start_migration() requires multisite network.' );
		}

		update_site_option( 'hbm_dest_url', 'https://93.184.216.34' );
		update_site_option( 'hbm_dest_key', 'test-key' );

		add_filter( 'pre_http_request', function ( $preempt ) {
			return [
				'response' => [ 'code' => 201, 'message' => 'Created' ],
				'body'     => wp_json_encode( [ 'migration_id' => 10, 'status_token' => 'tok' ] ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		} );

		$_POST = [ '_wpnonce' => wp_create_nonce( 'hbm_start_migration' ), 'site_ids' => [ 1 ] ];

		$before = time();
		try {
			AdminPage::handle_start_migration();
		} catch ( \Throwable $e ) {}

		$_POST = [];

		$stored = get_site_option( 'hbm_active_migration' );
		$this->assertArrayHasKey( 'started_at', $stored, 'started_at must be stored in hbm_active_migration.' );
		$this->assertGreaterThanOrEqual( $before, (int) $stored['started_at'] );
	}

	// -------------------------------------------------------------------------
	// Migration history — handle_clear_migration() saves history before clearing
	// -------------------------------------------------------------------------

	public function test_handle_clear_migration_saves_history_entry_before_deleting(): void {
		update_site_option( 'hbm_active_migration', [
			'migration_id' => 55,
			'dest_url'     => 'https://93.184.216.34',
			'dest_key'     => 'key',
			'status_token' => 'tok',
			'started_at'   => 1750000000,
		] );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/destination/status/' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [ 'status' => 'complete', 'sites' => [ [ 'source_domain' => 'src.test', 'dest_path' => '/', 'status' => 'complete', 'error_message' => null ] ] ] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		$_POST = [ '_wpnonce' => wp_create_nonce( 'hbm_clear_migration' ) ];

		try {
			AdminPage::handle_clear_migration();
		} catch ( \Throwable $e ) {}

		$_POST = [];

		$history = (array) get_site_option( 'hbm_migration_history', [] );
		$this->assertNotEmpty( $history, 'History must be saved when migration is cleared.' );
		$this->assertSame( 55, (int) $history[0]['migration_id'] );
		$this->assertSame( 'complete', $history[0]['status'] );

		// Active migration must have been deleted.
		$this->assertFalse( get_site_option( 'hbm_active_migration' ) );
	}

	public function test_handle_clear_migration_saves_unknown_status_when_destination_unreachable(): void {
		update_site_option( 'hbm_active_migration', [
			'migration_id' => 66,
			'dest_url'     => 'https://93.184.216.34',
			'dest_key'     => 'key',
			'status_token' => 'tok',
			'started_at'   => 0,
		] );

		// Destination is unreachable.
		add_filter( 'pre_http_request', function () {
			return new \WP_Error( 'http_request_failed', 'Connection refused.' );
		} );

		$_POST = [ '_wpnonce' => wp_create_nonce( 'hbm_clear_migration' ) ];

		try {
			AdminPage::handle_clear_migration();
		} catch ( \Throwable $e ) {}

		$_POST = [];

		$history = (array) get_site_option( 'hbm_migration_history', [] );
		$this->assertNotEmpty( $history );
		$this->assertSame( 66, (int) $history[0]['migration_id'] );
		$this->assertSame( 'unknown', $history[0]['status'], 'Status must be "unknown" when destination is unreachable.' );

		$this->assertFalse( get_site_option( 'hbm_active_migration' ) );
	}

	// -------------------------------------------------------------------------
	// render_page() shows Past Migrations section
	// -------------------------------------------------------------------------

	public function test_render_page_shows_past_migrations_when_history_exists(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'render_page() calls get_sites() — requires multisite.' );
		}

		update_site_option( 'hbm_migration_history', [
			[ 'migration_id' => 7, 'dest_url' => 'https://dest.test', 'started_at' => 1750000000, 'saved_at' => 1750001000, 'status' => 'complete', 'sites' => [] ],
		] );

		ob_start();
		AdminPage::render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Past Migrations', $html );
		$this->assertStringContainsString( 'dest.test', $html );
	}

	public function test_render_page_hides_past_migrations_section_when_history_empty(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'render_page() calls get_sites() — requires multisite.' );
		}

		delete_site_option( 'hbm_migration_history' );

		ob_start();
		AdminPage::render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'Past Migrations', $html );
	}

	public function test_search_replace_plain_string(): void {
		$result = SearchReplace::safe_replace( 'https://old.example.com/page', [
			'https://old.example.com' => 'https://new.example.com',
		] );
		$this->assertSame( 'https://new.example.com/page', $result );
	}

	public function test_search_replace_serialized_string(): void {
		$original = serialize( [ 'url' => 'https://old.example.com' ] );
		$result   = SearchReplace::safe_replace( $original, [
			'https://old.example.com' => 'https://new.example.com',
		] );
		$decoded = unserialize( $result );
		$this->assertSame( 'https://new.example.com', $decoded['url'] );
	}

	public function test_search_replace_nested_serialized(): void {
		$data     = [ 'a' => [ 'b' => 'https://old.example.com/wp-content/uploads/img.jpg' ] ];
		$original = serialize( $data );
		$result   = SearchReplace::safe_replace( $original, [
			'https://old.example.com' => 'https://new.example.com',
		] );
		$decoded = unserialize( $result );
		$this->assertSame( 'https://new.example.com/wp-content/uploads/img.jpg', $decoded['a']['b'] );
	}

	public function test_search_replace_non_string_passthrough(): void {
		$this->assertSame( 42, SearchReplace::safe_replace( 42, [ 'foo' => 'bar' ] ) );
		$this->assertNull( SearchReplace::safe_replace( null, [ 'foo' => 'bar' ] ) );
	}

	public function test_search_replace_multiple_replacements(): void {
		$result = SearchReplace::safe_replace( 'https://old.example.com/wp-content/uploads/', [
			'https://old.example.com'                       => 'https://dest.example.com/old.example.com',
			'https://old.example.com/wp-content/uploads/'   => 'https://dest.example.com/wp-content/uploads/sites/3/',
		] );
		// First replacement wins since str_replace is sequential.
		$this->assertStringContainsString( 'dest.example.com', $result );
	}
}
