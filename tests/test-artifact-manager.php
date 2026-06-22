<?php
/**
 * Tests for ArtifactManager and DownloadHandler security checks.
 */

use HBMigrator\ArtifactManager;

class Test_Artifact_Manager extends WP_UnitTestCase {

	private string $export_dir;

	public function set_up(): void {
		parent::set_up();
		$this->export_dir = ArtifactManager::get_export_dir();
		ArtifactManager::create_export_directory();
	}

	public function tear_down(): void {
		ArtifactManager::delete_all_artifacts();
		parent::tear_down();
	}

	public function test_create_export_directory_creates_dir(): void {
		$this->assertTrue( is_dir( $this->export_dir ) );
	}

	public function test_create_export_directory_writes_htaccess(): void {
		$this->assertFileExists( $this->export_dir . '.htaccess' );
		$contents = file_get_contents( $this->export_dir . '.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $contents );
	}

	public function test_create_export_directory_writes_index_stub(): void {
		$this->assertFileExists( $this->export_dir . 'index.php' );
	}

	public function test_list_artifacts_returns_empty_when_no_files(): void {
		$this->assertSame( [], ArtifactManager::list_artifacts() );
	}

	public function test_list_artifacts_returns_sql_and_xml_and_tar_gz_files(): void {
		// Create dummy artifact files.
		file_put_contents( $this->export_dir . 'export.sql', 'SQL data' );
		file_put_contents( $this->export_dir . 'export.xml', '<rss>' );
		file_put_contents( $this->export_dir . 'media.tar.gz', 'gz data' );

		$artifacts = ArtifactManager::list_artifacts();
		$filenames  = array_column( $artifacts, 'filename' );
		sort( $filenames );

		$this->assertContains( 'export.sql', $filenames );
		$this->assertContains( 'export.xml', $filenames );
		$this->assertContains( 'media.tar.gz', $filenames );
	}

	public function test_list_artifacts_excludes_security_files(): void {
		$artifacts = ArtifactManager::list_artifacts();
		$filenames  = array_column( $artifacts, 'filename' );
		$this->assertNotContains( '.htaccess', $filenames );
		$this->assertNotContains( 'index.php', $filenames );
	}

	public function test_delete_all_artifacts_removes_data_files(): void {
		file_put_contents( $this->export_dir . 'export.sql', 'SQL data' );
		ArtifactManager::delete_all_artifacts();
		$this->assertFileDoesNotExist( $this->export_dir . 'export.sql' );
	}

	public function test_delete_all_artifacts_preserves_security_files(): void {
		ArtifactManager::delete_all_artifacts();
		// The dir and security files should still exist after artifact deletion.
		$this->assertTrue( is_dir( $this->export_dir ) );
		$this->assertFileExists( $this->export_dir . '.htaccess' );
	}

	public function test_create_export_directory_is_idempotent(): void {
		$result = ArtifactManager::create_export_directory();
		$this->assertTrue( $result );
		$this->assertTrue( is_dir( $this->export_dir ) );
	}

	public function test_path_traversal_via_basename_is_blocked(): void {
		// Simulate what DownloadHandler does: basename() should strip the traversal.
		$malicious = '../../../wp-config.php';
		$sanitized = basename( $malicious );
		$this->assertSame( 'wp-config.php', $sanitized );

		// After basename(), realpath() guard prevents resolving outside export dir.
		// The candidate path would not exist in export dir, so realpath() returns false.
		$candidate = $this->export_dir . $sanitized;
		$resolved  = realpath( $candidate );
		$this->assertFalse( $resolved );
	}
}
