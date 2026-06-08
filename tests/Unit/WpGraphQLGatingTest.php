<?php
/**
 * Unit tests for WPGraphQL surface gating.
 *
 * Tests the Gate class's gate_wpgraphql() method and policy
 * enforcement (Disabled / Limited / Unrestricted) without a database.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;

/**
 * Class WpGraphQLGatingTest
 */
class WpGraphQLGatingTest extends TestCase {

	private Gate $gate;

	protected function setUp(): void {
		parent::setUp();

		$session    = \Mockery::mock( Sudo_Session::class );
		$stash      = \Mockery::mock( Request_Stash::class );
		$this->gate = new Gate( $session, $stash );

		// Standard stubs shared across all tests.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_GET['query'], $_GET['extensions'] );
		parent::tearDown();
	}

	// ── Helpers ────────────────────────────────────────────────────────

	/**
	 * Stub get_option() to return a wp_sudo_settings array with the given policy.
	 */
	private function with_policy( string $policy ): void {
		Functions\when( 'get_option' )->justReturn(
			array( Gate::SETTING_WPGRAPHQL_POLICY => $policy )
		);
	}

	/**
	 * Stub file_get_contents('php://input') via Patchwork to return the given body.
	 */
	private function with_body( string $body ): void {
		\Patchwork\redefine(
			'file_get_contents',
			function ( string $filename ) use ( $body ): string|false {
				return 'php://input' === $filename ? $body : \Patchwork\relay();
			}
		);
	}

	// ── Policy: Unrestricted ───────────────────────────────────────────

