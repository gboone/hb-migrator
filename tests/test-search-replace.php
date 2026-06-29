<?php
/**
 * Tests for SearchReplace::safe_replace() and _thumbnail_id remap.
 */

use HBMigrator\Destination\SearchReplace;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

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

/**
 * Tests for the _thumbnail_id integer postmeta remap that runs at finalization.
 * Exercises remap_postmeta_ids() directly via reflection.
 */
class Test_SearchReplace_Remap extends WP_UnitTestCase {

	private int $blog_id = 0;
	private int $jid     = 0;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			return;
		}
		QueueTable::maybe_create_or_upgrade();

		$this->blog_id = (int) wp_insert_site( [
			'domain'     => get_network()->domain,
			'path'       => '/remap-test-' . wp_generate_password( 6, false ) . '/',
			'network_id' => (int) get_network()->id,
			'title'      => 'Remap Test',
			'user_id'    => 1,
		] );

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$this->jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/remap-test/' );
		MigrationRegistry::update_site_job( $this->jid, [ 'dest_blog_id' => $this->blog_id, 'status' => 'running' ] );
	}

	public function tear_down(): void {
		parent::tear_down();
		if ( $this->blog_id ) {
			wp_delete_site( $this->blog_id );
		}
	}

	private function call_remap( int $site_job_id, int $blog_id ): void {
		$ref    = new ReflectionClass( SearchReplace::class );
		$method = $ref->getMethod( 'remap_postmeta_ids' );
		$method->setAccessible( true );
		$method->invoke( null, $site_job_id, $blog_id );
	}

	private function create_post_with_thumbnail( int $blog_id, int $source_att_id ): int {
		switch_to_blog( $blog_id );
		$post_id = wp_insert_post( [ 'post_title' => 'Test post', 'post_status' => 'publish', 'post_type' => 'post' ] );
		add_post_meta( $post_id, '_thumbnail_id', (string) $source_att_id );
		restore_current_blog();
		return (int) $post_id;
	}

	private function get_thumbnail_id( int $blog_id, int $post_id ): string {
		switch_to_blog( $blog_id );
		$val = get_post_meta( $post_id, '_thumbnail_id', true );
		restore_current_blog();
		return (string) $val;
	}

	// -------------------------------------------------------------------------
	// Happy path — source ID with an IdMap entry is rewritten to dest ID.
	// -------------------------------------------------------------------------

	public function test_remap_updates_thumbnail_id_when_id_map_entry_exists(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'remap_postmeta_ids() requires multisite.' );
		}

		$post_id = $this->create_post_with_thumbnail( $this->blog_id, 99 );
		IdMap::set( $this->jid, 'attachment', 99, 42 );

		$this->call_remap( $this->jid, $this->blog_id );

		$this->assertSame( '42', $this->get_thumbnail_id( $this->blog_id, $post_id ) );
	}

	// -------------------------------------------------------------------------
	// No IdMap entry — value must remain unchanged (attachment was not imported).
	// -------------------------------------------------------------------------

	public function test_remap_leaves_thumbnail_id_unchanged_when_no_id_map_entry(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'remap_postmeta_ids() requires multisite.' );
		}

		$post_id = $this->create_post_with_thumbnail( $this->blog_id, 77 );
		// Deliberately do not add an IdMap entry for source ID 77.

		$this->call_remap( $this->jid, $this->blog_id );

		$this->assertSame( '77', $this->get_thumbnail_id( $this->blog_id, $post_id ) );
	}

	// -------------------------------------------------------------------------
	// Multiple posts — all remapped in a single SQL pass.
	// -------------------------------------------------------------------------

	public function test_remap_updates_multiple_posts_in_one_pass(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'remap_postmeta_ids() requires multisite.' );
		}

		$post_a = $this->create_post_with_thumbnail( $this->blog_id, 100 );
		$post_b = $this->create_post_with_thumbnail( $this->blog_id, 200 );
		IdMap::set( $this->jid, 'attachment', 100, 11 );
		IdMap::set( $this->jid, 'attachment', 200, 22 );

		$this->call_remap( $this->jid, $this->blog_id );

		$this->assertSame( '11', $this->get_thumbnail_id( $this->blog_id, $post_a ) );
		$this->assertSame( '22', $this->get_thumbnail_id( $this->blog_id, $post_b ) );
	}

	// -------------------------------------------------------------------------
	// Mixed: one post remapped, one left alone (no entry for its ID).
	// -------------------------------------------------------------------------

	public function test_remap_remaps_only_posts_with_id_map_entries(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'remap_postmeta_ids() requires multisite.' );
		}

		$post_mapped   = $this->create_post_with_thumbnail( $this->blog_id, 300 );
		$post_unmapped = $this->create_post_with_thumbnail( $this->blog_id, 400 );
		IdMap::set( $this->jid, 'attachment', 300, 33 );
		// No entry for 400.

		$this->call_remap( $this->jid, $this->blog_id );

		$this->assertSame( '33', $this->get_thumbnail_id( $this->blog_id, $post_mapped ) );
		$this->assertSame( '400', $this->get_thumbnail_id( $this->blog_id, $post_unmapped ) );
	}
}
