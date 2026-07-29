<?php
/**
 * Tests for U6: AuditComparator — hashing, normalization, and count comparison. See
 * docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U6. Comparator: hashing,
 * normalization, and count comparison".
 *
 * These tests drive AuditComparator directly against manually-seeded IdMap entries and
 * AuditReport write-trail entries (the same shapes PostImporter/MediaImporter/TermImporter
 * actually record — see those classes' own record_write_trail()/inline AuditReport::record()
 * calls) rather than running the full pipeline, since U6's job is the comparison logic itself,
 * not re-testing U3/U4/U5's trail capture.
 */

use HBMigrator\Destination\AuditComparator;
use HBMigrator\Destination\AuditReport;
use HBMigrator\Destination\PostImporter;
use HBMigrator\Destination\SearchReplace;
use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\QueueTable;

class Test_AuditComparator extends WP_UnitTestCase {

	private int $mid;
	private int $jid;
	private string $source_siteurl = 'https://old-site.example.com';

	public function set_up(): void {
		parent::set_up();
		QueueTable::maybe_create_or_upgrade();

		$this->mid = MigrationRegistry::create_migration( $this->source_siteurl, 'key', null );
		$this->jid = $this->make_site_job();
	}

	public function tear_down(): void {
		parent::tear_down();
		remove_all_filters( 'hbm_audit_compare_batch_size' );
		remove_all_filters( 'hbm_audit_compare_time_limit' );
		remove_all_filters( 'hbm_audit_report_detail_cap' );
		remove_all_filters( 'wp_insert_post_empty_content' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_site_job(): int {
		$jid = MigrationRegistry::create_site_job(
			$this->mid,
			1,
			'old-site.example.com',
			$this->source_siteurl,
			$this->source_siteurl . '/wp-content/uploads',
			'/old-site/'
		);
		MigrationRegistry::update_site_job( $jid, [ 'dest_blog_id' => get_current_blog_id(), 'status' => 'complete' ] );
		return $jid;
	}

	private function dest_siteurl(): string {
		return rtrim( get_option( 'siteurl' ), '/' );
	}

	private function create_dest_post( array $overrides = [] ): int {
		return (int) wp_insert_post( array_merge( [
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_content' => '',
			'post_excerpt' => '',
			// Fixed default author (not the ambiguous "current user" WP would otherwise fall
			// back to in a test context) so authorship-agnostic tests aren't accidentally
			// flagged as diverged — matches the '' => fallback-ID-1 default on the source side.
			'post_author'  => 1,
		], $overrides ) );
	}

	private function create_dest_attachment(): int {
		return (int) wp_insert_post( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => 'Attachment',
			'post_mime_type' => 'image/jpeg',
		] );
	}

	/**
	 * Records a write-trail entry with exactly the shape PostImporter::record_write_trail()
	 * produces (see class-post-importer.php) — the same entry shape AuditComparator reads back.
	 */
	private function record_post_entry( int $source_id, string $outcome, array $overrides = [], ?int $jid = null ): void {
		$jid = $jid ?? $this->jid;
		AuditReport::record( $jid, 'site_job', array_merge( [
			'type'          => 'write',
			'object_type'   => 'post',
			'source_id'     => $source_id,
			'outcome'       => $outcome,
			'post_content'  => '',
			'post_excerpt'  => '',
			'meta'          => [],
			'source_author' => '',
			'post_name'     => '',
			'post_type'     => 'post',
			'post_title'    => '',
		], $overrides ) );
	}

	private function record_attachment_entry( int $source_id, string $outcome, ?int $jid = null ): void {
		$jid = $jid ?? $this->jid;
		AuditReport::record( $jid, 'site_job', [
			'type'           => 'write',
			'object_type'    => 'attachment',
			'source_id'      => $source_id,
			'outcome'        => $outcome,
			'file_url'       => '',
			'post_title'     => '',
			'description'    => '',
			'caption'        => '',
			'alt_text'       => '',
			'post_mime_type' => '',
		] );
	}

	private function record_term_entry( int $source_id, string $outcome, ?int $dest_id = null, ?int $jid = null ): void {
		$jid   = $jid ?? $this->jid;
		$entry = [
			'type'        => 'write',
			'object_type' => 'term',
			'source_id'   => $source_id,
			'outcome'     => $outcome,
		];
		if ( null !== $dest_id ) {
			$entry['dest_id'] = $dest_id;
		}
		AuditReport::record( $jid, 'site_job', $entry );
	}

	private function get_result( int $source_id, ?int $jid = null ): array {
		$results = AuditComparator::get_post_comparison_results( $jid ?? $this->jid );
		$this->assertArrayHasKey( $source_id, $results, "No comparison result recorded for source_id {$source_id}." );
		return $results[ $source_id ];
	}

	/**
	 * U7: reads back the report post's rendered post_content, on the primary site, matching
	 * AuditReport::render_summary()'s own switch_to_blog( get_main_site_id() ) convention.
	 */
	private function get_report_content( ?int $jid = null ): string {
		$post_id = AuditReport::get_or_create_for_site_job( $jid ?? $this->jid );
		switch_to_blog( get_main_site_id() );
		try {
			$post = get_post( $post_id );
			return $post ? (string) $post->post_content : '';
		} finally {
			restore_current_blog();
		}
	}

	// -------------------------------------------------------------------------
	// Happy path: expected URL rewrite is the only difference — content hashes as a match.
	// -------------------------------------------------------------------------

	public function test_content_matches_when_only_expected_url_rewrite_differs(): void {
		$source_id = 100;
		$dest_id   = $this->create_dest_post( [
			'post_content' => 'See ' . $this->dest_siteurl() . '/about',
			'post_title'   => 'Hello',
			'post_name'    => 'hello',
		] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_content' => 'See ' . $this->source_siteurl . '/about',
			'post_title'   => 'Hello',
			'post_name'    => 'hello',
		] );

		$checkpoint = AuditComparator::compare_batch( $this->jid, 0 );
		$this->assertNull( $checkpoint );

		$result = $this->get_result( $source_id );
		$this->assertTrue( $result['comparable'] );
		$this->assertFalse( $result['diverged'], 'A destination content difference fully explained by the expected URL rewrite must not be flagged.' );
		$this->assertTrue( $result['content_hash_match'] );
	}

