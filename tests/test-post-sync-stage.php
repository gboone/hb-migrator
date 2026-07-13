<?php
/**
 * Tests for PostSyncStage::process() (U3) — the 'posts' slot of a sync pass.
 *
 * Uses pre_http_request to mock SourceClient responses, mirroring test-post-importer.php's
 * convention, plus test-sync-dispatcher.php's pattern for constructing a 'syncing' site job.
 */

use HBMigrator\Destination\PostSyncStage;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_PostSyncStage extends WP_UnitTestCase {

	private int $mid;
	private int $jid;

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();

		$this->mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'testkey', null );
		$this->jid = MigrationRegistry::create_site_job(
			$this->mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/'
		);
		MigrationRegistry::update_migration_status( $this->mid, 'running' );
		MigrationRegistry::update_site_job( $this->jid, [
			'status'       => 'complete',
			'dest_blog_id' => get_current_blog_id(),
		] );
		MigrationRegistry::enable_site_job_sync( $this->jid );
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
	}

	private function mock_posts_response( array $posts ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $posts ) {
			if ( false !== strpos( $url, '/posts' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $posts ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	private function make_source_post( array $overrides = [] ): array {
		return array_merge( [
			'ID'                => 1,
			'post_author_email' => '',
			'post_date'         => '2024-01-01 00:00:00',
			'post_date_gmt'     => '2024-01-01 00:00:00',
			'post_content'      => 'Hello world.',
			'post_title'        => 'Test Post',
			'post_excerpt'      => '',
			'post_status'       => 'publish',
			'comment_status'    => 'open',
			'ping_status'       => 'open',
			'post_password'     => '',
			'post_name'         => 'test-post',
			'post_modified'     => '2024-01-01 00:00:00',
			'post_modified_gmt' => '2024-01-01 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_type'         => 'post',
			'post_mime_type'    => '',
			'comment_count'     => 0,
			'meta'              => [],
			'terms'             => [],
		], $overrides );
	}

	// -------------------------------------------------------------------------
	// Row-budget return-value contract.
	// -------------------------------------------------------------------------

	public function test_returns_false_when_no_posts_are_returned(): void {
		$this->mock_posts_response( [] );

		$stage = new PostSyncStage();
		$this->assertFalse( $stage->process( $this->jid ), 'No matching posts means already caught up.' );
	}

	public function test_returns_false_when_batch_is_under_row_budget(): void {
		$posts = [ $this->make_source_post( [ 'ID' => 1 ] ), $this->make_source_post( [ 'ID' => 2 ] ) ];
		$this->mock_posts_response( $posts );

		$stage = new PostSyncStage();
		$this->assertFalse( $stage->process( $this->jid ), 'A batch under the row budget means the stage is caught up.' );
	}

	public function test_returns_true_when_batch_hits_row_budget(): void {
		$posts = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$posts[] = $this->make_source_post( [ 'ID' => $i, 'post_name' => 'post-' . $i ] );
		}
		$this->mock_posts_response( $posts );

		$stage = new PostSyncStage();
		$this->assertTrue( $stage->process( $this->jid ), 'A full 100-row batch means more work likely remains.' );
	}

	// -------------------------------------------------------------------------
	// Cursor advance.
	// -------------------------------------------------------------------------

	public function test_cursor_advances_to_max_modified_seen_on_success(): void {
		$posts = [
			$this->make_source_post( [ 'ID' => 1, 'post_modified' => '2026-01-01 10:00:00' ] ),
			$this->make_source_post( [ 'ID' => 2, 'post_modified' => '2026-01-02 12:30:00' ] ),
		];
		$this->mock_posts_response( $posts );

		$stage = new PostSyncStage();
		$stage->process( $this->jid );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( '2026-01-02 12:30:00', $job->sync_cursor_posts );
	}

	public function test_cursor_is_untouched_when_no_posts_are_returned(): void {
		$this->mock_posts_response( [] );

		$stage = new PostSyncStage();
		$stage->process( $this->jid );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNull( $job->sync_cursor_posts );
	}

	public function test_cursor_does_not_advance_when_stage_throws(): void {
		// Simulate a failure partway through the HTTP layer — SourceClient::get() throws
		// on a non-200 response, which must propagate out of process() without ever
		// reaching the cursor-advance line.
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/posts' ) ) {
				return [
					'response' => [ 'code' => 500, 'message' => 'Error' ],
					'body'     => '',
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );

		$stage = new PostSyncStage();

		try {
			$stage->process( $this->jid );
			$this->fail( 'Expected a SourceClientException to propagate.' );
		} catch ( \HBMigrator\SourceClientException $e ) {
			// Expected — SyncDispatcher is the one that catches this in production.
		}

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNull( $job->sync_cursor_posts, 'A failed pass must leave the cursor untouched.' );
	}

	public function test_first_pass_with_null_cursor_still_imports_and_advances(): void {
		// Before the first successful sync pass, sync_cursor_posts is NULL. The stage must
		// still run (treating it as an epoch floor) rather than skip everything.
		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNull( $job->sync_cursor_posts );

		$posts = [ $this->make_source_post( [ 'ID' => 1, 'post_modified' => '2026-01-01 00:00:00' ] ) ];
		$this->mock_posts_response( $posts );

		$stage = new PostSyncStage();
		$stage->process( $this->jid );

		$this->assertNotNull( IdMap::get( $this->jid, 'post', 1 ) );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( '2026-01-01 00:00:00', $job->sync_cursor_posts );
	}

	// -------------------------------------------------------------------------
	// Integration: the post is actually imported via the normal Reader/Importer/IdMap path.
	// -------------------------------------------------------------------------

	public function test_new_post_is_imported_to_destination(): void {
		$this->mock_posts_response( [ $this->make_source_post( [ 'ID' => 7, 'post_title' => 'Synced Post' ] ) ] );

		$stage = new PostSyncStage();
		$stage->process( $this->jid );

		$dest_id = IdMap::get( $this->jid, 'post', 7 );
		$this->assertNotNull( $dest_id );
		$this->assertSame( 'Synced Post', get_post( $dest_id )->post_title );
	}
}
