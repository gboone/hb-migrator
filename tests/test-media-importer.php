<?php
/**
 * Tests for MediaImporter media conflict policy behaviour.
 */

use HBMigrator\Destination\MediaImporter;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_Media_Importer extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'upload_dir' );
	}

	// -------------------------------------------------------------------------
	// upload_dir_filter_for_date() — private helper, accessed via ReflectionClass
	// -------------------------------------------------------------------------

	private function call_upload_dir_filter_for_date( string $post_date ): mixed {
		$method = ( new \ReflectionClass( MediaImporter::class ) )
			->getMethod( 'upload_dir_filter_for_date' );
		$method->setAccessible( true );
		return $method->invoke( null, $post_date );
	}

	public function test_upload_dir_filter_returns_null_for_empty_date(): void {
		$filter = $this->call_upload_dir_filter_for_date( '' );
		$this->assertNull( $filter );
	}

	public function test_upload_dir_filter_returns_null_for_invalid_date(): void {
		$filter = $this->call_upload_dir_filter_for_date( 'not-a-date' );
		$this->assertNull( $filter );
	}

	public function test_upload_dir_filter_sets_correct_subdir_for_april(): void {
		$filter = $this->call_upload_dir_filter_for_date( '2026/04/15 10:00:00' );
		$this->assertIsCallable( $filter );

		$dirs = wp_upload_dir();
		$this->assertStringEndsWith( '/2026/04', $dirs['subdir'] );

		remove_filter( 'upload_dir', $filter );
	}

	public function test_upload_dir_filter_sets_correct_subdir_for_different_year(): void {
		$filter = $this->call_upload_dir_filter_for_date( '2023/11/01 00:00:00' );
		$this->assertIsCallable( $filter );

		$dirs = wp_upload_dir();
		$this->assertStringEndsWith( '/2023/11', $dirs['subdir'] );

		remove_filter( 'upload_dir', $filter );
	}

	public function test_upload_dir_filter_sets_path_and_url_consistently(): void {
		$filter = $this->call_upload_dir_filter_for_date( '2024/03/15 10:00:00' );
		$this->assertIsCallable( $filter );

		$dirs = wp_upload_dir();
		$this->assertStringEndsWith( '/2024/03', $dirs['path'] );
		$this->assertStringEndsWith( '/2024/03', $dirs['url'] );

		remove_filter( 'upload_dir', $filter );
	}

	public function test_upload_dir_filter_is_removed_after_call(): void {
		$filter = $this->call_upload_dir_filter_for_date( '2026/04/15 10:00:00' );
		$this->assertIsCallable( $filter );
		remove_filter( 'upload_dir', $filter );

		// After removal, wp_upload_dir() should return current-date subdir (not 2026/04).
		$dirs = wp_upload_dir();
		$this->assertStringNotContainsString( '2026/04', $dirs['subdir'] );
	}

	private function make_migration( array $policies = [] ): int {
		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null, $policies );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		return $mid;
	}

	private function make_site_job( int $mid, int $dest_blog_id = 1 ): int {
		return MigrationRegistry::create_site_job(
			$mid, 1, 'example.com', 'https://93.184.216.34', 'https://93.184.216.34/wp-content/uploads/', '/test/'
		);
	}

	private function mock_media( array $items ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $items ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/media' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $items ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Policy: import_all (default — no skip check, downloads happen normally)
	// -------------------------------------------------------------------------

	public function test_import_all_policy_proceeds_even_when_attachment_name_exists(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$existing_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_name'   => 'my-image',
			'post_status' => 'inherit',
			'post_title'  => 'My Image',
		] );

		$mid  = $this->make_migration( [ 'media_conflict_policy' => 'import_all' ] );
		$jid  = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		// Mock media source — file_url points to the allowed upload host.
		$this->mock_media( [ [
			'source_attachment_id' => 77,
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/my-image.jpg',
			'post_name'            => 'my-image',
			'post_title'           => 'My Image',
			'post_date'            => '2024-01-01 00:00:00',
			'post_parent_source_id' => 0,
			'alt_text'             => '',
			'caption'              => '',
			'description'          => '',
		] ] );

		MediaImporter::process( $jid, 0, 0 );

		// With import_all, the attachment should NOT be in IdMap yet (download would have
		// been attempted but will fail against the fake URL — verify no skip happened).
		$mapped = IdMap::get( $jid, 'attachment', 77 );
		// import_all never short-circuits with the existing attachment ID.
		$this->assertNotSame( $existing_att_id, $mapped );

		wp_delete_post( $existing_att_id, true );
	}

	// -------------------------------------------------------------------------
	// Policy: skip_duplicates
	// -------------------------------------------------------------------------

	public function test_skip_duplicates_reuses_existing_attachment_by_post_name(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$existing_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_name'   => 'skip-dup-image',
			'post_status' => 'inherit',
			'post_title'  => 'Skip Dup Image',
		] );

		$mid = $this->make_migration( [ 'media_conflict_policy' => 'skip_duplicates' ] );
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ [
			'source_attachment_id' => 88,
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/skip-dup-image.jpg',
			'post_name'            => 'skip-dup-image',
			'post_title'           => 'Skip Dup Image',
			'post_date'            => '2024-01-01 00:00:00',
			'post_parent_source_id' => 0,
			'alt_text'             => '',
			'caption'              => '',
			'description'          => '',
		] ] );

		MediaImporter::process( $jid, 0, 0 );

		$mapped = IdMap::get( $jid, 'attachment', 88 );
		$this->assertSame( $existing_att_id, $mapped, 'skip_duplicates must map source attachment to existing dest attachment.' );

		wp_delete_post( $existing_att_id, true );
	}

	public function test_skip_duplicates_downloads_when_no_matching_attachment_exists(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration( [ 'media_conflict_policy' => 'skip_duplicates' ] );
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ [
			'source_attachment_id' => 99,
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/no-match-image.jpg',
			'post_name'            => 'no-match-image',
			'post_title'           => 'No Match Image',
			'post_date'            => '2024-01-01 00:00:00',
			'post_parent_source_id' => 0,
			'alt_text'             => '',
			'caption'              => '',
			'description'          => '',
		] ] );

		MediaImporter::process( $jid, 0, 0 );

		// No existing attachment with that name → download attempted (will fail against fake URL,
		// so no IdMap entry). The key test: IdMap does NOT point to an existing attachment.
		$mapped = IdMap::get( $jid, 'attachment', 99 );
		$this->assertNull( $mapped, 'No existing match — should have attempted download, not set IdMap from existing.' );
	}

	// -------------------------------------------------------------------------
	// Retry logic for failed individual attachments
	// -------------------------------------------------------------------------

	private function mock_download_failure(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			// download_url calls wp_remote_get on the file URL — make it fail.
			if ( false !== strpos( $url, '/wp-content/uploads/' ) ) {
				return new \WP_Error( 'http_request_failed', 'Connection timed out.' );
			}
			return $preempt;
		}, 20, 3 ); // priority 20 so it runs after mock_media (priority 10)
	}

	private function make_attachment_item( int $source_id, string $filename = 'photo.jpg' ): array {
		return [
			'source_attachment_id'  => $source_id,
			'file_url'              => 'https://93.184.216.34/wp-content/uploads/' . $filename,
			'post_name'             => sanitize_title( $filename ),
			'post_title'            => $filename,
			'post_date'             => '2024-01-01 00:00:00',
			'post_parent_source_id' => 0,
			'alt_text'              => '',
			'caption'               => '',
			'description'           => '',
		];
	}

	public function test_download_failure_schedules_retry_action(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 301, 'retry-test.jpg' ) ] );
		$this->mock_download_failure();

		MediaImporter::process( $jid, 0, 0 );

		// A retry action with source_attachment_ids must have been scheduled.
		$scheduled = as_get_scheduled_actions( [
			'hook'     => 'hbm_import_media',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 20,
		] );

		$retry_found = false;
		foreach ( $scheduled as $action ) {
			$args = $action->get_args();
			if ( isset( $args['source_attachment_ids'] ) && in_array( 301, (array) $args['source_attachment_ids'], true ) ) {
				$retry_found = true;
				$this->assertSame( 1, $args['attempt'], 'Retry attempt must be 1.' );
				break;
			}
		}

		$this->assertTrue( $retry_found, 'A retry action with the failed source ID must be scheduled.' );
	}

	public function test_retry_pass_uses_ids_param_not_offset(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$ids_requested = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$ids_requested ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/media' ) ) {
				$parsed = wp_parse_url( $url, PHP_URL_QUERY );
				parse_str( $parsed ?: '', $params );
				if ( isset( $params['ids'] ) ) {
					$ids_requested = $params['ids'];
				} elseif ( preg_match( '/ids/', $url ) ) {
					$ids_requested = 'present';
				}
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

		// Retry pass — pass source_attachment_ids directly.
		MediaImporter::process( $jid, 0, 1, [ 401, 402 ] );

		$this->assertNotNull( $ids_requested, 'Retry pass must send ids param to source endpoint.' );
	}

	public function test_retry_pass_does_not_update_stage_offset(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id(), 'stage_offset' => 50 ] );

		$this->mock_media( [] ); // empty response from source

		MediaImporter::process( $jid, 0, 1, [ 501 ] );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( '50', (string) $job->stage_offset, 'Retry pass must not update stage_offset.' );
	}

	public function test_exhausted_retries_log_to_error_message(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$max = (int) apply_filters( 'hbm_max_retries', 3 );

		$this->mock_media( [ $this->make_attachment_item( 601, 'exhausted.jpg' ) ] );
		$this->mock_download_failure();

		// Run at exactly max_retries (exhausted).
		MediaImporter::process( $jid, 0, $max, [ 601 ] );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNotEmpty( $job->error_message, 'error_message must be set when retries are exhausted.' );
		$this->assertStringContainsString( '601', $job->error_message, 'error_message must include the permanently-failed source ID.' );
	}

	public function test_ssrf_failure_is_not_retried(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		// file_url with different host than source_upload_url — SSRF guard fires.
		$this->mock_media( [ [
			'source_attachment_id'  => 701,
			'file_url'              => 'https://different-host.internal/evil.jpg',
			'post_name'             => 'evil',
			'post_title'            => 'Evil',
			'post_date'             => '2024-01-01 00:00:00',
			'post_parent_source_id' => 0,
			'alt_text'              => '',
			'caption'               => '',
			'description'           => '',
		] ] );

		MediaImporter::process( $jid, 0, 0 );

		$scheduled = as_get_scheduled_actions( [
			'hook'     => 'hbm_import_media',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 20,
		] );

		$ssrf_retry = false;
		foreach ( $scheduled as $action ) {
			$args = $action->get_args();
			if ( isset( $args['source_attachment_ids'] ) && in_array( 701, (array) $args['source_attachment_ids'], true ) ) {
				$ssrf_retry = true;
				break;
			}
		}

		$this->assertFalse( $ssrf_retry, 'SSRF-blocked items must not be queued for retry.' );
	}

	public function test_skip_duplicates_with_multiple_items_maps_matched_and_skips_unmatched(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$existing_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_name'   => 'multi-dup-image',
			'post_status' => 'inherit',
			'post_title'  => 'Multi Dup Image',
		] );

		$mid = $this->make_migration( [ 'media_conflict_policy' => 'skip_duplicates' ] );
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [
			[
				'source_attachment_id' => 101,
				'file_url'             => 'https://93.184.216.34/wp-content/uploads/multi-dup-image.jpg',
				'post_name'            => 'multi-dup-image',
				'post_title'           => 'Multi Dup Image',
				'post_date'            => '',
				'post_parent_source_id' => 0,
				'alt_text'             => '',
				'caption'              => '',
				'description'          => '',
			],
			[
				'source_attachment_id' => 102,
				'file_url'             => 'https://93.184.216.34/wp-content/uploads/no-match-multi.jpg',
				'post_name'            => 'no-match-multi',
				'post_title'           => 'No Match Multi',
				'post_date'            => '',
				'post_parent_source_id' => 0,
				'alt_text'             => '',
				'caption'              => '',
				'description'          => '',
			],
		] );

		MediaImporter::process( $jid, 0, 0 );

		// Matched item: IdMap points to existing attachment.
		$this->assertSame( $existing_att_id, IdMap::get( $jid, 'attachment', 101 ) );
		// Unmatched item: download attempted (no entry or entry from successful download — but fake URL will fail).
		$this->assertNull( IdMap::get( $jid, 'attachment', 102 ) );

		wp_delete_post( $existing_att_id, true );
	}
}
