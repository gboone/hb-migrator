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

	/**
	 * Source attachment IDs fetched (passed to MediaImporter::import_batch()) by the most
	 * recent process() call for a given site_job_id, keyed by site_job_id. Mirrors
	 * PostSyncStage::$last_synced_post_ids' in-memory, single-request handoff shape exactly
	 * (same rationale: SyncDispatcher runs every stage in order within one PHP request, so
	 * no cross-request persistence is needed or attempted) — lets U6's SyncSearchReplaceStage
	 * scope its URL rewrite / _thumbnail_id remap to the attachment rows this pass actually
	 * touched, via get_synced_media_ids().
	 *
	 * @var array<int, int[]>
	 */
	private static array $last_synced_media_ids = [];

	public function process( int $site_job_id ): bool {
		global $wpdb;

		// Reset up front so a pass that exits early below (job/migration missing) never
		// leaves a stale prior pass's IDs visible to get_synced_media_ids().
		self::$last_synced_media_ids[ $site_job_id ] = [];

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

		self::$last_synced_media_ids[ $site_job_id ] = array_map(
			fn( $m ) => (int) ( $m['source_attachment_id'] ?? 0 ),
			$media
		);

		if ( empty( $media ) ) {
			// Nothing new, edited, or newly-parented since the last pass — already caught up.
			return false;
		}

		// Import first; only advance the cursor for the prefix of this batch that was
		// actually imported successfully. An exception here propagates straight out to
		// SyncDispatcher's catch block, so the cursor-advance loop below is never reached on
		// a hard failure — cursor only advances on success, mirroring PostSyncStage/R11's
		// contract. A per-item download/sideload/insert failure inside import_batch() is not
		// itself an exception — such items are reported back via $failed_items (source
		// attachment ID => reason) instead of throwing. Note this is distinct from a
		// *permanent* skip (no file_url, SSRF-guarded host, cross-run dedup match,
		// skip_duplicates match) — those are intentionally never retried and are correctly
		// absent from $failed_items, so they don't block the cursor here.
		$failed_items  = MediaImporter::import_batch( $site_job_id, $media );
		$failed_id_set = array_flip( array_keys( $failed_items ) );

		// The cursor cannot simply become "max post_modified fetched in this batch": an item
		// that failed to download/sideload/insert was skipped by MediaImporter, not written —
		// advancing the cursor past its position would exclude it from every future
		// `modified_since` fetch and drop it permanently, contradicting R5/R11's "skipped
		// this pass, retried on the next one, not dropped" — exactly the bug
		// CommentSyncStage already avoids for its own kind of per-item skip (see that
		// class's docblock). Media items are not guaranteed to arrive in modified-time
		// order, so walking the batch in fetch order and stopping the cursor's forward
		// extension at the first failed item (even if later items in the batch succeeded)
		// gives a safe high-water mark: everything before the failure is durably synced,
		// and it plus everything from that point in this batch is re-fetched and
		// re-attempted next pass. Re-fetching an already-succeeded item ahead of a failed one
		// is safe — MediaImporter's IdMap-keyed already-imported check makes that a no-op.
		$max_modified = $prior_cursor;
		$had_failure  = false;
		foreach ( $media as $m ) {
			$source_att_id = (int) ( $m['source_attachment_id'] ?? 0 );
			if ( isset( $failed_id_set[ $source_att_id ] ) ) {
				$had_failure = true;
				break;
			}
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
		// A failure also means more work remains this pass (the failed item and everything
		// after it in fetch order still needs to be retried), the same "keep retrying via
		// self-requeue rather than silently treating a failure-laden batch as caught up"
		// reasoning as PostSyncStage. Known, accepted trade-off (matching this plan's
		// existing CommentSyncStage blocked-parent gap): an attachment that fails every
		// single download/sideload attempt (a permanent problem, not a transient one) will
		// cause this stage to self-requeue indefinitely rather than skip past it — solving
		// that poison-item case is out of scope here; this fix only targets the silent-skip
		// data-loss bug for transient failures.
		return $had_failure || count( $media ) >= self::BATCH_SIZE;
	}

	/**
	 * Source attachment IDs fetched by this stage's most recent process() call for the given
	 * site_job_id — used by U6's SyncSearchReplaceStage to scope its URL rewrite and
	 * _thumbnail_id remap to exactly the attachment rows this pass touched (attachments are
	 * `wp_posts` rows too, so their dest IDs join the same posts/postmeta scoped WHERE IN()
	 * set as PostSyncStage::get_synced_post_ids()). Mirrors that method's contract exactly,
	 * including the empty-array default for a site job this stage hasn't run for in the
	 * current PHP process.
	 */
	public static function get_synced_media_ids( int $site_job_id ): array {
		return self::$last_synced_media_ids[ $site_job_id ] ?? [];
	}
}
