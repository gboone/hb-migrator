<?php
/**
 * Tests for SiteIndex::proxy_migration_status() — dest_key cleared on completion.
 */

use HBMigrator\Source\SiteIndex;

class Test_SiteIndex extends WP_UnitTestCase {

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
		delete_site_option( 'hbm_active_migration' );
	}

	private function set_active_migration( array $overrides = [] ): void {
		update_site_option( 'hbm_active_migration', array_merge( [
			'migration_id' => 42,
			'dest_url'     => 'https://93.184.216.34',
			'dest_key'     => 'super-secret-bearer-token',
			'status_token' => 'abc123',
		], $overrides ) );
	}

	private function mock_destination_response( array $body, int $code = 200 ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $body, $code ) {
			if ( false !== strpos( $url, '/destination/status/' ) ) {
				return [
					'response' => [ 'code' => $code, 'message' => 'OK' ],
					'body'     => wp_json_encode( $body ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	public function test_dest_key_cleared_when_migration_complete(): void {
		$this->set_active_migration();
		$this->mock_destination_response( [ 'status' => 'complete', 'sites' => [] ] );

		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		SiteIndex::proxy_migration_status( $req );

		$stored = get_site_option( 'hbm_active_migration' );
		$this->assertSame( '', $stored['dest_key'], 'dest_key must be cleared after migration completes.' );
	}

	public function test_dest_key_retained_while_migration_running(): void {
		$this->set_active_migration();
		$this->mock_destination_response( [ 'status' => 'running', 'sites' => [] ] );

		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		SiteIndex::proxy_migration_status( $req );

		$stored = get_site_option( 'hbm_active_migration' );
		$this->assertSame( 'super-secret-bearer-token', $stored['dest_key'] );
	}

	public function test_proxy_returns_404_when_no_active_migration(): void {
		delete_site_option( 'hbm_active_migration' );
		$req      = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		$response = SiteIndex::proxy_migration_status( $req );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_proxy_returns_destination_body_to_caller(): void {
		$this->set_active_migration();
		$dest_body = [ 'status' => 'running', 'sites' => [ [ 'site_job_id' => 1, 'status' => 'running' ] ] ];
		$this->mock_destination_response( $dest_body );

		$req      = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		$response = SiteIndex::proxy_migration_status( $req );
		$data     = $response->get_data();

		$this->assertSame( 'running', $data['status'] );
		$this->assertCount( 1, $data['sites'] );
	}
}
