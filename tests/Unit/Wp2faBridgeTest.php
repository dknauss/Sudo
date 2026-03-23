<?php
/**
 * Tests for the WP 2FA bridge.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * @coversNothing Procedural bridge file.
 */
class Wp2faBridgeTest extends TestCase {

	/**
	 * Test bridge leaves detection unchanged when WP 2FA is inactive.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detection_preserves_existing_state_when_wp2fa_inactive(): void {
		\Brain\Monkey\setUp();

		$callbacks = $this->capture_bridge_hooks();
		$this->assertArrayHasKey( 'wp_sudo_requires_two_factor', $callbacks['filters'] );

		$callback = $callbacks['filters']['wp_sudo_requires_two_factor'];

		$this->assertFalse( $callback( false, 42 ) );
		$this->assertTrue( $callback( true, 42 ) );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test detection delegates to WP 2FA's user helper when active.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detection_reads_wp2fa_user_helper_state(): void {
		\Brain\Monkey\setUp();
		$this->define_wp2fa_stubs();
		\WP2FA\Admin\Helpers\User_Helper::$using_two_factor = true;

		$callbacks = $this->capture_bridge_hooks();
		$callback  = $callbacks['filters']['wp_sudo_requires_two_factor'];

		$this->assertTrue( $callback( false, 42 ) );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test TOTP users get the primary authcode field.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_outputs_totp_field_for_totp_users(): void {
		\Brain\Monkey\setUp();
		$this->define_wp2fa_stubs();
		\WP2FA\Admin\Helpers\User_Helper::$method = 'totp';

		Functions\when( 'get_user_meta' )->justReturn( '' );

		$callbacks = $this->capture_bridge_hooks();
		$callback  = $callbacks['actions']['wp_sudo_render_two_factor_fields'];
		$user      = new \WP_User();
		$user->ID  = 42;

		ob_start();
		$callback( $user );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="wp2fa_authcode"', $output );
		$this->assertStringContainsString( 'wp-sudo-wp2fa-code', $output );
		$this->assertStringNotContainsString( 'name="wp2fa_backup_code"', $output );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test email users trigger OTP generation and see backup-code fallback.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_email_method_sends_token_and_shows_backup_codes(): void {
		\Brain\Monkey\setUp();
		$this->define_wp2fa_stubs();
		\WP2FA\Admin\Helpers\User_Helper::$method = 'email';

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single ) {
				if ( 'wp_2fa_backup_codes' === $key ) {
					return array( 'backup-123' );
				}
				return '';
			}
		);

		$callbacks = $this->capture_bridge_hooks();
		$callback  = $callbacks['actions']['wp_sudo_render_two_factor_fields'];
		$user      = new \WP_User();
		$user->ID  = 42;

		ob_start();
		$callback( $user );
		$output = (string) ob_get_clean();

		$this->assertSame( array( 42 ), \WP2FA\Authenticator\Authentication::$generated_tokens );
		$this->assertStringContainsString( 'name="wp2fa_authcode"', $output );
		$this->assertStringContainsString( 'name="wp2fa_backup_code"', $output );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Test valid TOTP codes pass bridge validation.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_validate_accepts_valid_totp_code(): void {
		\Brain\Monkey\setUp();
		$this->define_wp2fa_stubs();
		\WP2FA\Admin\Helpers\User_Helper::$method                = 'totp';
		\WP2FA\Authenticator\Authentication::$totp_should_pass = true;

		$_POST['wp2fa_authcode'] = '123456';

		$callbacks = $this->capture_bridge_hooks();
		$callback  = $callbacks['filters']['wp_sudo_validate_two_factor'];
		$user      = new \WP_User();
		$user->ID  = 42;

		$this->assertTrue( $callback( false, $user ) );

		unset( $_POST['wp2fa_authcode'] );
		\Brain\Monkey\tearDown();
	}

	/**
	 * Test backup-code fallback succeeds when the primary method does not.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_validate_accepts_backup_code_fallback(): void {
		\Brain\Monkey\setUp();
		$this->define_wp2fa_stubs();
		\WP2FA\Admin\Helpers\User_Helper::$method                  = 'email';
		\WP2FA\Authenticator\Authentication::$email_should_pass  = false;
		\WP2FA\Methods\Backup_Codes::$backup_should_pass         = true;

		$_POST['wp2fa_authcode']    = '000000';
		$_POST['wp2fa_backup_code'] = 'backup-123';

		$callbacks = $this->capture_bridge_hooks();
		$callback  = $callbacks['filters']['wp_sudo_validate_two_factor'];
		$user      = new \WP_User();
		$user->ID  = 42;

		$this->assertTrue( $callback( false, $user ) );

		unset( $_POST['wp2fa_authcode'], $_POST['wp2fa_backup_code'] );
		\Brain\Monkey\tearDown();
	}

	/**
	 * Load the bridge and capture its filters/actions.
	 *
	 * @return array<string, array<string, callable>>
	 */
	private function capture_bridge_hooks(): array {
		$callbacks = array(
			'filters' => array(),
			'actions' => array(),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $text ): void {
				echo $text;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks['filters'][ $hook ] = $callback;
				return true;
			}
		);

