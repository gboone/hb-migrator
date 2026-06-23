<?php

namespace HBMigrator\Source;

class PreflightService {

	/**
	 * Gather local source data for the given blog IDs.
	 *
	 * Returns summary stats and the raw inputs needed by the destination's
	 * pre-flight check (user emails, source site URLs, media fingerprints).
	 */
	public static function gather( array $blog_ids ): array {
		$blog_ids = array_map( 'intval', $blog_ids );

		// --- User emails (network-wide, one query) ---
		$user_emails = get_users( [ 'blog_id' => 0, 'fields' => 'user_email', 'number' => 0 ] );
		$user_emails = array_values( array_unique( $user_emails ) );

		// --- Per-site stats and media fingerprints ---
		$site_count  = 0;
		$post_count  = 0;
		$source_siteurls = [];
		$media_items = [];

		$limit = (int) apply_filters( 'hbm_preflight_media_limit', 500 );

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );

			$site_count++;
			$source_siteurls[] = get_option( 'siteurl' );

			// Post count (published).
			$counts     = wp_count_posts();
			$post_count += (int) ( $counts->publish ?? 0 );

			// Media fingerprints up to the cap.
			$attachments = get_posts( [
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => $limit,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			] );

			foreach ( $attachments as $attachment_id ) {
				$file = get_attached_file( (int) $attachment_id );
				if ( ! $file || ! is_readable( $file ) ) {
					continue;
				}
				$md5 = hash_file( 'md5', $file );
				if ( ! $md5 ) {
					continue;
				}
				$media_items[] = [
					'blog_id'   => $blog_id,
					'post_name' => get_post( (int) $attachment_id )->post_name ?? sanitize_title( basename( $file ) ),
					'filename'  => basename( $file ),
					'filesize'  => (int) filesize( $file ),
					'md5'       => $md5,
				];
			}

			restore_current_blog();
		}

		return [
			'summary' => [
				'site_count'  => $site_count,
				'user_count'  => count( $user_emails ),
				'post_count'  => $post_count,
				'media_count' => count( $media_items ),
			],
			'user_emails'      => $user_emails,
			'source_siteurls'  => $source_siteurls,
			'media'            => $media_items,
		];
	}

	/**
	 * REST callback for POST /source/run-preflight.
	 */
	public static function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$blog_ids = array_map( 'intval', (array) ( $request->get_param( 'site_ids' ) ?: [] ) );
		if ( empty( $blog_ids ) ) {
			return new \WP_REST_Response( [ 'error' => 'site_ids is required.' ], 400 );
		}

		$result = self::run( $blog_ids );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 502 );
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Run pre-flight: gather source data and send to destination for conflict checking.
	 *
	 * @param array  $blog_ids   Source blog IDs to include.
	 * @param string $dest_url   Destination site URL (from sitemeta hbm_dest_url).
	 * @param string $dest_key   Destination API key (Bearer token).
	 * @return array {summary, conflicts} or WP_Error on failure.
	 */
	public static function run( array $blog_ids, string $dest_url = '', string $dest_key = '' ): array|\WP_Error {
		if ( ! $dest_url || ! $dest_key ) {
			$dest_url = (string) get_site_option( 'hbm_dest_url', '' );
			$dest_key = (string) get_site_option( 'hbm_dest_key', '' );
		}

		if ( ! $dest_url || ! $dest_key ) {
			return new \WP_Error( 'no_destination', 'Destination URL and API key are required.' );
		}

		$gathered = self::gather( $blog_ids );

		$endpoint = trailingslashit( $dest_url ) . 'wp-json/' . HBM_API_NAMESPACE . '/destination/preflight';
		$response = wp_remote_post( $endpoint, [
			'headers'   => [
				'Authorization' => 'Bearer ' . $dest_key,
				'Content-Type'  => 'application/json',
			],
			'body'      => wp_json_encode( [
				'user_emails'     => $gathered['user_emails'],
				'source_siteurls' => $gathered['source_siteurls'],
				'media'           => $gathered['media'],
			] ),
			'timeout'   => 60,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! is_array( $body ) ) {
			return new \WP_Error(
				'destination_error',
				sprintf( 'Destination pre-flight returned HTTP %d.', $code )
			);
		}

		return [
			'summary'   => $gathered['summary'],
			'conflicts' => $body['conflicts'] ?? [ 'users' => [], 'sites' => [], 'media' => [] ],
		];
	}
}
