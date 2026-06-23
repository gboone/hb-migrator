<?php

namespace HBMigrator\Destination;

class PreflightChecker {

	/**
	 * Check for conflicts between the source payload and this destination network.
	 *
	 * @param array $payload {
	 *   @type string[] $user_emails  Source user email addresses to check.
	 *   @type string[] $site_paths   Computed dest_path values for source sites.
	 *   @type array[]  $media        Each entry: {blog_id, filename, post_name, md5, filesize}.
	 * }
	 * @return array {conflicts: {users: string[], sites: string[], media: array[]}}
	 */
	public function check( array $payload ): array {
		return [
			'conflicts' => [
				'users' => $this->check_user_emails( (array) ( $payload['user_emails'] ?? [] ) ),
				'sites' => $this->check_site_paths( (array) ( $payload['site_paths'] ?? [] ) ),
				'media' => $this->check_media( (array) ( $payload['media'] ?? [] ) ),
			],
		];
	}

	private function check_user_emails( array $emails ): array {
		if ( empty( $emails ) ) {
			return [];
		}

		global $wpdb;
		$emails        = array_map( 'sanitize_email', $emails );
		$placeholders  = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$matched = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_email FROM {$wpdb->users} WHERE user_email IN ($placeholders)",
			...$emails
		) );

		return array_values( $matched ?: [] );
	}

	private function check_site_paths( array $paths ): array {
		if ( empty( $paths ) || ! is_multisite() ) {
			return [];
		}

		$network = get_network();
		if ( ! $network ) {
			return [];
		}

		$sites = get_sites( [
			'domain'     => $network->domain,
			'path__in'   => $paths,
			'network_id' => $network->id,
			'number'     => count( $paths ),
			'fields'     => 'ids',
		] );

		if ( empty( $sites ) ) {
			return [];
		}

		// Return just the matched paths.
		$matched = [];
		foreach ( $sites as $site_id ) {
			$site = get_site( $site_id );
			if ( $site && in_array( $site->path, $paths, true ) ) {
				$matched[] = $site->path;
			}
		}

		return array_values( $matched );
	}

	private function check_media( array $media_items ): array {
		if ( empty( $media_items ) ) {
			return [];
		}

		$matched = [];

		foreach ( $media_items as $item ) {
			$blog_id   = isset( $item['blog_id'] ) ? (int) $item['blog_id'] : 0;
			$post_name = sanitize_title( (string) ( $item['post_name'] ?? $item['filename'] ?? '' ) );
			$md5       = (string) ( $item['md5'] ?? '' );

			if ( ! $blog_id || ! $post_name || ! $md5 ) {
				continue;
			}

			$match = $this->find_attachment_by_name_and_hash( $blog_id, $post_name, $md5 );
			if ( $match ) {
				$matched[] = [
					'blog_id'   => $blog_id,
					'post_name' => $post_name,
					'md5'       => $md5,
					'dest_id'   => $match,
				];
			}
		}

		return $matched;
	}

	private function find_attachment_by_name_and_hash( int $blog_id, string $post_name, string $expected_md5 ): int {
		switch_to_blog( $blog_id );

		$posts = get_posts( [
			'post_type'   => 'attachment',
			'name'        => $post_name,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
		] );

		if ( empty( $posts ) ) {
			restore_current_blog();
			return 0;
		}

		$attachment_id = (int) $posts[0];
		$file_path     = get_attached_file( $attachment_id );

		if ( ! $file_path || ! is_readable( $file_path ) ) {
			restore_current_blog();
			return 0;
		}

		$actual_md5 = hash_file( 'md5', $file_path );
		restore_current_blog();

		return ( $actual_md5 && hash_equals( $expected_md5, $actual_md5 ) ) ? $attachment_id : 0;
	}
}
