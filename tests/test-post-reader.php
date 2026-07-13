<?php
/**
 * Tests for PostReader::get_posts() — the existing last_id keyset-cursor mode (initial
 * migration) and U3's new modified_since delta-cursor mode (ongoing sync passes).
 */

use HBMigrator\Source\PostReader;

class Test_Post_Reader extends WP_UnitTestCase {

	private int $blog_id;

	public function set_up(): void {
		parent::set_up();
		$this->blog_id = get_current_blog_id();
	}

	private function make_request( array $params = [] ): \WP_REST_Request {
		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/sites/' . $this->blog_id . '/posts' );
		$req->set_param( 'blog_id', $this->blog_id );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function insert_post( array $overrides = [] ): int {
		$id = wp_insert_post( array_merge( [
			'post_title'   => 'Test Post',
			'post_content' => 'Hello world.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		], $overrides ) );
		$this->assertIsInt( $id );
		return $id;
	}

	private function set_post_modified( int $post_id, string $mysql_datetime ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_modified' => $mysql_datetime, 'post_modified_gmt' => $mysql_datetime ],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );
	}

	private function ids_from( \WP_REST_Response $response ): array {
		return array_map( fn( $p ) => (int) $p['ID'], $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// Existing last_id keyset-cursor mode (initial migration) — unchanged behavior.
	// -------------------------------------------------------------------------

	public function test_last_id_mode_returns_posts_with_higher_id_only(): void {
		$id1 = $this->insert_post();
		$id2 = $this->insert_post();

		$response = PostReader::get_posts( $this->make_request( [ 'last_id' => $id1 ] ) );
		$ids      = $this->ids_from( $response );

		$this->assertNotContains( $id1, $ids );
		$this->assertContains( $id2, $ids );
	}

	public function test_last_id_mode_excludes_attachments(): void {
		$attachment_id = wp_insert_attachment( [
			'post_title'     => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		] );

		$response = PostReader::get_posts( $this->make_request() );
		$ids      = $this->ids_from( $response );

		$this->assertNotContains( $attachment_id, $ids );
	}

	// -------------------------------------------------------------------------
	// Delta-cursor mode (U3 sync passes).
	// -------------------------------------------------------------------------

	public function test_new_post_since_cursor_is_returned(): void {
		$cursor = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$new_id = $this->insert_post();
		$this->set_post_modified( $new_id, gmdate( 'Y-m-d H:i:s' ) );

		$response = PostReader::get_posts( $this->make_request( [ 'modified_since' => $cursor ] ) );
		$ids      = $this->ids_from( $response );

		$this->assertContains( $new_id, $ids, 'A post modified after the cursor must be returned.' );
	}

	public function test_edited_post_within_overlap_window_before_cursor_is_returned(): void {
		// The post was modified 5 minutes before the stored cursor — inside the >=60s
		// (in practice 15-minute-assumed) safety overlap — so it must still be returned,
		// not treated as already covered by the prior pass.
		$cursor      = gmdate( 'Y-m-d H:i:s', time() );
		$modified_at = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );

		$id = $this->insert_post();
		$this->set_post_modified( $id, $modified_at );

		// Give it a source ID lower than any "new since" boundary by requesting with a
		// last_id at or above this post's own ID, isolating the assertion to the
		// modified_since/overlap branch rather than the ID branch.
		$response = PostReader::get_posts( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $id,
		] ) );
		$ids = $this->ids_from( $response );

		$this->assertContains( $id, $ids, 'A post modified just before the cursor must be caught by the overlap window.' );
	}

	public function test_post_modified_exactly_at_cursor_is_returned_not_skipped(): void {
		// Same-second edit / clock-skew case: post_modified equals the stored cursor
		// exactly. The overlap floor (cursor - overlap) is strictly less than the cursor,
		// so an exact match must still be included.
		$id = $this->insert_post();
		$ts = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
		$this->set_post_modified( $id, $ts );

		$response = PostReader::get_posts( $this->make_request( [
			'modified_since' => $ts,
			'last_id'        => $id,
		] ) );
		$ids = $this->ids_from( $response );

		$this->assertContains( $id, $ids, 'A post modified at the exact cursor timestamp must not be silently skipped.' );
	}

	public function test_post_well_outside_overlap_window_and_below_last_id_is_excluded(): void {
		// Modified over an hour before the cursor (well outside any sane overlap) and its
		// ID is not above last_id — this post has not changed since the prior pass and
		// must not be re-returned on every sync pass forever.
		$id = $this->insert_post();
		$this->set_post_modified( $id, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$cursor = gmdate( 'Y-m-d H:i:s', time() );

		$response = PostReader::get_posts( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $id,
		] ) );
		$ids = $this->ids_from( $response );

		$this->assertNotContains( $id, $ids, 'An unchanged post outside the overlap window must not be re-returned.' );
	}

	public function test_new_post_is_returned_via_id_branch_even_if_modified_before_cursor(): void {
		// A post whose ID is above last_id is always returned, even if for some reason its
		// post_modified doesn't clear the overlap floor (e.g. a backdated post_modified) —
		// the ID branch is the guarantee that a genuinely new post is never missed.
		$below_id = $this->insert_post();
		$new_id   = $this->insert_post();
		$this->set_post_modified( $new_id, gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ) );

		$cursor = gmdate( 'Y-m-d H:i:s', time() );

		$response = PostReader::get_posts( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $below_id,
		] ) );
		$ids = $this->ids_from( $response );

		$this->assertContains( $new_id, $ids, 'A post with an ID above last_id must always be returned regardless of its post_modified value.' );
	}

	public function test_delta_mode_still_excludes_attachments(): void {
		wp_insert_attachment( [
			'post_title'     => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		] );

		$cursor   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$response = PostReader::get_posts( $this->make_request( [ 'modified_since' => $cursor ] ) );

		foreach ( $response->get_data() as $p ) {
			$this->assertNotSame( 'attachment', $p['post_type'] );
		}
	}
}
