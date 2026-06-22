<?php

namespace HBMigrator;

class MigrationRegistry {

	// -----------------------------------------------------------------------
	// Migrations
	// -----------------------------------------------------------------------

	public static function create_migration( string $source_url, string $source_api_key, ?string $email ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'hbm_migrations',
			[
				'source_url'         => $source_url,
				'source_api_key'     => $source_api_key,
				'status'             => 'pending',
				'status_token'       => bin2hex( random_bytes( 16 ) ),
				'notification_email' => $email,
			]
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_migration( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_migrations';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ) );
	}

	public static function find_active_migration_for_source( string $source_url ): ?object {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_migrations';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE source_url = %s AND status IN ('pending', 'running') ORDER BY id DESC LIMIT 1",
			$source_url
		) );
	}

	public static function update_migration_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->base_prefix . 'hbm_migrations',
			[ 'status' => $status ],
			[ 'id' => $id ]
		);
	}

	public static function fail_migration( int $id, string $error_message ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->base_prefix . 'hbm_migrations',
			[ 'status' => 'failed', 'error_message' => $error_message ],
			[ 'id' => $id ]
		);
	}

	/**
	 * Atomically marks the migration complete. Returns true only if this call
	 * won the race (rows_affected = 1), preventing duplicate completion emails.
	 */
	public static function complete_migration( int $id ): bool {
		global $wpdb;
		$table  = $wpdb->base_prefix . 'hbm_migrations';
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET status = 'complete', completed_at = NOW() WHERE id = %d AND status = 'running'",
			$id
		) );
		if ( $result ) {
			// Clean up working tables once migration is done.
			$jobs = self::get_site_jobs_for_migration( $id );
			foreach ( $jobs as $job ) {
				IdMap::delete_for_job( (int) $job->id );
			}
			UserSiteRoles::delete_for_migration( $id );
		}
		return (bool) $result;
	}

	public static function list_migrations(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM `{$wpdb->base_prefix}hbm_migrations` ORDER BY id DESC" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input, table name is hardcoded
	}

	// -----------------------------------------------------------------------
	// Site jobs
	// -----------------------------------------------------------------------

	public static function create_site_job(
		int $migration_id,
		int $source_blog_id,
		string $source_domain,
		string $source_siteurl,
		string $source_upload_url,
		string $dest_path
	): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'hbm_site_jobs',
			[
				'migration_id'      => $migration_id,
				'source_blog_id'    => $source_blog_id,
				'source_domain'     => $source_domain,
				'source_siteurl'    => $source_siteurl,
				'source_upload_url' => $source_upload_url,
				'dest_path'         => $dest_path,
				'status'            => 'pending',
			]
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_site_job( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->base_prefix}hbm_site_jobs` WHERE id = %d", $id ) );
	}

	public static function update_site_job( int $id, array $fields ): void {
		global $wpdb;
		$wpdb->update( $wpdb->base_prefix . 'hbm_site_jobs', $fields, [ 'id' => $id ] );
	}

	public static function get_site_jobs_for_migration( int $migration_id ): array {
		global $wpdb;
		$table = $wpdb->base_prefix . 'hbm_site_jobs';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE migration_id = %d ORDER BY id", $migration_id )
		) ?: [];
	}

	public static function all_sites_complete( int $migration_id ): bool {
		$jobs = self::get_site_jobs_for_migration( $migration_id );
		if ( empty( $jobs ) ) {
			return false;
		}
		foreach ( $jobs as $job ) {
			if ( 'complete' !== $job->status ) {
				return false;
			}
		}
		return true;
	}
}
