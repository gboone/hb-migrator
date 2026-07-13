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
 * @see docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U5. Comment
 *      migration"
 */
class CommentSyncStage implements SyncStageInterface {

	/**
	 * Row budget per invocation, matching PostImporter/PostSyncStage's own 100-row convention.
	 */
	private const BATCH_SIZE = 100;

	public function process( int $site_job_id ): bool {
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

		if ( empty( $comments ) ) {
			// Nothing new since the last pass — already caught up.
			return false;
		}

		// Import first; only advance the cursor for the prefix of this batch that was
		// actually mapped. An exception here propagates straight out to SyncDispatcher's
		// catch block, so the cursor-advance loop below is never reached on failure — cursor
		// only advances on success, mirroring U3's PostSyncStage.
		CommentImporter::process( $site_job_id, $comments );

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
		$max_id  = $last_id;
		$blocked = false;
		foreach ( $comments as $c ) {
			$id = (int) ( $c['comment_ID'] ?? 0 );
			if ( null === IdMap::get( $site_job_id, 'comment', $id ) ) {
				$blocked = true;
				break;
			}
			$max_id = $id;
		}

		if ( $max_id !== $last_id ) {
			MigrationRegistry::update_site_job( $site_job_id, [ 'sync_cursor_comments' => $max_id ] );
		}

		// Only self-requeue an immediate continuation when there's genuine forward progress
		// left to make this pass (a full row-budget batch with nothing blocking it). A
		// blocked batch won't un-block until posts sync again, which only happens on a later
		// pass (webhook or cron) — immediately re-requesting the exact same query would just
		// hit the same block with no progress, so let the next scheduled pass retry instead.
		return ! $blocked && count( $comments ) >= self::BATCH_SIZE;
	}
}
