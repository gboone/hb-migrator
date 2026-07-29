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

		// Bail if the site job has moved on from 'complete' since this comparison run started
		// (found during code review). Two races this guards against: (1) sync gets enabled
		// (status -> 'syncing') while a large migration's self-chained comparison is still
		// in-flight — without this check, a later batch would diff the destination's current
		// (sync-modified) content against the stale pre-sync cached snapshot and misreport a
		// legitimate sync write as drift; (2) sync gets finalized/cleared (which can only happen
		// after status has already left 'complete') and AuditReport::delete_for_site_job() runs —
		// without this check, a still-in-flight batch would call get_or_create_for_site_job() and
		// resurrect the just-deleted report post full of "not comparable" garbage. Re-checked
		// fresh on every self-chained invocation (this method re-fetches $job every call), not
		// just once, since sync can be enabled at any point after finalize() first enqueues this.
		if ( 'complete' !== $job->status ) {
			return null;
		}

		// U4 (R6, docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U4. Comparator
		// trail-read caching"): load_latest_write_entries() itself now transient-caches this read
		// per site_job_id+object_type across the whole self-chained comparison run — this call
		// only actually re-reads _hbm_audit_write postmeta on the FIRST compare_batch_inner() call
		// for this site job; every later checkpoint hits the cache instead (see that method's own
		// docblock). Called exactly once per compare_batch_inner() invocation (found during code
		// review — this used to be loaded a second time inside load_driving_post_map() with
		// identical arguments) and passed to both load_driving_post_map() and the per-item loop
		// below.
		$write_entries = self::load_latest_write_entries( $site_job_id, 'post' );

		$driving_map = self::load_driving_post_map( $site_job_id, $write_entries );
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
	 * grounding), so it is the base of this map. A source_id with NO IdMap entry at all and a
	 * *latest* write-trail outcome of 'failed' never landed (PostImporter only calls
	 * IdMap::set() on success) — but the plan explicitly wants failed attempts surfaced as "not
	 * comparable/failed to import" rather than silently dropped (see compare_post()). Reconciling
	 * both: failed-only source_ids (no IdMap entry, latest outcome 'failed') are added to the
	 * driving map with a dest_id of 0 (no destination row exists to compare against), merged into
	 * the same sorted keyset so a single cursor covers both landed and permanently-failed items in
	 * one deterministic pass. This is a judgment call beyond the plan's literal prose.
	 *
	 * Note this is NOT a claim that 'failed' outcome and IdMap-presence are mutually exclusive in
	 * general — a resumed/retried batch can record a LATER 'failed' entry (e.g. a wp_update_post()
	 * failure) for a source_id that already landed on an EARLIER successful attempt, so IdMap
	 * still holds a real dest_id for it. Those source_ids stay in $landed with their real dest_id
	 * (never added to $failed here, since `isset($landed[$source_id])` is false only for
	 * genuinely-never-landed items) — compare_post() is responsible for not re-deriving "did this
	 * land" purely from the latest outcome string once a real dest_id is in hand (found during
	 * code review — see that method's $dest_id <= 0 gate).
	 *
	 * @param array $write_entries Pre-loaded via load_latest_write_entries($site_job_id, 'post')
	 *                             — passed in rather than reloaded here (found during code
	 *                             review: this method used to re-fetch the exact same data
	 *                             compare_batch_inner() had already loaded moments earlier).
	 */
	private static function load_driving_post_map( int $site_job_id, array $write_entries ): array {
		$landed = IdMap::get_all_for_job( $site_job_id, 'post' );

		$failed = [];
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

		// R2 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U2. Shared
		// write-trail contract"): a single point of truth for the field defaults this method
		// applies to the "expected" (source-derived) side of a comparison — see
		// normalize_write_entry()'s own docblock. Read-time only: no producer call site changes,
		// no change to what gets stored in postmeta.
		$entry = self::normalize_write_entry( $entry );

		$outcome = (string) ( $entry['outcome'] ?? '' );
		// Gate on $dest_id, not the latest entry's outcome alone: a resumed/retried batch can
		// record a 'failed' entry for a source_id that already landed on an EARLIER successful
		// attempt (PostImporter::import_batch()'s update branch records 'failed' on a
		// wp_update_post() failure without ever clearing that item's IdMap entry from its prior
		// successful insert). load_driving_post_map() already resolves the correct non-zero
		// $dest_id for this case from IdMap — trusting the latest entry's outcome instead would
		// mischaracterize a genuinely-landed post as "not comparable", hiding real drift on
		// exactly the posts most likely to have it (found during code review).
		if ( 'failed' === $outcome && $dest_id <= 0 ) {
			// Nothing ever landed on destination for this item — surfaced as "not comparable /
			// failed to import" per the plan, not silently dropped, but not hashed either.
			return $base + [
				'comparable' => false,
				'diverged'   => false,
				'reason'     => 'source_write_failed',
			];
		}

		// Expected (normalized) side — pure computation, no destination read yet. Every field
		// below except 'meta' was already defaulted by normalize_write_entry() above.
		$expected_content   = SearchReplace::safe_replace( $entry['post_content'], $replacements );
		$expected_excerpt   = SearchReplace::safe_replace( $entry['post_excerpt'], $replacements );
		$expected_title     = $entry['post_title'];
		$expected_slug      = $entry['post_name'];
		// $entry['meta'] is intentionally NOT cast to array here — normalize_expected_meta()'s
		// `array $meta` type hint is the deliberate guard that turns a malformed cached entry
		// (e.g. 'meta' corrupted to a non-array) into a real \Throwable (TypeError), which
		// compare_batch()'s outer try/catch is exactly built to contain (see that method's own
		// docblock and this unit's "forced internal failure" test scenario). normalize_write_
		// entry() deliberately leaves 'meta' untouched (see that method's docblock) so this
		// ?? [] default (for an entirely absent key) and the type-hint guard below behave
		// identically to before this refactor.
		$expected_meta_list    = self::normalize_expected_meta( $entry['meta'] ?? [], $replacements, $attachment_map );
		$expected_content_hash = self::hash_content( (string) $expected_content, (string) $expected_excerpt );
		$expected_meta_hash    = self::hash_meta_list( $expected_meta_list );
		$source_author_email  = $entry['source_author'];

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
			// performs at write time — R4, see PostImporter::resolve_author_id()'s own
			// docblock — rather than a literal identity comparison. This correctly treats a
			// user_conflict_policy: merge resolution to a pre-existing destination user as a
			// match — the resolution is a pure function of the same inputs (source email +
			// destination's current user table), so re-running it here reproduces exactly what
			// import_batch() decided, without needing any new stored state (see plan grounding +
			// final report). Already switched to $job->dest_blog_id above, satisfying
			// resolve_author_id()'s "caller must already be switched to the destination blog"
			// contract.
			$expected_author_id = PostImporter::resolve_author_id( $source_author_email );
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

		// R3 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U2. Shared
		// write-trail contract"): shares AuditReport::append_entry()'s exact wp_slash()/
		// add_post_meta()/clean_post_cache() sequence via write_meta_row() rather than
		// duplicating that mechanics here — see that method's docblock for the full rationale
		// (backslash preservation, cache-invalidation-suspension caller safety).
		switch_to_blog( get_main_site_id() );
		try {
			AuditReport::write_meta_row( $post_id, self::META_RESULT, $result );
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
	 * U7: the self-chaining entry point the `hbm_audit_compare` Action Scheduler action calls
	 * directly (registered in Plugin::register_action_hooks() — see class-plugin.php).
	 * SearchReplace::finalize() enqueues this action once, immediately after a site job flips to
	 * `complete` — it never calls this (or compare_batch()) synchronously, so an arbitrarily long,
	 * self-chained comparison run can never delay or destabilize finalize() itself.
	 *
	 * Calls compare_batch() once. If it returns a non-null checkpoint (more work remains), this
	 * method self-enqueues the same action with that checkpoint and returns — the next invocation
	 * picks up where this one left off. Once compare_batch() returns null (every cached post
	 * compared, or nothing to compare, or an internal failure inside compare_batch() already
	 * logged and swallowed there — this method's perspective can't distinguish those cases, which
	 * is fine, that's compare_batch()'s own documented contract), this method computes the
	 * media/taxonomy/taxonomy-term counts and the per-post comparison results, then renders the
	 * final summary into the report post via AuditReport::render_summary() — exactly once, after
	 * the LAST batch, never after an intermediate one (a self-enqueue above always `return`s
	 * before reaching this step).
	 *
	 * Wraps its ENTIRE body in try/catch (\Throwable), matching every other method in this class
	 * (see class docblock): this is now the thing an Action Scheduler action calls directly, so an
	 * uncaught exception here is exactly the failure-cascade risk this whole plan is designed to
	 * prevent — a bug in the count/render step must never propagate into
	 * PipelineController::handle_batch_failure()'s retry/failure machinery, nor regress an
	 * already-`complete` site job back to `failed`.
	 *
	 * @param int $site_job_id
	 * @param int $last_pk  Resume cursor, forwarded to compare_batch() as-is. Defaults to 0 —
	 *                      matches the shape of every other stage's process() entry point
	 *                      (SearchReplace::process(), PostImporter::process(), etc.), which all
	 *                      accept a checkpoint/cursor parameter with a zero default for the first
	 *                      call.
	 */
	public static function process( int $site_job_id, int $last_pk = 0 ): void {
		try {
			$checkpoint = self::compare_batch( $site_job_id, $last_pk );

			if ( null !== $checkpoint ) {
				// Time budget exhausted mid-batch — dispatch a continuation from the checkpoint.
				// R8 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U6.
				// AuditComparator self-chain: bounded retry for continuation enqueue"): the enqueue
				// call itself gets a small bounded retry rather than being called directly here —
				// see enqueue_continuation_with_retry()'s own docblock for why.
				self::enqueue_continuation_with_retry( $site_job_id, $checkpoint );
				return;
			}

			// Same status guard as compare_batch_inner() (found during code review): if sync was
			// enabled/finalized in the narrow window between the last compare_batch() call
			// returning null and this render step, skip rendering — a stale-vs-post-sync summary,
			// or resurrecting a just-deleted report post, is worse than no final render at all.
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || 'complete' !== $job->status ) {
				return;
			}

			// Every cached post has now been compared (or compare_batch() already swallowed an
			// internal failure) — render the final summary exactly once, now that no further
			// batches will run for this site job.
			$counts       = self::compute_counts( $site_job_id );
			$post_results = self::get_post_comparison_results( $site_job_id );
			AuditReport::render_summary( $site_job_id, $counts, $post_results );

			// U4 (R6): this comparison run is now fully finished (no further compare_batch()
			// checkpoint will ever run for this site job) — explicitly invalidate every
			// object_type's cached write-trail read rather than relying solely on the
			// WRITE_CACHE_TTL backstop (see clear_write_cache()'s own docblock).
			self::clear_write_cache( $site_job_id );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditComparator::process() failed for site job ' . $site_job_id . ' at last_pk ' . $last_pk . ': ' . $e->getMessage() );
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
	 * How long the U4 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U4.
	 * Comparator trail-read caching") write-trail transient cache is allowed to live as a
	 * defensive backstop. Not the primary invalidation mechanism — that's the explicit
	 * clear_write_cache() call in process()'s success path — comfortably longer than any
	 * realistic self-chained comparison run, so it only matters if that explicit cleanup is
	 * somehow skipped (e.g. the process crashes between the last render and the cleanup call).
	 * Filterable (see write_cache_ttl()) — found during code review: every sibling constant in
	 * this class (TIME_LIMIT, BATCH_SIZE, CONTINUATION_ENQUEUE_ATTEMPTS/RETRY_DELAY) already
	 * follows this filterable-constant convention; this one hadn't.
	 */
	private const WRITE_CACHE_TTL = HOUR_IN_SECONDS;

	private static function write_cache_ttl(): int {
		return max( 0, (int) apply_filters( 'hbm_audit_compare_write_cache_ttl', self::WRITE_CACHE_TTL ) );
	}

	/**
	 * Loads every _hbm_audit_write entry for $site_job_id (via AuditReport's U6 read-access
	 * addition — see that class), filters to $object_type, and keeps only the LATEST entry per
	 * source_id (rows are already in ascending meta_id/insertion order — see
	 * AuditReport::get_write_entries_for_site_job()'s docblock — so a later foreach iteration
	 * simply overwrites an earlier one for the same key). This is the single mechanism this
	 * class uses to resolve "a resumed/retried batch produced more than one trail entry for the
	 * same source_id" for posts, attachments, and terms alike.
	 *
	 * U4 (R6): this is called with 'post' once per compare_batch_inner() call (the per-checkpoint
	 * driver, which repeats up to once per BATCH_SIZE-sized chunk of a large migration) and with
	 * 'attachment'/'term' once each from compute_object_type_counts() (only reached once total per
	 * comparison run, after the last checkpoint). Reloading the full trail from postmeta on every
	 * one of those calls is the O(N^2/batch) cost this cache removes: the result is now cached in
	 * a WP transient keyed by BOTH $site_job_id AND $object_type (never by $site_job_id alone —
	 * that would incorrectly serve compute_object_type_counts()'s 'attachment'/'term' calls the
	 * 'post'-filtered result compare_batch_inner() cached earlier in the same run, a correctness
	 * bug, not just a missed optimization). Safe specifically because post write-trail entries are
	 * immutable once comparison starts (compare_batch()/process() only ever run after
	 * SearchReplace::finalize(), by which point every import stage has already completed and no
	 * new write-trail entries will ever be recorded for this site job again — see class docblock
	 * and this file's accompanying plan) — the cache only needs to survive one comparison run.
	 * Populated on first read per object_type per run; explicitly cleared by clear_write_cache()
	 * once process() finishes rendering the final summary; a WRITE_CACHE_TTL backstop covers the
	 * case where that explicit cleanup is somehow never reached.
	 */
	private static function load_latest_write_entries( int $site_job_id, string $object_type ): array {
		$cache_key = self::write_cache_key( $site_job_id, $object_type );

		switch_to_blog( get_main_site_id() );
		try {
			$cached = get_transient( $cache_key );
		} finally {
			restore_current_blog();
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}

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

		switch_to_blog( get_main_site_id() );
		try {
			set_transient( $cache_key, $latest, self::write_cache_ttl() );
		} finally {
			restore_current_blog();
		}

		return $latest;
	}

	/**
	 * The transient key U4's cache uses for one site job's one object_type — see
	 * load_latest_write_entries()'s docblock for why BOTH components are required.
	 */
	private static function write_cache_key( int $site_job_id, string $object_type ): string {
		return 'hbm_audit_write_cache_' . $site_job_id . '_' . $object_type;
	}

	/**
	 * U4's explicit, primary cache invalidation (docs/plans/2026-07-29-001-fix-audit-report-
	 * hardening-plan.md, "U4. Comparator trail-read caching"): deletes every object_type's cached
	 * write-trail entry for a site job. Called by process() once it finishes rendering the final
	 * summary (the success path) — the only three object_types this class ever caches are 'post',
	 * 'attachment', and 'term' (see load_latest_write_entries()'s call sites), so those are the
	 * only keys that need explicit deletion; a WRITE_CACHE_TTL backstop covers anything this
	 * explicit call doesn't reach (e.g. process() crashing between the render and this call).
	 */
	private static function clear_write_cache( int $site_job_id ): void {
		switch_to_blog( get_main_site_id() );
		try {
			foreach ( [ 'post', 'attachment', 'term' ] as $object_type ) {
				delete_transient( self::write_cache_key( $site_job_id, $object_type ) );
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * R2 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U2. Shared write-trail
	 * contract: entry normalization and author resolution"): a single, read-time point of truth
	 * for the field defaults compare_post() applies to a raw cached write-trail entry's
	 * "expected" (source-derived) side — previously scattered across ~10 separate `?? ''`/`?? 0`
	 * inline call sites. Deliberately a read-time normalizer, not a write-time contract: none of
	 * the 8 existing producer call sites (PostImporter::record_write_trail() and this file's own
	 * test helpers) change, and neither does what gets stored in postmeta — see this class's own
	 * "Key Technical Decisions" reference in the accompanying plan.
	 *
	 * `meta` is deliberately left untouched here — no default, no type coercion. Defaulting or
	 * casting it here would defeat normalize_expected_meta()'s own `array $meta` type-hint
	 * contract, which is the deliberate trigger for this class's "malformed cached entry"
	 * failure-containment test (see that method's docblock). Callers still apply their own
	 * `$entry['meta'] ?? []` default for an entirely absent key, exactly as before this
	 * extraction.
	 */
	private static function normalize_write_entry( array $entry ): array {
		$entry['post_content']  = (string) ( $entry['post_content'] ?? '' );
		$entry['post_excerpt']  = (string) ( $entry['post_excerpt'] ?? '' );
		$entry['post_title']    = (string) ( $entry['post_title'] ?? '' );
		$entry['post_name']     = (string) ( $entry['post_name'] ?? '' );
		$entry['source_author'] = (string) ( $entry['source_author'] ?? '' );
		return $entry;
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

	/**
	 * R8 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U6. AuditComparator
	 * self-chain: bounded retry for continuation enqueue"): how many times
	 * enqueue_continuation_with_retry() will attempt the `hbm_audit_compare` continuation
	 * enqueue call before giving up. Filterable for the same testability reason as
	 * time_limit()/batch_size() above.
	 */
	private const CONTINUATION_ENQUEUE_ATTEMPTS = 3;

	/**
	 * Seconds (fractional) enqueue_continuation_with_retry() sleeps between a failed attempt and
	 * the next one. Deliberately short, small, and non-exponential — this is NOT
	 * PipelineController::handle_batch_failure()'s minute-scale exponential backoff (that
	 * mechanism's persisted-retry-count-via-re-enqueued-args and status-flipping-on-exhaustion
	 * shape is exactly what this retry must NOT reuse — see this method's own docblock and the
	 * plan's Key Technical Decisions for R8). A short pause — not zero — because this runs inside
	 * a background job with no user waiting (so the pause costs nothing observable) and because a
	 * transient DB/Action-Scheduler write error realistically needs a brief moment to clear, not a
	 * sub-millisecond re-attempt that hits the same failure for the same reason. Filterable so
	 * tests can force this to near-zero and stay fast.
	 */
	private const CONTINUATION_ENQUEUE_RETRY_DELAY = 0.5;

	private static function continuation_enqueue_attempts(): int {
		return max( 1, (int) apply_filters( 'hbm_audit_compare_continuation_enqueue_attempts', self::CONTINUATION_ENQUEUE_ATTEMPTS ) );
	}

	private static function continuation_enqueue_retry_delay(): float {
		return max( 0.0, (float) apply_filters( 'hbm_audit_compare_continuation_enqueue_retry_delay', self::CONTINUATION_ENQUEUE_RETRY_DELAY ) );
	}

	/**
	 * R8: wraps process()'s self-chain continuation `hbm_audit_compare` enqueue call in a small
	 * bounded-retry loop, entirely local to this single process() invocation — no persisted
	 * retry-count state, no new parameter on process()'s own signature, no change to
	 * Plugin::register_action_hooks()'s action registration. A transient Action Scheduler/DB
	 * write error is exactly the kind of failure a few retries with a short pause between them can
	 * ride out (see CONTINUATION_ENQUEUE_RETRY_DELAY's own docblock for why the pause is short but
	 * non-zero).
	 *
	 * On any attempt succeeding: returns normally, action enqueued, no different from calling
	 * as_enqueue_async_action() directly. On every attempt failing: logs via error_log() (matching
	 * this class's universal swallow-and-log discipline — see class docblock) and returns
	 * normally — no exception ever propagates out of this method, and `hbm_site_jobs.status` is
	 * never touched either way. Deliberately does NOT call
	 * PipelineController::handle_batch_failure() — that helper unconditionally flips `status` to
	 * `'failed'` on retry exhaustion when given a site_job_id, which would violate the audit
	 * layer's non-negotiable rule that its own failures must never regress an already-`complete`
	 * site job (see plan's R8 Key Technical Decision).
	 *
	 * Cannot detect "the action was enqueued but Action Scheduler never claimed/ran it" — an
	 * accepted, pre-existing limitation this inherits from SearchReplace's own self-chain (see
	 * plan Risks), not addressed here.
	 */
	private static function enqueue_continuation_with_retry( int $site_job_id, int $checkpoint ): void {
		$attempts = self::continuation_enqueue_attempts();
		$delay    = self::continuation_enqueue_retry_delay();

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$failure_reason = null;

			try {
				$action_id = as_enqueue_async_action(
					'hbm_audit_compare',
					[ 'site_job_id' => $site_job_id, 'last_pk' => $checkpoint ],
					'hb-migrator'
				);

				// Found during code review: ActionScheduler_ActionFactory::create() (the code
				// as_enqueue_async_action() calls into) already wraps its own DB-save call in a
				// try/catch and returns 0 on failure WITHOUT rethrowing — the exact "transient
				// DB/Action-Scheduler write error" this retry loop exists to ride out never
				// actually reaches this method as a \Throwable. Treating a 0 return the same as
				// a caught exception is what makes this loop's retry actually fire for its real
				// target failure mode, instead of silently treating a failed enqueue as success.
				if ( $action_id > 0 ) {
					return;
				}

				$failure_reason = 'as_enqueue_async_action() returned 0 (no exception thrown)';
			} catch ( \Throwable $e ) {
				$failure_reason = $e->getMessage();
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HB Migrator: AuditComparator continuation enqueue attempt ' . $attempt . '/' . $attempts . ' failed for site job ' . $site_job_id . ' at checkpoint ' . $checkpoint . ': ' . $failure_reason );

			if ( $attempt < $attempts && $delay > 0.0 ) {
				usleep( (int) round( $delay * 1000000 ) );
			}
		}

		// Every attempt failed — swallow and log, exactly like every other method in this class
		// (see class docblock). Never rethrow, never touch hbm_site_jobs.status: process()'s own
		// outer try/catch would otherwise feed PipelineController::handle_batch_failure(), which
		// is precisely the cascade this retry exists to avoid.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'HB Migrator: AuditComparator failed to enqueue hbm_audit_compare continuation for site job ' . $site_job_id . ' at checkpoint ' . $checkpoint . ' after ' . $attempts . ' attempts — giving up without altering hbm_site_jobs.status.' );
	}
}
