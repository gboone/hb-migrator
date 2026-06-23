<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;

class PostImporter {

	public static function process( int $site_job_id, int $last_id, int $attempt ): void {
		global $wpdb;
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
			if ( ! $migration ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'posts', 'error_message' => null ] );

			$posts = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/sites/' . (int) $job->source_blog_id . '/posts',
				[ 'per_page' => 100, 'last_id' => $last_id ]
			);

			switch_to_blog( (int) $job->dest_blog_id );
			wp_suspend_cache_invalidation( true );
			kses_remove_filters();

			$max_id = $last_id;

			foreach ( $posts as $p ) {
				$source_id = (int) $p['ID'];

				$existing_dest_id = IdMap::get( $site_job_id, 'post', $source_id );
				if ( null !== $existing_dest_id ) {
					// Post row already exists from a prior attempt. Re-sync its meta in a
					// transaction so we never have a window where the post has zero meta.
					$dest_id = $existing_dest_id;
					$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $dest_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					foreach ( $p['meta'] as $meta ) {
						$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$wpdb->postmeta,
							[
								'post_id'    => $dest_id,
								'meta_key'   => $meta['key'], // phpcs:ignore WordPress.DB.SlowDBQuery
								'meta_value' => $meta['value'],
							]
						);
					}
					$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				} else {
					// Resolve author.
					$author_id = 1;
					if ( ! empty( $p['post_author_email'] ) ) {
						$user = get_user_by( 'email', $p['post_author_email'] );
						if ( $user ) {
							$author_id = $user->ID;
						}
					}

					// Resolve post_parent.
					$post_parent = 0;
					if ( $p['post_parent'] > 0 ) {
						$dest_parent = IdMap::get( $site_job_id, 'post', (int) $p['post_parent'] );
						$post_parent = $dest_parent ?? 0;
					}

					$post_data = wp_slash( [
						'import_id'         => $source_id,
						'post_author'       => $author_id,
						'post_date'         => $p['post_date'],
						'post_date_gmt'     => $p['post_date_gmt'],
						'post_content'      => $p['post_content'],
						'post_title'        => $p['post_title'],
						'post_excerpt'      => $p['post_excerpt'],
						'post_status'       => $p['post_status'],
						'comment_status'    => $p['comment_status'],
						'ping_status'       => $p['ping_status'],
						'post_password'     => $p['post_password'],
						'post_name'         => $p['post_name'],
						'post_modified'     => $p['post_modified'],
						'post_modified_gmt' => $p['post_modified_gmt'],
						'post_parent'       => $post_parent,
						'menu_order'        => (int) $p['menu_order'],
						'post_type'         => $p['post_type'],
						'post_mime_type'    => $p['post_mime_type'],
					] );

					$dest_id = wp_insert_post( $post_data, false, false );
					if ( is_wp_error( $dest_id ) || ! $dest_id ) {
						continue;
					}

					IdMap::set( $site_job_id, 'post', $source_id, (int) $dest_id );

					// Insert meta directly to avoid maybe_serialize() double-serializing
					// already-serialized values and to preserve raw bytes from the source DB.
					foreach ( $p['meta'] as $meta ) {
						$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$wpdb->postmeta,
							[
								'post_id'    => $dest_id,
								'meta_key'   => $meta['key'], // phpcs:ignore WordPress.DB.SlowDBQuery
								'meta_value' => $meta['value'],
							]
						);
					}
				}

				// Preserve comment_count — wp_insert_post ignores this field.
				$wpdb->update( $wpdb->posts, [ 'comment_count' => (int) ( $p['comment_count'] ?? 0 ) ], [ 'ID' => $dest_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				// Set terms by slug.
				$terms_by_tax = [];
				foreach ( $p['terms'] as $t ) {
					$terms_by_tax[ $t['taxonomy'] ][] = $t['slug'];
				}
				foreach ( $terms_by_tax as $taxonomy => $slugs ) {
					wp_set_object_terms( $dest_id, $slugs, $taxonomy );
				}

				if ( $source_id > $max_id ) {
					$max_id = $source_id;
				}
			}

			kses_init_filters();
			wp_suspend_cache_invalidation( false );
			restore_current_blog();

			MigrationRegistry::update_site_job( $site_job_id, [ 'stage_offset' => $max_id ] );

			if ( count( $posts ) >= 100 ) {
				as_enqueue_async_action(
					'hbm_import_posts',
					[ 'site_job_id' => $site_job_id, 'last_id' => $max_id, 'attempt' => 0 ],
					'hb-migrator'
				);
				return;
			}

			// Posts done — start media.
			as_enqueue_async_action(
				'hbm_import_media',
				[ 'site_job_id' => $site_job_id, 'offset' => 0, 'attempt' => 0 ],
				'hb-migrator'
			);

		} catch ( \Throwable $e ) {
			kses_init_filters();
			wp_suspend_cache_invalidation( false );
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_import_posts',
				[ 'site_job_id' => $site_job_id, 'last_id' => $last_id, 'attempt' => $attempt ],
				$e,
				$site_job_id
			);
		}
	}
}
