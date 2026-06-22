<?php
/**
 * Plugin Name:       HB Migrator
 * Plugin URI:        https://github.com/Automattic/hb-migrator
 * Description:       Async export pipeline for self-hosted WordPress → VIP migrations.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Automattic
 * License:           GPL-2.0-or-later
 * Text Domain:       hb-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HBM_VERSION', '0.1.0' );
define( 'HBM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HBM_DB_VERSION', 1 );

// Action Scheduler must be required before plugins_loaded so the
// "best version wins" singleton can evaluate all bundled copies.
require_once HBM_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';

// PSR-4-style autoloader: HBMigrator\Foo → includes/class-foo.php
spl_autoload_register( function ( $class ) {
	$prefix = 'HBMigrator\\';
	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	// Convert namespace separators and StudlyCaps → kebab-case filenames.
	$parts = explode( '\\', $relative );
	$file  = HBM_PLUGIN_DIR . 'includes/';
	$last  = array_pop( $parts );
	if ( $parts ) {
		$file .= strtolower( implode( '/', $parts ) ) . '/';
	}
	$file .= 'class-' . strtolower( preg_replace( '/([A-Z])/', '-$1', lcfirst( $last ) ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, [ 'HBMigrator\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'HBMigrator\\Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'HBMigrator\\Plugin', 'get_instance' ] );
