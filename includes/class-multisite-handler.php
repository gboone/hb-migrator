<?php

namespace HBMigrator;

class MultisiteHandler {

	public static function is_multisite_export(): bool {
		return is_multisite();
	}

	public static function get_blog_id(): int {
		return get_current_blog_id();
	}

	/**
	 * Network-level tables excluded from single-site exports.
	 * Filterable so callers can extend the list post-import if gaps emerge.
	 *
	 * @return string[]
	 */
	public static function get_excluded_tables(): array {
		$tables = [
			'wp_users',
			'wp_usermeta',
			'wp_blogs',
			'wp_blogmeta',
			'wp_site',
			'wp_sitemeta',
			'wp_signups',
			'wp_registration_log',
			'wp_sitecategories',
		];
		return (array) apply_filters( 'hbm_excluded_network_tables', $tables );
	}

	/**
	 * Rewrite a table name from its source prefix to wp_{blog_id}_.
	 *
	 * Network-level tables are returned unchanged (they are filtered out
	 * upstream, but this guard prevents accidental rewriting if the caller
	 * passes them in).
	 */
	public static function rewrite_table_name( string $table ): string {
		global $wpdb;
		if ( ! self::is_multisite_export() ) {
			return $table;
		}
		if ( in_array( $table, self::get_excluded_tables(), true ) ) {
			return $table;
		}
		$prefix  = $wpdb->prefix;
		$blog_id = self::get_blog_id();
		if ( 0 === strpos( $table, $prefix ) ) {
			return 'wp_' . $blog_id . '_' . substr( $table, strlen( $prefix ) );
		}
		return $table;
	}

	/**
	 * Rewrite the user_roles option name for multisite VIP import.
	 * VIP import expects the option key to be wp_{blog_id}_user_roles.
	 */
	public static function rewrite_option_user_roles( string $option_name ): string {
		if ( ! self::is_multisite_export() ) {
			return $option_name;
		}
		if ( 'user_roles' === $option_name ) {
			return 'wp_' . self::get_blog_id() . '_user_roles';
		}
		return $option_name;
	}

	/**
	 * Rewrite an upload path for multisite VIP import.
	 * wp-content/uploads/ → wp-content/uploads/sites/{blog_id}/
	 */
	public static function rewrite_media_path( string $path ): string {
		if ( ! self::is_multisite_export() ) {
			return $path;
		}
		return str_replace(
			'wp-content/uploads/',
			'wp-content/uploads/sites/' . self::get_blog_id() . '/',
			$path
		);
	}
}
