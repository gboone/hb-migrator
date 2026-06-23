<?php

namespace HBMigrator\Destination;

use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;

class SearchReplace {

	// Authoritative skip list for options. Matches OptionReader::SKIP so both
	// sides exclude the same names (source never sends them; destination never replaces them).
	private const SKIP_OPTION_NAMES = [ 'siteurl', 'home' ];

	// How many seconds to spend per AS action before checkpointing.
	// VIP AS runner has no hard per-action wall-clock kill, but 50 seconds leaves
	// headroom for the rest of process() and avoids unexpected timeout behavior.
	private const TIME_LIMIT = 50.0;

	// Total number of phases (posts×4 columns, postmeta, options).
	private const PHASE_COUNT = 6;

	/**
	 * Processes one phase of the search-replace pass.
	 *
	 * @param int $site_job_id
	 * @param int $attempt      Retry counter managed by PipelineController.
	 * @param int $phase        0–5: which table/column to work on this action.
	 * @param int $last_pk      Keyset cursor — resume from this pk within the phase.
	 */
	public static function process( int $site_job_id, int $attempt, int $phase = 0, int $last_pk = 0 ): void {
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

			if ( empty( $replacements ) ) {
				// Nothing to replace — skip straight to finalization.
				self::finalize( $site_job_id, (int) $job->migration_id );
				return;
			}

			$checkpoint = self::run_phase(
				(int) $job->dest_blog_id,
				$replacements,
				$phase,
				$last_pk
			);

			if ( null !== $checkpoint ) {
				// Time budget exhausted mid-phase — dispatch a continuation from the checkpoint.
				as_enqueue_async_action(
					'hbm_search_replace',
					[ 'site_job_id' => $site_job_id, 'attempt' => 0, 'phase' => $phase, 'last_pk' => $checkpoint ],
					'hb-migrator'
				);
				return;
			}

			$next_phase = $phase + 1;
			if ( $next_phase < self::PHASE_COUNT ) {
				// Phase complete — dispatch the next phase.
				as_enqueue_async_action(
					'hbm_search_replace',
					[ 'site_job_id' => $site_job_id, 'attempt' => 0, 'phase' => $next_phase, 'last_pk' => 0 ],
					'hb-migrator'
				);
				return;
			}

			// All phases done.
			self::finalize( $site_job_id, (int) $job->migration_id );

		} catch ( \Throwable $e ) {
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_search_replace',
				[ 'site_job_id' => $site_job_id, 'attempt' => $attempt, 'phase' => $phase, 'last_pk' => $last_pk ],
				$e,
				$site_job_id
			);
		}
	}

	private static function finalize( int $site_job_id, int $migration_id ): void {
		MigrationRegistry::update_site_job( $site_job_id, [
			'status'        => 'complete',
			'current_stage' => null,
		] );

		// complete_migration() uses a NOT EXISTS subquery to atomically check that all
		// site jobs are complete before updating the migration row — no separate read needed.
		if ( MigrationRegistry::complete_migration( $migration_id ) ) {
			self::maybe_send_notification( $migration_id );
		}
	}

	/**
	 * Processes one phase within the destination subsite blog context.
	 *
	 * @param int   $blog_id
	 * @param array $replacements
	 * @param int   $phase      0=posts.post_content, 1=post_excerpt, 2=post_title,
	 *                          3=guid, 4=postmeta.meta_value, 5=options.option_value
	 * @param int   $from_pk    Resume keyset cursor for the phase.
	 * @return int|null  null = phase complete; int = last pk processed (time budget exceeded).
	 */
	private static function run_phase( int $blog_id, array $replacements, int $phase, int $from_pk ): ?int {
		global $wpdb;
		switch_to_blog( $blog_id );
		$started = microtime( true );

		switch ( $phase ) {
			case 0:
				$result = self::replace_in_column( $wpdb->posts, 'post_content', $replacements, $from_pk, $started );
				break;
			case 1:
				$result = self::replace_in_column( $wpdb->posts, 'post_excerpt', $replacements, $from_pk, $started );
				break;
			case 2:
				$result = self::replace_in_column( $wpdb->posts, 'post_title', $replacements, $from_pk, $started );
				break;
			case 3:
				$result = self::replace_in_column( $wpdb->posts, 'guid', $replacements, $from_pk, $started );
				break;
			case 4:
				$result = self::replace_in_column( $wpdb->postmeta, 'meta_value', $replacements, $from_pk, $started );
				break;
			case 5:
				$result = self::replace_in_options( $wpdb->options, $replacements, $from_pk, $started );
				break;
			default:
				$result = null;
		}

		restore_current_blog();
		return $result;
	}

	/**
	 * Replaces strings in a single non-options column with keyset pagination.
	 *
	 * @return int|null  null = table exhausted; int = last pk (time budget exceeded, checkpoint here).
	 */
	private static function replace_in_column( string $table, string $col, array $replacements, int $from_pk, float $started ): ?int {
		global $wpdb;

		$pk_col  = false !== strpos( $table, 'postmeta' ) ? 'meta_id' : 'ID';
		$last_pk = $from_pk;
		$batch   = 200;

		while ( true ) {
			// Keyset pagination — stable under concurrent writes, O(n) instead of O(n²).
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT {$pk_col} AS pk, `{$col}` AS val FROM `{$table}` WHERE {$pk_col} > %d ORDER BY {$pk_col} ASC LIMIT %d",
				$last_pk,
				$batch
			), ARRAY_A );

			if ( empty( $rows ) ) {
				return null; // Phase complete.
			}

			foreach ( $rows as $row ) {
				$original = $row['val'];
				$replaced = self::safe_replace( $original, $replacements );
				if ( $replaced !== $original ) {
					$wpdb->update( $table, [ $col => $replaced ], [ $pk_col => $row['pk'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				$last_pk = (int) $row['pk'];
			}

			if ( ( microtime( true ) - $started ) > self::TIME_LIMIT ) {
				return $last_pk; // Budget exceeded — checkpoint here.
			}
		}
	}

	/**
	 * Replaces strings in the options table with keyset pagination.
	 *
	 * @return int|null  null = table exhausted; int = last option_id (time budget exceeded).
	 */
	private static function replace_in_options( string $table, array $replacements, int $from_pk, float $started ): ?int {
		global $wpdb;

		$last_pk = $from_pk;
		$batch   = 200;

		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT option_id AS pk, option_name, option_value AS val FROM `{$table}`
				  WHERE option_id > %d
				    AND option_name NOT IN ('siteurl','home')
				  ORDER BY option_id ASC
				  LIMIT %d",
				$last_pk,
				$batch
			), ARRAY_A );

			if ( empty( $rows ) ) {
				return null; // Phase complete.
			}

			foreach ( $rows as $row ) {
				if ( in_array( $row['option_name'], self::SKIP_OPTION_NAMES, true ) ) {
					$last_pk = (int) $row['pk'];
					continue;
				}
				$original = $row['val'];
				$replaced = self::safe_replace( $original, $replacements );
				if ( $replaced !== $original ) {
					$wpdb->update( $table, [ 'option_value' => $replaced ], [ 'option_id' => $row['pk'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				$last_pk = (int) $row['pk'];
			}

			if ( ( microtime( true ) - $started ) > self::TIME_LIMIT ) {
				return $last_pk; // Budget exceeded — checkpoint here.
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
