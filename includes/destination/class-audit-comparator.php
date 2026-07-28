<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;

/**
 * U6: the core diffing logic for the migration audit report (see
 * docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U6. Comparator: hashing,
 * normalization, and count comparison"). Given the cached write-action trail data for a
 * completed site job (recorded by U4/U5 into AuditReport's `_hbm_audit_write` postmeta), this
 * class determines what genuinely diverged between source and destination:
 *
 * - `compare_batch()` — a bounded, resumable, per-post pass (slug/authorship/content-hash/
 *   postmeta-hash), mirroring SearchReplace::run_phase()'s own TIME_LIMIT/keyset-cursor/
 *   checkpoint-return shape exactly (see class-search-replace.php). Re-applies the same
 *   URL-rewrite replacement map and `_thumbnail_id` IdMap remap SearchReplace itself already
 *   applied, so only genuine unexpected drift is flagged (R5).
 * - `compute_counts()` — a separate, cheap, non-batched aggregate count comparison for
 *   media/taxonomy terms (R3).
 *
 * This unit's job ends at producing the comparison DATA — turning it into the rendered report
 * (high-level counts, then flagged posts, then full detail) and wiring the Action Scheduler
 * self-chaining entry point is U7's job, not this class's, per the plan's unit boundary.
 *
 * Failure containment (critical, non-negotiable — see plan "Key Technical Decisions" and
 * AuditReport's identical shape, which this mirrors exactly): `compare_batch()` and
 * `compute_counts()` each wrap their entire body in try/catch (\Throwable) and never rethrow.
 * A later unit (U7, not this one) wires `compare_batch()` into a self-chained Action Scheduler
 * action — an uncaught exception here could eventually let AS's own retry/failure machinery
 * mark an already-`complete` site job `failed` purely because comparing it hit a bug. Returning
 * `null` from `compare_batch()` on internal failure is a deliberate, safe choice documented at
 * that method: it makes the caller (U7's self-chaining loop) believe the batch is "done" rather
 * than looping forever retrying the same poison batch, at the cost of that one run's comparison
 * data being silently incomplete for whatever this checkpoint didn't reach. This try/catch only
 * guards against thrown exceptions, not a PHP execution-time/memory fatal — which is exactly why
 * this method is bounded/checkpointed rather than a single unbounded pass over every post.
 */
class AuditComparator {

	// How many seconds to spend per compare_batch() call before checkpointing. Mirrors
	// SearchReplace::TIME_LIMIT's own rationale (VIP AS runner has no hard per-action wall-clock
	// kill, but this leaves headroom). Filterable (see time_limit()) so tests can force the
	// budget-exceeded checkpoint path deterministically instead of relying on wall-clock timing.
	private const TIME_LIMIT = 50.0;

	// Deliberately smaller than SearchReplace::run_phase()'s 200-row batch — SearchReplace's
	// batches are cheap string replacements, whereas each item here does two hashes plus a
	// destination row/postmeta re-read and a get_user_by() lookup, meaningfully heavier
	// per-item work. Filterable (see batch_size()) for the same testability reason as the time
	// limit above.
	private const BATCH_SIZE = 50;

	/**
	 * postmeta key (NOT unique — add_post_meta(..., false), one row per post compared, mirroring
	 * AuditReport's own many-rows-per-key convention) storing this unit's own per-post comparison
	 * results on the report post. This is new storage this unit introduces itself (rather than
	 * extending AuditReport) — see this file's class docblock and the accompanying report note:
	 * AuditReport owns the *request/write trail* (what the migration did); this is the
	 * *comparator's own derived output* (what a later read of the destination found), a
	 * different concern with its own lifecycle, so it gets its own meta key rather than being
	 * folded into `_hbm_audit_write` (which would also corrupt this class's own "latest write
	 * entry per source_id" lookup on any later re-run, since a comparison result and a write
	 * entry would then be indistinguishable when scanning by object_type).
	 */
	private const META_RESULT = '_hbm_audit_compare_result';

