<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;
use HBMigrator\UserSiteRoles;

class TermImporter {

	public static function process( int $site_job_id, int $offset, int $attempt ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job ) {
				return;
			}

			$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
			if ( ! $migration ) {
				return;
			}

			// Create destination subsite on first batch.
			if ( null === $job->dest_blog_id || 0 === (int) $job->dest_blog_id ) {
				$site_policy  = $migration->site_conflict_policy ?? 'generate_new';
				$dest_blog_id = self::create_subsite( $job, $site_policy );
				MigrationRegistry::update_site_job( $site_job_id, [
					'dest_blog_id'  => $dest_blog_id,
					'status'        => 'running',
					'current_stage' => 'terms',
					'dest_path'     => $job->dest_path, // may have been updated by generate_new suffix
				] );
				$job->dest_blog_id = $dest_blog_id;
				// Assign roles from DB — no HTTP calls needed.
				self::assign_user_roles( (int) $job->migration_id, (int) $job->source_blog_id, $dest_blog_id );
			} else {
				MigrationRegistry::update_site_job( $site_job_id, [
					'status'        => 'running',
					'current_stage' => 'terms',
				] );
			}

			$terms = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/sites/' . (int) $job->source_blog_id . '/terms',
				[ 'per_page' => 100, 'offset' => $offset ]
			);

			switch_to_blog( (int) $job->dest_blog_id );

			// First pass: insert terms without parent (parents may not exist yet).
			$pending_parents = [];
			foreach ( $terms as $t ) {
				$existing = get_term_by( 'slug', $t['slug'], $t['taxonomy'] );
				if ( $existing ) {
					IdMap::set( $site_job_id, 'term', (int) $t['term_id'], (int) $existing->term_id );
					if ( $t['parent'] > 0 ) {
						$pending_parents[ $existing->term_id ] = (int) $t['parent'];
					}
					continue;
				}

				$result = wp_insert_term( $t['name'], $t['taxonomy'], [
					'slug'        => $t['slug'],
					'description' => $t['description'],
					'parent'      => 0,
				] );

				if ( is_wp_error( $result ) ) {
					continue;
				}

				$dest_term_id = (int) $result['term_id'];
				IdMap::set( $site_job_id, 'term', (int) $t['term_id'], $dest_term_id );

				if ( $t['parent'] > 0 ) {
					$pending_parents[ $dest_term_id ] = (int) $t['parent'];
				}
			}

			// Second pass: set parent IDs using the ID map.
			foreach ( $pending_parents as $dest_term_id => $source_parent_id ) {
				$dest_parent_id = IdMap::get( $site_job_id, 'term', $source_parent_id );
				if ( $dest_parent_id ) {
					$term = get_term( $dest_term_id );
					if ( $term && ! is_wp_error( $term ) ) {
						wp_update_term( $dest_term_id, $term->taxonomy, [ 'parent' => $dest_parent_id ] );
					}
				}
			}

			restore_current_blog();

			if ( count( $terms ) >= 100 ) {
				MigrationRegistry::update_site_job( $site_job_id, [ 'stage_offset' => $offset + 100 ] );
				as_enqueue_async_action(
					'hbm_import_terms',
					[ 'site_job_id' => $site_job_id, 'offset' => $offset + 100, 'attempt' => 0 ],
					'hb-migrator'
				);
				return;
			}

			// Terms done — start posts.
			as_enqueue_async_action(
				'hbm_import_posts',
				[ 'site_job_id' => $site_job_id, 'last_id' => 0, 'attempt' => 0 ],
				'hb-migrator'
			);

		} catch ( \Throwable $e ) {
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_import_terms',
				[ 'site_job_id' => $site_job_id, 'offset' => $offset, 'attempt' => $attempt ],
				$e,
				$site_job_id
			);
		}
	}

	private static function assign_user_roles( int $migration_id, int $source_blog_id, int $dest_blog_id ): void {
		$rows = UserSiteRoles::get_for_migration_blog( $migration_id, $source_blog_id );
		foreach ( $rows as $row ) {
			$dest_user_id = IdMap::get( IdMap::NETWORK, 'user', (int) $row['source_user_id'] );
			if ( $dest_user_id ) {
				add_user_to_blog( $dest_blog_id, $dest_user_id, $row['role'] );
			}
		}
	}

	private static function create_subsite( object $job, string $policy = 'generate_new' ): int {
		$network = get_network();
		if ( ! $network ) {
			throw new \RuntimeException( 'No network found on destination.' );
		}

		$result = wp_insert_site( [
			'domain'     => $network->domain,
			'path'       => $job->dest_path,
			'network_id' => get_current_network_id(),
			'title'      => $job->source_domain,
			'user_id'    => get_current_user_id(),
		] );

		if ( ! is_wp_error( $result ) ) {
			return (int) $result;
		}

		$code = $result->get_error_code();
		if ( 'blog_slug_already_exists' !== $code ) {
			throw new \RuntimeException( 'Could not create subsite: ' . $result->get_error_message() );
		}

		// Path collision — apply the site conflict policy.
		if ( 'use_existing' === $policy ) {
			$matches = get_sites( [
				'domain'     => $network->domain,
				'path'       => $job->dest_path,
				'network_id' => $network->id,
				'number'     => 1,
				'fields'     => 'ids',
			] );
			if ( ! empty( $matches ) ) {
				return (int) $matches[0];
			}
			// Path not found (race or stale conflict) — fall through to generate_new.
		}

		// generate_new: append -2, -3, … up to 10 attempts.
		$base = rtrim( $job->dest_path, '/' );
		for ( $i = 2; $i <= 11; $i++ ) {
			$candidate = $base . '-' . $i . '/';
			$retry     = wp_insert_site( [
				'domain'     => $network->domain,
				'path'       => $candidate,
				'network_id' => get_current_network_id(),
				'title'      => $job->source_domain,
				'user_id'    => get_current_user_id(),
			] );
			if ( ! is_wp_error( $retry ) ) {
				$job->dest_path = $candidate; // bubble updated path back to caller
				return (int) $retry;
			}
		}

		MigrationRegistry::update_site_job(
			(int) $job->id,
			[ 'status' => 'failed', 'error_message' => 'Could not create unique subsite path after 10 attempts for: ' . $job->dest_path ]
		);
		throw new \RuntimeException( 'Subsite path exhausted — marked failed, not retrying.' );
	}
}
