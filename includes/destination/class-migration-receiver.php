<?php

namespace HBMigrator\Destination;

use HBMigrator\ApiAuth;
use HBMigrator\MigrationRegistry;
use HBMigrator\MultisiteHandler;
use HBMigrator\SourceClient;

class MigrationReceiver {

	public static function register_routes(): void {
		$ns   = HBM_API_NAMESPACE;
		$auth = fn( \WP_REST_Request $r ) => ApiAuth::verify_request( $r );

		register_rest_route( $ns, '/destination/begin', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'begin' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( $ns, '/destination/status/(?P<migration_id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ self::class, 'status' ],
			'permission_callback' => $auth,
		] );
	}

	public static function begin( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! is_multisite() ) {
			return new \WP_REST_Response( [ 'error' => 'Destination must be a multisite installation.' ], 400 );
		}

		$source_url     = sanitize_text_field( $request->get_param( 'source_url' ) );
		$source_api_key = sanitize_text_field( $request->get_param( 'source_api_key' ) );
		$site_ids       = array_map( 'intval', (array) $request->get_param( 'site_ids' ) );
		$email          = sanitize_email( (string) $request->get_param( 'notification_email' ) );

		if ( ! $source_url || ! $source_api_key || empty( $site_ids ) ) {
			return new \WP_REST_Response( [ 'error' => 'source_url, source_api_key, and site_ids are required.' ], 400 );
		}

		if ( ! self::is_safe_source_url( $source_url ) ) {
			return new \WP_REST_Response( [ 'error' => 'source_url must use https and must not point to a private network address.' ], 400 );
		}

		// Idempotency: return existing running/pending migration for the same source.
		$existing = MigrationRegistry::find_active_migration_for_source( $source_url );
		if ( $existing ) {
			return new \WP_REST_Response( [
				'migration_id' => (int) $existing->id,
				'status'       => $existing->status,
				'status_token' => $existing->status_token,
			], 200 );
		}

		// Fetch source site list to populate job rows.
		try {
			$source_sites = SourceClient::get( $source_url, $source_api_key, 'source/sites' );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => 'Could not reach source: ' . $e->getMessage() ], 502 );
		}

		$sites_by_id = [];
		foreach ( $source_sites as $s ) {
			$sites_by_id[ (int) $s['blog_id'] ] = $s;
		}

		$valid_ids = array_filter( $site_ids, fn( $id ) => isset( $sites_by_id[ $id ] ) );
		if ( empty( $valid_ids ) ) {
			return new \WP_REST_Response( [ 'error' => 'None of the provided site_ids were found on the source.' ], 422 );
		}

		$migration_id = MigrationRegistry::create_migration( $source_url, $source_api_key, $email ?: null );

		foreach ( $valid_ids as $blog_id ) {
			$s         = $sites_by_id[ $blog_id ];
			$dest_path = MultisiteHandler::dest_path_for_siteurl( $s['siteurl'] );

			MigrationRegistry::create_site_job(
				$migration_id,
				$blog_id,
				$s['domain'],
				$s['siteurl'],
				$s['upload_url'] ?? '',
				$dest_path
			);
		}

		MigrationRegistry::update_migration_status( $migration_id, 'running' );

		as_enqueue_async_action(
			'hbm_import_network_users',
			[ 'migration_id' => $migration_id, 'offset' => 0, 'attempt' => 0 ],
			'hb-migrator'
		);

		$migration = MigrationRegistry::get_migration( $migration_id );

		return new \WP_REST_Response( [
			'migration_id' => $migration_id,
			'status'       => 'queued',
			'status_token' => $migration->status_token ?? '',
		], 201 );
	}

	public static function status( \WP_REST_Request $request ): \WP_REST_Response {
		$migration_id = (int) $request->get_param( 'migration_id' );
		$migration    = MigrationRegistry::get_migration( $migration_id );

		if ( ! $migration ) {
			return new \WP_REST_Response( [ 'error' => 'Migration not found.' ], 404 );
		}

		// IDOR guard: only the source that created this migration can query it.
		$provided_token = sanitize_text_field( (string) $request->get_param( 'status_token' ) );
		if ( empty( $migration->status_token ) || ! hash_equals( $migration->status_token, $provided_token ) ) {
			return new \WP_REST_Response( [ 'error' => 'Forbidden.' ], 403 );
		}

		$jobs  = MigrationRegistry::get_site_jobs_for_migration( $migration_id );
		$sites = [];
		foreach ( $jobs as $job ) {
			$sites[] = [
				'site_job_id'   => (int) $job->id,
				'source_domain' => $job->source_domain,
				'dest_path'     => $job->dest_path,
				'status'        => $job->status,
				'current_stage' => $job->current_stage,
				'stage_offset'  => (int) $job->stage_offset,
				'stage_total'   => (int) $job->stage_total,
				'error_message' => $job->error_message,
			];
		}

		return new \WP_REST_Response( [
			'migration_id'  => (int) $migration->id,
			'status'        => $migration->status,
			'source_url'    => $migration->source_url,
			'error_message' => $migration->error_message,
			'sites'         => $sites,
		] );
	}

	private static function is_safe_source_url( string $url ): bool {
		$parsed = wp_parse_url( $url );

		if ( 'https' !== ( $parsed['scheme'] ?? '' ) ) {
			return false;
		}

		$host = $parsed['host'] ?? '';
		if ( ! $host ) {
			return false;
		}

		// Resolve to IP and reject private/reserved ranges.
		$ip = gethostbyname( $host );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
