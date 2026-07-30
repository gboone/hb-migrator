<?php
/**
 * Tests for MediaImporter media conflict policy behaviour.
 */

use HBMigrator\Destination\AuditReport;
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
		remove_all_filters( 'wp_generate_attachment_metadata' );
		remove_all_filters( 'big_image_size_threshold' );
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
		$this->assertStringContainsString( 'download failed', $job->error_message, 'error_message must include the failure reason.' );
		$this->assertStringContainsString( 'Connection timed out', $job->error_message, 'error_message must include the underlying error from download_url.' );
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

	// Intercepts download_url by writing a minimal valid 1x1 PNG to the temp file WordPress
	// pre-creates, then returns a 200 response. This lets wp_handle_sideload and wp_insert_attachment
	// run normally so tests can reach wp_generate_attachment_metadata.
	private function mock_successful_png_download(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/wp-content/uploads/' ) ) {
				if ( ! empty( $args['filename'] ) ) {
					// Minimal 1×1 transparent PNG (67 bytes).
					file_put_contents( $args['filename'], base64_decode(
						'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
					) );
				}
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '',
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => $args['filename'] ?? null,
				];
			}
			return $preempt;
		}, 20, 3 );
	}

	// -------------------------------------------------------------------------
	// U2: Metadata generation failure → delete attachment and retry
	// -------------------------------------------------------------------------

	public function test_empty_metadata_schedules_retry(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 801, 'corrupt-image.png' ) ] );
		$this->mock_successful_png_download();
		add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 99 );

		MediaImporter::process( $jid, 0, 0 );

		$this->assertNull( IdMap::get( $jid, 'attachment', 801 ), 'Empty metadata must not produce an IdMap entry.' );

		$scheduled  = as_get_scheduled_actions( [ 'hook' => 'hbm_import_media', 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 50 ] );
		$retry_found = false;
		foreach ( $scheduled as $action ) {
			$args = $action->get_args();
			if ( isset( $args['source_attachment_ids'] ) && in_array( 801, (array) $args['source_attachment_ids'], true ) ) {
				$retry_found = true;
				break;
			}
		}
		$this->assertTrue( $retry_found, 'Metadata failure must schedule a retry.' );
	}

	public function test_false_metadata_schedules_retry(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 802, 'corrupt-false.png' ) ] );
		$this->mock_successful_png_download();
		add_filter( 'wp_generate_attachment_metadata', '__return_false', 99 );

		MediaImporter::process( $jid, 0, 0 );

		$this->assertNull( IdMap::get( $jid, 'attachment', 802 ), 'False metadata must not produce an IdMap entry.' );
	}

	// -------------------------------------------------------------------------
	// WordPress core's "big image" auto-scaling (5.3+, big_image_size_threshold, default
	// 2560px) would otherwise silently rename a migrated file to "{name}-scaled.{ext}" —
	// breaking any source content that already references the file by its original name. See
	// import_batch()'s own big_image_size_threshold override.
	// -------------------------------------------------------------------------

	/**
	 * A 1x1 fixture can never exercise WP core's real big-image scaling path — the constrained
	 * target size always ends up identical to the original, so WP skips creating a redundant
	 * scaled copy regardless of threshold (confirmed empirically: forcing the threshold below 1
	 * against the 1x1 fixture used elsewhere in this file never produces a "-scaled" file or an
	 * "original_image" metadata key, with or without import_batch()'s override). A real,
	 * non-trivial image is required to genuinely trigger it.
	 */
	private function mock_successful_sized_png_download( int $width, int $height ): void {
		$png_bytes = static function () use ( $width, $height ): string {
			$image = imagecreatetruecolor( $width, $height );
			ob_start();
			imagepng( $image );
			$bytes = ob_get_clean();
			imagedestroy( $image );
			return $bytes;
		};

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $png_bytes ) {
			if ( false !== strpos( $url, '/wp-content/uploads/' ) ) {
				if ( ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $png_bytes() );
				}
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '',
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => $args['filename'] ?? null,
				];
			}
			return $preempt;
		}, 20, 3 );
	}

	public function test_big_image_scaling_is_disabled_so_original_filename_is_preserved(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 1001, 'no-scale.png' ) ] );
		$this->mock_successful_sized_png_download( 50, 50 );
		// A real, non-trivial (50x50) fixture, with the threshold forced below its actual size —
		// proving import_batch()'s override genuinely disables WP core's scaling path for an
		// image that would otherwise, verifiably, trigger it (see this method's own docblock on
		// why a 1x1 fixture can't exercise this).
		add_filter( 'big_image_size_threshold', static fn() => 10 );

		MediaImporter::process( $jid, 0, 0 );

		$dest_id = IdMap::get( $jid, 'attachment', 1001 );
		$this->assertNotNull( $dest_id, 'The attachment must still import successfully.' );

		$meta = wp_get_attachment_metadata( $dest_id );
		$this->assertArrayNotHasKey( 'original_image', $meta, 'An "original_image" metadata key only ever exists when WP core\'s big-image scaling actually ran — its absence proves scaling was disabled, not merely skipped for some other reason.' );

		$attached_file = get_post_meta( $dest_id, '_wp_attached_file', true );
		$this->assertStringNotContainsString( '-scaled', $attached_file, 'The migrated file must keep its original filename, not get renamed with a "-scaled" suffix.' );
		$this->assertStringContainsString( 'no-scale.png', $attached_file, 'The migrated file must retain its exact original filename.' );
	}

	public function test_metadata_failure_reason_in_permanent_error_message(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$max = (int) apply_filters( 'hbm_max_retries', 3 );

		$this->mock_media( [ $this->make_attachment_item( 803, 'corrupt-exhausted.png' ) ] );
		$this->mock_successful_png_download();
		add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 99 );

		MediaImporter::process( $jid, 0, $max, [ 803 ] );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNotEmpty( $job->error_message, 'error_message must be set when metadata retries are exhausted.' );
		$this->assertStringContainsString( '803', $job->error_message );
		$this->assertStringContainsString( 'metadata generation failed', $job->error_message );
	}

	// -------------------------------------------------------------------------
	// U3: Cross-run deduplication by _hbm_source_attachment_id
	// -------------------------------------------------------------------------

	public function test_cross_run_healthy_prior_import_reuses_existing_attachment(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		// Simulate a healthy attachment left by a previous migration run.
		$prior_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post_title'  => 'Prior Healthy Attachment',
		] );
		update_post_meta( $prior_att_id, '_hbm_source_attachment_id', 901 );
		wp_update_attachment_metadata( $prior_att_id, [ 'width' => 1200, 'height' => 800, 'file' => '2023/09/prior-healthy.jpg' ] );

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 901, 'prior-healthy.jpg' ) ] );

		MediaImporter::process( $jid, 0, 0 );

		$this->assertSame( $prior_att_id, IdMap::get( $jid, 'attachment', 901 ), 'Healthy prior attachment must be reused via IdMap — no re-download.' );

		wp_delete_post( $prior_att_id, true );
	}

	public function test_cross_run_broken_prior_import_is_deleted_before_reimport(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		// Simulate a broken attachment: post exists with source meta, but metadata is empty.
		$broken_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post_title'  => 'Prior Broken Attachment',
		] );
		update_post_meta( $broken_att_id, '_hbm_source_attachment_id', 902 );
		// Deliberately leave _wp_attachment_metadata unset (empty).

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 902, 'prior-broken.jpg' ) ] );
		$this->mock_download_failure(); // re-import attempt expected; let it fail for simplicity

		MediaImporter::process( $jid, 0, 0 );

		// The broken attachment must have been deleted.
		$this->assertNull( get_post( $broken_att_id ), 'Broken prior attachment must be deleted before re-import attempt.' );
		// IdMap must not point to the old broken post.
		$this->assertNotSame( $broken_att_id, IdMap::get( $jid, 'attachment', 902 ) );
	}

	public function test_cross_run_no_prior_import_proceeds_normally(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 903, 'fresh-import.jpg' ) ] );
		$this->mock_download_failure();

		MediaImporter::process( $jid, 0, 0 );

		// No prior attachment → download attempted normally (fails here); no IdMap entry.
		$this->assertNull( IdMap::get( $jid, 'attachment', 903 ), 'Fresh import with no prior attachment must leave no IdMap entry after failed download.' );
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

	// -------------------------------------------------------------------------
	// filetype_override_filter() — private helper, accessed via ReflectionClass
	// -------------------------------------------------------------------------

	private function call_filetype_override_filter( string $source_mime, string $filename ): mixed {
		$method = ( new \ReflectionClass( MediaImporter::class ) )
			->getMethod( 'filetype_override_filter' );
		$method->setAccessible( true );
		return $method->invoke( null, $source_mime, $filename );
	}

	public function test_filetype_override_filter_returns_null_for_empty_mime(): void {
		$filter = $this->call_filetype_override_filter( '', 'image.jpg' );
		$this->assertNull( $filter );
		$this->assertFalse( has_filter( 'wp_check_filetype_and_ext' ), 'No filter should be registered when source MIME is empty.' );
	}

	/** @dataProvider blocked_extensions_provider */
	public function test_filetype_override_filter_returns_null_for_blocked_extension( string $filename ): void {
		$filter = $this->call_filetype_override_filter( 'application/octet-stream', $filename );
		$this->assertNull( $filter, "Extension in {$filename} should be blocked." );
		$this->assertFalse( has_filter( 'wp_check_filetype_and_ext' ), 'No filter should be registered for blocked extension.' );
	}

	public static function blocked_extensions_provider(): array {
		return [
			'php'      => [ 'shell.php' ],
			'phar'     => [ 'evil.phar' ],
			'phtml'    => [ 'script.phtml' ],
			'asp'      => [ 'page.asp' ],
			'sh'       => [ 'run.sh' ],
			'exe'      => [ 'program.exe' ],
			'htaccess' => [ '.htaccess' ],
		];
	}

	public function test_filetype_override_filter_registers_filter_for_safe_extension(): void {
		$filter = $this->call_filetype_override_filter( 'image/svg+xml', 'logo.svg' );

		$this->assertIsCallable( $filter );
		$this->assertNotFalse( has_filter( 'wp_check_filetype_and_ext' ), 'Filter should be registered for a safe extension.' );

		remove_filter( 'wp_check_filetype_and_ext', $filter );
	}

	public function test_filetype_override_filter_only_fires_when_both_ext_and_type_are_false(): void {
		$filter = $this->call_filetype_override_filter( 'image/heic', 'photo.heic' );
		$this->assertIsCallable( $filter );

		// Simulate WP returning a partial result (another plugin already set ext).
		$partial_data = [ 'ext' => 'heic', 'type' => false, 'proper_filename' => false ];
		$result = $filter( $partial_data );
		$this->assertFalse( $result['type'], 'Filter must not overwrite when ext is already set.' );

		// Simulate WP's full rejection (both set to false boolean — the signal to override).
		$rejected_data = [ 'ext' => false, 'type' => false, 'proper_filename' => false ];
		$result = $filter( $rejected_data );
		$this->assertSame( 'heic', $result['ext'] );
		$this->assertSame( 'image/heic', $result['type'] );

		remove_filter( 'wp_check_filetype_and_ext', $filter );
	}

	public function test_filetype_override_filter_does_not_fire_for_empty_string_ext(): void {
		$filter = $this->call_filetype_override_filter( 'image/webp', 'animated.webp' );
		$this->assertIsCallable( $filter );

		// empty('') is true, but false === '' is false — verify strict check.
		$empty_string_data = [ 'ext' => '', 'type' => '', 'proper_filename' => false ];
		$result = $filter( $empty_string_data );
		$this->assertSame( '', $result['ext'], 'Filter must not fire for empty string (only for boolean false).' );

		remove_filter( 'wp_check_filetype_and_ext', $filter );
	}

	// -------------------------------------------------------------------------
	// U4: import_batch() extraction — shared by process() (initial migration/retries) and
	// MediaSyncStage (ongoing sync). These verify import_batch() is directly callable and
	// that it preserves the exact same conflict-policy behavior process() already exercises.
	// -------------------------------------------------------------------------

	public function test_import_batch_directly_honors_skip_duplicates_policy(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$existing_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_name'   => 'direct-skip-dup',
			'post_status' => 'inherit',
			'post_title'  => 'Direct Skip Dup',
		] );

		$mid = $this->make_migration( [ 'media_conflict_policy' => 'skip_duplicates' ] );
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$item = array_merge( $this->make_attachment_item( 950, 'direct-skip-dup.jpg' ), [ 'post_name' => 'direct-skip-dup' ] );
		$failed = MediaImporter::import_batch( $jid, [ $item ] );

		$this->assertSame( $existing_att_id, IdMap::get( $jid, 'attachment', 950 ), 'import_batch() called directly must still honor skip_duplicates.' );
		$this->assertSame( [], $failed );

		wp_delete_post( $existing_att_id, true );
	}

	public function test_import_batch_returns_failed_items_for_download_failures(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_download_failure();

		$failed = MediaImporter::import_batch( $jid, [ $this->make_attachment_item( 951, 'direct-fail.jpg' ) ] );

		$this->assertArrayHasKey( 951, $failed, 'import_batch() must return failed items so callers can decide whether to retry.' );
		$this->assertNull( IdMap::get( $jid, 'attachment', 951 ) );
	}

	public function test_import_batch_restores_current_blog_on_success(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		$dest_blog_id = get_current_blog_id();
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => $dest_blog_id ] );

		$before = get_current_blog_id();
		MediaImporter::import_batch( $jid, [] );
		$after = get_current_blog_id();

		$this->assertSame( $before, $after, 'import_batch() must restore the original blog even for an empty batch.' );
	}

	public function test_import_batch_missing_job_returns_empty_without_error(): void {
		$failed = MediaImporter::import_batch( 999999, [ $this->make_attachment_item( 952 ) ] );
		$this->assertSame( [], $failed, 'A missing/invalid site job must be a safe no-op.' );
	}

	public function test_process_still_advances_pipeline_via_import_batch(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		// Fewer than 50 items — process() should enqueue hbm_import_options next (initial
		// migration path is otherwise untouched by the import_batch() extraction).
		$this->mock_media( [ $this->make_attachment_item( 953, 'pipeline.jpg' ) ] );
		$this->mock_download_failure();

		MediaImporter::process( $jid, 0, 0 );

		$scheduled = as_get_scheduled_actions( [
			'hook'     => 'hbm_import_options',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 20,
		] );
		$this->assertNotEmpty( $scheduled, 'process() must still enqueue hbm_import_options after import_batch() extraction.' );
	}

	// -------------------------------------------------------------------------
	// U3: request-trail capture for MediaImporter's source/sites/{id}/media listing (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U3. Request-trail
	// capture at every outbound source call"). MediaImporter is site-job-scoped, so entries
	// are recorded via AuditReport::record() (scope: site_job).
	// -------------------------------------------------------------------------

	public function test_process_records_site_job_scoped_request_trail_entry_on_success(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_media( [ $this->make_attachment_item( 950, 'audit-success.jpg' ) ] );
		$this->mock_download_failure(); // download outcome is irrelevant to the request-trail entry itself

		MediaImporter::process( $jid, 0, 0 );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'site_job', $rows[0]['scope'] );
		$this->assertTrue( $rows[0]['success'] );
		$this->assertSame( 1, $rows[0]['count'] );
	}

	public function test_process_records_failed_request_trail_entry_when_source_unreachable(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/media' ) ) {
				return [
					'response' => [ 'code' => 500, 'message' => 'Internal Server Error' ],
					'body'     => '',
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		MediaImporter::process( $jid, 0, 0 );

		// Existing error-handling behavior is unchanged by U3: a retryable failure at
		// attempt 0 is rescheduled by PipelineController, not marked failed outright.
		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNotSame( 'failed', $job->status );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'site_job', $rows[0]['scope'] );
		$this->assertFalse( $rows[0]['success'] );
		$this->assertArrayHasKey( 'error', $rows[0] );
	}

	// -------------------------------------------------------------------------
	// U5: write-action trail for media (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U5. Write-action trail:
	// posts and media (sync-gated)"). import_batch() is the only method MediaSyncStage also
	// calls, so the status gate below is the single most important test in this file — a leak
	// here would mean audit data silently appearing for out-of-scope sync passes.
	// -------------------------------------------------------------------------

	private function get_write_trail_rows( int $jid ): array {
		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_write', false );
		restore_current_blog();
		return $rows;
	}

	/**
	 * Critical, non-obvious behavior (plan's explicit Execution note): a call to import_batch()
	 * for a site job whose status is NOT pending/running (simulating MediaSyncStage's own call,
	 * which only ever runs while status is 'syncing') must produce ZERO write-trail entries.
	 */
	public function test_import_batch_records_no_write_trail_entries_when_site_job_is_syncing(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id(), 'status' => 'syncing' ] );

		$this->mock_download_failure();

		MediaImporter::import_batch( $jid, [ $this->make_attachment_item( 970, 'sync-gate.jpg' ) ] );

		$this->assertCount(
			0,
			$this->get_write_trail_rows( $jid ),
			'A sync-context (status: syncing) call to import_batch() must not produce any write-trail entries.'
		);
	}

	/** Same gate, for the 'complete' status (also used by the sync pipeline's own callers). */
	public function test_import_batch_records_no_write_trail_entries_when_site_job_is_complete(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id(), 'status' => 'complete' ] );

		$this->mock_download_failure();

		MediaImporter::import_batch( $jid, [ $this->make_attachment_item( 971, 'sync-gate-complete.jpg' ) ] );

		$this->assertCount(
			0,
			$this->get_write_trail_rows( $jid ),
			'A sync-context (status: complete) call to import_batch() must not produce any write-trail entries.'
		);
	}

	public function test_import_batch_records_created_entry_with_raw_source_fields_for_new_attachment(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] ); // status stays 'pending'

		$this->mock_successful_png_download();

		$item = array_merge( $this->make_attachment_item( 980, 'created-entry.png' ), [
			'description' => 'Raw source description.',
			'caption'     => 'Raw source caption.',
			'alt_text'    => 'Raw alt text.',
		] );

		MediaImporter::import_batch( $jid, [ $item ] );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'write', $rows[0]['type'] );
		$this->assertSame( 'attachment', $rows[0]['object_type'] );
		$this->assertSame( 980, $rows[0]['source_id'] );
		$this->assertSame( 'created', $rows[0]['outcome'] );
		$this->assertSame( $item['file_url'], $rows[0]['file_url'] );
		$this->assertSame( 'Raw source description.', $rows[0]['description'] );
		$this->assertSame( 'Raw source caption.', $rows[0]['caption'] );
		$this->assertSame( 'Raw alt text.', $rows[0]['alt_text'] );
	}

	public function test_import_batch_records_updated_entry_for_already_mapped_retried_item(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_successful_png_download();

		$item = $this->make_attachment_item( 981, 'retry-entry.png' );
		MediaImporter::import_batch( $jid, [ $item ] );

		// Same item re-processed (resumed/retried pass) — already IdMap-mapped for this job.
		MediaImporter::import_batch( $jid, [ $item ] );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 2, $rows, 'Each attempt gets its own trail entry (request-trail-style duplication).' );
		$this->assertSame( 'created', $rows[0]['outcome'] );
		$this->assertSame( 'updated', $rows[1]['outcome'], 'A retried already-mapped item must be recorded as updated, not created (no double-counting).' );
	}

	public function test_import_batch_records_failed_entry_tagged_to_correct_source_id(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_download_failure();

		MediaImporter::import_batch( $jid, [ $this->make_attachment_item( 982, 'download-fail-entry.jpg' ) ] );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['outcome'] );
		$this->assertSame( 982, $rows[0]['source_id'] );
	}

	// -------------------------------------------------------------------------
	// U3 (hardening pass, docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md):
	// 5 of 8 record_write_trail() call sites in import_batch() had no assertion. Each test
	// below targets exactly one previously-untested call site.
	// -------------------------------------------------------------------------

	/** Gap (b): a healthy prior import (cross-run dedup by _hbm_source_attachment_id) is reused — recorded as 'created'. */
	public function test_import_batch_records_created_entry_for_healthy_prior_import_reuse(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$prior_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'post_title'  => 'Prior Healthy Attachment For Audit',
		] );
		update_post_meta( $prior_att_id, '_hbm_source_attachment_id', 991 );
		wp_update_attachment_metadata( $prior_att_id, [ 'width' => 1200, 'height' => 800, 'file' => '2023/09/prior-healthy-audit.jpg' ] );

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] ); // status stays 'pending'

		MediaImporter::import_batch( $jid, [ $this->make_attachment_item( 991, 'prior-healthy-audit.jpg' ) ] );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'created', $rows[0]['outcome'], 'A healthy prior-import reuse must be recorded as created, not updated.' );
		$this->assertSame( 991, $rows[0]['source_id'] );

		wp_delete_post( $prior_att_id, true );
	}

	/** Gap (c): skip_duplicates reuse of an existing attachment matched by filename — recorded as 'created'. */
	public function test_import_batch_records_created_entry_for_skip_duplicates_filename_match_reuse(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$existing_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_name'   => 'audit-skip-dup-image',
			'post_status' => 'inherit',
			'post_title'  => 'Audit Skip Dup Image',
		] );

		$mid = $this->make_migration( [ 'media_conflict_policy' => 'skip_duplicates' ] );
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$item = array_merge(
			$this->make_attachment_item( 992, 'audit-skip-dup-image.jpg' ),
			[ 'post_name' => 'audit-skip-dup-image' ]
		);
		MediaImporter::import_batch( $jid, [ $item ] );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'created', $rows[0]['outcome'], 'A skip_duplicates filename-match reuse must be recorded as created.' );
		$this->assertSame( 992, $rows[0]['source_id'] );

		wp_delete_post( $existing_att_id, true );
	}

	/** Gap (e): wp_handle_sideload() failure — recorded as 'failed'. Forced via wp_handle_sideload_prefilter, after a successful download so the pipeline actually reaches the sideload call. */
	public function test_import_batch_records_failed_entry_for_sideload_failure(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_successful_png_download();
		$sideload_filter = static function ( array $file ): array {
			$file['error'] = 'Forced sideload failure for test.';
			return $file;
		};
		add_filter( 'wp_handle_sideload_prefilter', $sideload_filter );

		MediaImporter::import_batch( $jid, [ $this->make_attachment_item( 993, 'sideload-fail-audit.png' ) ] );

		remove_filter( 'wp_handle_sideload_prefilter', $sideload_filter );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['outcome'] );
		$this->assertSame( 993, $rows[0]['source_id'] );
		$this->assertNull( IdMap::get( $jid, 'attachment', 993 ) );
	}

	/** Gap (f): wp_insert_attachment() failure — recorded as 'failed'. Forced via wp_insert_post_empty_content, the same mechanism used elsewhere in this codebase to force a falsy/error wp_insert_post() return. */
	public function test_import_batch_records_failed_entry_for_wp_insert_attachment_failure(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_successful_png_download();
		add_filter( 'wp_insert_post_empty_content', function ( $maybe_empty, $postarr ) {
			if ( 'FAIL_ME' === ( $postarr['post_title'] ?? '' ) ) {
				return true;
			}
			return $maybe_empty;
		}, 10, 2 );

		$item = array_merge(
			$this->make_attachment_item( 994, 'insert-fail-audit.png' ),
			[ 'post_title' => 'FAIL_ME' ]
		);
		MediaImporter::import_batch( $jid, [ $item ] );

		remove_all_filters( 'wp_insert_post_empty_content' );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['outcome'] );
		$this->assertSame( 994, $rows[0]['source_id'] );
		$this->assertNull( IdMap::get( $jid, 'attachment', 994 ) );
	}

	/** Gap (g): wp_generate_attachment_metadata() failure — recorded as 'failed'. Reuses test_empty_metadata_schedules_retry()'s forcing mechanism. */
	public function test_import_batch_records_failed_entry_for_metadata_generation_failure(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$mid = $this->make_migration();
		$jid = $this->make_site_job( $mid );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->mock_successful_png_download();
		add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 99 );

		MediaImporter::import_batch( $jid, [ $this->make_attachment_item( 995, 'metadata-fail-audit.png' ) ] );

		$rows = $this->get_write_trail_rows( $jid );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['outcome'] );
		$this->assertSame( 995, $rows[0]['source_id'] );
		$this->assertNull( IdMap::get( $jid, 'attachment', 995 ) );
	}
}
