<?php
/**
 * Tests for SiteIndex::proxy_migration_status() — dest_key cleared on completion,
 * history saved, and AdminPage::save_history_entry() deduplication.
 */

use HBMigrator\Source\SiteIndex;
use HBMigrator\Admin\AdminPage;

class Test_SiteIndex extends WP_UnitTestCase {

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
		delete_site_option( 'hbm_active_migration' );
		delete_site_option( 'hbm_migration_history' );
	}

	private function set_active_migration( array $overrides = [] ): void {
		update_site_option( 'hbm_active_migration', array_merge( [
			'migration_id' => 42,
			'dest_url'     => 'https://93.184.216.34',
			'dest_key'     => 'super-secret-bearer-token',
			'status_token' => 'abc123',
			'started_at'   => 1750000000,
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

	// -------------------------------------------------------------------------
	// Migration history
	// -------------------------------------------------------------------------

	public function test_history_entry_saved_when_migration_complete(): void {
		$this->set_active_migration( [ 'migration_id' => 77, 'started_at' => 1750000000 ] );
		$dest_body = [
			'status' => 'complete',
			'sites'  => [ [ 'source_domain' => 'source.test', 'dest_path' => '/', 'status' => 'complete', 'error_message' => null ] ],
		];
		$this->mock_destination_response( $dest_body );

		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		SiteIndex::proxy_migration_status( $req );

		$history = (array) get_site_option( 'hbm_migration_history', [] );
		$this->assertCount( 1, $history );
		$this->assertSame( 77, (int) $history[0]['migration_id'] );
		$this->assertSame( 'complete', $history[0]['status'] );
		$this->assertSame( 'source.test', $history[0]['sites'][0]['source_domain'] );
		$this->assertSame( 1750000000, (int) $history[0]['started_at'] );
	}

	public function test_history_not_duplicated_on_second_complete_poll(): void {
		$this->set_active_migration( [ 'migration_id' => 88 ] );
		$dest_body = [ 'status' => 'complete', 'sites' => [] ];
		$this->mock_destination_response( $dest_body );

		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		SiteIndex::proxy_migration_status( $req );
		SiteIndex::proxy_migration_status( $req );

		$history = (array) get_site_option( 'hbm_migration_history', [] );
		$entries_for_88 = array_filter( $history, fn( $e ) => (int) ( $e['migration_id'] ?? 0 ) === 88 );
		$this->assertCount( 1, $entries_for_88, 'Duplicate history entry must not be saved on repeated polls.' );
	}

	public function test_history_capped_at_ten_entries(): void {
		// Pre-fill with 10 entries.
		$existing = [];
		for ( $i = 1; $i <= 10; $i++ ) {
			$existing[] = [ 'migration_id' => $i, 'status' => 'complete', 'sites' => [], 'dest_url' => '', 'started_at' => 0, 'saved_at' => 0 ];
		}
		update_site_option( 'hbm_migration_history', $existing );

		$this->set_active_migration( [ 'migration_id' => 99 ] );
		$this->mock_destination_response( [ 'status' => 'complete', 'sites' => [] ] );

		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		SiteIndex::proxy_migration_status( $req );

		$history = (array) get_site_option( 'hbm_migration_history', [] );
		$this->assertCount( 10, $history, 'History must be capped at 10 entries.' );
		$this->assertSame( 99, (int) $history[0]['migration_id'], 'Newest entry must be first.' );
		// Oldest entry (id=1) should have been dropped.
		$ids = array_column( $history, 'migration_id' );
		$this->assertNotContains( 1, $ids );
	}

	public function test_history_not_saved_when_migration_still_running(): void {
		$this->set_active_migration();
		$this->mock_destination_response( [ 'status' => 'running', 'sites' => [] ] );

		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/migration-status' );
		SiteIndex::proxy_migration_status( $req );

		$history = get_site_option( 'hbm_migration_history', [] );
		$this->assertEmpty( $history, 'History must not be saved while migration is still running.' );
	}

	// -------------------------------------------------------------------------
	// save_history_entry() standalone behaviour
	// -------------------------------------------------------------------------

	public function test_save_history_entry_noop_without_active_migration(): void {
		delete_site_option( 'hbm_active_migration' );
		AdminPage::save_history_entry( [ 'status' => 'complete', 'sites' => [] ] );
		$this->assertEmpty( get_site_option( 'hbm_migration_history', [] ) );
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