	// -------------------------------------------------------------------------
	// Happy path: _thumbnail_id remapped via the attachment IdMap hashes as a match.
	// -------------------------------------------------------------------------

	public function test_thumbnail_id_matches_once_normalized_via_attachment_idmap(): void {
		$source_id     = 101;
		$source_att_id = 55;
		$dest_att_id   = 999;
		IdMap::set( $this->jid, 'attachment', $source_att_id, $dest_att_id );

		$dest_id = $this->create_dest_post( [ 'post_title' => 'Thumb Post', 'post_name' => 'thumb-post' ] );
		global $wpdb;
		$wpdb->insert( $wpdb->postmeta, [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'post_id'    => $dest_id,
			'meta_key'   => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => (string) $dest_att_id,
		] );

		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_title' => 'Thumb Post',
			'post_name'  => 'thumb-post',
			'meta'       => [ [ 'key' => '_thumbnail_id', 'value' => (string) $source_att_id ] ],
		] );

		AuditComparator::compare_batch( $this->jid, 0 );
		$result = $this->get_result( $source_id );

		$this->assertTrue( $result['postmeta_hash_match'], '_thumbnail_id must be reconciled via the attachment IdMap, not compared literally.' );
		$this->assertFalse( $result['diverged'] );
	}

	// -------------------------------------------------------------------------
	// Edge case: a merge-resolved pre-existing destination user is a match, not diverged.
	// -------------------------------------------------------------------------

	public function test_authorship_matches_when_merge_resolved_to_existing_user(): void {
		$existing_user_id = self::factory()->user->create( [ 'user_email' => 'author@old-site.example.com' ] );
		$this->assertNotSame( 1, $existing_user_id, 'Precondition: the resolved user must not be the fallback ID 1.' );

		$source_id = 102;
		$dest_id   = $this->create_dest_post( [
			'post_title'  => 'Authored',
			'post_name'   => 'authored',
			'post_author' => $existing_user_id,
		] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_title'    => 'Authored',
			'post_name'     => 'authored',
			'source_author' => 'author@old-site.example.com',
		] );

		AuditComparator::compare_batch( $this->jid, 0 );
		$result = $this->get_result( $source_id );

		$this->assertTrue( $result['authorship_match'], 'A user_conflict_policy: merge resolution to a pre-existing user must be treated as a match.' );
		$this->assertSame( $existing_user_id, $result['actual_author_id'] );
		$this->assertSame( $existing_user_id, $result['expected_author_id'] );
		$this->assertFalse( $result['diverged'] );
	}

	// -------------------------------------------------------------------------
	// Edge case: budget-exceeded mid-pass returns an exact checkpoint; resuming never
	// double-processes or skips an item.
	// -------------------------------------------------------------------------

	public function test_budget_exceeded_returns_checkpoint_and_resumes_without_double_processing(): void {
		add_filter( 'hbm_audit_compare_batch_size', static fn() => 1 );
		add_filter( 'hbm_audit_compare_time_limit', static fn() => -1.0 );

		$ids = [ 301, 302, 303 ];
		foreach ( $ids as $sid ) {
			$dest_id = $this->create_dest_post( [ 'post_title' => "Post {$sid}", 'post_name' => "post-{$sid}" ] );
			IdMap::set( $this->jid, 'post', $sid, $dest_id );
			$this->record_post_entry( $sid, 'created', [ 'post_title' => "Post {$sid}", 'post_name' => "post-{$sid}" ] );
		}

		$checkpoint = 0;
		$iterations = 0;
		do {
			$checkpoint = AuditComparator::compare_batch( $this->jid, $checkpoint );
			++$iterations;
		} while ( null !== $checkpoint && $iterations < 10 );

		$this->assertGreaterThan( 3, $iterations, 'batch_size=1 with an always-exceeded budget must take more than one call to drain 3 items.' );

		$results = AuditComparator::get_post_comparison_results( $this->jid );
		$this->assertCount( 3, $results, 'All three posts must be compared exactly once — no double-processing or skipping across resumed calls.' );
		foreach ( $ids as $sid ) {
			$this->assertArrayHasKey( $sid, $results );
			$this->assertFalse( $results[ $sid ]['diverged'] );
		}

		// Also assert at the raw storage level: exactly one stored result row per post, not
		// (e.g.) two rows for an item some bug reprocessed.
		$post_id = AuditReport::get_or_create_for_site_job( $this->jid );
		switch_to_blog( get_main_site_id() );
		$raw = get_post_meta( $post_id, '_hbm_audit_compare_result', false );
		restore_current_blog();
		$this->assertCount( 3, $raw, 'Exactly one stored comparison result per post.' );
	}

	// -------------------------------------------------------------------------
	// Verification: a multi-batch (checkpointed) run produces the same final result as a
	// single-batch run over the same data.
	// -------------------------------------------------------------------------

	public function test_multibatch_run_matches_single_batch_run_over_same_data(): void {
		$ids = [ 1001, 1002, 1003, 1004 ];
		foreach ( $ids as $sid ) {
			$dest_id = $this->create_dest_post( [ 'post_title' => "Multi {$sid}", 'post_name' => "multi-{$sid}" ] );
			IdMap::set( $this->jid, 'post', $sid, $dest_id );
			$this->record_post_entry( $sid, 'created', [ 'post_title' => "Multi {$sid}", 'post_name' => "multi-{$sid}" ] );
		}

		$this->assertNull( AuditComparator::compare_batch( $this->jid, 0 ) );
		$single_batch_results = AuditComparator::get_post_comparison_results( $this->jid );

		// Distinct slugs from the first job's posts above — both jobs' destination posts live
		// in the same real wp_posts table, and WordPress auto-deduplicates post_name across the
		// whole table, so reusing "multi-$sid" here would silently become "multi-$sid-2" and
		// make this purely a test-setup artifact rather than a comparator behavior difference.
		$jid2 = $this->make_site_job();
		foreach ( $ids as $sid ) {
			$dest_id = $this->create_dest_post( [ 'post_title' => "Multi {$sid}", 'post_name' => "multi-b-{$sid}" ] );
			IdMap::set( $jid2, 'post', $sid, $dest_id );
			$this->record_post_entry( $sid, 'created', [ 'post_title' => "Multi {$sid}", 'post_name' => "multi-b-{$sid}" ], $jid2 );
		}

		add_filter( 'hbm_audit_compare_batch_size', static fn() => 1 );
		add_filter( 'hbm_audit_compare_time_limit', static fn() => -1.0 );

		$checkpoint = 0;
		$iterations = 0;
		do {
			$checkpoint = AuditComparator::compare_batch( $jid2, $checkpoint );
			++$iterations;
		} while ( null !== $checkpoint && $iterations < 20 );

		remove_all_filters( 'hbm_audit_compare_batch_size' );
		remove_all_filters( 'hbm_audit_compare_time_limit' );

		$multi_batch_results = AuditComparator::get_post_comparison_results( $jid2 );

		$this->assertSame( array_keys( $single_batch_results ), array_keys( $multi_batch_results ) );
		foreach ( $ids as $sid ) {
			$this->assertSame( $single_batch_results[ $sid ]['diverged'], $multi_batch_results[ $sid ]['diverged'] );
			$this->assertSame( $single_batch_results[ $sid ]['content_hash_match'], $multi_batch_results[ $sid ]['content_hash_match'] );
			$this->assertSame( $single_batch_results[ $sid ]['postmeta_hash_match'], $multi_batch_results[ $sid ]['postmeta_hash_match'] );
		}
	}

	// -------------------------------------------------------------------------
	// Error/drift path: a genuinely different post_title is flagged.
	// -------------------------------------------------------------------------

	public function test_title_mismatch_is_flagged(): void {
		$source_id = 401;
		$dest_id   = $this->create_dest_post( [ 'post_title' => 'Destination Title', 'post_name' => 'same-slug' ] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [ 'post_title' => 'Source Title', 'post_name' => 'same-slug' ] );

		AuditComparator::compare_batch( $this->jid, 0 );
		$result = $this->get_result( $source_id );

		$this->assertTrue( $result['diverged'] );
		$this->assertContains( 'title', $result['diverged_fields'] );
		$this->assertFalse( $result['title_match'] );
	}

	// -------------------------------------------------------------------------
	// Error/drift path: a genuinely different slug (post_name) is flagged.
	// -------------------------------------------------------------------------

	public function test_slug_mismatch_is_flagged(): void {
		$source_id = 402;
		$dest_id   = $this->create_dest_post( [ 'post_title' => 'Same Title', 'post_name' => 'destination-slug' ] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [ 'post_title' => 'Same Title', 'post_name' => 'source-slug' ] );

		AuditComparator::compare_batch( $this->jid, 0 );
		$result = $this->get_result( $source_id );

		$this->assertTrue( $result['diverged'] );
		$this->assertContains( 'slug', $result['diverged_fields'] );
		$this->assertFalse( $result['slug_match'] );
	}

	// -------------------------------------------------------------------------
	// Error/drift path: destination content edited manually after import, in a way no known
	// transform explains, is a content-hash mismatch.
	// -------------------------------------------------------------------------

	public function test_content_mismatch_from_manual_edit_not_explained_by_known_transforms(): void {
		$source_id = 403;
		$dest_id   = $this->create_dest_post( [
			'post_content' => 'Original content, manually edited afterward.',
			'post_title'   => 'T',
			'post_name'    => 't',
		] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_content' => 'Original content.',
			'post_title'   => 'T',
			'post_name'    => 't',
		] );

		AuditComparator::compare_batch( $this->jid, 0 );
		$result = $this->get_result( $source_id );

		$this->assertTrue( $result['diverged'] );
		$this->assertContains( 'content_hash', $result['diverged_fields'] );
		$this->assertFalse( $result['content_hash_match'] );
	}

	// -------------------------------------------------------------------------
	// Happy path: no unexpected drift anywhere reports a fully clean comparison.
	// -------------------------------------------------------------------------

	public function test_clean_comparison_when_everything_matches(): void {
		$source_id = 1101;
		$dest_id   = $this->create_dest_post( [
			'post_title'   => 'Clean',
			'post_name'    => 'clean',
			'post_content' => 'All good.',
			'post_excerpt' => 'Excerpt.',
		] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_title'   => 'Clean',
			'post_name'    => 'clean',
			'post_content' => 'All good.',
			'post_excerpt' => 'Excerpt.',
		] );

		$this->assertNull( AuditComparator::compare_batch( $this->jid, 0 ) );

		$result = $this->get_result( $source_id );
		$this->assertTrue( $result['comparable'] );
		$this->assertFalse( $result['diverged'] );
		$this->assertEmpty( $result['diverged_fields'] );
	}

	// -------------------------------------------------------------------------
	// Edge case: a failed source write (never landed, no IdMap entry) is surfaced as "not
	// comparable" rather than silently dropped or crashing.
	// -------------------------------------------------------------------------

	public function test_failed_post_write_is_surfaced_as_not_comparable(): void {
		$source_id = 1201;
		$this->record_post_entry( $source_id, 'failed', [ 'post_title' => 'Never Landed' ] );

		$this->assertNull( AuditComparator::compare_batch( $this->jid, 0 ) );

		$result = $this->get_result( $source_id );
		$this->assertFalse( $result['comparable'] );
		$this->assertSame( 'source_write_failed', $result['reason'] );
		$this->assertSame( 0, $result['dest_id'] );
	}

	// -------------------------------------------------------------------------
	// A resumed/retried source_id (two write-trail entries) is compared using its LATEST
	// cached entry, not a stale first-attempt one.
	// -------------------------------------------------------------------------

	public function test_retried_source_id_uses_latest_cached_entry(): void {
		$source_id = 901;
		$dest_id   = $this->create_dest_post( [ 'post_title' => 'Second Attempt Title', 'post_name' => 'second-slug' ] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );

		// First (stale) attempt — would NOT match the destination if used.
		$this->record_post_entry( $source_id, 'created', [ 'post_title' => 'First Attempt Title', 'post_name' => 'first-slug' ] );
		// Second (retried, latest) attempt — matches destination exactly.
		$this->record_post_entry( $source_id, 'updated', [ 'post_title' => 'Second Attempt Title', 'post_name' => 'second-slug' ] );

		AuditComparator::compare_batch( $this->jid, 0 );
		$result = $this->get_result( $source_id );

		$this->assertFalse( $result['diverged'], 'The comparator must use the LATEST cached write-trail entry, not a stale first attempt.' );
		$this->assertSame( 'Second Attempt Title', $result['expected_title'] );
	}

	public function test_landed_post_is_still_compared_when_latest_entry_is_a_failed_retry(): void {
		// Found during code review: a source_id can land successfully on an earlier attempt
		// (real IdMap entry), then a LATER retried batch's wp_update_post() call can fail for
		// that same source_id (e.g. a transient DB error), recording a 'failed' outcome without
		// ever clearing the earlier successful IdMap entry. The comparator must gate on the real
		// (non-zero) dest_id, not blindly trust the latest entry's outcome string, or it
		// mischaracterizes a genuinely-landed, genuinely-divergent post as merely "not comparable".
		$source_id = 902;
		$dest_id   = $this->create_dest_post( [ 'post_title' => 'Stale Destination Title', 'post_name' => 'stale-slug' ] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );

		// First (successful) attempt.
		$this->record_post_entry( $source_id, 'created', [ 'post_title' => 'Original Title', 'post_name' => 'stale-slug' ] );
		// Second (retried) attempt's wp_update_post() call fails — recorded 'failed', but the
		// IdMap entry from the first attempt is untouched, so this source_id genuinely landed.
		$this->record_post_entry( $source_id, 'failed', [ 'post_title' => 'Updated Title That Never Landed', 'post_name' => 'stale-slug' ] );

		AuditComparator::compare_batch( $this->jid, 0 );
		$result = $this->get_result( $source_id );

		$this->assertTrue( $result['comparable'], 'A source_id with a real (non-zero) dest_id must still be compared, even if its LATEST write-trail entry outcome is "failed".' );
		$this->assertNotSame( 'source_write_failed', $result['reason'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Nothing to compare: no IdMap posts at all, or a missing site job.
	// -------------------------------------------------------------------------

	public function test_compare_batch_returns_null_when_nothing_to_compare(): void {
		$this->assertNull( AuditComparator::compare_batch( $this->jid, 0 ) );
	}

	public function test_compare_batch_returns_null_for_missing_site_job(): void {
		$this->assertNull( AuditComparator::compare_batch( 999999999, 0 ) );
	}

	// -------------------------------------------------------------------------
	// Found during code review: if the site job's status has moved on from 'complete' (sync was
	// enabled, or sync was finalized/cleared and the report was already deleted) since this
	// comparison run started, compare_batch()/process() must bail rather than diff against a
	// possibly sync-modified destination or resurrect a deleted report post.
	// -------------------------------------------------------------------------

	public function test_compare_batch_bails_when_site_job_is_no_longer_complete(): void {
		$source_id = 903;
		$dest_id   = $this->create_dest_post();
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created' );

		// Simulate sync having been enabled for this site job since the comparison was enqueued.
		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'syncing' ] );

		$this->assertNull( AuditComparator::compare_batch( $this->jid, 0 ), 'compare_batch() must bail (return null) once the site job is no longer complete.' );
		$this->assertSame( [], AuditComparator::get_post_comparison_results( $this->jid ), 'No comparison result should be stored once the site job has moved on from complete.' );
	}

	public function test_process_does_not_render_summary_when_site_job_is_no_longer_complete(): void {
		$source_id = 904;
		$dest_id   = $this->create_dest_post();
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created' );

		// Simulate sync being enabled (or the report being deleted via sync finalize) in the
		// narrow window between the last compare_batch() call and process()'s render step.
		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'syncing' ] );

		AuditComparator::process( $this->jid, 0 );

		$content = $this->get_report_content();
		$this->assertSame( '', $content, 'process() must not render a summary once the site job has moved on from complete.' );
	}

	// -------------------------------------------------------------------------
	// Critical, non-obvious behavior: a forced internal failure (a deliberately malformed
	// cached entry) never throws out of compare_batch() and never alters the site job's own
	// status column.
	// -------------------------------------------------------------------------

	public function test_internal_failure_does_not_throw_and_does_not_alter_site_job_status(): void {
		$source_id = 801;
		$dest_id   = $this->create_dest_post( [ 'post_title' => 'Fine', 'post_name' => 'fine' ] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );

		// 'meta' corrupted to a non-array value — normalize_expected_meta()'s `array $meta`
		// type hint turns this into a real TypeError when the comparator reaches it.
		AuditReport::record( $this->jid, 'site_job', [
			'type'          => 'write',
			'object_type'   => 'post',
			'source_id'     => $source_id,
			'outcome'       => 'created',
			'post_content'  => 'x',
			'post_excerpt'  => '',
			'meta'          => 'not-an-array',
			'source_author' => '',
			'post_name'     => 'fine',
			'post_type'     => 'post',
			'post_title'    => 'Fine',
		] );

		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'complete' ] );

		$log_file = tempnam( sys_get_temp_dir(), 'hbm_audit_comparator_test_log' );
		$prev_log = ini_set( 'error_log', $log_file );

		try {
			$checkpoint = AuditComparator::compare_batch( $this->jid, 0 );
		} finally {
			ini_set( 'error_log', $prev_log );
		}

		$this->assertNull( $checkpoint, 'An internal failure must return null — never rethrow, never loop forever on the same poison batch.' );

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 'complete', $job->status, "compare_batch() must never alter the site job's own status column." );

		$log_contents = file_get_contents( $log_file );
		unlink( $log_file );
		$this->assertStringContainsString( 'AuditComparator::compare_batch()', $log_contents, 'The failure must be observable via the logged message even though it never propagated.' );
	}

	// -------------------------------------------------------------------------
	// compute_counts(): media/taxonomy-term counts, failed attempts excluded from landed counts.
	// -------------------------------------------------------------------------

	public function test_compute_counts_reflects_actual_destination_rows_and_excludes_failures(): void {
		IdMap::set( $this->jid, 'attachment', 501, $this->create_dest_attachment() );
		IdMap::set( $this->jid, 'attachment', 502, $this->create_dest_attachment() );
		$this->record_attachment_entry( 501, 'created' );
		$this->record_attachment_entry( 502, 'created' );
		$this->record_attachment_entry( 503, 'failed' ); // Never landed — no IdMap entry.

		$t1 = wp_insert_term( 'Term One ' . wp_generate_password( 6, false ), 'category' );
		$t2 = wp_insert_term( 'Term Two ' . wp_generate_password( 6, false ), 'category' );
		IdMap::set( $this->jid, 'term', 601, (int) $t1['term_id'] );
		IdMap::set( $this->jid, 'term', 602, (int) $t2['term_id'] );
		$this->record_term_entry( 601, 'created', (int) $t1['term_id'] );
		$this->record_term_entry( 602, 'matched', (int) $t2['term_id'] );
		$this->record_term_entry( 603, 'failed' ); // Never landed — no IdMap entry.

		$counts = AuditComparator::compute_counts( $this->jid );

		$this->assertSame( 3, $counts['attachment']['attempted'] );
		$this->assertSame( 2, $counts['attachment']['landed'], 'A failed attachment attempt must not inflate the landed count.' );
		$this->assertSame( 1, $counts['attachment']['failed'] );
		$this->assertSame( 2, $counts['attachment']['id_map_count'] );
		$this->assertGreaterThanOrEqual( 2, $counts['attachment']['destination_actual'] );

		$this->assertSame( 3, $counts['term']['attempted'] );
		$this->assertSame( 2, $counts['term']['landed'], 'A failed term attempt must not inflate the landed count.' );
		$this->assertSame( 1, $counts['term']['failed'] );
		$this->assertSame( 2, $counts['term']['id_map_count'] );
		$this->assertArrayHasKey( 'category', $counts['term']['by_taxonomy'] );
		$this->assertGreaterThanOrEqual( 2, $counts['term']['by_taxonomy']['category'] );
	}

	// -------------------------------------------------------------------------
	// Covers the plan's flow analysis: the counting baseline is what was attempted (write
	// trail + IdMap), never a fresh "everything visible on source" query — unrelated
	// destination rows with no trail entry don't get folded into 'attempted'/'landed'.
	// -------------------------------------------------------------------------

	public function test_counts_baseline_is_what_was_attempted_not_everything_on_destination_or_source(): void {
		IdMap::set( $this->jid, 'attachment', 701, $this->create_dest_attachment() );
		$this->record_attachment_entry( 701, 'created' );

		// An unrelated attachment with no corresponding trail entry at all (e.g. pre-existing
		// destination content, or a scope this migration never attempted) — must not be
		// folded into 'attempted'/'landed', only reflected in the raw 'destination_actual' count.
		$this->create_dest_attachment();

		$counts = AuditComparator::compute_counts( $this->jid );

		$this->assertSame( 1, $counts['attachment']['attempted'] );
		$this->assertSame( 1, $counts['attachment']['landed'] );
		$this->assertSame( 1, $counts['attachment']['id_map_count'] );
		$this->assertSame( 2, $counts['attachment']['destination_actual'], 'destination_actual is a raw count, independent of what was attempted.' );
	}

	// -------------------------------------------------------------------------
	// U7: AuditComparator::process() — self-chaining orchestration — and
	// AuditReport::render_summary() — R6 report rendering. See docs/plans/
	// 2026-07-28-001-feat-migration-audit-report-plan.md, "U7. Report rendering and comparator
	// wiring".
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Happy path: a single-batch process() run (compare_batch() returns null on the first call)
	// renders counts, then flagged posts, then full detail, in that order. Zero flagged posts
	// renders a clear "No divergence found." state rather than an empty/missing section.
	// -------------------------------------------------------------------------

	public function test_process_single_batch_renders_ordered_summary_with_no_divergence(): void {
		$source_id = 2001;
		$dest_id   = $this->create_dest_post( [
			'post_title'   => 'Clean Post',
			'post_name'    => 'clean-post',
			'post_content' => 'All good.',
		] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_title'   => 'Clean Post',
			'post_name'    => 'clean-post',
			'post_content' => 'All good.',
		] );

		AuditComparator::process( $this->jid, 0 );

		$content = $this->get_report_content();

		$counts_pos  = strpos( $content, '=== Counts ===' );
		$flagged_pos = strpos( $content, '=== Flagged Posts ===' );
		$detail_pos  = strpos( $content, '=== Full Detail ===' );

		$this->assertNotFalse( $counts_pos, 'Rendered summary must contain a counts section.' );
		$this->assertNotFalse( $flagged_pos, 'Rendered summary must contain a flagged-posts section.' );
		$this->assertNotFalse( $detail_pos, 'Rendered summary must contain a full-detail section.' );
		$this->assertLessThan( $flagged_pos, $counts_pos, 'Counts must render before flagged posts (R6).' );
		$this->assertLessThan( $detail_pos, $flagged_pos, 'Flagged posts must render before full detail (R6).' );
		$this->assertStringContainsString( 'No divergence found.', $content, 'Zero flagged posts must render a clear "no divergence" state, not an empty section.' );
	}

	// -------------------------------------------------------------------------
	// Every flagged post — comparable-and-diverged (with diverged_fields) and
	// not-comparable-but-diverged (e.g. destination_post_missing, with its reason) — is listed
	// in the flagged section with the right detail.
	// -------------------------------------------------------------------------

	public function test_flagged_section_lists_diverged_fields_or_reason_for_each_flagged_post(): void {
		$sid_title = 2101;
		$dest_id   = $this->create_dest_post( [ 'post_title' => 'Dest Title', 'post_name' => 'flag-title' ] );
		IdMap::set( $this->jid, 'post', $sid_title, $dest_id );
		$this->record_post_entry( $sid_title, 'created', [ 'post_title' => 'Source Title', 'post_name' => 'flag-title' ] );

		$sid_missing = 2102;
		IdMap::set( $this->jid, 'post', $sid_missing, 9999999 ); // No such destination post.
		$this->record_post_entry( $sid_missing, 'created', [ 'post_title' => 'Ghost', 'post_name' => 'ghost' ] );

		AuditComparator::process( $this->jid, 0 );

		$content        = $this->get_report_content();
		$flagged_start  = strpos( $content, '=== Flagged Posts ===' );
		$detail_start   = strpos( $content, '=== Full Detail ===' );
		$flagged_section = substr( $content, $flagged_start, $detail_start - $flagged_start );

		$this->assertStringContainsString( "source_id={$sid_title}", $flagged_section );
		$this->assertStringContainsString( 'diverged_fields=[title]', $flagged_section );
		$this->assertStringContainsString( "source_id={$sid_missing}", $flagged_section );
		$this->assertStringContainsString( 'destination_post_missing', $flagged_section );
		$this->assertStringNotContainsString( 'No divergence found.', $flagged_section );
	}

	// -------------------------------------------------------------------------
	// Edge case: a site job whose post count exceeds the inline-render cap still shows a
	// complete counts/flagged section, with the full-detail section truncated and annotated.
	// -------------------------------------------------------------------------

	public function test_full_detail_section_is_capped_and_annotated_when_post_count_exceeds_cap(): void {
		add_filter( 'hbm_audit_report_detail_cap', static fn() => 2 );

		$ids = [ 2201, 2202, 2203, 2204 ];
		foreach ( $ids as $sid ) {
			$dest_id = $this->create_dest_post( [ 'post_title' => "Post {$sid}", 'post_name' => "cap-post-{$sid}" ] );
			IdMap::set( $this->jid, 'post', $sid, $dest_id );
			$this->record_post_entry( $sid, 'created', [ 'post_title' => "Post {$sid}", 'post_name' => "cap-post-{$sid}" ] );
		}

		AuditComparator::process( $this->jid, 0 );

		$post_id = AuditReport::get_or_create_for_site_job( $this->jid );
		$content = $this->get_report_content();

		// Counts and flagged sections are never subject to the cap — only full detail is.
		$this->assertStringContainsString( '=== Counts ===', $content );
		$this->assertStringContainsString( 'No divergence found.', $content );
		$this->assertStringContainsString( "showing 2 of 4 posts", $content, 'Full-detail section must be truncated to the forced cap.' );
		$this->assertStringContainsString( "wp post meta list {$post_id}", $content, 'Truncation note must point at the uncapped wp post meta list fallback.' );

		// The complete per-post detail is never capped in storage — only the inline render is.
		$results = AuditComparator::get_post_comparison_results( $this->jid );
		$this->assertCount( 4, $results, 'Storage (postmeta) must never be capped — only the inline render.' );
	}

	// -------------------------------------------------------------------------
	// Sanitization: any rendered text originating from source content (expected/actual
	// title/slug) is esc_html()-escaped before being embedded in post_content.
	// -------------------------------------------------------------------------

	public function test_source_derived_title_is_escaped_in_rendered_report(): void {
		$source_id = 2301;
		$dest_id   = $this->create_dest_post( [
			'post_title' => '<b>Dest</b> Title',
			'post_name'  => 'escape-slug',
		] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_title' => '<script>alert(1)</script>',
			'post_name'  => 'escape-slug',
		] );

		AuditComparator::process( $this->jid, 0 );

		$content = $this->get_report_content();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $content, 'Source-derived title must be esc_html()-escaped, never embedded as raw HTML.' );
		$this->assertStringContainsString( '&lt;script&gt;', $content, 'Escaped form of the diverging source title must still be present.' );
		$this->assertStringNotContainsString( '<b>Dest</b>', $content, 'Destination-side title must also be escaped before embedding.' );
	}

	public function test_backslash_in_source_derived_title_survives_render(): void {
		$source_id = 2302;
		$dest_id   = $this->create_dest_post( [
			'post_title' => 'Dest Title',
			'post_name'  => 'backslash-slug',
		] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [
			'post_title' => 'C:\\Windows\\Path and a \' quote',
			'post_name'  => 'backslash-slug',
		] );

		AuditComparator::process( $this->jid, 0 );

		$content = $this->get_report_content();

		$this->assertStringContainsString(
			'C:\\Windows\\Path',
			$content,
			'wp_update_post() only wp_slash()\'s automatically for object input, not a plain array — render_summary() must slash its own array input or wp_unslash() further downstream silently strips backslashes.'
		);
	}

	// -------------------------------------------------------------------------
	// Integration: a comparison spanning multiple self-chained process() invocations only
	// renders the final summary once, after the last batch completes — not after every
	// intermediate batch. Also verifies process() actually self-enqueues a continuation when
	// compare_batch() returns a non-null checkpoint.
	// -------------------------------------------------------------------------

	public function test_multibatch_process_renders_final_summary_only_once(): void {
		add_filter( 'hbm_audit_compare_batch_size', static fn() => 1 );
		add_filter( 'hbm_audit_compare_time_limit', static fn() => -1.0 );

		$ids = [ 2401, 2402, 2403 ];
		foreach ( $ids as $sid ) {
			$dest_id = $this->create_dest_post( [ 'post_title' => "Multi {$sid}", 'post_name' => "proc-multi-{$sid}" ] );
			IdMap::set( $this->jid, 'post', $sid, $dest_id );
			$this->record_post_entry( $sid, 'created', [ 'post_title' => "Multi {$sid}", 'post_name' => "proc-multi-{$sid}" ] );
		}

		// First call: batch_size=1 + an always-exceeded time budget means compare_batch()
		// processes exactly one item and returns a checkpoint — process() must self-enqueue a
		// continuation, not render.
		AuditComparator::process( $this->jid, 0 );
		$this->assertSame( '', $this->get_report_content(), 'post_content must remain unset after the first (non-final) batch.' );

		$scheduled = as_get_scheduled_actions( [
			'hook'     => 'hbm_audit_compare',
			'args'     => [ 'site_job_id' => $this->jid, 'last_pk' => $ids[0] ],
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 5,
		] );
		$this->assertCount( 1, $scheduled, 'process() must self-enqueue exactly one continuation when compare_batch() returns a checkpoint.' );

		// Second call: still one item left after this one — must still self-enqueue, not render.
		AuditComparator::process( $this->jid, $ids[0] );
		$this->assertSame( '', $this->get_report_content(), 'post_content must remain unset after an intermediate batch.' );

		// Third call drains the LAST item still remaining (2403) — but compare_batch()'s own
		// time-budget check runs unconditionally after every processed batch (see that method),
		// so it still returns a (non-null) checkpoint here even though every item has now been
		// processed once — matching this same file's own
		// test_budget_exceeded_returns_checkpoint_and_resumes_without_double_processing, which
		// requires MORE calls than there are items for exactly this reason. Must still
		// self-enqueue, not render.
		AuditComparator::process( $this->jid, $ids[1] );
		$this->assertSame( '', $this->get_report_content(), 'post_content must remain unset after the batch that processed the last item, since compare_batch() still returned a non-null checkpoint.' );

		// Fourth call: remaining_ids is now genuinely empty — compare_batch() returns null. This
		// is the ONLY call that must render the final summary.
		AuditComparator::process( $this->jid, $ids[2] );
		$content = $this->get_report_content();
		$this->assertStringContainsString( '=== Counts ===', $content, 'Final summary must be rendered exactly once, after the last batch.' );

		remove_all_filters( 'hbm_audit_compare_batch_size' );
		remove_all_filters( 'hbm_audit_compare_time_limit' );
	}

	// -------------------------------------------------------------------------
	// Integration: SearchReplace::finalize() enqueues hbm_audit_compare exactly once per site
	// job reaching complete, and never calls the comparator synchronously — finalize() itself
	// must complete regardless of how long the (enqueued, not inline) comparison run takes.
	// -------------------------------------------------------------------------

	public function test_search_replace_finalize_enqueues_audit_compare_exactly_once(): void {
		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'running' ] );

		$ref    = new ReflectionClass( SearchReplace::class );
		$method = $ref->getMethod( 'finalize' );
		$method->setAccessible( true );

		global $wpdb;
		// finalize() also runs remap_postmeta_ids()'s MySQL-only UPDATE...JOIN (see
		// test-search-replace.php's own precedent of skipping assertions on that query's effect
		// under the SQLite test driver) — harmless here since this test only asserts on the
		// hbm_audit_compare enqueue, not the thumbnail remap itself. Suppressed purely to keep
		// this test's output free of an unrelated, expected-under-SQLite query error dump.
		$suppress = $wpdb->suppress_errors( true );
		try {
			$method->invoke( null, $this->jid, $this->mid, get_current_blog_id() );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$scheduled = as_get_scheduled_actions( [
			'hook'     => 'hbm_audit_compare',
			'args'     => [ 'site_job_id' => $this->jid, 'last_pk' => 0 ],
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 10,
		] );
		$this->assertCount( 1, $scheduled, 'finalize() must enqueue hbm_audit_compare exactly once per site job reaching complete.' );

		// finalize() must never call the comparator synchronously — only the action is
		// enqueued, no comparison work has actually run yet.
		$this->assertSame( [], AuditComparator::get_post_comparison_results( $this->jid ), 'finalize() must not synchronously invoke compare_batch()/process() — comparison only runs once the enqueued action executes.' );
	}

	// -------------------------------------------------------------------------
	// Found during code review: SearchReplace::finalize()'s hbm_audit_compare enqueue call sits
	// inside SearchReplace::process()'s own outer try/catch, which feeds
	// PipelineController::handle_batch_failure() on any \Throwable. If the enqueue itself throws
	// (e.g. a transient Action Scheduler/DB error) and isn't independently contained, that
	// failure could eventually regress an already-'complete' site job back to 'failed' — exactly
	// the cascade this whole feature is designed to prevent. Verifies finalize() itself survives
	// (and the migration still completes) even when the enqueue call throws.
	// -------------------------------------------------------------------------

	public function test_finalize_survives_a_forced_audit_compare_enqueue_failure(): void {
		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'running' ] );
		MigrationRegistry::update_migration_status( $this->mid, 'running' );

		add_filter( 'pre_as_enqueue_async_action', function ( $pre, $hook ) {
			if ( 'hbm_audit_compare' === $hook ) {
				throw new \RuntimeException( 'Simulated Action Scheduler enqueue failure.' );
			}
			return $pre;
		}, 10, 2 );

		$ref    = new ReflectionClass( SearchReplace::class );
		$method = $ref->getMethod( 'finalize' );
		$method->setAccessible( true );

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		try {
			// The forced RuntimeException above must not propagate out of finalize() itself.
			$method->invoke( null, $this->jid, $this->mid, get_current_blog_id() );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_all_filters( 'pre_as_enqueue_async_action' );
		}

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 'complete', $job->status, 'A failure enqueuing hbm_audit_compare must never regress the site job away from complete.' );

		$migration = MigrationRegistry::get_migration( $this->mid );
		$this->assertSame( 'complete', $migration->status, 'finalize() must still complete the migration even when the audit-compare enqueue fails.' );

		$scheduled = as_get_scheduled_actions( [
			'hook'     => 'hbm_audit_compare',
			'args'     => [ 'site_job_id' => $this->jid, 'last_pk' => 0 ],
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 10,
		] );
		$this->assertCount( 0, $scheduled, 'No action should have actually been enqueued given the forced failure.' );
	}

	// -------------------------------------------------------------------------
	// Critical, non-obvious behavior: a forced internal failure during the count/render step
	// (wp_update_post() forced to fail) does not throw out of process() and does not alter the
	// site job's own status.
	// -------------------------------------------------------------------------

	public function test_forced_render_failure_does_not_throw_and_does_not_alter_site_job_status(): void {
		$source_id = 2501;
		$dest_id   = $this->create_dest_post( [ 'post_title' => 'Fine', 'post_name' => 'render-fail-fine' ] );
		IdMap::set( $this->jid, 'post', $source_id, $dest_id );
		$this->record_post_entry( $source_id, 'created', [ 'post_title' => 'Fine', 'post_name' => 'render-fail-fine' ] );

		// Report post already exists by this point (record_post_entry() above triggered lazy
		// creation) — this filter only needs to break the later wp_update_post() call inside
		// render_summary(), not the earlier wp_insert_post() that already succeeded.
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$log_file = tempnam( sys_get_temp_dir(), 'hbm_audit_render_summary_test_log' );
		$prev_log = ini_set( 'error_log', $log_file );

		try {
			AuditComparator::process( $this->jid, 0 );
		} finally {
			ini_set( 'error_log', $prev_log );
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		}

		$job = MigrationRegistry::get_site_job( $this->jid );
		$this->assertSame( 'complete', $job->status, "A forced render failure must never alter the site job's own status column." );

		// The comparison result itself must still have been stored — only the final render
		// step failed, not the comparison work compare_batch() already completed.
		$results = AuditComparator::get_post_comparison_results( $this->jid );
		$this->assertArrayHasKey( $source_id, $results );

		$log_contents = file_get_contents( $log_file );
		unlink( $log_file );
		$this->assertStringContainsString( 'render_summary()', $log_contents, 'The render failure must be observable via the logged message even though it never propagated.' );
	}

	// -------------------------------------------------------------------------
	// U2 hardening (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U2. Shared
	// write-trail contract: entry normalization and author resolution"). normalize_write_entry()
	// is the single point of truth for the field defaults compare_post() applies to a raw
	// cached write-trail entry — this is a pure internal refactor, so the rest of this file's
	// existing tests (run unchanged, above) are the regression net for compare_post() itself;
	// this test targets normalize_write_entry() directly.
	// -------------------------------------------------------------------------

	public function test_normalize_write_entry_fills_in_every_documented_default_for_a_sparse_entry(): void {
		$ref    = new ReflectionClass( AuditComparator::class );
		$method = $ref->getMethod( 'normalize_write_entry' );
		$method->setAccessible( true );

		$normalized = $method->invoke( null, [] );

		$this->assertSame( '', $normalized['post_content'] );
		$this->assertSame( '', $normalized['post_excerpt'] );
		$this->assertSame( '', $normalized['post_title'] );
		$this->assertSame( '', $normalized['post_name'] );
		$this->assertSame( '', $normalized['source_author'] );
		$this->assertArrayNotHasKey(
			'meta',
			$normalized,
			"normalize_write_entry() must leave 'meta' untouched for an entry that never had it — no default, no type coercion — per normalize_expected_meta()'s own array-type-hint contract."
		);
	}

	// -------------------------------------------------------------------------
	// U2 (R4): integration proof that PostImporter::import_batch() and
	// AuditComparator::compare_post() now share ONE author-resolution method
	// (PostImporter::resolve_author_id()), not just similarly-shaped inline code. Drives
	// import_batch() itself (not a hand-built write-trail entry) so a merge-resolved author only
	// reports as matched because both call sites resolve it identically.
	// -------------------------------------------------------------------------

	public function test_authorship_resolved_by_import_batch_is_reported_matched_via_shared_resolve_author_id(): void {
		$existing_user_id = self::factory()->user->create( [ 'user_email' => 'shared-author@old-site.example.com' ] );
		$this->assertNotSame( 1, $existing_user_id, 'Precondition: the resolved user must not be the fallback ID 1.' );

		// import_batch()'s write-trail recording is gated on the site job's status being
		// pending/running (see PostImporter::import_batch()'s $should_audit) — make_site_job()
		// sets 'complete', so switch to 'pending' for the import, then back to 'complete' so the
		// comparator (which requires 'complete') will actually run.
		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'pending' ] );

		$source_id = 950;
		PostImporter::import_batch( $this->jid, [ [
			'ID'                => $source_id,
			'post_author_email' => 'shared-author@old-site.example.com',
			'post_date'         => '2024-01-01 00:00:00',
			'post_date_gmt'     => '2024-01-01 00:00:00',
			'post_content'      => 'Shared author content.',
			'post_title'        => 'Shared Author Post',
			'post_excerpt'      => '',
			'post_status'       => 'publish',
			'comment_status'    => 'open',
			'ping_status'       => 'open',
			'post_password'     => '',
			'post_name'         => 'shared-author-post',
			'post_modified'     => '2024-01-01 00:00:00',
			'post_modified_gmt' => '2024-01-01 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_type'         => 'post',
			'post_mime_type'    => '',
			'comment_count'     => 0,
			'meta'              => [],
			'terms'             => [],
		] ] );

		MigrationRegistry::update_site_job( $this->jid, [ 'status' => 'complete' ] );

		$this->assertNull( AuditComparator::compare_batch( $this->jid, 0 ) );

		$result = $this->get_result( $source_id );
		$this->assertTrue(
			$result['authorship_match'],
			'An author resolved by PostImporter::import_batch() via the shared resolve_author_id() must be reported as matched by AuditComparator::compare_post() calling the SAME method.'
		);
		$this->assertSame( $existing_user_id, $result['expected_author_id'] );
		$this->assertSame( $existing_user_id, $result['actual_author_id'] );
		$this->assertFalse( $result['diverged'] );
	}
}
