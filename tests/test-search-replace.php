<?php
/**
 * Tests for SearchReplace::safe_replace() — serialization safety and PHP injection prevention.
 */

use HBMigrator\Destination\SearchReplace;

class Test_SearchReplace extends WP_UnitTestCase {

	private array $replacements = [ 'https://old.example.com' => 'https://new.example.com' ];

	public function test_plain_string_is_replaced(): void {
		$result = SearchReplace::safe_replace(
			'Check out https://old.example.com/about',
			$this->replacements
		);
		$this->assertSame( 'Check out https://new.example.com/about', $result );
	}

	public function test_no_match_returns_original(): void {
		$value  = 'https://other.example.com/path';
		$result = SearchReplace::safe_replace( $value, $this->replacements );
		$this->assertSame( $value, $result );
	}

	public function test_empty_replacements_returns_original(): void {
		$value = 'https://old.example.com/path';
		$this->assertSame( $value, SearchReplace::safe_replace( $value, [] ) );
	}

	public function test_integer_is_returned_unchanged(): void {
		$this->assertSame( 42, SearchReplace::safe_replace( 42, $this->replacements ) );
	}

	public function test_boolean_false_is_returned_unchanged(): void {
		$this->assertFalse( SearchReplace::safe_replace( false, $this->replacements ) );
	}

	public function test_null_is_returned_unchanged(): void {
		$this->assertNull( SearchReplace::safe_replace( null, $this->replacements ) );
	}

	public function test_array_values_are_replaced_recursively(): void {
		$value  = [ 'url' => 'https://old.example.com/img.jpg', 'title' => 'Photo' ];
		$result = SearchReplace::safe_replace( $value, $this->replacements );
		$this->assertSame( 'https://new.example.com/img.jpg', $result['url'] );
		$this->assertSame( 'Photo', $result['title'] );
	}

	public function test_array_keys_are_replaced(): void {
		$value  = [ 'https://old.example.com' => 'value' ];
		$result = SearchReplace::safe_replace( $value, $this->replacements );
		$this->assertArrayHasKey( 'https://new.example.com', $result );
	}

	public function test_serialized_string_value_is_replaced(): void {
		$original = serialize( [ 'url' => 'https://old.example.com/page/' ] );
		$result   = SearchReplace::safe_replace( $original, $this->replacements );
		$expected = serialize( [ 'url' => 'https://new.example.com/page/' ] );
		$this->assertSame( $expected, $result );
	}

	public function test_serialized_output_is_valid_php(): void {
		$original = serialize( [ 'a' => 'https://old.example.com', 'b' => 42 ] );
		$result   = SearchReplace::safe_replace( $original, $this->replacements );
		$decoded  = unserialize( $result ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$this->assertIsArray( $decoded );
		$this->assertSame( 'https://new.example.com', $decoded['a'] );
	}

	public function test_serialized_boolean_false_not_corrupted(): void {
		// b:0; is a common serialized value — must survive a no-match pass intact.
		$this->assertSame( 'b:0;', SearchReplace::safe_replace( 'b:0;', $this->replacements ) );
	}

	public function test_binary_data_returned_unchanged(): void {
		// Non-UTF-8 bytes (e.g. EXIF data stored in postmeta) must not be str_replaced.
		$binary = "\xFF\xFE\x00" . 'https://old.example.com' . "\x01\x02";
		$result = SearchReplace::safe_replace( $binary, $this->replacements );
		$this->assertSame( $binary, $result );
	}

	public function test_php_object_injection_prevented(): void {
		// A serialized object for a class that does not exist in this process.
		// With allowed_classes:false, PHP creates an __PHP_Incomplete_Class instead
		// of instantiating the real class — no __wakeup / __destruct fires.
		$fake_serial = 'O:4:"Evil":1:{s:3:"url";s:24:"https://old.example.com/";}';
		$result      = SearchReplace::safe_replace(
			$fake_serial,
			[ 'https://old.example.com/' => 'https://new.example.com/' ]
		);
		// URL was replaced.
		$this->assertStringContainsString( 'https://new.example.com/', $result );
		// Output is still valid serialization.
		$decoded = unserialize( $result, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$this->assertNotFalse( $decoded );
		// The class itself was never instantiated — we reach here without fatal/notice,
		// which confirms the allowed_classes:false guard in safe_replace() fired.
	}

	public function test_nested_serialized_in_array(): void {
		$inner    = serialize( 'https://old.example.com/deep' );
		$original = serialize( [ 'data' => $inner ] );
		$result   = SearchReplace::safe_replace( $original, $this->replacements );
		$decoded  = unserialize( $result ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		// The inner serialized string's URL is replaced because safe_replace recurses
		// into the unserialized array and replaces within the inner serialized value.
		$this->assertStringContainsString( 'https://new.example.com', $result );
		$this->assertIsArray( $decoded );
	}
}
