<?php

namespace HBMigrator\Destination;

use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;

class SearchReplace {

	public static function process( int $site_job_id, int $attempt ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'current_stage' => 'search_replace' ] );

			switch_to_blog( (int) $job->dest_blog_id );

			$dest_siteurl    = get_option( 'siteurl' );
			$dest_upload_url = trailingslashit( wp_upload_dir()['baseurl'] );
			$source_siteurl  = rtrim( $job->source_siteurl, '/' );
			$source_upload   = rtrim( $job->source_upload_url, '/' );

			restore_current_blog();

			$replacements = array_filter( [
				$source_siteurl => rtrim( $dest_siteurl, '/' ),
				$source_upload  => rtrim( $dest_upload_url, '/' ),
			], fn( $old ) => ! empty( $old ) );

			if ( ! empty( $replacements ) ) {
				self::run_on_site( (int) $job->dest_blog_id, $replacements );
			}

			MigrationRegistry::update_site_job( $site_job_id, [
				'status'        => 'complete',
				'current_stage' => null,
			] );

			// Check if all sites in this migration are complete.
			$migration_id = (int) $job->migration_id;
			if ( MigrationRegistry::all_sites_complete( $migration_id ) ) {
				MigrationRegistry::complete_migration( $migration_id );
				self::maybe_send_notification( $migration_id );
			}

		} catch ( \Throwable $e ) {
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_search_replace',
				[ 'site_job_id' => $site_job_id, 'attempt' => $attempt ],
				$e,
				$site_job_id
			);
		}
	}

	private static function run_on_site( int $blog_id, array $replacements ): void {
		global $wpdb;

		switch_to_blog( $blog_id );

		// posts: post_content, post_excerpt, post_title, guid
		$tables = [
			$wpdb->posts    => [ 'post_content', 'post_excerpt', 'post_title', 'guid' ],
			$wpdb->postmeta => [ 'meta_value' ],
			$wpdb->options  => [ 'option_value' ],
		];

		foreach ( $tables as $table => $columns ) {
			foreach ( $columns as $col ) {
				// Skip siteurl and home in options — already correct.
				if ( 'option_value' === $col ) {
					self::replace_in_options( $table, $col, $replacements );
					continue;
				}
				self::replace_in_column( $table, $col, $replacements );
			}
		}

		restore_current_blog();
	}

	private static function replace_in_column( string $table, string $col, array $replacements ): void {
		global $wpdb;

		$offset = 0;
		$batch  = 200;

		while ( true ) {
			$pk_col = 'option_value' === $col ? 'option_id' : ( false !== strpos( $table, 'postmeta' ) ? 'meta_id' : 'ID' );

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT $pk_col AS pk, `$col` AS val FROM `$table` LIMIT %d OFFSET %d",
				$batch,
				$offset
			), ARRAY_A );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$original = $row['val'];
				$replaced = self::safe_replace( $original, $replacements );
				if ( $replaced !== $original ) {
					$wpdb->update( $table, [ $col => $replaced ], [ $pk_col => $row['pk'] ] );
				}
			}

			$offset += $batch;
		}
	}

	private static function replace_in_options( string $table, string $col, array $replacements ): void {
		global $wpdb;

		$skip_names = [ 'siteurl', 'home' ];
		$offset     = 0;
		$batch      = 200;

		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_id AS pk, option_name, option_value AS val FROM `$table`
				  WHERE option_name NOT IN ('siteurl','home')
				  LIMIT %d OFFSET %d",
				$batch,
				$offset
			), ARRAY_A );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( in_array( $row['option_name'], $skip_names, true ) ) {
					continue;
				}
				$original = $row['val'];
				$replaced = self::safe_replace( $original, $replacements );
				if ( $replaced !== $original ) {
					$wpdb->update( $table, [ 'option_value' => $replaced ], [ 'option_id' => $row['pk'] ] );
				}
			}

			$offset += $batch;
		}
	}

	/**
	 * Serialization-aware string replacement.
	 *
	 * @param mixed  $value        The value to search within.
	 * @param array  $replacements Map of old => new strings.
	 * @return mixed
	 */
	public static function safe_replace( $value, array $replacements ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::safe_replace( $v, $replacements );
			}
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $data || 'b:0;' === $value ) {
				$replaced = self::safe_replace( $data, $replacements );
				return serialize( $replaced );
			}
		}

		foreach ( $replacements as $old => $new ) {
			$value = str_replace( $old, $new, $value );
		}

		return $value;
	}

	private static function maybe_send_notification( int $migration_id ): void {
		$migration = MigrationRegistry::get_migration( $migration_id );
		if ( ! $migration || ! $migration->notification_email ) {
			return;
		}
		wp_mail(
			$migration->notification_email,
			sprintf( '[HB Migrator] Migration from %s complete', $migration->source_url ),
			sprintf(
				"All sites from %s have been migrated successfully.\n\nMigration ID: %d",
				$migration->source_url,
				$migration_id
			)
		);
	}
}
