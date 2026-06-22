<?php

namespace HBMigrator;

class QueueTable {

	public static function maybe_create_or_upgrade(): void {
		$installed = (int) get_site_option( 'hbm_db_version', 0 );
		if ( $installed >= HBM_DB_VERSION ) {
			return;
		}
		self::create_tables();
		self::drop_old_tables();
		update_site_option( 'hbm_db_version', HBM_DB_VERSION );
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->base_prefix;

		$sql_migrations = "CREATE TABLE {$p}hbm_migrations (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  source_url varchar(500) NOT NULL,
  source_api_key varchar(64) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  notification_email varchar(200) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $charset;";

		$sql_site_jobs = "CREATE TABLE {$p}hbm_site_jobs (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_id bigint(20) UNSIGNED NOT NULL,
  source_blog_id bigint(20) UNSIGNED NOT NULL,
  dest_blog_id bigint(20) UNSIGNED DEFAULT NULL,
  source_domain varchar(500) NOT NULL,
  source_siteurl varchar(500) NOT NULL DEFAULT '',
  source_upload_url varchar(500) NOT NULL DEFAULT '',
  dest_path varchar(500) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  current_stage varchar(32) DEFAULT NULL,
  stage_offset bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  stage_total bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  error_message text DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY migration_id (migration_id),
  KEY status (status)
) $charset;";

		$sql_id_map = "CREATE TABLE {$p}hbm_id_map (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  site_job_id bigint(20) UNSIGNED NOT NULL,
  object_type varchar(16) NOT NULL,
  source_id bigint(20) UNSIGNED NOT NULL,
  dest_id bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY  (id),
  KEY lookup (site_job_id, object_type, source_id)
) $charset;";

		dbDelta( $sql_migrations );
		dbDelta( $sql_site_jobs );
		dbDelta( $sql_id_map );
	}

	private static function drop_old_tables(): void {
		global $wpdb;
		$p = $wpdb->base_prefix;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$p}hbm_queue`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$p}hbm_media_files`" );
		// phpcs:enable
	}
}
