<?php

namespace HBMigrator\Source;

class OptionReader {

	private const SKIP = [
		'siteurl',
		'home',
		'upload_path',
		'upload_url_path',
		'bloguploaddir',
		'fileupload_url',
		'upload_space_used',
		'active_plugins',
		'inactive_plugins',
		'template',
		'stylesheet',
		'current_theme',
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

		switch_to_blog( $blog_id );

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE autoload = 'yes'
			    OR option_name IN ('blogname','blogdescription','permalink_structure',
			                       'page_on_front','page_for_posts','show_on_front',
			                       'posts_per_page','timezone_string','gmt_offset',
			                       'date_format','time_format','default_category',
			                       'default_comment_status','default_ping_status',
			                       'comment_moderation','blog_public')
			 LIMIT 500",
			ARRAY_A
		);

		$options = [];
		foreach ( $rows as $row ) {
			$name = $row['option_name'];
			if ( in_array( $name, self::SKIP, true ) ) {
				continue;
			}
			// Skip internal WP transients and private options.
			if ( 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
				continue;
			}
			$options[ $name ] = $row['option_value'];
		}

		restore_current_blog();

		return new \WP_REST_Response( $options );
	}
}
