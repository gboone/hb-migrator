<?php
/**
 * Tests for MultisiteHandler.
 */

use HBMigrator\MultisiteHandler;

class Test_Multisite_Handler extends WP_UnitTestCase {

	public function test_rewrite_table_name_non_multisite(): void {
		// On a non-multisite install, table names pass through unchanged.
		$this->assertSame( 'wp_posts', MultisiteHandler::rewrite_table_name( 'wp_posts' ) );
	}

	public function test_rewrite_table_name_excluded_network_table(): void {
		// Network tables are never rewritten.
		$this->assertSame( 'wp_users', MultisiteHandler::rewrite_table_name( 'wp_users' ) );
		$this->assertSame( 'wp_blogs', MultisiteHandler::rewrite_table_name( 'wp_blogs' ) );
	}

	public function test_get_excluded_tables_contains_nine_entries(): void {
		$tables = MultisiteHandler::get_excluded_tables();
		$this->assertContains( 'wp_users', $tables );
		$this->assertContains( 'wp_usermeta', $tables );
		$this->assertContains( 'wp_blogs', $tables );
		$this->assertContains( 'wp_blogmeta', $tables );
		$this->assertContains( 'wp_site', $tables );
		$this->assertContains( 'wp_sitemeta', $tables );
		$this->assertContains( 'wp_signups', $tables );
		$this->assertContains( 'wp_registration_log', $tables );
		$this->assertContains( 'wp_sitecategories', $tables );
		$this->assertCount( 9, $tables );
	}

	public function test_rewrite_option_user_roles_non_multisite(): void {
		$this->assertSame( 'user_roles', MultisiteHandler::rewrite_option_user_roles( 'user_roles' ) );
		$this->assertSame( 'siteurl', MultisiteHandler::rewrite_option_user_roles( 'siteurl' ) );
	}

	public function test_rewrite_media_path_non_multisite(): void {
		$path = 'wp-content/uploads/2024/03/image.jpg';
		$this->assertSame( $path, MultisiteHandler::rewrite_media_path( $path ) );
	}

	public function test_is_multisite_export_non_multisite(): void {
		$this->assertFalse( MultisiteHandler::is_multisite_export() );
	}
}
