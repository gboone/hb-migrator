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

	public function test_find_site_job_by_domain_matches_source_domain(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'blog.example.com', 'https://blog.example.com', '', '/blog/' );

		$found = MigrationRegistry::find_site_job_by_domain( 'blog.example.com' );
		$this->assertNotNull( $found );
		$this->assertSame( $jid, (int) $found->id );
	}

	public function test_find_site_job_by_domain_matches_destination_domain(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'find_site_job_by_domain() dest-domain resolution requires multisite.' );
		}

		$dest_blog_id = (int) wp_insert_site( [
			'domain'     => get_network()->domain,
			'path'       => '/find-by-domain-test-' . wp_generate_password( 6, false ) . '/',
			'network_id' => (int) get_network()->id,
			'title'      => 'Find By Domain Test',
			'user_id'    => 1,
		] );

		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		// Deliberately no source_domain match for the destination domain we'll search for,
		// so this only resolves via the dest_blog_id -> get_site() fallback.
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'no-match-here.example.com', 'https://no-match-here.example.com', '', '/find-by-domain-test/' );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => $dest_blog_id ] );

		$found = MigrationRegistry::find_site_job_by_domain( get_network()->domain );
		$this->assertNotNull( $found );
		$this->assertSame( $jid, (int) $found->id );
	}

	public function test_find_site_job_by_domain_returns_null_when_no_match(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		MigrationRegistry::create_site_job( $mid, 1, 'blog.example.com', 'https://blog.example.com', '', '/blog/' );

		$this->assertNull( MigrationRegistry::find_site_job_by_domain( 'totally-unrelated.example.com' ) );
	}

	public function test_summarize_site_jobs_collects_distinct_source_domains(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		MigrationRegistry::create_site_job( $mid, 1, 'blog1.example.com', 'https://blog1.example.com', '', '/blog1/' );
		MigrationRegistry::create_site_job( $mid, 2, 'blog2.example.com', 'https://blog2.example.com', '', '/blog2/' );

		$summary = MigrationRegistry::summarize_site_jobs( $mid );
		$this->assertSame( [ 'blog1.example.com', 'blog2.example.com' ], $summary['source_domains'] );
	}

	public function test_summarize_site_jobs_dedupes_destination_domains(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'summarize_site_jobs() dest-domain resolution requires multisite.' );
		}

		// Path-based multisite: both site jobs resolve to the SAME destination domain,
		// differing only by path — the summary must not repeat it twice.
		$dest_blog_id_1 = (int) wp_insert_site( [
			'domain'     => get_network()->domain,
			'path'       => '/summarize-test-a-' . wp_generate_password( 6, false ) . '/',
			'network_id' => (int) get_network()->id,
			'title'      => 'Summarize Test A',
			'user_id'    => 1,
		] );
		$dest_blog_id_2 = (int) wp_insert_site( [
			'domain'     => get_network()->domain,
			'path'       => '/summarize-test-b-' . wp_generate_password( 6, false ) . '/',
			'network_id' => (int) get_network()->id,
			'title'      => 'Summarize Test B',
			'user_id'    => 1,
		] );

		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$j1  = MigrationRegistry::create_site_job( $mid, 1, 'blog1.example.com', 'https://blog1.example.com', '', '/a/' );
		$j2  = MigrationRegistry::create_site_job( $mid, 2, 'blog2.example.com', 'https://blog2.example.com', '', '/b/' );
		MigrationRegistry::update_site_job( $j1, [ 'dest_blog_id' => $dest_blog_id_1 ] );
		MigrationRegistry::update_site_job( $j2, [ 'dest_blog_id' => $dest_blog_id_2 ] );

		$summary = MigrationRegistry::summarize_site_jobs( $mid );
		$this->assertSame( [ get_network()->domain ], $summary['dest_domains'] );
	}

	public function test_summarize_site_jobs_last_sync_is_the_most_recent_pass_across_jobs(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );
		$j1  = MigrationRegistry::create_site_job( $mid, 1, 'blog1.example.com', 'https://blog1.example.com', '', '/blog1/' );
		$j2  = MigrationRegistry::create_site_job( $mid, 2, 'blog2.example.com', 'https://blog2.example.com', '', '/blog2/' );
		MigrationRegistry::update_site_job( $j1, [ 'sync_last_pass_at' => '2026-01-01 00:00:00' ] );
		MigrationRegistry::update_site_job( $j2, [ 'sync_last_pass_at' => '2026-06-15 12:30:00' ] );

		$summary = MigrationRegistry::summarize_site_jobs( $mid );
		$this->assertSame( '2026-06-15 12:30:00', $summary['last_sync'] );
	}

	public function test_summarize_site_jobs_with_no_site_jobs_returns_empty_summary(): void {
		$mid = MigrationRegistry::create_migration( 'https://source.example.com', 'key', null );

		$summary = MigrationRegistry::summarize_site_jobs( $mid );
		$this->assertSame( [], $summary['source_domains'] );
		$this->assertSame( [], $summary['dest_domains'] );
		$this->assertNull( $summary['last_sync'] );
	}

	public function test_schema_upgrade_updates_version(): void {
		delete_site_option( 'hbm_db_version' );
		// maybe_create_or_upgrade() only runs in an admin or WP_CLI context (race-avoidance
		// guard) — set_current_screen() makes is_admin() report true so this test can exercise
		// the real upgrade path instead of the guard.
		set_current_screen( 'dashboard' );
		QueueTable::maybe_create_or_upgrade();
		set_current_screen( 'front' );
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
