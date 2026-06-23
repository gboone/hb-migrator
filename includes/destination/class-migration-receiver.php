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

		register_rest_route( $ns, '/destination/preflight', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'preflight' ],
			'permission_callback' => $auth,
		] );
	}

	public static function preflight( \WP_REST_Request $request ): \WP_REST_Response {
		// Compute dest_paths from source_siteurls on the destination side so the
		// network domain slug-stripping (MultisiteHandler) uses the correct domain.
		$source_siteurls = (array) ( $request->get_param( 'source_siteurls' ) ?: [] );
		$network_domain  = get_network() ? get_network()->domain : '';
		$site_paths      = array_map(
			fn( $url ) => MultisiteHandler::dest_path_for_siteurl( (string) $url, $network_domain ),
			$source_siteurls
		);

		$checker = new PreflightChecker();
		$result  = $checker->check( [
			'user_emails' => (array) ( $request->get_param( 'user_emails' ) ?: [] ),
			'site_paths'  => $site_paths,
			'media'       => (array) ( $request->get_param( 'media' )       ?: [] ),
		] );
		return new \WP_REST_Response( $result );
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

		// Soft mutex: prevents parallel POST /destination/begin calls from both passing
		// the idempotency check and creating duplicate migrations. Not perfectly atomic
		// (set_transient is not CAS), but reduces the race window to near-zero in practice.
		$lock_key = 'hbm_begin_' . md5( $source_url );
		if ( get_transient( $lock_key ) ) {
			return new \WP_REST_Response( [ 'error' => 'Migration start already in progress for this source. Retry in a moment.' ], 429 );
		}
		set_transient( $lock_key, 1, 10 );

		// Idempotency: return existing running/pending/failed migration for the same source.
		$existing = MigrationRegistry::find_active_migration_for_source( $source_url );
		if ( $existing ) {
			$jobs       = MigrationRegistry::get_site_jobs_for_migration( (int) $existing->id );
			$all_pending = ! empty( $jobs ) && count( $jobs ) === count(
				array_filter( $jobs, fn( $j ) => 'pending' === $j->status )
			);
			$restarted = false;
		if ( $all_pending ) {
				// AS wasn't installed when the migration was first triggered. Re-enqueue the kickoff.
				as_enqueue_async_action(
					'hbm_import_network_users',
					[ 'migration_id' => (int) $existing->id, 'offset' => 0, 'attempt' => 0 ],
					'hb-migrator'
				);
				$restarted = true;
			} else {
				// Restart failed jobs from their last checkpoint, and restart orphaned
				// pending jobs (mixed-status batch where their AS action was dropped).
				// Status is NOT set to 'running' here; each importer sets it when it fires.
				foreach ( $jobs as $job ) {
					$restartable = in_array( $job->status, [ 'failed', 'pending' ], true );
					if ( ! $restartable || 'complete' === $job->status ) {
						continue;
					}

					if ( 'failed' === $job->status ) {
						MigrationRegistry::update_site_job( (int) $job->id, [ 'error_message' => null ] );
					}

					$offset = (int) $job->stage_offset;
					switch ( $job->current_stage ) {
						case 'posts':
							as_enqueue_async_action( 'hbm_import_posts', [ 'site_job_id' => (int) $job->id, 'last_id' => $offset, 'attempt' => 0 ], 'hb-migrator' );
							break;
						case 'media':
							as_enqueue_async_action( 'hbm_import_media', [ 'site_job_id' => (int) $job->id, 'offset' => $offset, 'attempt' => 0 ], 'hb-migrator' );
							break;
						case 'options':
							as_enqueue_async_action( 'hbm_import_options', [ 'site_job_id' => (int) $job->id, 'offset' => $offset, 'attempt' => 0 ], 'hb-migrator' );
							break;
						case 'search_replace':
							as_enqueue_async_action( 'hbm_search_replace', [ 'site_job_id' => (int) $job->id, 'attempt' => 0, 'phase' => 0, 'last_pk' => 0 ], 'hb-migrator' );
							break;
						case 'terms':
							as_enqueue_async_action( 'hbm_import_terms', [ 'site_job_id' => (int) $job->id, 'offset' => $offset, 'attempt' => 0 ], 'hb-migrator' );
							break;
						default:
							// Null or unknown stage (pending job never started) — restart from terms.
							as_enqueue_async_action( 'hbm_import_terms', [ 'site_job_id' => (int) $job->id, 'offset' => 0, 'attempt' => 0 ], 'hb-migrator' );
					}
					$restarted = true;
				}
			}

			// Backfill status_token for rows created before the column existed.
			// A null token causes the admin.js poll loop to receive 403 on every request.
			$status_token = $existing->status_token;
			if ( empty( $status_token ) ) {
				$status_token = bin2hex( random_bytes( 16 ) );
				MigrationRegistry::update_migration( (int) $existing->id, [ 'status_token' => $status_token ] );
			}

			delete_transient( $lock_key );
			return new \WP_REST_Response( [
				'migration_id' => (int) $existing->id,
				'status'       => $existing->status,
				'status_token' => $status_token,
				'restarted'    => $restarted,
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

		$policies = [
			'user_conflict_policy'  => sanitize_key( (string) ( $request->get_param( 'user_conflict_policy' )  ?: 'merge' ) ),
			'site_conflict_policy'  => sanitize_key( (string) ( $request->get_param( 'site_conflict_policy' )  ?: 'generate_new' ) ),
			'media_conflict_policy' => sanitize_key( (string) ( $request->get_param( 'media_conflict_policy' ) ?: 'import_all' ) ),
		];
		$migration_id   = MigrationRegistry::create_migration( $source_url, $source_api_key, $email ?: null, $policies );
		$network_domain = get_network()->domain ?? '';

		foreach ( $valid_ids as $blog_id ) {
			$s         = $sites_by_id[ $blog_id ];
			$dest_path = MultisiteHandler::dest_path_for_siteurl( $s['siteurl'], $network_domain );

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

		delete_transient( $lock_key );
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
