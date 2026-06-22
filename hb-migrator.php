<?php
/**
 * Plugin Name: HB Migrator
 * Plugin URI:  https://github.com/Automattic/hb-migrator
 * Description: End-to-end multisite migration via REST. Works as source and destination.
 * Version:     2.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Network:     true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HBM_VERSION', '2.0.0' );
define( 'HBM_DB_VERSION', 3 );
define( 'HBM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HBM_API_NAMESPACE', 'hb-migrator/v1' );

// Action Scheduler must be required before plugins_loaded so the
// "best version wins" singleton can evaluate all bundled copies.
require_once HBM_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';

// PSR-4-style autoloader:
//   HBMigrator\Foo            → includes/class-foo.php
//   HBMigrator\Source\Foo     → includes/source/class-foo.php
//   HBMigrator\Destination\Foo → includes/destination/class-foo.php
//   HBMigrator\Admin\Foo      → includes/admin/class-foo.php
spl_autoload_register( function ( $class ) {
	$prefix = 'HBMigrator\\';
	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$parts    = explode( '\\', $relative );
	$last     = array_pop( $parts );
	$filename = 'class-' . strtolower( preg_replace( '/([A-Z])/', '-$1', lcfirst( $last ) ) ) . '.php';
	if ( $parts ) {
		$path = HBM_PLUGIN_DIR . 'includes/' . strtolower( implode( '/', $parts ) ) . '/' . $filename;
	} else {
		$path = HBM_PLUGIN_DIR . 'includes/' . $filename;
	}
	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, [ 'HBMigrator\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'HBMigrator\\Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'HBMigrator\\Plugin', 'get_instance' ] );
