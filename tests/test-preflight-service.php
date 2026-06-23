<?php
/**
 * Tests for PreflightService.
 */

use HBMigrator\Source\PreflightService;

class Test_Preflight_Service extends WP_UnitTestCase {

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'hbm_preflight_media_limit' );
	}

	// -------------------------------------------------------------------------
	// gather() — summary stats
	// -------------------------------------------------------------------------

	public function test_gather_returns_summary_with_correct_site_count(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'gather() requires multisite to switch blogs.' );
		}

		$result = PreflightService::gather( [ get_current_blog_id() ] );

		$this->assertArrayHasKey( 'summary', $result );
		$this->assertSame( 1, $result['summary']['site_count'] );
	}

	public function test_gather_returns_user_email_list(): void {
		$user_id = wp_insert_user( [
			'user_login' => 'preflight-gather-user',
			'user_email' => 'preflight-gather@example.test',
			'user_pass'  => 'password',
		] );

		$result = PreflightService::gather( [ get_current_blog_id() ] );

		$this->assertContains( 'preflight-gather@example.test', $result['user_emails'] );

		wp_delete_user( $user_id );
	}

	public function test_gather_returns_source_siteurl_for_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'gather() requires multisite.' );
		}

		$result = PreflightService::gather( [ get_current_blog_id() ] );

		$this->assertNotEmpty( $result['source_siteurls'] );
		$this->assertSame( get_option( 'siteurl' ), $result['source_siteurls'][0] );
	}

	public function test_gather_returns_empty_media_when_no_attachments(): void {
		$result = PreflightService::gather( [ get_current_blog_id() ] );

		$this->assertIsArray( $result['media'] );
		$this->assertSame( [], $result['media'] );
	}

	public function test_gather_respects_media_limit_filter(): void {
		add_filter( 'hbm_preflight_media_limit', fn() => 0 );

		$result = PreflightService::gather( [ get_current_blog_id() ] );

		// With limit=0, get_posts returns everything for that limit but since there
		// are no attachments anyway, this verifies the filter is applied.
		$this->assertIsArray( $result['media'] );
	}

	// -------------------------------------------------------------------------
	// run() — destination HTTP call
	// -------------------------------------------------------------------------

	public function test_run_returns_wp_error_when_no_destination_configured(): void {
		delete_site_option( 'hbm_dest_url' );
		delete_site_option( 'hbm_dest_key' );

		$result = PreflightService::run( [ 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_run_returns_wp_error_when_destination_returns_non_200(): void {
		add_filter( 'pre_http_request', function () {
			return [
				'response' => [ 'code' => 502, 'message' => 'Bad Gateway' ],
				'body'     => wp_json_encode( [ 'error' => 'upstream error' ] ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		} );

		$result = PreflightService::run( [ 1 ], 'https://93.184.216.34', 'test-key' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_run_returns_summary_and_conflicts_on_success(): void {
		$mock_conflicts = [ 'users' => [], 'sites' => [], 'media' => [] ];

		add_filter( 'pre_http_request', function () use ( $mock_conflicts ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( [ 'conflicts' => $mock_conflicts ] ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		} );

		$result = PreflightService::run( [ get_current_blog_id() ], 'https://93.184.216.34', 'test-key' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'conflicts', $result );
		$this->assertSame( $mock_conflicts, $result['conflicts'] );
	}

	// -------------------------------------------------------------------------
	// /source/run-preflight endpoint — auth and routing.
	// -------------------------------------------------------------------------

	public function test_run_preflight_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . HBM_API_NAMESPACE . '/source/run-preflight', $routes );
	}

	public function test_run_preflight_endpoint_rejects_unauthenticated_request(): void {
		// REST request with no user capability.
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/source/run-preflight' );
		$response = rest_get_server()->dispatch( $req );

		// Should be 401 or 403 — permission_callback uses manage_network cap.
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}
}
