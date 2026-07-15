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

		self::install();
		update_site_option( 'hbm_db_version', HBM_DB_VERSION );
	}

	/**
	 * Unconditional install/upgrade, without the admin/WP_CLI context guard above.
	 * Exposed so test bootstraps can install the schema outside a real request context.
	 */
	public static function install(): void {
		self::create_tables();
		self::upgrade_indexes();
		self::upgrade_site_jobs_sync_columns();
		self::drop_old_tables();
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

		// status: pending, running, complete, failed, cancelled — the original initial-migration
		// lifecycle — plus 'syncing' (reached only from 'complete' via "Enable Sync") and
		// 'finalized' (reached only from 'syncing' via "Finalize & Stop Sync", terminal — sync
		// cannot be re-enabled after finalize). Column stays varchar(16); no enum constraint.
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
  sync_cursor_posts datetime DEFAULT NULL,
  sync_cursor_media datetime DEFAULT NULL,
  sync_cursor_comments bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  sync_locked_at datetime DEFAULT NULL,
  sync_webhook_token varchar(64) DEFAULT NULL,
  sync_enabled_at datetime DEFAULT NULL,
  sync_finalized_at datetime DEFAULT NULL,
  sync_last_pass_at datetime DEFAULT NULL,
  sync_last_error text DEFAULT NULL,
  sync_comment_stall_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  sync_comment_stall_count int(10) UNSIGNED NOT NULL DEFAULT 0,
  sync_comment_stall_note text DEFAULT NULL,
  sync_webhook_token_delivered_at datetime DEFAULT NULL,
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

	/**
	 * Upgrade v5 → v6: add the sync-lifecycle columns to hbm_site_jobs for installs that
	 * created the table before sync existed. dbDelta's CREATE TABLE above already produces
	 * these columns on a fresh install; this covers existing installs the same way
	 * upgrade_indexes() covers the hbm_id_map unique-key change — checked individually via
	 * information_schema so a partially-upgraded table (e.g. a previous run that failed
	 * partway through) doesn't error on columns that already exist.
	 *
	 * Upgrade v6 → v7: add the three sync_comment_stall_* columns (P1 fix — see
	 * CommentSyncStage's class docblock) using this same per-column existence check, so an
	 * install already on v6 (sync enabled, mid-migration) picks up the bounded-stall columns
	 * without a fresh install/dbDelta pass.
	 *
	 * Upgrade v7 → v8: add sync_webhook_token_delivered_at (code review fix — see
	 * Destination\SyncReceiver::deliver_sync_webhook_token()'s docblock). A failed token
	 * delivery to source previously degraded a site job to cron-only sync with no signal
	 * beyond an error_log() call; this column lets a future admin-page display (or any REST
	 * caller reading the site job) tell "delivered" apart from "never confirmed," using the
	 * same per-column existence check as the columns above.
	 */
	private static function upgrade_site_jobs_sync_columns(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_site_jobs';

		// Only touch the table if it already exists (upgrade path, not fresh install).
		$exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		) );
		if ( ! $exists ) {
			return;
		}

		$columns = [
			'sync_cursor_posts'        => 'datetime DEFAULT NULL',
			'sync_cursor_media'        => 'datetime DEFAULT NULL',
			'sync_cursor_comments'     => 'bigint(20) UNSIGNED NOT NULL DEFAULT 0',
			'sync_locked_at'           => 'datetime DEFAULT NULL',
			'sync_webhook_token'       => 'varchar(64) DEFAULT NULL',
			'sync_enabled_at'          => 'datetime DEFAULT NULL',
			'sync_finalized_at'        => 'datetime DEFAULT NULL',
			'sync_last_pass_at'        => 'datetime DEFAULT NULL',
			'sync_last_error'          => 'text DEFAULT NULL',
			'sync_comment_stall_id'    => 'bigint(20) UNSIGNED NOT NULL DEFAULT 0',
			'sync_comment_stall_count' => 'int(10) UNSIGNED NOT NULL DEFAULT 0',
			'sync_comment_stall_note'  => 'text DEFAULT NULL',
			'sync_webhook_token_delivered_at' => 'datetime DEFAULT NULL',
		];

		foreach ( $columns as $column => $definition ) {
			$column_exists = $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			) );
			if ( $column_exists ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" );
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
