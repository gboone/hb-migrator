<?php

namespace HBMigrator\Destination;

/**
 * Registers and unschedules the per-site-job recurring Action Scheduler action that acts as
 * the cron safety net for post-migration content sync (U7). This is the plugin's first use
 * of `as_schedule_recurring_action()` — every existing scheduled action in this codebase is
 * one-shot (`as_enqueue_async_action()` / `as_schedule_single_action()`), so the Action
 * Scheduler action table will now carry long-lived recurring rows for the duration of every
 * site job's sync window, distinct from the transient one-shot rows the initial migration
 * pipeline produces. See docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md,
 * "System-Wide Impact".
 *
 * Called from Admin\AdminPage::handle_enable_sync() / handle_finalize_sync() rather than from
 * MigrationRegistry directly: MigrationRegistry is a plain data-layer class with no existing
 * dependency on any Destination\* class or on Action Scheduler, and every other call in this
 * codebase that crosses from a domain event into scheduling infrastructure (see
 * Plugin::register_action_hooks()) is wired at the controller layer, not the data layer.
 * Keeping that boundary intact avoids a new circular relationship between
 * class-migration-registry.php (HBMigrator) and class-sync-scheduler.php
 * (HBMigrator\Destination).
 */
class SyncScheduler {

	/**
	 * Cron safety-net cadence, in seconds: how often `SyncDispatcher::run_sync_pass()` fires
	 * for a site job independent of the webhook trigger (U8). Filterable via `hbm_sync_interval`
	 * following the existing `apply_filters( 'hbm_max_retries', 3 )` precedent (see
	 * PipelineController, MediaImporter, UserImporter) so operators and tests can override it.
	 * Fifteen minutes balances staying reasonably current against source with not hammering
	 * source with polling; it's also the reference point the plan's cursor-overlap window
	 * (U3/U4) scales against — see "Risks & Dependencies" in
	 * docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md.
	 */
	private const DEFAULT_INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Registers the recurring `hbm_sync_pass` action for a site job, scheduled to first fire
	 * one interval from now. Called once, from Admin\AdminPage::handle_enable_sync(), right
	 * after MigrationRegistry::enable_site_job_sync() succeeds — a duplicate call (e.g. a
	 * retried request) is harmless: Action Scheduler's recurring-action storage does not
	 * de-duplicate by default, so callers must not call this more than once per site job
	 * without a corresponding unschedule() first, which handle_enable_sync()'s 'complete' ->
	 * 'syncing' guard (rejecting a second Enable Sync for the same job) already ensures.
	 */
	public static function schedule( int $site_job_id ): void {
		$interval = self::interval_seconds();
		as_schedule_recurring_action(
			time() + $interval,
			$interval,
			SyncDispatcher::ACTION_HOOK,
			[ 'site_job_id' => $site_job_id ],
			'hb-migrator'
		);
	}

	/**
	 * Unschedules every pending `hbm_sync_pass` action for a site job — both the recurring
	 * action registered by schedule() and any one-shot continuation SyncDispatcher::
	 * run_sync_pass() self-requeued via as_enqueue_async_action() for the same hook, group,
	 * and site_job_id arg — so no further pass, scheduled or in-queue, can fire once sync is
	 * finalized. Scoped by hook + args + group, mirroring MigrationRegistry::cancel_migration()'s
	 * use of as_unschedule_all_actions() as the precedent for cleanly stopping scheduled work
	 * tied to a specific ID; unlike cancel_migration()'s plugin-wide unschedule, this call is
	 * scoped to a single site job so finalizing one site job's sync never touches another's
	 * still-active recurring action.
	 */
	public static function unschedule( int $site_job_id ): void {
		as_unschedule_all_actions(
			SyncDispatcher::ACTION_HOOK,
			[ 'site_job_id' => $site_job_id ],
			'hb-migrator'
		);
	}

	private static function interval_seconds(): int {
		return (int) apply_filters( 'hbm_sync_interval', self::DEFAULT_INTERVAL_SECONDS );
	}
}