		Functions\when( 'add_action' )->alias(
			static function ( string $hook, callable $callback ) use ( &$callbacks ): bool {
				$callbacks['actions'][ $hook ] = $callback;
				return true;
			}
		);

		require __DIR__ . '/../../bridges/wp-sudo-wp2fa-bridge.php';

		return $callbacks;
	}

	/**
	 * Define lightweight WP 2FA stubs for bridge testing.
	 *
	 * @return void
	 */
	private function define_wp2fa_stubs(): void {
		if ( ! class_exists( '\WP2FA\Admin\Helpers\User_Helper', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval(
				'namespace WP2FA\Admin\Helpers; class User_Helper { public static bool $using_two_factor = false; public static string $method = ""; public static function is_user_using_two_factor( int $user_id ): bool { return self::$using_two_factor; } public static function get_enabled_method_for_user( \WP_User $user ): string { return self::$method; } }'
			);
		}

		if ( ! class_exists( '\WP2FA\Authenticator\Authentication', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval(
				'namespace WP2FA\Authenticator; class Authentication { public static array $generated_tokens = []; public static bool $totp_should_pass = false; public static bool $email_should_pass = false; public static function generate_token( int $user_id ): void { self::$generated_tokens[] = $user_id; } public static function is_valid_authcode( string $key, string $code ): bool { return self::$totp_should_pass && "totp-secret" === $key && "123456" === $code; } public static function validate_token( \WP_User $user, string $code ): bool { return self::$email_should_pass && "654321" === $code; } }'
			);
		}

		if ( ! class_exists( '\WP2FA\Methods\TOTP', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval(
				'namespace WP2FA\Methods; class TOTP { public static function get_totp_key( \WP_User $user ): string { return "totp-secret"; } }'
			);
		}

		if ( ! class_exists( '\WP2FA\Methods\Backup_Codes', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval(
				'namespace WP2FA\Methods; class Backup_Codes { public static bool $backup_should_pass = false; public static function validate_code( \WP_User $user, string $code ): bool { return self::$backup_should_pass && "backup-123" === $code; } }'
			);
		}

		\WP2FA\Admin\Helpers\User_Helper::$using_two_factor             = false;
		\WP2FA\Admin\Helpers\User_Helper::$method                       = '';
		\WP2FA\Authenticator\Authentication::$generated_tokens          = array();
		\WP2FA\Authenticator\Authentication::$totp_should_pass         = false;
		\WP2FA\Authenticator\Authentication::$email_should_pass        = false;
		\WP2FA\Methods\Backup_Codes::$backup_should_pass               = false;
	}
}
