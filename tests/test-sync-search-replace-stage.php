<?php
/**
 * Tests for SyncSearchReplaceStage::process() (U6) — the 'search_replace' slot of a sync
 * pass, the fourth and final stage. Exercises it against real PostSyncStage/MediaSyncStage/
 * CommentSyncStage runs (mocked SourceClient responses) so the in-memory "touched this pass"
 * handoffs (get_synced_post_ids(), get_synced_media_ids(), get_synced_comment_ids()) are
 * populated exactly as SyncDispatcher::run_sync_pass() would produce them in production.
 *
 * Mirrors test-media-sync-stage.php's/test-post-sync-stage.php's conventions: pre_http_request
 * mocks SourceClient responses, MigrationRegistry/QueueTable set up a 'syncing' site job.
 */

use HBMigrator\Destination\CommentSyncStage;
use HBMigrator\Destination\MediaSyncStage;
use HBMigrator\Destination\PostSyncStage;
use HBMigrator\Destination\SyncSearchReplaceStage;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_SyncSearchReplaceStage extends WP_UnitTestCase {

	private int $mid;
	private int $jid;
	private string $source_siteurl = 'https://93.184.216.34';

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();

		$this->mid = MigrationRegistry::create_migration( $this->source_siteurl, 'testkey', null );
		$this->jid = MigrationRegistry::create_site_job(
			$this->mid, 1, 'example.com', $this->source_siteurl, $this->source_siteurl . '/wp-content/uploads/', '/example.com/'
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

	private function dest_siteurl(): string {
		return rtrim( get_option( 'siteurl' ), '/' );
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

	// -------------------------------------------------------------------------
	// Zero-row scoped pass: fast no-op — no earlier stage touched anything this pass.
	// -------------------------------------------------------------------------

	public function test_returns_false_and_is_a_noop_when_no_earlier_stage_touched_anything(): void {
		$existing_post = self::factory()->post->create( [
			'post_content' => 'Should stay untouched: ' . $this->source_siteurl . '/x',
		] );

		$stage = new SyncSearchReplaceStage();
		$this->assertFalse( $stage->process( $this->jid ) );

		$this->assertStringContainsString( $this->source_siteurl, get_post( $existing_post )->post_content );
	}

	public function test_returns_false_for_a_site_job_that_does_not_exist(): void {
		$stage = new SyncSearchReplaceStage();
		$this->assertFalse( $stage->process( 999999 ) );
	}

	// -------------------------------------------------------------------------
	// A newly-synced post's post_content containing the source URL is rewritten, scoped to
	// just that post's row.
	// -------------------------------------------------------------------------

	public function test_newly_synced_post_content_url_is_rewritten_scoped_to_that_post(): void {
		// A pre-existing, unrelated post this pass does NOT touch — must be left alone.
		$pre_existing_post = self::factory()->post->create( [
			'post_content' => 'Old link: ' . $this->source_siteurl . '/pre-existing',
		] );

		$this->mock_response( '/posts', [
			$this->make_source_post( [
				'ID'           => 5,
				'post_content' => 'Read more at ' . $this->source_siteurl . '/about',
			] ),
		] );
		( new PostSyncStage() )->process( $this->jid );
		remove_all_filters( 'pre_http_request' );

		$dest_post_id = IdMap::get( $this->jid, 'post', 5 );
		$this->assertNotNull( $dest_post_id );

		$stage = new SyncSearchReplaceStage();
		$this->assertFalse( $stage->process( $this->jid ), 'A scoped pass always completes in one call — no continuation is ever needed.' );

		$rewritten = get_post( $dest_post_id )->post_content;
		$this->assertStringContainsString( $this->dest_siteurl(), $rewritten );
		$this->assertStringNotContainsString( $this->source_siteurl, $rewritten );

		// The pre-existing post was NOT touched this pass — scoped mode must leave it alone.
		$untouched = get_post( $pre_existing_post )->post_content;
		$this->assertStringContainsString( $this->source_siteurl, $untouched );
	}

	// -------------------------------------------------------------------------
	// A newly-synced post's _thumbnail_id referencing a newly-synced attachment is remapped
	// to the destination attachment ID.
	// -------------------------------------------------------------------------

	public function test_newly_synced_post_thumbnail_id_is_remapped_to_new_attachment(): void {
		global $wpdb;
		if ( $wpdb instanceof \WP_SQLite_DB ) {
			$this->markTestSkipped( 'remap_postmeta_ids_scoped() uses MySQL-only UPDATE...JOIN syntax — no SQLite equivalent.' );
		}

		$this->mock_response( '/posts', [
			$this->make_source_post( [ 'ID' => 6, 'post_content' => 'No URL here.' ] ),
		] );
		( new PostSyncStage() )->process( $this->jid );
		remove_all_filters( 'pre_http_request' );

		$dest_post_id = IdMap::get( $this->jid, 'post', 6 );
		$this->assertNotNull( $dest_post_id );
		add_post_meta( $dest_post_id, '_thumbnail_id', '999' );

		// MediaSyncStage must report attachment 999 as "touched this pass" for
		// SyncSearchReplaceStage to include the post in its scoped remap set — its own
		// static handoff (get_synced_media_ids()) captures every fetched source attachment
		// ID regardless of whether MediaImporter itself succeeded importing it (mirrors
		// PostSyncStage's own "capture what was fetched" contract — see class docblocks).
		$this->mock_response( '/media', [
			$this->make_source_media( [ 'source_attachment_id' => 999, 'file_url' => '' ] ),
		] );
		( new MediaSyncStage() )->process( $this->jid );
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( [ 999 ], MediaSyncStage::get_synced_media_ids( $this->jid ) );

		// Simulates a real import having mapped attachment 999 -> destination attachment
		// 4242 this same pass (a real pass with a resolvable file_url would have set this
		// itself via MediaImporter::import_batch() — see test-media-importer.php).
		IdMap::set( $this->jid, 'attachment', 999, 4242 );

		$stage = new SyncSearchReplaceStage();
		$stage->process( $this->jid );

		$this->assertSame( '4242', get_post_meta( $dest_post_id, '_thumbnail_id', true ) );
	}

	// -------------------------------------------------------------------------
	// Rows NOT touched by the current pass are left untouched by the scoped mode — even when
	// their attachment IS mapped in IdMap, proving the filter is on the touched-row ID set
	// this pass produced, not merely on IdMap presence.
	// -------------------------------------------------------------------------

	public function test_untouched_posts_thumbnail_id_is_left_alone_by_scoped_pass(): void {
		global $wpdb;
		if ( $wpdb instanceof \WP_SQLite_DB ) {
			$this->markTestSkipped( 'remap_postmeta_ids_scoped() uses MySQL-only UPDATE...JOIN syntax — no SQLite equivalent.' );
		}

		$touched_post = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 100, $touched_post );
		IdMap::set( $this->jid, 'attachment', 1, 111 );

		$untouched_post = self::factory()->post->create();
		add_post_meta( $untouched_post, '_thumbnail_id', '2' );
		IdMap::set( $this->jid, 'attachment', 2, 222 );

		// Only source post 100 (already IdMap-mapped to $touched_post) is fetched this pass —
		// PostImporter's existing-row branch updates that same destination row in place,
		// including a full postmeta re-sync from the payload's meta array (see
		// PostImporter::import_batch()'s existing-row branch), so _thumbnail_id must travel
		// in that payload rather than being added directly via add_post_meta() beforehand.
		$this->mock_response( '/posts', [
			$this->make_source_post( [
				'ID'           => 100,
				'post_content' => 'Updated content.',
				'meta'         => [ [ 'key' => '_thumbnail_id', 'value' => '1' ] ],
			] ),
		] );
		( new PostSyncStage() )->process( $this->jid );
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( [ 100 ], PostSyncStage::get_synced_post_ids( $this->jid ) );

		$stage = new SyncSearchReplaceStage();
		$stage->process( $this->jid );

		$this->assertSame( '111', get_post_meta( $touched_post, '_thumbnail_id', true ), 'The touched post must still be remapped.' );
		$this->assertSame( '2', get_post_meta( $untouched_post, '_thumbnail_id', true ), 'A post NOT touched this pass must be left exactly as-is, even though its attachment IS mapped in IdMap.' );
	}

	// -------------------------------------------------------------------------
	// Comment content — scoped to the comment IDs CommentSyncStage touched this pass.
	// -------------------------------------------------------------------------

	public function test_newly_synced_comment_content_url_is_rewritten_scoped_to_that_comment(): void {
		$dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', 1, $dest_post_id );

		$this->mock_response( '/comments', [
			$this->make_source_comment( [ 'comment_ID' => 1, 'comment_content' => 'See ' . $this->source_siteurl . '/thread' ] ),
		] );
		( new CommentSyncStage() )->process( $this->jid );
		remove_all_filters( 'pre_http_request' );

		$dest_comment_id = IdMap::get( $this->jid, 'comment', 1 );
		$this->assertNotNull( $dest_comment_id );

		$stage = new SyncSearchReplaceStage();
		$stage->process( $this->jid );

		$content = get_comment( $dest_comment_id )->comment_content;
		$this->assertStringContainsString( $this->dest_siteurl(), $content );
		$this->assertStringNotContainsString( $this->source_siteurl, $content );
	}
}
