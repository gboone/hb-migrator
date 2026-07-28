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
}
