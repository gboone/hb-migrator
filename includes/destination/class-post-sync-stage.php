<?php

namespace HBMigrator\Destination;

use HBMigrator\MigrationRegistry;
use HBMigrator\SourceClient;

/**
 * U3 sync stage: syncs new and edited posts (R4, R11) on every sync pass. Registered under
 * the 'posts' slot of the `hbm_sync_stages` filter (see Plugin::register_action_hooks() and
 * SyncDispatcher::get_stages()).
 *
 * Reuses PostReader (source) and PostImporter::import_batch() (destination) — the same
 * Reader/Importer/IdMap pipeline the initial migration uses — invoked again with a delta
 * cursor instead of a parallel content-moving mechanism, per the plan Summary's core
 * validated shape.
 *
 * Row budget matches PostImporter's own per-invocation batch size (100 — see
 * SourceClient::get() call below) so a single process() call never processes an unbounded
 * amount of work; SyncDispatcher self-requeues a continuation when this stage reports more
 * work remains (return true), per SyncStageInterface's contract.
 *
 * @see docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U3. Post
 *      delta sync"
 */
class PostSyncStage implements SyncStageInterface {

	private const BATCH_SIZE = 100;

	/**
	 * Fallback floor for a site job's very first sync pass (sync_cursor_posts is still
	 * NULL — Enable Sync has run but no pass has completed yet). Deliberately NOT "now" —
	 * a QA window commonly happens between initial-migration-complete and Enable Sync, and
	 * edits made during that window need to be caught by the first pass, not skipped.
	 * Combined with the ID > last_id branch (via IdMap's high-water mark below), this
	 * causes the first pass to re-check every already-migrated post for edits, in ID order,
	 * paginated across as many self-requeued batches as needed — the same row-budget-and-
	 * requeue convention every other importer in this codebase already follows.
	 */
	private const EPOCH = '1970-01-01 00:00:00';

	/**
	 * Source post IDs touched (fetched and passed to PostImporter::import_batch()) by the
	 * most recent process() call for a given site_job_id, keyed by site_job_id. Lets U4's
	 * MediaSyncStage include an attachment whose parent post was just synced this pass even
	 * when the attachment's own post_modified didn't change (e.g. a featured image swapped
	 * on an edited post) — see get_synced_post_ids().
	 *
	 * An in-memory static, not a DB column or transient: SyncDispatcher::run_sync_pass()
	 * runs every registered stage in order (posts, then media, ...) within one PHP
	 * request/process (see class-sync-dispatcher.php), so PostSyncStage::process() always
	 * runs immediately before MediaSyncStage::process() reads this for the same pass — no
	 * cross-request persistence is needed, and none is attempted.
	 *
	 * @var array<int, int[]>
	 */
	private static array $last_synced_post_ids = [];

	public function process( int $site_job_id ): bool {
		global $wpdb;

		// Reset up front so a pass that exits early below (job/migration missing) never
		// leaves a stale prior pass's IDs visible to get_synced_post_ids().
		self::$last_synced_post_ids[ $site_job_id ] = [];

		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return false;
		}

		$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
		if ( ! $migration ) {
			return false;
		}

		// Highest source post ID this site job has ever imported (initial migration or a
		// prior sync pass). Guarantees a genuinely new post is never missed even in the
		// (unlikely) case its post_modified value doesn't clear the modified_since overlap
		// floor. IdMap is not purged until finalize (U1), so this stays available for the
		// entire sync window.
		$id_map_table = $wpdb->base_prefix . 'hbm_id_map';
		$last_id      = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(source_id) FROM `{$id_map_table}` WHERE site_job_id = %d AND object_type = 'post'",
			$site_job_id
		) );

		$prior_cursor   = $job->sync_cursor_posts;
		$modified_since = $prior_cursor ?: self::EPOCH;

		$posts = SourceClient::get(
			$migration->source_url,
			$migration->source_api_key,
			'source/sites/' . (int) $job->source_blog_id . '/posts',
			[
				'per_page'       => self::BATCH_SIZE,
				'last_id'        => $last_id,
				'modified_since' => $modified_since,
			]
		);

		self::$last_synced_post_ids[ $site_job_id ] = array_map( fn( $p ) => (int) $p['ID'], $posts );

		if ( empty( $posts ) ) {
			// Nothing new or edited since the last pass — already caught up.
			return false;
		}

		// Import first; only advance the cursor if the batch is fully processed without
		// throwing (an exception here propagates straight out to SyncDispatcher's catch
		// block, so the cursor-advance line below is simply never reached — cursor only
		// advances on success, per plan R11/U3 test scenarios).
		PostImporter::import_batch( $site_job_id, $posts );

		$max_modified = $prior_cursor;
		foreach ( $posts as $p ) {
			$modified = $p['post_modified'] ?? null;
			if ( $modified && ( null === $max_modified || $modified > $max_modified ) ) {
				$max_modified = $modified;
			}
		}

		if ( $max_modified && $max_modified !== $prior_cursor ) {
			MigrationRegistry::update_site_job( $site_job_id, [ 'sync_cursor_posts' => $max_modified ] );
		}

		// A full batch means more matching posts likely remain beyond this row budget —
		// mirrors PostImporter::process()'s own `count( $posts ) >= 100` continuation check.
		return count( $posts ) >= self::BATCH_SIZE;
	}

	/**
	 * Source post IDs fetched by this stage's most recent process() call for the given
	 * site_job_id — used by MediaSyncStage (U4) as the `parent_ids` param passed to
	 * MediaReader, so an attachment whose parent post was just synced this pass is included
	 * even without its own post_modified change. Returns an empty array when this stage
	 * hasn't run for this site_job_id in the current PHP process (e.g. isolated tests, or a
	 * pass that exited early), which is the correct "nothing to add" default.
	 */
	public static function get_synced_post_ids( int $site_job_id ): array {
		return self::$last_synced_post_ids[ $site_job_id ] ?? [];
	}
}
