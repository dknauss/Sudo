<?php
/**
 * Tests for WP_Sudo\Admin (v2).
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Admin;
use WP_Sudo\Gate;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * @covers \WP_Sudo\Admin
 */
class AdminTest extends TestCase {

	/**
	 * Inject a Gate into the Request / Rule Tester's lazy diagnostic slot.
	 *
	 * The diagnostic Gate is built lazily in production; tests substitute a
	 * mock through the private property rather than a test-only constructor.
	 *
	 * @param Admin $admin Admin instance.
	 * @param Gate  $gate  Mock Gate.
	 * @return void
	 */
	private function inject_diagnostic_gate( Admin $admin, Gate $gate ): void {
		$ref = new \ReflectionProperty( Admin::class, 'diagnostic_gate' );
		// setAccessible() is a required no-op on PHP 8.0 and deprecated on 8.5+; suppress the notice.
		@$ref->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ref->setValue( $admin, $gate );
	}

	// -----------------------------------------------------------------
	// defaults()
	// -----------------------------------------------------------------

	public function test_defaults_returns_expected_structure(): void {
		$defaults = Admin::defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'session_duration', $defaults );
		$this->assertSame( 15, $defaults['session_duration'] );
	}

	public function test_defaults_include_all_policy_keys(): void {
		$defaults = Admin::defaults();

		$this->assertArrayHasKey( 'rest_app_password_policy', $defaults );
		$this->assertArrayHasKey( 'cli_policy', $defaults );
		$this->assertArrayHasKey( 'cron_policy', $defaults );
		$this->assertArrayHasKey( 'xmlrpc_policy', $defaults );
		$this->assertArrayHasKey( 'policy_preset', $defaults );
	}

	public function test_defaults_policies_are_limited(): void {
		$defaults = Admin::defaults();

		$this->assertSame( Gate::POLICY_LIMITED, $defaults['rest_app_password_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $defaults['cli_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $defaults['cron_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $defaults['xmlrpc_policy'] );
	}

	public function test_defaults_policy_preset_is_normal(): void {
		$defaults = Admin::defaults();

		$this->assertSame( Admin::POLICY_PRESET_NORMAL, $defaults['policy_preset'] );
	}

	public function test_defaults_no_allowed_roles_key(): void {
		$defaults = Admin::defaults();

		$this->assertArrayNotHasKey( 'allowed_roles', $defaults );
	}

	// -----------------------------------------------------------------
	// get()
	// -----------------------------------------------------------------

	public function test_get_returns_stored_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'session_duration' => 10 ) );

		$this->assertSame( 10, Admin::get( 'session_duration' ) );
	}

	public function test_get_returns_default_for_missing_key(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 15, Admin::get( 'session_duration' ) );
	}

	public function test_get_returns_explicit_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'custom', Admin::get( 'nonexistent_key', 'custom' ) );
	}

	// -----------------------------------------------------------------
	// is_passed_event_logging_enabled()
	// -----------------------------------------------------------------

	public function test_passed_event_logging_enabled_by_default(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertTrue( Admin::is_passed_event_logging_enabled() );
	}

	public function test_passed_event_logging_can_be_disabled_via_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				if ( Admin::PASSED_EVENT_LOGGING_FILTER === $hook ) {
					return false;
				}

				return $value;
			}
		);

		$this->assertFalse( Admin::is_passed_event_logging_enabled() );
	}

	// -----------------------------------------------------------------
	// sanitize_settings()
	// -----------------------------------------------------------------

	public function test_sanitize_clamps_duration_below_range(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 0 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_clamps_duration_above_range(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 30 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_clamps_negative_duration_to_default(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => -5 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_accepts_valid_duration(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 10 ) );

		$this->assertSame( 10, $result['session_duration'] );
	}

	public function test_sanitize_normalizes_valid_policy(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array(
			'session_duration'         => 15,
			'cli_policy'               => 'disabled',
			'cron_policy'              => 'limited',
			'xmlrpc_policy'            => 'unrestricted',
			'rest_app_password_policy' => 'disabled',
		) );

		$this->assertSame( 'disabled', $result['cli_policy'] );
		$this->assertSame( 'limited', $result['cron_policy'] );
		$this->assertSame( 'unrestricted', $result['xmlrpc_policy'] );
		$this->assertSame( 'disabled', $result['rest_app_password_policy'] );
	}

	public function test_sanitize_rejects_invalid_policy_values(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array(
			'session_duration' => 15,
			'cli_policy'       => 'invalid',
			'cron_policy'      => 'something',
		) );

		$this->assertSame( 'limited', $result['cli_policy'] );
		$this->assertSame( 'limited', $result['cron_policy'] );
	}

	public function test_sanitize_defaults_missing_policies_to_limited(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 15 ) );

		$this->assertSame( 'limited', $result['cli_policy'] );
		$this->assertSame( 'limited', $result['cron_policy'] );
		$this->assertSame( 'limited', $result['xmlrpc_policy'] );
		$this->assertSame( 'limited', $result['rest_app_password_policy'] );
	}

	public function test_policy_presets_define_expected_surface_values(): void {
		$presets = Admin::policy_presets();

		$this->assertSame(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_LIMITED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_LIMITED,
				Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_LIMITED,
			),
			$presets[ Admin::POLICY_PRESET_NORMAL ]['policies']
		);

		$this->assertSame(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_DISABLED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_DISABLED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_DISABLED,
				Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_DISABLED,
			),
			$presets[ Admin::POLICY_PRESET_INCIDENT_LOCKDOWN ]['policies']
		);

		$this->assertSame(
			array(
				Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_UNRESTRICTED,
				Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
				Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
				Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_DISABLED,
				Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_UNRESTRICTED,
			),
			$presets[ Admin::POLICY_PRESET_HEADLESS_FRIENDLY ]['policies']
		);
	}

	public function test_sanitize_applies_selected_policy_preset(): void {
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );

		$admin  = new Admin();
		$result = $admin->sanitize_settings(
			array(
				'session_duration'         => 15,
				'rest_app_password_policy' => Gate::POLICY_LIMITED,
				'cli_policy'               => Gate::POLICY_LIMITED,
				'cron_policy'              => Gate::POLICY_LIMITED,
				'xmlrpc_policy'            => Gate::POLICY_LIMITED,
				'wpgraphql_policy'         => Gate::POLICY_LIMITED,
				'policy_preset_selection'  => Admin::POLICY_PRESET_HEADLESS_FRIENDLY,
			)
		);

		$this->assertSame( Admin::POLICY_PRESET_HEADLESS_FRIENDLY, $result['policy_preset'] );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, $result['rest_app_password_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $result['cli_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $result['cron_policy'] );
		$this->assertSame( Gate::POLICY_DISABLED, $result['xmlrpc_policy'] );
		$this->assertSame( Gate::POLICY_UNRESTRICTED, $result['wpgraphql_policy'] );
	}

	public function test_sanitize_rejects_invalid_policy_preset_key(): void {
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );

		$admin  = new Admin();
		$result = $admin->sanitize_settings(
			array(
				'session_duration'         => 15,
				'rest_app_password_policy' => Gate::POLICY_DISABLED,
				'cli_policy'               => Gate::POLICY_LIMITED,
				'cron_policy'              => Gate::POLICY_LIMITED,
				'xmlrpc_policy'            => Gate::POLICY_LIMITED,
				'wpgraphql_policy'         => Gate::POLICY_LIMITED,
				'policy_preset_selection'  => 'not-a-real-preset',
			)
		);

		$this->assertSame( Admin::POLICY_PRESET_CUSTOM, $result['policy_preset'] );
		$this->assertSame( Gate::POLICY_DISABLED, $result['rest_app_password_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $result['cli_policy'] );
	}

	public function test_manual_policy_edit_after_preset_marks_configuration_custom(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'session_duration'         => 15,
				'rest_app_password_policy' => Gate::POLICY_UNRESTRICTED,
				'cli_policy'               => Gate::POLICY_LIMITED,
				'cron_policy'              => Gate::POLICY_LIMITED,
				'xmlrpc_policy'            => Gate::POLICY_DISABLED,
				'wpgraphql_policy'         => Gate::POLICY_UNRESTRICTED,
				'policy_preset'            => Admin::POLICY_PRESET_HEADLESS_FRIENDLY,
				'app_password_policies'    => array(),
			)
		);

		$admin  = new Admin();
		$result = $admin->sanitize_settings(
			array(
				'session_duration'         => 15,
				'rest_app_password_policy' => Gate::POLICY_UNRESTRICTED,
				'cli_policy'               => Gate::POLICY_DISABLED,
				'cron_policy'              => Gate::POLICY_LIMITED,
				'xmlrpc_policy'            => Gate::POLICY_DISABLED,
				'wpgraphql_policy'         => Gate::POLICY_UNRESTRICTED,
			)
		);

		$this->assertSame( Admin::POLICY_PRESET_CUSTOM, $result['policy_preset'] );
	}

	// -----------------------------------------------------------------
	// register()
	// -----------------------------------------------------------------

	public function test_register_hooks(): void {
		Actions\expectAdded( 'admin_menu' )
			->once();

		// admin_init is registered twice: once for register_settings (priority 10)
		// and once for cleanup_inert_governance_mode_option (priority 1).
		Actions\expectAdded( 'admin_init' )
			->twice();

		Actions\expectAdded( 'admin_enqueue_scripts' )
			->twice();

		Filters\expectAdded( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME )
			->once();

		Actions\expectAdded( 'wp_ajax_' . Admin::AJAX_MU_INSTALL )
			->once();

		Actions\expectAdded( 'wp_ajax_' . Admin::AJAX_MU_UNINSTALL )
			->once();

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// Constants
	// -----------------------------------------------------------------

	public function test_option_key_constant(): void {
		$this->assertSame( 'wp_sudo_settings', Admin::OPTION_KEY );
	}

	public function test_page_slug_constant(): void {
		$this->assertSame( 'wp-sudo-settings', Admin::PAGE_SLUG );
	}

	// -----------------------------------------------------------------
	// add_settings_page() — help tab hook registration
	// -----------------------------------------------------------------

	public function test_add_settings_page_registers_load_hook(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_options_page' )
			->once()
			->andReturn( 'settings_page_wp-sudo-settings' );

		Actions\expectAdded( 'load-settings_page_wp-sudo-settings' )
			->once();

		$admin = new Admin();
		$admin->add_settings_page();
	}

	public function test_add_settings_page_skips_load_hook_when_no_suffix(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_options_page' )
			->once()
			->andReturn( false );

		// No load- hook should be added when add_options_page returns false.
		Actions\expectAdded( 'load-' )->never();

		$admin = new Admin();
		$admin->add_settings_page();

		$this->assertTrue( true );
	}

	/**
	 * add_settings_page() must use manage_wp_sudo, not manage_options (governance enforcement).
	 */
	public function test_add_settings_page_uses_manage_wp_sudo_capability(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_options_page' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				\Mockery::type( 'string' ),
				'manage_wp_sudo',
				Admin::PAGE_SLUG,
				\Mockery::type( 'array' )
			)
			->andReturn( false );

		$admin = new Admin();
		$admin->add_settings_page();
	}

	// -----------------------------------------------------------------
	// add_help_tabs()
	// -----------------------------------------------------------------

	public function test_add_help_tabs_registers_six_tabs(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$this->assertCount( 6, $screen->get_help_tabs() );
	}

	public function test_add_help_tabs_has_expected_tab_ids(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs = $screen->get_help_tabs();
		$ids  = array_keys( $tabs );

		$this->assertContains( 'wp-sudo-start-here', $ids );
		$this->assertContains( 'wp-sudo-modes-policies', $ids );
		$this->assertContains( 'wp-sudo-rule-tester', $ids );
		$this->assertContains( 'wp-sudo-incident-response', $ids );
		$this->assertContains( 'wp-sudo-security-boundaries', $ids );
		$this->assertContains( 'wp-sudo-integrations-developers', $ids );
	}

	public function test_add_help_tabs_sets_sidebar(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$this->assertNotEmpty( $screen->get_help_sidebar() );
	}

	public function test_add_help_tabs_bails_when_no_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		$admin = new Admin();
		// Should not throw or error when screen is null.
		$admin->add_help_tabs();

		$this->assertTrue( true );
	}

	public function test_security_tab_mentions_boundaries(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-security-boundaries']['content'] ?? '';

		$this->assertStringContainsString( 'Compromised sessions', $content );
		$this->assertStringContainsString( 'Out of scope', $content );
	}

	public function test_modes_policies_tab_uses_full_sentences(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-modes-policies']['content'] ?? '';

		// Must start with a full sentence, not a fragment.
		$this->assertStringContainsString( 'Use a short session window', $content );
		$this->assertStringContainsString( 'Surface modes', $content );
	}

	public function test_sidebar_links_to_project_docs(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$sidebar = $screen->get_help_sidebar();

		$this->assertStringContainsString( 'docs/FAQ.md', $sidebar );
		$this->assertStringContainsString( 'docs/security-model.md', $sidebar );
		$this->assertStringContainsString( 'docs/developer-reference.md', $sidebar );
		$this->assertStringContainsString( 'docs/connectors-api-reference.md', $sidebar );
		$this->assertStringContainsString( 'docs/two-factor-integration.md', $sidebar );
	}

	public function test_how_it_works_tab_mentions_keyboard_shortcut(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-start-here']['content'] ?? '';

		$this->assertStringContainsString( 'Ctrl+Shift+S', $content );
	}

	public function test_start_here_tab_separates_reauthentication_from_authorization(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-start-here']['content'] ?? '';

		$this->assertStringContainsString( 'Sudo verifies that the current user is still the account holder', $content );
		$this->assertStringContainsString( 'WordPress still decides whether that user is allowed', $content );
		$this->assertStringNotContainsString( 'Role checks still run after the challenge', $content );
	}

	public function test_security_boundaries_tab_mentions_mu_plugin_and_multisite_docs(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-security-boundaries']['content'] ?? '';

		$this->assertStringContainsString( 'MU-plugin hardening', $content );
		$this->assertStringContainsString( 'multisite scope', strtolower( $content ) );
	}

	public function test_integrations_tab_covers_2fa_window_and_third_party(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-integrations-developers']['content'] ?? '';

		$this->assertStringContainsString( 'wp_sudo_two_factor_window', $content );
		$this->assertStringContainsString( 'wp_sudo_requires_two_factor', $content );
		$this->assertStringContainsString( 'wp_sudo_render_two_factor_fields', $content );
		$this->assertStringContainsString( 'wp_sudo_validate_two_factor', $content );
		$this->assertStringContainsString( 'Audit hooks', $content );
	}

	public function test_incident_response_tab_mentions_logging_plugins(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-incident-response']['content'] ?? '';

		$this->assertStringContainsString( 'WP Activity Log', $content );
		$this->assertStringContainsString( 'Stream', $content );
	}

	public function test_sidebar_links_to_logging_plugins(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$sidebar = $screen->get_help_sidebar();

		$this->assertStringContainsString( 'docs/FAQ.md', $sidebar );
		$this->assertStringContainsString( 'docs/security-model.md', $sidebar );
		$this->assertStringContainsString( 'docs/two-factor-integration.md', $sidebar );
	}

	public function test_help_tab_presets_describes_three_presets(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-modes-policies']['content'] ?? '';

		$this->assertStringContainsString( 'Normal', $content );
		$this->assertStringContainsString( 'Incident Lockdown', $content );
		$this->assertStringContainsString( 'Headless Friendly', $content );
		$this->assertStringContainsString( 'Surface modes', $content );
	}

	public function test_help_tab_rule_tester_describes_diagnostic_tool(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-rule-tester']['content'] ?? '';

		$this->assertStringContainsString( 'Safe request diagnostics', $content );
		$this->assertStringContainsString( 'connectors.update_credentials', $content );
		$this->assertStringContainsString( 'REST Params', $content );
	}

	public function test_modes_policies_tab_mentions_connectors(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-modes-policies']['content'] ?? '';

		$this->assertStringContainsString( 'Connectors', $content );
		$this->assertStringContainsString( 'credential writes are gated', $content );
		$this->assertStringContainsString( 'connectors.update_credentials', $content );
		$this->assertStringContainsString( 'per-site', $content );
		$this->assertStringContainsString( 'env/wp-config', $content );
	}

	// -----------------------------------------------------------------
	// render_settings_page()
	// -----------------------------------------------------------------

	public function test_render_settings_page_includes_introduction(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'reauthentication step', $output );
		$this->assertStringContainsString( 'two-factor authentication', $output );
		$this->assertStringContainsString( 'nav-tab-wrapper', $output );
	}

	public function test_render_settings_page_outputs_tab_navigation(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias( function ( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="nonce" />'; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nav-tab-wrapper', $output );
		$this->assertStringContainsString( 'aria-current="page"', $output );
		$this->assertStringContainsString( 'aria-label="Sudo settings sections"', $output );
		$this->assertStringContainsString( '>Settings</a>', $output );
		$this->assertStringContainsString( '>Gated Actions</a>', $output );
		$this->assertStringContainsString( '>Rule Tester</a>', $output );
	}

	public function test_render_settings_page_defaults_to_settings_tab(): void {
		unset( $_GET['tab'] );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias( function ( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="nonce" />'; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		// Default tab renders the form with settings_fields.
		$this->assertStringContainsString( 'nav-tab-active', $output );
		$this->assertStringContainsString( 'options.php', $output );
		// Should NOT contain tester or gated actions table content.
		$this->assertStringNotContainsString( 'Request / Rule Tester', $output );
	}

	public function test_render_settings_page_renders_actions_tab(): void {
		$_GET['tab'] = 'actions';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Gated Actions', $output );
		$this->assertStringContainsString( 'plugin.activate', $output );
		// Should NOT contain the settings form.
		$this->assertStringNotContainsString( 'options.php', $output );

		unset( $_GET['tab'] );
	}

	public function test_render_settings_page_renders_tester_tab(): void {
		$_GET['tab'] = 'tester';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Request / Rule Tester', $output );
		$this->assertStringNotContainsString( 'options.php', $output );

		unset( $_GET['tab'] );
	}

	public function test_render_settings_page_sanitizes_invalid_tab_to_default(): void {
		$_GET['tab'] = 'invalid_tab_name';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias( function ( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="nonce" />'; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		// Falls back to settings tab — renders the form.
		$this->assertStringContainsString( 'options.php', $output );
		$this->assertStringNotContainsString( 'Request / Rule Tester', $output );

		unset( $_GET['tab'] );
	}

	public function test_render_settings_page_tab_links_use_network_admin_url_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		// Tab links should use the network admin URL.
		$this->assertStringContainsString( 'wp-admin/network/', $output );
	}

	public function test_render_settings_page_includes_request_rule_tester(): void {
		$_GET['tab'] = 'tester';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Request / Rule Tester', $output );
		$this->assertStringContainsString( 'See how Sudo would evaluate a representative request', $output );
		$this->assertStringContainsString( 'name="wp_sudo_request_tester[url]"', $output );

		unset( $_GET['tab'] );
	}

	public function test_render_settings_page_processes_request_tester_submission(): void {
		$_GET['tab'] = 'tester';

		$gate = \Mockery::mock( Gate::class );
		$gate->shouldReceive( 'evaluate_diagnostic_request' )
			->once()
			->with(
				array(
					'surface'          => 'rest',
					'method'           => 'DELETE',
					'url'              => 'https://example.com/wp-json/wp/v2/plugins/hello-dolly',
					'is_authenticated' => true,
					'has_active_sudo'  => false,
					'is_network_admin' => true,
					'rest_auth_mode'   => 'application_password',
					'rest_params'      => array(),
				)
			)
			->andReturn(
				array(
					'matched_rule_id'       => 'plugin.delete',
					'matched_rule_label'    => 'Delete plugin',
					'matched_surface'       => 'rest',
					'decision'              => 'hard-block',
					'stash_replay_eligible' => false,
					'notes'                 => array( 'REST Application Password policy is Limited, so gated requests are blocked until policy changes.' ),
				)
			);

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( Admin::REQUEST_TESTER_NONCE_ACTION, Admin::REQUEST_TESTER_NONCE_NAME )
			->andReturn( true );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['wp_sudo_request_tester_submit'] = '1';
		$_POST['wp_sudo_request_tester'] = array(
			'surface'          => 'rest',
			'method'           => 'delete',
			'url'              => 'https://example.com/wp-json/wp/v2/plugins/hello-dolly',
			'is_authenticated' => '1',
			'has_active_sudo'  => '0',
			'is_network_admin' => '1',
			'rest_auth_mode'   => 'application_password',
			'rest_params'      => '',
		);

		$admin = new Admin();
		$this->inject_diagnostic_gate( $admin, $gate );

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Delete plugin', $output );
		$this->assertStringContainsString( 'hard-block', $output );
		$this->assertStringContainsString( 'REST Application Password policy is Limited', $output );
		$this->assertStringContainsString( 'id="wp-sudo-tester-result"', $output );
		$this->assertStringContainsString( 'role="status"', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );

		unset( $_POST['wp_sudo_request_tester_submit'], $_POST['wp_sudo_request_tester'], $_SERVER['REQUEST_METHOD'], $_GET['tab'] );
	}

	public function test_render_settings_page_includes_rest_params_textarea(): void {
		$_GET['tab'] = 'tester';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="wp_sudo_request_tester[rest_params]"', $output );
		$this->assertStringContainsString( 'REST Params', $output );
		$this->assertStringContainsString( 'textarea', $output );

		unset( $_GET['tab'] );
	}

	public function test_render_settings_page_passes_rest_params_to_gate_evaluator(): void {
		$_GET['tab'] = 'tester';

		$gate = \Mockery::mock( Gate::class );
		$gate->shouldReceive( 'evaluate_diagnostic_request' )
			->once()
			->with(
				array(
					'surface'          => 'rest',
					'method'           => 'PUT',
					'url'              => 'https://example.com/wp-json/wp/v2/settings',
					'is_authenticated' => true,
					'has_active_sudo'  => false,
					'is_network_admin' => false,
					'rest_auth_mode'   => 'cookie',
					'rest_params'      => array( 'connectors_ai_openai_api_key' => 'sk-test' ),
				)
			)
			->andReturn(
				array(
					'matched_rule_id'       => 'connectors.update_credentials',
					'matched_rule_label'    => 'Update connector credentials',
					'matched_surface'       => 'rest',
					'decision'              => 'gate',
					'stash_replay_eligible' => false,
					'notes'                 => array(),
				)
			);

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'network_admin_url' )->alias( fn( $path = '' ) => 'https://example.com/wp-admin/network/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['wp_sudo_request_tester_submit'] = '1';
		$_POST['wp_sudo_request_tester'] = array(
			'surface'          => 'rest',
			'method'           => 'put',
			'url'              => 'https://example.com/wp-json/wp/v2/settings',
			'is_authenticated' => '1',
			'has_active_sudo'  => '0',
			'is_network_admin' => '0',
			'rest_auth_mode'   => 'cookie',
			'rest_params'      => '{"connectors_ai_openai_api_key": "sk-test"}',
		);

		$admin = new Admin();
		$this->inject_diagnostic_gate( $admin, $gate );

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'connectors.update_credentials', $output );
		$this->assertStringContainsString( 'Update connector credentials', $output );

		unset( $_POST['wp_sudo_request_tester_submit'], $_POST['wp_sudo_request_tester'], $_SERVER['REQUEST_METHOD'], $_GET['tab'] );
	}

	// -----------------------------------------------------------------
	// Multisite: get()
	// -----------------------------------------------------------------

	public function test_get_uses_site_option_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Functions\expect( 'get_site_option' )
			->once()
			->with( Admin::OPTION_KEY, Admin::defaults() )
			->andReturn( array( 'session_duration' => 8 ) );

		$this->assertSame( 8, Admin::get( 'session_duration' ) );
	}

	public function test_get_uses_option_on_single_site(): void {
		Functions\when( 'is_multisite' )->justReturn( false );

		Functions\expect( 'get_option' )
			->once()
			->with( Admin::OPTION_KEY, Admin::defaults() )
			->andReturn( array( 'session_duration' => 12 ) );

		$this->assertSame( 12, Admin::get( 'session_duration' ) );
	}

	// -----------------------------------------------------------------
	// Multisite: register()
	// -----------------------------------------------------------------

	public function test_register_uses_network_admin_menu_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );

		Actions\expectAdded( 'network_admin_menu' )->once();
		Actions\expectAdded( 'network_admin_edit_wp_sudo_settings' )->once();
		// admin_init is registered twice: once for register_sections (priority 10)
		// and once for cleanup_inert_governance_mode_option (priority 1).
		Actions\expectAdded( 'admin_init' )->twice();
		Actions\expectAdded( 'admin_enqueue_scripts' )->twice();

		Filters\expectAdded( 'plugin_action_links_' . WP_SUDO_PLUGIN_BASENAME )->once();

		Actions\expectAdded( 'wp_ajax_' . Admin::AJAX_MU_INSTALL )->once();
		Actions\expectAdded( 'wp_ajax_' . Admin::AJAX_MU_UNINSTALL )->once();

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// Multisite: add_network_settings_page()
	// -----------------------------------------------------------------

	public function test_add_network_settings_page_registers_submenu(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'settings.php',
				\Mockery::type( 'string' ),
				\Mockery::type( 'string' ),
				'manage_wp_sudo',
				Admin::PAGE_SLUG,
				\Mockery::type( 'array' )
			)
			->andReturn( 'settings_page_wp-sudo-settings' );

		Actions\expectAdded( 'load-settings_page_wp-sudo-settings' )->once();

		$admin = new Admin();
		$admin->add_network_settings_page();
	}

	// -----------------------------------------------------------------
	// Multisite: handle_network_settings_save()
	// -----------------------------------------------------------------

	public function test_handle_network_settings_save_calls_nonce_check(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( Admin::PAGE_SLUG . '-options' )
			->andThrow( new \RuntimeException( 'nonce check executed' ) );

		$admin = new Admin();

		try {
			$admin->handle_network_settings_save();
			$this->fail( 'Expected nonce check short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'nonce check executed', $e->getMessage() );
		}
	}

	public function test_handle_network_settings_save_dies_when_user_cannot_manage_network_options(): void {
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\expect( 'wp_sudo_can' )
			->once()
			->with( 'manage_wp_sudo' )
			->andReturn( false );
		Functions\when( 'esc_html__' )->returnArg();

		Functions\expect( 'wp_die' )
			->once()
			->with( 'Unauthorized', '', array( 'response' => 403 ) )
			->andThrow( new \RuntimeException( 'unauthorized' ) );

		$admin = new Admin();

		try {
			$admin->handle_network_settings_save();
			$this->fail( 'Expected unauthorized short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'unauthorized', $e->getMessage() );
		}
	}

	public function test_handle_network_settings_save_updates_site_option_and_redirects(): void {
		$_POST[ Admin::OPTION_KEY ] = array(
			'session_duration' => '8',
			'cli_policy'       => Gate::POLICY_UNRESTRICTED,
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( Admin::defaults() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
		// No-referer case: fallback must be the bare settings page, no tab.
		Functions\when( 'wp_get_referer' )->justReturn( false );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/network/settings.php?page=wp-sudo-settings&updated=true' );

		Functions\expect( 'update_site_option' )
			->once()
			->with(
				Admin::OPTION_KEY,
				\Mockery::on(
					function ( $settings ) {
						return is_array( $settings )
							&& 8 === ( $settings['session_duration'] ?? null )
							&& Gate::POLICY_UNRESTRICTED === ( $settings['cli_policy'] ?? null );
					}
				)
			);

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with(
				\Mockery::on(
					function ( $url ) {
						return is_string( $url )
							&& str_contains( $url, 'page=wp-sudo-settings' )
							&& str_contains( $url, 'updated=true' )
							&& ! str_contains( $url, 'tab=' );
					}
				)
			)
			->andThrow( new \RuntimeException( 'redirected' ) );

		$admin = new Admin();

		try {
			$admin->handle_network_settings_save();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_POST[ Admin::OPTION_KEY ] );
	}

	/**
	 * Real add_query_arg() semantics for use across the tests below —
	 * supports both the single-array-args form and the
	 * (key, value, url) form used by handle_network_settings_save().
	 *
	 * @return void
	 */
	private function stub_real_add_query_arg_semantics(): void {
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ): string {
				if ( 2 === count( $args ) && is_array( $args[0] ) ) {
					$new_args = $args[0];
					$url      = $args[1];
				} else {
					$new_args = array( $args[0] => $args[1] );
					$url      = $args[2];
				}

				$parts = wp_parse_url( $url );
				$query = array();
				if ( ! empty( $parts['query'] ) ) {
					parse_str( $parts['query'], $query );
				}
				$query = array_merge( $query, $new_args );
				$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' );
				return $base . '?' . http_build_query( $query );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ) => parse_url( $url ) );
	}

	/**
	 * Bug: settings-tab-lost-on-reauth-replay.
	 *
	 * When a sudo-gated network settings save is replayed after reauthentication,
	 * wp_get_referer() (which prefers the replayed `_wp_http_referer` POST field
	 * over the Referer header) returns the tabbed settings URL the user was on
	 * (e.g. `...settings.php?page=wp-sudo-settings&tab=access`). The redirect
	 * must preserve that validated tab rather than dropping it.
	 *
	 * This test does NOT stub add_query_arg()/wp_parse_url() to a fixed
	 * string — it lets them run with real semantics, proving the &tab=
	 * argument present on the referring page reaches the final redirect.
	 */
	public function test_handle_network_settings_save_redirect_preserves_tab_query_arg(): void {
		$_POST[ Admin::OPTION_KEY ] = array(
			'session_duration' => '8',
			'cli_policy'       => Gate::POLICY_UNRESTRICTED,
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( Admin::defaults() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
		Functions\when( 'update_site_option' )->justReturn( true );

		Functions\when( 'network_admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.com/wp-admin/network/' . $path
		);

		// Simulates wp_referer_field() having captured the tabbed page the
		// form was rendered on, replayed via _wp_http_referer.
		Functions\when( 'wp_get_referer' )->justReturn(
			'https://example.com/wp-admin/network/settings.php?page=wp-sudo-settings&tab=access'
		);
		Functions\when( 'sanitize_key' )->returnArg();

		$this->stub_real_add_query_arg_semantics();

		$captured_redirect = null;
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with(
				\Mockery::on(
					function ( $url ) use ( &$captured_redirect ) {
						$captured_redirect = $url;
						return true;
					}
				)
			)
			->andThrow( new \RuntimeException( 'redirected' ) );

		$admin = new Admin();

		try {
			$admin->handle_network_settings_save();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertIsString( $captured_redirect );
		$this->assertStringContainsString( 'tab=access', $captured_redirect );
		$this->assertStringContainsString( 'updated=true', $captured_redirect );
		$this->assertStringContainsString( 'page=wp-sudo-settings', $captured_redirect );

		unset( $_POST[ Admin::OPTION_KEY ] );
	}

	/**
	 * Security: a same-host referer that is NOT the settings page must not
	 * have its query lifted onto the redirect target — only a validated
	 * `tab` from OUR settings page is ever honored.
	 */
	public function test_handle_network_settings_save_redirect_ignores_referer_for_different_page(): void {
		$_POST[ Admin::OPTION_KEY ] = array(
			'session_duration' => '8',
			'cli_policy'       => Gate::POLICY_UNRESTRICTED,
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( Admin::defaults() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
		Functions\when( 'update_site_option' )->justReturn( true );

		Functions\when( 'network_admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.com/wp-admin/network/' . $path
		);

		// Referer points at a different network admin page entirely.
		Functions\when( 'wp_get_referer' )->justReturn(
			'https://example.com/wp-admin/network/users.php?x=1'
		);

		$this->stub_real_add_query_arg_semantics();

		$captured_redirect = null;
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with(
				\Mockery::on(
					function ( $url ) use ( &$captured_redirect ) {
						$captured_redirect = $url;
						return true;
					}
				)
			)
			->andThrow( new \RuntimeException( 'redirected' ) );

		$admin = new Admin();

		try {
			$admin->handle_network_settings_save();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertIsString( $captured_redirect );
		$this->assertStringNotContainsString( 'x=1', $captured_redirect );
		$this->assertStringNotContainsString( 'tab=', $captured_redirect );
		$this->assertStringContainsString( 'page=wp-sudo-settings', $captured_redirect );
		$this->assertStringContainsString( 'updated=true', $captured_redirect );

		unset( $_POST[ Admin::OPTION_KEY ] );
	}

	/**
	 * Security: a referer for our settings page with an invalid/unknown
	 * `tab` value must not have that tab appended to the redirect.
	 */
	public function test_handle_network_settings_save_redirect_ignores_invalid_tab(): void {
		$_POST[ Admin::OPTION_KEY ] = array(
			'session_duration' => '8',
			'cli_policy'       => Gate::POLICY_UNRESTRICTED,
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( Admin::defaults() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
		Functions\when( 'update_site_option' )->justReturn( true );

		Functions\when( 'network_admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.com/wp-admin/network/' . $path
		);

		Functions\when( 'wp_get_referer' )->justReturn(
			'https://example.com/wp-admin/network/settings.php?page=wp-sudo-settings&tab=bogus'
		);
		Functions\when( 'sanitize_key' )->returnArg();

		$this->stub_real_add_query_arg_semantics();

		$captured_redirect = null;
		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with(
				\Mockery::on(
					function ( $url ) use ( &$captured_redirect ) {
						$captured_redirect = $url;
						return true;
					}
				)
			)
			->andThrow( new \RuntimeException( 'redirected' ) );

		$admin = new Admin();

		try {
			$admin->handle_network_settings_save();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertIsString( $captured_redirect );
		$this->assertStringNotContainsString( 'tab=bogus', $captured_redirect );
		$this->assertStringNotContainsString( 'tab=', $captured_redirect );
		$this->assertStringContainsString( 'page=wp-sudo-settings', $captured_redirect );
		$this->assertStringContainsString( 'updated=true', $captured_redirect );

		unset( $_POST[ Admin::OPTION_KEY ] );
	}

	// -----------------------------------------------------------------
	// render_gated_actions_table()
	// -----------------------------------------------------------------

	public function test_render_gated_actions_table_outputs_table(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_gated_actions_table();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<table class="widefat striped"', $output );
		$this->assertStringContainsString( 'Gated Actions', $output );
		$this->assertStringContainsString( 'plugin.activate', $output );
		$this->assertStringContainsString( 'theme.switch', $output );
		$this->assertStringContainsString( 'user.delete', $output );
	}

	public function test_render_gated_actions_table_shows_surfaces(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_gated_actions_table();
		$output = ob_get_clean();

		// plugin.activate has both Admin and REST surfaces.
		$this->assertStringContainsString( 'Admin', $output );
		$this->assertStringContainsString( 'REST', $output );
	}

	public function test_render_gated_actions_table_shows_graphql_row_when_wpgraphql_active(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'function_exists' )->alias(
			function ( string $name ): bool {
				return 'graphql' === $name;
			}
		);

		$admin = new Admin();

		ob_start();
		$admin->render_gated_actions_table();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'GraphQL', $output );
		$this->assertStringContainsString( 'All mutations', $output );
	}

	public function test_render_gated_actions_table_hides_graphql_row_when_wpgraphql_inactive(): void {
		// graphql() is not defined in the test environment, so function_exists('graphql')
		// returns false naturally — no mocking required.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_gated_actions_table();
		$output = ob_get_clean();

		// "All mutations" only appears in the conditional GraphQL table row —
		// the description paragraph uses lowercase "all mutations".
		$this->assertStringNotContainsString( 'All mutations', $output );
	}

	// -----------------------------------------------------------------
	// MU-plugin AJAX constants
	// -----------------------------------------------------------------

	public function test_ajax_mu_install_constant(): void {
		$this->assertSame( 'wp_sudo_mu_install', Admin::AJAX_MU_INSTALL );
	}

	public function test_ajax_mu_uninstall_constant(): void {
		$this->assertSame( 'wp_sudo_mu_uninstall', Admin::AJAX_MU_UNINSTALL );
	}

	public function test_mu_shim_contents_are_personalized_with_current_loader_path(): void {
		$template = "<?php\n\$wp_sudo_loader = '__WP_SUDO_LOADER_PATH__';\n";
		$loader   = "/var/www/html/wp-content/plugins/sudo-renamed/mu-plugin/wp-sudo-loader.php";

		$method = new \ReflectionMethod( Admin::class, 'personalize_mu_shim_contents' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$result = $method->invoke( null, $template, $loader );

		$this->assertStringContainsString( 'sudo-renamed/mu-plugin/wp-sudo-loader.php', $result );
		$this->assertStringNotContainsString( '__WP_SUDO_LOADER_PATH__', $result );
	}

	// -----------------------------------------------------------------
	// render_mu_plugin_status()
	// -----------------------------------------------------------------

	public function test_render_mu_plugin_status_shows_not_installed(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);

		// Simulate a writable mu-plugins directory so the Install button renders.
		\Patchwork\redefine(
			'is_writable',
			function ( string $path ): bool {
				return str_contains( $path, 'wp-content' ) ? true : \Patchwork\relay();
			}
		);

		// WP_SUDO_MU_LOADED is process-global once any earlier test defines
		// it (SiteHealthTest), so force the not-installed branch instead of
		// relying on the constant being absent.
		\Patchwork\redefine(
			'defined',
			function ( string $constant_name ): bool {
				return 'WP_SUDO_MU_LOADED' === $constant_name ? false : \Patchwork\relay();
			}
		);

		// render_mu_plugin_status() will show "Not installed" and an install button.
		$admin = new Admin();

		ob_start();
		$admin->render_mu_plugin_status();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Not installed', $output );
		$this->assertStringContainsString( 'wp-sudo-mu-install', $output );
		$this->assertStringContainsString( 'Install MU-Plugin', $output );

		// Manual instructions collapsed behind <details> (not <details open>).
		$this->assertStringContainsString( '<details', $output );
		$this->assertStringNotContainsString( '<details open', $output );

		// Accessibility: spinner has role="status", message has role="status" + tabindex.
		$this->assertStringContainsString( 'role="status"', $output );
		$this->assertStringContainsString( 'tabindex="-1"', $output );
	}

	public function test_render_mu_plugin_status_hides_button_when_not_writable(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);

		// Simulate a non-writable mu-plugins directory.
		\Patchwork\redefine(
			'is_writable',
			function ( string $path ): bool {
				return str_contains( $path, 'wp-content' ) ? false : \Patchwork\relay();
			}
		);

		// Force the not-installed branch regardless of whether an earlier
		// test defined the process-global WP_SUDO_MU_LOADED constant.
		\Patchwork\redefine(
			'defined',
			function ( string $constant_name ): bool {
				return 'WP_SUDO_MU_LOADED' === $constant_name ? false : \Patchwork\relay();
			}
		);

		$admin = new Admin();

		ob_start();
		$admin->render_mu_plugin_status();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Not installed', $output );

		// Install button must NOT be rendered.
		$this->assertStringNotContainsString( 'wp-sudo-mu-install', $output );
		$this->assertStringNotContainsString( 'Install MU-Plugin', $output );

		// Manual instructions shown expanded (<details open>).
		$this->assertStringContainsString( '<details open', $output );
		$this->assertStringContainsString( 'Manual install instructions', $output );
	}

	// -----------------------------------------------------------------
	// enqueue_assets() — JS and localized data
	// -----------------------------------------------------------------

	public function test_enqueue_assets_registers_admin_js(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'wp_enqueue_style' )->once();

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'wp-sudo-admin',
				\Mockery::type( 'string' ),
				\Mockery::type( 'array' ),
				\Mockery::any(),
				true
			);

		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-admin',
				'wpSudoAdmin',
				\Mockery::on( function ( $data ) {
					return isset( $data['ajaxUrl'] )
						&& isset( $data['nonce'] )
						&& $data['installAction'] === Admin::AJAX_MU_INSTALL
						&& $data['uninstallAction'] === Admin::AJAX_MU_UNINSTALL;
				} )
			);

		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fake-nonce' );

		$admin = new Admin();
		$admin->enqueue_assets( 'settings_page_' . Admin::PAGE_SLUG );
	}

	public function test_enqueue_assets_skips_other_pages(): void {
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		$admin = new Admin();
		$admin->enqueue_assets( 'toplevel_page_other-plugin' );

		// If we get here without expectations failing, the method correctly skipped.
		$this->assertTrue( true );
	}

	// -----------------------------------------------------------------
	// register_sections() — label_for associations
	// -----------------------------------------------------------------

	public function test_register_sections_includes_label_for_on_all_fields(): void {
		Functions\when( '__' )->returnArg();

		// Track all add_settings_field calls.
		$fields_called = array();
		Functions\expect( 'add_settings_section' )->zeroOrMoreTimes();
		Functions\expect( 'register_setting' )->zeroOrMoreTimes();

		Functions\expect( 'add_settings_field' )
			->zeroOrMoreTimes()
			->andReturnUsing(
				function ( $id, $title, $callback, $page, $section, $args = array() ) use ( &$fields_called ) {
					$fields_called[ $id ] = $args;
				}
			);

		$admin = new Admin();
		$admin->register_sections();

		// Session duration must have label_for.
		$this->assertArrayHasKey( 'session_duration', $fields_called );
		$this->assertArrayHasKey( 'label_for', $fields_called['session_duration'] );
		$this->assertSame( 'session_duration', $fields_called['session_duration']['label_for'] );

		$this->assertArrayHasKey( 'policy_preset_selection', $fields_called );
		$this->assertArrayHasKey( 'label_for', $fields_called['policy_preset_selection'] );
		$this->assertSame( 'policy_preset_selection', $fields_called['policy_preset_selection']['label_for'] );

		// All policy fields must have label_for matching their key.
		$policy_ids = array(
			Gate::SETTING_REST_APP_PASS_POLICY,
			Gate::SETTING_CLI_POLICY,
			Gate::SETTING_CRON_POLICY,
			Gate::SETTING_XMLRPC_POLICY,
		);
		foreach ( $policy_ids as $id ) {
			$this->assertArrayHasKey( $id, $fields_called, "Missing field: $id" );
			$this->assertArrayHasKey( 'label_for', $fields_called[ $id ], "Missing label_for for: $id" );
			$this->assertSame( $id, $fields_called[ $id ]['label_for'], "label_for mismatch for: $id" );
		}
	}

	// -----------------------------------------------------------------
	// enqueue_scripts() — admin JS strings
	// -----------------------------------------------------------------

	public function test_enqueue_scripts_localizes_strings(): void {
		$_GET['page'] = Admin::PAGE_SLUG;

		Functions\when( '__' )->returnArg();
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_enqueue_script' )->once();

		Functions\expect( 'admin_url' )
			->with( 'admin-ajax.php' )
			->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_create_nonce' )
			->with( 'wp_sudo_mu_plugin' )
			->andReturn( 'test-nonce' );

		$captured = null;
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-admin',
				'wpSudoAdmin',
				\Mockery::on(
					function ( $data ) use ( &$captured ) {
						$captured = $data;
						return true;
					}
				)
			);

		$admin = new Admin();
		$admin->enqueue_assets( 'settings_page_' . Admin::PAGE_SLUG );

		$this->assertArrayHasKey( 'strings', $captured );
		$this->assertArrayHasKey( 'genericError', $captured['strings'] );
		$this->assertArrayHasKey( 'networkError', $captured['strings'] );
		$this->assertNotEmpty( $captured['strings']['genericError'] );
		$this->assertNotEmpty( $captured['strings']['networkError'] );

		unset( $_GET['page'] );
	}

	public function test_enqueue_assets_includes_preset_descriptions(): void {
		$_GET['page'] = Admin::PAGE_SLUG;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_enqueue_script' )->once();

		Functions\expect( 'admin_url' )
			->with( 'admin-ajax.php' )
			->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_create_nonce' )
			->with( 'wp_sudo_mu_plugin' )
			->andReturn( 'test-nonce' );

		$captured = null;
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-admin',
				'wpSudoAdmin',
				\Mockery::on(
					function ( $data ) use ( &$captured ) {
						$captured = $data;
						return true;
					}
				)
			);

		$admin = new Admin();
		$admin->enqueue_assets( 'settings_page_' . Admin::PAGE_SLUG );

		$this->assertArrayHasKey( 'presetDescriptions', $captured );
		$descriptions = $captured['presetDescriptions'];

		// All 3 presets plus custom.
		$this->assertArrayHasKey( Admin::POLICY_PRESET_NORMAL, $descriptions );
		$this->assertArrayHasKey( Admin::POLICY_PRESET_INCIDENT_LOCKDOWN, $descriptions );
		$this->assertArrayHasKey( Admin::POLICY_PRESET_HEADLESS_FRIENDLY, $descriptions );
		$this->assertArrayHasKey( Admin::POLICY_PRESET_CUSTOM, $descriptions );

		// Descriptions are non-empty strings.
		$this->assertNotEmpty( $descriptions[ Admin::POLICY_PRESET_NORMAL ] );
		$this->assertNotEmpty( $descriptions[ Admin::POLICY_PRESET_CUSTOM ] );
		$this->assertStringContainsString( 'connector credentials', $descriptions[ Admin::POLICY_PRESET_HEADLESS_FRIENDLY ] );
		$this->assertStringContainsString( 'current site', $descriptions[ Admin::POLICY_PRESET_HEADLESS_FRIENDLY ] );

		unset( $_GET['page'] );
	}

	public function test_enqueue_assets_includes_preset_policies(): void {
		$_GET['page'] = Admin::PAGE_SLUG;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_enqueue_script' )->once();

		Functions\expect( 'admin_url' )
			->with( 'admin-ajax.php' )
			->andReturn( 'https://example.com/wp-admin/admin-ajax.php' );

		Functions\expect( 'wp_create_nonce' )
			->with( 'wp_sudo_mu_plugin' )
			->andReturn( 'test-nonce' );

		$captured = null;
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-admin',
				'wpSudoAdmin',
				\Mockery::on(
					function ( $data ) use ( &$captured ) {
						$captured = $data;
						return true;
					}
				)
			);

		$admin = new Admin();
		$admin->enqueue_assets( 'settings_page_' . Admin::PAGE_SLUG );

		// presetPolicies present with all 3 presets.
		$this->assertArrayHasKey( 'presetPolicies', $captured );
		$policies = $captured['presetPolicies'];

		$this->assertArrayHasKey( Admin::POLICY_PRESET_NORMAL, $policies );
		$this->assertArrayHasKey( Admin::POLICY_PRESET_INCIDENT_LOCKDOWN, $policies );
		$this->assertArrayHasKey( Admin::POLICY_PRESET_HEADLESS_FRIENDLY, $policies );

		// Each preset maps setting keys to policy values.
		$normal = $policies[ Admin::POLICY_PRESET_NORMAL ];
		$this->assertArrayHasKey( Gate::SETTING_REST_APP_PASS_POLICY, $normal );
		$this->assertArrayHasKey( Gate::SETTING_CLI_POLICY, $normal );
		$this->assertArrayHasKey( Gate::SETTING_CRON_POLICY, $normal );
		$this->assertArrayHasKey( Gate::SETTING_XMLRPC_POLICY, $normal );
		$this->assertArrayHasKey( Gate::SETTING_WPGRAPHQL_POLICY, $normal );
		$this->assertSame( Gate::POLICY_LIMITED, $normal[ Gate::SETTING_CLI_POLICY ] );

		// surfaceKeys present listing all policy setting keys.
		$this->assertArrayHasKey( 'surfaceKeys', $captured );
		$this->assertContains( Gate::SETTING_REST_APP_PASS_POLICY, $captured['surfaceKeys'] );
		$this->assertContains( Gate::SETTING_WPGRAPHQL_POLICY, $captured['surfaceKeys'] );

		unset( $_GET['page'] );
	}

	public function test_render_field_policy_presets_description_has_js_target_id(): void {
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );

		$admin = new Admin();

		ob_start();
		$admin->render_field_policy_presets();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="wp-sudo-preset-description"', $output );
		$this->assertStringContainsString( 'aria-describedby="wp-sudo-preset-description"', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );
	}

	// -----------------------------------------------------------------
	// add_help_tabs() — WPGraphQL conditional (v2.7.1)
	// -----------------------------------------------------------------

	/**
	 * Modes & Policies help tab shows the active WPGraphQL guidance
	 * when WPGraphQL is active (function_exists('graphql') returns true).
	 */
	public function test_help_tab_shows_wpgraphql_detail_when_active(): void {
		$screen = new \WP_Screen();
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		// function_exists mock goes LAST — Brain\Monkey uses function_exists internally
		// when registering prior stubs; setting this first causes redeclaration fatals.
		Functions\when( 'function_exists' )->alias( fn( string $n ): bool => 'graphql' === $n );

		( new Admin() )->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-modes-policies']['content'] ?? '';
		$this->assertStringContainsString( 'WPGraphQL note: in Limited mode', $content );
		$this->assertStringNotContainsString( 'policy appears here when WPGraphQL is installed', $content );
	}

	/**
	 * Modes & Policies help tab shows the install-prompt note
	 * when WPGraphQL is not active (function_exists('graphql') returns false).
	 */
	public function test_help_tab_shows_wpgraphql_install_note_when_inactive(): void {
		$screen = new \WP_Screen();
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		// No function_exists mock — Brain\Monkey returns null (falsy) by default.

		( new Admin() )->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-modes-policies']['content'] ?? '';
		$this->assertStringContainsString( 'WPGraphQL policy appears here when WPGraphQL is installed', $content );
		$this->assertStringNotContainsString( 'WPGraphQL note: in Limited mode', $content );
	}

	// =================================================================
	// App-password JS i18n keys
	// =================================================================

	public function test_app_password_assets_localizes_i18n_strings(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'wp_enqueue_script' )->justReturn();
		Functions\when( 'get_option' )->justReturn( array() );

		$_GET['user_id'] = 1;
		Functions\when( 'absint' )->justReturn( 1 );

		$captured = null;
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				'wp-sudo-app-passwords',
				'wpSudoAppPasswords',
				\Mockery::on(
					function ( $data ) use ( &$captured ) {
						$captured = $data;
						return true;
					}
				)
			);

		$admin = new Admin();
		$admin->maybe_enqueue_app_password_assets( 'profile.php' );

		$this->assertIsArray( $captured['i18n'] );
		$this->assertSame( 1, $captured['userId'] );
		$expected_keys = array( 'sudoRequired', 'policyAriaLabel', 'policyColumnHeader', 'policyColumnName' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $captured['i18n'], "Missing i18n key: $key" );
			$this->assertNotEmpty( $captured['i18n'][ $key ], "Empty string for i18n key: $key" );
		}

		unset( $_GET['user_id'] );
	}

	// =================================================================
	// render_field_policy_presets() — dropdown (Phase 10)
	// =================================================================

	public function test_render_field_policy_presets_outputs_select_dropdown(): void {
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );

		$admin = new Admin();

		ob_start();
		$admin->render_field_policy_presets();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<select', $output );
		$this->assertStringContainsString( 'policy_preset_selection', $output );
		$this->assertStringContainsString( 'Normal', $output );
		$this->assertStringContainsString( 'Incident Lockdown', $output );
		$this->assertStringContainsString( 'Headless Friendly', $output );
		// No radio buttons.
		$this->assertStringNotContainsString( 'type="radio"', $output );
	}

	public function test_render_field_policy_presets_shows_selected_description(): void {
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );

		$admin = new Admin();

		ob_start();
		$admin->render_field_policy_presets();
		$output = ob_get_clean();

		// Normal is the default, its description should appear.
		$this->assertStringContainsString( 'id="wp-sudo-preset-description"', $output );
		$this->assertStringContainsString( 'recommended baseline', $output );
		$this->assertStringContainsString( 'aria-describedby="wp-sudo-preset-description"', $output );
		$this->assertStringContainsString( 'aria-live="polite"', $output );
	}

	public function test_render_field_policy_presets_shows_custom_when_no_match(): void {
		// Return settings that don't match any preset.
		Functions\when( 'get_option' )->justReturn(
			array_merge( Admin::defaults(), array(
				'cli_policy'    => Gate::POLICY_UNRESTRICTED,
				'cron_policy'   => Gate::POLICY_DISABLED,
				'policy_preset' => Admin::POLICY_PRESET_CUSTOM,
			) )
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );

		$admin = new Admin();

		ob_start();
		$admin->render_field_policy_presets();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom', $output );
		$this->assertStringContainsString( 'disabled', $output );
		$this->assertStringContainsString( 'do not match any preset', $output );
	}

	public function test_render_field_policy_associates_select_with_description_text(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'selected' )->alias( fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );

		$admin = new Admin();

		ob_start();
		$admin->render_field_policy(
			array(
				'key'         => Gate::SETTING_CLI_POLICY,
				'description' => 'CLI policy description.',
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="cli_policy"', $output );
		$this->assertStringContainsString( 'aria-describedby="cli_policy-description"', $output );
		$this->assertStringContainsString( 'id="cli_policy-description"', $output );
		$this->assertStringContainsString( 'CLI policy description.', $output );
	}

	// =================================================================
	// sanitize_settings() — preset logic (Phase 10)
	// =================================================================

	public function test_sanitize_applies_preset_when_selection_changes(): void {
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );

		$admin  = new Admin();
		$result = $admin->sanitize_settings(
			array(
				'session_duration'        => 15,
				'policy_preset_selection' => Admin::POLICY_PRESET_INCIDENT_LOCKDOWN,
			)
		);

		$this->assertSame( Admin::POLICY_PRESET_INCIDENT_LOCKDOWN, $result['policy_preset'] );
		$this->assertSame( Gate::POLICY_DISABLED, $result['rest_app_password_policy'] );
		$this->assertSame( Gate::POLICY_DISABLED, $result['cli_policy'] );
		$this->assertSame( Gate::POLICY_LIMITED, $result['cron_policy'] );
		$this->assertSame( Gate::POLICY_DISABLED, $result['xmlrpc_policy'] );
	}

	public function test_sanitize_skips_preset_when_selection_unchanged(): void {
		Functions\when( 'get_option' )->justReturn(
			array_merge( Admin::defaults(), array(
				'policy_preset' => Admin::POLICY_PRESET_NORMAL,
			) )
		);

		$admin  = new Admin();
		$result = $admin->sanitize_settings(
			array(
				'session_duration'         => 10,
				'rest_app_password_policy' => Gate::POLICY_LIMITED,
				'cli_policy'               => Gate::POLICY_LIMITED,
				'cron_policy'              => Gate::POLICY_LIMITED,
				'xmlrpc_policy'            => Gate::POLICY_LIMITED,
				'wpgraphql_policy'         => Gate::POLICY_LIMITED,
				'policy_preset_selection'  => Admin::POLICY_PRESET_NORMAL,
			)
		);

		// Preset stays normal, duration was updated.
		$this->assertSame( Admin::POLICY_PRESET_NORMAL, $result['policy_preset'] );
		$this->assertSame( 10, $result['session_duration'] );
	}

	public function test_sanitize_marks_custom_when_policies_diverge_from_preset(): void {
		Functions\when( 'get_option' )->justReturn(
			array_merge( Admin::defaults(), array(
				'policy_preset' => Admin::POLICY_PRESET_NORMAL,
			) )
		);

		$admin  = new Admin();
		$result = $admin->sanitize_settings(
			array(
				'session_duration'         => 15,
				'rest_app_password_policy' => Gate::POLICY_UNRESTRICTED,
				'cli_policy'               => Gate::POLICY_LIMITED,
				'cron_policy'              => Gate::POLICY_LIMITED,
				'xmlrpc_policy'            => Gate::POLICY_LIMITED,
				'wpgraphql_policy'         => Gate::POLICY_LIMITED,
				'policy_preset_selection'  => Admin::POLICY_PRESET_NORMAL,
			)
		);

		$this->assertSame( Admin::POLICY_PRESET_CUSTOM, $result['policy_preset'] );
	}

	// =================================================================
	// render_gated_actions_table() — Connectors (Phase 10)
	// =================================================================

	public function test_render_gated_actions_table_includes_connector_credentials(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$admin = new Admin();

		ob_start();
		$admin->render_gated_actions_table();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'connectors.update_credentials', $output );
		$this->assertStringContainsString( 'REST', $output );
	}

	// =================================================================
	// render_policy_preset_notice() — plain language (Phase 10)
	// =================================================================

	public function test_preset_notice_uses_plain_language_surface_names(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'preset'  => Admin::POLICY_PRESET_HEADLESS_FRIENDLY,
				'current' => array(
					Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_UNRESTRICTED,
					Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
					Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
					Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_DISABLED,
					Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_UNRESTRICTED,
				),
			)
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		$admin = new Admin();

		ob_start();
		// Use reflection to call private method. setAccessible() is required
		// for PHP 8.0; it's a no-op in PHP 8.1+ and deprecated in PHP 8.5+.
		// Suppress deprecation warning for cross-version compatibility.
		$method = new \ReflectionMethod( Admin::class, 'render_policy_preset_notice' );
		@$method->setAccessible( true );
		$method->invoke( $admin );
		$output = ob_get_clean();

		// Uses plain names, not setting keys.
		$this->assertStringContainsString( 'REST', $output );
		$this->assertStringContainsString( 'CLI', $output );
		$this->assertStringContainsString( 'XML-RPC', $output );
		$this->assertStringContainsString( 'GraphQL', $output );
		$this->assertStringNotContainsString( 'rest_app_password_policy', $output );
		$this->assertStringNotContainsString( 'cli_policy', $output );
	}

	public function test_preset_notice_groups_by_policy_value(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'preset'  => Admin::POLICY_PRESET_HEADLESS_FRIENDLY,
				'current' => array(
					Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_UNRESTRICTED,
					Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
					Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
					Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_DISABLED,
					Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_UNRESTRICTED,
				),
			)
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		$admin = new Admin();

		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_policy_preset_notice' );
		@$method->setAccessible( true );
		$method->invoke( $admin );
		$output = ob_get_clean();

		// Surfaces are grouped: "REST and GraphQL are now unrestricted"
		$this->assertStringContainsString( 'REST and GraphQL', $output );
		$this->assertStringContainsString( 'unrestricted', $output );
		// "CLI and Cron are now limited"
		$this->assertStringContainsString( 'CLI and Cron', $output );
		$this->assertStringContainsString( 'limited', $output );
		// "XML-RPC is now disabled"
		$this->assertStringContainsString( 'XML-RPC', $output );
		$this->assertStringContainsString( 'disabled', $output );
		// Semicolons join groups.
		$this->assertStringContainsString( ';', $output );
	}

	public function test_preset_notice_simplifies_when_all_same_value(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'preset'  => Admin::POLICY_PRESET_NORMAL,
				'current' => array(
					Gate::SETTING_REST_APP_PASS_POLICY => Gate::POLICY_LIMITED,
					Gate::SETTING_CLI_POLICY           => Gate::POLICY_LIMITED,
					Gate::SETTING_CRON_POLICY          => Gate::POLICY_LIMITED,
					Gate::SETTING_XMLRPC_POLICY        => Gate::POLICY_LIMITED,
					Gate::SETTING_WPGRAPHQL_POLICY     => Gate::POLICY_LIMITED,
				),
			)
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		$admin = new Admin();

		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_policy_preset_notice' );
		@$method->setAccessible( true );
		$method->invoke( $admin );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'All surfaces are now limited', $output );
		$this->assertStringNotContainsString( ';', $output );
	}

	// -----------------------------------------------------------------
	// render_compatibility_mode_notice() — BRK-03 (reworked in 4.0.0)
	// -----------------------------------------------------------------

	/**
	 * Helper: set the private static compat_option_cleared flag via reflection.
	 */
	private function set_compat_option_cleared( bool $value ): void {
		$ref = new \ReflectionProperty( Admin::class, 'compat_option_cleared' );
		@$ref->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ref->setValue( null, $value );
	}

	/**
	 * Helper: invoke render_compatibility_mode_notice() and return its output.
	 */
	private function invoke_compatibility_notice(): string {
		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'render_compatibility_mode_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		ob_start();
		$method->invoke( $admin );
		return (string) ob_get_clean();
	}

	/**
	 * Notice renders a one-time dismissible "fixed" confirmation when the static
	 * flag is set (option was cleaned up this request). It must:
	 *   - output class "notice-success" (not notice-warning)
	 *   - output class "is-dismissible"
	 *   - NOT call _doing_it_wrong()
	 *   - NOT read get_option('wp_sudo_governance_mode')
	 */
	public function test_compatibility_notice_renders_when_flag_is_set(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\expect( '_doing_it_wrong' )->never();

		$this->set_compat_option_cleared( true );
		$output = $this->invoke_compatibility_notice();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringNotContainsString( 'notice-warning', $output );
	}

	/**
	 * Notice skips entirely for unauthorized users (no output, no flag check).
	 */
	public function test_compatibility_notice_skips_when_user_lacks_authority(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_sudo_can' )->justReturn( false );
		Functions\expect( '_doing_it_wrong' )->never();

		$this->set_compat_option_cleared( true );
		$this->assertSame( '', $this->invoke_compatibility_notice() );
	}

	/**
	 * Notice skips when the static cleared flag is false (no cleanup occurred
	 * this request — the option was already gone or cleanup hasn't run yet).
	 */
	public function test_compatibility_notice_skips_when_flag_is_not_set(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\expect( '_doing_it_wrong' )->never();

		$this->set_compat_option_cleared( false );
		$this->assertSame( '', $this->invoke_compatibility_notice() );
	}

	/**
	 * The notice must NOT call _doing_it_wrong() — the dev signal moved to the
	 * audit hook fired by cleanup_inert_governance_mode_option().
	 */
	public function test_compatibility_notice_does_not_call_doing_it_wrong(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\expect( '_doing_it_wrong' )->never();

		$this->set_compat_option_cleared( true );
		$this->invoke_compatibility_notice();

		// If _doing_it_wrong() was never called, the Mockery expectation above passes.
		$this->assertTrue( true );
	}

	public function test_compatibility_notice_registers_admin_and_network_hooks(): void {
		Functions\when( '__' )->returnArg();

		// admin_notices is registered twice: once for render_compatibility_mode_notice
		// and once for render_revoke_result_notice (4.5.0).
		Actions\expectAdded( 'admin_notices' )
			->twice();
		Actions\expectAdded( 'network_admin_notices' )
			->with( array( \Mockery::type( Admin::class ), 'render_compatibility_mode_notice' ), 10, 0 )
			->once();

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// cleanup_inert_governance_mode_option() — BRK-03 clear-on-detection
	// -----------------------------------------------------------------

	/**
	 * Helper: invoke cleanup_inert_governance_mode_option() on a fresh Admin.
	 *
	 * @return Admin The Admin instance used (for static state inspection).
	 */
	private function invoke_cleanup(): Admin {
		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'cleanup_inert_governance_mode_option' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin );
		return $admin;
	}

	/**
	 * Helper: read the private static compat_option_cleared flag via reflection.
	 */
	private function get_compat_option_cleared(): bool {
		$ref = new \ReflectionProperty( Admin::class, 'compat_option_cleared' );
		@$ref->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return (bool) $ref->getValue( null );
	}

	/**
	 * cleanup_inert_governance_mode_option() must be hooked on admin_init at
	 * priority 1 so it runs before admin_notices.
	 *
	 * register() also adds admin_init for register_settings (priority 10), so
	 * we expect admin_init twice in total.
	 */
	public function test_cleanup_registered_on_admin_init_priority_1(): void {
		// The cleanup hook — the assertion under test.
		Actions\expectAdded( 'admin_init' )
			->with(
				array( \Mockery::type( Admin::class ), 'cleanup_inert_governance_mode_option' ),
				1,
				0
			)
			->once();

		// The settings registration hook that also adds admin_init.
		Actions\expectAdded( 'admin_init' )
			->once();

		$admin = new Admin();
		$admin->register();
	}

	/**
	 * cleanup returns early without deleting or setting the flag when the user
	 * is not authorized.
	 */
	public function test_cleanup_returns_early_when_user_unauthorized(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( false );
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'delete_option' )->never();

		$this->set_compat_option_cleared( false );
		$this->invoke_cleanup();

		$this->assertFalse( $this->get_compat_option_cleared() );
	}

	/**
	 * On single-site, when the option is present in the per-site store, cleanup
	 * must: delete the option, set the static flag, and fire the audit action.
	 */
	public function test_cleanup_deletes_option_and_sets_flag_on_single_site(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		// Option present in per-site store.
		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				if ( 'wp_sudo_governance_mode' === $key ) {
					return 'compatibility';
				}
				return false;
			}
		);

		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$actions_fired = array();
		Functions\when( 'do_action' )->alias(
			function ( string $hook ) use ( &$actions_fired ) {
				$actions_fired[] = $hook;
			}
		);

		$this->set_compat_option_cleared( false );
		$this->invoke_cleanup();

		$this->assertContains( 'wp_sudo_governance_mode', $deleted );
		$this->assertTrue( $this->get_compat_option_cleared() );
		$this->assertContains( 'wp_sudo_inert_governance_mode_detected', $actions_fired );
	}

	/**
	 * On multisite, cleanup must delete from both per-site and network-wide stores.
	 */
	public function test_cleanup_deletes_from_both_stores_on_multisite(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		// Option present in per-site store (get_option).
		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				return 'wp_sudo_governance_mode' === $key ? 'compatibility' : false;
			}
		);
		// Not in sitemeta — doesn't matter, $found is already true.
		Functions\when( 'get_site_option' )->justReturn( false );

		$deleted      = array();
		$site_deleted = array();

		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		Functions\when( 'delete_site_option' )->alias(
			function ( string $key ) use ( &$site_deleted ) {
				$site_deleted[] = $key;
				return true;
			}
		);

		Functions\when( 'do_action' )->justReturn( null );

		$this->invoke_cleanup();

		$this->assertContains( 'wp_sudo_governance_mode', $deleted );
		$this->assertContains( 'wp_sudo_governance_mode', $site_deleted );
	}

	/**
	 * cleanup must fire do_action('wp_sudo_inert_governance_mode_detected') when
	 * it finds and deletes the option — this is the semantically-correct
	 * developer/audit signal replacing _doing_it_wrong().
	 */
	public function test_cleanup_fires_audit_action_when_option_found(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				return 'wp_sudo_governance_mode' === $key ? 'any-value' : false;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );

		$actions_fired = array();
		Functions\when( 'do_action' )->alias(
			function ( string $hook ) use ( &$actions_fired ) {
				$actions_fired[] = $hook;
			}
		);

		$this->invoke_cleanup();

		$this->assertContains( 'wp_sudo_inert_governance_mode_detected', $actions_fired );
	}

	/**
	 * cleanup must also detect the option when it lives in sitemeta only
	 * (get_option returns false but get_site_option has a value).
	 */
	public function test_cleanup_finds_option_in_sitemeta_on_multisite(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		// Not in per-site store.
		Functions\when( 'get_option' )->justReturn( false );
		// Present in sitemeta.
		Functions\when( 'get_site_option' )->alias(
			function ( string $key ) {
				return 'wp_sudo_governance_mode' === $key ? 'compatibility' : false;
			}
		);

		$deleted      = array();
		$site_deleted = array();

		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->alias(
			function ( string $key ) use ( &$site_deleted ) {
				$site_deleted[] = $key;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$this->invoke_cleanup();

		$this->assertContains( 'wp_sudo_governance_mode', $deleted );
		$this->assertContains( 'wp_sudo_governance_mode', $site_deleted );
		$this->assertTrue( $this->get_compat_option_cleared() );
	}

	/**
	 * When neither store has the option, cleanup must not delete, set flag,
	 * or fire the audit action.
	 */
	public function test_cleanup_does_nothing_when_option_absent(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'do_action' )->never();

		$this->set_compat_option_cleared( false );
		$this->invoke_cleanup();

		$this->assertFalse( $this->get_compat_option_cleared() );
	}

	/**
	 * cleanup detects any non-false value, not just 'compatibility' — the
	 * deletion is intentional regardless of value (per design: broadened
	 * detection from Phase 13 clarification Issue 8).
	 */
	public function test_cleanup_deletes_any_present_value_not_just_compatibility(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		// Non-'compatibility' value — still present (truthy), must be deleted.
		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				return 'wp_sudo_governance_mode' === $key ? 'strict' : false;
			}
		);

		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$this->invoke_cleanup();

		$this->assertContains( 'wp_sudo_governance_mode', $deleted );
		$this->assertTrue( $this->get_compat_option_cleared() );
	}

	// -----------------------------------------------------------------
	// Users list screen: Sudo Active filter
	// -----------------------------------------------------------------

	/**
	 * Test filter_user_views adds Sudo Active link to views.
	 *
	 * @return void
	 */
	public function test_filter_user_views_adds_sudo_active_link(): void {
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'get_users' )->never();

		\WP_User_Query::$mock_total = 3;

		$admin = new Admin();
		$views = $admin->filter_user_views( array( 'all' => '<a>All</a>' ) );

		$this->assertArrayHasKey( 'sudo_active', $views );
		$this->assertStringContainsString( 'sudo_active=1', $views['sudo_active'] );
		$this->assertStringContainsString( '3', $views['sudo_active'] );
		$this->assertSame( 1, \WP_User_Query::$last_query_vars['number'] );
		$this->assertTrue( \WP_User_Query::$last_query_vars['count_total'] );
		$this->assertSame( 'ID', \WP_User_Query::$last_query_vars['fields'] );
	}

	/**
	 * Test filter_user_views returns unmodified views when no active sessions.
	 *
	 * @return void
	 */
	public function test_filter_user_views_omitted_when_zero(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		\WP_User_Query::$mock_total = 0;

		$admin = new Admin();
		$views = $admin->filter_user_views( array( 'all' => '<a>All</a>' ) );

		$this->assertArrayNotHasKey( 'sudo_active', $views );
	}

	/**
	 * Test filter_user_views marks link as current when query arg present.
	 *
	 * @return void
	 */
	public function test_filter_user_views_current_class_when_active(): void {
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		\WP_User_Query::$mock_total = 1;

		$_GET['sudo_active'] = '1';

		$admin = new Admin();
		$views = $admin->filter_user_views( array( 'all' => '<a>All</a>' ) );

		$this->assertStringContainsString( 'current', $views['sudo_active'] );

		unset( $_GET['sudo_active'] );
	}

	/**
	 * Test filter_user_views does not mark the tab current for non-matching values.
	 *
	 * @return void
	 */
	public function test_filter_user_views_not_current_for_non_matching_value(): void {
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		\WP_User_Query::$mock_total = 1;

		$_GET['sudo_active'] = '2';

		$admin = new Admin();
		$views = $admin->filter_user_views( array( 'all' => '<a>All</a>' ) );

		$this->assertStringNotContainsString( 'current', $views['sudo_active'] );

		unset( $_GET['sudo_active'] );
	}

	/**
	 * Test filter_user_views uses the cached count when transient is available.
	 *
	 * @return void
	 */
	public function test_filter_user_views_uses_cached_count_when_present(): void {
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( 9 );
		$set_transient_called = false;
		Functions\when( 'set_transient' )->alias(
			static function () use ( &$set_transient_called ) {
				$set_transient_called = true;
				return true;
			}
		);

		$admin = new Admin();
		$views = $admin->filter_user_views( array( 'all' => '<a>All</a>' ) );

		$this->assertArrayHasKey( 'sudo_active', $views );
		$this->assertStringContainsString( '9', $views['sudo_active'] );
		$this->assertSame( array(), \WP_User_Query::$last_query_vars );
		$this->assertFalse( $set_transient_called );
	}

	/**
	 * Test filter_user_views stores the computed count in transient cache.
	 *
	 * @return void
	 */
	public function test_filter_user_views_caches_computed_count(): void {
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		$cached_value = null;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, int $value, int $ttl ) use ( &$cached_value ) {
				$cached_value = array( $key, $value, $ttl );
				return true;
			}
		);

		\WP_User_Query::$mock_total = 4;

		$admin = new Admin();
		$views = $admin->filter_user_views( array( 'all' => '<a>All</a>' ) );

		$this->assertArrayHasKey( 'sudo_active', $views );
		$this->assertStringContainsString( '4', $views['sudo_active'] );
		$this->assertSame( array( 'wp_sudo_active_count_1', 4, 30 ), $cached_value );
	}

	/**
	 * Test filter_users_by_sudo_active adds the meta query for sudo_active=1.
	 *
	 * @return void
	 */
	public function test_filter_users_by_sudo_active_adds_meta_query_for_explicit_filter(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$_GET['sudo_active'] = '1';

		$query = new \WP_User_Query(
			array(
				'meta_query' => array(),
			)
		);

		$admin = new Admin();
		$admin->filter_users_by_sudo_active( $query );

		$meta_query = $query->get( 'meta_query' );

		$this->assertIsArray( $meta_query );
		$this->assertCount( 1, $meta_query );
		$this->assertSame( '_wp_sudo_expires', $meta_query[0]['key'] );
		$this->assertSame( '>', $meta_query[0]['compare'] );

		unset( $_GET['sudo_active'] );
	}

	/**
	 * Test filter_users_by_sudo_active ignores non-matching values.
	 *
	 * @return void
	 */
	public function test_filter_users_by_sudo_active_ignores_non_matching_value(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$_GET['sudo_active'] = '2';

		$query = new \WP_User_Query(
			array(
				'meta_query' => array(),
			)
		);

		$admin = new Admin();
		$admin->filter_users_by_sudo_active( $query );

		$this->assertSame( array(), $query->get( 'meta_query' ) );

		unset( $_GET['sudo_active'] );
	}

	// -----------------------------------------------------------------
	// GOVERNANCE_CAPS constant
	// -----------------------------------------------------------------

	public function test_governance_caps_constant_contains_four_capabilities(): void {
		$this->assertCount( 4, Admin::GOVERNANCE_CAPS );
	}

	public function test_governance_caps_constant_has_manage_wp_sudo(): void {
		$this->assertContains( 'manage_wp_sudo', Admin::GOVERNANCE_CAPS );
	}

	public function test_governance_caps_constant_has_all_four_caps(): void {
		$this->assertContains( 'view_wp_sudo_activity', Admin::GOVERNANCE_CAPS );
		$this->assertContains( 'export_wp_sudo_activity', Admin::GOVERNANCE_CAPS );
		$this->assertContains( 'revoke_wp_sudo_sessions', Admin::GOVERNANCE_CAPS );
	}

	// -----------------------------------------------------------------
	// Access tab routing
	// -----------------------------------------------------------------

	public function test_render_settings_page_includes_access_tab_in_navigation(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->alias( static fn( $a, $b, $echo = false ) => (string) ( $a === $b ? 'selected="selected"' : '' ) );
		Functions\when( 'checked' )->alias( static fn( $a, $b = true, $echo = false ) => (string) ( $a === $b ? 'checked="checked"' : '' ) );
		Functions\when( 'wp_nonce_field' )->alias( static function ( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="nonce" />'; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( static function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tab=access', $output );
		$this->assertStringContainsString( '>Access</a>', $output );
	}

	public function test_render_settings_page_renders_access_tab(): void {
		$_GET['tab'] = 'access';

		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'Sudo Settings' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		$grant_admin               = new \WP_User( 7, array( 'administrator' ) );
		$grant_admin->display_name = 'Ada Admin';
		$grant_admin->user_login   = 'ada';

		Functions\when( 'get_users' )->alias( static function ( array $args = array() ) use ( $grant_admin ): array {
			if ( array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => 'all',
				'number'  => -1,
			) === $args ) {
				return array( $grant_admin );
			}

			return array();
		} );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias( static function ( array $args, string $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); } );

		$admin = new Admin();

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Access Control', $output );
		$this->assertStringContainsString( 'id="wp-sudo-grant-user-search"', $output );
		$this->assertStringContainsString( 'type="search"', $output );
		$this->assertStringContainsString( 'aria-controls="wp-sudo-grant-user"', $output );
		$this->assertStringContainsString( 'Search administrators by name or username', $output );
		$this->assertStringContainsString( 'Search filters the administrator list by name or username.', $output );
		$this->assertStringContainsString( '<select id="wp-sudo-grant-user" data-searchable="true"', $output );
		$this->assertStringContainsString( '<option value="0">', $output );
		$this->assertStringContainsString( '— Select a user —', $output );
		$this->assertStringContainsString( '<option value="7" data-search-text="ada admin ada">Ada Admin (ada)</option>', $output );
		$this->assertStringNotContainsString( 'type="number" id="wp-sudo-grant-user"', $output );
		$this->assertStringContainsString( '<option value="manage_wp_sudo"', $output );
		// Friendly label is the visible option text; the raw slug is not shown in prominent text.
		$this->assertStringContainsString( 'Manage Sudo settings and policies', $output );
		$this->assertStringNotContainsString( 'Manage Sudo settings and policies (manage_wp_sudo)', $output );
		$this->assertStringContainsString( 'value="view_wp_sudo_activity"', $output );
		$this->assertStringContainsString( 'value="export_wp_sudo_activity"', $output );
		$this->assertStringContainsString( 'value="revoke_wp_sudo_sessions"', $output );

		$grant_js = file_get_contents( dirname( __DIR__, 2 ) . '/admin/js/wp-sudo-admin.js' );
		$this->assertIsString( $grant_js );
		$this->assertStringContainsString( 'wp-sudo-grant-user-search', $grant_js );
		$this->assertMatchesRegularExpression( '/user_id:\s*userId/', $grant_js );

		unset( $_GET['tab'] );
	}

	/**
	 * Stubs the WordPress functions the Access-tab User cell newly depends on
	 * (avatar, edit-link capability, admin URL, and translated role names).
	 */
	private function stub_access_user_cell(): void {
		Functions\when( 'get_avatar' )->justReturn( '<img alt="" src="avatar.png" />' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'self_admin_url' )->returnArg();
		Functions\when( 'translate_user_role' )->returnArg( 1 );
		Functions\when( 'wp_roles' )->alias(
			static function () {
				return new class() {
					public function get_names(): array {
						return array( 'administrator' => 'Administrator' );
					}
				};
			}
		);
	}

	public function test_render_access_tab_holder_table_has_no_revoke_session_button(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		$this->stub_access_user_cell();

		$holder_admin               = new \WP_User( 9, array( 'administrator' ) );
		$holder_admin->display_name = 'Holder Admin';
		$holder_admin->user_login   = 'holder';

		Functions\when( 'get_users' )->alias( static function ( array $args = array() ) use ( $holder_admin ): array {
			if ( 'revoke_wp_sudo_sessions' === ( $args['capability'] ?? null ) ) {
				return array( $holder_admin );
			}

			if ( array_key_exists( 'capability', $args ) ) {
				return array();
			}

			// Administrator grant-list query.
			return array();
		} );

		$admin = new Admin();

		ob_start();
		$admin->render_access_tab();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Holder Admin', $output );
		$this->assertStringContainsString( 'revoke_wp_sudo_sessions', $output );
		$this->assertStringContainsString( 'wp-sudo-revoke-cap', $output );
		$this->assertStringContainsString( '>Revoke<', $output );
		$this->assertStringNotContainsString( 'wp-sudo-revoke-session', $output );
		$this->assertStringNotContainsString( 'Revoke Session', $output );
	}

	public function test_render_access_tab_holder_table_shows_friendly_labels_not_slug_codes(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		$this->stub_access_user_cell();

		$holder_admin               = new \WP_User( 9, array( 'administrator' ) );
		$holder_admin->display_name = 'Holder Admin';
		$holder_admin->user_login   = 'holder';

		Functions\when( 'get_users' )->alias( static function ( array $args = array() ) use ( $holder_admin ): array {
			if ( 'revoke_wp_sudo_sessions' === ( $args['capability'] ?? null ) ) {
				return array( $holder_admin );
			}

			if ( array_key_exists( 'capability', $args ) ) {
				return array();
			}

			return array();
		} );

		$admin = new Admin();

		ob_start();
		$admin->render_access_tab();
		$output = ob_get_clean();

		// The human-readable label is the visible text for the capability.
		$this->assertStringContainsString( "Revoke other users' active sessions", $output );
		// Each capability and its Revoke control are grouped in one container.
		$this->assertStringContainsString( 'wp-sudo-cap-item', $output );
		// The raw slug is NOT rendered as prominent visible text (no bare <code> slug).
		$this->assertStringNotContainsString( '<code>revoke_wp_sudo_sessions</code>', $output );
		// The slug stays available to assistive tech (screen-reader text) and as a tooltip.
		$this->assertStringContainsString( '<span class="screen-reader-text">revoke_wp_sudo_sessions</span>', $output );
		$this->assertStringContainsString( 'title="revoke_wp_sudo_sessions"', $output );
		// The revoke control contract is preserved for the JS handler.
		$this->assertStringContainsString( 'wp-sudo-revoke-cap', $output );
		$this->assertStringContainsString( 'data-cap="revoke_wp_sudo_sessions"', $output );
		$this->assertStringContainsString( '>Revoke<', $output );
		// Each button carries a capability-specific accessible name for screen readers
		// tabbing through otherwise-identical "Revoke" controls.
		$this->assertStringContainsString( 'aria-label="Revoke Revoke other users\' active sessions capability"', $output );
	}

	public function test_render_access_tab_user_cell_shows_avatar_name_role_and_secondary_login(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		$this->stub_access_user_cell();

		$holder               = new \WP_User( 12, array( 'administrator' ) );
		$holder->user_login   = 'msantos';
		$holder->display_name = 'msantos';
		$holder->first_name   = 'Maria';
		$holder->last_name    = 'Santos';

		Functions\when( 'get_users' )->alias(
			static function ( array $args = array() ) use ( $holder ): array {
				if ( 'manage_wp_sudo' === ( $args['capability'] ?? null ) ) {
					return array( $holder );
				}
				return array();
			}
		);

		$admin = new Admin();
		ob_start();
		$admin->render_access_tab();
		$output = ob_get_clean();

		// Avatar container present (image supplied by the get_avatar stub).
		$this->assertStringContainsString( 'wp-sudo-access-user-avatar', $output );
		// Full real name is the primary/prominent identity, not the login.
		$this->assertStringContainsString( '<span class="wp-sudo-access-user-name">Maria Santos</span>', $output );
		// Translated role label is shown as a chip.
		$this->assertStringContainsString( '<span class="wp-sudo-access-user-role">Administrator</span>', $output );
		// Username is secondary and linked to the user-edit screen.
		$this->assertStringContainsString( 'wp-sudo-access-user-login', $output );
		$this->assertStringContainsString( 'user-edit.php?user_id=12', $output );
		$this->assertStringContainsString( '>msantos</a>', $output );
		// The old bare display_name + <code>login</code> layout is gone.
		$this->assertStringNotContainsString( '<code>msantos</code>', $output );
	}

	public function test_render_access_tab_user_cell_login_is_plain_span_when_edit_user_denied(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		$this->stub_access_user_cell();
		// Deny the edit-user capability so the login renders unlinked.
		Functions\when( 'current_user_can' )->justReturn( false );

		$holder               = new \WP_User( 13, array( 'administrator' ) );
		$holder->user_login   = 'msantos';
		$holder->display_name = 'msantos';
		$holder->first_name   = 'Maria';
		$holder->last_name    = 'Santos';

		Functions\when( 'get_users' )->alias(
			static function ( array $args = array() ) use ( $holder ): array {
				return 'manage_wp_sudo' === ( $args['capability'] ?? null ) ? array( $holder ) : array();
			}
		);

		$admin = new Admin();
		ob_start();
		$admin->render_access_tab();
		$output = ob_get_clean();

		// Login shows as a non-linked span, not an anchor, and no edit URL leaks.
		$this->assertStringContainsString( '<span class="wp-sudo-access-user-login">msantos</span>', $output );
		$this->assertStringNotContainsString( 'user-edit.php?user_id=13', $output );
	}

	public function test_render_access_tab_user_cell_links_primary_when_login_is_primary_and_edit_allowed(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		$this->stub_access_user_cell();

		// No real name and display_name === login → the login is the sole identity,
		// so it becomes the primary line and must still carry the edit link.
		$holder               = new \WP_User( 15, array( 'administrator' ) );
		$holder->user_login   = 'admin';
		$holder->display_name = 'admin';

		Functions\when( 'get_users' )->alias(
			static function ( array $args = array() ) use ( $holder ): array {
				return 'manage_wp_sudo' === ( $args['capability'] ?? null ) ? array( $holder ) : array();
			}
		);

		$admin = new Admin();
		ob_start();
		$admin->render_access_tab();
		$output = ob_get_clean();

		// The primary line itself is the edit link, carrying both identity classes.
		$this->assertStringContainsString( 'class="wp-sudo-access-user-name wp-sudo-access-user-login"', $output );
		$this->assertStringContainsString( 'user-edit.php?user_id=15', $output );
		$this->assertStringContainsString( '>admin</a>', $output );
		// No separate secondary login line is emitted.
		$this->assertStringNotContainsString( 'wp-sudo-access-user-secondary', $output );
	}

	// -----------------------------------------------------------------
	// AJAX registration: governance handlers
	// -----------------------------------------------------------------

	public function test_register_adds_grant_cap_ajax_hook(): void {
		Actions\expectAdded( 'wp_ajax_' . Admin::AJAX_GRANT_CAP )
			->once();

		$admin = new Admin();
		$admin->register();
	}

	public function test_register_adds_revoke_cap_ajax_hook(): void {
		Actions\expectAdded( 'wp_ajax_' . Admin::AJAX_REVOKE_CAP )
			->once();

		$admin = new Admin();
		$admin->register();
	}

	/**
	 * The wp_ajax_ session-revoke action is orphaned now that the admin-post
	 * row-action handler (plan 02) is the single revocation entry point.
	 * register() must not add it.
	 */
	public function test_register_does_not_add_revoke_session_ajax_hook(): void {
		Actions\expectAdded( 'wp_ajax_wp_sudo_revoke_session' )
			->never();

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// handle_grant_cap()
	// -----------------------------------------------------------------

	public function test_handle_grant_cap_requires_manage_wp_sudo_cap(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 403 );

		$admin = new Admin();
		$admin->handle_grant_cap();
	}

	public function test_handle_grant_cap_rejects_invalid_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 400 );

		$_POST['cap']     = 'not_a_valid_sudo_cap';
		$_POST['user_id'] = '5';

		$admin = new Admin();
		$admin->handle_grant_cap();

		unset( $_POST['cap'], $_POST['user_id'] );
	}

	public function test_handle_grant_cap_grants_capability_to_target_user(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		$mock_user     = \Mockery::mock( \WP_User::class );
		$mock_user->ID = 5;
		$mock_user->shouldReceive( 'add_cap' )->once()->with( 'manage_wp_sudo' );

		Functions\when( 'get_userdata' )->justReturn( $mock_user );

		$_POST['cap']     = 'manage_wp_sudo';
		$_POST['user_id'] = '5';

		$admin = new Admin();
		$admin->handle_grant_cap();

		unset( $_POST['cap'], $_POST['user_id'] );
	}

	public function test_handle_grant_cap_fires_capability_granted_hook(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		$mock_user     = \Mockery::mock( \WP_User::class );
		$mock_user->ID = 5;
		$mock_user->shouldReceive( 'add_cap' )->once();

		Functions\when( 'get_userdata' )->justReturn( $mock_user );

		Actions\expectDone( 'wp_sudo_capability_granted' )
			->once()
			->with( 5, 'manage_wp_sudo', 1, 1 );

		$_POST['cap']     = 'manage_wp_sudo';
		$_POST['user_id'] = '5';

		$admin = new Admin();
		$admin->handle_grant_cap();

		unset( $_POST['cap'], $_POST['user_id'] );
	}

	// -----------------------------------------------------------------
	// handle_revoke_cap()
	// -----------------------------------------------------------------

	public function test_handle_revoke_cap_requires_manage_wp_sudo_cap(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 403 );

		$admin = new Admin();
		$admin->handle_revoke_cap();
	}

	public function test_handle_revoke_cap_blocks_last_manager_removal(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		// Only one manage_wp_sudo holder.
		Functions\when( 'get_users' )->justReturn( array( 1 ) );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 409 );

		$_POST['cap']     = 'manage_wp_sudo';
		$_POST['user_id'] = '1';

		$admin = new Admin();
		$admin->handle_revoke_cap();

		unset( $_POST['cap'], $_POST['user_id'] );
	}

	public function test_handle_revoke_cap_removes_capability_from_user(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		$mock_user     = \Mockery::mock( \WP_User::class );
		$mock_user->ID = 5;
		$mock_user->shouldReceive( 'remove_cap' )->once()->with( 'view_wp_sudo_activity' );

		Functions\when( 'get_userdata' )->justReturn( $mock_user );

		// No get_users call for non-manage_wp_sudo cap.
		$_POST['cap']     = 'view_wp_sudo_activity';
		$_POST['user_id'] = '5';

		$admin = new Admin();
		$admin->handle_revoke_cap();

		unset( $_POST['cap'], $_POST['user_id'] );
	}

	public function test_handle_revoke_cap_fires_capability_revoked_hook(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_send_json_success' )->justReturn( null );

		$mock_user     = \Mockery::mock( \WP_User::class );
		$mock_user->ID = 7;
		$mock_user->shouldReceive( 'remove_cap' )->once();

		Functions\when( 'get_userdata' )->justReturn( $mock_user );

		Actions\expectDone( 'wp_sudo_capability_revoked' )
			->once()
			->with( 7, 'export_wp_sudo_activity', 1, 1 );

		$_POST['cap']     = 'export_wp_sudo_activity';
		$_POST['user_id'] = '7';

		$admin = new Admin();
		$admin->handle_revoke_cap();

		unset( $_POST['cap'], $_POST['user_id'] );
	}

	// -----------------------------------------------------------------
	// handle_app_password_policy_save() — F14 UUID validation
	// -----------------------------------------------------------------

	/**
	 * Helper: mock the components needed for Sudo_Session::is_active($user_id)
	 * to return true. $user_id defaults to 1.
	 */
	private function mock_active_sudo_session( int $user_id = 1 ): void {
		$token = 'test-sudo-token';
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key, bool $single ) use ( $user_id, $token ) {
				if ( $uid === $user_id ) {
					if ( \WP_Sudo\Sudo_Session::META_KEY === $key ) {
						return time() + 600;
					}
					if ( \WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key ) {
						return hash( 'sha256', $token );
					}
				}
				return '';
			}
		);
		Functions\when( 'hash_equals' )->alias(
			static function ( string $a, string $b ): bool {
				return $a === $b;
			}
		);
		$_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] = $token;
	}

	/**
	 * F14 — UUID format: supplying a non-UUID string must be rejected before
	 * the option is written.
	 *
	 * @since 3.1.5
	 */
	public function test_handle_app_password_policy_save_rejects_malformed_uuid(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session();
		Functions\when( 'wp_is_uuid' )->justReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ) );

		$_POST['uuid']   = 'not-a-uuid';
		$_POST['policy'] = 'limited';

		$admin = new Admin();
		$admin->handle_app_password_policy_save();

		unset( $_POST['uuid'], $_POST['policy'] );
	}

	/**
	 * F14 — UUID existence: a well-formed UUID that doesn't belong to any of
	 * the user's application passwords must be rejected.
	 *
	 * @since 3.1.5
	 */
	public function test_handle_app_password_policy_save_rejects_uuid_not_owned_by_user(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session();
		Functions\when( 'wp_is_uuid' )->justReturn( true );

		// No passwords registered for this user.
		\WP_Application_Passwords::$mock_passwords = array();

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ) );

		$_POST['uuid']   = '12345678-1234-4234-a234-123456789012';
		$_POST['policy'] = 'limited';

		$admin = new Admin();
		$admin->handle_app_password_policy_save();

		unset( $_POST['uuid'], $_POST['policy'] );
	}

	/**
	 * F14 — Happy path: a valid UUID that exists for the current user is saved.
	 *
	 * @since 3.1.5
	 */
	public function test_handle_app_password_policy_save_accepts_valid_existing_uuid(): void {
		$uuid = '12345678-1234-4234-a234-123456789012';

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session();
		Functions\when( 'wp_is_uuid' )->justReturn( true );

		// UUID exists for user 1.
		\WP_Application_Passwords::$mock_passwords = array(
			$uuid => array( 'uuid' => $uuid, 'name' => 'Test App' ),
		);

		Functions\when( 'get_option' )->justReturn( Admin::defaults() );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\expect( 'wp_send_json_success' )->once();

		$_POST['uuid']   = $uuid;
		$_POST['policy'] = 'limited';

		$admin = new Admin();
		$admin->handle_app_password_policy_save();

		unset( $_POST['uuid'], $_POST['policy'] );
	}

	/**
	 * App Password policy saves from user-edit.php validate UUID ownership
	 * against the profile user, not only the acting manager.
	 *
	 * @since 3.1.5
	 */
	public function test_handle_app_password_policy_save_accepts_valid_uuid_for_edited_user(): void {
		$uuid = '12345678-1234-4234-a234-123456789012';

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session( 1 );
		Functions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static function ( string $cap, int $target_user_id ): bool {
				return 'edit_user' === $cap && 5 === $target_user_id;
			}
		);
		Functions\when( 'wp_is_uuid' )->justReturn( true );

		\WP_Application_Passwords::$mock_passwords = array(
			5 => array(
				$uuid => array( 'uuid' => $uuid, 'name' => 'Target User App' ),
			),
		);

		$captured = null;
		Functions\when( 'get_option' )->justReturn( Admin::defaults() );
		Functions\expect( 'update_option' )
			->once()
			->with(
				Admin::OPTION_KEY,
				\Mockery::on(
					static function ( array $settings ) use ( &$captured ): bool {
						$captured = $settings;
						return true;
					}
				)
			);
		Functions\expect( 'wp_send_json_success' )->once();

		$_POST['user_id'] = '5';
		$_POST['uuid']    = $uuid;
		$_POST['policy']  = 'limited';

		$admin = new Admin();
		$admin->handle_app_password_policy_save();

		$this->assertSame( 'limited', $captured['app_password_policies'][ $uuid ] ?? null );

		unset( $_POST['user_id'], $_POST['uuid'], $_POST['policy'] );
	}

	/**
	 * Cross-user App Password policy saves require the actor to be allowed to
	 * edit the target user.
	 *
	 * @since 3.1.5
	 */
	public function test_handle_app_password_policy_save_rejects_edited_user_without_edit_cap(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session( 1 );
		Functions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);
		Functions\when( 'current_user_can' )->justReturn( false );

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 403 );

		$_POST['user_id'] = '5';
		$_POST['uuid']    = '12345678-1234-4234-a234-123456789012';
		$_POST['policy']  = 'limited';

		$admin = new Admin();
		$admin->handle_app_password_policy_save();

		unset( $_POST['user_id'], $_POST['uuid'], $_POST['policy'] );
	}

	/**
	 * A UUID that exists for the actor but not the edited profile user must not
	 * be accepted when saving from user-edit.php.
	 *
	 * @since 3.1.5
	 */
	public function test_handle_app_password_policy_save_rejects_uuid_not_owned_by_edited_user(): void {
		$uuid = '12345678-1234-4234-a234-123456789012';

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session( 1 );
		Functions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_is_uuid' )->justReturn( true );

		\WP_Application_Passwords::$mock_passwords = array(
			1 => array(
				$uuid => array( 'uuid' => $uuid, 'name' => 'Actor App' ),
			),
			5 => array(),
		);

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ) );

		$_POST['user_id'] = '5';
		$_POST['uuid']    = $uuid;
		$_POST['policy']  = 'limited';

		$admin = new Admin();
		$admin->handle_app_password_policy_save();

		unset( $_POST['user_id'], $_POST['uuid'], $_POST['policy'] );
	}

	/**
	 * F14 — Cleanup hook: deleting an application password removes its
	 * policy override from settings.
	 *
	 * @since 3.1.5
	 */
	public function test_handle_app_password_deleted_removes_policy_override(): void {
		$uuid     = 'deadbeef-dead-4ead-beef-deadbeefcafe';
		$settings = array_merge(
			Admin::defaults(),
			array( 'app_password_policies' => array( $uuid => 'disabled' ) )
		);

		Functions\when( 'get_option' )->justReturn( $settings );

		$captured = null;
		Functions\expect( 'update_option' )
			->once()
			->with(
				Admin::OPTION_KEY,
				\Mockery::on(
					static function ( array $data ) use ( &$captured ): bool {
						$captured = $data;
						return true;
					}
				)
			);

		$admin = new Admin();
		$admin->handle_app_password_deleted( 1, array( 'uuid' => $uuid ) );

		$this->assertArrayNotHasKey( $uuid, $captured['app_password_policies'] ?? array() );
	}

	// -----------------------------------------------------------------
	// revoke_session_core() — factored revocation core
	//
	// handle_revoke_session() (the AJAX wrapper) was removed as orphaned
	// once the admin-post row-action handler became the single revocation
	// entry point. Its cap/rate-limit/audit-hook behavior lived entirely in
	// the shared core below, which is tested directly via reflection, and
	// via the row-action handler tests further down — no coverage was lost.
	// -----------------------------------------------------------------

	/**
	 * Invoke the private revoke_session_core() helper via reflection.
	 *
	 * @param Admin  $admin           Admin instance.
	 * @param int    $target_user_id  Target user ID.
	 * @param int    $revoker_user_id Revoker user ID.
	 * @param string $reason          Reason/surface tag.
	 * @return array<string, mixed>
	 */
	private function invoke_revoke_session_core( Admin $admin, int $target_user_id, int $revoker_user_id, string $reason ): array {
		$method = new \ReflectionMethod( Admin::class, 'revoke_session_core' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $method->invoke( $admin, $target_user_id, $revoker_user_id, $reason );
	}

	public function test_revoke_session_core_returns_no_cap_and_never_checks_liveness_for_capless_caller(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( false );

		// A LIVE target — must not be consulted at all for a capless caller.
		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $this->invoke_revoke_session_core( $admin, 9, 2, 'users_list_row_action' );

		$this->assertSame( 'no_cap', $result['outcome'] );
	}

	public function test_revoke_session_core_returns_self_target_when_target_equals_revoker(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );

		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $this->invoke_revoke_session_core( $admin, 2, 2, 'users_list_row_action' );

		$this->assertSame( 'self_target', $result['outcome'] );
	}

	public function test_revoke_session_core_returns_target_expired_without_deactivate_or_rate_slot(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		// Target's expiry is in the past -> not live.
		Functions\when( 'get_user_meta' )->justReturn( time() - 60 );

		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $this->invoke_revoke_session_core( $admin, 9, 2, 'users_list_row_action' );

		$this->assertSame( 'target_expired', $result['outcome'] );
	}

	public function test_revoke_session_core_returns_rate_limited_when_transient_at_limit(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( time() + 120 ); // Live target.
		Functions\when( 'get_transient' )->justReturn( 10 );

		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $this->invoke_revoke_session_core( $admin, 9, 2, 'users_list_row_action' );

		$this->assertSame( 'rate_limited', $result['outcome'] );
	}

	public function test_revoke_session_core_succeeds_and_fires_audit_hook_with_reason_tag(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( time() + 120 ); // Live target.
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		Functions\expect( 'set_transient' )
			->once()
			->with( '_wp_sudo_revoke_count_2', 1, \Mockery::type( 'int' ) );

		Actions\expectDone( 'wp_sudo_session_revoked' )
			->once()
			->with( 9, 2, 'revoke_all_ui', 1 );

		$admin  = new Admin();
		$result = $this->invoke_revoke_session_core( $admin, 9, 2, 'revoke_all_ui' );

		$this->assertSame( 'success', $result['outcome'] );
	}

	public function test_revoke_session_core_consumes_one_rate_slot_per_call(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( time() + 120 ); // Live target.
		Functions\when( 'get_transient' )->justReturn( 3 );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );

		Functions\expect( 'set_transient' )
			->once()
			->with( '_wp_sudo_revoke_count_2', 4, \Mockery::type( 'int' ) );

		$admin  = new Admin();
		$result = $this->invoke_revoke_session_core( $admin, 9, 2, 'users_list_row_action' );

		$this->assertSame( 'success', $result['outcome'] );
	}

	// -----------------------------------------------------------------
	// user_row_actions() — per-row "Revoke sudo session" visibility gate
	// -----------------------------------------------------------------

	/**
	 * Build a minimal WP_User stub for row-action tests.
	 *
	 * @param int $id User ID.
	 * @return \WP_User
	 */
	private function make_row_user( int $id ): \WP_User {
		$user     = new \WP_User();
		$user->ID = $id;
		return $user;
	}

	public function test_user_row_actions_absent_when_operator_lacks_cap(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Functions\expect( 'get_user_meta' )->never();

		$admin   = new Admin();
		$actions = $admin->user_row_actions( array( 'edit' => '<a>Edit</a>' ), $this->make_row_user( 9 ) );

		$this->assertArrayNotHasKey( 'wp_sudo_revoke_session', $actions );
	}

	public function test_user_row_actions_absent_on_operators_own_row(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );

		// Liveness must not even be consulted for the operator's own row.
		Functions\expect( 'get_user_meta' )->never();

		$admin   = new Admin();
		$actions = $admin->user_row_actions( array( 'edit' => '<a>Edit</a>' ), $this->make_row_user( 9 ) );

		$this->assertArrayNotHasKey( 'wp_sudo_revoke_session', $actions );
	}

	public function test_user_row_actions_absent_when_target_session_not_live(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Target's expiry is in the past -> is_session_live() is false.
		Functions\when( 'get_user_meta' )->justReturn( time() - 60 );

		$admin   = new Admin();
		$actions = $admin->user_row_actions( array( 'edit' => '<a>Edit</a>' ), $this->make_row_user( 9 ) );

		$this->assertArrayNotHasKey( 'wp_sudo_revoke_session', $actions );
	}

	public function test_user_row_actions_present_for_live_target_even_without_operator_session(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Target's expiry is in the future -> is_session_live() is true. The
		// operator's OWN session state is irrelevant to visibility — the action
		// must still render even though only the target's meta is read here.
		Functions\when( 'get_user_meta' )->justReturn( time() + 120 );
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( string $url, $action = -1 ) {
				return $url . '&_wpnonce=test-nonce&action=' . rawurlencode( (string) $action );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ) {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );

		$admin   = new Admin();
		$actions = $admin->user_row_actions( array( 'edit' => '<a>Edit</a>' ), $this->make_row_user( 9 ) );

		$this->assertArrayHasKey( 'wp_sudo_revoke_session', $actions );
		$this->assertStringContainsString( 'admin-post.php', $actions['wp_sudo_revoke_session'] );
		$this->assertStringContainsString( 'user_id=9', $actions['wp_sudo_revoke_session'] );
		$this->assertStringContainsString( 'Revoke sudo session', $actions['wp_sudo_revoke_session'] );
	}

	// -----------------------------------------------------------------
	// handle_revoke_session_row_action() — per-user admin_post_ handler
	// -----------------------------------------------------------------

	/**
	 * check_admin_referer() with $stop=true (the default) dies via
	 * wp_nonce_ays() on an invalid/missing nonce and never returns to the
	 * caller — there is no "false" branch to handle in the handler itself.
	 * This test proves the nonce check action name/short-circuit happens
	 * BEFORE any cap/session/core processing, mirroring the established
	 * test_handle_network_settings_save_calls_nonce_check() pattern.
	 */
	public function test_revoke_session_row_action_calls_nonce_check_first(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( Admin::REVOKE_SESSION_ROW_NONCE_ACTION )
			->andThrow( new \RuntimeException( 'nonce check executed' ) );

		// No further processing should occur past the nonce check.
		Functions\expect( 'wp_sudo_can' )->never();
		Functions\expect( 'get_user_meta' )->never();

		$_GET['user_id'] = '9';

		$admin = new Admin();

		try {
			$admin->handle_revoke_session_row_action();
			$this->fail( 'Expected nonce check short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'nonce check executed', $e->getMessage() );
		}

		unset( $_GET['user_id'] );
	}

	public function test_revoke_session_row_action_success_calls_core_and_redirects(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		// Operator (2) holds a token-bound active sudo session (is_active) AND the
		// target (9) session is live. is_active(2) requires the expiry meta, a
		// stored token hash, and a matching request cookie token.
		$operator_token = 'operator-sudo-token';
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key ) use ( $operator_token ) {
				if ( \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return time() + 120;
				}
				if ( 2 === $uid && \WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $operator_token );
				}
				return '';
			}
		);
		Functions\when( 'hash_equals' )->alias( static fn( string $a, string $b ): bool => $a === $b );
		$_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] = $operator_token;
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);

		Actions\expectDone( 'wp_sudo_session_revoked' )
			->once()
			->with( 9, 2, 'users_list_row_action', 1 );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( \Mockery::on( static function ( string $url ): bool {
				return false !== strpos( $url, 'users.php' )
					&& false !== strpos( $url, 'wp_sudo_revoke_result=success' );
			} ) )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$_GET['user_id'] = '9';

		$admin = new Admin();

		try {
			$admin->handle_revoke_session_row_action();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_GET['user_id'] );
		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_revoke_session_row_action_blocked_when_operator_has_no_active_session(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		// Operator's OWN session meta is expired/missing -> is_session_live(2) false.
		// The alias distinguishes the operator-gate read (user 2) from any target
		// read by returning 0 only for the operator's own meta key reads.
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key ) {
				if ( 2 === $user_id && \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return 0;
				}
				return time() + 120;
			}
		);

		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( \Mockery::on( static function ( string $url ): bool {
				return false !== strpos( $url, 'users.php' )
					&& false !== strpos( $url, 'wp_sudo_revoke_result=no-operator-session' );
			} ) )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$_GET['user_id'] = '9';

		$admin = new Admin();

		try {
			$admin->handle_revoke_session_row_action();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_GET['user_id'] );
	}

	/**
	 * Security regression guard: the perform handler must require the operator's
	 * CURRENT request to hold a token-bound sudo session (is_active), not merely
	 * a future expiry timestamp (is_session_live). A live expiry with no valid
	 * request token — e.g. a stolen auth cookie, or a second session without its
	 * own sudo — must be blocked before any session is revoked.
	 *
	 * @since 4.5.0
	 */
	public function test_revoke_session_row_action_blocked_when_operator_session_lacks_token_binding(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		// Operator (2) expiry is in the FUTURE — is_session_live(2) would pass —
		// but there is no stored token hash, so verify_token()/is_active(2) fails.
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $user_id parity with get_user_meta signature.
				if ( \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return time() + 600;
				}
				return ''; // no TOKEN_META_KEY hash -> verify_token() is false
			}
		);

		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( \Mockery::on( static function ( string $url ): bool {
				return false !== strpos( $url, 'users.php' )
					&& false !== strpos( $url, 'wp_sudo_revoke_result=no-operator-session' );
			} ) )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$_GET['user_id'] = '9';

		$admin = new Admin();

		try {
			$admin->handle_revoke_session_row_action();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_GET['user_id'] );
	}

	public function test_revoke_session_row_action_blocked_when_operator_lacks_cap(): void {
		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );

		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'do_action' )->never();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( \Mockery::on( static function ( string $url ): bool {
				return false !== strpos( $url, 'wp_sudo_revoke_result=no_cap' );
			} ) )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$_GET['user_id'] = '9';

		$admin = new Admin();

		try {
			$admin->handle_revoke_session_row_action();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_GET['user_id'] );
	}

	/**
	 * Render-to-click race: the target's session expires between row-render
	 * and the handler actually executing. The core must return target_expired
	 * with NO deactivate() and NO rate slot consumed; the handler must surface
	 * a distinct result code rather than collapsing into success.
	 */
	public function test_revoke_session_row_action_handles_target_expired_race(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\expect( 'check_admin_referer' )
			->once()
			->andReturn( true );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		// Operator (2) holds a token-bound active sudo session (passes is_active),
		// but the target's session has since expired (race) -> target_expired.
		$operator_token = 'operator-sudo-token';
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key ) use ( $operator_token ) {
				if ( 2 === $user_id && \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return time() + 120;
				}
				if ( 2 === $user_id && \WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key ) {
					return hash( 'sha256', $operator_token );
				}
				return time() - 60;
			}
		);
		Functions\when( 'hash_equals' )->alias( static fn( string $a, string $b ): bool => $a === $b );
		$_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] = $operator_token;

		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( \Mockery::on( static function ( string $url ): bool {
				return false !== strpos( $url, 'wp_sudo_revoke_result=target_expired' );
			} ) )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$_GET['user_id'] = '9';

		$admin = new Admin();

		try {
			$admin->handle_revoke_session_row_action();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_GET['user_id'] );
		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] );
	}

	// -----------------------------------------------------------------
	// Bulk action: "Revoke sudo sessions" on the Users list
	// -----------------------------------------------------------------

	public function test_register_adds_bulk_revoke_dropdown_and_nonced_interceptor(): void {
		Filters\expectAdded( 'bulk_actions-users' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 1 );

		Actions\expectAdded( 'load-users.php' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 0 );

		// Core does NOT nonce-check custom bulk actions on users.php, so the
		// un-nonce-able handle_bulk_actions-users filter must NOT be used —
		// it would remain a CSRF bypass around the load-users.php interceptor.
		Filters\expectAdded( 'handle_bulk_actions-users' )->never();

		$admin = new Admin();
		$admin->register();
	}

	public function test_register_does_not_add_revoke_all_admin_post_or_tablenav_hooks(): void {
		Actions\expectAdded( 'restrict_manage_users' )->never();
		Actions\expectAdded( 'admin_post_wp_sudo_revoke_all_confirm' )->never();
		Actions\expectAdded( 'admin_post_wp_sudo_revoke_all_perform' )->never();

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// handle_bulk_revoke_request() — nonce-verified load-users.php interceptor
	// -----------------------------------------------------------------

	/**
	 * CSRF regression guard: a crafted GET with our action but no nonce must
	 * die inside check_admin_referer() BEFORE any guard or teardown work —
	 * this is the exact un-nonced hole (core does not nonce-check custom
	 * users.php bulk actions) that the interceptor exists to close.
	 */
	public function test_bulk_request_nonce_checked_before_any_processing(): void {
		Functions\when( 'is_network_admin' )->justReturn( false );
		$_REQUEST['action'] = Admin::BULK_REVOKE_SESSIONS_ACTION;
		$_REQUEST['users']  = array( '9' );

		Functions\expect( 'check_admin_referer' )
			->once()
			->with( 'bulk-users' )
			->andThrow( new \RuntimeException( 'nonce check executed' ) );

		Functions\expect( 'wp_sudo_can' )->never();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();

		$admin = new Admin();

		try {
			$admin->handle_bulk_revoke_request();
			$this->fail( 'Expected nonce check short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'nonce check executed', $e->getMessage() );
		}

		unset( $_REQUEST['action'], $_REQUEST['users'] );
	}

	public function test_bulk_request_ignores_other_actions(): void {
		Functions\when( 'is_network_admin' )->justReturn( false );
		$_REQUEST['action'] = 'delete';

		Functions\expect( 'check_admin_referer' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();

		$admin = new Admin();
		$admin->handle_bulk_revoke_request();

		unset( $_REQUEST['action'] );
	}

	/**
	 * load-users.php also fires for network/users.php ($pagenow is rewritten
	 * to users.php there), where the list-table nonce is bulk-users-network —
	 * the site-scoped handler must bail explicitly rather than wp_die a
	 * legitimate network operator.
	 */
	public function test_bulk_request_bails_in_network_admin(): void {
		Functions\when( 'is_network_admin' )->justReturn( true );
		$_REQUEST['action'] = Admin::BULK_REVOKE_SESSIONS_ACTION;
		$_REQUEST['users']  = array( '9' );

		Functions\expect( 'check_admin_referer' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();

		$admin = new Admin();
		$admin->handle_bulk_revoke_request();

		unset( $_REQUEST['action'], $_REQUEST['users'] );
	}

	/**
	 * Mirrors WP_List_Table::current_action(): when filter_action is set the
	 * Filter button won the submit and no bulk action runs.
	 */
	public function test_bulk_request_bails_when_filter_action_present(): void {
		Functions\when( 'is_network_admin' )->justReturn( false );
		$_REQUEST['action']        = Admin::BULK_REVOKE_SESSIONS_ACTION;
		$_REQUEST['filter_action'] = 'Filter';
		$_REQUEST['users']         = array( '9' );

		Functions\expect( 'check_admin_referer' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();

		$admin = new Admin();
		$admin->handle_bulk_revoke_request();

		unset( $_REQUEST['action'], $_REQUEST['filter_action'], $_REQUEST['users'] );
	}

	/**
	 * Mirrors WP_Users_List_Table::current_action(): a role-change submit
	 * (changeit) takes precedence over whatever sits in the bulk-action
	 * dropdown, so core's promote flow must win over a stale revoke
	 * selection.
	 */
	public function test_bulk_request_bails_when_role_change_submitted(): void {
		Functions\when( 'is_network_admin' )->justReturn( false );
		$_REQUEST['action']   = Admin::BULK_REVOKE_SESSIONS_ACTION;
		$_REQUEST['changeit'] = 'Change';
		$_REQUEST['users']    = array( '9' );

		Functions\expect( 'check_admin_referer' )->never();
		Functions\expect( 'wp_safe_redirect' )->never();
		Functions\expect( 'delete_user_meta' )->never();

		$admin = new Admin();
		$admin->handle_bulk_revoke_request();

		unset( $_REQUEST['action'], $_REQUEST['changeit'], $_REQUEST['users'] );
	}

	public function test_bulk_request_empty_selection_falls_through_after_nonce(): void {
		Functions\when( 'is_network_admin' )->justReturn( false );
		$_REQUEST['action'] = Admin::BULK_REVOKE_SESSIONS_ACTION;

		Functions\expect( 'check_admin_referer' )
			->once()
			->with( 'bulk-users' )
			->andReturn( 1 );
		Functions\expect( 'wp_safe_redirect' )->never();
		Functions\expect( 'wp_sudo_can' )->never();

		$admin = new Admin();
		$admin->handle_bulk_revoke_request();

		unset( $_REQUEST['action'] );
	}

	/**
	 * When the request carries no referer, the interceptor falls back to
	 * admin_url('users.php') as the sendback base so the operator still
	 * lands on the Users list with the result notice.
	 */
	public function test_bulk_request_falls_back_to_users_url_without_referer(): void {
		Functions\when( 'is_network_admin' )->justReturn( false );
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.com/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'wp_sudo_can' )->justReturn( false ); // Shortest guard path: no_cap.
		Functions\when( 'get_current_user_id' )->justReturn( 2 );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( \Mockery::on( static function ( string $url ): bool {
				return 0 === strpos( $url, 'https://example.com/wp-admin/users.php' )
					&& false !== strpos( $url, 'wp_sudo_revoke_result=no_cap' );
			} ) )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$_REQUEST['action'] = Admin::BULK_REVOKE_SESSIONS_ACTION;
		$_REQUEST['users']  = array( '9' );

		$admin = new Admin();

		try {
			$admin->handle_bulk_revoke_request();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_REQUEST['action'], $_REQUEST['users'] );
	}

	public function test_bulk_request_success_delegates_and_redirects_with_result(): void {
		Functions\when( 'is_network_admin' )->justReturn( false );
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_get_referer' )->justReturn( 'https://example.com/wp-admin/users.php?sudo_active=1' );
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session( 2 );
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key ) {
				if ( \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return time() + 600;
				}
				if ( \WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key && 2 === $uid ) {
					return hash( 'sha256', 'test-sudo-token' );
				}
				return '';
			}
		);
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$_REQUEST['action'] = Admin::BULK_REVOKE_SESSIONS_ACTION;
		$_REQUEST['users']  = array( '9' );

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->with( \Mockery::on( static function ( string $url ): bool {
				return false !== strpos( $url, 'wp_sudo_revoke_result=success' )
					&& false !== strpos( $url, 'sudo_active=1' );
			} ) )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$admin = new Admin();

		try {
			$admin->handle_bulk_revoke_request();
			$this->fail( 'Expected redirect short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		unset( $_REQUEST['action'], $_REQUEST['users'] );
		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Multisite scope guard: submitted IDs that are not members of the
	 * current site are skipped — a per-site operator cannot revoke a
	 * network user's global session by forging users[] (Codex P2).
	 */
	public function test_handle_bulk_revoke_skips_targets_not_member_of_current_site(): void {
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session( 2 );
		Functions\when( 'is_user_member_of_blog' )->alias(
			static fn( int $uid ): bool => 11 === $uid
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key ) {
				if ( \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return time() + 600; // Both live network-wide…
				}
				if ( \WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key && 2 === $uid ) {
					return hash( 'sha256', 'test-sudo-token' );
				}
				return '';
			}
		);
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		// …but only member 11 is revoked; forged non-member 9 is skipped.
		Actions\expectDone( 'wp_sudo_session_revoked' )
			->once()
			->with( 11, 2, 'users_list_bulk_action', 1 );

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions( 'https://example.com/wp-admin/users.php', Admin::BULK_REVOKE_SESSIONS_ACTION, array( 9, 11 ) );

		$this->assertStringContainsString( 'wp_sudo_revoke_result=success', $result );
		$this->assertStringContainsString( 'wp_sudo_revoke_count=1', $result );

		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * Cross-site liveness oracle guard: the row-action core must reject a
	 * non-member target BEFORE consulting session liveness, so an operator
	 * on site A cannot enumerate whether a network user's sudo session is
	 * live via the target_expired/target_not_member outcome split.
	 */
	public function test_revoke_session_core_rejects_non_member_before_liveness_or_rate(): void {
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'is_user_member_of_blog' )->justReturn( false );

		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $this->invoke_revoke_session_core( $admin, 9, 2, 'users_list_row_action' );

		$this->assertSame( 'target_not_member', $result['outcome'] );
	}

	public function test_bulk_revoke_dropdown_entry_requires_cap(): void {
		Functions\when( '__' )->returnArg();

		Functions\when( 'wp_sudo_can' )->justReturn( false );
		$admin = new Admin();
		$this->assertArrayNotHasKey(
			Admin::BULK_REVOKE_SESSIONS_ACTION,
			$admin->register_bulk_revoke_action( array( 'delete' => 'Delete' ) )
		);
	}

	public function test_bulk_revoke_dropdown_entry_present_for_cap_holder_regardless_of_count(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_sudo_can' )->justReturn( true );

		// The entry must NOT consult the 30s-stale active count — no transient reads.
		Functions\expect( 'get_transient' )->never();

		$admin   = new Admin();
		$actions = $admin->register_bulk_revoke_action( array( 'delete' => 'Delete' ) );

		$this->assertSame( 'Revoke sudo sessions', $actions[ Admin::BULK_REVOKE_SESSIONS_ACTION ] );
		$this->assertSame( 'Delete', $actions['delete'] ); // Existing entries preserved.
	}

	public function test_handle_bulk_revoke_ignores_other_actions(): void {
		Functions\expect( 'wp_sudo_can' )->never();
		Functions\expect( 'get_transient' )->never();

		$admin    = new Admin();
		$sendback = 'https://example.com/wp-admin/users.php?sudo_active=1';

		$this->assertSame(
			$sendback,
			$admin->handle_bulk_revoke_sessions( $sendback, 'delete', array( 9 ) )
		);
	}

	public function test_handle_bulk_revoke_blocked_when_operator_lacks_cap(): void {
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );

		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions( 'https://example.com/wp-admin/users.php', Admin::BULK_REVOKE_SESSIONS_ACTION, array( 9 ) );

		$this->assertStringContainsString( 'wp_sudo_revoke_result=no_cap', $result );
	}

	public function test_handle_bulk_revoke_blocked_when_operator_has_no_active_session(): void {
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		// Operator's own expiry is in the past -> is_active(2) false.
		Functions\when( 'get_user_meta' )->justReturn( time() - 60 );

		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions( 'https://example.com/wp-admin/users.php', Admin::BULK_REVOKE_SESSIONS_ACTION, array( 9 ) );

		$this->assertStringContainsString( 'wp_sudo_revoke_result=no-operator-session', $result );
	}

	/**
	 * Security regression guard (bulk): the batch must require the operator's
	 * token-bound sudo session (is_active), not merely a future expiry
	 * timestamp (is_session_live). A live expiry with no valid request token
	 * — e.g. a stolen auth cookie, or a second session without its own sudo —
	 * must be blocked before any session is revoked.
	 */
	public function test_handle_bulk_revoke_blocked_when_operator_session_lacks_token_binding(): void {
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		// Future expiry but no stored token hash -> verify_token()/is_active(2) false.
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $user_id parity with get_user_meta signature.
				if ( \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return time() + 600;
				}
				return '';
			}
		);

		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions( 'https://example.com/wp-admin/users.php', Admin::BULK_REVOKE_SESSIONS_ACTION, array( 9 ) );

		$this->assertStringContainsString( 'wp_sudo_revoke_result=no-operator-session', $result );
	}

	public function test_handle_bulk_revoke_blocked_when_rate_limited_without_consuming_slot(): void {
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		$this->mock_active_sudo_session( 2 );
		Functions\when( 'get_transient' )->justReturn( 10 ); // At REVOKE_RATE_LIMIT.

		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		Functions\expect( 'do_action' )->never();

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions( 'https://example.com/wp-admin/users.php', Admin::BULK_REVOKE_SESSIONS_ACTION, array( 9 ) );

		$this->assertStringContainsString( 'wp_sudo_revoke_result=rate_limited', $result );
	}

	/**
	 * The security contract inherited from the old revoke-all path: a batch
	 * consumes exactly ONE rate slot regardless of size, skips the operator's
	 * own row, and fires the audit hook once per revoked user with the
	 * bulk reason tag.
	 */
	public function test_handle_bulk_revoke_consumes_one_slot_skips_self_and_fires_hook_per_user(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		// Operator (2) token-bound active (cookie + hash from the helper) …
		$this->mock_active_sudo_session( 2 );
		// … and the selected targets (9, 11) hold live sessions too.
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $uid, string $key ) {
				if ( \WP_Sudo\Sudo_Session::META_KEY === $key ) {
					return time() + 600;
				}
				if ( \WP_Sudo\Sudo_Session::TOKEN_META_KEY === $key && 2 === $uid ) {
					return hash( 'sha256', 'test-sudo-token' );
				}
				return '';
			}
		);
		Functions\when( 'get_transient' )->justReturn( 3 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'delete_user_meta' )->justReturn( true );
		Functions\when( 'setcookie' )->justReturn( true );
		Functions\when( 'headers_sent' )->justReturn( true );

		// Exactly ONE rate slot for the whole batch.
		Functions\expect( 'set_transient' )
			->once()
			->with( '_wp_sudo_revoke_count_2', 4, \Mockery::type( 'int' ) );

		Actions\expectDone( 'wp_sudo_session_revoked' )
			->once()
			->with( 9, 2, 'users_list_bulk_action', 1 );
		Actions\expectDone( 'wp_sudo_session_revoked' )
			->once()
			->with( 11, 2, 'users_list_bulk_action', 1 );

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions(
			'https://example.com/wp-admin/users.php?sudo_active=1&paged=2',
			Admin::BULK_REVOKE_SESSIONS_ACTION,
			array( 9, 2, 11 ) // Operator 2 selected among targets.
		);

		$this->assertStringContainsString( 'wp_sudo_revoke_result=success', $result );
		$this->assertStringContainsString( 'wp_sudo_revoke_count=2', $result );
		$this->assertStringContainsString( 'wp_sudo_revoke_skipped_self=1', $result );
		// Sendback filter context preserved (objection 6b): filter + pagination survive.
		$this->assertStringContainsString( 'sudo_active=1', $result );
		$this->assertStringContainsString( 'paged=2', $result );
	}

	public function test_handle_bulk_revoke_self_only_selection_returns_self_target(): void {
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		$this->mock_active_sudo_session( 2 );
		Functions\when( 'get_transient' )->justReturn( 0 );
		// The batch slot is consumed up front, even when nothing ends up
		// revoked (same accounting as the old drained-set path).
		Functions\expect( 'set_transient' )
			->once()
			->with( '_wp_sudo_revoke_count_2', 1, \Mockery::type( 'int' ) );

		Functions\expect( 'delete_user_meta' )->never();
		Actions\expectDone( 'wp_sudo_session_revoked' )->never();

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions( 'https://example.com/wp-admin/users.php', Admin::BULK_REVOKE_SESSIONS_ACTION, array( 2 ) );

		$this->assertStringContainsString( 'wp_sudo_revoke_result=self_target', $result );

		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] );
	}

	public function test_handle_bulk_revoke_none_live_returns_distinct_code(): void {
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		$this->stub_bulk_sendback_url_fns();
		Functions\when( 'wp_sudo_can' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		// Operator (2) token-bound active; targets 9/11 have no live session
		// (the helper's meta stub returns '' for every other user).
		$this->mock_active_sudo_session( 2 );
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\expect( 'delete_user_meta' )->never();
		Actions\expectDone( 'wp_sudo_session_revoked' )->never();

		$admin  = new Admin();
		$result = $admin->handle_bulk_revoke_sessions( 'https://example.com/wp-admin/users.php', Admin::BULK_REVOKE_SESSIONS_ACTION, array( 9, 11 ) );

		$this->assertStringContainsString( 'wp_sudo_revoke_result=bulk_none_live', $result );

		unset( $_COOKIE[ \WP_Sudo\Sudo_Session::TOKEN_COOKIE ] );
	}

	/**
	 * The "Sudo Active (N)" badge count transient must be deleted for the
	 * current site when a session starts or ends.
	 */
	public function test_flush_sudo_active_count_cache_deletes_site_scoped_transient(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 7 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'wp_sudo_active_count_7' );

		Admin::flush_sudo_active_count_cache();
	}

	/**
	 * Stub add_query_arg/remove_query_arg for bulk sendback tests.
	 *
	 * @return void
	 */
	private function stub_bulk_sendback_url_fns(): void {
		Functions\when( 'remove_query_arg' )->alias(
			static function ( $keys, string $url ): string {
				foreach ( (array) $keys as $key ) {
					$url = preg_replace( '/([?&])' . preg_quote( $key, '/' ) . '=[^&]*(&|$)/', '$1', $url );
				}
				return rtrim( $url, '?&' );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, ?string $url = null ) {
				if ( ! is_array( $args ) ) {
					// add_query_arg( $key, $value, $url ) form.
					$key   = $args;
					$value = func_get_arg( 1 );
					$url   = func_get_arg( 2 );
					$args  = array( $key => $value );
				}
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
			}
		);
	}

	// -----------------------------------------------------------------
	// select_revoke_result_notice() / render_revoke_result_notice() (REVK-05)
	// -----------------------------------------------------------------

	/**
	 * Data provider mapping every result code a revoke handler can emit to
	 * its expected wp_admin_notice() type and a distinct, actionable message
	 * substring. self_target and rate_limited are included for completeness
	 * (REVK-05 "never a silent no-op" applies to every produced code), while
	 * no_cap, no-operator-session, and target_expired are the three REVK-05
	 * blocked-path messages.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function provide_revoke_result_codes(): array {
		return array(
			'no_cap'               => array( 'no_cap', 'error', 'permission' ),
			'no-operator-session'  => array( 'no-operator-session', 'error', 'Start a sudo session' ),
			'self_target'          => array( 'self_target', 'error', 'own session' ),
			'target_expired'       => array( 'target_expired', 'error', 'no longer has an active sudo session' ),
			'rate_limited'         => array( 'rate_limited', 'error', 'Rate limit' ),
			'target_not_member'    => array( 'target_not_member', 'error', 'not a member of this site' ),
			'success'              => array( 'success', 'success', 'revoked' ),
			'bulk_none_live'       => array( 'bulk_none_live', 'warning', 'None of the selected users' ),
		);
	}

	/**
	 * A bulk success that skipped the operator's own row must say so in the
	 * same notice, so the self-protection is visible rather than silent.
	 */
	public function test_select_revoke_result_notice_success_mentions_skipped_self(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'select_revoke_result_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$notice = $method->invoke( $admin, 'success', 2, true );

		$this->assertIsArray( $notice );
		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringContainsString( 'revoked', $notice['message'] );
		$this->assertStringContainsString( 'own session was skipped', $notice['message'] );

		// Without the flag the fragment must be absent.
		$plain = $method->invoke( $admin, 'success', 2, false );
		$this->assertStringNotContainsString( 'own session was skipped', $plain['message'] );
	}

	/**
	 * @dataProvider provide_revoke_result_codes
	 */
	public function test_select_revoke_result_notice_maps_every_code_to_a_distinct_message( string $result_code, string $expected_type, string $expected_substring ): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'select_revoke_result_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$notice = $method->invoke( $admin, $result_code, 3 );

		$this->assertIsArray( $notice );
		$this->assertSame( $expected_type, $notice['type'] );
		$this->assertStringContainsString( $expected_substring, $notice['message'] );
	}

	/**
	 * REVK-05: the three blocked-path messages (no_cap, no-operator-session,
	 * target_expired) must all be textually distinct from one another and
	 * from the success message — a shared generic string would fail this
	 * even though each individually "maps to a message".
	 */
	public function test_select_revoke_result_notice_blocked_path_messages_are_distinct(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'select_revoke_result_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$no_cap         = $method->invoke( $admin, 'no_cap', 0 )['message'];
		$no_operator    = $method->invoke( $admin, 'no-operator-session', 0 )['message'];
		$target_expired = $method->invoke( $admin, 'target_expired', 0 )['message'];
		$success        = $method->invoke( $admin, 'success', 0 )['message'];

		$messages = array( $no_cap, $no_operator, $target_expired, $success );
		$this->assertSame( $messages, array_unique( $messages ), 'Every blocked-path + success message must be textually distinct.' );
	}

	/**
	 * Success on a single-user revoke (count not meaningful) still returns a
	 * distinct success message without a stray count reference.
	 */
	public function test_select_revoke_result_notice_success_without_count(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'select_revoke_result_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$notice = $method->invoke( $admin, 'success', 0 );

		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringContainsString( 'revoked', $notice['message'] );
	}

	/**
	 * Success on a bulk revocation includes the exact count in the message
	 * so the operator sees how many sessions were actually revoked.
	 */
	public function test_select_revoke_result_notice_success_includes_count_for_bulk_revocation(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'select_revoke_result_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$notice = $method->invoke( $admin, 'success', 5 );

		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringContainsString( '5', $notice['message'] );
	}

	/**
	 * Unknown/absent result codes select no notice at all (REVK-05: never a
	 * silent no-op applies to KNOWN outcomes; an unrecognized/forged code
	 * must not fabricate a message).
	 */
	public function test_select_revoke_result_notice_returns_null_for_unknown_code(): void {
		Functions\when( '__' )->returnArg();

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'select_revoke_result_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$this->assertNull( $method->invoke( $admin, 'not-a-real-code', 0 ) );
		$this->assertNull( $method->invoke( $admin, '', 0 ) );
	}

	/**
	 * render_revoke_result_notice() only renders on the Users-list screen
	 * ($GLOBALS['pagenow'] === 'users.php') — never leaking the notice onto
	 * unrelated admin screens the redirect never targets.
	 */
	public function test_render_revoke_result_notice_bails_off_users_screen(): void {
		$GLOBALS['pagenow'] = 'edit.php';
		$_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] = 'success';

		Functions\expect( 'wp_admin_notice' )->never();

		$admin = new Admin();
		$admin->render_revoke_result_notice();

		unset( $_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] );
	}

	/**
	 * No result code present on users.php: nothing renders.
	 */
	public function test_render_revoke_result_notice_bails_when_no_result_code(): void {
		$GLOBALS['pagenow'] = 'users.php';
		unset( $_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] );

		Functions\expect( 'wp_admin_notice' )->never();

		$admin = new Admin();
		$admin->render_revoke_result_notice();
	}

	/**
	 * An unrecognized result code on users.php renders nothing (REVK-05
	 * "never a silent no-op" governs KNOWN outcomes; forged/garbage query
	 * values must not produce a fabricated notice).
	 */
	public function test_render_revoke_result_notice_bails_on_unknown_code(): void {
		$GLOBALS['pagenow'] = 'users.php';
		$_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] = 'bogus-code';

		Functions\expect( 'wp_admin_notice' )->never();

		$admin = new Admin();
		$admin->render_revoke_result_notice();

		unset( $_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] );
	}

	/**
	 * A recognized blocked-path code on users.php calls wp_admin_notice()
	 * with the error type, is-dismissible, and an assertive aria-live/role
	 * pairing so assistive tech announces it promptly.
	 */
	public function test_render_revoke_result_notice_renders_error_notice_for_blocked_path(): void {
		$GLOBALS['pagenow'] = 'users.php';
		$_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] = 'no-operator-session';

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $val ) => abs( (int) $val ) );

		Functions\expect( 'wp_admin_notice' )
			->once()
			->with(
				\Mockery::on( static fn( string $message ): bool => false !== strpos( $message, 'Start a sudo session' ) ),
				\Mockery::on( static function ( array $args ): bool {
					return 'error' === ( $args['type'] ?? '' )
						&& true === ( $args['dismissible'] ?? false )
						&& isset( $args['attributes']['role'], $args['attributes']['aria-live'] )
						&& 'alert' === $args['attributes']['role']
						&& 'assertive' === $args['attributes']['aria-live'];
				} )
			);

		$admin = new Admin();
		$admin->render_revoke_result_notice();

		unset( $_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] );
	}

	/**
	 * A success code on users.php calls wp_admin_notice() with the success
	 * type, is-dismissible, and a polite status role/aria-live pairing, and
	 * uses the revoke-count query arg when present (bulk revocation).
	 */
	public function test_render_revoke_result_notice_renders_success_notice_with_count(): void {
		$GLOBALS['pagenow'] = 'users.php';
		$_GET[ Admin::REVOKE_RESULT_QUERY_ARG ] = 'success';
		$_GET[ Admin::REVOKE_COUNT_QUERY_ARG ]  = '4';

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $val ) => abs( (int) $val ) );

		Functions\expect( 'wp_admin_notice' )
			->once()
			->with(
				\Mockery::on( static fn( string $message ): bool => false !== strpos( $message, '4' ) ),
				\Mockery::on( static function ( array $args ): bool {
					return 'success' === ( $args['type'] ?? '' )
						&& true === ( $args['dismissible'] ?? false )
						&& isset( $args['attributes']['role'], $args['attributes']['aria-live'] )
						&& 'status' === $args['attributes']['role']
						&& 'polite' === $args['attributes']['aria-live'];
				} )
			);

		$admin = new Admin();
		$admin->render_revoke_result_notice();

		unset( $_GET[ Admin::REVOKE_RESULT_QUERY_ARG ], $_GET[ Admin::REVOKE_COUNT_QUERY_ARG ] );
	}

	/**
	 * register() hooks render_revoke_result_notice() to admin_notices so the
	 * post-redirect notice actually renders on the next page load.
	 */
	public function test_register_hooks_render_revoke_result_notice_to_admin_notices(): void {
		Actions\expectAdded( 'admin_notices' )
			->once()
			->withArgs(
				static function ( $callback ) {
					return is_array( $callback ) && 'render_revoke_result_notice' === $callback[1];
				}
			);

		$admin = new Admin();
		$admin->register();
	}

	// -----------------------------------------------------------------
	// render_drift_detection_panel()
	// -----------------------------------------------------------------

	/**
	 * The drift filter must use the raw stored capability (allcaps), so a
	 * manage_options-holder WITHOUT a stored manage_wp_sudo primitive is listed
	 * and a holder (role- or directly-granted) is excluded. The recovery-mode
	 * remap immunity is proven at the integration level (real map_meta_cap);
	 * see tests/Integration — the unit WP_User stub has no map_meta_cap.
	 */
	public function test_drift_panel_lists_by_raw_stored_capability(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'is_multisite' )->justReturn( false );

		$drifted        = new \WP_User( 2 );
		$drifted->display_name = 'Drifted Editor';
		$drifted->user_login   = 'drifted';
		$drifted->allcaps      = array( 'manage_options' => true ); // no manage_wp_sudo

		$holder         = new \WP_User( 3 );
		$holder->display_name  = 'Real Manager';
		$holder->user_login    = 'manager';
		$holder->allcaps       = array( 'manage_options' => true, 'manage_wp_sudo' => true );

		Functions\when( 'get_users' )->justReturn( array( $drifted, $holder ) );

		$admin = new Admin();
		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_drift_detection_panel' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin, 'test-nonce' );
		$output = ob_get_clean();

		// Drifted user (manage_options, no stored manage_wp_sudo) is listed.
		$this->assertStringContainsString( 'Drifted Editor', $output );
		$this->assertStringContainsString( 'drifted', $output );
		// Holder (stored manage_wp_sudo) is excluded.
		$this->assertStringNotContainsString( 'Real Manager', $output );
	}

	/**
	 * GCOV-01: on single-site the body copy must keep naming manage_options —
	 * the wording fix is context-aware, not a blanket rename.
	 */
	public function test_drift_panel_names_manage_options_on_single_site(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'is_multisite' )->justReturn( false );

		$drifted               = new \WP_User( 2 );
		$drifted->display_name = 'Drifted Editor';
		$drifted->user_login   = 'drifted';
		$drifted->allcaps      = array( 'manage_options' => true );
		Functions\when( 'get_users' )->justReturn( array( $drifted ) );

		$admin = new Admin();
		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_drift_detection_panel' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin, 'test-nonce' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'hold manage_options but not manage_wp_sudo', $output );
		$this->assertStringNotContainsString( 'manage_network_options', $output );
	}

	/**
	 * GCOV-01: on multisite the detection capability is manage_network_options,
	 * and the body copy must name that capability, not manage_options.
	 */
	public function test_drift_panel_names_network_capability_on_multisite(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );

		$drifted               = new \WP_User( 2 );
		$drifted->display_name = 'Network Operator';
		$drifted->user_login   = 'netop';
		$drifted->allcaps      = array( 'manage_network_options' => true );
		Functions\when( 'get_users' )->justReturn( array( $drifted ) );

		$admin = new Admin();
		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_drift_detection_panel' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin, 'test-nonce' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'hold manage_network_options but not manage_wp_sudo', $output );
		$this->assertStringContainsString( 'Network Operator', $output );
	}

	/**
	 * GCOV-02: a multisite super admin has effective access to Sudo settings
	 * via the wp_sudo_can() short-circuit regardless of stored caps, so the
	 * panel must not list them as "cannot access" — while a candidate who is
	 * NOT a super admin (a stored manage_network_options grant, e.g. via a
	 * plugin role) and lacks the raw manage_wp_sudo cap must STILL be listed.
	 * The second half pins the regression a wholesale switch to wp_sudo_can()
	 * would cause.
	 */
	public function test_drift_panel_excludes_super_admin_but_lists_drifted_network_operator(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->alias( static fn( int $user_id ): bool => 3 === $user_id );

		$super               = new \WP_User( 3 );
		$super->display_name = 'Super Admin';
		$super->user_login   = 'superadmin';
		$super->allcaps      = array( 'manage_network_options' => true ); // no raw manage_wp_sudo

		$drifted               = new \WP_User( 4 );
		$drifted->display_name = 'Network Operator';
		$drifted->user_login   = 'netop';
		$drifted->allcaps      = array( 'manage_network_options' => true ); // no raw manage_wp_sudo

		Functions\when( 'get_users' )->justReturn( array( $super, $drifted ) );

		$admin = new Admin();
		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_drift_detection_panel' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin, 'test-nonce' );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Super Admin', $output, 'Super admin has effective access via wp_sudo_can() and must not be listed' );
		$this->assertStringNotContainsString( 'superadmin', $output );
		$this->assertStringContainsString( 'Network Operator', $output, 'Genuinely drifted non-super-admin network operator must still be listed' );
	}

	public function test_drift_panel_renders_nothing_when_no_drift(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'is_multisite' )->justReturn( false );

		$holder            = new \WP_User( 3 );
		$holder->allcaps   = array( 'manage_options' => true, 'manage_wp_sudo' => true );
		Functions\when( 'get_users' )->justReturn( array( $holder ) );

		$admin = new Admin();
		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_drift_detection_panel' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin, 'test-nonce' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	// -----------------------------------------------------------------
	// render_recovery_mode_notice() / maybe_record_recovery_mode_usage()
	// -----------------------------------------------------------------

	public function test_recovery_mode_notice_renders_warning_when_active(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );

		$admin = new Admin();

		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_recovery_mode_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'WP_SUDO_RECOVERY_MODE', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
		// Permanent notice: must NOT be dismissible.
		$this->assertStringNotContainsString( 'is-dismissible', $output );

		// Accuracy: the break-glass gate is capability-based, not role-based, and
		// keys on manage_network_options under multisite. The warning must not
		// understate the blast radius by implying only "administrators" qualify.
		$this->assertStringContainsString( 'manage_options', $output );
		$this->assertStringContainsString( 'manage_network_options', $output );
		$this->assertStringContainsString( 'regardless of role', $output );
		$this->assertStringNotContainsString( 'any administrator who holds', $output );
	}

	public function test_recovery_mode_notice_renders_nothing_when_inactive(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		$admin = new Admin();

		ob_start();
		$method = new \ReflectionMethod( Admin::class, 'render_recovery_mode_notice' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_recovery_mode_usage_fires_audit_hook_when_active(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		Actions\expectDone( 'wp_sudo_recovery_mode_active' )
			->once()
			->with( 7 );

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'maybe_record_recovery_mode_usage' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin );
	}

	public function test_recovery_mode_usage_does_not_fire_when_inactive(): void {
		Functions\when( 'wp_sudo_is_recovery_mode' )->justReturn( false );

		Actions\expectDone( 'wp_sudo_recovery_mode_active' )->never();

		$admin  = new Admin();
		$method = new \ReflectionMethod( Admin::class, 'maybe_record_recovery_mode_usage' );
		@$method->setAccessible( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$method->invoke( $admin );
	}

	// -----------------------------------------------------------------
	// MIG-05: manage_wp_sudo capability routing (regression lock)
	// -----------------------------------------------------------------

	/**
	 * MIG-05 regression lock: add_settings_page() must register the settings
	 * page with capability 'manage_wp_sudo', never bare 'manage_options'.
	 *
	 * This encodes the governance-first routing requirement: admin surfaces
	 * route through the dedicated sudo cap, not the WordPress primitive.
	 *
	 * @since 4.0.0 (MIG-05 regression lock)
	 */
	public function test_mig05_single_site_settings_page_routes_through_manage_wp_sudo(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_options_page' )
			->once()
			->with(
				\Mockery::type( 'string' ),
				\Mockery::type( 'string' ),
				'manage_wp_sudo',
				Admin::PAGE_SLUG,
				\Mockery::type( 'array' )
			)
			->andReturn( false );

		$admin = new Admin();
		$admin->add_settings_page();
	}

	/**
	 * MIG-05 regression lock: add_network_settings_page() must register the
	 * network settings page with capability 'manage_wp_sudo', never bare
	 * 'manage_options' or 'manage_network_options'.
	 *
	 * @since 4.0.0 (MIG-05 regression lock)
	 */
	public function test_mig05_network_settings_page_routes_through_manage_wp_sudo(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'settings.php',
				\Mockery::type( 'string' ),
				\Mockery::type( 'string' ),
				'manage_wp_sudo',
				Admin::PAGE_SLUG,
				\Mockery::type( 'array' )
			)
			->andReturn( false );

		$admin = new Admin();
		$admin->add_network_settings_page();
	}
}
