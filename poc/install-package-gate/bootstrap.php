<?php
/**
 * Bootstrap for the install_package() slice.
 *
 * Loads the WordPress test library and the slice as an mu-plugin, so the filter
 * is registered before any test runs. Deliberately separate from WP Sudo's own
 * suites: this is a proof of concept for the core proposal, not part of the
 * plugin, and it must not perturb the plugin's test counts.
 */

declare( strict_types = 1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: sys_get_temp_dir() . '/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found at {$_tests_dir}.\nRun bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require __DIR__ . '/install-package-gate.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
