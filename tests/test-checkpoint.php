<?php
/**
 * Tests for MigrationRegistry, IdMap, and QueueTable (v2 schema).
 */

use HBMigrator\MigrationRegistry;
use HBMigrator\IdMap;
use HBMigrator\QueueTable;

class Test_MigrationRegistry extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function test_create_and_get_migration(): void {
		$id = MigrationRegistry::create_migration( 'https://source.example.com', 'testapikey', 'admin@example.com' );
		$this->assertGreaterThan( 0, $id );
		$m = MigrationRegistry::get_migration( $id );
		$this->assertNotNull( $m );
		$this->assertSame( 'https://source.example.com', $m->source_url );
		$this->assertSame( 'pending', $m->status );
	}

	public function test_update_migration_status(): void {
		$id = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		MigrationRegistry::update_migration_status( $id, 'running' );
		$m = MigrationRegistry::get_migration( $id );
		$this->assertSame( 'running', $m->status );
	}

	public function test_complete_migration(): void {
		$id  = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $id, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_migration_status( $id, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'complete' ] );
		$this->assertTrue( MigrationRegistry::complete_migration( $id ) );
		$m = MigrationRegistry::get_migration( $id );
		$this->assertSame( 'complete', $m->status );
		$this->assertNotNull( $m->completed_at );
	}

	public function test_complete_migration_requires_running_status(): void {
		// Migration in 'pending' state should not be completed — it was never started.
		$id  = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $id, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'complete' ] );
		$this->assertFalse( MigrationRegistry::complete_migration( $id ) );
		$this->assertSame( 'pending', MigrationRegistry::get_migration( $id )->status );
	}

	public function test_complete_migration_blocked_when_job_incomplete(): void {
		$id  = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $id, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_migration_status( $id, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'running' ] );
		$this->assertFalse( MigrationRegistry::complete_migration( $id ) );
		$this->assertSame( 'running', MigrationRegistry::get_migration( $id )->status );
	}

	public function test_complete_migration_clears_source_api_key(): void {
		$id  = MigrationRegistry::create_migration( 'https://source.example.com', 'supersecretkey', null );
		$jid = MigrationRegistry::create_site_job( $id, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_migration_status( $id, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'complete' ] );
		MigrationRegistry::complete_migration( $id );
		$this->assertSame( '', MigrationRegistry::get_migration( $id )->source_api_key );
	}

	public function test_complete_migration_is_idempotent(): void {
		$id  = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $id, 1, 'example.com', 'https://example.com', '', '/example.com/' );
		MigrationRegistry::update_migration_status( $id, 'running' );
		MigrationRegistry::update_site_job( $jid, [ 'status' => 'complete' ] );
		$this->assertTrue( MigrationRegistry::complete_migration( $id ) );
		$this->assertFalse( MigrationRegistry::complete_migration( $id ) );
	}

	public function test_create_site_job(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 4, 'example.com', 'https://example.com', 'https://example.com/wp-content/uploads/', '/example.com/' );
		$this->assertGreaterThan( 0, $jid );
		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 4, (int) $job->source_blog_id );
		$this->assertSame( '/example.com/', $job->dest_path );
	}

	public function test_all_sites_complete(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$j1  = MigrationRegistry::create_site_job( $mid, 4, 'example.com', 'https://example.com', '', '/example.com/' );
		$j2  = MigrationRegistry::create_site_job( $mid, 7, 'news.example.com', 'https://news.example.com', '', '/news.example.com/' );
		$this->assertFalse( MigrationRegistry::all_sites_complete( $mid ) );
		MigrationRegistry::update_site_job( $j1, [ 'status' => 'complete' ] );
		$this->assertFalse( MigrationRegistry::all_sites_complete( $mid ) );
		MigrationRegistry::update_site_job( $j2, [ 'status' => 'complete' ] );
		$this->assertTrue( MigrationRegistry::all_sites_complete( $mid ) );
	}

	public function test_schema_upgrade_updates_version(): void {
		delete_site_option( 'hbm_db_version' );
		QueueTable::maybe_create_or_upgrade();
		$this->assertSame( HBM_DB_VERSION, (int) get_site_option( 'hbm_db_version' ) );
	}
}

class Test_IdMap extends WP_UnitTestCase {

	public function test_set_and_get(): void {
		IdMap::set( 1, 'post', 100, 200 );
		$this->assertSame( 200, IdMap::get( 1, 'post', 100 ) );
	}

	public function test_get_missing_returns_null(): void {
		$this->assertNull( IdMap::get( 999, 'post', 999 ) );
	}

	public function test_network_user_mapping(): void {
		IdMap::set( IdMap::NETWORK, 'user', 5, 12 );
		$this->assertSame( 12, IdMap::get( IdMap::NETWORK, 'user', 5 ) );
	}

	public function test_upsert(): void {
		IdMap::set( 10, 'post', 1, 100 );
		IdMap::set( 10, 'post', 1, 200 );
		$this->assertSame( 200, IdMap::get( 10, 'post', 1 ) );
	}

	public function test_delete_for_job(): void {
		IdMap::set( 42, 'post', 1, 2 );
		IdMap::delete_for_job( 42 );
		$this->assertNull( IdMap::get( 42, 'post', 1 ) );
	}
}
