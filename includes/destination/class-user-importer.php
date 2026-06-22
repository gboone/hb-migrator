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

			$users = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/users',
				[ 'per_page' => 100, 'offset' => $offset ]
			);

			wp_suspend_cache_invalidation( true );

			foreach ( $users as $u ) {
				$existing = get_user_by( 'email', $u['user_email'] );

				if ( $existing ) {
					$dest_user_id = $existing->ID;
				} else {
					$user_data = [
						'user_login'      => self::unique_login( $u['user_login'] ),
						'user_email'      => $u['user_email'],
						'display_name'    => $u['display_name'],
						'user_registered' => $u['user_registered'],
						'user_url'        => $u['user_url'],
						'first_name'      => $u['first_name'] ?? '',
						'last_name'       => $u['last_name'] ?? '',
						'description'     => $u['description'] ?? '',
					];

					$dest_user_id = wp_insert_user( $user_data );
					if ( is_wp_error( $dest_user_id ) ) {
						continue;
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

			if ( count( $users ) >= 100 ) {
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
}
