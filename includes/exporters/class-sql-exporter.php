<?php

namespace HBMigrator\Exporters;

use HBMigrator\ArtifactManager;
use HBMigrator\Checkpoint;
use HBMigrator\MultisiteHandler;
use HBMigrator\PipelineController;

class SqlExporter {

	private const BATCH_SIZE_DEFAULT = 500;
	private const EXPORT_FILENAME    = 'hbm-export.sql';

	/**
	 * AS action callback: hbm_export_sql_batch($table_index, $last_pk, $attempt).
	 *
	 * $table_index maps to batch_offset in hbm_queue.
	 * $last_pk     maps to row_offset and drives keyset pagination.
	 */
	public static function process_batch( int $table_index, int $last_pk, int $attempt ): void {
		try {
			global $wpdb;
			$batch_size = (int) apply_filters( 'hbm_sql_batch_size', self::BATCH_SIZE_DEFAULT );
			$tables     = self::get_export_tables();

			if ( $table_index >= count( $tables ) ) {
				PipelineController::stage_complete( 'sql' );
				return;
			}

			$table   = $tables[ $table_index ];
			$pk_col  = self::get_primary_key( $table );
			$out     = ArtifactManager::get_export_dir() . self::EXPORT_FILENAME;
			$mode    = ( 0 === $table_index && 0 === $last_pk ) ? 'w' : 'a';

			$fh = fopen( $out, $mode );
			if ( false === $fh ) {
				throw new \RuntimeException( "Cannot open export file for writing: $out" );
			}

			// Write DDL on the first batch of each table.
			if ( 0 === $last_pk ) {
				fwrite( $fh, self::get_create_table_statement( $table ) . "\n\n" );
			}

			// Keyset pagination when PK exists; LIMIT/OFFSET fallback otherwise.
			if ( $pk_col ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM `$table` WHERE `$pk_col` > %d ORDER BY `$pk_col` LIMIT %d",
						$last_pk,
						$batch_size
					),
					ARRAY_A
				);
			} else {
				// Tables without a usable PK use $last_pk as an OFFSET.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM `$table` LIMIT %d OFFSET %d",
						$batch_size,
						$last_pk
					),
					ARRAY_A
				);
			}

			if ( ! empty( $rows ) ) {
				fwrite( $fh, self::rows_to_insert( $table, $rows ) );
				$new_pk = $pk_col ? (int) end( $rows )[ $pk_col ] : $last_pk + count( $rows );
				fclose( $fh );
				Checkpoint::set_row_offset( 'sql', $new_pk );
				Checkpoint::set_offset( 'sql', $table_index );
				as_enqueue_async_action( 'hbm_export_sql_batch', [ $table_index, $new_pk, 0 ], 'hb-migrator' );
				return;
			}

			fclose( $fh );

			// This table is done — advance to the next.
			$next = $table_index + 1;
			Checkpoint::set_row_offset( 'sql', 0 );
			Checkpoint::set_offset( 'sql', $next );

			if ( $next >= count( $tables ) ) {
				PipelineController::stage_complete( 'sql' );
				return;
			}

			as_enqueue_async_action( 'hbm_export_sql_batch', [ $next, 0, 0 ], 'hb-migrator' );

		} catch ( \Throwable $e ) {
			PipelineController::handle_batch_failure( 'sql', $e, [ $table_index, $last_pk, $attempt ] );
		}
	}

	/** Return all tables to export, excluding multisite network tables. */
	public static function get_export_tables(): array {
		global $wpdb;
		$all_tables = $wpdb->get_col( 'SHOW TABLES' );
		$excluded   = MultisiteHandler::is_multisite_export()
			? MultisiteHandler::get_excluded_tables()
			: [];

		return array_values( array_filter( $all_tables, fn( $t ) => ! in_array( $t, $excluded, true ) ) );
	}

	/**
	 * Return the DDL for $table with ENGINE forced to InnoDB.
	 * Table name is rewritten for multisite exports.
	 */
	public static function get_create_table_statement( string $table ): string {
		global $wpdb;
		$row = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
		if ( ! $row || empty( $row[1] ) ) {
			throw new \RuntimeException( "Could not get CREATE TABLE for `$table`" );
		}
		$ddl = $row[1];

		// Enforce InnoDB — VIP only supports InnoDB.
		$ddl = preg_replace( '/ENGINE=\w+/i', 'ENGINE=InnoDB', $ddl );

		// Rewrite table name for multisite exports.
		$target_name = MultisiteHandler::rewrite_table_name( $table );
		if ( $target_name !== $table ) {
			$ddl = str_replace( "`$table`", "`$target_name`", $ddl );
		}

		return "DROP TABLE IF EXISTS `$target_name`;\n" . $ddl . ';';
	}

	/**
	 * Return the name of the AUTO_INCREMENT column, or null if none exists.
	 */
	public static function get_primary_key( string $table ): ?string {
		global $wpdb;
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A );
		foreach ( $columns as $col ) {
			if ( 'PRI' === ( $col['Key'] ?? '' ) && 'auto_increment' === ( $col['Extra'] ?? '' ) ) {
				return $col['Field'];
			}
		}
		return null;
	}

	/**
	 * Convert a result set to a single INSERT statement.
	 *
	 * @param string   $table Source table name.
	 * @param array[]  $rows  Rows as associative arrays.
	 */
	public static function rows_to_insert( string $table, array $rows ): string {
		global $wpdb;
		$target = MultisiteHandler::rewrite_table_name( $table );
		$cols   = '`' . implode( '`, `', array_keys( $rows[0] ) ) . '`';
		$values = [];
		foreach ( $rows as $row ) {
			$placeholders = implode( ', ', array_fill( 0, count( $row ), '%s' ) );
			$values[]     = '(' . $wpdb->prepare( $placeholders, array_values( $row ) ) . ')';
		}
		return "INSERT INTO `$target` ($cols) VALUES\n" . implode( ",\n", $values ) . ";\n";
	}
}
