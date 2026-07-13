<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\SourceClient;

/**
 * U5 sync stage: migrates comments (R6) — a one-time backfill of pre-existing comments,
 * which happens naturally on a site job's first pass because sync_cursor_comments starts at
 * its column default of 0 (see QueueTable's hbm_site_jobs schema), plus ongoing sync of new
 * comments by ID cursor on every subsequent pass. No separate "backfill mode" is needed: the
 * cursor-from-zero mechanism *is* the backfill, bounded by this stage's own row budget and
 * self-requeue continuation like every other stage, not literally unbounded in one call.
 *
 * Registered under the 'comments' slot of the `hbm_sync_stages` filter (see
 * Plugin::register_action_hooks() and SyncDispatcher::get_stages()). SyncDispatcher's stage
 * array iterates 'posts' before 'comments' (see SyncDispatcher::default_stages() and U3's
 * registration in Plugin::register_action_hooks()), so a comment on a source post created in
 * the same burst has somewhere to attach by the time this stage runs — see this plan's U5
 * "Dependencies: U2, U3 (posts must sync before comments within a pass)".
 *
 * Reuses CommentReader (source) and CommentImporter::process() (destination) — the same
 * Reader/Importer/IdMap pipeline every other content type in this plugin uses, invoked again
 * with a delta cursor instead of a parallel content-moving mechanism.
 *
 * Unlike PostSyncStage/MediaSyncStage, comments have no modified-timestamp column to build a
 * datetime-plus-overlap delta cursor from (wp_comments has no comment_modified equivalent), so
 * sync_cursor_comments (hbm_site_jobs, bigint UNSIGNED NOT NULL DEFAULT 0 — added by U1) is a
 * plain numeric ID high-water mark, used directly as CommentReader's last_id cursor — no
 * delta/overlap mode, unlike U3/U4. Comment edits and moderation-status changes to
 * already-synced comments are webhook-only, not covered by this cron-driven stage (see plan
 * "Key Technical Decisions", "Comment edits and moderation-status changes are webhook-only").
 *
 * A blocked comment (unmapped comment_post_ID or comment_parent) is retried, not dropped —
 * except when the SAME comment remains the blocking item for MAX_STALL_PASSES consecutive
 * passes, at which point it's force-imported with comment_parent dropped to 0 (top-level)
 * rather than left blocking the cursor forever — see MAX_STALL_PASSES' docblock for why this
 * bound exists (a parent comment deleted on source, which WordPress core never cascades to its
 * children, otherwise stalls sync_cursor_comments permanently — P1 fix, code review).
 *
 * @see docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U5. Comment
 *      migration"
 */
class CommentSyncStage implements SyncStageInterface {

	/**
	 * Row budget per invocation, matching PostImporter/PostSyncStage's own 100-row convention.
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Bound on how many consecutive passes the SAME comment (by source comment_ID) is allowed
	 * to be the cursor's blocking item before this stage gives up trying to resolve its
	 * comment_parent and force-imports it as a top-level comment instead (P1 fix — code
	 * review).
	 *
	 * Why bounded retry is required at all: the cursor-advance logic below intentionally stops
	 * at the first comment CommentImporter couldn't resolve, so a *transient* block (parent not
	 * synced yet, will arrive next pass) gets retried rather than dropped. But WordPress core
	 * never cascade-deletes or reparents child comments when a parent comment is deleted (see
	 * wp-includes/comment.php — no such logic exists), so a reply whose parent was deleted on
	 * source has a comment_parent that can NEVER resolve via IdMap. Left unbounded, that one
	 * comment would freeze sync_cursor_comments at its position forever, silently halting sync
	 * of every comment created after it on this site job — the exact bug this constant exists
	 * to bound.
	 *
	 * Why 5: SyncScheduler's cron safety net runs a pass every 15 minutes by default
	 * (SyncScheduler::DEFAULT_INTERVAL_SECONDS), and the webhook trigger (U8) can run passes
	 * more often than that. Five consecutive stalls on the exact same comment is already several
	 * multiples of the slowest realistic "genuinely still transient" case — a parent one batch
	 * behind its child, or a same-pass ordering quirk (comment_parent > comment_ID is legal and
	 * explicitly handled, see CommentImporter's docblock) — while still bounding the worst-case
	 * freeze to roughly an hour of cron cadence rather than indefinitely. Not tied to
	 * PostImporter/MediaImporter's row-level $wpdb write-failure retries (a different failure
	 * shape — a single write erroring, not a reference that can never resolve on this host) and
	 * not configurable via a filter, matching this stage's existing BATCH_SIZE convention.
	 */
	private const MAX_STALL_PASSES = 5;

