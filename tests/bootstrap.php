<?php
/**
 * PHPUnit bootstrap for hb-migrator.
 *
 * Test-execution infrastructure only — see docs/testing.md. WordPress core lives at
 * .wordpress-core/ (downloaded, gitignored) with the official WordPress-team SQLite
 * database-integration drop-in installed as its DB driver, so the full suite can run without a
 * MySQL server. Neither this repo's plugin code nor its production runtime has any SQLite
 * dependency — this bootstrap only affects how `phpunit` executes locally.
 */

require __DIR__ . '/generate-suite.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$wp_phpunit_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $wp_phpunit_dir . '/includes/functions.php';

/**
 * Loads the plugin under test before WordPress finishes bootstrapping, mirroring how a real
 * mu-plugin or regular plugin would be loaded.
 */
function _hbm_manually_load_plugin() {
	require dirname( __DIR__ ) . '/hb-migrator.php';

	// register_activation_hook() only fires via WP's real activate_plugin() flow, which
	// never runs here — install the schema directly so tests see the plugin's tables.
	\HBMigrator\QueueTable::install();
	\HBMigrator\ApiAuth::get_or_create_key();
	update_site_option( 'hbm_db_version', HBM_DB_VERSION );
}
tests_add_filter( 'muplugins_loaded', '_hbm_manually_load_plugin' );

require $wp_phpunit_dir . '/includes/bootstrap.php';
