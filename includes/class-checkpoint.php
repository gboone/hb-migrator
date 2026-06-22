<?php

namespace HBMigrator;

class Checkpoint {

	/** Insert the three stage rows in 'pending' state. */
	public static function initialize_stages(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hbm_queue';
		foreach ( [ 'sql', 'wxr', 'media' ] as $stage ) {
			$wpdb->insert( $table, [
				'stage'         => $stage,
				'status'        => 'pending',
				'batch_offset'  => 0,
				'row_offset'    => 0,
				'total_items'   => 0,
				'attempt_count' => 0,
				'error_message' => null,
			] );
		}
	}

	public static function get_stage( string $stage ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'hbm_queue';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE stage = %s LIMIT 1", $stage ) );
	}

	public static function get_all_stages(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'hbm_queue';
		return $wpdb->get_results( "SELECT * FROM `$table`" ) ?: [];
	}

	/**
	 * Update stage-level offset (table_index for SQL, last_post_id for WXR,
	 * file_index for media).
	 */
	public static function set_offset( string $stage, int $offset ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_queue',
			[ 'batch_offset' => $offset ],
			[ 'stage' => $stage ]
		);
	}

	/** Update the within-table row offset (SQL keyset last_pk). */
	public static function set_row_offset( string $stage, int $row_offset ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_queue',
			[ 'row_offset' => $row_offset ],
			[ 'stage' => $stage ]
		);
	}

	public static function set_total( string $stage, int $total ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_queue',
			[ 'total_items' => $total ],
			[ 'stage' => $stage ]
		);
	}

	public static function set_status( string $stage, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_queue',
			[ 'status' => $status ],
			[ 'stage' => $stage ]
		);
	}

	public static function mark_stage_complete( string $stage ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_queue',
			[ 'status' => 'complete' ],
			[ 'stage' => $stage ]
		);
	}

	public static function mark_stage_failed( string $stage, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_queue',
			[
				'status'        => 'failed',
				'error_message' => $error,
			],
			[ 'stage' => $stage ]
		);
	}

	public static function increment_attempt( string $stage ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hbm_queue';
		$wpdb->query( $wpdb->prepare(
			"UPDATE `$table` SET attempt_count = attempt_count + 1 WHERE stage = %s",
			$stage
		) );
	}

	public static function reset_all(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}hbm_queue" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}hbm_media_files" );
	}

	/** Return true when all three stages are in 'complete' status. */
	public static function is_pipeline_complete(): bool {
		$stages = self::get_all_stages();
		if ( count( $stages ) < 3 ) {
			return false;
		}
		foreach ( $stages as $row ) {
			if ( 'complete' !== $row->status ) {
				return false;
			}
		}
		return true;
	}

	/** Return true when any stage is in 'failed' status. */
	public static function is_pipeline_failed(): bool {
		$stages = self::get_all_stages();
		foreach ( $stages as $row ) {
			if ( 'failed' === $row->status ) {
				return true;
			}
		}
		return false;
	}

	// -----------------------------------------------------------------------
	// Media file index helpers
	// -----------------------------------------------------------------------

	/**
	 * Insert a batch of media file records.
	 *
	 * @param array<array{relative_path: string, file_size: int, partition: int}> $files
	 */
	public static function insert_media_files( array $files ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hbm_media_files';
		foreach ( $files as $f ) {
			$wpdb->insert( $table, [
				'relative_path' => $f['relative_path'],
				'file_size'     => (int) $f['file_size'],
				'partition'     => (int) $f['partition'],
				'status'        => 'pending',
			] );
		}
	}

	/** Return the total number of media files. */
	public static function count_media_files(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbm_media_files" );
	}

	/**
	 * Return a page of pending media files starting at $offset.
	 *
	 * @return object[]
	 */
	public static function get_media_files( int $offset, int $limit ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}hbm_media_files ORDER BY id LIMIT %d OFFSET %d",
			$limit,
			$offset
		) ) ?: [];
	}

	/** Mark a media file row as copied. */
	public static function mark_media_file_copied( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbm_media_files',
			[ 'status' => 'copied' ],
			[ 'id' => $id ]
		);
	}

	/** Return the highest partition number assigned during discovery. */
	public static function max_media_partition(): int {
		global $wpdb;
		$val = $wpdb->get_var( "SELECT MAX(partition) FROM {$wpdb->prefix}hbm_media_files" );
		return (int) $val;
	}
}
