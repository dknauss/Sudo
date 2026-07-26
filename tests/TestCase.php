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
		// it. The empty verifier keys the proof record under a shared slot and the
		// HMAC binds to it. Binding/concurrency tests override this with when().
		Functions\when( 'wp_get_session_token' )->justReturn( '' );

		// Default stub for the auth-salt used to HMAC-sign the sudo proof record.
		// A fixed value keeps activation/verification deterministic across tests;
		// forgery tests rely on an attacker being unable to reproduce this value.
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-auth-salt' );

		// Default stub for the enforcement-path object-cache bypass. Production
		// deletes the user_meta cache entry before re-reading the proof record so
		// a poisoned persistent cache cannot forge a session; a no-op is fine here.
		Functions\when( 'wp_cache_delete' )->justReturn( true );

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

	/**
	 * Stub add_query_arg()/wp_parse_url() with FAITHFUL WordPress core semantics.
	 *
	 * This mirrors wp-includes/functions.php add_query_arg()/build_query()/
	 * _http_build_query() byte-for-byte, including the behavior most other
	 * stubs in this suite get wrong: newly-added array values are concatenated
	 * WITHOUT urlencode() (build_query() calls _http_build_query() with
	 * $urlencode = false). Only values already present in the URL's existing
	 * query string get urlencode_deep()'d, because they were parsed back out
	 * of a real (already-encoded) query string via wp_parse_str().
	 *
	 * This distinction is load-bearing: a caller that nests a full URL
	 * (itself containing '&') as a raw, un-pre-encoded VALUE inside the
	 * array passed to add_query_arg() will have that nested '&' become a
	 * new top-level query separator in the output — silently truncating
	 * the nested URL's own query string. Do NOT use http_build_query()
	 * (which encodes by default) to stub this function; it hides that bug.
	 *
	 * @return void
	 */
	protected function stub_faithful_add_query_arg(): void {
		Functions\when( 'wp_parse_str' )->alias(
			static function ( $string, &$array ) {
				parse_str( (string) $string, $array );
			}
		);

		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ) {
				if ( is_array( $args[0] ) ) {
					$uri = ( count( $args ) < 2 || false === $args[1] ) ? ( $_SERVER['REQUEST_URI'] ?? '' ) : $args[1];
				} else {
					$uri = ( count( $args ) < 3 || false === $args[2] ) ? ( $_SERVER['REQUEST_URI'] ?? '' ) : $args[2];
				}

				$frag = strstr( $uri, '#' );
				if ( $frag ) {
					$uri = substr( $uri, 0, -strlen( $frag ) );
				} else {
					$frag = '';
				}

				$protocol = '';
				if ( 0 === stripos( $uri, 'http://' ) ) {
					$protocol = 'http://';
					$uri      = substr( $uri, 7 );
				} elseif ( 0 === stripos( $uri, 'https://' ) ) {
					$protocol = 'https://';
					$uri      = substr( $uri, 8 );
				}

				if ( str_contains( $uri, '?' ) ) {
					list( $base, $query ) = explode( '?', $uri, 2 );
					$base                .= '?';
				} elseif ( $protocol || ! str_contains( $uri, '=' ) ) {
					$base  = $uri . '?';
					$query = '';
				} else {
					$base  = '';
					$query = $uri;
				}

				parse_str( $query, $qs );
				$qs = array_map(
					static function ( $v ) {
						return is_array( $v ) ? $v : urlencode( (string) $v );
					},
					$qs
				);

				if ( is_array( $args[0] ) ) {
					foreach ( $args[0] as $k => $v ) {
						$qs[ $k ] = $v; // NOT urlencoded — matches real WP behavior.
					}
				} else {
					$qs[ $args[0] ] = $args[1]; // NOT urlencoded.
				}

				foreach ( $qs as $k => $v ) {
					if ( false === $v ) {
						unset( $qs[ $k ] );
					}
				}

				$pairs = array();
				foreach ( $qs as $k => $v ) {
					if ( null === $v ) {
						continue;
					}
					$pairs[] = $k . '=' . ( false === $v ? '0' : $v );
				}
				$ret = implode( '&', $pairs );
				$ret = trim( $ret, '?' );
				$ret = preg_replace( '#=(&|$)#', '$1', $ret );
				$ret = $protocol . $base . $ret . $frag;
				$ret = rtrim( $ret, '?' );
				return str_replace( '?#', '#', $ret );
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

	/**
	 * Build a valid, HMAC-signed sudo proof record matching production's schema.
	 *
	 * Mirrors Sudo_Session::set_token()/resolve_valid_proof(): the record stored
	 * under Sudo_Session::PROOF_META_KEY is an array of the cookie-token hash,
	 * the expiry, and an HMAC over "$user_id|$verifier|$token_hash|$expires"
	 * keyed with wp_salt('auth') (stubbed to 'unit-test-auth-salt' in setUp()).
	 * Pass this back from a get_user_meta() stub for the PROOF_META_KEY branch to
	 * simulate an active session for the current browser/user.
	 *
	 * @param int    $user_id  User the record belongs to.
	 * @param string $cookie   Plaintext wp_sudo_token cookie value.
	 * @param int    $expires  Session expiry timestamp.
	 * @param string $verifier Raw login-session verifier bound into the HMAC.
	 * @param string $salt     Auth salt used for the HMAC.
	 * @return array{token: string, expires: int, hmac: string}
	 */
	protected function make_proof_record(
		int $user_id,
		string $cookie,
		int $expires,
		string $verifier = '',
		string $salt = 'unit-test-auth-salt'
	): array {
		$token_hash = hash( 'sha256', $cookie );

		return array(
			'token'   => $token_hash,
			'expires' => $expires,
			'hmac'    => hash_hmac( 'sha256', "{$user_id}|{$verifier}|{$token_hash}|{$expires}", $salt ),
		);
	}

}
