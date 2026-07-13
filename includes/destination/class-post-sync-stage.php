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

		// Import first; only advance the cursor for the prefix of this batch that was
		// actually imported successfully. An exception here propagates straight out to
		// SyncDispatcher's catch block, so the cursor-advance loop below is never reached on
		// a hard failure — cursor only advances on success, per plan R11/U3 test scenarios.
		$result        = PostImporter::import_batch( $site_job_id, $posts );
		$failed_id_set = array_flip( $result['failed_ids'] );

		// The cursor cannot simply become "max post_modified fetched in this batch": a post
		// that failed wp_insert_post()/wp_update_post() (e.g. a transient DB error) was
		// skipped by PostImporter, not written — advancing the cursor past its position would
		// exclude it from every future `modified_since` fetch and drop it permanently,
		// contradicting R11's "skipped this pass, retried on the next one, not dropped" —
		// exactly the bug CommentSyncStage already avoids for its own kind of per-item skip
		// (see that class's docblock). Posts are not guaranteed to arrive in modified-time
		// order, so walking the batch in fetch order and stopping the cursor's forward
		// extension at the first failed item (even if later items in the batch succeeded)
		// gives a safe high-water mark: everything before the failure is durably synced,
		// and it plus everything from that point in this batch is re-fetched and
		// re-attempted next pass. Re-fetching an already-succeeded post ahead of a failed one
		// is safe — PostImporter's IdMap-keyed existing-row check makes that a no-op update.
		$max_modified = $prior_cursor;
		$had_failure  = false;
		foreach ( $posts as $p ) {
			$source_id = (int) ( $p['ID'] ?? 0 );
			if ( isset( $failed_id_set[ $source_id ] ) ) {
				$had_failure = true;
				break;
			}
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
		// A failure also means more work remains this pass (the failed item and everything
		// after it in fetch order still needs to be retried) — without this, a batch that
		// hit a transient failure but didn't fill the row budget would be wrongly treated as
		// "caught up" and only retried on the next scheduled cron/webhook pass instead of
		// immediately. Known, accepted trade-off (matching this plan's existing
		// CommentSyncStage blocked-parent gap): a source post that fails every single import
		// attempt (a permanent data problem, not a transient one) will cause this stage to
		// self-requeue indefinitely rather than skip past it — solving that poison-item case
		// is out of scope here; this fix only targets the silent-skip data-loss bug for
		// transient failures.
		return $had_failure || count( $posts ) >= self::BATCH_SIZE;
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
