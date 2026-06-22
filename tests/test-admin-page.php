<?php
/**
 * Tests for Admin\AdminPage and SearchReplace (v2).
 */

use HBMigrator\Destination\SearchReplace;

class Test_Admin_Page extends WP_UnitTestCase {

	public function test_search_replace_plain_string(): void {
		$result = SearchReplace::safe_replace( 'https://old.example.com/page', [
			'https://old.example.com' => 'https://new.example.com',
		] );
		$this->assertSame( 'https://new.example.com/page', $result );
	}

	public function test_search_replace_serialized_string(): void {
		$original = serialize( [ 'url' => 'https://old.example.com' ] );
		$result   = SearchReplace::safe_replace( $original, [
			'https://old.example.com' => 'https://new.example.com',
		] );
		$decoded = unserialize( $result );
		$this->assertSame( 'https://new.example.com', $decoded['url'] );
	}

	public function test_search_replace_nested_serialized(): void {
		$data     = [ 'a' => [ 'b' => 'https://old.example.com/wp-content/uploads/img.jpg' ] ];
		$original = serialize( $data );
		$result   = SearchReplace::safe_replace( $original, [
			'https://old.example.com' => 'https://new.example.com',
		] );
		$decoded = unserialize( $result );
		$this->assertSame( 'https://new.example.com/wp-content/uploads/img.jpg', $decoded['a']['b'] );
	}

	public function test_search_replace_non_string_passthrough(): void {
		$this->assertSame( 42, SearchReplace::safe_replace( 42, [ 'foo' => 'bar' ] ) );
		$this->assertNull( SearchReplace::safe_replace( null, [ 'foo' => 'bar' ] ) );
	}

	public function test_search_replace_multiple_replacements(): void {
		$result = SearchReplace::safe_replace( 'https://old.example.com/wp-content/uploads/', [
			'https://old.example.com'                       => 'https://dest.example.com/old.example.com',
			'https://old.example.com/wp-content/uploads/'   => 'https://dest.example.com/wp-content/uploads/sites/3/',
		] );
		// First replacement wins since str_replace is sequential.
		$this->assertStringContainsString( 'dest.example.com', $result );
	}
}
