<?php
/**
 * Tests for TermImporter site conflict policy behaviour.
 *
 * These tests exercise create_subsite() via reflection since it is private.
 * Full process() tests would require multisite + mocked SourceClient; the
 * unit tests here isolate the path-collision logic directly.
 */

use HBMigrator\Destination\AuditReport;
use HBMigrator\Destination\TermImporter;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_Term_Importer extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
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

	// -------------------------------------------------------------------------
	// Email suppression tests
	// -------------------------------------------------------------------------

	public function test_create_subsite_does_not_trigger_mail_send(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$phpmailer_fired = false;
		$sentinel        = function() use ( &$phpmailer_fired ) {
			$phpmailer_fired = true;
		};
		add_action( 'phpmailer_init', $sentinel );

		$job    = $this->make_job( [ 'dest_path' => '/mail-suppress-test/' ] );
		$new_id = $this->call_create_subsite( $job );
		$this->assertGreaterThan( 0, $new_id );

		remove_action( 'phpmailer_init', $sentinel );
		wp_delete_site( $new_id );

		$this->assertFalse( $phpmailer_fired, 'phpmailer must not be initialized during subsite creation.' );
	}

	public function test_create_subsite_removes_pre_wp_mail_filter_on_success(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$count_before = count( array_keys( $GLOBALS['wp_filter']['pre_wp_mail']->callbacks ?? [] ) );

		$job    = $this->make_job( [ 'dest_path' => '/filter-cleanup-test/' ] );
		$new_id = $this->call_create_subsite( $job );
		wp_delete_site( $new_id );

		$count_after = count( array_keys( $GLOBALS['wp_filter']['pre_wp_mail']->callbacks ?? [] ) );
		$this->assertSame( $count_before, $count_after, 'pre_wp_mail filter must not remain registered after create_subsite() returns.' );
	}

	// -------------------------------------------------------------------------
	// dest_blog_id guard in process() — deleted and soft-deleted blog handling.
	// These tests call process() via a mocked HTTP source so we can exercise the
	// early guard without running the full pipeline. We verify via the DB that
	// the site was created (or not) and that dest_blog_id was reset.
	// -------------------------------------------------------------------------

	public function test_process_resets_dest_blog_id_and_creates_subsite_when_blog_hard_deleted(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		// Create a site, grab its ID, then delete it.
		$deleted_id = wp_insert_site( [
			'domain'     => get_network()->domain,
			'path'       => '/guard-hard-delete/',
			'network_id' => get_network()->id,
			'title'      => 'Temporary',
			'user_id'    => 1,
		] );
		$this->assertIsInt( $deleted_id );
		wp_delete_site( $deleted_id );

		$this->assertNull( get_site( $deleted_id ), 'Pre-condition: blog must be fully gone.' );

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/guard-hard-reset/' );
		// Seed a stale dest_blog_id pointing at the deleted blog.
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => $deleted_id ] );

		// Intercept source HTTP calls so process() can reach the guard.
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/terms' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		TermImporter::process( $jid, 0, 0 );

		remove_all_filters( 'pre_http_request' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNotEquals( $deleted_id, (int) $job->dest_blog_id, 'dest_blog_id must be reset to a new site, not the deleted one.' );
		$this->assertGreaterThan( 0, (int) $job->dest_blog_id, 'A new subsite must have been created.' );

		wp_delete_site( (int) $job->dest_blog_id );
	}

	public function test_process_resets_dest_blog_id_when_blog_is_soft_deleted(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		// Create a site and mark it soft-deleted (deleted=1) directly in the DB.
		$soft_deleted_id = wp_insert_site( [
			'domain'     => get_network()->domain,
			'path'       => '/guard-soft-delete/',
			'network_id' => get_network()->id,
			'title'      => 'Soft Deleted',
			'user_id'    => 1,
		] );
		$this->assertIsInt( $soft_deleted_id );
		global $wpdb;
		$wpdb->update( $wpdb->blogs, [ 'deleted' => 1 ], [ 'blog_id' => $soft_deleted_id ] );
		clean_blog_cache( $soft_deleted_id );

		$site = get_site( $soft_deleted_id );
		$this->assertNotNull( $site );
		$this->assertSame( 1, (int) $site->deleted, 'Pre-condition: blog must be soft-deleted.' );

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/guard-soft-reset/' );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => $soft_deleted_id ] );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/terms' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		TermImporter::process( $jid, 0, 0 );

		remove_all_filters( 'pre_http_request' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNotEquals( $soft_deleted_id, (int) $job->dest_blog_id, 'dest_blog_id must be reset away from soft-deleted blog.' );
		$this->assertGreaterThan( 0, (int) $job->dest_blog_id, 'A new subsite must have been created.' );

		wp_delete_site( $soft_deleted_id );
		wp_delete_site( (int) $job->dest_blog_id );
	}

	public function test_process_does_not_reset_dest_blog_id_when_blog_is_live(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		// Create a live blog to use as the existing dest.
		$live_id = wp_insert_site( [
			'domain'     => get_network()->domain,
			'path'       => '/guard-live/',
			'network_id' => get_network()->id,
			'title'      => 'Live',
			'user_id'    => 1,
		] );
		$this->assertIsInt( $live_id );

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/guard-live/' );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => $live_id ] );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/terms' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		TermImporter::process( $jid, 0, 0 );

		remove_all_filters( 'pre_http_request' );

		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( $live_id, (int) $job->dest_blog_id, 'dest_blog_id must NOT be changed when blog is live.' );

		wp_delete_site( $live_id );
	}

	// -------------------------------------------------------------------------
	// U3: request-trail capture for TermImporter's source/sites/{id}/terms listing (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U3. Request-trail
	// capture at every outbound source call"). TermImporter is site-job-scoped, so entries
	// are recorded via AuditReport::record() (scope: site_job).
	// -------------------------------------------------------------------------

	public function test_process_records_site_job_scoped_request_trail_entry_on_success(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/audit-term-success/' );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/terms' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( [
						[ 'term_id' => 1, 'name' => 'Cat', 'slug' => 'cat', 'taxonomy' => 'category', 'parent' => 0, 'description' => '' ],
					] ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		TermImporter::process( $jid, 0, 0 );

		remove_all_filters( 'pre_http_request' );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'site_job', $rows[0]['scope'] );
		$this->assertTrue( $rows[0]['success'] );
		$this->assertSame( 1, $rows[0]['count'] );

		$job = MigrationRegistry::get_site_job( $jid );
		if ( $job->dest_blog_id ) {
			wp_delete_site( (int) $job->dest_blog_id );
		}
	}

	public function test_process_records_failed_request_trail_entry_when_source_unreachable(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/audit-term-fail/' );

		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/terms' ) ) {
				return [
					'response' => [ 'code' => 500, 'message' => 'Internal Server Error' ],
					'body'     => '',
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		TermImporter::process( $jid, 0, 0 );

		remove_all_filters( 'pre_http_request' );

		// Existing error-handling behavior is unchanged by U3: a retryable failure at
		// attempt 0 is rescheduled by PipelineController, not marked failed outright.
		$job = MigrationRegistry::get_site_job( $jid );
		$this->assertNotSame( 'failed', $job->status );

		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'site_job', $rows[0]['scope'] );
		$this->assertFalse( $rows[0]['success'] );
		$this->assertArrayHasKey( 'error', $rows[0] );

		if ( $job->dest_blog_id ) {
			wp_delete_site( (int) $job->dest_blog_id );
		}
	}

	// -------------------------------------------------------------------------
	// U4: write-action trail for terms and subsite creation (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U4. Write-action trail:
	// terms, users, options"). TermImporter is site-job-scoped, so entries are recorded via
	// AuditReport::record() (scope: site_job).
	// -------------------------------------------------------------------------

	private function mock_terms_response( array $terms ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $terms ) {
			if ( false !== strpos( $url, '/source/sites/' ) && false !== strpos( $url, '/terms' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $terms ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	private function get_write_rows( int $jid ): array {
		$post_id = AuditReport::get_or_create_for_site_job( $jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_write', false );
		restore_current_blog();
		return $rows;
	}

	public function test_process_records_created_outcome_for_new_term(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/audit-term-created/' );

		$this->mock_terms_response( [
			[ 'term_id' => 111, 'name' => 'Dog', 'slug' => 'audit-new-term-dog', 'taxonomy' => 'category', 'parent' => 0, 'description' => '' ],
		] );

		TermImporter::process( $jid, 0, 0 );
		remove_all_filters( 'pre_http_request' );

		$rows = $this->get_write_rows( $jid );
		$term_rows = array_values( array_filter( $rows, fn( $r ) => 'term' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( 1, $term_rows );
		$this->assertSame( 'created', $term_rows[0]['outcome'] );
		$this->assertSame( 111, $term_rows[0]['source_id'] );
		$this->assertArrayHasKey( 'dest_id', $term_rows[0] );

		$job = MigrationRegistry::get_site_job( $jid );
		if ( $job->dest_blog_id ) {
			wp_delete_site( (int) $job->dest_blog_id );
		}
	}

	public function test_process_records_matched_outcome_for_existing_term_by_slug(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/audit-term-matched/' );

		// Pre-create the destination subsite and a term on it with the matching slug, so
		// the per-item loop's get_term_by( 'slug', ... ) finds it and takes the "matched" branch.
		$job = MigrationRegistry::get_site_job( $jid );
		$network = get_network();
		$dest_blog_id = wp_insert_site( [
			'domain'     => $network->domain,
			'path'       => '/audit-term-matched-dest/',
			'network_id' => $network->id,
			'title'      => 'Dest',
			'user_id'    => 1,
		] );
		$this->assertNotInstanceOf( \WP_Error::class, $dest_blog_id );
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => $dest_blog_id ] );

		switch_to_blog( $dest_blog_id );
		wp_insert_term( 'Existing Cat', 'category', [ 'slug' => 'audit-existing-term-cat' ] );
		restore_current_blog();

		$this->mock_terms_response( [
			[ 'term_id' => 222, 'name' => 'Existing Cat', 'slug' => 'audit-existing-term-cat', 'taxonomy' => 'category', 'parent' => 0, 'description' => '' ],
		] );

		TermImporter::process( $jid, 0, 0 );
		remove_all_filters( 'pre_http_request' );

		$rows = $this->get_write_rows( $jid );
		$term_rows = array_values( array_filter( $rows, fn( $r ) => 'term' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( 1, $term_rows );
		$this->assertSame( 'matched', $term_rows[0]['outcome'] );
		$this->assertSame( 222, $term_rows[0]['source_id'] );

		wp_delete_site( $dest_blog_id );
	}

	public function test_process_records_failed_outcome_for_term_wp_insert_term_error(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'process() requires multisite.' );
		}

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		MigrationRegistry::update_migration_status( $mid, 'running' );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/audit-term-failed/' );

		// An empty slug/name combination that wp_insert_term() rejects (WP requires a non-empty
		// term name) forces the existing is_wp_error() branch this loop already handles.
		$this->mock_terms_response( [
			[ 'term_id' => 333, 'name' => '', 'slug' => 'audit-fail-term', 'taxonomy' => 'category', 'parent' => 0, 'description' => '' ],
		] );

		TermImporter::process( $jid, 0, 0 );
		remove_all_filters( 'pre_http_request' );

		$rows = $this->get_write_rows( $jid );
		$term_rows = array_values( array_filter( $rows, fn( $r ) => 'term' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( 1, $term_rows );
		$this->assertSame( 'failed', $term_rows[0]['outcome'] );
		$this->assertSame( 333, $term_rows[0]['source_id'] );
		$this->assertArrayHasKey( 'error', $term_rows[0] );

		$job = MigrationRegistry::get_site_job( $jid );
		if ( $job->dest_blog_id ) {
			wp_delete_site( (int) $job->dest_blog_id );
		}
	}

	public function test_create_subsite_records_write_trail_entry_on_success(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', '/audit-subsite-success/' );

		$job    = $this->make_job( [ 'id' => $jid, 'dest_path' => '/audit-subsite-success/' ] );
		$new_id = $this->call_create_subsite( $job, 'generate_new' );

		$rows        = $this->get_write_rows( $jid );
		$subsite_rows = array_values( array_filter( $rows, fn( $r ) => 'subsite' === ( $r['object_type'] ?? null ) ) );
		$this->assertCount( 1, $subsite_rows );
		$this->assertSame( 'created', $subsite_rows[0]['outcome'] );
		$this->assertSame( $new_id, $subsite_rows[0]['dest_id'] );

		wp_delete_site( $new_id );
	}

	public function test_create_subsite_records_write_trail_entry_on_path_collision_exhaustion(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'create_subsite() requires multisite.' );
		}

		$network = get_network();
		$base    = '/audit-exhaust-test';
		$created_ids = [];
		// Original path plus every generate_new suffix (-2 .. -11) so all 11 wp_insert_site()
		// attempts collide and create_subsite_inner() exhausts its retries.
		for ( $i = 1; $i <= 11; $i++ ) {
			$path = ( 1 === $i ) ? $base . '/' : $base . '-' . $i . '/';
			$id   = wp_insert_site( [
				'domain'     => $network->domain,
				'path'       => $path,
				'network_id' => $network->id,
				'title'      => 'Blocker ' . $i,
				'user_id'    => 1,
			] );
			$this->assertNotInstanceOf( \WP_Error::class, $id );
			$created_ids[] = (int) $id;
		}

		$mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'key', null );
		$jid = MigrationRegistry::create_site_job( $mid, 1, 'example.com', 'https://93.184.216.34', '', $base . '/' );

		$job = $this->make_job( [ 'id' => $jid, 'dest_path' => $base . '/' ] );

		$threw = false;
		try {
			$this->call_create_subsite( $job, 'generate_new' );
		} catch ( \RuntimeException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw, 'Subsite creation must throw once all path-collision retries are exhausted.' );

		$rows = $this->get_write_rows( $jid );
		$this->assertNotEmpty( $rows, 'A site job that fails during subsite creation must not have an empty report.' );

		$failed_rows = array_values( array_filter(
			$rows,
			fn( $r ) => 'subsite' === ( $r['object_type'] ?? null ) && 'failed' === ( $r['outcome'] ?? null )
		) );
		$this->assertCount( 1, $failed_rows );
		$this->assertArrayHasKey( 'error', $failed_rows[0] );

		$job_row = MigrationRegistry::get_site_job( $jid );
		$this->assertSame( 'failed', $job_row->status );

		foreach ( $created_ids as $id ) {
			wp_delete_site( $id );
		}
	}
}
