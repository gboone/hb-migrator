<?php
/**
 * Tests for CommentImporter::process() (U5) — the resolve-then-upsert order from the plan's
 * pseudocode: comment_post_ID via the post IdMap, comment_parent via a new 'comment' IdMap
 * object_type, user_id via the existing 'user' IdMap object_type, THEN the idempotent-retry
 * check (IdMap::get(site_job_id, 'comment', comment_ID)) that decides insert vs. update.
 */

use HBMigrator\Destination\CommentImporter;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_CommentImporter extends WP_UnitTestCase {

	private int $mid;
	private int $jid;
	private int $dest_post_id;
	private int $source_post_id = 55;

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();

		$this->mid = MigrationRegistry::create_migration( 'https://93.184.216.34', 'testkey', null );
		$this->jid = MigrationRegistry::create_site_job(
			$this->mid, 1, 'example.com', 'https://93.184.216.34', '', '/example.com/'
		);
		MigrationRegistry::update_migration_status( $this->mid, 'running' );
		MigrationRegistry::update_site_job( $this->jid, [ 'dest_blog_id' => get_current_blog_id() ] );

		$this->dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', $this->source_post_id, $this->dest_post_id );
	}

	private function make_source_comment( array $overrides = [] ): array {
		return array_merge( [
			'comment_ID'           => 1,
			'comment_post_ID'      => $this->source_post_id,
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
	// New top-level comment.
	// -------------------------------------------------------------------------

	public function test_new_top_level_comment_imported_and_mapped_to_correct_post(): void {
		$comment = $this->make_source_comment( [ 'comment_ID' => 101 ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 101 );
		$this->assertNotNull( $dest_id );

		$dest_comment = get_comment( $dest_id );
		$this->assertNotNull( $dest_comment );
		$this->assertSame( $this->dest_post_id, (int) $dest_comment->comment_post_ID );
	}

	// -------------------------------------------------------------------------
	// Reply / parent remap.
	// -------------------------------------------------------------------------

	public function test_reply_comment_parent_remapped_when_parent_already_synced(): void {
		$parent = $this->make_source_comment( [ 'comment_ID' => 10 ] );
		CommentImporter::process( $this->jid, [ $parent ] );
		$dest_parent_id = IdMap::get( $this->jid, 'comment', 10 );
		$this->assertNotNull( $dest_parent_id );

		$reply = $this->make_source_comment( [ 'comment_ID' => 11, 'comment_parent' => 10 ] );
		CommentImporter::process( $this->jid, [ $reply ] );

		$dest_reply_id = IdMap::get( $this->jid, 'comment', 11 );
		$this->assertNotNull( $dest_reply_id );
		$this->assertSame( $dest_parent_id, (int) get_comment( $dest_reply_id )->comment_parent );
	}

	public function test_reply_comment_skipped_when_parent_not_yet_synced(): void {
		// Parent (source comment_ID 50) has NOT been synced/mapped yet.
		$reply = $this->make_source_comment( [ 'comment_ID' => 20, 'comment_parent' => 50 ] );
		CommentImporter::process( $this->jid, [ $reply ] );

		$this->assertNull( IdMap::get( $this->jid, 'comment', 20 ), 'A reply must not be inserted while its parent is unmapped.' );
	}

	public function test_reply_skipped_even_when_parent_comment_id_is_higher_than_reply(): void {
		// The parent's source comment_ID (99) is HIGHER than the reply's own (30) —
		// comment_ID ascending order must never be assumed as a stand-in for the IdMap
		// mapping check (wp_insert_comment() performs no such validation in WordPress core).
		$reply = $this->make_source_comment( [ 'comment_ID' => 30, 'comment_parent' => 99 ] );
		CommentImporter::process( $this->jid, [ $reply ] );

		$this->assertNull( IdMap::get( $this->jid, 'comment', 30 ) );

		// The "later" (higher-ID) parent syncs...
		$parent = $this->make_source_comment( [ 'comment_ID' => 99 ] );
		CommentImporter::process( $this->jid, [ $parent ] );
		$dest_parent_id = IdMap::get( $this->jid, 'comment', 99 );
		$this->assertNotNull( $dest_parent_id );

		// ...and only now does a retry of the reply succeed, with the correct parent.
		CommentImporter::process( $this->jid, [ $reply ] );
		$dest_reply_id = IdMap::get( $this->jid, 'comment', 30 );
		$this->assertNotNull( $dest_reply_id );
		$this->assertSame( $dest_parent_id, (int) get_comment( $dest_reply_id )->comment_parent );
	}

	// -------------------------------------------------------------------------
	// Post not yet synced.
	// -------------------------------------------------------------------------

	public function test_comment_on_unsynced_post_skipped_then_imported_after_post_syncs(): void {
		$unsynced_source_post_id = 777;
		$comment = $this->make_source_comment( [ 'comment_ID' => 40, 'comment_post_ID' => $unsynced_source_post_id ] );

		CommentImporter::process( $this->jid, [ $comment ] );
		$this->assertNull( IdMap::get( $this->jid, 'comment', 40 ), 'A comment on an unmapped post must be skipped, not dropped.' );

		// The post now syncs.
		$new_dest_post_id = self::factory()->post->create();
		IdMap::set( $this->jid, 'post', $unsynced_source_post_id, $new_dest_post_id );

		// ...and the same comment succeeds on retry.
		CommentImporter::process( $this->jid, [ $comment ] );
		$dest_comment_id = IdMap::get( $this->jid, 'comment', 40 );
		$this->assertNotNull( $dest_comment_id );
		$this->assertSame( $new_dest_post_id, (int) get_comment( $dest_comment_id )->comment_post_ID );
	}

	// -------------------------------------------------------------------------
	// User remap.
	// -------------------------------------------------------------------------

	public function test_registered_and_mapped_user_id_remapped(): void {
		$dest_user_id = self::factory()->user->create();
		IdMap::set( IdMap::NETWORK, 'user', 555, $dest_user_id );

		$comment = $this->make_source_comment( [ 'comment_ID' => 60, 'user_id' => 555 ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 60 );
		$this->assertSame( $dest_user_id, (int) get_comment( $dest_id )->user_id );
	}

	public function test_registered_but_unmapped_user_falls_back_to_anonymous_not_error(): void {
		$comment = $this->make_source_comment( [ 'comment_ID' => 61, 'user_id' => 999999 ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 61 );
		$this->assertNotNull( $dest_id, 'An unmapped user must fall back to anonymous, not fail the import.' );
		$this->assertSame( 0, (int) get_comment( $dest_id )->user_id );
	}

	public function test_anonymous_comment_author_triplet_preserved(): void {
		$comment = $this->make_source_comment( [
			'comment_ID'           => 62,
			'user_id'              => 0,
			'comment_author'       => 'Guest Person',
			'comment_author_email' => 'guest@example.com',
			'comment_author_url'   => 'https://guest.example.com',
		] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id      = IdMap::get( $this->jid, 'comment', 62 );
		$dest_comment = get_comment( $dest_id );

		$this->assertSame( 'Guest Person', $dest_comment->comment_author );
		$this->assertSame( 'guest@example.com', $dest_comment->comment_author_email );
		$this->assertSame( 'https://guest.example.com', $dest_comment->comment_author_url );
	}

	// -------------------------------------------------------------------------
	// Moderation status / comment_type fidelity.
	// -------------------------------------------------------------------------

	public function test_spam_moderation_status_preserved(): void {
		$comment = $this->make_source_comment( [ 'comment_ID' => 70, 'comment_approved' => 'spam' ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 70 );
		$this->assertSame( 'spam', get_comment( $dest_id )->comment_approved );
	}

	public function test_trash_moderation_status_preserved(): void {
		$comment = $this->make_source_comment( [ 'comment_ID' => 71, 'comment_approved' => 'trash' ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 71 );
		$this->assertSame( 'trash', get_comment( $dest_id )->comment_approved );
	}

	public function test_pingback_comment_type_preserved(): void {
		$comment = $this->make_source_comment( [ 'comment_ID' => 72, 'comment_type' => 'pingback' ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 72 );
		$this->assertSame( 'pingback', get_comment( $dest_id )->comment_type );
	}

	public function test_trackback_comment_type_preserved(): void {
		$comment = $this->make_source_comment( [ 'comment_ID' => 73, 'comment_type' => 'trackback' ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 73 );
		$this->assertSame( 'trackback', get_comment( $dest_id )->comment_type );
	}

	// -------------------------------------------------------------------------
	// Idempotent-retry: update in place, not duplicate.
	// -------------------------------------------------------------------------

	public function test_already_mapped_comment_is_updated_not_duplicated_on_retry(): void {
		$comment = $this->make_source_comment( [ 'comment_ID' => 80, 'comment_content' => 'Original.' ] );
		CommentImporter::process( $this->jid, [ $comment ] );

		$dest_id = IdMap::get( $this->jid, 'comment', 80 );
		$this->assertNotNull( $dest_id );

		$edited = $this->make_source_comment( [
			'comment_ID'      => 80,
			'comment_content' => 'Edited after a later-stage failure.',
		] );
		CommentImporter::process( $this->jid, [ $edited ] );

		$this->assertSame(
			$dest_id,
			IdMap::get( $this->jid, 'comment', 80 ),
			'Retry must update the same destination comment, not insert a new one.'
		);
		$this->assertSame( 'Edited after a later-stage failure.', get_comment( $dest_id )->comment_content );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d",
			$this->dest_post_id
		) );
		$this->assertSame( 1, $count, 'Retry must not create a duplicate comment.' );
	}

	public function test_comment_date_gmt_preserved_exactly_on_update_not_recomputed(): void {
		// wp_update_comment() unconditionally recomputes comment_date_gmt from comment_date
		// via get_gmt_from_date() — this test guards CommentImporter's defensive
		// $wpdb->update() fix for that WordPress core behavior. The GMT value below is
		// deliberately different from what get_gmt_from_date() would compute from
		// comment_date under the test suite's default (UTC, zero-offset) timezone, so the
		// assertion only passes if the fix is actually applied.
		$comment = $this->make_source_comment( [
			'comment_ID'       => 81,
			'comment_date'     => '2020-06-15 08:00:00',
			'comment_date_gmt' => '2020-06-15 12:00:00',
		] );
		CommentImporter::process( $this->jid, [ $comment ] );
		$dest_id = IdMap::get( $this->jid, 'comment', 81 );
		$this->assertSame( '2020-06-15 12:00:00', get_comment( $dest_id )->comment_date_gmt, 'Insert path must also preserve the exact value.' );

		$edited = $this->make_source_comment( [
			'comment_ID'       => 81,
			'comment_date'     => '2020-06-15 08:00:00',
			'comment_date_gmt' => '2020-06-15 12:00:00',
			'comment_content'  => 'Edited.',
		] );
		CommentImporter::process( $this->jid, [ $edited ] );

		$this->assertSame( '2020-06-15 12:00:00', get_comment( $dest_id )->comment_date_gmt );
	}
}
