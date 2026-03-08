<?php
/**
 * PHPUnit bootstrap for WP Sudo integration tests.
 *
 * Loads the real WordPress test environment (WP_UnitTestCase, real DB).
 * Brain\Monkey and Patchwork are NOT loaded here — integration tests
 * use real WordPress functions, not mocks.
 *
 * Prerequisites: run `bash bin/install-wp-tests.sh` once before using this suite.
 *
 * @package WP_Sudo\Tests\Integration
 */

$_repo_root = dirname( __DIR__, 2 );
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_repo_tests_dir = $_repo_root . '/.tmp/wordpress-tests-lib';
	if ( file_exists( $_repo_tests_dir . '/includes/functions.php' ) ) {
		$_tests_dir = $_repo_tests_dir;
	} else {
		$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "ERROR: WordPress test library not found at {$_tests_dir}/includes/functions.php\n";
	echo "Run: bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest\n";
	exit( 1 );
}

// Polyfills path: tell the WP bootstrap where to find yoast/phpunit-polyfills.
// The WP bootstrap defaults to dirname(__DIR__, 3)/vendor/... which resolves to
// /tmp/vendor/... (wrong). Point it to this plugin's vendor directory instead.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

// Give access to tests_add_filter() before WordPress boots.
require_once $_tests_dir . '/includes/functions.php';

// Load the plugin under test at muplugins_loaded — the earliest safe hook.
// muplugins_loaded fires after WP core functions exist but before plugins_loaded.
// wp-sudo.php calls add_action(), which requires WordPress functions.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $_repo_root ) {
		// Load Two Factor plugin if installed (required by TwoFactorTest).
		$_wp_core = getenv( 'WP_CORE_DIR' );
		if ( ! $_wp_core ) {
			$_repo_wp_core = $_repo_root . '/.tmp/wordpress';
			if ( file_exists( $_repo_wp_core . '/wp-load.php' ) ) {
				$_wp_core = $_repo_wp_core;
			} else {
				$_wp_core = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress';
			}
		}
		$_tf_file = $_wp_core . '/wp-content/plugins/two-factor/two-factor.php';
		if ( file_exists( $_tf_file ) ) {
			require_once $_tf_file;
		}

		require_once dirname( __DIR__, 2 ) . '/wp-sudo.php';
	}
);

// Composer autoloader for WP_Sudo\Tests\Integration\* classes.
// Do NOT require tests/bootstrap.php — it defines ABSPATH and class stubs
// that conflict with the real WordPress environment loaded below.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Boot the real WordPress environment (connects to MySQL, fires init hooks).
require $_tests_dir . '/includes/bootstrap.php';
