<?php
/**
 * Tests for WP-CLI sudo commands.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\CLI_Command;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;

/**
 * @covers \WP_Sudo\CLI_Command
 */
class CliCommandTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->ensure_wp_cli_stub();
		\WP_CLI::reset();
	}

	public function test_status_reports_active_session_for_current_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $meta_key, bool $single ) {
				if ( 7 === $user_id && Sudo_Session::META_KEY === $meta_key && true === $single ) {
					return time() + 120;
				}
				return 0;
			}
		);

		$command = new CLI_Command();
		$command->status( array(), array() );

		$this->assertSame( 'success', \WP_CLI::$messages[0]['type'] ?? null );
		$this->assertStringContainsString( 'user 7', \WP_CLI::$messages[0]['message'] ?? '' );
		$this->assertStringContainsString( 'expiry is in the future', \WP_CLI::$messages[0]['message'] ?? '' );
		$this->assertStringContainsString( 'token binding cannot be verified from WP-CLI', \WP_CLI::$messages[0]['message'] ?? '' );
		$this->assertStringNotContainsString( 'is active', \WP_CLI::$messages[0]['message'] ?? '' );
	}

	public function test_status_reports_missing_session_for_current_user(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( 0 );

		$command = new CLI_Command();
		$command->status( array(), array() );

		$this->assertSame( 'log', \WP_CLI::$messages[0]['type'] ?? null );
		$this->assertStringContainsString( 'No active sudo session', \WP_CLI::$messages[0]['message'] ?? '' );
	}

	public function test_revoke_deactivates_explicit_user(): void {
		Functions\when( 'headers_sent' )->justReturn( true );

		Functions\expect( 'delete_user_meta' )
			->times( 3 ) // META_KEY + TOKEN_META_KEY + SESSION_BIND_META_KEY.
			->with( 9, \Mockery::type( 'string' ) );

		Functions\expect( 'do_action' )
			->once()
			->with( 'wp_sudo_deactivated', 9 );

		$command = new CLI_Command();
		$command->revoke( array(), array( 'user' => '9' ) );

		$this->assertSame( 'success', \WP_CLI::$messages[0]['type'] ?? null );
		$this->assertStringContainsString( 'user 9', \WP_CLI::$messages[0]['message'] ?? '' );
	}

	public function test_revoke_all_deactivates_all_users_with_sessions(): void {
		Functions\when( 'headers_sent' )->justReturn( true );

		Functions\expect( 'get_users' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $args ): bool {
						return 'ids' === ( $args['fields'] ?? '' )
							&& Sudo_Session::META_KEY === ( $args['meta_key'] ?? '' )
							&& -1 === ( $args['number'] ?? 0 );
					}
				)
			)
			->andReturn( array( 2, 3 ) );

		Functions\expect( 'delete_user_meta' )
			->times( 6 ) // 2 users × (META_KEY + TOKEN_META_KEY + SESSION_BIND_META_KEY).
			->with( \Mockery::type( 'int' ), \Mockery::type( 'string' ) );

		Functions\expect( 'do_action' )
			->times( 2 )
			->with( 'wp_sudo_deactivated', \Mockery::type( 'int' ) );

		$command = new CLI_Command();
		$command->revoke( array(), array( 'all' => true ) );

		$this->assertSame( 'success', \WP_CLI::$messages[0]['type'] ?? null );
		$this->assertStringContainsString( '2 sudo session', \WP_CLI::$messages[0]['message'] ?? '' );
	}

	public function test_revoke_errors_when_no_target_user_is_available(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No target user' );

		$command = new CLI_Command();
		$command->revoke( array(), array() );
	}

	/**
	 * Define a lightweight WP_CLI stub for command unit tests.
	 *
	 * @return void
	 */
	private function ensure_wp_cli_stub(): void {
		if ( ! class_exists( '\\WP_CLI', false ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			eval( 'namespace { class WP_CLI { public static array $commands = []; public static array $messages = []; public static function add_command( string $name, $callable ): bool { self::$commands[ $name ] = $callable; return true; } public static function success( string $message ): void { self::$messages[] = ["type" => "success", "message" => $message]; } public static function warning( string $message ): void { self::$messages[] = ["type" => "warning", "message" => $message]; } public static function log( string $message ): void { self::$messages[] = ["type" => "log", "message" => $message]; } public static function error( string $message ): void { throw new \\RuntimeException( $message ); } public static function reset(): void { self::$commands = []; self::$messages = []; } } }' );
		}
	}
}
