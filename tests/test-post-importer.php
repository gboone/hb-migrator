<?php
/**
 * Tests for PostImporter — comment_count preservation.
 *
 * Uses pre_http_request to mock SourceClient responses.
 */

use HBMigrator\Destination\AuditReport;
use HBMigrator\Destination\PostImporter;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_PostImporter extends WP_UnitTestCase {

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
		MigrationRegistry::update_site_job( $this->jid, [ 'dest_blog_id' => get_current_blog_id() ] );
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

	public function test_comment_count_is_written_to_destination_post(): void {
		$source_post = $this->make_source_post( [ 'comment_count' => 7 ] );
		$this->mock_posts_response( [ $source_post ] );

		PostImporter::process( $this->jid, 0, 0 );

		// Find the post that was just inserted.
		$posts = get_posts( [
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		$this->assertNotEmpty( $posts, 'PostImporter did not create a destination post.' );
		$this->assertSame( 7, (int) $posts[0]->comment_count );
	}

	public function test_zero_comment_count_is_preserved(): void {
		$source_post = $this->make_source_post( [ 'comment_count' => 0 ] );
		$this->mock_posts_response( [ $source_post ] );

		PostImporter::process( $this->jid, 0, 0 );

		$posts = get_posts( [
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		$this->assertNotEmpty( $posts );
		$this->assertSame( 0, (int) $posts[0]->comment_count );
	}

	public function test_post_meta_is_written(): void {
		$source_post = $this->make_source_post( [
			'meta' => [
				[ 'key' => '_custom_field', 'value' => 'custom-value' ],
			],
		] );
		$this->mock_posts_response( [ $source_post ] );

		PostImporter::process( $this->jid, 0, 0 );

		$posts = get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1 ] );
		$this->assertNotEmpty( $posts );
		$this->assertSame( 'custom-value', get_post_meta( $posts[0]->ID, '_custom_field', true ) );
	}

	public function test_attachment_post_type_is_skipped(): void {
		$attachment = $this->make_source_post( [
			'ID'            => 999,
			'post_type'     => 'attachment',
			'post_mime_type' => 'image/jpeg',
			'post_name'     => 'photo-jpg',
		] );
		$this->mock_posts_response( [ $attachment ] );

		PostImporter::process( $this->jid, 0, 0 );

		// No attachment post should have been created.
		$created = get_posts( [
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'numberposts' => -1,
		] );
		$this->assertEmpty( $created, 'PostImporter must not create attachment posts.' );
	}

	public function test_attachment_skipped_and_regular_post_imported_in_mixed_batch(): void {
		$attachment = $this->make_source_post( [
			'ID'        => 801,
			'post_type' => 'attachment',
			'post_name' => 'image-jpg',
		] );
		$post = $this->make_source_post( [
			'ID'        => 802,
			'post_type' => 'post',
			'post_name' => 'my-article',
		] );
		$this->mock_posts_response( [ $attachment, $post ] );

		PostImporter::process( $this->jid, 0, 0 );

		// The attachment must be absent.
		$att_posts = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1 ] );
		$this->assertEmpty( $att_posts );

		// The regular post must be present.
		$reg_posts = get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1 ] );
		$this->assertCount( 1, $reg_posts );
		$this->assertSame( 'my-article', $reg_posts[0]->post_name );
	}

	public function test_attachment_source_id_not_in_idmap(): void {
		$attachment = $this->make_source_post( [
			'ID'        => 501,
			'post_type' => 'attachment',
		] );
		$this->mock_posts_response( [ $attachment ] );

		PostImporter::process( $this->jid, 0, 0 );

		$this->assertNull( \HBMigrator\IdMap::get( $this->jid, 'post', 501 ) );
	}

	// -------------------------------------------------------------------------
	// U3: existing-row branch now updates core fields, not just postmeta.
	// -------------------------------------------------------------------------

	public function test_existing_row_branch_updates_core_fields_on_edit(): void {
		$original = $this->make_source_post( [
			'ID'           => 42,
			'post_title'   => 'Original Title',
			'post_content' => 'Original content.',
			'post_status'  => 'draft',
		] );
		$this->mock_posts_response( [ $original ] );
		PostImporter::process( $this->jid, 0, 0 );

		$dest_id = \HBMigrator\IdMap::get( $this->jid, 'post', 42 );
		$this->assertNotNull( $dest_id, 'First pass must have created and mapped the post.' );

		remove_all_filters( 'pre_http_request' );

		$edited = $this->make_source_post( [
			'ID'           => 42,
			'post_title'   => 'Edited Title',
			'post_content' => 'Edited content.',
			'post_status'  => 'publish',
		] );
		$this->mock_posts_response( [ $edited ] );
		PostImporter::process( $this->jid, 0, 0 );

		$post = get_post( $dest_id );
		$this->assertSame( 'Edited Title', $post->post_title, 'post_title must be updated on the existing-row branch, not just postmeta.' );
		$this->assertSame( 'Edited content.', $post->post_content );
		$this->assertSame( 'publish', $post->post_status );

		// The destination post row must still map to the same source ID — an edit-sync
		// updates in place, it does not insert a duplicate.
		$this->assertSame( $dest_id, \HBMigrator\IdMap::get( $this->jid, 'post', 42 ) );
		$all_posts = get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1 ] );
		$this->assertCount( 1, $all_posts, 'An edit-sync must not create a duplicate post.' );
	}

	public function test_existing_row_branch_reapplying_unchanged_content_is_a_safe_no_op(): void {
		$source_post = $this->make_source_post( [ 'ID' => 55, 'post_title' => 'Stable Title' ] );
		$this->mock_posts_response( [ $source_post ] );
		PostImporter::process( $this->jid, 0, 0 );

		$dest_id = \HBMigrator\IdMap::get( $this->jid, 'post', 55 );

		remove_all_filters( 'pre_http_request' );
		$this->mock_posts_response( [ $source_post ] );

		// Re-applying identical content (e.g. a post caught again within the sync
		// overlap window) must not error and must leave the destination row equivalent.
		PostImporter::process( $this->jid, 0, 0 );

		$post = get_post( $dest_id );
		$this->assertSame( 'Stable Title', $post->post_title );
		$this->assertSame( $dest_id, \HBMigrator\IdMap::get( $this->jid, 'post', 55 ) );
	}

	public function test_post_missing_from_a_later_batch_is_left_untouched_on_destination(): void {
		// Simulates a post deleted on source: it simply no longer appears in the source
		// response. R7 — deletions are never propagated, so the destination row must be
		// left exactly as it was.
		$post_a = $this->make_source_post( [ 'ID' => 10, 'post_title' => 'Post A' ] );
		$post_b = $this->make_source_post( [ 'ID' => 11, 'post_title' => 'Post B' ] );
		$this->mock_posts_response( [ $post_a, $post_b ] );
		PostImporter::process( $this->jid, 0, 0 );

		$dest_a = \HBMigrator\IdMap::get( $this->jid, 'post', 10 );
		$this->assertNotNull( $dest_a );

		// A subsequent batch that only includes post B (post A "deleted" on source) —
		// import_batch() is called directly here since deletion detection happens purely
		// by a post's absence from what the source batch returns, not via any HTTP layer.
		PostImporter::import_batch( $this->jid, [ $post_b ] );

		$post = get_post( $dest_a );
		$this->assertNotNull( $post, 'A post absent from a later batch must not be deleted on destination.' );
		$this->assertSame( 'Post A', $post->post_title, 'A post absent from a later batch must be left completely untouched.' );
	}

	// -------------------------------------------------------------------------
	// import_batch() return-shape contract (code review P0/P1 fix): callers such as
	// PostSyncStage need per-item failure visibility, not just the highest source ID
	// processed, so a failed item's position never gets silently skipped by a delta-sync
	// cursor. import_batch() now returns ['max_id' => int, 'failed_ids' => int[]] instead
	// of a bare int.
	// -------------------------------------------------------------------------

	public function test_import_batch_returns_max_id_and_empty_failed_ids_on_full_success(): void {
		$posts = [
			$this->make_source_post( [ 'ID' => 20 ] ),
			$this->make_source_post( [ 'ID' => 21 ] ),
		];

		$result = PostImporter::import_batch( $this->jid, $posts );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'max_id', $result );
		$this->assertArrayHasKey( 'failed_ids', $result );
		$this->assertSame( 21, $result['max_id'] );
		$this->assertSame( [], $result['failed_ids'] );
	}

	public function test_import_batch_reports_failed_ids_for_a_wp_insert_post_failure(): void {
		// wp_insert_post() is called by PostImporter with $wp_error = false, so a rejected
		// insert (e.g. WordPress's own "empty content" guard) surfaces as a falsy return (0),
		// not a WP_Error — import_batch() must still record it as a failure.
		add_filter( 'wp_insert_post_empty_content', function ( $maybe_empty, $postarr ) {
			if ( 'FAIL_ME' === ( $postarr['post_title'] ?? '' ) ) {
				return true;
			}
			return $maybe_empty;
		}, 10, 2 );

		$failing    = $this->make_source_post( [ 'ID' => 30, 'post_title' => 'FAIL_ME' ] );
		$succeeding = $this->make_source_post( [ 'ID' => 31, 'post_title' => 'This One Is Fine' ] );

		$result = PostImporter::import_batch( $this->jid, [ $failing, $succeeding ] );

		$this->assertSame( [ 30 ], $result['failed_ids'], 'The failed insert must be reported by source ID.' );
		$this->assertNull( \HBMigrator\IdMap::get( $this->jid, 'post', 30 ), 'A failed insert must not be mapped.' );
		$this->assertNotNull( \HBMigrator\IdMap::get( $this->jid, 'post', 31 ), 'The other post in the same batch must still import.' );

		remove_all_filters( 'wp_insert_post_empty_content' );
	}

	// -------------------------------------------------------------------------
	// U3: request-trail capture for PostImporter's source/sites/{id}/posts listing (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U3. Request-trail
	// capture at every outbound source call"). PostImporter is site-job-scoped, so entries
	// are recorded via AuditReport::record() (scope: site_job).
	// -------------------------------------------------------------------------

	public function test_process_records_site_job_scoped_request_trail_entry_on_success(): void {
		$source_post = $this->make_source_post( [ 'ID' => 900 ] );
		$this->mock_posts_response( [ $source_post ] );

		PostImporter::process( $this->jid, 0, 0 );

		$post_id = AuditReport::get_or_create_for_site_job( $this->jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'site_job', $rows[0]['scope'] );
		$this->assertTrue( $rows[0]['success'] );
		$this->assertSame( 1, $rows[0]['count'] );
	}

	public function test_process_records_failed_request_trail_entry_when_source_unreachable(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/posts' ) ) {
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

		PostImporter::process( $this->jid, 0, 0 );

		// Existing error-handling behavior is unchanged by U3: a retryable failure at
		// attempt 0 is rescheduled by PipelineController, not marked failed outright.
		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertNotSame( 'failed', $job->status );

		$post_id = AuditReport::get_or_create_for_site_job( $this->jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_request', false );
		restore_current_blog();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'site_job', $rows[0]['scope'] );
		$this->assertFalse( $rows[0]['success'] );
		$this->assertArrayHasKey( 'error', $rows[0] );
	}

	// -------------------------------------------------------------------------
	// U5: write-action trail for posts (see
	// docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U5. Write-action trail:
	// posts and media (sync-gated)"). import_batch() is the only method PostSyncStage also
	// calls, so the status gate below is the single most important test in this file — a leak
	// here would mean audit data silently appearing for out-of-scope sync passes.
	// -------------------------------------------------------------------------

	private function get_write_trail_rows(): array {
		$post_id = AuditReport::get_or_create_for_site_job( $this->jid );
		switch_to_blog( get_main_site_id() );
		$rows = get_post_meta( $post_id, '_hbm_audit_write', false );
		restore_current_blog();
		return $rows;
	}

	/**
	 * Critical, non-obvious behavior (plan's explicit Execution note): a call to import_batch()
	 * for a site job whose status is NOT pending/running (simulating PostSyncStage's own call,
	 * which only ever runs while status is 'syncing') must produce ZERO write-trail entries.
	 */
	public function test_import_batch_records_no_write_trail_entries_when_site_job_is_syncing(): void {
		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'syncing' ] );

		$post = $this->make_source_post( [ 'ID' => 700 ] );
		PostImporter::import_batch( $this->jid, [ $post ] );

		$this->assertCount(
			0,
			$this->get_write_trail_rows(),
			'A sync-context (status: syncing) call to import_batch() must not produce any write-trail entries.'
		);
	}

	/** Same gate, for the 'complete' status (also used by the sync pipeline's own callers). */
	public function test_import_batch_records_no_write_trail_entries_when_site_job_is_complete(): void {
		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'complete' ] );

		$post = $this->make_source_post( [ 'ID' => 701 ] );
		PostImporter::import_batch( $this->jid, [ $post ] );

		$this->assertCount(
			0,
			$this->get_write_trail_rows(),
			'A sync-context (status: complete) call to import_batch() must not produce any write-trail entries.'
		);
	}

	public function test_import_batch_records_created_entry_with_raw_source_fields_for_new_post(): void {
		// Site job status is 'pending' by default from set_up() — the initial-migration context.
		$post = $this->make_source_post( [
			'ID'            => 710,
			'post_content'  => 'Raw source content.',
			'post_excerpt'  => 'Raw source excerpt.',
			'post_author_email' => 'author@example.com',
			'meta'          => [ [ 'key' => '_custom_field', 'value' => 'custom-value' ] ],
		] );

		PostImporter::import_batch( $this->jid, [ $post ] );

		$rows = $this->get_write_trail_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'write', $rows[0]['type'] );
		$this->assertSame( 'post', $rows[0]['object_type'] );
		$this->assertSame( 710, $rows[0]['source_id'] );
		$this->assertSame( 'created', $rows[0]['outcome'] );
		$this->assertSame( 'Raw source content.', $rows[0]['post_content'] );
		$this->assertSame( 'Raw source excerpt.', $rows[0]['post_excerpt'] );
		$this->assertSame( 'author@example.com', $rows[0]['source_author'] );
		$this->assertSame( [ [ 'key' => '_custom_field', 'value' => 'custom-value' ] ], $rows[0]['meta'] );
		$this->assertSame( 'test-post', $rows[0]['post_name'], 'Slug must be cached for later comparator use (R4).' );
		$this->assertSame( 'post', $rows[0]['post_type'] );
	}

	public function test_import_batch_records_updated_entry_for_already_mapped_retried_item(): void {
		$post = $this->make_source_post( [ 'ID' => 720 ] );
		PostImporter::import_batch( $this->jid, [ $post ] );

		// Same batch re-processed (resumed/retried pass) — the item is already IdMap-mapped.
		PostImporter::import_batch( $this->jid, [ $post ] );

		$rows = $this->get_write_trail_rows();
		$this->assertCount( 2, $rows, 'Each attempt gets its own trail entry (request-trail-style duplication).' );
		$this->assertSame( 'created', $rows[0]['outcome'] );
		$this->assertSame( 'updated', $rows[1]['outcome'], 'A retried already-mapped item must be recorded as updated, not created (no double-counting).' );
	}

	public function test_import_batch_records_failed_entry_tagged_to_correct_source_id(): void {
		add_filter( 'wp_insert_post_empty_content', function ( $maybe_empty, $postarr ) {
			if ( 'FAIL_ME' === ( $postarr['post_title'] ?? '' ) ) {
				return true;
			}
			return $maybe_empty;
		}, 10, 2 );

		$post = $this->make_source_post( [ 'ID' => 730, 'post_title' => 'FAIL_ME' ] );
		PostImporter::import_batch( $this->jid, [ $post ] );

		$rows = $this->get_write_trail_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['outcome'] );
		$this->assertSame( 730, $rows[0]['source_id'] );

		remove_all_filters( 'wp_insert_post_empty_content' );
	}
}
