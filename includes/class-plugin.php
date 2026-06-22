<?php

namespace HBMigrator;

class Plugin {

	private static ?Plugin $instance = null;

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}

	private function setup(): void {
		// Upgrade schema only in admin/CLI context to avoid race on page load.
		QueueTable::maybe_create_or_upgrade();
		ApiAuth::get_or_create_key();

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		// Register AS action hooks directly — we're already on plugins_loaded priority 10,
		// so we can't re-add to plugins_loaded at an earlier priority.
		$this->register_action_hooks();

		if ( is_admin() || is_network_admin() ) {
			Admin\AdminPage::init();
		}
	}

	public function register_rest_routes(): void {
		Source\SourceEndpoints::register_routes();
		Destination\MigrationReceiver::register_routes();
		Admin\ProgressEndpoint::register_routes();
	}

	public function register_action_hooks(): void {
		add_action( 'hbm_import_network_users', [ Destination\UserImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_import_terms',         [ Destination\TermImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_import_posts',         [ Destination\PostImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_import_media',         [ Destination\MediaImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_import_options',       [ Destination\OptionImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_search_replace',       [ Destination\SearchReplace::class, 'process' ], 10, 2 );
	}

	public static function activate(): void {
		QueueTable::maybe_create_or_upgrade();
		ApiAuth::get_or_create_key();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'hb-migrator' );
		}
	}
}
