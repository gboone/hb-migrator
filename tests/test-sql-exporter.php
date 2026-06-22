<?php
/**
 * Tests for SqlExporter.
 */

use HBMigrator\Checkpoint;
use HBMigrator\Exporters\SqlExporter;
use HBMigrator\QueueTable;

class Test_Sql_Exporter extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
		Checkpoint::reset_all();
		Checkpoint::initialize_stages();
	}

	public function test_get_export_tables_returns_array(): void {
		$tables = SqlExporter::get_export_tables();
		$this->assertIsArray( $tables );
		$this->assertNotEmpty( $tables );
	}

	public function test_get_export_tables_excludes_multisite_tables_on_single_site(): void {
		// On a non-multisite install, wp_users should NOT be excluded.
		$tables = SqlExporter::get_export_tables();
		$this->assertContains( 'wp_users', $tables );
	}

	public function test_get_primary_key_returns_id_for_posts(): void {
		$pk = SqlExporter::get_primary_key( 'wp_posts' );
		$this->assertSame( 'ID', $pk );
	}

	public function test_get_primary_key_returns_null_for_table_without_ai_pk(): void {
		// wp_term_relationships has a compound PK, no AUTO_INCREMENT.
		$pk = SqlExporter::get_primary_key( 'wp_term_relationships' );
		$this->assertNull( $pk );
	}

	public function test_get_create_table_statement_enforces_innodb(): void {
		$ddl = SqlExporter::get_create_table_statement( 'wp_posts' );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $ddl );
	}

	public function test_get_create_table_statement_includes_drop_if_exists(): void {
		$ddl = SqlExporter::get_create_table_statement( 'wp_posts' );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $ddl );
	}

	public function test_rows_to_insert_produces_valid_insert(): void {
		$rows = [
			[ 'id' => '1', 'name' => 'Alice' ],
			[ 'id' => '2', 'name' => 'Bob' ],
		];
		$sql = SqlExporter::rows_to_insert( 'wp_test', $rows );
		$this->assertStringContainsString( 'INSERT INTO `wp_test`', $sql );
		$this->assertStringContainsString( 'Alice', $sql );
		$this->assertStringContainsString( 'Bob', $sql );
	}

	public function test_process_batch_with_out_of_range_table_index_marks_complete(): void {
		SqlExporter::process_batch( 9999, 0, 0 );
		$row = Checkpoint::get_stage( 'sql' );
		$this->assertSame( 'complete', $row->status );
	}

	public function test_process_batch_writes_sql_file(): void {
		// Create a post so there is something to export.
		$post_id = self::factory()->post->create( [ 'post_title' => 'Test Post' ] );

		// Run one batch on the wp_posts table.
		$tables  = SqlExporter::get_export_tables();
		$idx     = array_search( 'wp_posts', $tables, true );
		$this->assertNotFalse( $idx, 'wp_posts should be in export tables' );

		SqlExporter::process_batch( (int) $idx, 0, 0 );

		$export_file = \HBMigrator\ArtifactManager::get_export_dir() . 'hbm-export.sql';
		$this->assertFileExists( $export_file );
		$this->assertStringContainsString( 'INSERT INTO', file_get_contents( $export_file ) );
	}
}
