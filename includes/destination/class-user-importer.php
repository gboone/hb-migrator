<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;
use HBMigrator\SourceClientException;
use HBMigrator\UserSiteRoles;

class UserImporter {

	/**
	 * R7 (docs/plans/2026-07-29-001-fix-audit-report-hardening-plan.md, "U5. UserImporter
	 * batched staged writes"): the per-user loop below accumulates staged write-trail entries
	 * and flushes them via AuditReport::record_batch_for_migration() every this-many users,
	 * instead of one AuditReport::record_for_migration() round-trip per user (which produced an
	 * O(n^2) read-modify-write pattern at real migration scale — each call re-fetched and
	 * re-stored the entire, ever-growing staged-entries option). A modest value balances the
	 * round-trip reduction against failure blast radius: batching trades "1 entry lost per
	 * failure" for "up to this many entries lost per failure" in the pure round-trip-failure
	 * case (the mid-batch-exception case is separately protected by the unconditional flush in
	 * the catch block below).
	 */
	private const STAGED_ENTRY_FLUSH_THRESHOLD = 25;

	public static function process( int $migration_id, int $offset, int $attempt ): void {
		// Declared before the try block — and before anything inside it that could itself throw
		// — so the catch block's exception-path flush below never operates on an undefined
		// variable, no matter how early a failure occurs.
		$staged_entries = [];

		try {
			$migration = MigrationRegistry::get_migration( $migration_id );
			if ( ! $migration ) {
				return;
			}
			if ( 'cancelled' === $migration->status ) {
				return;
			}

			// On a fresh start (or restart), clear any role rows from a prior run before
			// re-inserting. Mid-batch retries (offset > 0) skip this so already-stored
			// roles from earlier batches are preserved.
			if ( 0 === $offset ) {
				UserSiteRoles::delete_for_migration( $migration_id );
			}

			// U3 request-trail capture (see docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md).
			// UserImporter runs once per migration, before any site-job-specific stage, so this is
			// recorded via record_for_migration() (scope: migration) rather than record() — copied
			// into every sibling site job's report the first time each one is created.
			try {
				$users = SourceClient::get(
					$migration->source_url,
					$migration->source_api_key,
					'source/users',
					[ 'per_page' => 100, 'offset' => $offset ]
				);
			} catch ( SourceClientException $e ) {
				AuditReport::record_for_migration( $migration_id, 'migration', [
					'type'    => 'request',
					'path'    => 'source/users',
					'success' => false,
					'error'   => $e->getMessage(),
				] );
				throw $e;
			}

			AuditReport::record_for_migration( $migration_id, 'migration', [
				'type'    => 'request',
				'path'    => 'source/users',
				'success' => true,
				'count'   => count( $users ),
			] );

			wp_suspend_cache_invalidation( true );

			$policy = $migration->user_conflict_policy ?? 'merge';

			// Suppress all outgoing mail during user creation — core and plugins alike
			// hook user_register and send new-user notifications; pre_wp_mail short-
			// circuits wp_mail() before any message is composed (available since WP 5.7).
			$suppress_mail = static function (): \WP_Error {
				return new \WP_Error( 'hbm_suppressed', '' );
			};
			add_filter( 'pre_wp_mail', $suppress_mail );

			foreach ( $users as $u ) {
				$dest_user_id = null;
				// U4 write-action trail (see docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md):
				// reuses this loop's own existing merge-vs-create control-flow signal — no new
				// bookkeeping needed beyond tracking which branch was taken.
				$outcome      = null;

				if ( 'merge' === $policy ) {
					$existing = get_user_by( 'email', $u['user_email'] );
					if ( $existing ) {
						$dest_user_id = $existing->ID;
						$outcome      = 'merged';
					}
				}

				if ( null === $dest_user_id ) {
					$email     = $u['user_email'];
					$user_data = [
						'user_login'      => self::unique_login( $u['user_login'] ),
						'user_email'      => $email,
						'display_name'    => $u['display_name'],
						'user_registered' => $u['user_registered'],
						'user_url'        => $u['user_url'],
						'first_name'      => $u['first_name'] ?? '',
						'last_name'       => $u['last_name'] ?? '',
						'description'     => $u['description'] ?? '',
					];

					$new_id = wp_insert_user( $user_data );

					if ( is_wp_error( $new_id ) && 'create' === $policy ) {
						$email                  = self::make_unique_email( $u['user_email'], $u['user_login'], $migration->source_url );
						$user_data['user_email'] = $email;
						$new_id                  = wp_insert_user( $user_data );
					}

					if ( is_wp_error( $new_id ) ) {
						$staged_entries[] = [
							'type'        => 'write',
							'object_type' => 'user',
							'source_id'   => (int) $u['source_user_id'],
							'outcome'     => 'failed',
							'error'       => $new_id->get_error_message(),
						];
						self::maybe_flush_staged_entries( $migration_id, $staged_entries );
						continue;
					}

					$dest_user_id = (int) $new_id;
					$outcome      = 'created';

					if ( 'create' === $policy ) {
						update_user_meta( $dest_user_id, 'hbm_original_email', $u['user_email'] );
					}
				}

				IdMap::set( IdMap::NETWORK, 'user', (int) $u['source_user_id'], $dest_user_id );

				$staged_entries[] = [
					'type'        => 'write',
					'object_type' => 'user',
					'source_id'   => (int) $u['source_user_id'],
					'dest_id'     => $dest_user_id,
					'outcome'     => $outcome,
				];

				// Store per-site roles so TermImporter can assign them after subsite creation
				// without making additional HTTP requests.
				foreach ( $u['site_roles'] as $sr ) {
					UserSiteRoles::store( $migration_id, (int) $u['source_user_id'], (int) $sr['blog_id'], $sr['role'] );
				}

				self::maybe_flush_staged_entries( $migration_id, $staged_entries );
			}

			remove_filter( 'pre_wp_mail', $suppress_mail );
			wp_suspend_cache_invalidation( false );

			// R7 unconditional flush (see docs/plans/2026-07-29-001-fix-audit-report-hardening-
			// plan.md, "U5"): whatever remains in the accumulator below the periodic threshold
			// must not be silently dropped just because the loop ended before reaching it. Placed
			// once here, before either of the two success exits below, so both are covered.
			self::flush_staged_entries( $migration_id, $staged_entries );

			// Circuit breaker: cap at 100k users to prevent a looping source from
			// holding the pipeline open indefinitely.
			$max_users = 100000;
			if ( count( $users ) >= 100 && ( $offset + 100 ) < $max_users ) {
				as_enqueue_async_action(
					'hbm_import_network_users',
					[ 'migration_id' => $migration_id, 'offset' => $offset + 100, 'attempt' => 0 ],
					'hb-migrator'
				);
				return;
			}

			// All users done — kick off per-site term import.
			$jobs = MigrationRegistry::get_site_jobs_for_migration( $migration_id );
			foreach ( $jobs as $job ) {
				as_enqueue_async_action(
					'hbm_import_terms',
					[ 'site_job_id' => (int) $job->id, 'offset' => 0, 'attempt' => 0 ],
					'hb-migrator'
				);
			}

		} catch ( \Throwable $e ) {
			if ( isset( $suppress_mail ) ) {
				remove_filter( 'pre_wp_mail', $suppress_mail );
			}
			wp_suspend_cache_invalidation( false );

			// R7 exception-path flush (docs/plans/2026-07-29-001-fix-audit-report-hardening-
			// plan.md, "U5"): the one genuinely new failure mode this refactor introduces — with
			// per-user-immediate writes, each already-processed user's entry was already durable
			// by the time a later user's processing could throw, so no equivalent loss risk
			// existed before. Flush best-effort, before the retry-or-fail decision below, so
			// whatever was accumulated so far survives the exception.
			self::flush_staged_entries( $migration_id, $staged_entries );

			// UserImporter is network-level; on retry exhaustion, fail the whole migration.
			$max = (int) apply_filters( 'hbm_max_retries', 3 );
			if ( $attempt < $max ) {
				$delay = 60 * ( 2 ** $attempt );
				as_schedule_single_action(
					time() + $delay,
					'hbm_import_network_users',
					[ 'migration_id' => $migration_id, 'offset' => $offset, 'attempt' => $attempt + 1 ],
					'hb-migrator'
				);
			} else {
				MigrationRegistry::fail_migration( $migration_id, $e->getMessage() );
			}
		}
	}

