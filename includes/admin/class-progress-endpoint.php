<?php

namespace HBMigrator\Admin;

use HBMigrator\MigrationRegistry;

class ProgressEndpoint {

	public static function register_routes(): void {
		register_rest_route( HBM_API_NAMESPACE, '/admin/migrations', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ self::class, 'list_migrations' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );
	}

	public static function list_migrations( \WP_REST_Request $request ): \WP_REST_Response {
		$migrations = MigrationRegistry::list_migrations();
		$data       = [];

		foreach ( $migrations as $m ) {
			$jobs  = MigrationRegistry::get_site_jobs_for_migration( (int) $m->id );
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
			$data[] = [
				'migration_id' => (int) $m->id,
				'source_url'   => $m->source_url,
				'status'       => $m->status,
				'created_at'   => $m->created_at,
				'completed_at' => $m->completed_at,
				'sites'        => $sites,
			];
		}

		return new \WP_REST_Response( $data );
	}
}
