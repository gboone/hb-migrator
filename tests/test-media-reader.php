<?php
/**
 * Tests for MediaReader::get_media() — pagination and targeted ID fetch.
 */

use HBMigrator\Source\MediaReader;

class Test_Media_Reader extends WP_UnitTestCase {

	private int $blog_id;

	public function set_up(): void {
		parent::set_up();
		$this->blog_id = get_current_blog_id();
	}

	public function tear_down(): void {
		parent::tear_down();
		// Clean up attachments created during tests.
		$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
		foreach ( $attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
	}

	private function make_request( array $params = [] ): \WP_REST_Request {
		$req = new WP_REST_Request( 'GET', '/' . HBM_API_NAMESPACE . '/source/sites/' . $this->blog_id . '/media' );
		$req->set_param( 'blog_id', $this->blog_id );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function insert_attachment( string $title = 'file' ): int {
		return wp_insert_attachment( [
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		] );
	}

	private function insert_attached_attachment( string $title = 'attached' ): int {
		$parent_id = wp_insert_post( [
			'post_title'  => 'parent-for-' . $title,
			'post_status' => 'publish',
			'post_type'   => 'post',
		] );
		return wp_insert_attachment( [
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		], false, $parent_id );
	}

	private function insert_attachment_with_parent( int $parent_id, string $title = 'attached' ): int {
		return wp_insert_attachment( [
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		], false, $parent_id );
	}

	private function set_attachment_modified( int $att_id, string $mysql_datetime ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_modified' => $mysql_datetime, 'post_modified_gmt' => $mysql_datetime ],
			[ 'ID' => $att_id ]
		);
		clean_post_cache( $att_id );
	}

	private function attachment_ids_from( \WP_REST_Response $response ): array {
		return array_column( $response->get_data(), 'source_attachment_id' );
	}

	// -------------------------------------------------------------------------
	// Offset pagination (no ids param)
	// -------------------------------------------------------------------------

	public function test_returns_paginated_results_without_ids(): void {
		$id1 = $this->insert_attachment( 'a' );
		$id2 = $this->insert_attachment( 'b' );

		$response = MediaReader::get_media( $this->make_request( [ 'per_page' => 50 ] ) );
		$data     = $response->get_data();

		$returned_ids = array_column( $data, 'source_attachment_id' );
		$this->assertContains( $id1, $returned_ids );
		$this->assertContains( $id2, $returned_ids );
	}

	public function test_offset_pagination_skips_earlier_items(): void {
		$id1 = $this->insert_attachment( 'first' );
		$id2 = $this->insert_attachment( 'second' );

		$response = MediaReader::get_media( $this->make_request( [ 'per_page' => 1, 'offset' => 1 ] ) );
		$data     = $response->get_data();
		$returned = array_column( $data, 'source_attachment_id' );

		$this->assertCount( 1, $data );
		$this->assertContains( $id2, $returned );
		$this->assertNotContains( $id1, $returned );
	}

	// -------------------------------------------------------------------------
	// Targeted ID fetch
	// -------------------------------------------------------------------------

	public function test_ids_param_returns_only_specified_attachments(): void {
		$id1 = $this->insert_attachment( 'target' );
		$id2 = $this->insert_attachment( 'other' );
		$id3 = $this->insert_attachment( 'other2' );

		$response = MediaReader::get_media( $this->make_request( [ 'ids' => [ $id1, $id3 ] ] ) );
		$data     = $response->get_data();

		$returned = array_column( $data, 'source_attachment_id' );
		$this->assertContains( $id1, $returned );
		$this->assertContains( $id3, $returned );
		$this->assertNotContains( $id2, $returned );
	}

	public function test_ids_param_ignores_offset(): void {
		$id1 = $this->insert_attachment( 'a' );
		$this->insert_attachment( 'b' );

		// offset=999 would return nothing via pagination, but ids overrides it.
		$response = MediaReader::get_media( $this->make_request( [ 'ids' => [ $id1 ], 'offset' => 999 ] ) );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( $id1, $data[0]['source_attachment_id'] );
	}

	public function test_ids_param_silently_omits_nonexistent_ids(): void {
		$real_id    = $this->insert_attachment( 'real' );
		$fake_id    = 99999;

		$response = MediaReader::get_media( $this->make_request( [ 'ids' => [ $real_id, $fake_id ] ] ) );
		$data     = $response->get_data();

		$returned = array_column( $data, 'source_attachment_id' );
		$this->assertContains( $real_id, $returned );
		$this->assertNotContains( $fake_id, $returned );
	}

	public function test_empty_ids_falls_through_to_pagination(): void {
		$id1 = $this->insert_attachment( 'a' );

		$response = MediaReader::get_media( $this->make_request( [ 'ids' => [] ] ) );
		$data     = $response->get_data();

		$this->assertContains( $id1, array_column( $data, 'source_attachment_id' ) );
	}

	public function test_ids_capped_at_200(): void {
		// Pass 201 IDs — only 200 should be passed to the query.
		$ids = range( 1, 201 );
		$response = MediaReader::get_media( $this->make_request( [ 'ids' => $ids ] ) );
		// The query should run (not throw) and return at most 200 items.
		$this->assertIsArray( $response->get_data() );
		$this->assertLessThanOrEqual( 200, count( $response->get_data() ) );
	}

	// -------------------------------------------------------------------------
	// attached_only scope
	// -------------------------------------------------------------------------

	public function test_attached_only_excludes_orphan_attachments(): void {
		$orphan_id   = $this->insert_attachment( 'orphan' );
		$attached_id = $this->insert_attached_attachment( 'attached' );

		$response = MediaReader::get_media( $this->make_request( [ 'attached_only' => '1' ] ) );
		$returned = array_column( $response->get_data(), 'source_attachment_id' );

		$this->assertContains( $attached_id, $returned, 'Attached attachment should be included.' );
		$this->assertNotContains( $orphan_id, $returned, 'Orphan attachment should be excluded.' );
	}

	public function test_default_scope_includes_all_attachments(): void {
		$orphan_id   = $this->insert_attachment( 'orphan' );
		$attached_id = $this->insert_attached_attachment( 'attached' );

		$response = MediaReader::get_media( $this->make_request() );
		$returned = array_column( $response->get_data(), 'source_attachment_id' );

		$this->assertContains( $orphan_id, $returned );
		$this->assertContains( $attached_id, $returned );
	}

	public function test_attached_only_ignored_when_ids_param_is_set(): void {
		$orphan_id = $this->insert_attachment( 'orphan' );
		$this->insert_attached_attachment( 'attached' );

		// IDs retry passes always fetch the specified IDs regardless of scope.
		$response = MediaReader::get_media( $this->make_request( [ 'ids' => [ $orphan_id ], 'attached_only' => '1' ] ) );
		$returned = array_column( $response->get_data(), 'source_attachment_id' );

		$this->assertContains( $orphan_id, $returned, 'IDs path must ignore attached_only and fetch the requested ID.' );
	}

	public function test_posts_where_filter_removed_after_attached_only_query(): void {
		global $wp_filter;
		$this->insert_attachment( 'solo' );
		$count_before = count( array_keys( $wp_filter['posts_where']->callbacks ?? [] ) );

		MediaReader::get_media( $this->make_request( [ 'attached_only' => '1' ] ) );

		$count_after = count( array_keys( $wp_filter['posts_where']->callbacks ?? [] ) );
		$this->assertSame( $count_before, $count_after, 'posts_where filter must be removed after the query.' );
	}

	// -------------------------------------------------------------------------
	// Data shape
	// -------------------------------------------------------------------------

	public function test_response_includes_expected_fields(): void {
		$this->insert_attachment( 'sample' );

		$response = MediaReader::get_media( $this->make_request() );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data );
		$item = $data[0];
		foreach ( [ 'source_attachment_id', 'post_title', 'post_date', 'file_url' ] as $field ) {
			$this->assertArrayHasKey( $field, $item, "Missing field: $field" );
		}
	}