	/**
	 * WordPress core itself writes these two postmeta keys as a side effect of wp_insert_post()
	 * on any newly-published post with ping_status 'open' (see _publish_post_hook() in
	 * wp-includes/post.php) — not something the migration pipeline controls, and not reliably
	 * present in the cached source snapshot either (they're transient, normally cleared once
	 * wp-cron processes pingbacks/enclosures, which may have already happened on source by
	 * export time but not yet on a freshly-imported destination row). Left uncompared, this
	 * would be a near-universal false-positive postmeta mismatch on almost every published post,
	 * unrelated to migration correctness — discovered via this unit's own "happy path" test.
	 * Excluded from postmeta hashing on both the expected and actual side. This is the one
	 * deliberate exclusion this class makes beyond SearchReplace's own normalization scope (see
	 * final report) — everything else in postmeta is still compared as-is.
	 */
	private const IGNORED_META_KEYS = [ '_pingme', '_encloseme' ];

	/**
	 * Compares every post the initial migration landed for this site job (per IdMap's
	 * authoritative source_id => dest_id map, plus any permanently-failed source_ids the write
	 * trail recorded — see load_driving_post_map()) against the destination's current actual
	 * row, hashing content/postmeta after re-applying the same URL-rewrite and `_thumbnail_id`
	 * remap SearchReplace itself already used. Bounded to TIME_LIMIT seconds per call; resumes
	 * from $last_pk (a source_id keyset cursor) on the next call.
	 *
	 * @param int $site_job_id
	 * @param int $last_pk      Resume cursor — only source_ids greater than this are considered.
	 * @return int|null  null = every post already IdMap-landed for this job has been compared
	 *                    (or there was nothing to compare, or an internal failure occurred —
	 *                    see class docblock); int = last source_id processed, budget exceeded
	 *                    mid-pass, resume from here.
	 */
	public static function compare_batch( int $site_job_id, int $last_pk ): ?int {
		try {
			return self::compare_batch_inner( $site_job_id, $last_pk );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditComparator::compare_batch() failed for site job ' . $site_job_id . ' at last_pk ' . $last_pk . ': ' . $e->getMessage() );
			// Deliberate — see class docblock: return null (not $last_pk) so a self-chaining
			// caller treats this as "done" rather than retrying the same failing checkpoint
			// forever. Never touches hbm_site_jobs.status either way.
			return null;
		}
	}

	private static function compare_batch_inner( int $site_job_id, int $last_pk ): ?int {
		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return null;
		}

		$driving_map = self::load_driving_post_map( $site_job_id );
		if ( empty( $driving_map ) ) {
			return null;
		}

		$remaining_ids = array_values( array_filter(
			array_keys( $driving_map ),
			static fn( $sid ) => $sid > $last_pk
		) );
		if ( empty( $remaining_ids ) ) {
			return null;
		}

		$replacements   = self::build_replacements( $job );
		$attachment_map = IdMap::get_all_for_job( $site_job_id, 'attachment' );
		// Built once per compare_batch() call, not cached across calls — an accepted tradeoff
		// (see final report): re-reads every _hbm_audit_write row for this report post on every
		// call, which is the cost of not introducing any new cross-call persistence beyond this
		// unit's own scope.
		$write_entries = self::load_latest_write_entries( $site_job_id, 'post' );

		$started    = microtime( true );
		$time_limit = self::time_limit();
		$batch_size = self::batch_size();
		$last_processed = $last_pk;
		$cursor     = 0;
		$total      = count( $remaining_ids );

		while ( $cursor < $total ) {
			$batch_ids = array_slice( $remaining_ids, $cursor, $batch_size );
			$cursor   += count( $batch_ids );

			foreach ( $batch_ids as $source_id ) {
				$dest_id = $driving_map[ $source_id ];
				$entry   = $write_entries[ $source_id ] ?? null;

				$result = self::compare_post( $job, $source_id, $dest_id, $entry, $replacements, $attachment_map );
				self::store_result( $site_job_id, $result );

				$last_processed = $source_id;
			}

			if ( ( microtime( true ) - $started ) > $time_limit ) {
				return $last_processed; // Budget exceeded — checkpoint here.
			}
		}

