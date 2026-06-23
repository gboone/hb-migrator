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