	/**
	 * Source comment IDs fetched (passed to CommentImporter::process()) by the most recent
	 * process() call for a given site_job_id, keyed by site_job_id. Mirrors
	 * PostSyncStage::$last_synced_post_ids' in-memory, single-request handoff shape exactly
	 * — SyncDispatcher runs every stage in order within one PHP request, so no cross-request
	 * persistence is needed or attempted. Used by U6's SyncSearchReplaceStage via
	 * get_synced_comment_ids() to scope comment_content rewriting to the rows this pass
	 * touched. Deliberately captures every fetched ID, not only the ones CommentImporter
	 * actually mapped this pass (a comment skipped for an unmapped post/parent has no
	 * destination row yet, so its IdMap lookup in SyncSearchReplaceStage naturally resolves
	 * to null and it is simply left out of the scoped WHERE IN() set) — the same "capture
	 * what was fetched, let the ID-map lookup filter it" contract PostSyncStage/MediaSyncStage
	 * already use.
	 *
	 * @var array<int, int[]>
	 */
	private static array $last_synced_comment_ids = [];

	public function process( int $site_job_id ): bool {
		// Reset up front so a pass that exits early below (job/migration missing) never
		// leaves a stale prior pass's IDs visible to get_synced_comment_ids().
		self::$last_synced_comment_ids[ $site_job_id ] = [];

		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return false;
		}

		$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
		if ( ! $migration ) {
			return false;
		}

		$last_id = (int) ( $job->sync_cursor_comments ?? 0 );

		$comments = SourceClient::get(
			$migration->source_url,
			$migration->source_api_key,
			'source/sites/' . (int) $job->source_blog_id . '/comments',
			[
				'per_page' => self::BATCH_SIZE,
				'last_id'  => $last_id,
			]
		);

		self::$last_synced_comment_ids[ $site_job_id ] = array_map(
			fn( $c ) => (int) ( $c['comment_ID'] ?? 0 ),
			$comments
		);

		if ( empty( $comments ) ) {
			// Nothing new since the last pass — already caught up.
			return false;
		}

		// Import first; only advance the cursor for the prefix of this batch that was
		// actually mapped. An exception here propagates straight out to SyncDispatcher's
		// catch block, so the cursor-advance loop below is never reached on failure — cursor
		// only advances on success, mirroring U3's PostSyncStage.
		//
		// $write_failed_ids (code-review addition): CommentImporter::process() now reports
		// which fetched comment_IDs did NOT end up durably synced this call, including a
		// wp_update_comment() failure on a comment that was ALREADY IdMap-mapped from a prior
		// pass — a case the walk loop below previously had no way to see, since
		// `IdMap::get()` still returns that comment's old (still-valid) mapping regardless of
		// whether today's re-apply attempt actually succeeded. Without this, the cursor could
		// advance past a comment whose update just silently failed.
		$write_failed_ids = array_flip( CommentImporter::process( $site_job_id, $comments ) );

		// The cursor cannot simply become "max comment_ID fetched in this batch": a comment
		// whose comment_post_ID or comment_parent isn't mapped yet is skipped by
		// CommentImporter, not inserted — advancing the cursor past it would exclude it from
		// every future `comment_ID > last_id` fetch and drop it permanently, contradicting
		// R6's "skipped this pass, retried on the next one, not dropped". CommentReader
		// returns rows ordered by comment_ID ASC, so walking the batch in order and stopping
		// at the first comment_ID with no destination mapping yet gives the correct
		// high-water mark: everything before it is durably synced, it and everything after
		// it (in this batch) will be re-fetched and re-attempted next pass. Re-fetching an
		// already-synced comment ahead of a blocked one is safe — CommentImporter's
		// IdMap-keyed existing-row check makes that a no-op update, not a duplicate.
		//
		// Bounded-stall exception (P1 fix — see MAX_STALL_PASSES docblock): if the first
		// unresolved comment_ID encountered here is the SAME one that was already the
		// blocking item on the immediately preceding pass, this pass's attempt count is
		// $old_stall_count + 1; once that reaches MAX_STALL_PASSES, CommentImporter is called
		// a second time for just that one comment with its comment_ID in $force_top_level_ids,
		// which drops its comment_parent to 0 (top-level) rather than continuing to require an
		// IdMap match that, for a parent deleted on source, can never come. If that force
		// succeeds (i.e. the comment's post WAS mapped — only its parent was the permanent
		// blocker), the walk falls through and keeps scanning the rest of the batch exactly as
		// if this comment had resolved normally, so a later, different blocking comment in the
		// same batch still gets its own fresh count (never inherits this one's), per-pass.
		$old_stall_id    = (int) ( $job->sync_comment_stall_id ?? 0 );
		$old_stall_count = (int) ( $job->sync_comment_stall_count ?? 0 );

