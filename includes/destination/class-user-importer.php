<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;
use HBMigrator\UserSiteRoles;

class UserImporter {

	public static function process( int $migration_id, int $offset, int $attempt ): void {
		try {
			$migration = MigrationRegistry::get_migration( $migration_id );
			if ( ! $migration ) {
				return;
			}

			// On a fresh start (or restart), clear any role rows from a prior run before
			// re-inserting. Mid-batch retries (offset > 0) skip this so already-stored
			// roles from earlier batches are preserved.
			if ( 0 === $offset ) {
				UserSiteRoles::delete_for_migration( $migration_id );
			}

			$users = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/users',
				[ 'per_page' => 100, 'offset' => $offset ]
			);

			wp_suspend_cache_invalidation( true );

			$policy = $migration->user_conflict_policy ?? 'merge';

			foreach ( $users as $u ) {
				$dest_user_id = null;

				if ( 'merge' === $policy ) {
					$existing = get_user_by( 'email', $u['user_email'] );
					if ( $existing ) {
						$dest_user_id = $existing->ID;
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
						continue;
					}

					$dest_user_id = (int) $new_id;

					if ( 'create' === $policy ) {
						update_user_meta( $dest_user_id, 'hbm_original_email', $u['user_email'] );
					}
				}

				IdMap::set( IdMap::NETWORK, 'user', (int) $u['source_user_id'], $dest_user_id );

				// Store per-site roles so TermImporter can assign them after subsite creation
				// without making additional HTTP requests.
				foreach ( $u['site_roles'] as $sr ) {
					UserSiteRoles::store( $migration_id, (int) $u['source_user_id'], (int) $sr['blog_id'], $sr['role'] );
				}
			}

			wp_suspend_cache_invalidation( false );

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
			wp_suspend_cache_invalidation( false );

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