	// -------------------------------------------------------------------------
	// Delta-cursor mode (U4 sync passes) — mirrors PostReader's U3 modified_since shape.
	// -------------------------------------------------------------------------

	public function test_new_attachment_since_cursor_is_returned(): void {
		$cursor = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$new_id = $this->insert_attachment( 'new' );
		$this->set_attachment_modified( $new_id, gmdate( 'Y-m-d H:i:s' ) );

		$response = MediaReader::get_media( $this->make_request( [ 'modified_since' => $cursor ] ) );
		$ids      = $this->attachment_ids_from( $response );

		$this->assertContains( $new_id, $ids, 'An attachment modified after the cursor must be returned.' );
	}

	public function test_attachment_own_modified_change_is_returned(): void {
		// e.g. alt text edited on an already-synced attachment.
		$id = $this->insert_attachment( 'edited' );
		$this->set_attachment_modified( $id, gmdate( 'Y-m-d H:i:s' ) );

		$cursor = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$response = MediaReader::get_media( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $id, // isolate to the post_modified branch, not the ID branch.
		] ) );
		$ids = $this->attachment_ids_from( $response );

		$this->assertContains( $id, $ids, 'An attachment whose own post_modified changed must be re-synced.' );
	}

	public function test_unchanged_attachment_outside_overlap_window_is_excluded(): void {
		$id = $this->insert_attachment( 'stable' );
		$this->set_attachment_modified( $id, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$cursor = gmdate( 'Y-m-d H:i:s' );

		$response = MediaReader::get_media( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $id,
		] ) );
		$ids = $this->attachment_ids_from( $response );

		$this->assertNotContains( $id, $ids, 'An unchanged attachment outside the overlap window must not be re-returned.' );
	}

	public function test_attachment_with_touched_parent_is_included_without_own_change(): void {
		// U4's core scenario: a featured image swapped on a post U3 just synced — the
		// attachment's own post_modified is untouched, but its parent post ID is passed via
		// parent_ids (sourced from PostSyncStage::get_synced_post_ids() in production).
		$parent_id = wp_insert_post( [ 'post_title' => 'parent', 'post_status' => 'publish', 'post_type' => 'post' ] );
		$att_id    = $this->insert_attachment_with_parent( $parent_id, 'featured' );
		$this->set_attachment_modified( $att_id, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$cursor = gmdate( 'Y-m-d H:i:s' );

		$response = MediaReader::get_media( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $att_id,
			'parent_ids'     => [ $parent_id ],
		] ) );
		$ids = $this->attachment_ids_from( $response );

		$this->assertContains( $att_id, $ids, 'An attachment whose parent post was just synced must be included even without its own change.' );
	}

	public function test_attachment_not_in_parent_ids_and_unchanged_is_excluded(): void {
		$other_parent_id = wp_insert_post( [ 'post_title' => 'other-parent', 'post_status' => 'publish', 'post_type' => 'post' ] );
		$att_id          = $this->insert_attachment_with_parent( $other_parent_id, 'unrelated' );
		$this->set_attachment_modified( $att_id, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$cursor           = gmdate( 'Y-m-d H:i:s' );
		$unrelated_parent = wp_insert_post( [ 'post_title' => 'unrelated-parent', 'post_status' => 'publish', 'post_type' => 'post' ] );

		$response = MediaReader::get_media( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $att_id,
			'parent_ids'     => [ $unrelated_parent ],
		] ) );
		$ids = $this->attachment_ids_from( $response );

		$this->assertNotContains( $att_id, $ids, 'An attachment whose parent is not in parent_ids, and which has no own change, must be excluded.' );
	}

	public function test_new_attachment_is_returned_via_id_branch_regardless_of_modified(): void {
		$below_id = $this->insert_attachment( 'below' );
		$new_id   = $this->insert_attachment( 'new-id' );
		$this->set_attachment_modified( $new_id, gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ) );

		$cursor = gmdate( 'Y-m-d H:i:s' );

		$response = MediaReader::get_media( $this->make_request( [
			'modified_since' => $cursor,
			'last_id'        => $below_id,
		] ) );
		$ids = $this->attachment_ids_from( $response );

		$this->assertContains( $new_id, $ids, 'An attachment with an ID above last_id must always be returned regardless of post_modified.' );
	}

	public function test_ids_param_takes_precedence_over_modified_since(): void {
		// The existing targeted-retry `ids` param must keep working unchanged, even when
		// modified_since is also present — ids always wins (see class docblock).
		$id1 = $this->insert_attachment( 'target' );
		$id2 = $this->insert_attachment( 'other' );
		$this->set_attachment_modified( $id2, gmdate( 'Y-m-d H:i:s' ) );

		$response = MediaReader::get_media( $this->make_request( [
			'ids'             => [ $id1 ],
			'modified_since'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
		] ) );
		$ids = $this->attachment_ids_from( $response );

		$this->assertSame( [ $id1 ], $ids, 'ids param must take precedence over modified_since.' );
	}

	public function test_deleted_on_source_attachment_simply_absent_from_delta_results(): void {
		// R7: deletions on source are never propagated. This is inherent to the query — a
		// deleted attachment simply cannot appear in the result set, so there is nothing to
		// assert other than that a still-present attachment is unaffected.
		$id = $this->insert_attachment( 'present' );
		$this->set_attachment_modified( $id, gmdate( 'Y-m-d H:i:s' ) );

		$cursor   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$response = MediaReader::get_media( $this->make_request( [ 'modified_since' => $cursor ] ) );
		$ids      = $this->attachment_ids_from( $response );

		$this->assertContains( $id, $ids );
		$this->assertNotContains( 999999999, $ids, 'A nonexistent (i.e. deleted) source ID never appears in results.' );
	}
}
