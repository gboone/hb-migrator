<?php
/**
 * Tests for CommentReader::get_comments() — per-blog fetch, ID-cursor pagination, and field
 * shape (U5). Follows test-media-reader.php's WP_REST_Request/make_request() pattern.
 */

use HBMigrator\Source\CommentReader;

class Test_Comment_Reader extends WP_UnitTestCase {

	private int $blog_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->blog_id = get_current_blog_id();
		$this->post_id = self::factory()->post->create();
	}

	private function make_request( array $params = [] ): \WP_REST_Request {
		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/sites/' . $this->blog_id . '/comments' );
		$req->set_param( 'blog_id', $this->blog_id );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function insert_comment( array $overrides = [] ): int {
		$data = array_merge( [
			'comment_post_ID'      => $this->post_id,
			'comment_author'       => 'Jane Doe',
			'comment_author_email' => 'jane@example.com',
			'comment_author_url'   => '',
			'comment_content'      => 'Hello world.',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
			'comment_date'         => '2026-01-01 00:00:00',
			'comment_date_gmt'     => '2026-01-01 00:00:00',
			'comment_approved'     => '1',
		], $overrides );

		return (int) wp_insert_comment( $data );
	}

	// -------------------------------------------------------------------------
	// ID-cursor pagination.
	// -------------------------------------------------------------------------

	public function test_returns_comments_above_last_id(): void {
		$id1 = $this->insert_comment();
		$id2 = $this->insert_comment();

		$response = CommentReader::get_comments( $this->make_request( [ 'last_id' => $id1 ] ) );
		$returned = array_column( $response->get_data(), 'comment_ID' );

		$this->assertNotContains( $id1, $returned, 'A comment at or below last_id must not be returned.' );
		$this->assertContains( $id2, $returned );
	}

	public function test_results_ordered_ascending_by_comment_id(): void {
		$id1 = $this->insert_comment();
		$id2 = $this->insert_comment();
		$id3 = $this->insert_comment();

		$response = CommentReader::get_comments( $this->make_request() );
		$returned = array_column( $response->get_data(), 'comment_ID' );

		$this->assertSame( [ $id1, $id2, $id3 ], $returned );
	}

	public function test_per_page_limits_results(): void {
		$this->insert_comment();
		$this->insert_comment();
		$this->insert_comment();

		$response = CommentReader::get_comments( $this->make_request( [ 'per_page' => 2 ] ) );
		$this->assertCount( 2, $response->get_data() );
	}

	public function test_no_comments_returns_empty_array(): void {
		$response = CommentReader::get_comments( $this->make_request() );
		$this->assertSame( [], $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// Full-fidelity backfill: spam/trash included, not filtered.
	// -------------------------------------------------------------------------

	public function test_includes_spam_and_trash_comments(): void {
		$spam_id  = $this->insert_comment( [ 'comment_approved' => 'spam' ] );
		$trash_id = $this->insert_comment( [ 'comment_approved' => 'trash' ] );

		$response = CommentReader::get_comments( $this->make_request() );
		$returned = array_column( $response->get_data(), 'comment_ID' );

		$this->assertContains( $spam_id, $returned, 'Spam comments must be included for full-fidelity backfill.' );
		$this->assertContains( $trash_id, $returned, 'Trash comments must be included for full-fidelity backfill.' );
	}

	public function test_comment_approved_value_preserved_as_is(): void {
		$id = $this->insert_comment( [ 'comment_approved' => 'spam' ] );

		$response = CommentReader::get_comments( $this->make_request() );
		$row      = current( array_filter( $response->get_data(), fn( $c ) => $c['comment_ID'] === $id ) );

		$this->assertSame( 'spam', $row['comment_approved'] );
	}

	// -------------------------------------------------------------------------
	// comment_type fidelity (pingback/trackback).
	// -------------------------------------------------------------------------

	public function test_pingback_comment_type_preserved(): void {
		$id = $this->insert_comment( [ 'comment_type' => 'pingback', 'comment_content' => 'Pingback from elsewhere.' ] );

		$response = CommentReader::get_comments( $this->make_request() );
		$row      = current( array_filter( $response->get_data(), fn( $c ) => $c['comment_ID'] === $id ) );

		$this->assertNotFalse( $row );
		$this->assertSame( 'pingback', $row['comment_type'] );
	}

	public function test_trackback_comment_type_preserved(): void {
		$id = $this->insert_comment( [ 'comment_type' => 'trackback' ] );

		$response = CommentReader::get_comments( $this->make_request() );
		$row      = current( array_filter( $response->get_data(), fn( $c ) => $c['comment_ID'] === $id ) );

		$this->assertSame( 'trackback', $row['comment_type'] );
	}

	// -------------------------------------------------------------------------
	// Anonymous vs. registered commenter fields.
	// -------------------------------------------------------------------------

	public function test_anonymous_comment_author_triplet_preserved(): void {
		$id = $this->insert_comment( [
			'comment_author'       => 'Anon Guest',
			'comment_author_email' => 'guest@example.com',
			'comment_author_url'   => 'https://example.com',
			'user_id'              => 0,
		] );

		$response = CommentReader::get_comments( $this->make_request() );
		$row      = current( array_filter( $response->get_data(), fn( $c ) => $c['comment_ID'] === $id ) );

		$this->assertSame( 'Anon Guest', $row['comment_author'] );
		$this->assertSame( 'guest@example.com', $row['comment_author_email'] );
		$this->assertSame( 'https://example.com', $row['comment_author_url'] );
		$this->assertSame( 0, $row['user_id'] );
	}

	public function test_registered_user_id_included(): void {
		$user_id = self::factory()->user->create();
		$id      = $this->insert_comment( [ 'user_id' => $user_id ] );

		$response = CommentReader::get_comments( $this->make_request() );
		$row      = current( array_filter( $response->get_data(), fn( $c ) => $c['comment_ID'] === $id ) );

		$this->assertSame( $user_id, $row['user_id'] );
	}

	// -------------------------------------------------------------------------
	// comment_parent threading.
	// -------------------------------------------------------------------------

	public function test_comment_parent_included_and_preserved(): void {
		$parent_id = $this->insert_comment();
		$reply_id  = $this->insert_comment( [ 'comment_parent' => $parent_id ] );

		$response = CommentReader::get_comments( $this->make_request() );
		$row      = current( array_filter( $response->get_data(), fn( $c ) => $c['comment_ID'] === $reply_id ) );

		$this->assertSame( $parent_id, $row['comment_parent'] );
	}

	// -------------------------------------------------------------------------
	// Data shape.
	// -------------------------------------------------------------------------

	public function test_response_includes_expected_fields(): void {
		$this->insert_comment();

		$response = CommentReader::get_comments( $this->make_request() );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data );
		$item = $data[0];
		foreach ( [
			'comment_ID', 'comment_post_ID', 'comment_parent', 'comment_type', 'user_id',
			'comment_approved', 'comment_content', 'comment_date', 'comment_date_gmt',
			'comment_author', 'comment_author_email', 'comment_author_url',
		] as $field ) {
			$this->assertArrayHasKey( $field, $item, "Missing field: $field" );
		}
	}

	public function test_comment_post_id_matches_associated_post(): void {
		$id = $this->insert_comment();

		$response = CommentReader::get_comments( $this->make_request() );
		$row      = current( array_filter( $response->get_data(), fn( $c ) => $c['comment_ID'] === $id ) );

		$this->assertSame( $this->post_id, $row['comment_post_ID'] );
	}
}
