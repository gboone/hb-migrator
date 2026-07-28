<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;
use HBMigrator\SourceClientException;

class PostImporter {

	public static function process( int $site_job_id, int $last_id, int $attempt ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
			if ( ! $migration ) {
				return;
			}
			if ( 'cancelled' === $migration->status ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'posts', 'error_message' => null ] );

			// U3 request-trail capture (see docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md).
			// PostImporter is site-job-scoped, so this is recorded via record() (scope: site_job).
			$posts_path = 'source/sites/' . (int) $job->source_blog_id . '/posts';
			try {
				$posts = SourceClient::get(
					$migration->source_url,
					$migration->source_api_key,
					$posts_path,
					[ 'per_page' => 100, 'last_id' => $last_id ]
				);
			} catch ( SourceClientException $e ) {
				AuditReport::record( $site_job_id, 'site_job', [
					'type'    => 'request',
					'path'    => $posts_path,
					'success' => false,
					'error'   => $e->getMessage(),
				] );
				throw $e;
			}

			AuditReport::record( $site_job_id, 'site_job', [
				'type'    => 'request',
				'path'    => $posts_path,
				'success' => true,
				'count'   => count( $posts ),
			] );

			$result = self::import_batch( $site_job_id, $posts );
			$max_id = max( $last_id, $result['max_id'] );

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
			PipelineController::handle_batch_failure(
				'hbm_import_posts',
				[ 'site_job_id' => $site_job_id, 'last_id' => $last_id, 'attempt' => $attempt ],
				$e,
				$site_job_id
			);
		}
	}

	/**
	 * Upserts a batch of source posts (as already fetched from PostReader, via either the
	 * initial-migration pipeline's ID-keyset cursor or U3's sync delta-cursor) into the
	 * destination site. Shared by process() (initial migration) and PostSyncStage (U3
	 * ongoing sync) so both reuse the exact same Reader/Importer/IdMap upsert logic rather
	 * than a parallel content-moving mechanism — see plan Summary.
	 *
	 * Wraps switch_to_blog()/kses/cache-invalidation bracketing in a try/finally so a
	 * mid-batch exception can never leave the destination blog switched or kses filters
	 * removed — callers no longer need to replicate that cleanup themselves.
	 *
	 * @return array{max_id: int, failed_ids: int[]} 'max_id' is the highest source post ID
	 *              processed in this batch regardless of success (0 if none), for callers
	 *              (process()) that track an ID-keyset cursor. Sync callers (PostSyncStage)
	 *              track their own modified-timestamp cursor separately and use 'failed_ids'
	 *              instead — the source post IDs that hit a wp_insert_post()/wp_update_post()
	 *              failure and were skipped this pass, so the caller can avoid advancing a
	 *              cursor past them (a failed item must be retried next pass, not silently
	 *              skipped forever — see class-comment-sync-stage.php's same-shaped
	 *              stop-at-first-unresolved-item contract).
	 */
	public static function import_batch( int $site_job_id, array $posts ): array {
		global $wpdb;

		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return [ 'max_id' => 0, 'failed_ids' => [] ];
		}

		// U5 write-action trail (see docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md,
		// "U5. Write-action trail: posts and media (sync-gated)"). PostSyncStage is the only other
		// caller of import_batch() and it only ever runs while the site job's own status is
		// 'syncing' — gating on pending/running keeps sync passes out of the audit trail entirely.
		$should_audit = in_array( $job->status, [ 'pending', 'running' ], true );

		$max_id     = 0;
		$failed_ids = [];
		$touched_ids = [];

		switch_to_blog( (int) $job->dest_blog_id );
		wp_suspend_cache_invalidation( true );
		kses_remove_filters();

		try {
			foreach ( $posts as $p ) {
				// Attachment posts are handled by the media pipeline with file sideloading.
				// Creating them here produces hollow records that cause -1 filename collisions.
				if ( 'attachment' === ( $p['post_type'] ?? '' ) ) {
					continue;
				}

				$source_id = (int) $p['ID'];

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

				$post_data = [
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
				];

				$existing_dest_id = IdMap::get( $site_job_id, 'post', $source_id );
				if ( null !== $existing_dest_id ) {
					// Post row already exists — either re-processed after a prior
					// attempt failed mid-pipeline (initial migration), or this is an
					// ongoing sync pass re-applying an edit (U3, R4/R11: source always
					// wins). Update core fields, not just postmeta, using the same
					// wp_slash() convention the insert branch below uses — wp_update_post()
					// expects an already-slashed array for plain-array input, mirroring
					// wp_insert_post()'s own expectation (see wp_update_post() source:
					// it only wp_slash()'s for you when $postarr is an object, not an
					// array). Re-applying identical values is a safe no-op.
					$dest_id       = $existing_dest_id;
					$update_result = wp_update_post( wp_slash( array_merge( $post_data, [ 'ID' => $dest_id ] ) ), true, false );
					if ( is_wp_error( $update_result ) || ! $update_result ) {
						// Leave the existing destination row untouched rather than partially
						// apply — mirrors the insert branch's "skip this post" behavior on
						// wp_insert_post() failure. Recorded in $failed_ids so callers (e.g.
						// PostSyncStage) never advance a cursor past this source ID.
						$failed_ids[] = $source_id;
						if ( $should_audit ) {
							self::record_write_trail( $site_job_id, $source_id, 'failed', $p );
						}
						continue;
					}

					// Re-sync meta in a transaction so we never have a window where the
					// post has zero meta.
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

					// U5: an already-IdMap-mapped item being re-processed (resumed/retried
					// batch) is "updated," not "created" — matches this branch's own
					// insert-vs-update signal, avoiding double-counting a retried item.
					if ( $should_audit ) {
						self::record_write_trail( $site_job_id, $source_id, 'updated', $p );
					}
				} else {
					$dest_id = wp_insert_post( wp_slash( array_merge( $post_data, [ 'import_id' => $source_id ] ) ), false, false );
					if ( is_wp_error( $dest_id ) || ! $dest_id ) {
						// Recorded in $failed_ids so callers (e.g. PostSyncStage) never
						// advance a cursor past this source ID.
						$failed_ids[] = $source_id;
						if ( $should_audit ) {
							self::record_write_trail( $site_job_id, $source_id, 'failed', $p );
						}
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

					// U5: a fresh insert (no prior IdMap entry) is "created" -- the happy path.
					if ( $should_audit ) {
						self::record_write_trail( $site_job_id, $source_id, 'created', $p );
					}
				}

				// Preserve comment_count — wp_insert_post/wp_update_post ignore this field.
				$wpdb->update( $wpdb->posts, [ 'comment_count' => (int) ( $p['comment_count'] ?? 0 ) ], [ 'ID' => $dest_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$touched_ids[] = $dest_id;

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
		} finally {
			kses_init_filters();
			wp_suspend_cache_invalidation( false );
			// Suspending invalidation above skipped, rather than deferred, every
			// clean_post_cache() call wp_insert_post()/wp_update_post() would normally
			// have made — clean explicitly now so touched posts don't serve stale
			// cached data (comment_count, title, etc.) from a persistent object cache.
			foreach ( $touched_ids as $touched_id ) {
				clean_post_cache( $touched_id );
			}
			restore_current_blog();
		}

		return [ 'max_id' => $max_id, 'failed_ids' => $failed_ids ];
	}

	/**
	 * U5 write-action trail entry for a single post item — see
	 * docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U5. Write-action trail:
	 * posts and media (sync-gated)". Callers must already have checked the site job's
	 * pending/running status gate (import_batch()'s $should_audit) before calling this — no
	 * gating happens here.
	 *
	 * The recorded entry doubles as the cached source snapshot a later unit (U6, not this
	 * unit's concern) hashes against, so it carries the raw source fields needed to re-derive a
	 * normalized content/postmeta hash later: post content, excerpt, serialized postmeta, and
	 * the source author identifier (post_author_email — the same field import_batch() itself
	 * uses to resolve the destination author above). Also carries post_name (slug), post_type,
	 * and post_title — R4 explicitly requires comparing slugs across every post type, and U6's
	 * own drift-detection scenario requires a title change to register as a mismatch; none of
	 * the three is derivable from the other cached fields.
	 */
	private static function record_write_trail( int $site_job_id, int $source_id, string $outcome, array $p ): void {
		AuditReport::record( $site_job_id, 'site_job', [
			'type'          => 'write',
			'object_type'   => 'post',
			'source_id'     => $source_id,
			'outcome'       => $outcome,
			'post_content'  => $p['post_content'] ?? '',
			'post_excerpt'  => $p['post_excerpt'] ?? '',
			'meta'          => $p['meta'] ?? [],
			'source_author' => $p['post_author_email'] ?? '',
			'post_name'     => $p['post_name'] ?? '',
			'post_type'     => $p['post_type'] ?? '',
			'post_title'    => $p['post_title'] ?? '',
		] );
	}
}
