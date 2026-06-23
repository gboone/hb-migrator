<?php

namespace HBMigrator;

class SourceClientException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $http_status = 0,
		public readonly bool $retryable = true
	) {
		parent::__construct( $message );
	}
}

class SourceClient {

	public static function get( string $source_url, string $api_key, string $path, array $params = [] ): array {
		$url = trailingslashit( $source_url ) . 'wp-json/' . HBM_API_NAMESPACE . '/' . ltrim( $path, '/' );
		if ( $params ) {
			$url = add_query_arg( $params, $url );
		}

		// Re-validate the resolved IP before each async HTTP call to prevent DNS rebinding.
		// MigrationReceiver::is_safe_source_url() checks only at begin()-time; Action Scheduler
		// jobs re-resolve DNS independently, so an attacker who passed validation with a public
		// IP could flip their DNS to an internal address before the first job fires.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host ) {
			$ip = gethostbyname( $host );
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				throw new SourceClientException(
					"Source hostname {$host} resolved to a private/reserved IP address.",
					0,
					false // not retryable — re-resolving won't fix a rebinding attempt
				);
			}
		}

		$response = wp_remote_get( $url, [
			'headers'   => [ 'Authorization' => 'Bearer ' . $api_key ],
			'timeout'   => 60,
			'sslverify' => true,
		] );
		if ( is_wp_error( $response ) ) {
			throw new SourceClientException( 'Source request failed: ' . $response->get_error_message(), 0, true );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$retryable = $code >= 500 || 429 === $code;
			throw new SourceClientException( "Source returned HTTP {$code} for {$path}", $code, $retryable );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			throw new SourceClientException( "Source returned non-JSON for {$path}", 200, false );
		}
		return $body;
	}
}
