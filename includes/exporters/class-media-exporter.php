<?php

namespace HBMigrator\Exporters;

use HBMigrator\ArtifactManager;
use HBMigrator\Checkpoint;
use HBMigrator\PipelineController;

class MediaExporter {

	private const BATCH_SIZE_DEFAULT      = 50;
	private const PARTITION_SIZE_DEFAULT  = 500;
	private const ARCHIVE_FILENAME_PREFIX = 'hbm-media';

	/**
	 * AS action callback: hbm_export_media_batch($file_index, $partition, $attempt).
	 *
	 * file_index = global position in hbm_media_files (maps to batch_offset).
	 * partition  = which .tar.gz shard we're building.
	 *
	 * Phase 1 (file_index === 0, partition === 0): run discovery, populate
	 * hbm_media_files, re-dispatch the first real copy batch.
	 */
	public static function process_batch( int $file_index, int $partition, int $attempt ): void {
		try {
			if ( 0 === $file_index && 0 === $partition ) {
				self::discover_media_files();
				// Re-dispatch as a copy batch.
				as_enqueue_async_action( 'hbm_export_media_batch', [ 0, 0, 0 ], 'hb-migrator' );
				return;
			}

			$batch_size     = (int) apply_filters( 'hbm_media_batch_size', self::BATCH_SIZE_DEFAULT );
			$partition_size = (int) apply_filters( 'hbm_media_partition_size', self::PARTITION_SIZE_DEFAULT );

			$files = Checkpoint::get_media_files( $file_index, $batch_size );

			if ( empty( $files ) ) {
				// All files copied — finalize archives.
				as_enqueue_async_action( 'hbm_media_finalize', [ 0 ], 'hb-migrator' );
				Checkpoint::set_status( 'media', 'running' );
				return;
			}

			$staging_dir = ArtifactManager::get_staging_dir();
			wp_mkdir_p( $staging_dir );
			$uploads_dir = wp_upload_dir()['basedir'];

			foreach ( $files as $file ) {
				$src  = $uploads_dir . '/' . ltrim( $file->relative_path, '/' );
				$dest = $staging_dir . ltrim( $file->relative_path, '/' );
				wp_mkdir_p( dirname( $dest ) );
				if ( file_exists( $src ) ) {
					copy( $src, $dest );
				}
				Checkpoint::mark_media_file_copied( (int) $file->id );
			}

			$last_file  = end( $files );
			$new_index  = $file_index + count( $files );
			$new_part   = (int) floor( $new_index / $partition_size );
			Checkpoint::set_offset( 'media', $new_index );
			as_enqueue_async_action( 'hbm_export_media_batch', [ $new_index, $new_part, 0 ], 'hb-migrator' );

		} catch ( \Throwable $e ) {
			PipelineController::handle_batch_failure( 'media', $e, [ $file_index, $partition, $attempt ] );
		}
	}

	/**
	 * AS action callback: hbm_media_finalize($attempt).
	 *
	 * Packages the staging directory into one or more .tar.gz archives using
	 * PharData (built-in PHP; no proc_open, no shell exec).
	 *
	 * Build directly to .tar.gz to avoid buffering the whole archive in memory
	 * (PharData::compress() would buffer; new PharData('.tar.gz') streams).
	 */
	public static function finalize( int $attempt ): void {
		try {
			if ( ! extension_loaded( 'Phar' ) ) {
				throw new \RuntimeException( 'The Phar PHP extension is required to create media archives. Please enable it on your server.' );
			}

			$staging_dir = ArtifactManager::get_staging_dir();
			$export_dir  = ArtifactManager::get_export_dir();
			$partitions  = Checkpoint::max_media_partition();

			for ( $p = 0; $p <= $partitions; $p++ ) {
				$archive_path = $export_dir . self::ARCHIVE_FILENAME_PREFIX . '-' . $p . '.tar.gz';

				// Build directly to .tar.gz (streams; no in-memory buffer step).
				$phar = new \PharData( $archive_path );

				$iterator = self::get_staging_files_for_partition( $staging_dir, $p );
				foreach ( $iterator as $abs_path ) {
					if ( ! is_file( $abs_path ) ) {
						continue;
					}
					$relative = ltrim( str_replace( $staging_dir, '', $abs_path ), '/' );
					$phar->addFile( $abs_path, $relative );
				}
			}

			// Staging directory no longer needed.
			ArtifactManager::remove_directory( $staging_dir );
			PipelineController::stage_complete( 'media' );

		} catch ( \Throwable $e ) {
			PipelineController::handle_batch_failure( 'media', $e, [ $attempt ] );
		}
	}

	/**
	 * Scan wp-content/uploads and populate hbm_media_files.
	 */
	private static function discover_media_files(): void {
		$partition_size = (int) apply_filters( 'hbm_media_partition_size', self::PARTITION_SIZE_DEFAULT );
		$uploads_dir    = wp_upload_dir()['basedir'];

		if ( ! is_dir( $uploads_dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $uploads_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$batch     = [];
		$index     = 0;
		$partition = 0;

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$abs      = $file->getRealPath();
			$relative = ltrim( str_replace( $uploads_dir, '', $abs ), '/' );

			$batch[] = [
				'relative_path' => $relative,
				'file_size'     => $file->getSize(),
				'partition'     => $partition,
			];
			$index++;

			if ( count( $batch ) >= 500 ) {
				Checkpoint::insert_media_files( $batch );
				$batch = [];
			}

			$partition = (int) floor( $index / $partition_size );
		}

		if ( ! empty( $batch ) ) {
			Checkpoint::insert_media_files( $batch );
		}

		Checkpoint::set_total( 'media', $index );
	}

	/**
	 * Return paths of all staged files in $partition.
	 * Uses DB partition column to find the relative_paths, then maps to staging dir.
	 *
	 * @return iterable<string>
	 */
	private static function get_staging_files_for_partition( string $staging_dir, int $partition ): iterable {
		global $wpdb;
		$table  = $wpdb->prefix . 'hbm_media_files';
		$offset = 0;
		$limit  = 200;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT relative_path FROM `$table` WHERE partition = %d LIMIT %d OFFSET %d",
					$partition,
					$limit,
					$offset
				)
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				yield $staging_dir . $row->relative_path;
			}
			$offset += count( $rows );
		}
	}
}
