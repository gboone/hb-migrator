<?php
/**
 * Local test config: WP core lives at ../.wordpress-core (downloaded, gitignored), the DB
 * driver is the SQLite integration drop-in installed at .wordpress-core/wp-content/db.php —
 * this repo/plugin has no SQLite dependency itself, this is test-execution infrastructure only.
 * See docs/testing.md.
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/.wordpress-core/' );

// Values are unused by the SQLite driver but some WP core code paths reference the constants.
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wordpress_test' );
define( 'DB_PASSWORD', 'wordpress_test' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );

// Multisite is required — this plugin is multisite-only.
define( 'WP_TESTS_MULTISITE', true );
