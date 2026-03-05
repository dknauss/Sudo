<?php
/**
 * Integration tests for WPGraphQL surface gating.
 *
 * Tests Gate::check_wpgraphql() against a real WordPress + MySQL environment,
 * covering all three policy modes and session bypass. WPGraphQL does not need
 * to be installed — the Gate is called directly with a raw body string.
 *
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

/**
 * Class WpGraphQLGatingTest
 */
class WpGraphQLGatingTest extends TestCase {

	private Gate $gate;

	private const MUTATION_BODY = '{"query":"mutation { deleteUser(input:{id:\"1\"}) { deletedId } }"}';
	private const QUERY_BODY    = '{"query":"{ posts { nodes { id title } } }"}';
	private const PERSISTED_MUTATION_BODY = '{"extensions":{"persistedQuery":{"sha256Hash":"persisted-mutation-hash","version":1}}}';
	private const PERSISTED_QUERY_BODY    = '{"extensions":{"persistedQuery":{"sha256Hash":"persisted-query-hash","version":1}}}';

	public function set_up(): void {
		parent::set_up();
		$this->gate = new Gate( new Sudo_Session(), new Request_Stash() );
	}

	// ── Helpers ────────────────────────────────────────────────────────

	/**
	 * Persist a specific wpgraphql_policy value in the settings option.
	 */
	private function set_policy( string $policy ): void {
		$settings = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ Gate::SETTING_WPGRAPHQL_POLICY ] = $policy;
		$this->update_wp_sudo_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();
	}

	// ── Limited policy (default) ───────────────────────────────────────

	/** @test */
	public function test_authenticated_mutation_blocked_when_limited_and_no_session(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** @test */
	public function test_authenticated_query_passes_when_limited_and_no_session(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$result = $this->gate->check_wpgraphql( self::QUERY_BODY );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_authenticated_mutation_passes_when_limited_and_session_active(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		Sudo_Session::activate( $user->ID );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_limited_mutation_block_fires_audit_hook(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$blocked_args = array();
		add_action(
			'wp_sudo_action_blocked',
			static function ( $uid, $rule_id, $surface ) use ( &$blocked_args ) {
				$blocked_args = array( $uid, $rule_id, $surface );
			},
			10,
			3
		);

		$this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertSame( $user->ID, $blocked_args[0] ?? null );
		$this->assertSame( 'wpgraphql', $blocked_args[1] ?? null );
		$this->assertSame( 'wpgraphql', $blocked_args[2] ?? null );
	}

	/** @test */
	public function test_unauthenticated_mutation_blocked_when_limited(): void {
		// Since v2.5.2, unauthenticated mutations are blocked in Limited mode.
		// get_current_user_id() returns 0, the sudo session check is skipped,
		// and the mutation falls through to the sudo_blocked response.
		wp_set_current_user( 0 );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );
	}

	/** @test */
	public function test_unauthenticated_query_passes_when_limited(): void {
		wp_set_current_user( 0 );

		$result = $this->gate->check_wpgraphql( self::QUERY_BODY );

		$this->assertNull( $result );
	}

	// ── Disabled policy ────────────────────────────────────────────────

	/** @test */
	public function test_disabled_policy_blocks_query(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->set_policy( Gate::POLICY_DISABLED );

		$result = $this->gate->check_wpgraphql( self::QUERY_BODY );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_disabled', $result->get_error_code() );
	}

	/** @test */
	public function test_disabled_policy_blocks_mutation(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->set_policy( Gate::POLICY_DISABLED );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_disabled', $result->get_error_code() );
	}

	/** @test */
	public function test_disabled_policy_does_not_fire_audit_hook(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->set_policy( Gate::POLICY_DISABLED );

		$hook_fired = false;
		add_action(
			'wp_sudo_action_blocked',
			static function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertFalse( $hook_fired );
	}

	// ── Unrestricted policy ────────────────────────────────────────────

	/** @test */
	public function test_unrestricted_policy_passes_mutation(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->set_policy( Gate::POLICY_UNRESTRICTED );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertNull( $result );
	}

	/** @test */
	public function test_unrestricted_policy_passes_query(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->set_policy( Gate::POLICY_UNRESTRICTED );

		$result = $this->gate->check_wpgraphql( self::QUERY_BODY );

		$this->assertNull( $result );
	}

	// ── Settings persistence ───────────────────────────────────────────

	/** @test */
	public function test_wpgraphql_policy_setting_persists(): void {
		$this->set_policy( Gate::POLICY_UNRESTRICTED );

		$stored = $this->get_wp_sudo_option( Admin::OPTION_KEY, array() );

		$this->assertIsArray( $stored );
		$this->assertSame(
			Gate::POLICY_UNRESTRICTED,
			$stored[ Gate::SETTING_WPGRAPHQL_POLICY ] ?? null
		);
	}

	/** @test */
	public function test_wpgraphql_policy_defaults_to_limited_when_not_set(): void {
		// No explicit policy stored — Admin::get() should return the limited default.
		$policy = Admin::get( Gate::SETTING_WPGRAPHQL_POLICY, Gate::POLICY_LIMITED );

		$this->assertSame( Gate::POLICY_LIMITED, $policy );
	}

	// ── Bypass filter ─────────────────────────────────────────────────

	/** @test */
	public function test_bypass_filter_exempts_mutation_in_limited_mode(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		// No sudo session — would normally be blocked.

		add_filter( 'wp_sudo_wpgraphql_bypass', '__return_true' );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertNull( $result );

		remove_filter( 'wp_sudo_wpgraphql_bypass', '__return_true' );
	}

	/** @test */
	public function test_bypass_filter_does_not_affect_disabled_mode(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );
		$this->set_policy( Gate::POLICY_DISABLED );

		add_filter( 'wp_sudo_wpgraphql_bypass', '__return_true' );

		$result = $this->gate->check_wpgraphql( self::MUTATION_BODY );

		// Disabled policy returns before the filter fires — still blocked.
		$this->assertWPError( $result );
		$this->assertSame( 'sudo_disabled', $result->get_error_code() );

		remove_filter( 'wp_sudo_wpgraphql_bypass', '__return_true' );
	}

	/** @test */
	public function test_bypass_filter_receives_correct_body(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$captured_body = null;
		$callback      = static function ( bool $bypass, string $body ) use ( &$captured_body ): bool {
			$captured_body = $body;
			return true; // bypass so test doesn't need a session.
		};

		add_filter( 'wp_sudo_wpgraphql_bypass', $callback, 10, 2 );

		$this->gate->check_wpgraphql( self::MUTATION_BODY );

		$this->assertSame( self::MUTATION_BODY, $captured_body );

		remove_filter( 'wp_sudo_wpgraphql_bypass', $callback );
	}

	// ── Persisted-query classification filter ─────────────────────────

	/** @test */
	public function test_limited_persisted_mutation_blocked_when_classifier_marks_mutation(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$classifier = static function ( string $classification, string $body ): string {
			if ( str_contains( $body, 'persisted-mutation-hash' ) ) {
				return 'mutation';
			}
			return $classification;
		};

		add_filter( 'wp_sudo_wpgraphql_classification', $classifier, 10, 2 );

		$result = $this->gate->check_wpgraphql( self::PERSISTED_MUTATION_BODY );

		$this->assertWPError( $result );
		$this->assertSame( 'sudo_blocked', $result->get_error_code() );

		remove_filter( 'wp_sudo_wpgraphql_classification', $classifier );
	}

	/** @test */
	public function test_limited_persisted_query_passes_when_classifier_marks_query(): void {
		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		$classifier = static function ( string $classification, string $body ): string {
			if ( str_contains( $body, 'persisted-query-hash' ) ) {
				return 'query';
			}
			return $classification;
		};

		add_filter( 'wp_sudo_wpgraphql_classification', $classifier, 10, 2 );

		$result = $this->gate->check_wpgraphql( self::PERSISTED_QUERY_BODY );

		$this->assertNull( $result );

		remove_filter( 'wp_sudo_wpgraphql_classification', $classifier );
	}
}
