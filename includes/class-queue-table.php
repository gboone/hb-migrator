<?php

namespace HBMigrator;

class QueueTable {

	public static function maybe_create_or_upgrade(): void {
		// Only run in admin or CLI context to avoid concurrent race on first page load.
		if ( ! ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			return;
		}

		$installed = (int) get_site_option( 'hbm_db_version', 0 );
		if ( $installed >= HBM_DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::upgrade_indexes();
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
  status_token varchar(64) DEFAULT NULL,
  error_message text DEFAULT NULL,
  notification_email varchar(200) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at datetime DEFAULT NULL,
  user_conflict_policy varchar(20) NOT NULL DEFAULT 'merge',
  site_conflict_policy varchar(20) NOT NULL DEFAULT 'generate_new',
  media_conflict_policy varchar(20) NOT NULL DEFAULT 'import_all',
  media_import_scope varchar(20) NOT NULL DEFAULT 'all',
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
  UNIQUE KEY lookup (site_job_id, object_type, source_id)
) $charset;";

		$sql_user_site_roles = "CREATE TABLE {$p}hbm_user_site_roles (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_id bigint(20) UNSIGNED NOT NULL,
  source_user_id bigint(20) UNSIGNED NOT NULL,
  source_blog_id bigint(20) UNSIGNED NOT NULL,
  role varchar(64) NOT NULL,
  PRIMARY KEY  (id),
  KEY migration_blog (migration_id, source_blog_id)
) $charset;";

		dbDelta( $sql_migrations );
		dbDelta( $sql_site_jobs );
		dbDelta( $sql_id_map );
		dbDelta( $sql_user_site_roles );
	}

	/**
	 * Upgrade v2 → v3: promote the non-unique hbm_id_map lookup key to UNIQUE.
	 * dbDelta cannot modify existing index types, so we do it manually.
	 */
	private static function upgrade_indexes(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_id_map';

		// Only touch the index if the table already exists (upgrade path, not fresh install).
		$exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		) );
		if ( ! $exists ) {
			return;
		}

		$non_unique = $wpdb->get_var( $wpdb->prepare(
			'SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
			$table,
			'lookup'
		) );

		if ( $non_unique === null ) {
			return; // Index doesn't exist yet — dbDelta will create it as UNIQUE.
		}

		if ( (int) $non_unique === 1 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `lookup`" );
			$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `lookup` (site_job_id, object_type, source_id)" );
			// phpcs:enable
		}
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
