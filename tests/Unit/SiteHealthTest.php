<?php
/**
 * Tests for Site_Health.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Site_Health;
use WP_Sudo\Gate;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

/**
 * @covers \WP_Sudo\Site_Health
 */
class SiteHealthTest extends TestCase {

	/**
	 * Instance under test.
	 *
	 * @var Site_Health
	 */
	private Site_Health $health;

	protected function setUp(): void {
		parent::setUp();
		$this->health = new Site_Health();
	}

	// ── register() ───────────────────────────────────────────────────

	public function test_register_adds_filter(): void {
		Filters\expectAdded( 'site_status_tests' )
			->once()
			->with( array( $this->health, 'register_tests' ), \Mockery::any() );

		$this->health->register();
	}

	// ── register_tests() ─────────────────────────────────────────────

	public function test_register_tests_adds_five_tests(): void {
		Functions\when( '__' )->returnArg();

		$tests = array( 'direct' => array(), 'async' => array() );
		$result = $this->health->register_tests( $tests );

		$this->assertArrayHasKey( 'wp_sudo_mu_plugin', $result['direct'] );
		$this->assertArrayHasKey( 'wp_sudo_policies', $result['direct'] );
		$this->assertArrayHasKey( 'wp_sudo_stale_sessions', $result['direct'] );
		$this->assertArrayHasKey( 'wp_sudo_gated_action_integrity', $result['direct'] );
		$this->assertArrayHasKey( 'wp_sudo_recovery_mode', $result['direct'] );
	}

	public function test_register_tests_preserves_existing(): void {
		Functions\when( '__' )->returnArg();

		$tests = array(
			'direct' => array( 'existing_test' => array( 'label' => 'Existing' ) ),
			'async'  => array(),
		);
		$result = $this->health->register_tests( $tests );

		$this->assertArrayHasKey( 'existing_test', $result['direct'] );
		// 1 pre-existing + 6 WP Sudo tests (wp_sudo_replay_binding added in #322).
		$this->assertCount( 7, $result['direct'] );
		$this->assertArrayHasKey( 'wp_sudo_replay_binding', $result['direct'] );
	}

	// ── test_role_manifest() (#179) ──────────────────────────────────

	public function test_register_tests_omits_manifest_when_feature_disabled(): void {
		Functions\when( '__' )->returnArg();
		$this->assertFalse( defined( 'WP_SUDO_ROLE_MANIFEST' ), 'guard: constant must be undefined.' );

		$result = $this->health->register_tests( array( 'direct' => array(), 'async' => array() ) );

		$this->assertArrayNotHasKey( 'wp_sudo_role_manifest', $result['direct'] );
	}

