<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;

class UserImporter {

	public static function process( int $migration_id, int $offset, int $attempt ): void {
		try {
			global $wpdb;

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

					// Restore the original password hash directly — avoids double-hashing.
					$wpdb->update(
						$wpdb->users,
						[ 'user_pass' => $u['user_pass'] ],
						[ 'ID' => $dest_user_id ]
					);
					clean_user_cache( $dest_user_id );
				}

				// Map source user ID → dest user ID at network level (site_job_id = 0).
				IdMap::set( IdMap::NETWORK, 'user', (int) $u['source_user_id'], $dest_user_id );
				// Blog role assignment happens in TermImporter after each subsite is created,
				// since dest_blog_id is NULL until that point.
			}

			wp_suspend_cache_invalidation( false );

			if ( count( $users ) >= 100 ) {
				// More pages to process.
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
			PipelineController::handle_batch_failure(
				'hbm_import_network_users',
				[ 'migration_id' => $migration_id, 'offset' => $offset, 'attempt' => $attempt ],
				$e
			);
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
