<?php

namespace HBMigrator\Destination;

use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;

class SearchReplace {

	// Authoritative skip list for options. Matches OptionReader::SKIP so both
	// sides exclude the same names (source never sends them; destination never replaces them).
	private const SKIP_OPTION_NAMES = [ 'siteurl', 'home' ];

	public static function process( int $site_job_id, int $attempt ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'search_replace', 'error_message' => null ] );

			switch_to_blog( (int) $job->dest_blog_id );

			$dest_siteurl    = get_option( 'siteurl' );
			$dest_upload_url = trailingslashit( wp_upload_dir()['baseurl'] );
			$source_siteurl  = rtrim( $job->source_siteurl, '/' );
			$source_upload   = rtrim( $job->source_upload_url, '/' );

			restore_current_blog();

			// Filter on the KEY (source URL) — replacing an empty source string would corrupt all content.
			$replacements = array_filter( [
				$source_siteurl => rtrim( $dest_siteurl, '/' ),
				$source_upload  => rtrim( $dest_upload_url, '/' ),
			], fn( $key ) => ! empty( $key ), ARRAY_FILTER_USE_KEY );

			if ( ! empty( $replacements ) ) {
				self::run_on_site( (int) $job->dest_blog_id, $replacements );
			}

			MigrationRegistry::update_site_job( $site_job_id, [
				'status'        => 'complete',
				'current_stage' => null,
			] );

			// Atomic completion: only the winner sends the notification.
			$migration_id = (int) $job->migration_id;
			if ( MigrationRegistry::all_sites_complete( $migration_id ) ) {
				if ( MigrationRegistry::complete_migration( $migration_id ) ) {
					self::maybe_send_notification( $migration_id );
				}
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

		$tables = [
			$wpdb->posts    => [ 'post_content', 'post_excerpt', 'post_title', 'guid' ],
			$wpdb->postmeta => [ 'meta_value' ],
			$wpdb->options  => [ 'option_value' ],
		];

		foreach ( $tables as $table => $columns ) {
			foreach ( $columns as $col ) {
				if ( $wpdb->options === $table && 'option_value' === $col ) {
					self::replace_in_options( $table, $replacements );
					continue;
				}
				self::replace_in_column( $table, $col, $replacements );
			}
		}

		restore_current_blog();
	}

	private static function replace_in_column( string $table, string $col, array $replacements ): void {
		global $wpdb;

		// Determine the primary key column name.
		$pk_col  = false !== strpos( $table, 'postmeta' ) ? 'meta_id' : 'ID';
		$last_pk = 0;
		$batch   = 200;

		while ( true ) {
			// Keyset pagination — stable under concurrent writes, O(n) instead of O(n²).
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT {$pk_col} AS pk, `{$col}` AS val FROM `{$table}` WHERE {$pk_col} > %d ORDER BY {$pk_col} ASC LIMIT %d",
				$last_pk,
				$batch
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
				$last_pk = (int) $row['pk'];
			}
		}
	}

	private static function replace_in_options( string $table, array $replacements ): void {
		global $wpdb;

		$last_pk = 0;
		$batch   = 200;

		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_id AS pk, option_name, option_value AS val FROM `{$table}`
				  WHERE option_id > %d
				    AND option_name NOT IN ('siteurl','home')
				  ORDER BY option_id ASC
				  LIMIT %d",
				$last_pk,
				$batch
			), ARRAY_A );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( in_array( $row['option_name'], self::SKIP_OPTION_NAMES, true ) ) {
					$last_pk = (int) $row['pk'];
					continue;
				}
				$original = $row['val'];
				$replaced = self::safe_replace( $original, $replacements );
				if ( $replaced !== $original ) {
					$wpdb->update( $table, [ 'option_value' => $replaced ], [ 'option_id' => $row['pk'] ] );
				}
				$last_pk = (int) $row['pk'];
			}
		}
	}

	/**
	 * Serialization-aware, binary-safe string replacement.
	 *
	 * @param mixed  $value        The value to search within.
	 * @param array  $replacements Map of old => new strings.
	 * @return mixed
	 */
	public static function safe_replace( $value, array $replacements ) {
		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $k => $v ) {
				$new_key            = is_string( $k ) ? str_replace( array_keys( $replacements ), array_values( $replacements ), $k ) : $k;
				$result[ $new_key ] = self::safe_replace( $v, $replacements );
			}
			return $result;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		// Skip binary data — str_replace on non-UTF-8 bytes corrupts EXIF/binary meta.
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			// Use allowed_classes:false to prevent object instantiation (PHP object injection).
			// Incomplete-class objects round-trip correctly through serialize/unserialize.
			$data = unserialize( $value, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
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
