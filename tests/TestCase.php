<?php
/**
 * Base test case for WP Sudo unit tests.
 *
 * Sets up and tears down Brain\Monkey for WordPress function mocking.
 *
 * @package WP_Sudo\Tests
 */

namespace WP_Sudo\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default stubs for sanitization functions used throughout the plugin.
		// Individual tests can override these with specific expectations.
			Functions\stubs(
				array(
					'wp_unslash'          => static function ( $value ) {
						return $value;
					},
					'sanitize_text_field' => static function ( $str ) {
						return (string) $str;
					},
					'__'                  => static function ( $text ) {
						return (string) $text;
					},
					'get_current_user_id' => static function () {
						return 0;
					},
					'wp_get_referer'      => static function () {
						return false;
					},
				)
			);

		// Default stub for the login-session token used by Sudo_Session binding.
		// Empty by default so binding stays inert for tests that do not exercise
		// it (no SESSION_BIND_META_KEY value => verify_token skips the check, and
		// set_token clears any bind). Binding tests override this with when().
		Functions\when( 'wp_get_session_token' )->justReturn( '' );

		// Default stub for application password UUID — null means not app-password auth.
		// Individual tests can override with Functions\when() for specific UUIDs.
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( null );

		// Default stubs for multisite functions — single-site mode.
		// Using when() instead of stubs() so tests can re-define with when().
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'network_admin_url' )->alias(
			static function ( string $path = '' ) {
				return 'https://example.com/wp-admin/network/' . ltrim( $path, '/' );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::CHALLENGE_COOKIE ] );
		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] );

		// Reset the WP_Application_Passwords mock state.
		if ( class_exists( 'WP_Application_Passwords' ) && property_exists( 'WP_Application_Passwords', 'mock_passwords' ) ) {
			\WP_Application_Passwords::$mock_passwords = null;
		}

		if ( class_exists( 'WP_User_Query' ) && property_exists( 'WP_User_Query', 'mock_total' ) ) {
			\WP_User_Query::$mock_total      = 0;
			\WP_User_Query::$mock_results    = array();
			\WP_User_Query::$last_query_vars = array();
		}

		// Clear per-request static caches to prevent cross-test contamination.
		\WP_Sudo\Action_Registry::reset_cache();
		\WP_Sudo\Sudo_Session::reset_cache();
		\WP_Sudo\Admin::reset_cache();
		\WP_Sudo\Event_Store::reset_runtime_cache();
		\WP_Sudo\Event_Recorder::reset_buffer();

		Monkey\tearDown();
		parent::tearDown();
	}

}