	/** @test */
	public function test_unrestricted_policy_passes_mutation(): void {
		$this->with_policy( Gate::POLICY_UNRESTRICTED );
		$this->with_body( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );

		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\expect( 'wp_send_json' )->never();

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_unrestricted_policy_passes_query(): void {
		$this->with_policy( Gate::POLICY_UNRESTRICTED );
		$this->with_body( '{"query":"{ posts { nodes { id title } } }"}' );

		Functions\expect( 'wp_send_json' )->never();

		$this->gate->gate_wpgraphql();
	}

	// ── Policy: Disabled ───────────────────────────────────────────────

	/** @test */
	public function test_disabled_policy_blocks_query(): void {
		$this->with_policy( Gate::POLICY_DISABLED );
		$this->with_body( '{"query":"{ posts { nodes { id } } }"}' );

		Functions\expect( 'wp_send_json' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $data ): bool {
						return 'sudo_disabled' === $data['code'];
					}
				),
				403
			);

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_disabled_policy_blocks_mutation(): void {
		$this->with_policy( Gate::POLICY_DISABLED );
		$this->with_body( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );

		Functions\expect( 'wp_send_json' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $data ): bool {
						return 'sudo_disabled' === $data['code'];
					}
				),
				403
			);

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_disabled_policy_does_not_fire_blocked_hook(): void {
		$this->with_policy( Gate::POLICY_DISABLED );
		$this->with_body( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );

		Functions\expect( 'wp_send_json' )->once(); // consumed to allow the call.

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();

		$this->gate->gate_wpgraphql();
	}

	// ── Policy: Limited (default) ──────────────────────────────────────

	/** @test */
	public function test_limited_blocks_mutation_no_session_fires_hook(): void {
		Functions\when( 'get_option' )->justReturn( array() ); // limited is the default
		$this->with_body( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( false ); // no active session

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'wpgraphql', 'wpgraphql' );

		Functions\expect( 'wp_send_json' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $data ): bool {
						return 'sudo_blocked' === $data['code']
							&& 403 === $data['data']['status'];
					}
				),
				403
			);

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_limited_passes_query_no_session(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->with_body( '{"query":"{ users { nodes { id name } } }"}' );

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();
		Functions\expect( 'wp_send_json' )->never();

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_limited_passes_empty_body(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->with_body( '' );

		Functions\expect( 'wp_send_json' )->never();

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_limited_blocks_get_query_mutation_with_empty_body(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->with_body( '' );
		$_GET['query'] = 'mutation { deleteUser(input:{id:"1"}) { deletedId } }';
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( false );

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'wpgraphql', 'wpgraphql' );

		Functions\expect( 'wp_send_json' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $data ): bool {
						return 'sudo_blocked' === $data['code']
							&& 403 === $data['data']['status'];
					}
				),
				403
			);

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_limited_passes_get_query_read_operation_with_empty_body(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->with_body( '' );
		$_GET['query'] = 'query { posts { nodes { id } } }';

		Actions\expectDone( 'wp_sudo_action_blocked' )->never();
		Functions\expect( 'wp_send_json' )->never();

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_limited_blocks_get_persisted_operation_with_empty_body(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->with_body( '' );
		$_GET['extensions'] = '{"persistedQuery":{"sha256Hash":"abc123","version":1}}';
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( false );

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 1, 'wpgraphql', 'wpgraphql' );

		Functions\expect( 'wp_send_json' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $data ): bool {
						return 'sudo_blocked' === $data['code']
							&& 403 === $data['data']['status'];
					}
				),
				403
			);

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_limited_blocks_unauthenticated_mutation(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->with_body( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		Functions\when( 'get_current_user_id' )->justReturn( 0 ); // unauthenticated

		// No get_user_meta stub needed — is_active() is never called (short-circuit on $user_id=0).

		Actions\expectDone( 'wp_sudo_action_blocked' )
			->once()
			->with( 0, 'wpgraphql', 'wpgraphql' );

		Functions\expect( 'wp_send_json' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $data ): bool {
						return 'sudo_blocked' === $data['code']
							&& 403 === $data['data']['status'];
					}
				),
				403
			);

		$this->gate->gate_wpgraphql();
	}

	/** @test */
	public function test_limited_passes_mutation_with_active_session(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->with_body( '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		// Simulate Sudo_Session::is_active( 1 ) === true.
		$token = 'test-graphql-gate-token';
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key ) use ( $token ): mixed {
				if ( Sudo_Session::META_KEY === $key ) {
					return time() + 600;
				}
				if ( Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $token );
				}
				return '';
			}
		);
		Functions\when( 'hash_equals' )->alias(
			static function ( string $a, string $b ): bool {
				return $a === $b;
			}
		);
		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $token;

		Functions\expect( 'wp_send_json' )->never();

		$this->gate->gate_wpgraphql();
	}

	// ── Bypass filter ─────────────────────────────────────────────────

	private const MUTATION_BODY = '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}';
	private const QUERY_BODY    = '{"query":"{ posts { nodes { id title } } }"}';

	/** @test */
	public function test_limited_bypass_filter_passes_mutation_when_true(): void {
		Functions\when( 'get_option' )->justReturn( array() ); // limited default
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( false ); // no session

		Filters\expectApplied( 'wp_sudo_wpgraphql_bypass' )
			->once()
			->andReturn( true );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_limited_bypass_filter_default_still_blocks_mutation(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( false );

		Filters\expectApplied( 'wp_sudo_wpgraphql_bypass' )
			->once()
			->andReturn( false );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
	}

	/** @test */
	public function test_disabled_does_not_fire_bypass_filter(): void {
		$this->with_policy( Gate::POLICY_DISABLED );

		Filters\expectApplied( 'wp_sudo_wpgraphql_bypass' )->never();

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'sudo_disabled', $result->get_error_code() );
	}

	/** @test */
	public function test_unrestricted_does_not_fire_bypass_filter(): void {
		$this->with_policy( Gate::POLICY_UNRESTRICTED );

		Filters\expectApplied( 'wp_sudo_wpgraphql_bypass' )->never();

		$result = $this->gate->check_wpgraphql( self::QUERY_BODY );

		$this->assertNull( $result );
	}

	// ── wp_sudo_action_allowed (Unrestricted audit) ─────────────────

	/** @test */
	public function test_unrestricted_fires_action_allowed_for_mutation(): void {
		$this->with_policy( Gate::POLICY_UNRESTRICTED );

		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		Actions\expectDone( 'wp_sudo_action_allowed' )
			->once()
			->with( 42, 'wpgraphql', 'wpgraphql' );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_unrestricted_does_not_fire_action_allowed_for_query(): void {
		$this->with_policy( Gate::POLICY_UNRESTRICTED );

		Actions\expectDone( 'wp_sudo_action_allowed' )->never();

		$result = $this->gate->check_wpgraphql( self::QUERY_BODY );

		$this->assertNull( $result );
	}
}
