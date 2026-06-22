<?php

namespace HBMigrator;

class Plugin {

	private static ?Plugin $instance = null;

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		// Register AS action hooks on plugins_loaded so handlers are present
		// before AS begins dispatching pending actions (avoids the action_scheduler_init
		// timing gap where pending actions could fire without a registered callback).
		add_action( 'plugins_loaded', [ $this, 'register_action_hooks' ], 20 );

		// REST endpoint must be registered on all requests (REST requests are not admin).
		Admin\ProgressEndpoint::init();

		// Admin-facing integrations.
		if ( is_admin() ) {
			Admin\AdminPage::init();
			Admin\DownloadHandler::init();
		}
	}

	public function register_action_hooks(): void {
		add_action( 'hbm_export_sql_batch',   [ Exporters\SqlExporter::class, 'process_batch' ], 10, 3 );
		add_action( 'hbm_export_wxr_batch',   [ Exporters\WxrExporter::class, 'process_batch' ], 10, 2 );
		add_action( 'hbm_export_media_batch', [ Exporters\MediaExporter::class, 'process_batch' ], 10, 3 );
		add_action( 'hbm_media_finalize',     [ Exporters\MediaExporter::class, 'finalize' ], 10, 1 );
	}

	/** Runs on plugin activation. */
	public static function activate(): void {
		QueueTable::maybe_create_or_upgrade();
		ArtifactManager::create_export_directory();
	}

	/** Runs on plugin deactivation — preserves data for resume. */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'hb-migrator' );
		}
	}
}
