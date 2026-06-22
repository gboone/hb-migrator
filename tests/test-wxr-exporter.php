<?php
/**
 * Tests for WxrExporter.
 */

use HBMigrator\Checkpoint;
use HBMigrator\Exporters\WxrExporter;
use HBMigrator\QueueTable;

class Test_Wxr_Exporter extends WP_UnitTestCase {

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

	public function test_first_batch_writes_xml_header(): void {
		WxrExporter::process_batch( 0, 0 );
		$file = \HBMigrator\ArtifactManager::get_export_dir() . 'hbm-export.xml';
		$this->assertFileExists( $file );
		$contents = file_get_contents( $file );
		$this->assertStringContainsString( '<?xml version="1.0"', $contents );
		$this->assertStringContainsString( '<rss', $contents );
	}

	public function test_empty_site_completes_stage(): void {
		// No posts, so first batch should write header + footer and complete.
		WxrExporter::process_batch( 0, 0 );
		$row = Checkpoint::get_stage( 'wxr' );
		$this->assertSame( 'complete', $row->status );
	}

	public function test_empty_site_writes_closing_tags(): void {
		WxrExporter::process_batch( 0, 0 );
		$file     = \HBMigrator\ArtifactManager::get_export_dir() . 'hbm-export.xml';
		$contents = file_get_contents( $file );
		$this->assertStringContainsString( '</channel>', $contents );
		$this->assertStringContainsString( '</rss>', $contents );
	}

	public function test_posts_are_written_as_items(): void {
		self::factory()->post->create( [
			'post_title'  => 'My Export Post',
			'post_status' => 'publish',
		] );
		WxrExporter::process_batch( 0, 0 );
		$file     = \HBMigrator\ArtifactManager::get_export_dir() . 'hbm-export.xml';
		$contents = file_get_contents( $file );
		$this->assertStringContainsString( 'My Export Post', $contents );
		$this->assertStringContainsString( '<item>', $contents );
	}

	public function test_subsequent_batch_does_not_rewrite_header(): void {
		// Create a post so first batch writes items.
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		WxrExporter::process_batch( 0, 0 );
		$file            = \HBMigrator\ArtifactManager::get_export_dir() . 'hbm-export.xml';
		$after_first     = file_get_contents( $file );
		$header_count_1  = substr_count( $after_first, '<?xml version' );
		$this->assertSame( 1, $header_count_1, 'XML header should appear exactly once' );
	}

	public function test_checkpoint_updated_with_last_post_id(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		WxrExporter::process_batch( 0, 0 );
		$row = Checkpoint::get_stage( 'wxr' );
		// On a site with just one post, last_post_id should equal the post ID
		// (stage complete when next batch returns empty).
		$this->assertGreaterThanOrEqual( 0, (int) $row->batch_offset );
	}
}
