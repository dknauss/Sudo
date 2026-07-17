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
						if ( 'ids' !== ( $args['fields'] ?? '' ) || -1 !== ( $args['number'] ?? 0 ) ) {
							return false;
						}
						$meta_query = $args['meta_query'][0] ?? array();
						return Sudo_Session::META_KEY === ( $meta_query['key'] ?? '' )
							&& '>' === ( $meta_query['compare'] ?? '' )
							&& 'NUMERIC' === ( $meta_query['type'] ?? '' )
							&& is_int( $meta_query['value'] ?? null );
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

	// ---- manifest (#179) ----

	/**
	 * Mock the WordPress calls Role_Audit::collect_current_state() makes so the
	 * CLI manifest command runs deterministically without a database.
	 *
	 * @param int[] $admins     Administrator IDs to report as current.
	 * @param int[] $governance Governance-cap holder IDs to report as current.
	 * @return void
	 */
	private function stub_collect_state( array $admins, array $governance = array() ): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_users' )->alias(
			static function ( array $query ) use ( $admins, $governance ) {
				if ( isset( $query['capability'] ) ) {
					return $governance;
				}
				return $admins;
			}
		);
		$roles        = new \stdClass();
		$roles->roles = array( 'administrator' => array( 'capabilities' => array( 'read' => true, 'manage_options' => true ) ) );
		Functions\when( 'wp_roles' )->justReturn( $roles );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0 ) {
				return json_encode( $data, (int) $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
	}

	public function test_manifest_unknown_action_errors(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( "Unknown manifest action 'bogus'" );

		( new CLI_Command() )->manifest( array( 'bogus' ), array( 'path' => '/tmp/x.json' ) );
	}

	public function test_manifest_missing_path_errors(): void {
		$this->assertFalse( defined( 'WP_SUDO_ROLE_MANIFEST' ), 'guard: constant must be undefined.' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No manifest path' );

		( new CLI_Command() )->manifest( array( 'generate' ), array() );
	}

	public function test_manifest_generate_writes_parseable_file(): void {
		$this->stub_collect_state( array( 1 ) );
		$path = tempnam( sys_get_temp_dir(), 'wpsudo-cli-manifest-' );

		try {
			( new CLI_Command() )->manifest( array( 'generate' ), array( 'path' => $path ) );

			$this->assertSame( 'success', \WP_CLI::$messages[0]['type'] ?? null );
			$decoded = json_decode( (string) file_get_contents( $path ), true );
			$this->assertSame( 1, $decoded['manifest_version'] );
			$this->assertSame( array( 1 ), $decoded['sites']['1']['administrators'] );
			$this->assertArrayHasKey( 'administrator', $decoded['privileged_roles'] );
		} finally {
			unlink( $path );
		}
	}

	public function test_manifest_diff_reports_clean_when_state_matches(): void {
		$this->stub_collect_state( array( 1 ) );
		$path = tempnam( sys_get_temp_dir(), 'wpsudo-cli-manifest-' );

		try {
			// Generate against the mocked state, then diff the same state → clean.
			( new CLI_Command() )->manifest( array( 'generate' ), array( 'path' => $path ) );
			\WP_CLI::reset();

			( new CLI_Command() )->manifest( array( 'diff' ), array( 'path' => $path ) );

			$this->assertSame( 'success', \WP_CLI::$messages[0]['type'] ?? null );
			$this->assertStringContainsString( 'No role/capability drift', \WP_CLI::$messages[0]['message'] ?? '' );
		} finally {
			unlink( $path );
		}
	}

	public function test_manifest_diff_errors_on_drift(): void {
		// Generate a manifest with NO administrators, then diff against a current
		// state that has an unauthorized administrator (99).
		$this->stub_collect_state( array() );
		$path = tempnam( sys_get_temp_dir(), 'wpsudo-cli-manifest-' );
		( new CLI_Command() )->manifest( array( 'generate' ), array( 'path' => $path ) );
		\WP_CLI::reset();

		$this->stub_collect_state( array( 99 ) );

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'Role/capability drift detected' );

			( new CLI_Command() )->manifest( array( 'diff' ), array( 'path' => $path ) );
		} finally {
			unlink( $path );
		}
	}

	public function test_manifest_diff_errors_on_unreadable_manifest(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Manifest missing or unreadable' );

		( new CLI_Command() )->manifest( array( 'diff' ), array( 'path' => '/no/such/wp-sudo-manifest.json' ) );
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
