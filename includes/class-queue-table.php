<?php

namespace HBMigrator;

class QueueTable {

	/** Run on activation and on plugins_loaded when DB version is stale. */
	public static function maybe_create_or_upgrade(): void {
		$installed = (int) get_option( 'hbm_db_version', 0 );
		if ( $installed >= HBM_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'hbm_db_version', HBM_DB_VERSION );
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// hbm_queue: one row per export stage.
		// batch_offset encodes stage-specific progress (table_index for SQL, last post ID for WXR).
		// row_offset encodes within-table row position for SQL keyset pagination (last_pk seen).
		$sql_queue = "CREATE TABLE {$wpdb->prefix}hbm_queue (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  stage varchar(32) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  batch_offset bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  row_offset bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  total_items bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  attempt_count tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  error_message text,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY stage (stage)
) $charset;";

		// hbm_media_files: durable ordered file list for the media export stage.
		// Populated during discovery batch; consumed by subsequent copy batches.
		$sql_media = "CREATE TABLE {$wpdb->prefix}hbm_media_files (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  relative_path varchar(500) NOT NULL,
  file_size bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  partition smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'pending',
  PRIMARY KEY  (id),
  KEY partition_status (partition, status)
) $charset;";

		dbDelta( $sql_queue );
		dbDelta( $sql_media );
	}
}
