<?php

namespace HBMigrator;

class SourceClient {

	public static function get( string $source_url, string $api_key, string $path, array $params = [] ): array {
		$url = trailingslashit( $source_url ) . 'wp-json/' . HBM_API_NAMESPACE . '/' . ltrim( $path, '/' );
		if ( $params ) {
			$url = add_query_arg( $params, $url );
		}
		$response = wp_remote_get( $url, [
			'headers'   => [ 'Authorization' => 'Bearer ' . $api_key ],
			'timeout'   => 60,
			'sslverify' => true,
		] );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Source request failed: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new \RuntimeException( "Source returned HTTP $code for $path" );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			throw new \RuntimeException( "Source returned non-JSON for $path" );
		}
		return $body;
	}
}
