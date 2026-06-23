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

	// --- network_domain slug-stripping (prevents dotted paths on VIP subdirectory multisite) ---

	public function test_subdomain_stripped_to_slug_when_network_domain_matches(): void {
		// greg.harmsboone.org on sites.harmsboone.org → /greg/ not /greg.harmsboone.org/
		$this->assertSame(
			'/greg/',
			MultisiteHandler::dest_path_for_siteurl( 'https://greg.harmsboone.org', 'sites.harmsboone.org' )
		);
	}

	public function test_subdomain_with_subdirectory_stripped(): void {
		$this->assertSame(
			'/greg/store/',
			MultisiteHandler::dest_path_for_siteurl( 'https://greg.harmsboone.org/store', 'sites.harmsboone.org' )
		);
	}

	public function test_multi_label_subdomain_not_stripped_to_avoid_dotted_slug(): void {
		// news.greg.harmsboone.org would leave slug "news.greg" — still dotted, keep full path.
		$this->assertSame(
			'/news.greg.harmsboone.org/',
			MultisiteHandler::dest_path_for_siteurl( 'https://news.greg.harmsboone.org', 'sites.harmsboone.org' )
		);
	}

	public function test_base_domain_not_stripped(): void {
		// harmsboone.org itself does not end with .harmsboone.org, so no stripping.
		$this->assertSame(
			'/harmsboone.org/',
			MultisiteHandler::dest_path_for_siteurl( 'https://harmsboone.org', 'sites.harmsboone.org' )
		);
	}

	public function test_no_network_domain_falls_back_to_full_host(): void {
		// Without network_domain, existing behaviour is preserved.
		$this->assertSame(
			'/greg.harmsboone.org/',
			MultisiteHandler::dest_path_for_siteurl( 'https://greg.harmsboone.org' )
		);
	}

	public function test_network_domain_is_bare_base_domain(): void {
		// Network is harmsboone.org (no subdomain prefix), source is greg.harmsboone.org.
		$this->assertSame(
			'/greg/',
			MultisiteHandler::dest_path_for_siteurl( 'https://greg.harmsboone.org', 'harmsboone.org' )
		);
	}
}
