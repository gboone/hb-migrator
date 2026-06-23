<?php
/**
 * Tests for PreflightChecker and the /destination/preflight endpoint.
 */

use HBMigrator\Destination\PreflightChecker;
use HBMigrator\Destination\MigrationReceiver;

class Test_Destination_Preflight extends WP_UnitTestCase {

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
	}

	// -------------------------------------------------------------------------
	// No conflicts — clean payloads return empty conflict arrays.
	// -------------------------------------------------------------------------

	public function test_check_with_empty_payload_returns_empty_conflicts(): void {
		$checker = new PreflightChecker();
		$result  = $checker->check( [] );

		$this->assertSame( [], $result['conflicts']['users'] );
		$this->assertSame( [], $result['conflicts']['sites'] );
		$this->assertSame( [], $result['conflicts']['media'] );
	}

	public function test_check_with_nonexistent_email_returns_no_user_conflict(): void {
		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'user_emails' => [ 'nobody@example.invalid' ],
		] );

		$this->assertSame( [], $result['conflicts']['users'] );
	}

	// -------------------------------------------------------------------------
	// User email conflict detection.
	// -------------------------------------------------------------------------

	public function test_check_detects_existing_user_email(): void {
		$user_id = wp_insert_user( [
			'user_login' => 'preflight-tester',
			'user_email' => 'preflight-tester@example.test',
			'user_pass'  => 'password',
		] );

		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'user_emails' => [ 'preflight-tester@example.test' ],
		] );

		$this->assertContains( 'preflight-tester@example.test', $result['conflicts']['users'] );

		wp_delete_user( $user_id );
	}

	public function test_check_does_not_flag_nonconflicting_email(): void {
		$user_id = wp_insert_user( [
			'user_login' => 'preflight-tester2',
			'user_email' => 'preflight-tester2@example.test',
			'user_pass'  => 'password',
		] );

		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'user_emails' => [ 'different@example.test' ],
		] );

		$this->assertSame( [], $result['conflicts']['users'] );

		wp_delete_user( $user_id );
	}

	// -------------------------------------------------------------------------
	// Site path conflict detection (multisite only).
	// -------------------------------------------------------------------------

	public function test_check_site_paths_returns_empty_when_not_multisite(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'This test is for single-site only.' );
		}

		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'site_paths' => [ '/somesite/' ],
		] );

		$this->assertSame( [], $result['conflicts']['sites'] );
	}

	// -------------------------------------------------------------------------
	// Media conflict detection.
	// -------------------------------------------------------------------------

	public function test_check_media_returns_empty_when_no_items(): void {
		$checker = new PreflightChecker();
		$result  = $checker->check( [ 'media' => [] ] );

		$this->assertSame( [], $result['conflicts']['media'] );
	}

	public function test_check_media_skips_item_with_missing_fields(): void {
		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'media' => [
				[ 'md5' => 'abc123' ], // missing blog_id and post_name
			],
		] );

		$this->assertSame( [], $result['conflicts']['media'] );
	}

	public function test_check_media_no_match_when_attachment_does_not_exist(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Media check requires a resolvable blog_id.' );
		}

		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'media' => [
				[
					'blog_id'   => 1,
					'post_name' => 'nonexistent-image',
					'md5'       => md5( 'something' ),
				],
			],
		] );

		$this->assertSame( [], $result['conflicts']['media'] );
	}

	// -------------------------------------------------------------------------
	// Endpoint: no Bearer token → permission_callback rejects (401/403).
	// -------------------------------------------------------------------------

	public function test_preflight_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . HBM_API_NAMESPACE . '/destination/preflight', $routes );
	}

	public function test_preflight_endpoint_with_no_auth_returns_error(): void {
		$req = new WP_REST_Request( 'POST', '/' . HBM_API_NAMESPACE . '/destination/preflight' );
		// No Authorization header — ApiAuth::verify_request should fail.
		$response = rest_get_server()->dispatch( $req );
		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	// -------------------------------------------------------------------------
	// Endpoint: malformed payload — safe empty results, no PHP error.
	// -------------------------------------------------------------------------

	public function test_preflight_callback_with_malformed_payload_returns_empty(): void {
		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'user_emails' => 'not-an-array',
			'media'       => null,
		] );

		$this->assertIsArray( $result['conflicts']['users'] );
		$this->assertIsArray( $result['conflicts']['media'] );
	}
}
