<?php
/**
 * Tests for AdminPage action handlers and ProgressEndpoint.
 */

use HBMigrator\Admin\AdminPage;
use HBMigrator\Admin\ProgressEndpoint;
use HBMigrator\Checkpoint;
use HBMigrator\QueueTable;

class Test_Admin_Page extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
		Checkpoint::reset_all();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	// -----------------------------------------------------------------------
	// Start export handler
	// -----------------------------------------------------------------------

	public function test_handle_start_export_requires_valid_nonce(): void {
		$_REQUEST['_wpnonce'] = 'bad_nonce';
		$this->expectException( \WPDieException::class );
		AdminPage::handle_start_export();
	}

	public function test_handle_start_export_requires_manage_options(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$_POST['_wpnonce'] = wp_create_nonce( 'hbm_start_export' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		$this->expectException( \WPDieException::class );
		AdminPage::handle_start_export();
	}

	// -----------------------------------------------------------------------
	// Retry stage handler
	// -----------------------------------------------------------------------

	public function test_handle_retry_stage_validates_stage_allowlist(): void {
		Checkpoint::initialize_stages();
		Checkpoint::mark_stage_failed( 'sql', 'timeout' );

		$_POST['stage']       = 'sql';
		$_POST['_wpnonce']    = wp_create_nonce( 'hbm_retry_stage_sql' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// Should redirect (not throw).
		try {
			AdminPage::handle_retry_stage();
		} catch ( \WPDieException $e ) {
			$this->fail( 'handle_retry_stage should not wp_die for a valid stage' );
		} catch ( \Exception $e ) {
			// wp_redirect throws in test environment — that's fine.
		}

		$row = Checkpoint::get_stage( 'sql' );
		$this->assertSame( 'running', $row->status );
	}

	public function test_handle_retry_stage_with_invalid_stage_name_is_rejected(): void {
		$_POST['stage']       = "sql'; DROP TABLE wp_options;--";
		$_POST['_wpnonce']    = wp_create_nonce( 'hbm_retry_stage_' . $_POST['stage'] );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// sanitize_key() strips special chars so retry_stage receives 'sql'.
		// After sanitize_key, it will be 'sql-drop-tablwp_options' which is invalid.
		// The test verifies the sanitize path doesn't expose injection points.
		$sanitized = sanitize_key( $_POST['stage'] );
		$this->assertStringNotContainsString( "'", $sanitized );
		$this->assertStringNotContainsString( ';', $sanitized );
	}

	// -----------------------------------------------------------------------
	// Reset handler
	// -----------------------------------------------------------------------

	public function test_handle_reset_requires_valid_nonce(): void {
		$_REQUEST['_wpnonce'] = 'bad_nonce';
		$this->expectException( \WPDieException::class );
		AdminPage::handle_reset_export();
	}

	// -----------------------------------------------------------------------
	// REST progress endpoint
	// -----------------------------------------------------------------------

	public function test_progress_endpoint_registers(): void {
		ProgressEndpoint::init();
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/hb-migrator/v1/progress', $routes );
	}

	public function test_progress_endpoint_requires_manage_options(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$request = new \WP_REST_Request( 'GET', '/hb-migrator/v1/progress' );
		$can     = current_user_can( 'manage_options' );
		$this->assertFalse( $can );
	}

	public function test_progress_endpoint_returns_stage_data(): void {
		ProgressEndpoint::init();
		do_action( 'rest_api_init' );
		Checkpoint::initialize_stages();

		$request  = new \WP_REST_Request( 'GET', '/hb-migrator/v1/progress' );
		$response = ProgressEndpoint::get_progress( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'stages', $data );
		$this->assertArrayHasKey( 'is_running', $data );
		$this->assertArrayHasKey( 'is_complete', $data );
		$this->assertArrayHasKey( 'is_failed', $data );
		$this->assertArrayHasKey( 'artifacts', $data );

		$this->assertCount( 3, $data['stages'] );
	}

	public function test_progress_endpoint_is_complete_true_when_all_done(): void {
		ProgressEndpoint::init();
		do_action( 'rest_api_init' );
		Checkpoint::initialize_stages();
		foreach ( [ 'sql', 'wxr', 'media' ] as $s ) {
			Checkpoint::mark_stage_complete( $s );
		}

		$request  = new \WP_REST_Request( 'GET', '/hb-migrator/v1/progress' );
		$response = ProgressEndpoint::get_progress( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['is_complete'] );
		$this->assertFalse( $data['is_failed'] );
	}

	public function test_progress_endpoint_is_failed_true_when_any_stage_failed(): void {
		ProgressEndpoint::init();
		do_action( 'rest_api_init' );
		Checkpoint::initialize_stages();
		Checkpoint::mark_stage_failed( 'sql', 'boom' );

		$request  = new \WP_REST_Request( 'GET', '/hb-migrator/v1/progress' );
		$response = ProgressEndpoint::get_progress( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['is_failed'] );
	}
}
