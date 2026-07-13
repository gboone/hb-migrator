<?php

namespace HBMigrator\Destination;

use HBMigrator\MigrationRegistry;

/**
 * Single entry point both the cron poll (U7) and the webhook receiver (U8) call to run one
 * sync pass for a site job — this convergence is what prevents the two triggers from
 * diverging into two different sync implementations (see plan "Key Technical Decisions",
 * "A single dispatcher, not per-trigger logic").
 *
 * Claims a per-site-job lock via MigrationRegistry::claim_sync_lock(), a single atomic
 * UPDATE checked via $wpdb->rows_affected, so a webhook-triggered pass and a cron-triggered
 * pass for the same site job never run concurrently (R12). Do NOT weaken this back to a
 * SELECT-then-UPDATE — that has a real TOCTOU race between the two triggers; see
 * MigrationRegistry::claim_sync_lock()'s docblock and the plan's "Key Technical Decisions".
 *
 * On successful claim, runs each registered stage in order (posts, media, comments, scoped
 * search-replace). This class depends only on SyncStageInterface, never on a concrete
 * U3-U6 stage class — real stages register themselves via the `hbm_sync_stages` filter once
 * those units land; until then, the default stages are no-ops that report "no more work"
 * immediately, so a pass just claims the lock, does nothing, and releases it.
 *
 * A sync-pass failure is non-terminal (R3): a stage throwing is caught, recorded to
 * sync_last_error, and does NOT change the site job's status — unlike
 * PipelineController::handle_batch_failure(), which marks the whole job failed on retry
 * exhaustion. The lock is released on every path — success, "more work remains", and a
 * caught exception — via a try/finally, so a mid-pass exception can never leave
 * sync_locked_at set.
 */
class SyncDispatcher {

	/**
	 * Action Scheduler hook this dispatcher self-requeues under when a stage reports more
	 * work remains, mirroring the row-budget-and-requeue convention every other importer in
	 * this codebase follows. Registered to [ self::class, 'run_sync_pass' ] in
	 * Plugin::register_action_hooks().
	 */
	public const ACTION_HOOK = 'hbm_sync_pass';

	/**
	 * Reclaims a lock left behind by a process that died mid-pass (PHP timeout, OOM) rather
	 * than leaving the site job locked out of sync indefinitely. Set comfortably above the
	 * worst-case duration of a single stage's own row-budgeted batch: every existing
	 * importer this dispatcher mirrors checkpoints well under a minute (PostImporter and
	 * TermImporter cap at 100 rows per batch, MediaImporter at 50 rows including file
	 * downloads, SearchReplace checkpoints on its own 50-second TIME_LIMIT per phase). Ten
	 * minutes leaves generous headroom for a slow HTTP round-trip to source without either
	 * reclaiming a lock from a pass that's still legitimately running (which would allow a
	 * genuine double-run) or leaving a genuinely dead one locked out for long. See
	 * docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "Risks &
	 * Dependencies" — U2's lock-staleness threshold trade-off.
	 */
	private const STALENESS_MINUTES = 10;

	/**
	 * Runs one sync pass for a site job. Both triggers (cron, webhook) call this with only
	 * a site_job_id — everything else needed to run the pass is read from hbm_site_jobs.
	 */
	public static function run_sync_pass( int $site_job_id ): void {
		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || 'syncing' !== $job->status ) {
			// Not currently syncing (never enabled, already finalized, or cancelled mid-way)
			// — a no-op with zero side effects, not an error.
			return;
		}

		if ( ! MigrationRegistry::claim_sync_lock( $site_job_id, self::STALENESS_MINUTES ) ) {
			// Another pass already holds a fresh lock for this site job (or the status
			// changed between the check above and the claim attempt) — the expected
			// "someone else already has it" outcome, not a failure. That in-flight pass,
			// or the next scheduled attempt, covers this site job.
			return;
		}

		$more_work = false;
		$error     = null;

		try {
			foreach ( self::get_stages( $site_job_id ) as $stage ) {
				if ( $stage->process( $site_job_id ) ) {
					$more_work = true;
				}
			}
		} catch ( \Throwable $e ) {
			$error = $e;
		} finally {
			// Runs on every path — success, "more work remains", and a caught exception —
			// so sync_locked_at can never be left set once this method returns.
			MigrationRegistry::release_sync_lock( $site_job_id, $error ? $error->getMessage() : null );
		}

		if ( $error ) {
			// Non-terminal (R3): status stays 'syncing'. Do not self-requeue here — the
			// existing cron schedule (U7) or a future webhook call will retry naturally;
			// requeuing immediately on every failure would risk a tight failure loop with
			// no backoff, unlike PipelineController::handle_batch_failure()'s exponential
			// delay for the initial-migration pipeline.
			return;
		}

		if ( $more_work ) {
			as_enqueue_async_action( self::ACTION_HOOK, [ 'site_job_id' => $site_job_id ], 'hb-migrator' );
		}
	}

	/**
	 * Ordered stage slots for a sync pass. Filterable so U3-U6 can register their real
	 * implementations without this class ever depending on them directly, and so tests can
	 * substitute stub stages without touching production wiring.
	 *
	 * @return SyncStageInterface[] Keyed by stage slot name for readability; iteration
	 *                              order (posts, media, comments, search_replace) is what
	 *                              the dispatcher actually runs against.
	 */
	private static function get_stages( int $site_job_id ): array {
		return apply_filters( 'hbm_sync_stages', self::default_stages(), $site_job_id );
	}

	/**
	 * Until U3-U6 land, every slot is a no-op stage that immediately reports "no more
	 * work" — a sync pass on a job with no real stages registered just claims the lock,
	 * does nothing, and releases it.
	 */
	private static function default_stages(): array {
		$noop = new class() implements SyncStageInterface {
			public function process( int $site_job_id ): bool {
				return false;
			}
		};
		return [
			'posts'          => $noop,
			'media'          => $noop,
			'comments'       => $noop,
			'search_replace' => $noop,
		];
	}
}
