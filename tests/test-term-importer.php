<?php
/**
 * Tests for TermImporter site conflict policy behaviour.
 *
 * These tests exercise create_subsite() via reflection since it is private.
 * Full process() tests would require multisite + mocked SourceClient; the
 * unit tests here isolate the path-collision logic directly.
 */

use HBMigrator\Destination\TermImporter;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_Term_Importer extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	/**
	 * Call TermImporter::create_subsite() via reflection.
	 */
	private function call_create_subsite( object $job, string $policy = 'generate_new' ): int {
		$ref    = new ReflectionClass( TermImporter::class );
		$method = $ref->getMethod( 'create_subsite' );
		$method->setAccessible( true );
		return (int) $method->invoke( null, $job, $policy );
	}

	private function make_job( array $overrides = [] ): object {
		return (object) array_merge( [
			'id'            => 0,
			'migration_id'  => 0,
			'source_blog_id' => 1,
			'source_domain' => 'example.com',
			'dest_path'     => '/testsite/',
			'dest_blog_id'  => null,
			'status'        => 'pending',
		], $overrides );
	}

	// -------------------------------------------------------------------------
	// These tests require multisite to exercise wp_insert_site().
	// -------------------------------------------------------------------------

	public function test_no_collision_creates_subsite_at_original_path(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$job    = $this->make_job( [ 'dest_path' => '/no-collision-test/' ] );
		$new_id = $this->call_create_subsite( $job, 'generate_new' );

		$this->assertGreaterThan( 0, $new_id );
		$site = get_site( $new_id );
		$this->assertNotNull( $site );
		$this->assertSame( '/no-collision-test/', $site->path );

		wp_delete_site( $new_id );
	}

	public function test_generate_new_creates_at_suffix_path_when_original_exists(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$network = get_network();
		// Create the conflicting site at /gn-collision/.
		$existing_id = wp_insert_site( [
			'domain'     => $network->domain,
			'path'       => '/gn-collision/',
			'network_id' => $network->id,
			'title'      => 'Existing',
			'user_id'    => 1,
		] );
		$this->assertNotInstanceOf( \WP_Error::class, $existing_id );

		$job    = $this->make_job( [ 'dest_path' => '/gn-collision/' ] );
		$new_id = $this->call_create_subsite( $job, 'generate_new' );

		$this->assertGreaterThan( 0, $new_id );
		$site = get_site( $new_id );
		$this->assertNotNull( $site );
		$this->assertSame( '/gn-collision-2/', $site->path );
		$this->assertSame( '/gn-collision-2/', $job->dest_path, 'job->dest_path must be updated to suffix path.' );

		wp_delete_site( $existing_id );
		wp_delete_site( $new_id );
	}

	public function test_generate_new_increments_counter_when_first_suffix_also_exists(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$network = get_network();
		$id1     = wp_insert_site( [ 'domain' => $network->domain, 'path' => '/double-collision/', 'network_id' => $network->id, 'title' => 'A', 'user_id' => 1 ] );
		$id2     = wp_insert_site( [ 'domain' => $network->domain, 'path' => '/double-collision-2/', 'network_id' => $network->id, 'title' => 'B', 'user_id' => 1 ] );
		$this->assertNotInstanceOf( \WP_Error::class, $id1 );
		$this->assertNotInstanceOf( \WP_Error::class, $id2 );

		$job    = $this->make_job( [ 'dest_path' => '/double-collision/' ] );
		$new_id = $this->call_create_subsite( $job, 'generate_new' );

		$this->assertGreaterThan( 0, $new_id );
		$site = get_site( $new_id );
		$this->assertSame( '/double-collision-3/', $site->path );

		wp_delete_site( $id1 );
		wp_delete_site( $id2 );
		wp_delete_site( $new_id );
	}

	public function test_use_existing_returns_existing_blog_id_without_creating(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$network     = get_network();
		$existing_id = wp_insert_site( [
			'domain'     => $network->domain,
			'path'       => '/use-existing-test/',
			'network_id' => $network->id,
			'title'      => 'Existing',
			'user_id'    => 1,
		] );
		$this->assertNotInstanceOf( \WP_Error::class, $existing_id );

		$site_count_before = count( get_sites( [ 'number' => 0 ] ) );

		$job      = $this->make_job( [ 'dest_path' => '/use-existing-test/' ] );
		$returned = $this->call_create_subsite( $job, 'use_existing' );

		$this->assertSame( (int) $existing_id, $returned, 'use_existing must return the existing site ID.' );

		$site_count_after = count( get_sites( [ 'number' => 0 ] ) );
		$this->assertSame( $site_count_before, $site_count_after, 'No new site should have been created.' );

		wp_delete_site( $existing_id );
	}
}
