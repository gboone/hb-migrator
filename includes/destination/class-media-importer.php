<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;
use HBMigrator\SourceClientException;

class MediaImporter {

	/**
	 * @param array $source_attachment_ids When non-empty, this is a targeted retry pass — only
	 *                                     these source attachment IDs are fetched and attempted.
	 *                                     stage_offset is not updated and no next-batch action
	 *                                     is scheduled.
	 */
	public static function process( int $site_job_id, int $offset, int $attempt, array $source_attachment_ids = [] ): void {
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

			$media_scope   = $migration->media_import_scope ?? 'all';
			$is_retry_pass = ! empty( $source_attachment_ids );

			if ( ! $is_retry_pass ) {
				MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'media', 'error_message' => null ] );
			}

			// U3 request-trail capture (see docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md).
			// MediaImporter is site-job-scoped, so this is recorded via record() (scope: site_job).
			$media_path = 'source/sites/' . (int) $job->source_blog_id . '/media';
			try {
				$media = SourceClient::get(
					$migration->source_url,
					$migration->source_api_key,
					$media_path,
					$is_retry_pass
						? [ 'ids' => $source_attachment_ids ]
						: [ 'per_page' => 50, 'offset' => $offset, 'attached_only' => ( 'attached_only' === $media_scope ) ? 1 : 0 ]
				);
			} catch ( SourceClientException $e ) {
				AuditReport::record( $site_job_id, 'site_job', [
					'type'    => 'request',
					'path'    => $media_path,
					'success' => false,
					'error'   => $e->getMessage(),
				] );
				throw $e;
			}

			AuditReport::record( $site_job_id, 'site_job', [
				'type'    => 'request',
				'path'    => $media_path,
				'success' => true,
				'count'   => count( $media ),
			] );

			$failed_items = self::import_batch( $site_job_id, $media );

			// Retry any items that failed to download or import.
			if ( ! empty( $failed_items ) ) {
				$max_retries = (int) apply_filters( 'hbm_max_retries', 3 );
				if ( $attempt < $max_retries ) {
					$delay = 60 * (int) pow( 2, $attempt );
					as_schedule_single_action( time() + $delay, 'hbm_import_media', [
						'site_job_id'           => $site_job_id,
						'offset'                => 0,
						'attempt'               => $attempt + 1,
						'source_attachment_ids' => array_keys( $failed_items ),
					], 'hb-migrator' );
				} else {
					$existing = MigrationRegistry::get_site_job( $site_job_id );
					$prefix   = ! empty( $existing->error_message ) ? $existing->error_message . "\n" : '';
					$count    = count( $failed_items );
					$lines    = [];
					foreach ( $failed_items as $id => $reason ) {
						$lines[] = "  {$id}: {$reason}";
					}
					MigrationRegistry::update_site_job( $site_job_id, [
						'error_message' => $prefix . sprintf(
							'%d media item%s permanently failed to import:',
							$count,
							1 === $count ? '' : 's'
						) . "\n" . implode( "\n", $lines ),
					] );
				}
			}

			// Retry passes don't advance the pipeline — the original batch already did.
			if ( $is_retry_pass ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'stage_offset' => $offset + count( $media ) ] );

			if ( count( $media ) >= 50 ) {
				as_enqueue_async_action(
					'hbm_import_media',
					[ 'site_job_id' => $site_job_id, 'offset' => $offset + 50, 'attempt' => 0, 'source_attachment_ids' => [] ],
					'hb-migrator'
				);
				return;
			}

			// Media done — import options.
			as_enqueue_async_action(
				'hbm_import_options',
				[ 'site_job_id' => $site_job_id, 'offset' => 0, 'attempt' => 0 ],
				'hb-migrator'
			);

		} catch ( \Throwable $e ) {
			PipelineController::handle_batch_failure(
				'hbm_import_media',
				[ 'site_job_id' => $site_job_id, 'offset' => $offset, 'attempt' => $attempt, 'source_attachment_ids' => $source_attachment_ids ],
				$e,
				$site_job_id
			);
		}
	}

	/**
	 * Upserts a batch of source media items (as already fetched from MediaReader, via either
	 * the initial-migration pipeline's offset pagination, a targeted retry-by-ID pass, or U4's
	 * sync delta-cursor) into the destination site. Shared by process() (initial migration and
	 * retries) and MediaSyncStage (U4 ongoing sync) so both reuse the exact same
	 * Reader/Importer/IdMap upsert logic — including conflict-policy handling
	 * (media_conflict_policy, media_import_scope) and cross-run dedup — rather than a parallel
	 * content-moving mechanism, mirroring PostImporter::import_batch()'s U3 precedent.
	 *
	 * Wraps switch_to_blog() bracketing in a try/finally so a mid-batch exception can never
	 * leave the destination blog switched — callers no longer need to replicate that cleanup.
	 *
	 * @return array source_attachment_id => human-readable failure reason, for callers
	 *               (process()) that schedule retries. Empty when every item in the batch
	 *               either imported successfully or was permanently skipped (no file_url,
	 *               SSRF guard).
	 */
	public static function import_batch( int $site_job_id, array $media ): array {
		$job = MigrationRegistry::get_site_job( $site_job_id );
		if ( ! $job || ! $job->dest_blog_id ) {
			return [];
		}

		// U5 write-action trail (see docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md,
		// "U5. Write-action trail: posts and media (sync-gated)"). MediaSyncStage is the only
		// other caller of import_batch() and it only ever runs while the site job's own status is
		// 'syncing' -- gating on pending/running keeps sync passes out of the audit trail entirely.
		$should_audit = in_array( $job->status, [ 'pending', 'running' ], true );

		$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
		if ( ! $migration ) {
			return [];
		}
		$media_policy = $migration->media_conflict_policy ?? 'import_all';

		// Allowed download origin: the source site's upload URL (prevents SSRF via crafted file_url).
		$allowed_upload_origin = wp_parse_url( rtrim( $job->source_upload_url, '/' ), PHP_URL_HOST );

		switch_to_blog( (int) $job->dest_blog_id );

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$failed_items = []; // source_att_id => human-readable failure reason

		try {
			foreach ( $media as $att ) {
				$source_att_id = (int) ( $att['source_attachment_id'] ?? 0 );

				// Skip if already imported (idempotency on retry).
				if ( $source_att_id && IdMap::get( $site_job_id, 'attachment', $source_att_id ) ) {
					if ( $should_audit ) {
						self::record_write_trail( $site_job_id, $source_att_id, 'updated', $att );
					}
					continue;
				}

				// Cross-run deduplication: find attachments created by a previous migration run
				// for this same source attachment. IdMap is per-site_job_id so it doesn't survive
				// a Clear + re-run; post meta does.
				if ( $source_att_id ) {
					$prev_atts = get_posts( [
						'post_type'   => 'attachment',
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
						'meta_key'    => '_hbm_source_attachment_id',
						'meta_value'  => $source_att_id,
					] );
					if ( ! empty( $prev_atts ) ) {
						$prev_id   = (int) $prev_atts[0];
						$prev_meta = wp_get_attachment_metadata( $prev_id );
						if ( ! empty( $prev_meta ) ) {
							// Healthy prior import — record in IdMap and skip re-download.
							IdMap::set( $site_job_id, 'attachment', $source_att_id, $prev_id );
							if ( $should_audit ) {
								self::record_write_trail( $site_job_id, $source_att_id, 'created', $att );
							}
							continue;
						}
						// Broken prior import — delete it so the re-sideload won't get a -1 suffix.
						wp_delete_attachment( $prev_id, true );
					}
				}

				// skip_duplicates: reuse existing destination attachment matched by filename.
				if ( 'skip_duplicates' === $media_policy && $source_att_id ) {
					$post_name = $att['post_name'] ?? '';
					if ( ! $post_name ) {
						$post_name = sanitize_title( basename( wp_parse_url( $att['file_url'] ?? '', PHP_URL_PATH ) ) );
					}
					if ( $post_name ) {
						$existing_atts = get_posts( [
							'post_type'   => 'attachment',
							'name'        => $post_name,
							'post_status' => 'any',
							'numberposts' => 1,
							'fields'      => 'ids',
						] );
						if ( ! empty( $existing_atts ) ) {
							IdMap::set( $site_job_id, 'attachment', $source_att_id, (int) $existing_atts[0] );
							if ( $should_audit ) {
								self::record_write_trail( $site_job_id, $source_att_id, 'created', $att );
							}
							continue;
						}
					}
				}

				$file_url = $att['file_url'] ?? '';
				if ( ! $file_url ) {
					continue; // permanent skip — no file to download
				}

				// Validate file_url origin against the source's upload directory to prevent SSRF.
				$file_host = wp_parse_url( $file_url, PHP_URL_HOST );
				if ( ! $allowed_upload_origin || $file_host !== $allowed_upload_origin ) {
					continue; // permanent skip — SSRF guard
				}

				$tmp = download_url( $file_url, 60 );
				if ( is_wp_error( $tmp ) ) {
					if ( $source_att_id ) {
						$failed_items[ $source_att_id ] = 'download failed: ' . $tmp->get_error_message();
						if ( $should_audit ) {
							self::record_write_trail( $site_job_id, $source_att_id, 'failed', $att );
						}
					}
					continue;
				}

				$file_array      = [
					'name'     => basename( wp_parse_url( $file_url, PHP_URL_PATH ) ),
					'tmp_name' => $tmp,
				];
				$date_filter     = self::upload_dir_filter_for_date( $att['post_date'] ?? '' );
				$filetype_filter = self::filetype_override_filter( $att['post_mime_type'] ?? '', $file_array['name'] );
				$sideload        = wp_handle_sideload( $file_array, [ 'test_form' => false ] );
				if ( $date_filter ) {
					remove_filter( 'upload_dir', $date_filter );
				}
				if ( $filetype_filter ) {
					remove_filter( 'wp_check_filetype_and_ext', $filetype_filter );
				}
				if ( isset( $sideload['error'] ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( $source_att_id ) {
						$failed_items[ $source_att_id ] = 'sideload failed: ' . $sideload['error'];
						if ( $should_audit ) {
							self::record_write_trail( $site_job_id, $source_att_id, 'failed', $att );
						}
					}
					continue;
				}

				$post_parent = 0;
				if ( $att['post_parent_source_id'] > 0 ) {
					$dest_parent = IdMap::get( $site_job_id, 'post', (int) $att['post_parent_source_id'] );
					$post_parent = $dest_parent ?? 0;
				}

				$attachment_data = [
					'post_mime_type' => $sideload['type'],
					'post_title'     => $att['post_title'] ?: sanitize_file_name( $file_array['name'] ),
					'post_content'   => $att['description'] ?? '',
					'post_excerpt'   => $att['caption'] ?? '',
					'post_date'      => $att['post_date'] ?? '',
					'post_name'      => $att['post_name'] ?? '',
					'post_status'    => 'inherit',
				];

				$dest_att_id = wp_insert_attachment( $attachment_data, $sideload['file'], $post_parent, true );
				if ( is_wp_error( $dest_att_id ) ) {
					@unlink( $sideload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( $source_att_id ) {
						$failed_items[ $source_att_id ] = 'insert failed: ' . $dest_att_id->get_error_message();
						if ( $should_audit ) {
							self::record_write_trail( $site_job_id, $source_att_id, 'failed', $att );
						}
					}
					continue;
				}

				if ( $source_att_id ) {
					update_post_meta( $dest_att_id, '_hbm_source_attachment_id', $source_att_id );
				}

				$meta = wp_generate_attachment_metadata( $dest_att_id, $sideload['file'] );
				if ( empty( $meta ) ) {
					wp_delete_attachment( $dest_att_id, true );
					if ( $source_att_id ) {
						$failed_items[ $source_att_id ] = 'metadata generation failed — image may be corrupt or unprocessable';
						if ( $should_audit ) {
							self::record_write_trail( $site_job_id, $source_att_id, 'failed', $att );
						}
					}
					continue;
				}
				$meta = self::restore_original_filename_after_core_processing( $dest_att_id, $sideload['file'], $meta );
				wp_update_attachment_metadata( $dest_att_id, $meta );

				if ( ! empty( $att['alt_text'] ) ) {
					update_post_meta( $dest_att_id, '_wp_attachment_image_alt', $att['alt_text'] );
				}

				if ( $source_att_id ) {
					IdMap::set( $site_job_id, 'attachment', $source_att_id, $dest_att_id );
					if ( $should_audit ) {
						self::record_write_trail( $site_job_id, $source_att_id, 'created', $att );
					}
				}
			}
		} finally {
			restore_current_blog();
		}

		return $failed_items;
	}

	/**
	 * Registers a wp_check_filetype_and_ext filter that trusts the source MIME type for file
	 * types the destination site hasn't explicitly allowed (e.g. SVG, HEIC). Returns the
	 * callable so the caller can remove it immediately after wp_handle_sideload().
	 * Returns null when no source MIME type is available.
	 */
	private static function filetype_override_filter( string $source_mime, string $filename ): ?callable {
		if ( ! $source_mime ) {
			return null;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		// Never override WP's intentional block on server-executable file types.
		// WordPress sets both ext and type to the boolean false (not empty string) when blocking
		// a file — using strict false === comparison avoids clobbering partial results from other
		// filters and avoids overriding WP's finfo-based content-integrity check.
		$blocked = [ 'php', 'php3', 'php4', 'php5', 'php7', 'phps', 'phtml', 'phar', 'asp', 'aspx', 'cgi', 'pl', 'py', 'rb', 'sh', 'shtml', 'htaccess', 'exe', 'dll' ];
		if ( in_array( $ext, $blocked, true ) ) {
			return null;
		}

		$filter = static function ( array $data ) use ( $source_mime, $ext ): array {
			// WP returns false (not empty string) for both when it fully rejects a type.
			// Only override when WP produced a complete rejection — not a partial result.
			if ( false === $data['ext'] && false === $data['type'] ) {
				$data['ext']  = $ext;
				$data['type'] = $source_mime;
			}
			return $data;
		};
		add_filter( 'wp_check_filetype_and_ext', $filter );
		return $filter;
	}

	/**
	 * Registers a one-shot upload_dir filter for the given post date and returns the callable
	 * so the caller can remove it immediately after wp_handle_sideload(). Returns null when
	 * post_date is absent or unparseable (WordPress default upload dir is used in that case).
	 */
	private static function upload_dir_filter_for_date( string $post_date ): ?callable {
		if ( ! $post_date ) {
			return null;
		}
		$ts = strtotime( $post_date );
		if ( ! $ts ) {
			return null;
		}
		$subdir = '/' . gmdate( 'Y/m', $ts );
		$filter = function ( array $dirs ) use ( $subdir ): array {
			$dirs['subdir'] = $subdir;
			$dirs['path']   = $dirs['basedir'] . $subdir;
			$dirs['url']    = $dirs['baseurl'] . $subdir;
			return $dirs;
		};
		add_filter( 'upload_dir', $filter );
		return $filter;
	}

	/**
	 * Undoes a filename-preservation-breaking side effect of wp_generate_attachment_metadata():
	 * WordPress core's own image processing (big-image auto-scaling, `big_image_size_threshold`,
	 * 5.3+, OR EXIF-orientation auto-rotation — both go through the same internal
	 * `_wp_image_meta_replace_original()` path) leaves the true original file untouched at its
	 * original name, but re-processes a NEW derivative (resized or rotated) under a suffixed name
	 * ("{name}-scaled.{ext}" / "{name}-rotated.{ext}") and repoints `_wp_attached_file` — the
	 * primary served file — at that new derivative instead. A migrated attachment often already
	 * has source content (post_content, other postmeta) referencing this exact file by its
	 * original name; left as WP core leaves it, the file actually served no longer matches.
	 *
	 * Rather than disabling this processing (losing the resize/rotation-correction benefit
	 * entirely), this keeps whatever WP core produced but renames the new derivative back into
	 * the original file's name — the original file itself is discarded to free that name, since
	 * keeping both would just relocate the naming mismatch onto whichever copy kept the suffix.
	 *
	 * Deliberately narrow: only fires when `$meta['original_image']` is set (i.e. WP core
	 * actually produced a derivative) AND the derivative's extension exactly matches the
	 * original's. A mismatched extension means a genuine format conversion happened (e.g.
	 * HEIC -> JPEG, `generate_filename('')`, no suffix but a different extension) — renaming a
	 * converted file's bytes back under the original (wrong-format) extension would be actively
	 * incorrect, not a naming fix, so that case is left as WP core produced it.
	 *
	 * @param int    $dest_att_id
	 * @param string $original_file The exact path wp_handle_sideload() placed the file at,
	 *                               before any of wp_generate_attachment_metadata()'s own
	 *                               processing — WP core never moves or renames this file, only
	 *                               ever creates an additional derivative alongside it.
	 * @param array  $meta          wp_generate_attachment_metadata()'s return value.
	 * @return array The (possibly adjusted) metadata to pass to wp_update_attachment_metadata().
	 */
	private static function restore_original_filename_after_core_processing( int $dest_att_id, string $original_file, array $meta ): array {
		if ( empty( $meta['original_image'] ) ) {
			return $meta;
		}

		$derivative_file = get_attached_file( $dest_att_id );
		if ( $derivative_file === $original_file ) {
			return $meta;
		}

		$original_ext   = strtolower( (string) pathinfo( $original_file, PATHINFO_EXTENSION ) );
		$derivative_ext = strtolower( (string) pathinfo( $derivative_file, PATHINFO_EXTENSION ) );
		if ( $original_ext !== $derivative_ext ) {
			return $meta; // Genuine format conversion — WP core's naming is correct as-is.
		}

		// rename() atomically replaces an existing destination on the POSIX filesystems this
		// plugin actually runs on (Linux hosting, including VIP) — deliberately not preceded by
		// a separate unlink() of $original_file, so a failed rename() never leaves the true
		// original deleted with nothing to replace it; either both survive as WP core left them,
		// or the derivative fully replaces the original in one step.
		if ( ! @rename( $derivative_file, $original_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $meta; // Leave WP core's own naming in place rather than risk a dangling reference.
		}

		update_attached_file( $dest_att_id, $original_file );
		$meta['file'] = _wp_relative_upload_path( $original_file );
		unset( $meta['original_image'] );

		return $meta;
	}

	/**
	 * U5 write-action trail entry for a single media item — see
	 * docs/plans/2026-07-28-001-feat-migration-audit-report-plan.md, "U5. Write-action trail:
	 * posts and media (sync-gated)". Callers must already have checked the site job's
	 * pending/running status gate (import_batch()'s $should_audit) before calling this — no
	 * gating happens here.
	 *
	 * The recorded entry doubles as the cached source snapshot a later unit (U6, not this
	 * unit's concern) hashes against, so it carries the raw source fields needed to re-derive a
	 * normalized hash later — the media equivalent of PostImporter::record_write_trail()'s
	 * content/excerpt/meta/author fields: the source file URL, title, description (post_content
	 * equivalent), caption (post_excerpt equivalent), and alt text.
	 */
	private static function record_write_trail( int $site_job_id, int $source_att_id, string $outcome, array $att ): void {
		AuditReport::record( $site_job_id, 'site_job', [
			'type'           => 'write',
			'object_type'    => 'attachment',
			'source_id'      => $source_att_id,
			'outcome'        => $outcome,
			'file_url'       => $att['file_url'] ?? '',
			'post_title'     => $att['post_title'] ?? '',
			'description'    => $att['description'] ?? '',
			'caption'        => $att['caption'] ?? '',
			'alt_text'       => $att['alt_text'] ?? '',
			'post_mime_type' => $att['post_mime_type'] ?? '',
		] );
	}
}
