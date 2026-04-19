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
	// sanitize_settings()
	// -----------------------------------------------------------------

	public function test_sanitize_clamps_duration_below_range(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 0 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_clamps_duration_above_range(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 30 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_clamps_negative_duration_to_default(): void {
		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => -5 ) );

		$this->assertSame( 15, $result['session_duration'] );
	}

	public function test_sanitize_accepts_valid_duration(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

		$admin  = new Admin();
		$result = $admin->sanitize_settings( array( 'session_duration' => 10 ) );

		$this->assertSame( 10, $result['session_duration'] );
	}

	public function test_sanitize_normalizes_valid_policy(): void {
		Functions\when( 'absint' )->alias( fn( $val ) => abs( (int) $val ) );

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

		Actions\expectAdded( 'admin_init' )
			->once();

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

	// -----------------------------------------------------------------
	// add_help_tabs()
	// -----------------------------------------------------------------

	public function test_add_help_tabs_registers_twelve_tabs(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$this->assertCount( 12, $screen->get_help_tabs() );
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

		$this->assertContains( 'wp-sudo-how-it-works', $ids );
		$this->assertContains( 'wp-sudo-session-policies', $ids );
		$this->assertContains( 'wp-sudo-policy-presets', $ids );
		$this->assertContains( 'wp-sudo-app-passwords', $ids );
		$this->assertContains( 'wp-sudo-mu-plugin', $ids );
		$this->assertContains( 'wp-sudo-security', $ids );
		$this->assertContains( 'wp-sudo-security-model', $ids );
		$this->assertContains( 'wp-sudo-environment', $ids );
		$this->assertContains( 'wp-sudo-recommended-plugins', $ids );
		$this->assertContains( 'wp-sudo-extending', $ids );
		$this->assertContains( 'wp-sudo-audit-hooks', $ids );
		$this->assertContains( 'wp-sudo-rule-tester', $ids );
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

	public function test_security_tab_mentions_two_factor(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-security']['content'] ?? '';

		$this->assertStringContainsString( 'Two-Factor Authentication', $content );
		$this->assertStringContainsString( 'Two Factor plugin', $content );
	}

	public function test_settings_tab_uses_full_sentences(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-session-policies']['content'] ?? '';

		// Must start with a full sentence, not a fragment.
		$this->assertStringContainsString( 'This setting controls', $content );
		$this->assertStringNotContainsString( 'How long the sudo window', $content );
	}

	public function test_recommended_plugins_tab_lists_complements(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-recommended-plugins']['content'] ?? '';

		$this->assertStringContainsString( 'Two Factor', $content );
		$this->assertStringContainsString( 'WebAuthn Provider', $content );
		$this->assertStringContainsString( 'WP Activity Log', $content );
		$this->assertStringContainsString( 'Stream', $content );
		$this->assertStringContainsString( '10 action hooks', $content );
	}

	public function test_how_it_works_tab_mentions_keyboard_shortcut(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-how-it-works']['content'] ?? '';

		$this->assertStringContainsString( 'Keyboard Shortcut', $content );
		$this->assertStringContainsString( 'Ctrl+Shift+S', $content );
	}

	public function test_settings_tab_covers_mu_plugin_and_multisite(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-mu-plugin']['content'] ?? '';

		$this->assertStringContainsString( 'MU-Plugin', $content );
		$this->assertStringContainsString( 'Multisite', $content );
	}

	public function test_extending_tab_covers_2fa_window_and_third_party(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-extending']['content'] ?? '';

		$this->assertStringContainsString( 'wp_sudo_two_factor_window', $content );
		$this->assertStringContainsString( 'wp_sudo_requires_two_factor', $content );
		$this->assertStringContainsString( 'wp_sudo_render_two_factor_fields', $content );
		$this->assertStringContainsString( 'wp_sudo_validate_two_factor', $content );
		$this->assertStringContainsString( 'bridges/', $content );
	}

	public function test_audit_hooks_tab_mentions_logging_plugins(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-audit-hooks']['content'] ?? '';

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

		$this->assertStringContainsString( 'wp-security-audit-log', $sidebar );
		$this->assertStringContainsString( 'plugins/stream', $sidebar );
		$this->assertStringContainsString( 'two-factor', $sidebar );
	}

	public function test_help_tab_presets_describes_three_presets(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-policy-presets']['content'] ?? '';

		$this->assertStringContainsString( 'Normal', $content );
		$this->assertStringContainsString( 'Incident Lockdown', $content );
		$this->assertStringContainsString( 'Headless Friendly', $content );
		$this->assertStringContainsString( 'Custom', $content );
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

		$this->assertStringContainsString( 'side-effect-free diagnostic', $content );
		$this->assertStringContainsString( 'connectors.update_credentials', $content );
		$this->assertStringContainsString( 'REST Params', $content );
	}

	public function test_session_policies_tab_mentions_connectors(): void {
		$screen = new \WP_Screen();

		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$admin = new Admin();
		$admin->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-session-policies']['content'] ?? '';

		$this->assertStringContainsString( 'Connectors', $content );
		$this->assertStringContainsString( 'AI provider API keys', $content );
		$this->assertStringContainsString( 'connectors.update_credentials', $content );
	}

	// -----------------------------------------------------------------
	// render_settings_page()
	// -----------------------------------------------------------------

	public function test_render_settings_page_includes_introduction(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
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
		$this->assertStringContainsString( '>Settings</a>', $output );
		$this->assertStringContainsString( '>Gated Actions</a>', $output );
		$this->assertStringContainsString( '>Rule Tester</a>', $output );
	}

	public function test_render_settings_page_defaults_to_settings_tab(): void {
		unset( $_GET['tab'] );

		Functions\when( 'current_user_can' )->justReturn( true );
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
		$this->assertStringContainsString( 'See how WP Sudo would evaluate a representative request', $output );
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
		);

		$admin = new Admin( $gate );

		ob_start();
		$admin->render_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Delete plugin', $output );
		$this->assertStringContainsString( 'hard-block', $output );
		$this->assertStringContainsString( 'REST Application Password policy is Limited', $output );

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
		Actions\expectAdded( 'admin_init' )->once();
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
				'manage_network_options',
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
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_network_options' )
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
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_network_options' )
			->andReturn( true );
		Functions\when( 'absint' )->alias( fn( $value ) => abs( (int) $value ) );
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
			->with( \Mockery::on( fn( $url ) => is_string( $url ) && str_contains( $url, 'page=wp-sudo-settings' ) && str_contains( $url, 'updated=true' ) ) )
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

		// WP_SUDO_MU_LOADED is not defined, so render_mu_plugin_status()
		// will show "Not installed" and an install button.
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
	// is_mu_plugin_installed()
	// -----------------------------------------------------------------

	public function test_is_mu_plugin_installed_returns_false_when_file_missing(): void {
		// WP_CONTENT_DIR points to /tmp/fake-wordpress/wp-content
		// which should not contain wp-sudo-gate.php.
		$this->assertFalse( Admin::is_mu_plugin_installed() );
	}

	// -----------------------------------------------------------------
	// rewrite_role_error()
	// -----------------------------------------------------------------

	public function test_rewrite_role_error_skips_without_param(): void {
		unset( $_GET['update'] );

		Functions\expect( 'wp_safe_redirect' )->never();

		$admin = new Admin();
		$admin->rewrite_role_error();
	}

	public function test_rewrite_role_error_skips_other_update_values(): void {
		$_GET['update'] = 'promote';

		Functions\expect( 'wp_safe_redirect' )->never();

		$admin = new Admin();
		$admin->rewrite_role_error();

		unset( $_GET['update'] );
	}

	// -----------------------------------------------------------------
	// render_role_error_notice()
	// -----------------------------------------------------------------

	public function test_render_role_error_notice_skips_without_param(): void {
		unset( $_GET['update'] );

		$admin = new Admin();
		$admin->render_role_error_notice();

		$this->expectOutputString( '' );
	}

	public function test_render_role_error_notice_skips_other_update_values(): void {
		$_GET['update'] = 'promote';

		$admin = new Admin();
		$admin->render_role_error_notice();

		$this->expectOutputString( '' );

		unset( $_GET['update'] );
	}

	public function test_render_role_error_notice_outputs_for_matching_param(): void {
		$_GET['update'] = 'wp_sudo_role_error';

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_get_admin_notice' )->alias( function ( $message, $args ) {
			return '<div class="notice"><p>' . $message . '</p></div>';
		} );
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->expectOutputRegex( '/demote yourself to a role/' );

		$admin = new Admin();
		$admin->render_role_error_notice();

		unset( $_GET['update'] );
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

	// -----------------------------------------------------------------
	// add_help_tabs() — WPGraphQL conditional (v2.7.1)
	// -----------------------------------------------------------------

	/**
	 * "Session & Policies" help tab shows the full WPGraphQL explanation
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
		$content = $tabs['wp-sudo-session-policies']['content'] ?? '';
		$this->assertStringContainsString( 'WPGraphQL works differently', $content );
		$this->assertStringNotContainsString( 'its policy setting appears on this page', $content );
	}

	/**
	 * "Session & Policies" help tab shows the install-prompt note
	 * when WPGraphQL is not active (function_exists('graphql') returns false).
	 */
	public function test_help_tab_shows_wpgraphql_install_note_when_inactive(): void {
		$screen = new \WP_Screen();
		Functions\when( 'get_current_screen' )->justReturn( $screen );
		Functions\when( '__' )->returnArg();
		// No function_exists mock — Brain\Monkey returns null (falsy) by default.

		( new Admin() )->add_help_tabs();

		$tabs    = $screen->get_help_tabs();
		$content = $tabs['wp-sudo-session-policies']['content'] ?? '';
		$this->assertStringContainsString( 'its policy setting appears on this page', $content );
		$this->assertStringNotContainsString( 'WPGraphQL works differently', $content );
	}

	// =================================================================
	// App-password JS i18n keys
	// =================================================================

	public function test_app_password_assets_localizes_i18n_strings(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
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
		$this->assertStringContainsString( '<p class="description">', $output );
		$this->assertStringContainsString( 'recommended baseline', $output );
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
		// Use reflection to call private method (setAccessible is a no-op since PHP 8.1).
		$method = new \ReflectionMethod( Admin::class, 'render_policy_preset_notice' );
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
		$method->invoke( $admin );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'All surfaces are now limited', $output );
		$this->assertStringNotContainsString( ';', $output );
	}
}
