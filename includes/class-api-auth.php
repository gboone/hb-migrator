<?php

namespace HBMigrator;

class ApiAuth {

	public static function get_or_create_key(): string {
		$key = get_site_option( 'hbm_api_key' );
		if ( ! $key ) {
			$key = bin2hex( random_bytes( 32 ) );
			update_site_option( 'hbm_api_key', $key );
		}
		return (string) $key;
	}

	public static function verify_request( \WP_REST_Request $request ): bool {
		$auth = $request->get_header( 'authorization' );
		if ( ! $auth || 0 !== strpos( $auth, 'Bearer ' ) ) {
			return false;
		}
		$token = substr( $auth, 7 );
		return hash_equals( self::get_or_create_key(), $token );
	}
}
