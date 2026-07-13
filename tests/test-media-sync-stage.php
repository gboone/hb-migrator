<?php
/**
 * Tests for MediaSyncStage::process() (U4) — the 'media' slot of a sync pass.
 *
 * Mirrors test-post-sync-stage.php's (U3) conventions: pre_http_request mocks SourceClient
 * responses, MigrationRegistry/QueueTable set up a 'syncing' site job. Also covers the
 * parent_ids handoff from PostSyncStage::get_synced_post_ids() — the mechanism by which an
 * attachment whose parent post U3 just synced is included even without its own change.
 */

use HBMigrator\Destination\MediaSyncStage;
use HBMigrator\Destination\PostSyncStage;
use HBMigrator\Destination\SyncDispatcher;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_MediaSyncStage extends WP_UnitTestCase {

	private int $mid;
	private int $jid;

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();

		$this->mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'testkey', null );
		$this->jid = MigrationRegistry::create_site_job(
			$this->mid, 1, 'example.com', 'https://93.184.216.34', 'https://93.184.216.34/wp-content/uploads/', '/example.com/'
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

	private function mock_media_response( array $media ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $media ) {
			if ( false !== strpos( $url, '/media' ) ) {
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => wp_json_encode( $media ),
					'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
					'cookies'  => [],
					'filename' => null,
				];
			}
			return $preempt;
		}, 10, 3 );
	}

	private function mock_media_error_response(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/media' ) ) {
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

	private function make_source_media( array $overrides = [] ): array {
		return array_merge( [
			'source_attachment_id'  => 1,
			'post_title'            => 'Test Media',
			'post_date'             => '2024-01-01 00:00:00',
			'post_date_gmt'         => '2024-01-01 00:00:00',
			'post_modified'         => '2024-01-01 00:00:00',
			'post_mime_type'        => 'image/jpeg',
			'post_parent_source_id' => 0,
			'post_name'             => 'test-media',
			'alt_text'              => '',
			'caption'               => '',
			'description'           => '',
			'file_url'              => '', // no file_url => permanent skip, no download attempted.
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

	public function test_returns_false_when_no_media_are_returned(): void {
		$this->mock_media_response( [] );

		$stage = new MediaSyncStage();
		$this->assertFalse( $stage->process( $this->jid ), 'No matching media means already caught up.' );
	}

	public function test_returns_false_when_batch_is_under_row_budget(): void {
		$media = [ $this->make_source_media( [ 'source_attachment_id' => 1 ] ), $this->make_source_media( [ 'source_attachment_id' => 2 ] ) ];
		$this->mock_media_response( $media );

		$stage = new MediaSyncStage();
		$this->assertFalse( $stage->process( $this->jid ), 'A batch under the row budget means the stage is caught up.' );
	}

	public function test_returns_true_when_batch_hits_row_budget(): void {
		$media = [];
		for ( $i = 1; $i <= 50; $i++ ) {
			$media[] = $this->make_source_media( [ 'source_attachment_id' => $i, 'post_name' => 'media-' . $i ] );
		}
		$this->mock_media_response( $media );

		$stage = new MediaSyncStage();
		$this->assertTrue( $stage->process( $this->jid ), 'A full 50-row batch means more work likely remains (MediaImporter\'s own convention, not PostImporter\'s 100).' );
	}

	// -------------------------------------------------------------------------
	// Cursor advance.
	// -------------------------------------------------------------------------

	public function test_cursor_advances_to_max_modified_seen_on_success(): void {
		$media = [
			$this->make_source_media( [ 'source_attachment_id' => 1, 'post_modified' => '2026-01-01 10:00:00' ] ),
			$this->make_source_media( [ 'source_attachment_id' => 2, 'post_modified' => '2026-01-02 12:30:00' ] ),
		];
		$this->mock_media_response( $media );

		$stage = new MediaSyncStage();
		$stage->process( $this->jid );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( '2026-01-02 12:30:00', $job->sync_cursor_media );
	}

	public function test_cursor_is_untouched_when_no_media_are_returned(): void {
		$this->mock_media_response( [] );

		$stage = new MediaSyncStage();
		$stage->process( $this->jid );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNull( $job->sync_cursor_media );
	}

	public function test_cursor_does_not_advance_when_stage_throws(): void {
		$this->mock_media_error_response();

		$stage = new MediaSyncStage();

		try {
			$stage->process( $this->jid );
			$this->fail( 'Expected a SourceClientException to propagate.' );
		} catch ( \HBMigrator\SourceClientException $e ) {
			// Expected — SyncDispatcher is the one that catches this in production.
		}

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNull( $job->sync_cursor_media, 'A failed pass must leave the cursor untouched.' );
	}

	public function test_first_pass_with_null_cursor_still_imports_and_advances(): void {
		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNull( $job->sync_cursor_media );

		$media = [ $this->make_source_media( [ 'source_attachment_id' => 1, 'post_modified' => '2026-01-01 00:00:00', 'file_url' => '' ] ) ];
		$this->mock_media_response( $media );

		$stage = new MediaSyncStage();
		$stage->process( $this->jid );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( '2026-01-01 00:00:00', $job->sync_cursor_media );
	}

	// -------------------------------------------------------------------------
	// Cursor-skip regression coverage (code review P0/P1): a per-item import failure
	// (download/sideload) must never let the cursor advance past it, even when a later item
	// in the same fetch batch succeeds. Mirrors test-media-importer.php's
	// mock_download_failure()/mock_successful_png_download() conventions, combined into one
	// filter keyed by filename so one item in the batch fails and the other succeeds.
	// -------------------------------------------------------------------------

	private function mock_media_download_partial_failure( string $fail_url_needle ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $fail_url_needle ) {
			if ( false === strpos( $url, '/wp-content/uploads/' ) ) {
				return $preempt;
			}
			if ( false !== strpos( $url, $fail_url_needle ) ) {
				// download_url() calls wp_remote_get() on the file URL — make it fail.
				return new \WP_Error( 'http_request_failed', 'Connection timed out.' );
			}
			// Minimal 1x1 transparent PNG (67 bytes), written to the temp file WordPress
			// pre-creates, so wp_handle_sideload()/wp_insert_attachment() succeed normally.
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], base64_decode(
					'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
				) );
			}
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => '',
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => $args['filename'] ?? null,
			];
		}, 20, 3 ); // priority 20 so it runs after mock_media_response() (priority 10).
	}

	public function test_cursor_does_not_advance_past_earlier_failed_item_when_later_item_succeeds(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		$failing = $this->make_source_media( [
			'source_attachment_id' => 1,
			'post_name'            => 'fail-item',
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/fail-download.jpg',
			'post_modified'        => '2026-01-01 09:00:00',
		] );
		$succeeding = $this->make_source_media( [
			'source_attachment_id' => 2,
			'post_name'            => 'succeed-item',
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/succeeds.png',
			'post_modified'        => '2026-01-02 10:00:00',
		] );
		$this->mock_media_response( [ $failing, $succeeding ] );
		$this->mock_media_download_partial_failure( 'fail-download.jpg' );

		$stage  = new MediaSyncStage();
		$result = $stage->process( $this->jid );

		$this->assertTrue(
			$result,
			'A pass with a transient download failure must signal more work remains (retry), not "caught up".'
		);

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNull(
			$job->sync_cursor_media,
			'Cursor must not advance past the earlier failed item even though the later item in the same batch succeeded — advancing here would permanently skip retrying attachment ID 1.'
		);

		$this->assertNull( IdMap::get( $this->jid, 'attachment', 1 ), 'The failed download must not be mapped.' );
		$this->assertNotNull( IdMap::get( $this->jid, 'attachment', 2 ), 'The later, non-failing attachment must still have been imported.' );
	}

	public function test_cursor_advances_normally_when_every_item_in_the_batch_succeeds(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		// No-regression check: with no failures, cursor behavior is unchanged from before
		// this fix — it advances to the max post_modified seen, and process() reports no
		// more work for an under-budget batch.
		$item_a = $this->make_source_media( [
			'source_attachment_id' => 3,
			'post_name'            => 'ok-item-a',
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/ok-a.png',
			'post_modified'        => '2026-02-01 08:00:00',
		] );
		$item_b = $this->make_source_media( [
			'source_attachment_id' => 4,
			'post_name'            => 'ok-item-b',
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/ok-b.png',
			'post_modified'        => '2026-02-02 09:30:00',
		] );
		$this->mock_media_response( [ $item_a, $item_b ] );
		$this->mock_media_download_partial_failure( 'no-such-file-fails' ); // nothing matches — both succeed.

		$stage  = new MediaSyncStage();
		$result = $stage->process( $this->jid );

		$this->assertFalse( $result, 'An under-budget batch with no failures means already caught up.' );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( '2026-02-02 09:30:00', $job->sync_cursor_media );

		$this->assertNotNull( IdMap::get( $this->jid, 'attachment', 3 ) );
		$this->assertNotNull( IdMap::get( $this->jid, 'attachment', 4 ) );
	}

	// -------------------------------------------------------------------------
	// Integration: a new attachment is actually imported via the normal Reader/Importer/
	// IdMap path (reusing MediaImporter::import_batch(), including conflict-policy logic).
	// -------------------------------------------------------------------------

	public function test_new_attachment_with_file_url_is_attempted_and_recorded_on_failure_path(): void {
		// No mock download success wiring here — the file_url points at a fake host that
		// will fail the SSRF allowed-origin check (job's source_upload_url host), so this
		// verifies the sync stage reaches MediaImporter::import_batch() (permanent skip,
		// no IdMap entry, no exception) rather than exercising the full download pipeline
		// (covered by test-media-importer.php).
		$media = [ $this->make_source_media( [
			'source_attachment_id' => 42,
			'file_url'             => 'https://different-host.invalid/photo.jpg',
		] ) ];
		$this->mock_media_response( $media );

		$stage = new MediaSyncStage();
		$stage->process( $this->jid );

		$this->assertNull( IdMap::get( $this->jid, 'attachment', 42 ), 'SSRF-guarded file_url must be permanently skipped, not error out the stage.' );
	}

	public function test_existing_conflict_policy_is_unaffected_for_first_time_sync_pass_import(): void {
		// R5/U4 test scenario: skip_duplicates must behave identically whether the batch
		// came from the initial-migration pipeline or a sync pass.
		$existing_att_id = wp_insert_post( [
			'post_type'   => 'attachment',
			'post_name'   => 'sync-pass-dup',
			'post_status' => 'inherit',
			'post_title'  => 'Sync Pass Dup',
		] );

		// This test's migration was already created in set_up() — switch its policy directly.
		MigrationRegistry::update_migration( $this->mid, [ 'media_conflict_policy' => 'skip_duplicates' ] );

		$media = [ $this->make_source_media( [
			'source_attachment_id' => 43,
			'post_name'            => 'sync-pass-dup',
			'file_url'             => 'https://93.184.216.34/wp-content/uploads/sync-pass-dup.jpg',
		] ) ];
		$this->mock_media_response( $media );

		$stage = new MediaSyncStage();
		$stage->process( $this->jid );

		$this->assertSame( $existing_att_id, IdMap::get( $this->jid, 'attachment', 43 ), 'skip_duplicates must be honored identically during a sync pass.' );

		wp_delete_post( $existing_att_id, true );
	}

	// -------------------------------------------------------------------------
	// U4: parent_ids handoff from PostSyncStage::get_synced_post_ids().
	// -------------------------------------------------------------------------

	public function test_parent_ids_param_sourced_from_post_sync_stage_touched_ids(): void {
		// Run PostSyncStage first (mirrors SyncDispatcher's posts-before-media order),
		// populating its in-memory "touched this pass" registry for this site job.
		$this->mock_posts_response( [ $this->make_source_post( [ 'ID' => 77 ] ) ] );
		( new PostSyncStage() )->process( $this->jid );
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( [ 77 ], PostSyncStage::get_synced_post_ids( $this->jid ) );

		$captured_parent_ids = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_parent_ids ) {
			if ( false !== strpos( $url, '/media' ) ) {
				$parsed = wp_parse_url( $url, PHP_URL_QUERY );
				parse_str( $parsed ?: '', $params );
				$captured_parent_ids = $params['parent_ids'] ?? [];
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

		( new MediaSyncStage() )->process( $this->jid );

		$this->assertNotNull( $captured_parent_ids, 'MediaSyncStage must send a parent_ids param.' );
		$this->assertContains( '77', (array) $captured_parent_ids, 'parent_ids must include the source post ID PostSyncStage just synced.' );
	}

	public function test_parent_ids_param_is_empty_when_post_sync_stage_has_not_run(): void {
		// A fresh site job for which PostSyncStage::process() has never run in this process
		// — get_synced_post_ids() must default to empty, not error or leak another job's IDs.
		$other_jid = MigrationRegistry::create_site_job(
			$this->mid, 2, 'example2.com', 'https://93.184.216.34', 'https://93.184.216.34/wp-content/uploads/', '/example2.com/'
		);
		MigrationRegistry::update_site_job( $other_jid, [ 'status' => 'complete', 'dest_blog_id' => get_current_blog_id() ] );
		MigrationRegistry::enable_site_job_sync( $other_jid );

		$this->assertSame( [], PostSyncStage::get_synced_post_ids( $other_jid ) );

		$captured_parent_ids = 'not-set';
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_parent_ids ) {
			if ( false !== strpos( $url, '/media' ) ) {
				$parsed = wp_parse_url( $url, PHP_URL_QUERY );
				parse_str( $parsed ?: '', $params );
				$captured_parent_ids = $params['parent_ids'] ?? [];
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

		( new MediaSyncStage() )->process( $other_jid );

		$this->assertEmpty( $captured_parent_ids, 'parent_ids must be empty when PostSyncStage has not run for this site job.' );
	}

	// -------------------------------------------------------------------------
	// End-to-end cross-stage handoff through the REAL SyncDispatcher::run_sync_pass()
	// (testing-review gap): the tests above call PostSyncStage/MediaSyncStage directly, in
	// manually-chosen order — they never prove the production `hbm_sync_stages` wiring
	// (Plugin::register_action_hooks()) actually runs 'posts' before 'media' within one real
	// dispatched pass, nor that MediaImporter's post_parent resolution sees IdMap state a
	// same-pass PostSyncStage run just wrote. Mirrors test-comment-sync-stage.php's
	// test_comment_on_a_post_synced_in_the_same_pass_is_imported_via_sync_dispatcher() 1:1,
	// with the media-specific twist that the mocked '/media' response is itself gated on the
	// `parent_ids` request param, so this also proves MediaSyncStage actually sent
	// PostSyncStage::get_synced_post_ids() through to the request — not just that IdMap
	// happened to already contain the mapping.
	// -------------------------------------------------------------------------

	public function test_attachment_whose_parent_post_synced_in_the_same_pass_is_picked_up_via_parent_ids_handoff_through_sync_dispatcher(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'MediaImporter requires a destination blog_id.' );
		}

		// Deliberately no pre-existing IdMap 'post' mapping for source post 88 — it only
		// becomes mapped because PostSyncStage (registered under the 'posts' slot) runs
		// before MediaSyncStage (registered under the 'media' slot) within the same
		// SyncDispatcher::run_sync_pass() call. See SyncDispatcher::default_stages() for the
		// fixed slot order and Plugin::register_action_hooks() for both registrations.
		$this->mock_posts_response( [ $this->make_source_post( [ 'ID' => 88, 'post_title' => 'Same-pass parent post' ] ) ] );

		$attachment = $this->make_source_media( [
			'source_attachment_id'  => 200,
			'post_name'             => 'same-pass-attachment',
			'post_parent_source_id' => 88,
			'file_url'              => 'https://93.184.216.34/wp-content/uploads/same-pass.png',
			'post_modified'         => '2026-01-01 00:00:00',
		] );

		// Unlike mock_media_response(), this only returns the attachment when the request's
		// own `parent_ids` param includes source post 88 — so this test fails if
		// MediaSyncStage doesn't actually forward PostSyncStage::get_synced_post_ids() into
		// its SourceClient::get() call, not just if IdMap resolution is broken.
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $attachment ) {
			if ( false === strpos( $url, '/media' ) ) {
				return $preempt;
			}
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( $query ?: '', $params );
			$parent_ids = array_map( 'strval', (array) ( $params['parent_ids'] ?? [] ) );
			$items      = in_array( '88', $parent_ids, true ) ? [ $attachment ] : [];
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => wp_json_encode( $items ),
				'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );

		// Reuses this file's own successful-download mock (needle matches nothing in this
		// attachment's file_url, so the download/sideload succeeds) — same technique
		// test_cursor_advances_normally_when_every_item_in_the_batch_succeeds() already uses.
		$this->mock_media_download_partial_failure( 'no-such-file-fails' );

		SyncDispatcher::run_sync_pass( $this->jid );

		$dest_post_id = IdMap::get( $this->jid, 'post', 88 );
		$this->assertNotNull( $dest_post_id, 'Post must have synced this pass.' );

		$dest_att_id = IdMap::get( $this->jid, 'attachment', 200 );
		$this->assertNotNull(
			$dest_att_id,
			'The attachment must have been picked up via the parent_ids handoff — the mocked source only returns it when parent_ids includes the just-synced post, proving PostSyncStage ran before MediaSyncStage within the real dispatched pass.'
		);
		$this->assertSame(
			$dest_post_id,
			(int) get_post( $dest_att_id )->post_parent,
			'The attachment must be parented to the correct destination post, resolved via the IdMap mapping PostSyncStage wrote earlier in the same pass.'
		);
	}

	// -------------------------------------------------------------------------
	// R7: deletions on source are never propagated (nothing to assert beyond absence).
	// -------------------------------------------------------------------------

	public function test_attachment_absent_from_a_later_batch_is_left_untouched_on_destination(): void {
		// Simulates an attachment deleted on source between passes: it simply no longer
		// appears in the response. import_batch() must not touch destination for it.
		$this->mock_media_response( [ $this->make_source_media( [ 'source_attachment_id' => 55, 'file_url' => '' ] ) ] );
		( new MediaSyncStage() )->process( $this->jid );

		// No IdMap entry (no file_url — permanent skip), nothing on destination to disturb.
		$this->assertNull( IdMap::get( $this->jid, 'attachment', 55 ) );

		remove_all_filters( 'pre_http_request' );
		$this->mock_media_response( [] ); // attachment 55 "deleted" on source — absent now.
		( new MediaSyncStage() )->process( $this->jid );

		$this->assertNull( IdMap::get( $this->jid, 'attachment', 55 ), 'A later pass missing a previously-seen attachment must not error or fabricate an entry.' );
	}
}