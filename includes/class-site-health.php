<?php
/**
 * Site Health integration — diagnostic tests for WP Sudo.
 *
 * Registers three tests in the WordPress Site Health panel:
 *
 * 1. **MU-plugin status** — whether the optional mu-plugin is installed.
 * 2. **Session audit** — whether any users have stale sudo tokens.
 * 3. **Entry-point policy review** — whether non-interactive surfaces
 *    (REST App Passwords, WP-CLI, Cron, XML-RPC, and WPGraphQL when active)
 *    use the recommended "limited" or "disabled" policy (warns on "unrestricted").
 * 4. **Gated action integrity** — whether filtered rules still include the
 *    built-in protection set.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Site_Health
 *
 * @since 2.1.0
 */
class Site_Health {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
	}

	/**
	 * Register WP Sudo tests with Site Health.
	 *
	 * @param array<string, mixed> $tests Existing tests.
	 * @return array<string, mixed>
	 */
	public function register_tests( array $tests ): array {
		$tests['direct']['wp_sudo_mu_plugin'] = array(
			'label' => __( 'Sudo MU-Plugin', 'wp-sudo' ),
			'test'  => array( $this, 'test_mu_plugin_status' ),
		);

		$tests['direct']['wp_sudo_policies'] = array(
			'label' => __( 'Sudo Entry Point Policies', 'wp-sudo' ),
			'test'  => array( $this, 'test_policy_review' ),
		);

		$tests['direct']['wp_sudo_stale_sessions'] = array(
			'label' => __( 'Sudo Stale Sessions', 'wp-sudo' ),
			'test'  => array( $this, 'test_stale_sessions' ),
		);

		$tests['direct']['wp_sudo_gated_action_integrity'] = array(
			'label' => __( 'Sudo Gated Action Integrity', 'wp-sudo' ),
			'test'  => array( $this, 'test_gated_action_integrity' ),
		);

		// Role/capability lockdown audit — only when the operator has opted in by
		// configuring a manifest (WP_SUDO_ROLE_MANIFEST). Inert otherwise (#179).
		if ( Role_Manifest::is_enabled() ) {
			$tests['direct']['wp_sudo_role_manifest'] = array(
				'label' => __( 'Sudo Role/Capability Manifest', 'wp-sudo' ),
				'test'  => array( $this, 'test_role_manifest' ),
			);
		}

		return $tests;
	}

	/**
	 * Test: role/capability manifest drift (#179).
	 *
	 * Compares current privileged state to the trusted manifest without firing the
	 * audit event (Site Health is a read). Reports "unreadable" (misconfiguration),
	 * "drift" (security), or "clean".
	 *
	 * @return array<string, mixed>
	 */
	public function test_role_manifest(): array {
		$manifest = Role_Manifest::load();

		if ( null === $manifest ) {
			return $this->format_role_manifest_result( 'unreadable', array() );
		}

		$report = Role_Audit::diff( $manifest, Role_Audit::collect_current_state( $manifest ) );

		return $this->format_role_manifest_result( $report['has_drift'] ? 'drift' : 'clean', $report );
	}

	/**
	 * Build the Site Health result array for a manifest audit outcome.
	 *
	 * @param string               $kind   One of 'clean', 'drift', 'unreadable'.
	 * @param array<string, mixed> $report Drift report (for 'drift').
	 * @return array<string, mixed>
	 */
	private function format_role_manifest_result( string $kind, array $report ): array {
		$badge = array(
			'label' => __( 'Security', 'wp-sudo' ),
			'color' => 'blue',
		);

		if ( 'unreadable' === $kind ) {
			return array(
				'label'       => __( 'Sudo role manifest is unreadable', 'wp-sudo' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'A role/capability manifest path is configured but the file is missing or invalid. The lockdown audit is inactive until it is regenerated with "wp sudo manifest generate".', 'wp-sudo' ) . '</p>',
				'test'        => 'wp_sudo_role_manifest',
			);
		}

		if ( 'drift' === $kind ) {
			$count = 0;
			foreach ( ( $report['sites'] ?? array() ) as $entry ) {
				$count += count( $entry['administrators'] ?? array() ) + count( $entry['governance'] ?? array() );
			}
			$count += count( $report['network']['super_admins'] ?? array() );
			$roles  = count( $report['roles'] ?? array() );

			return array(
				'label'       => __( 'Sudo detected role/capability drift', 'wp-sudo' ),
				'status'      => 'critical',
				'badge'       => $badge,
				'description' => '<p>' . sprintf(
					/* translators: 1: number of unauthorized principals, 2: number of drifted role definitions */
					esc_html__( 'Stored privileged state has drifted from the trusted manifest: %1$d unauthorized principal(s) and %2$d changed role definition(s). Review with "wp sudo manifest diff", then remediate or re-baseline with "wp sudo manifest generate".', 'wp-sudo' ),
					$count,
					$roles
				) . '</p>',
				'test'        => 'wp_sudo_role_manifest',
			);
		}

		return array(
			'label'       => __( 'Sudo role/capability state matches the manifest', 'wp-sudo' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => '<p>' . esc_html__( 'All trusted administrators, super admins, governance-cap holders, and watched role definitions match the manifest. No drift detected.', 'wp-sudo' ) . '</p>',
			'test'        => 'wp_sudo_role_manifest',
		);
	}

	/**
	 * Test: MU-plugin status.
	 *
	 * Checks whether the optional WP Sudo mu-plugin drop-in is installed
	 * at wp-content/mu-plugins/wp-sudo-gate.php.
	 *
	 * @return array<string, mixed>
	 */
	public function test_mu_plugin_status(): array {
		$mu_installed = defined( 'WP_SUDO_MU_LOADED' );

		if ( $mu_installed ) {
			return array(
				'label'       => __( 'Sudo MU-Plugin is installed', 'wp-sudo' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Security', 'wp-sudo' ),
					'color' => 'blue',
				),
				'description' => '<p>' . __( 'The Sudo MU-Plugin is installed, ensuring gate hooks are registered before any regular plugin loads.', 'wp-sudo' ) . '</p>',
				'test'        => 'wp_sudo_mu_plugin',
			);
		}

		return array(
			'label'       => __( 'Sudo MU-Plugin is not installed', 'wp-sudo' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Security', 'wp-sudo' ),
				'color' => 'orange',
			),
			'description' => '<p>' . __( 'The optional Sudo MU-Plugin is not installed. While the plugin works without it, the MU-Plugin ensures gate hooks are registered before any regular plugin can interfere.', 'wp-sudo' ) . '</p>',
			'actions'     => '<p>' . sprintf(
				/* translators: %s: URL to the Sudo settings page */
				__( 'Install the MU-Plugin with one click from <a href="%s">Settings &rarr; Sudo</a>.', 'wp-sudo' ),
				esc_url( $this->get_settings_url() )
			) . '</p>',
			'test'        => 'wp_sudo_mu_plugin',
		);
	}

	/**
	 * Test: Entry-point policy review.
	 *
	 * Verifies that non-interactive entry points (REST App Passwords, WP-CLI,
	 * Cron, XML-RPC, and WPGraphQL when active) use a secure policy.
	 * "Limited" (default) and "Disabled" are both considered secure.
	 * "Unrestricted" is flagged as a recommendation to tighten.
	 *
	 * WPGraphQL is only included in the check when the plugin is active —
	 * the stored policy value is irrelevant when WPGraphQL is not installed.
	 *
	 * @since 2.1.0
	 * @since 2.2.0 Three-tier model: disabled, limited, unrestricted.
	 * @since 2.5.0 Added WPGraphQL policy.
	 * @since 2.8.0 WPGraphQL policy check is conditional on plugin presence.
	 *
	 * @return array<string, mixed>
	 */
	public function test_policy_review(): array {
		$policy_keys = array(
			Gate::SETTING_REST_APP_PASS_POLICY => __( 'REST API (App Passwords)', 'wp-sudo' ),
			Gate::SETTING_CLI_POLICY           => __( 'WP-CLI', 'wp-sudo' ),
			Gate::SETTING_CRON_POLICY          => __( 'Cron', 'wp-sudo' ),
			Gate::SETTING_XMLRPC_POLICY        => __( 'XML-RPC', 'wp-sudo' ),
		);
		if ( function_exists( 'graphql' ) ) {
			$policy_keys[ Gate::SETTING_WPGRAPHQL_POLICY ] = __( 'WPGraphQL', 'wp-sudo' );
		}

		$unrestricted = array();

		foreach ( $policy_keys as $key => $label ) {
			$value = Admin::get( $key, Gate::POLICY_LIMITED );
			if ( Gate::POLICY_UNRESTRICTED === $value ) {
				$unrestricted[] = $label;
			}
		}

		if ( empty( $unrestricted ) ) {
			return array(
				'label'       => __( 'All Sudo entry point policies are secure', 'wp-sudo' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Security', 'wp-sudo' ),
					'color' => 'blue',
				),
				'description' => '<p>' . __( 'All non-interactive entry points are set to "limited" or "disabled", preventing unrestricted access to gated operations via CLI, Cron, XML-RPC, and Application Passwords.', 'wp-sudo' ) . '</p>',
				'test'        => 'wp_sudo_policies',
			);
		}

		return array(
			'label'       => __( 'Some Sudo entry point policies are unrestricted', 'wp-sudo' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Security', 'wp-sudo' ),
				'color' => 'orange',
			),
			'description' => '<p>' . sprintf(
				/* translators: %s: comma-separated list of unrestricted policy names */
				__( 'The following entry points are set to "unrestricted": %s. Consider using "limited" (blocks only gated actions) or "disabled" (shuts off the entire surface) for better security.', 'wp-sudo' ),
				esc_html( implode( ', ', $unrestricted ) )
			) . '</p>',
			'test'        => 'wp_sudo_policies',
		);
	}

	/**
	 * Test: Gated action registry integrity.
	 *
	 * Warns when a wp_sudo_gated_actions filter removed built-in rules. Built-in
	 * removal is still supported for advanced integrations, but operators should
	 * see when the baseline protection set has been reduced.
	 *
	 * @since 3.1.5
	 *
	 * @return array<string, mixed>
	 */
	public function test_gated_action_integrity(): array {
		$missing = Action_Registry::get_missing_builtin_rule_ids();

		if ( empty( $missing ) ) {
			return array(
				'label'       => __( 'All built-in Sudo gated actions are registered', 'wp-sudo' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Security', 'wp-sudo' ),
					'color' => 'blue',
				),
				'description' => '<p>' . __( 'The filtered gated action registry still includes the built-in Sudo protection set.', 'wp-sudo' ) . '</p>',
				'test'        => 'wp_sudo_gated_action_integrity',
			);
		}

		return array(
			'label'       => __( 'Some built-in Sudo gated actions are not registered', 'wp-sudo' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Security', 'wp-sudo' ),
				'color' => 'orange',
			),
			'description' => '<p>' . sprintf(
				/* translators: %s: comma-separated list of missing built-in gated action IDs */
				__( 'A wp_sudo_gated_actions filter removed these built-in rules: %s. Confirm this is intentional; otherwise, restore the default rules so dangerous actions remain gated.', 'wp-sudo' ),
				esc_html( implode( ', ', $missing ) )
			) . '</p>',
			'test'        => 'wp_sudo_gated_action_integrity',
		);
	}

	/**
	 * Test: Stale sudo sessions.
	 *
	 * Checks for users with expired sudo tokens that were not cleaned up.
	 * This can happen if a session expires while the user is not browsing
	 * (the is_active() cleanup only fires on page load).
	 *
	 * @return array<string, mixed>
	 */
	public function test_stale_sessions(): array {
		$stale_users = $this->find_stale_sessions();

		if ( empty( $stale_users ) ) {
			return array(
				'label'       => __( 'No stale Sudo sessions found', 'wp-sudo' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Security', 'wp-sudo' ),
					'color' => 'blue',
				),
				'description' => '<p>' . __( 'All sudo session tokens are either active or have been cleaned up. No action needed.', 'wp-sudo' ) . '</p>',
				'test'        => 'wp_sudo_stale_sessions',
			);
		}

		$count = count( $stale_users );

		// Clean up stale sessions automatically.
		foreach ( $stale_users as $uid ) {
			delete_user_meta( $uid, Sudo_Session::META_KEY );
			delete_user_meta( $uid, Sudo_Session::TOKEN_META_KEY );
		}

		return array(
			'label'       => sprintf(
				/* translators: %d: number of stale sessions cleaned */
				_n(
					'%d stale Sudo session cleaned up',
					'%d stale Sudo sessions cleaned up',
					$count,
					'wp-sudo'
				),
				$count
			),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'wp-sudo' ),
				'color' => 'blue',
			),
			'description' => '<p>' . sprintf(
				/* translators: %d: number of stale sessions */
				_n(
					'Found and cleaned %d expired sudo session token. This is normal — tokens expire naturally but are only cleaned on the next page load.',
					'Found and cleaned %d expired sudo session tokens. This is normal — tokens expire naturally but are only cleaned on the next page load.',
					$count,
					'wp-sudo'
				),
				$count
			) . '</p>',
			'test'        => 'wp_sudo_stale_sessions',
		);
	}

	/**
	 * Get the URL to the WP Sudo settings page.
	 *
	 * Returns the network admin URL on multisite, site admin URL otherwise.
	 *
	 * @return string
	 */
	private function get_settings_url(): string {
		if ( is_multisite() ) {
			return network_admin_url( 'settings.php?page=' . Admin::PAGE_SLUG );
		}

		return admin_url( 'options-general.php?page=' . Admin::PAGE_SLUG );
	}

	/**
	 * Find users with expired sudo session meta.
	 *
	 * @return int[] User IDs with stale sessions.
	 */
	private function find_stale_sessions(): array {
		$batch_size = 100;
		$offset     = 0;
		$stale      = array();
		$now        = time();

		do {
			$users = get_users(
				array(
					'meta_key'     => Sudo_Session::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'   => '0', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_compare' => '>',
					'fields'       => 'ID',
					'number'       => $batch_size,
					'offset'       => $offset,
				)
			);

			$found = count( $users );

			foreach ( $users as $uid ) {
				$expires = (int) get_user_meta( (int) $uid, Sudo_Session::META_KEY, true );
				if ( $expires > 0 && $expires < $now ) {
					$stale[] = (int) $uid;
				}
			}

			$offset += $batch_size;
		} while ( $found === $batch_size );

		return $stale;
	}
}
