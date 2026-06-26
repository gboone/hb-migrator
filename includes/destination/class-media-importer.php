<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;

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

			$media_policy   = $migration->media_conflict_policy ?? 'import_all';
			$media_scope    = $migration->media_import_scope    ?? 'all';
			$is_retry_pass  = ! empty( $source_attachment_ids );

			if ( ! $is_retry_pass ) {
				MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'media', 'error_message' => null ] );
			}

			$media = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/sites/' . (int) $job->source_blog_id . '/media',
				$is_retry_pass
					? [ 'ids' => $source_attachment_ids ]
					: [ 'per_page' => 50, 'offset' => $offset, 'attached_only' => ( 'attached_only' === $media_scope ) ? 1 : 0 ]
			);

			// Allowed download origin: the source site's upload URL (prevents SSRF via crafted file_url).
			$allowed_upload_origin = wp_parse_url( rtrim( $job->source_upload_url, '/' ), PHP_URL_HOST );

			switch_to_blog( (int) $job->dest_blog_id );

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$failed_items = []; // source_att_id => human-readable failure reason

			foreach ( $media as $att ) {
				$source_att_id = (int) ( $att['source_attachment_id'] ?? 0 );

				// Skip if already imported (idempotency on retry).
				if ( $source_att_id && IdMap::get( $site_job_id, 'attachment', $source_att_id ) ) {
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
					}
					continue;
				}
				wp_update_attachment_metadata( $dest_att_id, $meta );

				if ( ! empty( $att['alt_text'] ) ) {
					update_post_meta( $dest_att_id, '_wp_attachment_image_alt', $att['alt_text'] );
				}

				if ( $source_att_id ) {
					IdMap::set( $site_job_id, 'attachment', $source_att_id, $dest_att_id );
				}
			}

			restore_current_blog();

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
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_import_media',
				[ 'site_job_id' => $site_job_id, 'offset' => $offset, 'attempt' => $attempt, 'source_attachment_ids' => $source_attachment_ids ],
				$e,
				$site_job_id
			);
		}
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
}
