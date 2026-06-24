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
}
