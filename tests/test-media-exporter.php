<?php
/**
 * Tests for MediaExporter.
 */

use HBMigrator\Checkpoint;
use HBMigrator\Exporters\MediaExporter;
use HBMigrator\QueueTable;

class Test_Media_Exporter extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
		Checkpoint::reset_all();
		Checkpoint::initialize_stages();
		\HBMigrator\ArtifactManager::create_export_directory();
	}

	public function tear_down(): void {
		\HBMigrator\ArtifactManager::delete_all_artifacts();
		parent::tear_down();
	}

	public function test_phar_extension_requirement(): void {
		$this->assertTrue(
			extension_loaded( 'Phar' ),
			'Phar extension must be available for media export tests'
		);
	}

	public function test_discovery_batch_populates_media_files(): void {
		// Create a temporary uploads structure.
		$uploads_dir = wp_upload_dir()['basedir'];
		$fake_file   = $uploads_dir . '/2024/03/test-image.jpg';
		wp_mkdir_p( dirname( $fake_file ) );
		file_put_contents( $fake_file, 'fake image data' );

		// Run discovery batch (file_index=0, partition=0 triggers discovery).
		MediaExporter::process_batch( 0, 0, 0 );

		$count = Checkpoint::count_media_files();
		$this->assertGreaterThan( 0, $count );

		// Cleanup.
		@unlink( $fake_file );
	}

	public function test_copy_batch_stages_files(): void {
		$uploads_dir = wp_upload_dir()['basedir'];
		$fake_file   = $uploads_dir . '/2024/04/sample.jpg';
		wp_mkdir_p( dirname( $fake_file ) );
		file_put_contents( $fake_file, 'jpg content' );

		// Seed the media file list directly.
		Checkpoint::insert_media_files( [
			[ 'relative_path' => '2024/04/sample.jpg', 'file_size' => 11, 'partition' => 0 ],
		] );

		// Run copy batch (non-zero trigger: file_index=1, partition=0).
		MediaExporter::process_batch( 1, 0, 0 );

		$staging = \HBMigrator\ArtifactManager::get_staging_dir() . '2024/04/sample.jpg';
		$this->assertFileExists( $staging );

		// Cleanup.
		@unlink( $fake_file );
		@unlink( $staging );
	}

	public function test_empty_uploads_dir_process_dispatches_finalize(): void {
		// Insert a single file row but make it already copied.
		Checkpoint::insert_media_files( [
			[ 'relative_path' => '2024/01/nonexistent.jpg', 'file_size' => 0, 'partition' => 0 ],
		] );

		// Trigger an off-zero batch with empty file list (no rows at offset 999).
		MediaExporter::process_batch( 999, 0, 0 );

		$row = Checkpoint::get_stage( 'media' );
		$this->assertSame( 'running', $row->status );
	}

	public function test_finalize_creates_tar_gz_archive(): void {
		$staging_dir = \HBMigrator\ArtifactManager::get_staging_dir();
		wp_mkdir_p( $staging_dir . '2024/01' );
		file_put_contents( $staging_dir . '2024/01/img.jpg', 'fake' );

		// Seed one media file row so max_partition returns 0.
		Checkpoint::insert_media_files( [
			[ 'relative_path' => '2024/01/img.jpg', 'file_size' => 4, 'partition' => 0 ],
		] );

		MediaExporter::finalize( 0 );

		$archive = \HBMigrator\ArtifactManager::get_export_dir() . 'hbm-media-0.tar.gz';
		$this->assertFileExists( $archive );
	}
}
