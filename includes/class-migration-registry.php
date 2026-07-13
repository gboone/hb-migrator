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
			as_unschedule_all_actions( 'hb-migrator' );
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
	 * Atomically marks the migration complete only when ALL site jobs are 'complete'.
	 * Returns true only if this call won (rows_affected = 1).
	 *
	 * The NOT EXISTS subquery replaces the previous two-step all_sites_complete()
	 * + UPDATE pattern. On WordPress VIP, HyperDB can route a read to a replica that
	 * hasn't caught up, causing all_sites_complete() to return false even after every
	 * job finished and leaving the migration permanently stuck in 'running'. A single
	 * UPDATE statement eliminates that replica-lag window entirely.
	 *
	 * The source_api_key wipe and hbm_id_map cleanup this method used to always perform
	 * on completion are now skipped whenever the migration has at least one site job
	 * capable of entering sync (status 'complete' with a live, non-deleted destination
	 * subsite — the same precondition "Enable Sync" itself checks). Wiping the credential
	 * or deleting the IdMap out from under a job that could still sync would make every
	 * later sync pass fail outright. That cleanup instead runs per-job in
	 * finalize_site_job_sync() once a job's sync window actually ends. A migration none of
	 * whose jobs are sync-capable (e.g. every destination subsite was already deleted)
	 * still gets today's immediate cleanup — see docs/plans/2026-07-13-002-feat-post-migration-content-sync-plan.md,
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
			           AND j.status != 'complete'
			    )",
			$id,
			$id
		) );
		if ( $result ) {
			$jobs = self::get_site_jobs_for_migration( $id );

			$sync_capable = false;
			foreach ( $jobs as $job ) {
				if ( self::is_job_sync_capable( $job ) ) {
					$sync_capable = true;
					break;
				}
			}

			if ( $sync_capable ) {
				// Deferred to finalize_site_job_sync() for whichever job(s) actually sync.
			} else {
				foreach ( $jobs as $job ) {
					IdMap::delete_for_job( (int) $job->id );
				}
				$wpdb->update( $table, [ 'source_api_key' => '' ], [ 'id' => $id ] );
			}

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
	 * Moves a site job from 'syncing' to the terminal 'finalized' state and runs, for just
	 * that job, the credential-wipe/IdMap cleanup complete_migration() deferred when the job
	 * became sync-capable (see complete_migration() docblock). The migration's shared
	 * source_api_key is only wiped once none of its site jobs could still need it (i.e. none
	 * remain 'complete' or 'syncing') — other jobs on the same migration may still be
	 * mid-sync and need the credential to keep authenticating to source.
	 */
	public static function finalize_site_job_sync( int $site_job_id ): bool {
		global $wpdb;
		$table  = $wpdb->base_prefix . 'hbm_site_jobs';
		$result = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE `{$table}`
			    SET status = 'finalized', sync_finalized_at = NOW()
			  WHERE id = %d
			    AND status = 'syncing'",
			$site_job_id
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
	 * Wipes hbm_migrations.source_api_key only once no site job on the migration is still
	 * 'complete' or 'syncing' — i.e. no job remains that could either enable sync or is
	 * already mid-sync and needs the credential for its next pass.
	 */
	private static function maybe_wipe_migration_credential( int $migration_id ): void {
		global $wpdb;
		$jobs = self::get_site_jobs_for_migration( $migration_id );
		foreach ( $jobs as $job ) {
			if ( in_array( $job->status, [ 'complete', 'syncing' ], true ) ) {
				return;
			}
		}
		$wpdb->update( $wpdb->base_prefix . 'hbm_migrations', [ 'source_api_key' => '' ], [ 'id' => $migration_id ] );
	}
}
