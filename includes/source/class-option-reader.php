<?php

namespace HBMigrator\Source;

class OptionReader {

	/**
	 * Options always excluded from export.
	 *
	 * Environment-specific values that must not be blindly copied (siteurl, credentials, upload
	 * paths) are excluded. Theme and plugin state (template, stylesheet, active_plugins) are
	 * exported and applied conditionally by the importer — only when the theme/plugin is actually
	 * installed at the destination.
	 */
	const SKIP = [
		'siteurl',
		'home',
		'upload_path',
		'upload_url_path',
		'bloguploaddir',
		'fileupload_url',
		'upload_space_used',
		'inactive_plugins',
		'theme_switched',
		'recently_edited',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
		'admin_email',
	];

	public static function get_options( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$blog_id = (int) $request->get_param( 'blog_id' );
		$offset  = max( 0, (int) ( $request->get_param( 'offset' ) ?: 0 ) );
		$batch   = 200;

		switch_to_blog( $blog_id );

		// Explicit name list for important options that may not be autoloaded.
		$extra_names = "'blogname','blogdescription','permalink_structure','page_on_front','page_for_posts','show_on_front','posts_per_page','timezone_string','gmt_offset','date_format','time_format','default_category','default_comment_status','default_ping_status','comment_moderation','blog_public','active_plugins','template','stylesheet','current_theme'";

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE autoload = 'yes' OR option_name IN ({$extra_names})
			 ORDER BY option_id
			 LIMIT %d OFFSET %d",
			$batch,
			$offset
		), ARRAY_A );

		$options = [];
		foreach ( $rows ?? [] as $row ) {
			$name = $row['option_name'];
			if ( in_array( $name, self::SKIP, true ) ) {
				continue;
			}
			if ( 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
				continue;
			}
			$options[ $name ] = $row['option_value'];
		}

		restore_current_blog();

		return new \WP_REST_Response( [
			'options'  => $options,
			'has_more' => count( $rows ?? [] ) === $batch,
		] );
	}
}
