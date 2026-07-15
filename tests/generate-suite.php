<?php
/**
 * Regenerates tests/.generated/ — one thin wrapper class per WP_UnitTestCase
 * subclass declared in tests/test-*.php.
 *
 * PHPUnit 12's directory-based test discovery (TestSuiteLoader) requires a
 * file's basename to be a case-insensitive suffix of the class it declares.
 * This codebase follows WordPress core's test-file convention instead
 * (test-checkpoint.php declaring Test_MigrationRegistry and Test_IdMap,
 * multiple classes per hyphenated file) so PHPUnit can never discover it
 * directly. Each generated wrapper file is named after — and merely extends
 * — one real test class, satisfying PHPUnit's discovery rule without
 * touching any real test file. phpunit.xml points its <directory> at this
 * generated folder instead of tests/ itself.
 */

$tests_dir     = __DIR__;
$generated_dir = $tests_dir . '/.generated';

if ( is_dir( $generated_dir ) ) {
	foreach ( glob( $generated_dir . '/*.php' ) as $stale ) {
		unlink( $stale );
	}
} else {
	mkdir( $generated_dir );
}

$count = 0;

foreach ( glob( $tests_dir . '/test-*.php' ) as $file ) {
	$contents = file_get_contents( $file );

	if ( ! preg_match_all( '/^class\s+(\w+)\s+extends\s+WP_UnitTestCase\b/m', $contents, $matches ) ) {
		continue;
	}

	foreach ( $matches[1] as $class ) {
		$wrapper_class = $class . '_Suite';
		$wrapper_file  = $generated_dir . '/' . $wrapper_class . '.php';

		$body = "<?php\n"
			. 'require_once ' . var_export( realpath( $file ), true ) . ";\n"
			. "class {$wrapper_class} extends \\{$class} {}\n";

		file_put_contents( $wrapper_file, $body );
		++$count;
	}
}

fwrite( STDERR, "Generated {$count} PHPUnit suite wrapper(s) in {$generated_dir}\n" );
