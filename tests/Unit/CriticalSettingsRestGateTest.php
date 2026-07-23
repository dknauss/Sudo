<?php
/**
 * Regression test: the options.critical REST rule must gate /wp/v2/settings
 * writes keyed by their show_in_rest names, not only the raw option names.
 *
 * WordPress keys the settings endpoint by each setting's show_in_rest name, so
 * `siteurl` arrives as `url` and `admin_email` as `email`. The callback
 * previously matched only raw option names, so a cookie-authenticated
 * POST /wp/v2/settings {"url":"https://evil/"} repointed siteurl ungated —
 * an XSS-as-RCE primitive (attacker-origin scripts load same-origin in wp-admin).
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Action_Registry;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * @covers \WP_Sudo\Action_Registry
 */
class CriticalSettingsRestGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	private function rest_callback() {
		return Action_Registry::find( 'options.critical' )['rest']['callback'];
	}

	private function request( array $params ) {
		return new class( $params ) {
			/** @var array<string,mixed> */
			private $params;
			public function __construct( array $params ) {
				$this->params = $params;
			}
			public function get_params(): array {
				return $this->params;
			}
		};
	}

	public function test_gates_siteurl_via_rest_alias_url(): void {
		$this->assertTrue( call_user_func( $this->rest_callback(), $this->request( array( 'url' => 'https://evil.test/' ) ) ) );
	}

	public function test_gates_admin_email_via_rest_alias_email(): void {
		$this->assertTrue( call_user_func( $this->rest_callback(), $this->request( array( 'email' => 'attacker@evil.test' ) ) ) );
	}

	public function test_still_gates_raw_option_names(): void {
		$this->assertTrue( call_user_func( $this->rest_callback(), $this->request( array( 'siteurl' => 'https://evil.test/' ) ) ) );
	}

	public function test_ignores_non_critical_settings(): void {
		$this->assertFalse( call_user_func( $this->rest_callback(), $this->request( array( 'title' => 'My Blog', 'description' => 'Just another site' ) ) ) );
	}

	/**
	 * The REST aliases must honor wp_sudo_critical_options: narrowing the filter to
	 * drop siteurl must ungate its REST alias `url` too (parity with the admin path).
	 */
	public function test_rest_aliases_respect_the_critical_options_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return 'wp_sudo_critical_options' === $tag ? array( 'admin_email' ) : $value;
			}
		);
		$cb = $this->rest_callback();
		// siteurl dropped from the filter → its alias `url` no longer gates.
		$this->assertFalse( call_user_func( $cb, $this->request( array( 'url' => 'https://evil.test/' ) ) ) );
		// admin_email retained → its alias `email` still gates.
		$this->assertTrue( call_user_func( $cb, $this->request( array( 'email' => 'attacker@evil.test' ) ) ) );
	}
}
