<?php

namespace HBMigrator;

class IdMap {

	/** site_job_id used for network-level objects (users). */
	const NETWORK = 0;

	public static function set( int $site_job_id, string $type, int $source_id, int $dest_id ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_id_map';

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` (site_job_id, object_type, source_id, dest_id)
			 VALUES (%d, %s, %d, %d)
			 ON DUPLICATE KEY UPDATE dest_id = VALUES(dest_id)",
			$site_job_id,
			$type,
			$source_id,
			$dest_id
		) );
	}

	public static function get( int $site_job_id, string $type, int $source_id ): ?int {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_id_map';
		$val   = $wpdb->get_var( $wpdb->prepare(
			"SELECT dest_id FROM `{$table}` WHERE site_job_id = %d AND object_type = %s AND source_id = %d",
			$site_job_id,
			$type,
			$source_id
		) );
		return null !== $val ? (int) $val : null;
	}

	public static function delete_for_job( int $site_job_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->base_prefix . 'hbm_id_map', [ 'site_job_id' => $site_job_id ] );
	}
}
