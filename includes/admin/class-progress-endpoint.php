<?php

namespace HBMigrator\Admin;

use HBMigrator\PipelineController;

class ProgressEndpoint {

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			'hb-migrator/v1',
			'/progress',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_progress' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			]
		);
	}

	public static function get_progress( \WP_REST_Request $request ): \WP_REST_Response {
		$progress  = PipelineController::get_progress();
		$artifacts = \HBMigrator\ArtifactManager::list_artifacts();

		$is_running  = false;
		$is_complete = true;
		$is_failed   = false;

		foreach ( $progress as $data ) {
			if ( 'running' === $data['status'] ) {
				$is_running = true;
			}
			if ( 'complete' !== $data['status'] ) {
				$is_complete = false;
			}
			if ( 'failed' === $data['status'] ) {
				$is_failed = true;
			}
		}

		return new \WP_REST_Response( [
			'stages'      => $progress,
			'is_running'  => $is_running,
			'is_complete' => $is_complete && ! empty( $progress ),
			'is_failed'   => $is_failed,
			'artifacts'   => $artifacts,
		] );
	}
}