		$max_id          = $last_id;
		$blocked         = false;
		$new_stall_id    = 0;
		$new_stall_count = 0;
		$gave_up_note    = null;

		foreach ( $comments as $c ) {
			$id = (int) ( $c['comment_ID'] ?? 0 );

			if ( null !== IdMap::get( $site_job_id, 'comment', $id ) && isset( $write_failed_ids[ $id ] ) ) {
				// Already mapped from a prior pass, but this pass's re-apply (wp_update_comment())
				// failed — a transient write failure, not an unresolved reference, so it is NOT
				// eligible for the bounded-stall/force-top-level treatment below (forcing
				// comment_parent to 0 cannot fix a failed database write). Stop the walk here and
				// retry next pass, same as the plugin's other content types treat a per-item
				// write failure (see PostSyncStage/MediaSyncStage's equivalent handling) — no
				// stall bookkeeping is touched for this comment.
				$blocked = true;
				break;
			}

			if ( null === IdMap::get( $site_job_id, 'comment', $id ) ) {
				$attempt = ( $id === $old_stall_id ) ? $old_stall_count + 1 : 1;

				if ( $attempt >= self::MAX_STALL_PASSES ) {
					// This exact comment has now been the cursor's blocking item for
					// MAX_STALL_PASSES consecutive passes without resolving. Force it through
					// with comment_parent dropped — a no-op if comment_post_ID is what's
					// actually still unresolved (CommentImporter still skips it in that case,
					// untouched by $force_top_level_ids), so this can never fabricate a
					// destination post, only waive the parent check.
					CommentImporter::process( $site_job_id, [ $c ], [ $id ] );
				}

				if ( null === IdMap::get( $site_job_id, 'comment', $id ) ) {
					// Still unresolved — either not yet eligible for forcing, or forcing
					// didn't help because comment_post_ID (not comment_parent) is the actual
					// unresolved reference. Stop the walk here exactly as before; pin the
					// stall count at the bound rather than growing it unbounded pass after
					// pass once forcing is already being (harmlessly, idempotently) retried
					// every pass.
					$blocked         = true;
					$new_stall_id    = $id;
					$new_stall_count = min( $attempt, self::MAX_STALL_PASSES );
					break;
				}

				// Forcing just resolved it this pass.
				$gave_up_note = sprintf(
					'Comment #%d: parent comment #%d never resolved after %d consecutive sync passes (likely deleted on source) — imported as a top-level comment.',
					$id,
					(int) ( $c['comment_parent'] ?? 0 ),
					self::MAX_STALL_PASSES
				);
			}

			$max_id = $id;
		}

		$job_updates = [];
		if ( $max_id !== $last_id ) {
			$job_updates['sync_cursor_comments'] = $max_id;
		}
		if ( null !== $gave_up_note ) {
			$job_updates['sync_comment_stall_note'] = $gave_up_note;
		}
		if ( $blocked ) {
			if ( $new_stall_id !== $old_stall_id || $new_stall_count !== $old_stall_count ) {
				$job_updates['sync_comment_stall_id']    = $new_stall_id;
				$job_updates['sync_comment_stall_count'] = $new_stall_count;
			}
		} elseif ( 0 !== $old_stall_id || 0 !== $old_stall_count ) {
			// Nothing blocked this pass — clear any stall bookkeeping left over from a prior
			// pass so a later, unrelated blocking comment starts its own fresh count rather
			// than inheriting this one's.
			$job_updates['sync_comment_stall_id']    = 0;
			$job_updates['sync_comment_stall_count'] = 0;
		}

		if ( ! empty( $job_updates ) ) {
			MigrationRegistry::update_site_job( $site_job_id, $job_updates );
		}

		// Only self-requeue an immediate continuation when there's genuine forward progress
		// left to make this pass (a full row-budget batch with nothing blocking it). A
		// blocked batch won't un-block until posts sync again, which only happens on a later
		// pass (webhook or cron) — immediately re-requesting the exact same query would just
		// hit the same block with no progress, so let the next scheduled pass retry instead.
		return ! $blocked && count( $comments ) >= self::BATCH_SIZE;
	}

	/**
	 * Source comment IDs fetched by this stage's most recent process() call for the given
	 * site_job_id — used by U6's SyncSearchReplaceStage to scope comment_content rewriting
	 * to exactly the comment rows this pass touched. Returns an empty array when this stage
	 * hasn't run for this site_job_id in the current PHP process, mirroring
	 * PostSyncStage::get_synced_post_ids()'s "nothing to add" default.
	 */
	public static function get_synced_comment_ids( int $site_job_id ): array {
		return self::$last_synced_comment_ids[ $site_job_id ] ?? [];
	}
}
