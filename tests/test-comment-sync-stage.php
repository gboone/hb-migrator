<?php
/**
 * Tests for CommentSyncStage::process() (U5) — the 'comments' slot of a sync pass.
 *
 * Uses pre_http_request to mock SourceClient responses, mirroring test-post-sync-stage.php's
 * convention, plus test-sync-dispatcher.php's pattern for constructing a 'syncing' site job.
 */

use HBMigrator\Destination\CommentSyncStage;
use HBMigrator\Destination\SyncDispatcher;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_CommentSyncStage extends WP_UnitTestCase {

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

	private function mock_response( string $path_fragment, array $items ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $path_fragment, $items ) {
			if ( false !== strpos( $url, $path_fragment ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $items ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	private function mock_comments_response( array $comments ): void {
		$this->mock_response( '/comments', $comments );
	}

	private function mock_posts_response( array $posts ): void {
		$this->mock_response( '/posts', $posts );
	}

	private function make_source_comment( array $overrides = [] ): array {
		return array_merge( [
			'comment_ID'           => 1,
			'comment_post_ID'      => 1,
			'comment_parent'       => 0,
			'comment_type'         => 'comment',
			'user_id'              => 0,
			'comment_approved'     => '1',
			'comment_content'      => 'Hello world.',
			'comment_date'         => '2026-01-01 00:00:00',
			'comment_date_gmt'     => '2026-01-01 00:00:00',
			'comment_author'       => 'Jane Doe',
			'comment_author_email' => 'jane@example.com',
			'comment_author_url'   => 'https://example.com',
		], $overrides );
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

	public function test_returns_false_when_no_comments_are_returned(): void {
		$this->mock_comments_response( [] );

		$stage = new CommentSyncStage();
		$this->assertFalse( $stage->process( $this->jid ), 'No new comments means already caught up.' );
	}

	public function test_returns_false_when_batch_is_under_row_budget(): void {
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$this->mock_comments_response( [
			$this->make_source_comment( [ 'comment_ID' => 1 ] ),
			$this->make_source_comment( [ 'comment_ID' => 2 ] ),
		] );

		$stage = new CommentSyncStage();
		$this->assertFalse( $stage->process( $this->jid ) );
	}

	public function test_returns_true_when_batch_hits_row_budget_and_none_blocked(): void {
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$comments = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$comments[] = $this->make_source_comment( [ 'comment_ID' => $i ] );
		}
		$this->mock_comments_response( $comments );

		$stage = new CommentSyncStage();
		$this->assertTrue( $stage->process( $this->jid ), 'A full 100-row batch with nothing blocked means more work likely remains.' );
	}

	// -------------------------------------------------------------------------
	// Cursor advance.
	// -------------------------------------------------------------------------

	public function test_cursor_advances_to_max_comment_id_seen_on_success(): void {
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$this->mock_comments_response( [
			$this->make_source_comment( [ 'comment_ID' => 5 ] ),
			$this->make_source_comment( [ 'comment_ID' => 9 ] ),
		] );

		$stage = new CommentSyncStage();
		$stage->process( $this->jid );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 9, (int) $job->sync_cursor_comments );
	}

	public function test_cursor_is_untouched_when_no_comments_are_returned(): void {
		$this->mock_comments_response( [] );

		$stage = new CommentSyncStage();
		$stage->process( $this->jid );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 0, (int) $job->sync_cursor_comments );
	}

	public function test_cursor_does_not_advance_when_stage_throws(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/comments' ) ) {
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

		$stage = new CommentSyncStage();

		try {
			$stage->process( $this->jid );
			$this->fail( 'Expected a SourceClientException to propagate.' );
		} catch ( \HBMigrator\SourceClientException $e ) {
			// Expected — SyncDispatcher is the one that catches this in production.
		}

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 0, (int) $job->sync_cursor_comments, 'A failed pass must leave the cursor untouched.' );
	}

	public function test_first_pass_with_zero_cursor_still_imports_and_advances(): void {
		// Before any successful pass, sync_cursor_comments is the column default 0 — this
		// IS the one-time backfill mechanism (no separate "backfill mode" needed).
		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 0, (int) $job->sync_cursor_comments );

		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$this->mock_comments_response( [ $this->make_source_comment( [ 'comment_ID' => 1 ] ) ] );

		$stage = new CommentSyncStage();
		$stage->process( $this->jid );

		$this->assertNotNull( IdMap::get( $this->jid, 'comment', 1 ) );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 1, (int) $job->sync_cursor_comments );
	}

	// -------------------------------------------------------------------------
	// Cursor must not skip past a blocked (unresolved-reference) comment — otherwise it
	// would never be retried, contradicting R6's "skipped this pass, not dropped".
	// -------------------------------------------------------------------------

	public function test_cursor_does_not_advance_past_a_blocked_comment(): void {
		// comment_ID 1 references a post that HAS synced (source post ID 1); comment_ID 2
		// references a post that has NOT (source post ID 999) — it must block, and the
		// cursor must stop at 1, not jump to 2, or comment_ID 2 would never be retried.
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$this->mock_comments_response( [
			$this->make_source_comment( [ 'comment_ID' => 1, 'comment_post_ID' => 1 ] ),
			$this->make_source_comment( [ 'comment_ID' => 2, 'comment_post_ID' => 999 ] ),
		] );

		$stage     = new CommentSyncStage();
		$more_work = $stage->process( $this->jid );

		$this->assertNotNull( IdMap::get( $this->jid, 'comment', 1 ), 'The resolvable comment must still be imported.' );
		$this->assertNull( IdMap::get( $this->jid, 'comment', 2 ), 'The blocked comment must not be imported.' );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 1, (int) $job->sync_cursor_comments, 'Cursor must stop just before the blocked comment, not skip past it.' );
		$this->assertFalse( $more_work, 'A blocked batch must not self-requeue an immediate continuation — nothing will have changed.' );
	}

	public function test_blocked_comment_is_retried_and_synced_once_its_post_syncs(): void {
		$this->mock_comments_response( [
			$this->make_source_comment( [ 'comment_ID' => 1, 'comment_post_ID' => 42 ] ),
		] );

		$stage = new CommentSyncStage();
		$stage->process( $this->jid );

		$this->assertNull( IdMap::get( $this->jid, 'comment', 1 ) );
		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 0, (int) $job->sync_cursor_comments );

		// The post now syncs (as it would on a subsequent pass's 'posts' stage).
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 42, $dest_post_id );

		// A later pass re-fetches from the same (unmoved) cursor and succeeds.
		$stage->process( $this->jid );

		$dest_comment_id = IdMap::get( $this->jid, 'comment', 1 );
		$this->assertNotNull( $dest_comment_id );
		$this->assertSame( $dest_post_id, (int) get_comment( $dest_comment_id )->comment_post_ID );
	}

	// -------------------------------------------------------------------------
	// Integration: the comment is actually imported via the normal Reader/Importer/IdMap path.
	// -------------------------------------------------------------------------

	public function test_new_comment_is_imported_to_destination(): void {
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$this->mock_comments_response( [
			$this->make_source_comment( [ 'comment_ID' => 7, 'comment_content' => 'Synced comment.' ] ),
		] );

		$stage = new CommentSyncStage();
		$stage->process( $this->jid );

		$dest_id = IdMap::get( $this->jid, 'comment', 7 );
		$this->assertNotNull( $dest_id );
		$this->assertSame( 'Synced comment.', get_comment( $dest_id )->comment_content );
	}

	// -------------------------------------------------------------------------
	// Initial backfill: many pre-existing comments imported, bounded by row-budget/requeue.
	// -------------------------------------------------------------------------

	public function test_initial_backfill_processes_full_batch_and_signals_continuation(): void {
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$comments = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$comments[] = $this->make_source_comment( [ 'comment_ID' => $i ] );
		}
		$this->mock_comments_response( $comments );

		$stage     = new CommentSyncStage();
		$more_work = $stage->process( $this->jid );

		$this->assertTrue( $more_work, 'A full 100-row batch means the backfill likely has more comments beyond this row budget, not literally unbounded in one call.' );
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->assertNotNull( IdMap::get( $this->jid, 'comment', $i ) );
		}
	}

	// -------------------------------------------------------------------------
	// Posts-before-comments ordering (plan U5 Dependencies: "U3 (posts must sync before
	// comments within a pass)"). Exercised through the real SyncDispatcher and the real
	// production stage registrations from Plugin::register_action_hooks() — not stubs — so
	// this specifically confirms the production wiring orders 'posts' ahead of 'comments'.
	// -------------------------------------------------------------------------

	public function test_comment_on_a_post_synced_in_the_same_pass_is_imported_via_sync_dispatcher(): void {
		// Deliberately no pre-existing IdMap 'post' mapping — the post only becomes mapped
		// because PostSyncStage (registered under the 'posts' slot) runs before
		// CommentSyncStage (registered under the 'comments' slot) within the same
		// SyncDispatcher::run_sync_pass() call. See SyncDispatcher::default_stages() for the
		// fixed slot order and Plugin::register_action_hooks() for both registrations.
		$this->mock_posts_response( [ $this->make_source_post( [ 'ID' => 3, 'post_title' => 'Same-pass post' ] ) ] );
		$this->mock_comments_response( [
			$this->make_source_comment( [ 'comment_ID' => 1, 'comment_post_ID' => 3 ] ),
		] );

		SyncDispatcher::run_sync_pass( $this->jid );

		$dest_post_id = IdMap::get( $this->jid, 'post', 3 );
		$this->assertNotNull( $dest_post_id, 'Post must have synced this pass.' );

		$dest_comment_id = IdMap::get( $this->jid, 'comment', 1 );
		$this->assertNotNull( $dest_comment_id, 'Comment on the same-pass post must also have synced — proves posts ran before comments.' );
		$this->assertSame( $dest_post_id, (int) get_comment( $dest_comment_id )->comment_post_ID );
	}
}
