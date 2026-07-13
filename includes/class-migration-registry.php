<?php

namespace HBMigrator;

class MigrationRegistry {

	// -----------------------------------------------------------------------
	// Migrations
	// -----------------------------------------------------------------------

	public static function create_migration( string $source_url, string $source_api_key, ?string $email, array $policies = [] ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'hbm_migrations',
			[
				'source_url'            => $source_url,
				'source_api_key'        => $source_api_key,
				'status'                => 'pending',
				'status_token'          => bin2hex( random_bytes( 16 ) ),
				'notification_email'    => $email,
				'user_conflict_policy'  => $policies['user_conflict_policy']  ?? 'merge',
				'site_conflict_policy'  => $policies['site_conflict_policy']  ?? 'generate_new',
				'media_conflict_policy' => $policies['media_conflict_policy'] ?? 'import_all',
				'media_import_scope'    => $policies['media_import_scope']    ?? 'all',
			]
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_conflict_policies( int $migration_id ): array {
		$migration = self::get_migration( $migration_id );
		return [
			'user_conflict_policy'  => $migration->user_conflict_policy  ?? 'merge',
			'site_conflict_policy'  => $migration->site_conflict_policy  ?? 'generate_new',
			'media_conflict_policy' => $migration->media_conflict_policy ?? 'import_all',
			'media_import_scope'    => $migration->media_import_scope    ?? 'all',
		];
	}

	public static function get_migration( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_migrations';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ) );
	}

	public static function find_active_migration_for_source( string $source_url ): ?object {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_migrations';
		// Include 'failed' so begin() can restart a failed migration rather than
		// silently creating a second one that duplicates all content.
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE source_url = %s AND status IN ('pending', 'running', 'failed') ORDER BY id DESC LIMIT 1",
			$source_url
		) );
	}

	public static function update_migration( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( $wpdb->base_prefix . 'hbm_migrations', $fields, [ 'id' => $id ] );
	}

	public static function update_migration_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->base_prefix . 'hbm_migrations',
			[ 'status' => $status ],
			[ 'id' => $id ]
		);
	}

	public static function cancel_migration( int $id ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE `{$wpdb->base_prefix}hbm_migrations` SET status = 'cancelled' WHERE id = %d AND status NOT IN ('complete', 'cancelled')",
			$id
		) );
		$cancelled = (bool) $wpdb->rows_affected;
		if ( $cancelled ) {
			// Stop any pending AS actions so importers that haven't fired yet are also halted.
			// In-flight actions that are already executing will exit via the status guard at the
			// top of each importer's process() method.
			//
			// as_unschedule_all_actions( $hook, $args = [], $group = '' ) — the group name must
			// go in the THIRD parameter, not the first (that's $hook). Passing it as $hook alone
			// (the original bug here) matches no scheduled action's hook, so this call silently
			// unscheduled nothing. With $hook = '' and $args = [], the group-only branch inside
			// as_unschedule_all_actions() calls ActionScheduler_Store::cancel_actions_by_group(),
			// which is what actually bulk-cancels every pending action tagged with this group,
			// regardless of hook — see lib/action-scheduler/functions.php.
			as_unschedule_all_actions( '', [], 'hb-migrator' );
		}
		return $cancelled;
	}

	public static function fail_migration( int $id, string $error_message ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->base_prefix . 'hbm_migrations',
			[ 'status' => 'failed', 'error_message' => $error_message ],
			[ 'id' => $id ]
		);
	}

	/**
	 * Atomically marks the migration complete once every site job has either finished the
	 * one-shot pipeline ('complete') or moved beyond it into sync ('syncing', 'finalized').
	 * Returns true only if this call won (rows_affected = 1).
	 *
	 * The NOT EXISTS subquery replaces the previous two-step all_sites_complete()
	 * + UPDATE pattern. On WordPress VIP, HyperDB can route a read to a replica that
	 * hasn't caught up, causing all_sites_complete() to return false even after every
	 * job finished and leaving the migration permanently stuck in 'running'. A single
	 * UPDATE statement eliminates that replica-lag window entirely.
	 *
	 * 'syncing' and 'finalized' were added to the allowed set alongside 'complete' after a
	 * P1 bug: this method is only ever called right after ONE site job's own pipeline just
	 * finished (see SearchReplace::finalize()), so it always re-checks every sibling job too.
	 * If any sibling had already had "Enable Sync" clicked, its status was 'syncing' —
	 * never 'complete' again, since sync cannot be re-enabled — so the original
	 * `j.status != 'complete'` gate failed forever and the migration row stayed 'running'
	 * permanently even though every job was either done or legitimately syncing.
	 *
	 * The source_api_key wipe and hbm_id_map cleanup this method used to always perform
	 * on completion are now skipped per-job (IdMap) / migration-wide (credential) whenever
	 * job_cleanup_is_deferred() says a job could still need them — either it's actively
	 * 'syncing', or it's 'complete' with a live, non-deleted destination subsite (the same
	 * precondition "Enable Sync" itself checks), meaning "Enable Sync" could still succeed
	 * for it. Wiping the credential or deleting the IdMap out from under a job that could
	 * still sync (or already is) would make every later sync pass fail outright. That
	 * cleanup instead runs per-job in finalize_site_job_sync() once a job's sync window
	 * actually ends. A job stuck at 'complete' that can NEVER become sync-capable (e.g. its
	 * destination subsite was deleted) is not deferred — it still gets today's original
	 * immediate cleanup, since waiting on a sync window that will never open would block
	 * that job's own cleanup forever. See
	 * docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md,
	 * "Key Technical Decisions".
	 */
	public static function complete_migration( int $id ): bool {
		global $wpdb;
		$table      = $wpdb->base_prefix . 'hbm_migrations';
		$jobs_table = $wpdb->base_prefix . 'hbm_site_jobs';
		$result     = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE `{$table}`
			    SET status = 'complete', completed_at = NOW()
			  WHERE id = %d
			    AND status = 'running'
			    AND NOT EXISTS (
			        SELECT 1 FROM `{$jobs_table}` j
			         WHERE j.migration_id = %d
			           AND j.status NOT IN ( 'complete', 'syncing', 'finalized' )
			    )",
			$id,
			$id
		) );
		if ( $result ) {
			$jobs = self::get_site_jobs_for_migration( $id );

			foreach ( $jobs as $job ) {
				if ( ! self::job_cleanup_is_deferred( $job ) ) {
					IdMap::delete_for_job( (int) $job->id );
				}
			}
			self::maybe_wipe_migration_credential( $id );

			// Role-assignment data is not needed by sync (roles are only applied once, at
			// subsite creation), so this cleanup is unconditional regardless of sync capability.
			UserSiteRoles::delete_for_migration( $id );
		}
		return (bool) $result;
	}

	/**
	 * Whether a 'complete' site job could still be moved into 'syncing' — i.e. whether
	 * "Enable Sync" would succeed for it right now. Mirrors TermImporter::process()'s
	 * dest_site/deleted live-subsite check (includes/destination/class-term-importer.php).
	 */
	private static function is_job_sync_capable( object $job ): bool {
		if ( 'complete' !== $job->status || empty( $job->dest_blog_id ) ) {
			return false;
		}
		$dest_site = get_site( (int) $job->dest_blog_id );
		return $dest_site && ! (int) $dest_site->deleted;
	}

	/**
	 * Whether a site job's credential/IdMap cleanup must wait rather than run now: true for
	 * a job actively mid-sync ('syncing') or still capable of entering sync from 'complete'
	 * (is_job_sync_capable()) — both represent a real, ongoing need for the migration's
	 * shared source_api_key and this job's own IdMap rows. False for 'finalized' (cleanup
	 * already ran when it finalized) and for a 'complete' job that can never become
	 * sync-capable (e.g. its destination subsite was deleted) — that job should get today's
	 * original immediate cleanup rather than being stuck waiting on a sync window that will
	 * never open. Shared by complete_migration() (per-job IdMap decision) and
	 * maybe_wipe_migration_credential() (migration-wide credential decision) so both answer
	 * "does this job still need it?" identically instead of drifting apart.
	 */
	private static function job_cleanup_is_deferred( object $job ): bool {
		return 'syncing' === $job->status || self::is_job_sync_capable( $job );
	}

	public static function list_migrations(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM `{$wpdb->base_prefix}hbm_migrations` ORDER BY id DESC" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input, table name is hardcoded
	}

	// -----------------------------------------------------------------------
	// Site jobs
	// -----------------------------------------------------------------------

	public static function create_site_job(
		int $migration_id,
		int $source_blog_id,
		string $source_domain,
		string $source_siteurl,
		string $source_upload_url,
		string $dest_path
	): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'hbm_site_jobs',
			[
				'migration_id'      => $migration_id,
				'source_blog_id'    => $source_blog_id,
				'source_domain'     => $source_domain,
				'source_siteurl'    => $source_siteurl,
				'source_upload_url' => $source_upload_url,
				'dest_path'         => $dest_path,
				'status'            => 'pending',
			]
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_site_job( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->base_prefix}hbm_site_jobs` WHERE id = %d", $id ) );
	}

	public static function update_site_job( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( $wpdb->base_prefix . 'hbm_site_jobs', $fields, [ 'id' => $id ] );
	}

	public static function get_site_jobs_for_migration( int $migration_id ): array {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_site_jobs';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE migration_id = %d ORDER BY id", $migration_id )
		) ?: [];
	}

	public static function all_sites_complete( int $migration_id ): bool {
		$jobs = self::get_site_jobs_for_migration( $migration_id );
		if ( empty( $jobs ) ) {
			return false;
		}
		foreach ( $jobs as $job ) {
			if ( 'complete' !== $job->status ) {
				return false;
			}
		}
		return true;
	}

	// -----------------------------------------------------------------------
	// Sync lifecycle
	// -----------------------------------------------------------------------

	/**
	 * Site jobs an operator can currently act on from the sync admin UI: those still
	 * eligible for "Enable Sync" ('complete') and those already syncing. Read live from
	 * hbm_site_jobs (not the hbm_migration_history snapshot the Past Migrations table
	 * reads) so the list can never drift from what the sync actions actually did.
	 */
	public static function get_syncable_site_jobs(): array {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_site_jobs';
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input, table name is hardcoded
			"SELECT * FROM `{$table}` WHERE status IN ('complete', 'syncing') ORDER BY id DESC"
		) ?: [];
	}

	/**
	 * Site jobs belonging to a single migration that are currently 'syncing'. Used by
	 * Admin\AdminPage::handle_clear_migration() to stop every still-syncing site job for a
	 * migration in one action (the Clear button) instead of requiring an operator to visit
	 * the Post-Migration Sync table and click "Finalize & Stop Sync" on each row individually.
	 * cancel_migration() alone cannot cover this case: it is a no-op once the migration is
	 * already 'complete' — which is exactly the state every migration with a 'syncing' site
	 * job is already in, since "Enable Sync" itself requires the job (and therefore the
	 * migration) to have already reached 'complete'. See cancel_migration()'s SQL guard.
	 */
	public static function get_syncing_site_jobs_for_migration( int $migration_id ): array {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_site_jobs';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE migration_id = %d AND status = 'syncing' ORDER BY id", $migration_id )
		) ?: [];
	}

	/**
	 * Moves a site job from 'complete' to 'syncing', generating a fresh sync_webhook_token
	 * (mirrors hbm_migrations.status_token's generation shape — bin2hex(random_bytes(32)),
	 * matching ApiAuth::get_or_create_key()'s randomness convention). The WHERE status =
	 * 'complete' guard is the sole authority on whether the transition is legal: it rejects
	 * a job that isn't 'complete' (including 'finalized', so sync can never be re-enabled)
	 * and, via rows_affected, protects against a double submit racing itself. Callers are
	 * responsible for the live-destination-subsite check (TermImporter's dest_site/deleted
	 * pattern) before calling this, so they can surface a specific error naming the missing
	 * subsite rather than the generic rejection this method alone would produce.
	 */
	public static function enable_site_job_sync( int $site_job_id ): bool {
		global $wpdb;
		$table  = $wpdb->base_prefix . 'hbm_site_jobs';
		$token  = bin2hex( random_bytes( 32 ) );
		$result = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE `{$table}`
			    SET status = 'syncing', sync_enabled_at = NOW(), sync_webhook_token = %s
			  WHERE id = %d
			    AND status = 'complete'",
			$token,
			$site_job_id
		) );
		return (bool) $result;
	}

	/**
	 * Staleness threshold finalize_site_job_sync() uses to decide whether a currently-set
	 * sync_locked_at represents a genuinely in-flight pass (must block finalize) or an
	 * abandoned lock left by a process that died mid-pass (must not block finalize forever).
	 * Deliberately kept equal to SyncDispatcher::STALENESS_MINUTES
	 * (includes/destination/class-sync-dispatcher.php) so "the lock is held" means the same
	 * thing to claim_sync_lock() and to finalize — if that constant ever changes, update
	 * this one too.
	 */
	private const FINALIZE_LOCK_STALENESS_MINUTES = 10;

	/**
	 * Moves a site job from 'syncing' to the terminal 'finalized' state and runs, for just
	 * that job, the credential-wipe/IdMap cleanup complete_migration() deferred when the job
	 * became sync-capable (see complete_migration() docblock). The migration's shared
	 * source_api_key is only wiped once none of its site jobs could still need it (i.e. none
	 * remain 'syncing' or 'complete'-and-still-sync-capable — see job_cleanup_is_deferred())
	 * — other jobs on the same migration may still be mid-sync and need the credential to
	 * keep authenticating to source.
	 *
	 * The UPDATE also requires sync_locked_at to be unset or stale (P0 fix): without this,
	 * an operator clicking "Finalize & Stop Sync" while a sync pass is actively in-flight
	 * (holding a fresh lock via claim_sync_lock()) could have this method delete the
	 * job's IdMap out from under that pass's PostImporter/CommentImporter calls, which use
	 * IdMap::get() to decide insert-vs-update — with the map wiped mid-pass, they'd
	 * re-insert already-migrated posts/comments as duplicates. This is a single atomic
	 * UPDATE (not a SELECT-then-act check) using the same staleness comparison
	 * claim_sync_lock() uses, so "is the lock held" is answered consistently: a fresh lock
	 * blocks finalize; a stale/abandoned one does not (it will self-clear the next time
	 * claim_sync_lock() reclaims and a pass completes, at which point retrying finalize
	 * succeeds). A rejection here is indistinguishable from "job isn't 'syncing'" to the
	 * caller — both are the same "not eligible right now, try again" outcome
	 * handle_finalize_sync() already surfaces to the operator as a request to retry.
	 */
	public static function finalize_site_job_sync( int $site_job_id ): bool {
		global $wpdb;
		$table  = $wpdb->base_prefix . 'hbm_site_jobs';
		$result = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE `{$table}`
			    SET status = 'finalized', sync_finalized_at = NOW()
			  WHERE id = %d
			    AND status = 'syncing'
			    AND ( sync_locked_at IS NULL OR sync_locked_at < NOW() - INTERVAL %d MINUTE )",
			$site_job_id,
			self::FINALIZE_LOCK_STALENESS_MINUTES
		) );
		if ( ! $result ) {
			return false;
		}

		IdMap::delete_for_job( $site_job_id );

		$job = self::get_site_job( $site_job_id );
		if ( $job ) {
			self::maybe_wipe_migration_credential( (int) $job->migration_id );
		}

		return true;
	}

	/**
	 * Atomically claims the sync-pass lock for a site job via a single UPDATE checked via
	 * rows_affected — not a SELECT-then-UPDATE, which would have a real TOCTOU race between
	 * a webhook request (PHP-FPM worker) and a cron tick (separate Action Scheduler worker)
	 * both seeing the lock unclaimed before either writes it. Mirrors complete_migration()'s
	 * atomic UPDATE + rows_affected pattern, adopted there after an earlier two-step
	 * SELECT-then-UPDATE hit a replica-lag race in production — see that method's docblock.
	 *
	 * A lock older than $staleness_minutes is treated as abandoned and reclaimed, the
	 * backstop for a process killed mid-pass (PHP timeout, OOM) that never reached its own
	 * release — otherwise that site job would be locked out of sync permanently. See
	 * docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md, "Key Technical
	 * Decisions" and "Risks & Dependencies" (U2's staleness-threshold trade-off).
	 *
	 * @return bool True only if THIS call claimed the lock (rows_affected === 1); false
	 *              means another pass already holds a fresh lock, or the job is no longer
	 *              'syncing' — both expected "someone else has it" outcomes, not errors.
	 */
	public static function claim_sync_lock( int $site_job_id, int $staleness_minutes ): bool {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_site_jobs';
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE `{$table}`
			    SET sync_locked_at = NOW()
			  WHERE id = %d
			    AND status = 'syncing'
			    AND ( sync_locked_at IS NULL OR sync_locked_at < NOW() - INTERVAL %d MINUTE )",
			$site_job_id,
			$staleness_minutes
		) );
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Releases a sync-pass lock claimed by claim_sync_lock(), unconditionally clearing
	 * sync_locked_at and recording the pass outcome. Callers (SyncDispatcher) invoke this
	 * from a try/finally so a mid-pass exception can never leave sync_locked_at set —
	 * the staleness reclaim in claim_sync_lock() is only the backstop for the cases a
	 * caught exception can't cover (a hard process kill that skips finally entirely).
	 *
	 * Does not touch `status` — a sync-pass failure is non-terminal (R3): the site job
	 * stays 'syncing' regardless of $error_message, unlike PipelineController's
	 * handle_batch_failure(), which marks the whole job 'failed' on retry exhaustion.
	 *
	 * @param string|null $error_message Null clears sync_last_error (successful pass,
	 *                                   including one that still has more work remaining);
	 *                                   non-null records the caught exception's message.
	 */
	public static function release_sync_lock( int $site_job_id, ?string $error_message = null ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_site_jobs';
		if ( null === $error_message ) {
			$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"UPDATE `{$table}`
				    SET sync_locked_at = NULL, sync_last_pass_at = NOW(), sync_last_error = NULL
				  WHERE id = %d",
				$site_job_id
			);
		} else {
			$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"UPDATE `{$table}`
				    SET sync_locked_at = NULL, sync_last_pass_at = NOW(), sync_last_error = %s
				  WHERE id = %d",
				$error_message,
				$site_job_id
			);
		}
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Wipes hbm_migrations.source_api_key only once no site job on the migration still needs
	 * it per job_cleanup_is_deferred() — i.e. none remain 'syncing' or 'complete'-and-still-
	 * sync-capable. A job merely 'complete' but permanently unable to ever sync (destination
	 * subsite deleted) does NOT count as still needing it — treating it as a blocker would
	 * leave the credential (and, via complete_migration(), every other job's IdMap) stuck
	 * un-wiped forever, even after every job that could actually use it has finished or
	 * finalized. This was a real bug: the previous version blocked on bare 'complete'
	 * status regardless of sync capability.
	 */
	private static function maybe_wipe_migration_credential( int $migration_id ): void {
		global $wpdb;
		$jobs = self::get_site_jobs_for_migration( $migration_id );
		foreach ( $jobs as $job ) {
			if ( self::job_cleanup_is_deferred( $job ) ) {
				return;
			}
		}
		$wpdb->update( $wpdb->base_prefix . 'hbm_migrations', [ 'source_api_key' => '' ], [ 'id' => $migration_id ] );
	}
}
