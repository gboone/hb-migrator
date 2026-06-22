<?php
/**
 * Tests for Checkpoint and QueueTable.
 *
 * Run with: vendor/bin/phpunit tests/test-checkpoint.php
 * Requires WP test suite bootstrap (wp-tests-config.php).
 */

use HBMigrator\Checkpoint;
use HBMigrator\QueueTable;

class Test_Checkpoint extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
		Checkpoint::reset_all();
	}

	public function test_initialize_stages_inserts_three_rows(): void {
		Checkpoint::initialize_stages();
		$stages = Checkpoint::get_all_stages();
		$this->assertCount( 3, $stages );
		$statuses = array_column( $stages, 'status' );
		$this->assertSame( [ 'pending', 'pending', 'pending' ], $statuses );
	}

	public function test_set_offset_updates_only_target_stage(): void {
		Checkpoint::initialize_stages();
		Checkpoint::set_offset( 'sql', 500 );
		$sql = Checkpoint::get_stage( 'sql' );
		$wxr = Checkpoint::get_stage( 'wxr' );
		$this->assertSame( 500, (int) $sql->batch_offset );
		$this->assertSame( 0, (int) $wxr->batch_offset );
	}

	public function test_set_row_offset(): void {
		Checkpoint::initialize_stages();
		Checkpoint::set_row_offset( 'sql', 9999 );
		$sql = Checkpoint::get_stage( 'sql' );
		$this->assertSame( 9999, (int) $sql->row_offset );
	}

	public function test_mark_stage_failed_sets_error(): void {
		Checkpoint::initialize_stages();
		Checkpoint::mark_stage_failed( 'wxr', 'OOM error' );
		$wxr = Checkpoint::get_stage( 'wxr' );
		$this->assertSame( 'failed', $wxr->status );
		$this->assertSame( 'OOM error', $wxr->error_message );
	}

	public function test_mark_stage_complete(): void {
		Checkpoint::initialize_stages();
		Checkpoint::mark_stage_complete( 'sql' );
		$sql = Checkpoint::get_stage( 'sql' );
		$this->assertSame( 'complete', $sql->status );
	}

	public function test_reset_all_clears_rows(): void {
		Checkpoint::initialize_stages();
		Checkpoint::reset_all();
		$this->assertCount( 0, Checkpoint::get_all_stages() );
	}

	public function test_is_pipeline_complete(): void {
		Checkpoint::initialize_stages();
		$this->assertFalse( Checkpoint::is_pipeline_complete() );
		foreach ( [ 'sql', 'wxr', 'media' ] as $s ) {
			Checkpoint::mark_stage_complete( $s );
		}
		$this->assertTrue( Checkpoint::is_pipeline_complete() );
	}

	public function test_is_pipeline_failed(): void {
		Checkpoint::initialize_stages();
		$this->assertFalse( Checkpoint::is_pipeline_failed() );
		Checkpoint::mark_stage_failed( 'sql', 'boom' );
		$this->assertTrue( Checkpoint::is_pipeline_failed() );
	}

	public function test_insert_and_get_media_files(): void {
		Checkpoint::insert_media_files( [
			[ 'relative_path' => '2024/03/img.jpg', 'file_size' => 1024, 'partition' => 0 ],
			[ 'relative_path' => '2024/04/vid.mp4', 'file_size' => 2048, 'partition' => 0 ],
		] );
		$this->assertSame( 2, Checkpoint::count_media_files() );
		$files = Checkpoint::get_media_files( 0, 10 );
		$this->assertSame( '2024/03/img.jpg', $files[0]->relative_path );
	}

	public function test_mark_media_file_copied(): void {
		Checkpoint::insert_media_files( [
			[ 'relative_path' => '2024/03/img.jpg', 'file_size' => 1024, 'partition' => 0 ],
		] );
		$files = Checkpoint::get_media_files( 0, 1 );
		Checkpoint::mark_media_file_copied( (int) $files[0]->id );
		$updated = Checkpoint::get_media_files( 0, 10 );
		$this->assertSame( 'copied', $updated[0]->status );
	}

	public function test_schema_upgrade_updates_version(): void {
		delete_option( 'hbm_db_version' );
		QueueTable::maybe_create_or_upgrade();
		$this->assertSame( HBM_DB_VERSION, (int) get_option( 'hbm_db_version' ) );
	}
}
