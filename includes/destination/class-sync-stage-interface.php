<?php

namespace HBMigrator\Destination;

/**
 * Contract for one stage of a sync pass (posts, media, comments, scoped search-replace).
 *
 * SyncDispatcher::run_sync_pass() (U2) depends only on this interface, never on a concrete
 * stage class — U3 (posts), U4 (media), U5 (comments), and U6 (scoped search-replace) each
 * supply a real implementation later and register it via the `hbm_sync_stages` filter,
 * without SyncDispatcher's own contract or dependency direction changing. See
 * docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "### U2. Sync pass
 * dispatcher and concurrency guard".
 */
interface SyncStageInterface {

	/**
	 * Processes up to this stage's own row budget for the given site job, mirroring the
	 * row-budget-and-self-requeue convention every existing importer in this codebase
	 * already follows (PostImporter/TermImporter at 100 rows, MediaImporter at 50,
	 * SearchReplace on a 50-second TIME_LIMIT). The stage owns and advances whatever
	 * cursor column belongs to its own content type (e.g. sync_cursor_posts) — the
	 * dispatcher does not know or manage that shape.
	 *
	 * @param int $site_job_id
	 * @return bool True if more work remains for this stage and it should be invoked
	 *              again before the pass can be considered caught up — the dispatcher
	 *              will release the lock and self-requeue a continuation rather than
	 *              loop past this stage's row budget in one call. False if the stage has
	 *              no more work for now.
	 */
	public function process( int $site_job_id ): bool;
}
