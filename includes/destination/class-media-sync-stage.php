<?php

namespace HBMigrator\Destination;

use HBMigrator\MigrationRegistry;
use HBMigrator\SourceClient;

/**
 * U4 sync stage: syncs new media, and media associated with a post that changed since the
 * last sync pass (R5), on every sync pass. Registered under the 'media' slot of the
 * `hbm_sync_stages` filter (see Plugin::register_action_hooks() and
 * SyncDispatcher::get_stages()).
 *
 * Reuses MediaReader (source) and MediaImporter::import_batch() (destination) — the same
 * Reader/Importer/IdMap pipeline the initial migration uses, including its existing
 * conflict-policy (media_conflict_policy, media_import_scope) and cross-run dedup logic —
 * invoked again with a delta cursor instead of a parallel content-moving mechanism,
 * mirroring PostSyncStage's (U3) shape exactly.
 *
 * Row budget matches MediaImporter's own per-invocation batch size (50, not PostImporter's
 * 100 — media batches also download and sideload a file per item, so the existing pipeline
 * already caps them lower) so a single process() call never processes an unbounded amount
 * of work; SyncDispatcher self-requeues a continuation when this stage reports more work
 * remains (return true), per SyncStageInterface's contract.
 *
 * @see docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U4. Media
 *      delta sync"
 */
class MediaSyncStage implements SyncStageInterface {

	private const BATCH_SIZE = 50;

	/**
	 * Fallback floor for a site job's very first media sync pass (sync_cursor_media is still
	 * NULL — Enable Sync has run but no pass has completed yet). Mirrors
	 * PostSyncStage::EPOCH's rationale: not "now", so edits/uploads made during the QA
	 * window between initial-migration-complete and Enable Sync are still caught by the
	 * first pass rather than skipped.
	 */
	private const EPOCH = '1970-01-01 00:00:00';

	public function process( int $site_job_id ): bool {
		global $wpdb;

		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return false;
		}

		$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
		if ( ! $migration ) {
			return false;
		}

		// Highest source attachment ID this site job has ever imported (initial migration
		// or a prior sync pass). Same MAX(source_id)-over-IdMap workaround PostSyncStage
		// (U3) uses — hbm_site_jobs' sync_cursor_media column is a datetime, not a numeric
		// ID column, so it can't itself carry the keyset ID cursor.
		$id_map_table = $wpdb->base_prefix . 'hbm_id_map';
		$last_id      = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(source_id) FROM `{$id_map_table}` WHERE site_job_id = %d AND object_type = 'attachment'",
			$site_job_id
		) );

		$prior_cursor   = $job->sync_cursor_media;
		$modified_since = $prior_cursor ?: self::EPOCH;

		// Attachments whose parent post was touched by PostSyncStage (U3) this same pass —
		// e.g. a featured image swapped on an edited post — must be picked up even when the
		// attachment row itself has no own post_modified change. PostSyncStage always runs
		// before this stage within the same run_sync_pass() call (see
		// SyncDispatcher::default_stages()' posts-before-media key order), so this in-memory
		// handoff reflects exactly what was just synced, not a stale earlier pass.
		$parent_ids = PostSyncStage::get_synced_post_ids( $site_job_id );

		$media = SourceClient::get(
			$migration->source_url,
			$migration->source_api_key,
			'source/sites/' . (int) $job->source_blog_id . '/media',
			[
				'per_page'       => self::BATCH_SIZE,
				'last_id'        => $last_id,
				'modified_since' => $modified_since,
				'parent_ids'     => $parent_ids,
			]
		);

		if ( empty( $media ) ) {
			// Nothing new, edited, or newly-parented since the last pass — already caught up.
			return false;
		}

		// Import first; only advance the cursor if the batch is fully processed without
		// throwing (an exception here propagates straight out to SyncDispatcher's catch
		// block, so the cursor-advance line below is simply never reached — cursor only
		// advances on success, mirroring PostSyncStage/R11's contract). A per-item download
		// or sideload failure inside import_batch() is not itself an exception — such items
		// are simply skipped for this pass (see MediaImporter::import_batch()'s $failed_items
		// return value); they are naturally retried on a later pass if the attachment or its
		// parent post changes again, matching the accepted-gap shape of other best-effort
		// sync paths in this plan (e.g. comment edits, U8's webhook trigger).
		MediaImporter::import_batch( $site_job_id, $media );

		$max_modified = $prior_cursor;
		foreach ( $media as $m ) {
			$modified = $m['post_modified'] ?? null;
			if ( $modified && ( null === $max_modified || $modified > $max_modified ) ) {
				$max_modified = $modified;
			}
		}

		if ( $max_modified && $max_modified !== $prior_cursor ) {
			MigrationRegistry::update_site_job( $site_job_id, [ 'sync_cursor_media' => $max_modified ] );
		}

		// A full batch means more matching media likely remain beyond this row budget —
		// mirrors MediaImporter::process()'s own `count( $media ) >= 50` continuation check.
		return count( $media ) >= self::BATCH_SIZE;
	}
}
