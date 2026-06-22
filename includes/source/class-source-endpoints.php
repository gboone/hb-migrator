<?php

namespace HBMigrator\Source;

use HBMigrator\ApiAuth;

class SourceEndpoints {

	public static function register_routes(): void {
		$auth         = fn( \WP_REST_Request $r ) => ApiAuth::verify_request( $r );
		$ns           = HBM_API_NAMESPACE;
		$blog_id_args = [
			'blog_id' => [
				'validate_callback' => fn( $value ) => (bool) get_site( (int) $value ),
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route( $ns, '/source/sites', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ SiteIndex::class, 'get_sites' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/source/sites/(?P<blog_id>\d+)/terms', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ TermReader::class, 'get_terms' ],
			'permission_callback' => $auth,
			'args'                => $blog_id_args,
		] );

		register_rest_route( $ns, '/source/sites/(?P<blog_id>\d+)/posts', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ PostReader::class, 'get_posts' ],
			'permission_callback' => $auth,
			'args'                => $blog_id_args,
		] );

		register_rest_route( $ns, '/source/users', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ UserReader::class, 'get_users' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/source/sites/(?P<blog_id>\d+)/media', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ MediaReader::class, 'get_media' ],
			'permission_callback' => $auth,
			'args'                => $blog_id_args,
		] );

		register_rest_route( $ns, '/source/sites/(?P<blog_id>\d+)/options', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ OptionReader::class, 'get_options' ],
			'permission_callback' => $auth,
			'args'                => $blog_id_args,
		] );

		// Proxy endpoint: source admin polls this to get destination migration status.
		register_rest_route( $ns, '/source/migration-status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ SiteIndex::class, 'proxy_migration_status' ],
			'permission_callback' => fn() => current_user_can( 'manage_network' ),
		] );
	}
}
