<?php

namespace HBMigrator;

/**
 * Stores per-blog role assignments during user import, so TermImporter can
 * assign roles after subsite creation without making additional HTTP requests.
 */
class UserSiteRoles {

	public static function store( int $migration_id, int $source_user_id, int $source_blog_id, string $role ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'hbm_user_site_roles',
			[
				'migration_id'   => $migration_id,
				'source_user_id' => $source_user_id,
				'source_blog_id' => $source_blog_id,
				'role'           => $role,
			]
		);
	}

	/**
	 * @return array<array{source_user_id:int, role:string}>
	 */
	public static function get_for_migration_blog( int $migration_id, int $source_blog_id ): array {
		global $wpdb;
		$table  = $wpdb->base_prefix . 'hbm_user_site_roles';
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT source_user_id, role FROM `{$table}` WHERE migration_id = %d AND source_blog_id = %d",
			$migration_id,
			$source_blog_id
		), ARRAY_A );
		return $rows ?: [];
	}

	public static function delete_for_migration( int $migration_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->base_prefix . 'hbm_user_site_roles', [ 'migration_id' => $migration_id ] );
	}
}
