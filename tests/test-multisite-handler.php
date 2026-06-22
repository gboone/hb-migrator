<?php
/**
 * Tests for MultisiteHandler (v2 — dest_path_for_siteurl).
 */

use HBMigrator\MultisiteHandler;

class Test_Multisite_Handler extends WP_UnitTestCase {

	public function test_dest_path_bare_domain(): void {
		$this->assertSame( '/example.com/', MultisiteHandler::dest_path_for_siteurl( 'https://example.com' ) );
	}

	public function test_dest_path_subdomain(): void {
		$this->assertSame( '/news.example.com/', MultisiteHandler::dest_path_for_siteurl( 'https://news.example.com' ) );
	}

	public function test_dest_path_subdirectory(): void {
		$this->assertSame( '/example.com/store/', MultisiteHandler::dest_path_for_siteurl( 'https://example.com/store' ) );
	}

	public function test_dest_path_subdirectory_trailing_slash(): void {
		$this->assertSame( '/example.com/store/', MultisiteHandler::dest_path_for_siteurl( 'https://example.com/store/' ) );
	}

	public function test_dest_path_nested_subdirectory(): void {
		$this->assertSame( '/example.com/a/b/', MultisiteHandler::dest_path_for_siteurl( 'https://example.com/a/b' ) );
	}
}
