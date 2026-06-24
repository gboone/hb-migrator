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
