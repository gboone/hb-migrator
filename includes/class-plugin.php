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

		// Source-side content-event hooks for U8's webhook trigger. Registered
		// unconditionally on every install — each hook is a no-op unless this blog has a
		// sync_webhook_token on file (see Source\SyncWebhook::get_sync_config()), since this
		// plugin runs symmetrically as source and/or destination on any given install.
		Source\SyncWebhook::init();

		if ( is_admin() || is_network_admin() ) {
			Admin\AdminPage::init();
		}
	}

	public function register_rest_routes(): void {
		Source\SourceEndpoints::register_routes();
		Destination\MigrationReceiver::register_routes();
		Destination\SyncReceiver::register_routes();
		Admin\ProgressEndpoint::register_routes();
	}

	public function register_action_hooks(): void {
		add_action( 'hbm_import_network_users', [ Destination\UserImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_import_terms',         [ Destination\TermImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_import_posts',         [ Destination\PostImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_import_media',         [ Destination\MediaImporter::class, 'process' ], 10, 4 );
		add_action( 'hbm_import_options',       [ Destination\OptionImporter::class, 'process' ], 10, 3 );
		add_action( 'hbm_search_replace',       [ Destination\SearchReplace::class, 'process' ], 10, 4 );
		add_action( 'hbm_sync_pass',            [ Destination\SyncDispatcher::class, 'run_sync_pass' ], 10, 1 );

		// Register U3's real 'posts' sync stage. SyncDispatcher depends only on
		// SyncStageInterface (see class-sync-stage-interface.php); this is the wiring
		// point U3-U6 use to plug their real implementations in without SyncDispatcher
		// ever depending on a concrete stage class directly.
		add_filter( 'hbm_sync_stages', static function ( array $stages ) {
			$stages['posts'] = new Destination\PostSyncStage();
			return $stages;
		} );

		// Register U5's real 'comments' sync stage. SyncDispatcher::default_stages() already
		// orders 'comments' after 'posts' in its stage array, and both this filter callback
		// and U3's above only ever set/overwrite their own slot key, so registration order
		// between the two callbacks doesn't matter — only SyncDispatcher's fixed iteration
		// order does, which already puts posts before comments (U5 depends on this so a
		// comment's post has somewhere to attach within the same pass).
		add_filter( 'hbm_sync_stages', static function ( array $stages ) {
			$stages['comments'] = new Destination\CommentSyncStage();
			return $stages;
		} );
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
