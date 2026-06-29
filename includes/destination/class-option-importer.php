<?php

namespace HBMigrator\Destination;

use HBMigrator\IdMap;
use HBMigrator\MigrationRegistry;
use HBMigrator\PipelineController;
use HBMigrator\SourceClient;

class OptionImporter {

	// Options whose values store post IDs — remap using the ID map.
	private const POST_ID_OPTIONS = [ 'page_on_front', 'page_for_posts', 'page_for_privacy_policy' ];

	// Options that must never be overwritten from the source, regardless of what the source sends.
	// A compromised source could otherwise escalate privileges by rewriting roles or credentials.
	// Theme and plugin state (template, stylesheet, active_plugins) are handled separately below
	// with existence checks rather than blocked entirely.
	private const DEST_DENYLIST = [
		'wp_user_roles',
		'wp_capabilities',
		'user_roles',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
		'admin_email',
		'siteurl',
		'home',
	];

	public static function process( int $site_job_id, int $offset, int $attempt ): void {
		try {
			$job = MigrationRegistry::get_site_job( $site_job_id );
			if ( ! $job || ! $job->dest_blog_id ) {
				return;
			}

			$migration = MigrationRegistry::get_migration( (int) $job->migration_id );
			if ( ! $migration ) {
				return;
			}
			if ( 'cancelled' === $migration->status ) {
				return;
			}

			MigrationRegistry::update_site_job( $site_job_id, [ 'status' => 'running', 'current_stage' => 'options', 'error_message' => null ] );

			$response = SourceClient::get(
				$migration->source_url,
				$migration->source_api_key,
				'source/sites/' . (int) $job->source_blog_id . '/options',
				[ 'offset' => $offset ]
			);

			$options  = $response['options'] ?? $response; // back-compat if response is flat
			$has_more = $response['has_more'] ?? false;

			switch_to_blog( (int) $job->dest_blog_id );

			$theme_stylesheet   = null;
			$raw_active_plugins = null;

			foreach ( $options as $name => $value ) {
				// Destination-side guard: never import security-sensitive options even if the
				// source includes them (the source-side OptionReader::SKIP list may differ).
				if ( in_array( $name, self::DEST_DENYLIST, true ) ) {
					continue;
				}

				// Theme state: collect stylesheet for conditional application after the loop.
				// template and current_theme are set automatically by switch_theme().
				if ( 'stylesheet' === $name ) {
					$theme_stylesheet = (string) $value;
					continue;
				}
				if ( 'template' === $name || 'current_theme' === $name ) {
					continue;
				}

				// Plugin state: collect for conditional application after the loop.
				if ( 'active_plugins' === $name ) {
					$raw_active_plugins = $value;
					continue;
				}

				// Remap post-ID options.
				if ( in_array( $name, self::POST_ID_OPTIONS, true ) && (int) $value > 0 ) {
					$dest_id = IdMap::get( $site_job_id, 'post', (int) $value );
					if ( $dest_id ) {
						$value = $dest_id;
					}
				}

				// Safe unserialize: no class instantiation guards against PHP object injection.
				$stored = is_serialized( $value )
					? unserialize( $value, [ 'allowed_classes' => false ] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					: $value;
				update_option( $name, $stored );
			}

			// Apply source theme only if it is installed at the destination.
			if ( null !== $theme_stylesheet ) {
				$theme = wp_get_theme( $theme_stylesheet );
				if ( $theme->exists() ) {
					switch_theme( $theme_stylesheet );
				}
			}

			// Apply active plugins, keeping only those physically present at the destination.
			// update_option() is used directly (no activation hooks) because migration restores
			// an already-configured site's state rather than freshly installing plugins.
			if ( null !== $raw_active_plugins ) {
				$plugins = is_serialized( $raw_active_plugins )
					? unserialize( $raw_active_plugins, [ 'allowed_classes' => false ] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					: $raw_active_plugins;
				if ( is_array( $plugins ) ) {
					$available = array_values( array_filter(
						$plugins,
						fn( string $p ) => validate_file( $p ) === 0 && file_exists( WP_PLUGIN_DIR . '/' . $p )
					) );
					update_option( 'active_plugins', $available );
				}
			}

			restore_current_blog();

			// Circuit breaker: a looping or malicious source returning has_more:true forever
			// would keep the pipeline running indefinitely without this ceiling.
			$max_options = 20000;
			if ( $has_more && ( $offset + 200 ) < $max_options ) {
				as_enqueue_async_action(
					'hbm_import_options',
					[ 'site_job_id' => $site_job_id, 'offset' => $offset + 200, 'attempt' => 0 ],
					'hb-migrator'
				);
				return;
			}

			// Options done — start search-replace from phase 0.
			as_enqueue_async_action(
				'hbm_search_replace',
				[ 'site_job_id' => $site_job_id, 'attempt' => 0, 'phase' => 0, 'last_pk' => 0 ],
				'hb-migrator'
			);

		} catch ( \Throwable $e ) {
			if ( isset( $job ) && $job ) {
				restore_current_blog();
			}
			PipelineController::handle_batch_failure(
				'hbm_import_options',
				[ 'site_job_id' => $site_job_id, 'offset' => $offset, 'attempt' => $attempt ],
				$e,
				$site_job_id
			);
		}
	}
}