	/**
	 * Flushes $staged_entries (if non-empty) to AuditReport::record_batch_for_migration() and
	 * clears the accumulator by reference — called at every flush point in process() (periodic,
	 * both success exits, and the exception path) so each call site doesn't repeat the
	 * empty-check/clear dance itself.
	 */
	private static function flush_staged_entries( int $migration_id, array &$staged_entries ): void {
		if ( empty( $staged_entries ) ) {
			return;
		}

		AuditReport::record_batch_for_migration( $migration_id, 'migration', $staged_entries );
		$staged_entries = [];
	}

	/**
	 * R7 periodic flush: called once per loop iteration, after that iteration's entry has been
	 * accumulated. Flushes only once the accumulator reaches STAGED_ENTRY_FLUSH_THRESHOLD — the
	 * two unconditional flush call sites in process() (both success exits, and the exception
	 * path) cover whatever remains below this threshold when the loop ends early.
	 */
	private static function maybe_flush_staged_entries( int $migration_id, array &$staged_entries ): void {
		if ( count( $staged_entries ) >= self::STAGED_ENTRY_FLUSH_THRESHOLD ) {
			self::flush_staged_entries( $migration_id, $staged_entries );
		}
	}

	private static function unique_login( string $login ): string {
		$login    = sanitize_user( $login, true );
		$original = $login;
		$i        = 1;
		while ( username_exists( $login ) ) {
			$login = $original . $i;
			$i++;
		}
		return $login;
	}

	private static function make_unique_email( string $original_email, string $login, string $source_url ): string {
		$source_domain = wp_parse_url( $source_url, PHP_URL_HOST ) ?: 'imported';
		$base          = sanitize_user( $login, true );
		$candidate     = $base . '+imported@' . $source_domain;
		$i             = 2;
		while ( email_exists( $candidate ) && $i < 1000 ) {
			$candidate = $base . '+imported' . $i . '@' . $source_domain;
			$i++;
		}
		return $candidate;
	}
}
