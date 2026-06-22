<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;

class OptionImporter {

	// Options whose values store post IDs — remap using the ID map.
	private const POST_ID_OPTIONS = [ 'page_on_front', 'page_for_posts', 'page_for_privacy_policy' ];

	public static function process( int $site_job_id, int $attempt ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
			if ( ! $migration ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'current_stage' => 'options' ] );

			$options = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/sites/' . (int) $job->source_blog_id . '/options'
			);

			switch_to_blog( (int) $job->dest_blog_id );

			foreach ( $options as $name => $value ) {
				// Remap post-ID options.
				if ( in_array( $name, self::POST_ID_OPTIONS, true ) && (int) $value > 0 ) {
					$dest_id = IdMap::get( $site_job_id, 'post', (int) $value );
					if ( $dest_id ) {
						$value = $dest_id;
					}
				}

				update_option( $name, maybe_unserialize( $value ) );
			}

			restore_current_blog();

			// Options done — run search-replace.
			as_enqueue_async_action(
				'hbm_search_replace',
				[ 'site_job_id' => $site_job_id, 'attempt' => 0 ],
				'hb-migrator'
			);

		} catch ( \Throwable $e ) {
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_import_options',
				[ 'site_job_id' => $site_job_id, 'attempt' => $attempt ],
				$e,
				$site_job_id
			);
		}
	}
}