		return null; // Every id in the driving map has now been compared.
	}

	/**
	 * The keyset-paginated "table" compare_batch() scans, sorted ascending for a stable cursor.
	 * IdMap::get_all_for_job() is the authoritative, deduplicated "what actually landed" list
	 * (a source_id only appears here once it has a real destination row — see plan's IdMap
	 * grounding), so it is the base of this map. A source_id whose *latest* write-trail entry is
	 * 'failed' never gets an IdMap entry at all (PostImporter only calls IdMap::set() on
	 * success) — but the plan explicitly wants failed attempts surfaced as "not comparable/
	 * failed to import" rather than silently dropped (see compare_post()). Reconciling both:
	 * failed-only source_ids (no IdMap entry, latest outcome 'failed') are added to the driving
	 * map with a dest_id of 0 (no destination row exists to compare against), merged into the
	 * same sorted keyset so a single cursor covers both landed and permanently-failed items in
	 * one deterministic pass. This is a judgment call beyond the plan's literal prose — flagged
	 * in the final report.
	 */
	private static function load_driving_post_map( int $site_job_id ): array {
		$landed = IdMap::get_all_for_job( $site_job_id, 'post' );

		$write_entries = self::load_latest_write_entries( $site_job_id, 'post' );
		$failed        = [];
		foreach ( $write_entries as $source_id => $entry ) {
			if ( ! isset( $landed[ $source_id ] ) && 'failed' === (string) ( $entry['outcome'] ?? '' ) ) {
				$failed[ $source_id ] = 0;
			}
		}

		$driving = $landed + $failed;
		ksort( $driving, SORT_NUMERIC );
		return $driving;
	}

	/**
	 * Compares one post: source_id's cached write-trail entry (the "expected, normalized"
	 * source of truth) against dest_id's current actual destination row.
	 *
	 * @param object     $job             The site job row (MigrationRegistry::get_site_job()).
	 * @param int        $source_id
	 * @param int        $dest_id         0 if this source_id never landed (failed import).
	 * @param array|null $entry           The latest cached _hbm_audit_write entry for this
	 *                                    source_id, or null if none was ever recorded (an
	 *                                    audit-layer gap, not a pipeline failure — see below).
	 * @param array      $replacements    SearchReplace-equivalent URL replacement map.
	 * @param array      $attachment_map  IdMap::get_all_for_job( $site_job_id, 'attachment' ).
	 */
	private static function compare_post( object $job, int $source_id, int $dest_id, ?array $entry, array $replacements, array $attachment_map ): array {
		$base = [ 'source_id' => $source_id, 'dest_id' => $dest_id ];

		if ( null === $entry ) {
			// No cached entry at all for a source_id that (per the driving map) either landed
			// or was recorded failed — can only happen if AuditReport::record() itself silently
			// swallowed a failure for this exact item while IdMap::set() (a separate, unguarded
			// DB call) still succeeded. Not crashing or miscounting: surfaced as not comparable.
			return $base + [
				'comparable' => false,
				'diverged'   => false,
				'reason'     => 'no_cached_write_entry',
			];
		}

		$outcome = (string) ( $entry['outcome'] ?? '' );
		if ( 'failed' === $outcome ) {
			// Nothing landed on destination for this item — surfaced as "not comparable /
			// failed to import" per the plan, not silently dropped, but not hashed either.
			return $base + [
				'comparable' => false,
				'diverged'   => false,
				'reason'     => 'source_write_failed',
			];
		}

		// Expected (normalized) side — pure computation, no destination read yet.
		$expected_content   = SearchReplace::safe_replace( (string) ( $entry['post_content'] ?? '' ), $replacements );
		$expected_excerpt   = SearchReplace::safe_replace( (string) ( $entry['post_excerpt'] ?? '' ), $replacements );
		$expected_title     = (string) ( $entry['post_title'] ?? '' );
		$expected_slug      = (string) ( $entry['post_name'] ?? '' );
		// $entry['meta'] is intentionally NOT cast to array here — normalize_expected_meta()'s
		// `array $meta` type hint is the deliberate guard that turns a malformed cached entry
		// (e.g. 'meta' corrupted to a non-array) into a real \Throwable (TypeError), which
		// compare_batch()'s outer try/catch is exactly built to contain (see that method's own
		// docblock and this unit's "forced internal failure" test scenario).
		$expected_meta_list    = self::normalize_expected_meta( $entry['meta'] ?? [], $replacements, $attachment_map );
		$expected_content_hash = self::hash_content( (string) $expected_content, (string) $expected_excerpt );
		$expected_meta_hash    = self::hash_meta_list( $expected_meta_list );
		$source_author_email  = (string) ( $entry['source_author'] ?? '' );

		switch_to_blog( (int) $job->dest_blog_id );
		try {
			$post = get_post( $dest_id );
			if ( ! $post ) {
				return $base + [
					'comparable' => false,
					'diverged'   => true,
					'reason'     => 'destination_post_missing',
				];
			}

			$actual_title        = (string) $post->post_title;
			$actual_slug         = (string) $post->post_name;
			$actual_author_id    = (int) $post->post_author;
			$actual_content_hash = self::hash_content( (string) $post->post_content, (string) $post->post_excerpt );

			global $wpdb;
			$rows             = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$dest_id
			), ARRAY_A ) ?: [];
			$actual_meta_hash = self::hash_meta_list( self::to_actual_meta_list( $rows ) );

			// Authorship: re-derive the SAME resolution PostImporter::import_batch() itself
			// performs at write time (get_user_by('email', ...), fallback to user ID 1) rather
			// than a literal identity comparison. This correctly treats a user_conflict_policy:
			// merge resolution to a pre-existing destination user as a match — the resolution
			// is a pure function of the same inputs (source email + destination's current user
			// table), so re-running it here reproduces exactly what import_batch() decided,
			// without needing any new stored state (see plan grounding + final report).
			$expected_author_id = 1;
			if ( '' !== $source_author_email ) {
				$user = get_user_by( 'email', $source_author_email );
				if ( $user ) {
					$expected_author_id = (int) $user->ID;
				}
			}
		} finally {
			restore_current_blog();
		}

		$slug_match     = $expected_slug === $actual_slug;
		$title_match    = $expected_title === $actual_title;
		$content_match  = $expected_content_hash === $actual_content_hash;
		$postmeta_match = $expected_meta_hash === $actual_meta_hash;
		$author_match   = $expected_author_id === $actual_author_id;

		$diverged_fields = [];
		if ( ! $slug_match ) {
			$diverged_fields[] = 'slug';
		}
		if ( ! $title_match ) {
			$diverged_fields[] = 'title';
		}
		if ( ! $content_match ) {
			$diverged_fields[] = 'content_hash';
		}
		if ( ! $postmeta_match ) {
			$diverged_fields[] = 'postmeta_hash';
		}
		if ( ! $author_match ) {
			$diverged_fields[] = 'authorship';
		}

		return $base + [
			'comparable'          => true,
			'diverged'            => ! empty( $diverged_fields ),
			'diverged_fields'     => $diverged_fields,
			'slug_match'          => $slug_match,
			'expected_slug'       => $expected_slug,
			'actual_slug'         => $actual_slug,
			'title_match'         => $title_match,
			'expected_title'      => $expected_title,
			'actual_title'        => $actual_title,
			'content_hash_match'  => $content_match,
			'postmeta_hash_match' => $postmeta_match,
			'authorship_match'    => $author_match,
			'expected_author_id'  => $expected_author_id,
			'actual_author_id'    => $actual_author_id,
		];
	}

	/**
	 * Persists one post's comparison result onto the report post, in AuditReport's own
	 * primary-site-switching convention (see that class's record()) — but as this unit's own
	 * storage (META_RESULT), not routed through AuditReport, since this is the comparator's own
	 * derived output rather than a request/write trail entry (see class docblock).
	 */
	private static function store_result( int $site_job_id, array $result ): void {
		$post_id = AuditReport::get_or_create_for_site_job( $site_job_id );
		if ( ! $post_id ) {
			return;
		}

		switch_to_blog( get_main_site_id() );
		try {
			add_post_meta( $post_id, self::META_RESULT, $result, false );
			// Same rationale as AuditReport::append_entry(): a caller mid-loop (PostImporter's
			// own import_batch()) may have set wp_suspend_cache_invalidation( true ) earlier in
			// the same request — though by the time the comparator runs (after SearchReplace::
			// finalize()) that flag has long been restored, clean_post_cache() here costs
			// nothing and keeps this method consistent with the rest of the audit layer's
			// caching discipline.
			clean_post_cache( $post_id );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Reads back every per-post comparison result recorded for a site job, keyed by source_id
	 * (latest entry wins — see load_latest_write_entries() for why this dedup convention is used
	 * throughout this class). Public: this is the read surface U7 (not this unit) will use to
	 * render the final report, and what this unit's own tests use to assert compare_batch()'s
	 * effects (compare_batch() itself only returns a checkpoint, not the comparison data).
	 */
	public static function get_post_comparison_results( int $site_job_id ): array {
		try {
			$post_id = AuditReport::get_or_create_for_site_job( $site_job_id );
			if ( ! $post_id ) {
				return [];
			}

			switch_to_blog( get_main_site_id() );
			try {
				$rows = get_post_meta( $post_id, self::META_RESULT, false ) ?: [];
			} finally {
				restore_current_blog();
			}

			$latest = [];
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['source_id'] ) ) {
					continue;
				}
				$latest[ (int) $row['source_id'] ] = $row;
			}
			return $latest;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditComparator::get_post_comparison_results() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Aggregate, non-batched count comparison for media (attachments) and taxonomy terms (R3).
	 * Deliberately not part of compare_batch()'s checkpointed loop — this is cheap aggregate
	 * counting, not per-item hashing, so it always completes in one call (see plan U6 approach).
	 * The baseline for "attempted"/"landed" is what the write trail + IdMap actually recorded —
	 * never a fresh "everything visible on source" query — so a post-type or media scope the
	 * migration's own policy never attempted is correctly never counted as "missing" (see plan's
	 * flow-analysis test scenario).
	 *
	 * @return array{
	 *   attachment: array{attempted:int,landed:int,failed:int,id_map_count:int,destination_actual:int},
	 *   term: array{attempted:int,landed:int,failed:int,id_map_count:int,destination_actual:int,by_taxonomy:array<string,int>}
	 * }  Empty array on a missing site job/destination or an internal failure (see class docblock).
	 */
	public static function compute_counts( int $site_job_id ): array {
		try {
			return self::compute_counts_inner( $site_job_id );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditComparator::compute_counts() failed for site job ' . $site_job_id . ': ' . $e->getMessage() );
			return [];
		}
	}

	private static function compute_counts_inner( int $site_job_id ): array {
		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return [];
		}

		$dest_blog_id = (int) $job->dest_blog_id;

		$attachment_counts = self::compute_object_type_counts( $site_job_id, $dest_blog_id, 'attachment' );
		$term_counts        = self::compute_object_type_counts( $site_job_id, $dest_blog_id, 'term' );

		switch_to_blog( $dest_blog_id );
		try {
			$term_counts['by_taxonomy'] = self::count_destination_terms_by_taxonomy();
		} finally {
			restore_current_blog();
		}

		return [
			'attachment' => $attachment_counts,
			'term'       => $term_counts,
		];
	}

	/**
	 * Shared by compute_counts_inner() for both 'attachment' and 'term' — attempted/landed/
	 * failed tallies come from the write trail (deduped to each source_id's LATEST entry, same
	 * convention as load_driving_post_map()/compare_post() — a retried batch's duplicate entries
	 * must not double-count); 'id_map_count' is IdMap's own authoritative landed count (should
	 * agree with 'landed' in the normal case, kept separate as a cross-check since they are
	 * derived from two independently-written tables); 'destination_actual' is a fresh count of
	 * the destination's current rows, read while switched to the destination blog.
	 */
	private static function compute_object_type_counts( int $site_job_id, int $dest_blog_id, string $object_type ): array {
		$id_map        = IdMap::get_all_for_job( $site_job_id, $object_type );
		$write_entries = self::load_latest_write_entries( $site_job_id, $object_type );

		$attempted = count( $write_entries );
		$landed    = 0;
		$failed    = 0;
		foreach ( $write_entries as $entry ) {
			$outcome = (string) ( $entry['outcome'] ?? '' );
			if ( in_array( $outcome, [ 'created', 'updated', 'matched' ], true ) ) {
				++$landed;
			} elseif ( 'failed' === $outcome ) {
				++$failed;
			}
		}

		switch_to_blog( $dest_blog_id );
		try {
			$actual = 'attachment' === $object_type
				? self::count_destination_attachments()
				: self::count_destination_terms();
		} finally {
			restore_current_blog();
		}

		return [
			'attempted'          => $attempted,
			'landed'             => $landed,
			'failed'             => $failed,
			'id_map_count'       => count( $id_map ),
			'destination_actual' => $actual,
		];
	}

	private static function count_destination_attachments(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function count_destination_terms(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function count_destination_terms_by_taxonomy(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT taxonomy, COUNT(*) AS cnt FROM {$wpdb->term_taxonomy} GROUP BY taxonomy", ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row['taxonomy'] ] = (int) $row['cnt'];
		}
		return $out;
	}

	/**
	 * Builds the exact same URL-rewrite replacement map SearchReplace::process() itself builds
	 * (mirrored verbatim — see class-search-replace.php), so the comparator's "expected" side is
	 * computed from the same transform the real migration already applied. Switches to the
	 * destination blog only long enough to read siteurl/upload dir, matching process()'s own
	 * bracketing.
	 */
	private static function build_replacements( object $job ): array {
		switch_to_blog( (int) $job->dest_blog_id );
		try {
			$dest_siteurl    = get_option( 'siteurl' );
			$dest_upload_url = trailingslashit( wp_upload_dir()['baseurl'] );
		} finally {
			restore_current_blog();
		}

		$source_siteurl = rtrim( (string) $job->source_siteurl, '/' );
		$source_upload  = rtrim( (string) $job->source_upload_url, '/' );

		return array_filter( [
			$source_siteurl => rtrim( $dest_siteurl, '/' ),
			$source_upload  => rtrim( $dest_upload_url, '/' ),
		], static fn( $key ) => ! empty( $key ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Loads every _hbm_audit_write entry for $site_job_id (via AuditReport's U6 read-access
	 * addition — see that class), filters to $object_type, and keeps only the LATEST entry per
	 * source_id (rows are already in ascending meta_id/insertion order — see
	 * AuditReport::get_write_entries_for_site_job()'s docblock — so a later foreach iteration
	 * simply overwrites an earlier one for the same key). This is the single mechanism this
	 * class uses to resolve "a resumed/retried batch produced more than one trail entry for the
	 * same source_id" for posts, attachments, and terms alike — built once per compare_batch()/
	 * compute_counts() call, never cached across calls (see those methods' own docblocks for why
	 * that's an accepted tradeoff).
	 */
	private static function load_latest_write_entries( int $site_job_id, string $object_type ): array {
		$rows   = AuditReport::get_write_entries_for_site_job( $site_job_id );
		$latest = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ( $row['object_type'] ?? null ) !== $object_type ) {
				continue;
			}
			$source_id = (int) ( $row['source_id'] ?? 0 );
			if ( $source_id <= 0 ) {
				continue;
			}
			$latest[ $source_id ] = $row;
		}
		return $latest;
	}

	/**
	 * Normalizes a cached post's source meta list into the "expected" form to hash: every value
	 * passed through the same safe_replace() URL rewrite SearchReplace itself applies, EXCEPT
	 * `_thumbnail_id`, which is remapped via the attachment IdMap instead — mirroring
	 * SearchReplace::run_thumbnail_remap()'s exact JOIN semantics (CAST(meta_value AS UNSIGNED)
	 * = im.source_id): if the source attachment ID has no IdMap entry (never imported), the raw
	 * source value is left as-is, matching run_thumbnail_remap()'s own JOIN (no match = no
	 * update). Normalization is deliberately bounded to exactly this one ID field — SearchReplace
	 * itself doesn't remap any other postmeta ID reference, and this plan doesn't extend
	 * normalization beyond what SearchReplace already knows how to do (see plan Risks).
	 *
	 * `array $meta` is a deliberate type-hint, not a defensive `(array)` cast at the call site —
	 * see compare_post()'s docblock re: this being the intentional trigger for the "malformed
	 * cached entry" failure-containment test.
	 */
	private static function normalize_expected_meta( array $meta, array $replacements, array $attachment_map ): array {
		$normalized = [];
		foreach ( $meta as $item ) {
			$key   = (string) ( $item['key'] ?? '' );
			$value = $item['value'] ?? '';

			if ( in_array( $key, self::IGNORED_META_KEYS, true ) ) {
				continue;
			}

			if ( '_thumbnail_id' === $key ) {
				$source_att_id = (int) $value;
				if ( isset( $attachment_map[ $source_att_id ] ) ) {
					$value = (string) $attachment_map[ $source_att_id ];
				}
			} else {
				$value = SearchReplace::safe_replace( (string) $value, $replacements );
			}

			$normalized[] = [ 'key' => $key, 'value' => (string) $value ];
		}
		return $normalized;
	}

	/**
	 * Maps raw wp_postmeta rows (meta_key/meta_value) into this class's canonical [key,value]
	 * shape, excluding IGNORED_META_KEYS (see that constant's docblock) the same way
	 * normalize_expected_meta() does, so both sides of the hash comparison are filtered
	 * identically.
	 */
	private static function to_actual_meta_list( array $rows ): array {
		$list = [];
		foreach ( $rows as $row ) {
			if ( in_array( $row['meta_key'], self::IGNORED_META_KEYS, true ) ) {
				continue;
			}
			$list[] = [ 'key' => (string) $row['meta_key'], 'value' => (string) $row['meta_value'] ];
		}
		return $list;
	}

	/**
	 * Canonicalizes a [key,value] meta list (sorted by key, then value, so hash comparison isn't
	 * order-sensitive — meta rows have no guaranteed stable order between source and
	 * destination) and hashes it. wp_json_encode() of the sorted list is the canonical
	 * serialization (a reasonable, deterministic form per the plan's own suggestion).
	 */
	private static function hash_meta_list( array $list ): string {
		usort( $list, static function ( $a, $b ) {
			$key_cmp = strcmp( (string) $a['key'], (string) $b['key'] );
			return 0 !== $key_cmp ? $key_cmp : strcmp( (string) $a['value'], (string) $b['value'] );
		} );
		return hash( 'sha256', (string) wp_json_encode( array_values( $list ) ) );
	}

	/**
	 * Content hash covers post_content + post_excerpt together (this unit's own choice — the
	 * plan leaves the exact combination up to the implementer): a NUL byte separator prevents an
	 * ambiguous concatenation boundary (e.g. content ending in "foo" + excerpt "bar" hashing
	 * identically to content "foobar" + empty excerpt).
	 */
	private static function hash_content( string $content, string $excerpt ): string {
		return hash( 'sha256', $content . "\x00" . $excerpt );
	}

	/**
	 * TIME_LIMIT, filtered — the plan's test scenario for the budget-exceeded checkpoint path
	 * explicitly calls for a deterministic override rather than relying on wall-clock timing
	 * flakiness. Production code never needs this filter; tests use it to force a tiny (even
	 * negative/zero) budget so the very first processed item already trips the check.
	 */
	private static function time_limit(): float {
		return (float) apply_filters( 'hbm_audit_compare_time_limit', self::TIME_LIMIT );
	}

	/**
	 * BATCH_SIZE, filtered for the same testability reason as time_limit() — forcing a batch
	 * size of 1 alongside a near-zero time limit lets a test deterministically checkpoint after
	 * exactly one item, proving the checkpoint reflects precisely how far compare_batch() got.
	 */
	private static function batch_size(): int {
		return max( 1, (int) apply_filters( 'hbm_audit_compare_batch_size', self::BATCH_SIZE ) );
	}
}
