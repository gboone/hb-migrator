<?php

namespace HBMigrator\Source;

class UserReader {

	public static function get_users( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?: 100 ), 500 );
		$offset   = max( 0, (int) $request->get_param( 'offset' ) );

		$users = get_users( [
			'number'  => $per_page,
			'offset'  => $offset,
			'blog_id' => 0,
			'orderby' => 'ID',
			'order'   => 'ASC',
		] );

		$data = [];
		foreach ( $users as $user ) {
			// Collect per-site roles.
			$site_roles = [];
			$blogs      = get_blogs_of_user( $user->ID );
			foreach ( $blogs as $blog ) {
				$cap_key = $wpdb->get_blog_prefix( $blog->userblog_id ) . 'capabilities';
				$caps    = get_user_meta( $user->ID, $cap_key, true );
				if ( is_array( $caps ) ) {
					foreach ( array_keys( $caps ) as $role ) {
						$site_roles[] = [
							'blog_id' => (int) $blog->userblog_id,
							'role'    => $role,
						];
					}
				}
			}

			$data[] = [
				'user_login'      => $user->user_login,
				'user_email'      => $user->user_email,
				'display_name'    => $user->display_name,
				'user_registered' => $user->user_registered,
				'user_url'        => $user->user_url,
				'description'     => get_user_meta( $user->ID, 'description', true ),
				'first_name'      => get_user_meta( $user->ID, 'first_name', true ),
				'last_name'       => get_user_meta( $user->ID, 'last_name', true ),
				'source_user_id'  => (int) $user->ID,
				'site_roles'      => $site_roles,
			];
		}

		return new \WP_REST_Response( $data );
	}
}
