<?php

namespace HBMigrator\Source;

use HBMigrator\ApiAuth;

class SiteIndex {

	public static function get_sites( \WP_REST_Request $request ): \WP_REST_Response {
		$sites = get_sites( [ 'number' => 0, 'deleted' => 0 ] );
		$data  = [];
		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );
			$data[] = [
				'blog_id'    => (int) $site->blog_id,
				'domain'     => $site->domain,
				'path'       => $site->path,
				'siteurl'    => get_option( 'siteurl' ),
				'home'       => get_option( 'home' ),
				'blogname'   => get_option( 'blogname' ),
				'upload_url' => trailingslashit( wp_upload_dir()['baseurl'] ),
				'archived'   => (bool) $site->archived,
				'spam'       => (bool) $site->spam,
			];
			restore_current_blog();
		}
		return new \WP_REST_Response( $data );
	}

	public static function proxy_migration_status( \WP_REST_Request $request ): \WP_REST_Response {
		$active = get_site_option( 'hbm_active_migration' );
		if ( ! $active || empty( $active['migration_id'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'No active migration' ], 404 );
		}

		// Pass the status_token so the destination IDOR check passes.
		$status_token = $active['status_token'] ?? '';
		$url = add_query_arg(
			[ 'status_token' => $status_token ],
			trailingslashit( $active['dest_url'] ) . 'wp-json/' . HBM_API_NAMESPACE . '/destination/status/' . (int) $active['migration_id']
		);

		$response = wp_remote_get( $url, [
			'headers'   => [ 'Authorization' => 'Bearer ' . $active['dest_key'] ],
			'timeout'   => 15,
			'sslverify' => true,
		] );
		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'error' => $response->get_error_message() ], 502 );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( [ 'error' => 'Unexpected response from destination.' ], 502 );
		}

		// Strip the Bearer token from sitemeta once the migration is done — it is no
		// longer needed for polling and should not persist on disk indefinitely.
		if ( 'complete' === ( $body['status'] ?? '' ) ) {
			$active['dest_key'] = '';
			update_site_option( 'hbm_active_migration', $active );
			\HBMigrator\Admin\AdminPage::save_history_entry( $body );
		}

		return new \WP_REST_Response( $body, wp_remote_retrieve_response_code( $response ) );
	}
}
