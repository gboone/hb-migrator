<?php

namespace HBMigrator\Destination;

class PreflightChecker {

	/**
	 * Check for conflicts between the source payload and this destination network.
	 *
	 * @param array $payload {
	 *   @type string[] $user_emails  Source user email addresses to check.
	 *   @type string[] $site_paths   Computed dest_path values for source sites.
	 *   @type array[]  $media        Each entry: {blog_id, filename, post_name, filesize}.
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

			if ( ! $blog_id || ! $post_name ) {
				continue;
			}

			$match = $this->find_attachment_by_name( $blog_id, $post_name );
			if ( $match ) {
				$matched[] = [
					'blog_id'   => $blog_id,
					'post_name' => $post_name,
					'dest_id'   => $match,
				];
			}
		}

		return $matched;
	}

	private function find_attachment_by_name( int $blog_id, string $post_name ): int {
		switch_to_blog( $blog_id );

		$posts = get_posts( [
			'post_type'   => 'attachment',
			'name'        => $post_name,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
		] );

		restore_current_blog();

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}
}