	public function test_role_manifest_reports_unreadable_when_no_manifest(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		$this->assertFalse( defined( 'WP_SUDO_ROLE_MANIFEST' ), 'guard: constant must be undefined so load() returns null.' );

		$result = $this->health->test_role_manifest();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'unreadable', $result['label'] );
		$this->assertSame( 'wp_sudo_role_manifest', $result['test'] );
	}

	public function test_format_role_manifest_result_clean_is_good(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$result = $this->invoke_format( 'clean', array() );

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_format_role_manifest_result_drift_is_critical_with_counts(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		$report = array(
			'has_drift' => true,
			'sites'     => array( 1 => array( 'administrators' => array( 99 ), 'governance' => array( 5 ) ) ),
			'network'   => array( 'super_admins' => array( 42 ) ),
			'roles'     => array( 'administrator' => array( 'expected' => 'a', 'actual' => 'b' ) ),
		);

		$result = $this->invoke_format( 'drift', $report );

		$this->assertSame( 'critical', $result['status'] );
		// 3 unauthorized principals, 1 changed role definition.
		$this->assertStringContainsString( '3', $result['description'] );
		$this->assertStringContainsString( '1', $result['description'] );
	}

	/**
	 * Invoke the private format_role_manifest_result() via reflection.
	 *
	 * @param string               $kind   Outcome kind.
	 * @param array<string, mixed> $report Drift report.
	 * @return array<string, mixed>
	 */
	private function invoke_format( string $kind, array $report ): array {
		$method = new \ReflectionMethod( Site_Health::class, 'format_role_manifest_result' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $method->invoke( $this->health, $kind, $report );
	}

	// ── test_mu_plugin_status() ──────────────────────────────────────

	public function test_mu_plugin_not_installed_returns_recommended_with_settings_link(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );

		// Force the not-installed branch regardless of whether an earlier
		// test defined the process-global WP_SUDO_MU_LOADED constant.
		\Patchwork\redefine(
			'defined',
			function ( string $constant_name ): bool {
				return 'WP_SUDO_MU_LOADED' === $constant_name ? false : \Patchwork\relay();
			}
		);

		$result = $this->health->test_mu_plugin_status();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'wp-sudo-settings', $result['actions'] );
	}

	public function test_mu_plugin_installed_returns_good(): void {
		Functions\when( '__' )->returnArg();

		// Simulate WP_SUDO_MU_LOADED being defined. Redefining defined()
		// instead of calling define() keeps the constant out of the
		// process-global namespace so later tests are not contaminated.
		\Patchwork\redefine(
			'defined',
			function ( string $constant_name ): bool {
				return 'WP_SUDO_MU_LOADED' === $constant_name ? true : \Patchwork\relay();
			}
		);

		$result = $this->health->test_mu_plugin_status();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_mu_plugin', $result['test'] );
	}

	// ── test_policy_review() ─────────────────────────────────────────

	public function test_policy_review_returns_good_when_all_limited(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_LIMITED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_LIMITED,
			)
		);

		$result = $this->health->test_policy_review();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_policies', $result['test'] );
	}

	public function test_policy_review_returns_good_when_all_disabled(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_DISABLED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_DISABLED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_DISABLED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_DISABLED,
			)
		);

		$result = $this->health->test_policy_review();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_policies', $result['test'] );
	}

	public function test_policy_review_returns_recommended_when_some_unrestricted(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_option' )->justReturn(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_UNRESTRICTED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_LIMITED,
			)
		);

		$result = $this->health->test_policy_review();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'wp_sudo_policies', $result['test'] );
	}

	public function test_policy_review_includes_wpgraphql_when_active(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_option' )->justReturn(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_LIMITED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_LIMITED,
				Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_UNRESTRICTED,
			)
		);
		// Set up function_exists mock last — setting it up before other
		// Functions\when() calls causes Brain\Monkey to use the stub when
		// checking whether functions are already declared.
		Functions\when( 'function_exists' )->alias( fn( string $n ): bool => 'graphql' === $n );

		$result = $this->health->test_policy_review();

		// WPGraphQL is active and unrestricted — should be flagged.
		$this->assertSame( 'recommended', $result['status'] );
	}

	public function test_policy_review_excludes_wpgraphql_when_inactive(): void {
		// function_exists('graphql') returns false naturally in the unit test environment.
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_LIMITED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_LIMITED,
				Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_UNRESTRICTED,
			)
		);

		$result = $this->health->test_policy_review();

		// WPGraphQL is not active — unrestricted setting must NOT trigger a warning.
		$this->assertSame( 'good', $result['status'] );
	}

	// ── test_gated_action_integrity() ────────────────────────────────

	public function test_gated_action_integrity_returns_good_when_builtins_present(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$result = $this->health->test_gated_action_integrity();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_gated_action_integrity', $result['test'] );
	}

	public function test_gated_action_integrity_warns_when_builtins_missing(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wp_sudo_gated_actions' !== $hook ) {
					return $value;
				}

				return array_values(
					array_filter(
						$value,
						static function ( array $rule ): bool {
							return ( $rule['id'] ?? '' ) !== 'plugin.activate';
						}
					)
				);
			}
		);

		$result = $this->health->test_gated_action_integrity();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'wp_sudo_gated_action_integrity', $result['test'] );
		$this->assertStringContainsString( 'plugin.activate', $result['description'] );
	}

	// ── test_stale_sessions() ────────────────────────────────────────

	public function test_stale_sessions_returns_good_when_none(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_users' )->justReturn( array() );

		$result = $this->health->test_stale_sessions();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_stale_sessions', $result['test'] );
	}

	public function test_stale_sessions_cleans_expired_tokens(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->returnArg();
		$expired_time = time() - 60;

		Functions\when( 'get_users' )->justReturn( array( 10, 20 ) );
		Functions\when( 'get_user_meta' )->justReturn( $expired_time );

		// Expect delete_user_meta for each stale user: META_KEY + PROOF_META_KEY + TOKEN_META_KEY + SESSION_BIND_META_KEY = 4 per user = 8 total.
		Functions\expect( 'delete_user_meta' )
			->times( 8 );

		$result = $this->health->test_stale_sessions();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_stale_sessions', $result['test'] );
	}

	public function test_stale_sessions_skips_active_sessions(): void {
		Functions\when( '__' )->returnArg();
		$future_time = time() + 300;

		Functions\when( 'get_users' )->justReturn( array( 10 ) );
		Functions\when( 'get_user_meta' )->justReturn( $future_time );

		// No cleanups should happen for active sessions.
		Functions\expect( 'delete_user_meta' )->never();

		$result = $this->health->test_stale_sessions();

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_stale_sessions_paginates_beyond_100_users(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->returnArg();

		$expired_time = time() - 60;

		// First batch: 100 user IDs (triggers next page). Second batch: 5 user IDs (stops loop).
		$first_batch  = range( 1, 100 );
		$second_batch = range( 101, 105 );

		Functions\expect( 'get_users' )
			->twice()
			->andReturnValues( array( $first_batch, $second_batch ) );

		Functions\when( 'get_user_meta' )->justReturn( $expired_time );

		// 105 stale users × 4 meta keys each (META_KEY + PROOF_META_KEY + TOKEN_META_KEY + SESSION_BIND_META_KEY) = 420 deletions.
		Functions\expect( 'delete_user_meta' )
			->times( 420 );

		$result = $this->health->test_stale_sessions();

		$this->assertSame( 'good', $result['status'] );
	}

	// ── test_replay_binding() (#322) ─────────────────────────────────

	/**
	 * #322: HTTPS sites can bind a resumed action to the browser, so replay works.
	 */
	public function test_replay_binding_good_when_cookies_are_secure(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'force_ssl_admin' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$result = $this->health->test_replay_binding();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_replay_binding', $result['test'] );
	}

	/**
	 * #322: without HTTPS the __Host- binding cookie cannot be set, so replay never
	 * engages and the user must repeat the action. Surfaced so the degradation is
	 * visible rather than looking like a bug.
	 */
	public function test_replay_binding_recommends_https_when_not_secure(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'force_ssl_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$result = $this->health->test_replay_binding();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'HTTPS', $result['description'] );
	}

	// ── test_recovery_mode() (#240) ──────────────────────────────────

	public function test_recovery_mode_good_when_inactive(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$result = $this->health->test_recovery_mode();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'wp_sudo_recovery_mode', $result['test'] );
	}

	public function test_recovery_mode_critical_when_unscoped(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( true );

		$result = $this->health->test_recovery_mode();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'wp_sudo_recovery_mode', $result['test'] );
		// Unscoped names the any-administrator blast radius.
		$this->assertStringContainsString( 'administrator', $result['description'] );
	}

	public function test_recovery_mode_critical_when_scoped_resolved(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( false );
		Functions\when( 'wp_sudo_recovery_mode_user' )->justReturn( 12 );

		$user             = new \stdClass();
		$user->user_login = 'jane';
		Functions\when( 'get_userdata' )->justReturn( $user );

		$result = $this->health->test_recovery_mode();

		$this->assertSame( 'critical', $result['status'] );
		// The resolved target is named so the operator can verify scope.
		$this->assertStringContainsString( 'jane', $result['description'] );
	}

	public function test_recovery_mode_critical_when_scoped_unresolvable(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'wp_sudo_recovery_mode_is_unscoped' )->justReturn( false );
		Functions\when( 'wp_sudo_recovery_mode_user' )->justReturn( null );

		$result = $this->health->test_recovery_mode();

		$this->assertSame( 'critical', $result['status'] );
		// An unresolvable target grants nobody — the operator is told to check
		// for a typo rather than left with a silent no-op.
		$this->assertStringContainsString( 'no one', strtolower( $result['description'] ) );
	}
}
