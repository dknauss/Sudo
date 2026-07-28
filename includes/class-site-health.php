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

		$tests['direct']['wp_sudo_recovery_mode'] = array(
			'label' => __( 'Sudo Break-Glass Recovery Mode', 'wp-sudo' ),
			'test'  => array( $this, 'test_recovery_mode' ),
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

		// Clean up stale sessions automatically. The scalar expiry marker is
		// intended to be >= every proof's expiry, so a scalar older than the
		// grace cutoff means all of that user's proofs are past grace too; clear
		// the whole proof record plus any legacy pre-4.9.0 rows.
		//
		// "Intended to be" is deliberate, because that relationship used to be
		// breakable. Sudo_Session::activate() maintained it by reading the scalar
		// back through the object cache, so a stale-low cached read could write a
		// scalar beneath a live proof's expiry — into the database, where this
		// query reads it.
		//
		// Worked example, all in-range: duration 15 min, browser A activates at
		// T0 (proof expires T0+900, scalar T0+900). An operator lowers the
		// duration to 1 min. At T0+10 browser B activates; a stale-low cached
		// read makes activate()'s `$existing > time()` false, so the scalar is
		// written as T0+70 — beneath A's live proof. At T0+200 this sweep's
		// cutoff is T0+80, T0+70 is below it, and A's proof is deleted with ~11
		// minutes of life left. So the failure was fail-closed only while the
		// too-low scalar was still in the future, and destructive once it aged
		// past the cutoff. Past tense throughout: the derivation described below
		// removes the mechanism.
		//
		// That was #354's own symptom reached through the other half of the
		// defect, and the same root cause was additionally fail-OPEN for
		// revoke_all_active_sessions(), which selects `META_KEY > time()` in SQL
		// and silently skipped a user whose sudo was still enforcing. Both are
		// closed by deriving the scalar structurally in Sudo_Session::set_token()
		// from the cache-bypassed merged proof map, rather than from a cached read
		// of the scalar itself — so the worked example above describes the defect
		// this pair of changes removes, not a live hazard. It is kept because the
		// query below is only safe while that derivation holds, and a future
		// change that reintroduced a cached read here would silently re-open it.
		//
		// The set is exactly the session-identity rows. It deliberately omits
		// LOCKOUT_UNTIL_META_KEY, FAILURE_EVENT_META_KEY and
		// THROTTLE_UNTIL_META_KEY: sweeping those would let an expired sudo
		// session silently clear an active rate-limit lockout, turning a
		// housekeeping pass into a lockout reset. The omission is a decision, not
		// an oversight.
		foreach ( $stale_users as $uid ) {
			delete_user_meta( $uid, Sudo_Session::META_KEY );
			delete_user_meta( $uid, Sudo_Session::PROOF_META_KEY );
			delete_user_meta( $uid, Sudo_Session::TOKEN_META_KEY );
			delete_user_meta( $uid, Sudo_Session::SESSION_BIND_META_KEY );
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
	 * Test: break-glass recovery mode is active (#240).
	 *
	 * Recovery mode (WP_SUDO_RECOVERY_MODE) deliberately relaxes the governance
	 * capability model and is meant to be removed as soon as normal access is
	 * restored. A forgotten constant leaves the model weakened with only an
	 * in-admin notice (visible only while on a Sudo screen) to signal it. This
	 * pull-based test surfaces the state as a Site Health critical status.
	 *
	 * The target is reported (login + ID) so the operator can confirm scope; a
	 * scoped-but-unresolvable value (typo'd login/ID) is called out because it
	 * grants nobody. The value appears in the status result only — never in
	 * debug_information (which operators paste into public support forums).
	 *
	 * @return array<string, mixed>
	 */
	public function test_recovery_mode(): array {
		$badge = array(
			'label' => __( 'Security', 'wp-sudo' ),
			'color' => 'red',
		);

		if ( ! wp_sudo_is_recovery_mode() ) {
			return array(
				'label'       => __( 'Break-glass recovery mode is not active', 'wp-sudo' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . __( 'WP Sudo break-glass recovery mode (WP_SUDO_RECOVERY_MODE) is not defined. The governance capability model is fully in force.', 'wp-sudo' ) . '</p>',
				'test'        => 'wp_sudo_recovery_mode',
			);
		}

		if ( wp_sudo_recovery_mode_is_unscoped() ) {
			$scope = __( 'It is unscoped: while it stays defined, any user who still holds administrator authority (network administrator on multisite) regains full Sudo governance access.', 'wp-sudo' );
		} else {
			$target = wp_sudo_recovery_mode_user();

			if ( null === $target ) {
				$scope = __( 'It is scoped to a value that does not resolve to any existing user, so no one is granted access — check WP_SUDO_RECOVERY_MODE for a typo.', 'wp-sudo' );
			} else {
				$user  = get_userdata( $target );
				$login = ( $user && isset( $user->user_login ) ) ? (string) $user->user_login : (string) $target;
				$scope = sprintf(
					/* translators: 1: user login, 2: user ID */
					__( 'It is scoped to the user "%1$s" (ID %2$d), who alone regains Sudo governance access while it stays defined — provided that user still holds administrator authority (manage_options, or manage_network_options on multisite); a scoped target who lacks it is still denied.', 'wp-sudo' ),
					esc_html( $login ),
					$target
				);
			}
		}

		return array(
			'label'       => __( 'Break-glass recovery mode is active', 'wp-sudo' ),
			'status'      => 'critical',
			'badge'       => $badge,
			'description' => '<p>' . $scope . '</p><p>' . __( 'Remove WP_SUDO_RECOVERY_MODE from wp-config.php as soon as normal access is restored — leaving it enabled weakens the governance model.', 'wp-sudo' ) . '</p>',
			'test'        => 'wp_sudo_recovery_mode',
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

		// Classify in the query, not in a follow-up read (#354).
		//
		// The previous shape selected on `META_KEY > 0` and then re-read each
		// user's scalar with get_user_meta() — a **cached** read. Enforcement
		// reads the signed proof cache-BYPASSED (Sudo_Session::read_proof()),
		// precisely because a persistent user_meta cache entry can be stale or
		// poisoned. So the sweep and the enforcement path could disagree, and a
		// failed cache invalidation let this classify a live session as stale and
		// delete every valid browser proof for that user. Deciding in SQL makes
		// the database authoritative for both, which is the property that was
		// missing.
		//
		// Reading the proof map here instead was considered and rejected: this
		// sweep runs for *other* users and, as a `direct` test, also under core's
		// scheduled check with no user context, so it cannot use
		// resolve_valid_proof() (which requires the current user and their
		// cookie) and would be trusting an unverified `expires` — while
		// cache-bypassing per user would evict each user's entire `user_meta`
		// bucket, not just this key.
		//
		// The cutoff excludes the grace window. A proof that expired within the
		// last GRACE_SECONDS is still usable under is_within_grace(), which
		// exists so an in-flight gated form is not lost (#279), and
		// Sudo_Session::set_token()'s own housekeeping sweep uses the same
		// `expires + GRACE_SECONDS` boundary. A bare `expires < now` here deleted
		// grace-eligible proofs up to two minutes early, with no cache failure
		// involved. Derived from the constant so the two sweeps cannot drift
		// apart again.
		$cutoff = time() - Sudo_Session::GRACE_SECONDS;

		do {
			$users = get_users(
				array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded, batched maintenance sweep; mirrors revoke_all_active_sessions().
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'key'     => Sudo_Session::META_KEY,
							'value'   => 0,
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
						array(
							'key'     => Sudo_Session::META_KEY,
							'value'   => $cutoff,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
					'fields'     => 'ID',
					'number'     => $batch_size,
					'offset'     => $offset,
				)
			);

			if ( ! is_array( $users ) ) {
				break;
			}

			$found = count( $users );

			foreach ( $users as $uid ) {
				$stale[] = (int) $uid;
			}

			$offset += $batch_size;
		} while ( $found === $batch_size );

		return $stale;
	}
}
