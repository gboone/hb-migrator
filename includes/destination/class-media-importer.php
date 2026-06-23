<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;

class MediaImporter {

	public static function process( int $site_job_id, int $offset, int $attempt ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
			if ( ! $migration ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'media', 'error_message' => null ] );

			$media = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/sites/' . (int) $job->source_blog_id . '/media',
				[ 'per_page' => 50, 'offset' => $offset ]
			);

			// Allowed download origin: the source site's upload URL (prevents SSRF via crafted file_url).
			$allowed_upload_origin = wp_parse_url( rtrim( $job->source_upload_url, '/' ), PHP_URL_HOST );

			switch_to_blog( (int) $job->dest_blog_id );

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			foreach ( $media as $att ) {
				$source_att_id = (int) ( $att['source_attachment_id'] ?? 0 );

				// Skip if already imported (idempotency on retry).
				if ( $source_att_id && IdMap::get( $site_job_id, 'attachment', $source_att_id ) ) {
					continue;
				}

				$file_url = $att['file_url'] ?? '';
				if ( ! $file_url ) {
					continue;
				}

				// Validate file_url origin against the source's upload directory to prevent SSRF.
				$file_host = wp_parse_url( $file_url, PHP_URL_HOST );
				if ( ! $allowed_upload_origin || $file_host !== $allowed_upload_origin ) {
					continue;
				}

				$tmp = download_url( $file_url, 60 );
				if ( is_wp_error( $tmp ) ) {
					continue;
				}

				$file_array = [
					'name'     => basename( wp_parse_url( $file_url, PHP_URL_PATH ) ),
					'tmp_name' => $tmp,
				];

				$sideload = wp_handle_sideload( $file_array, [ 'test_form' => false ] );
				if ( isset( $sideload['error'] ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
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
					continue;
				}

				$meta = wp_generate_attachment_metadata( $dest_att_id, $sideload['file'] );
				wp_update_attachment_metadata( $dest_att_id, $meta );

				if ( ! empty( $att['alt_text'] ) ) {
					update_post_meta( $dest_att_id, '_wp_attachment_image_alt', $att['alt_text'] );
				}

				if ( $source_att_id ) {
					IdMap::set( $site_job_id, 'attachment', $source_att_id, $dest_att_id );
				}
			}

			restore_current_blog();

			MigrationRegistry::update_site_job( $site_job_id, [ 'stage_offset' => $offset + count( $media ) ] );

			if ( count( $media ) >= 50 ) {
				as_enqueue_async_action(
					'hbm_import_media',
					[ 'site_job_id' => $site_job_id, 'offset' => $offset + 50, 'attempt' => 0 ],
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
				[ 'site_job_id' => $site_job_id, 'offset' => $offset, 'attempt' => $attempt ],
				$e,
				$site_job_id
			);
		}
	}
}
