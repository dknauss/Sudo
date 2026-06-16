<?php
/**
 * Version-aware upgrade routines.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Upgrader
 *
 * Runs sequential, one-time upgrade routines when the plugin version changes.
 *
 * @since 2.0.0
 *
 * Each routine targets a specific version and runs exactly once. The stored
 * version number is updated after all applicable routines have executed so
 * that a failed mid-sequence routine will be retried on the next page load.
 *
 * HOW TO ADD A NEW UPGRADE
 * ────────────────────────
 * 1. Add a private method named `upgrade_X_Y_Z()` where X.Y.Z is the version
 *    that introduces the change.
 * 2. Add the version string to the UPGRADES array, mapping it to the method name.
 * 3. The method will run exactly once for sites upgrading from an older version.
 */
class Upgrader {

	/**
	 * Option key for the stored database/schema version.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'wp_sudo_db_version';

	/**
	 * Ordered map of version → method name.
	 *
	 * Versions MUST be listed in ascending order. Each method runs once when
	 * upgrading from a version older than the key.
	 *
	 * @var array<string, string>
	 */
	private const UPGRADES = array(
		'2.0.0'  => 'upgrade_2_0_0',
		'2.1.0'  => 'upgrade_2_1_0',
		'2.2.0'  => 'upgrade_2_2_0',
		'2.15.0' => 'upgrade_2_15_0',
		'3.0.0'  => 'upgrade_3_0_0',
		'3.3.0'  => 'upgrade_3_3_0',
	);

	/**
	 * Run any pending upgrade routines.
	 *
	 * Compares the stored version against WP_SUDO_VERSION and sequentially
	 * executes every routine whose version is greater than the stored value.
	 *
	 * Safe to call on every request — returns immediately when no upgrade
	 * is needed (single option read, no writes).
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$stored = $this->get_db_version();

		// Nothing to do if already current.
		if ( version_compare( $stored, WP_SUDO_VERSION, '>=' ) ) {
			return;
		}

		// Prime the global $wp_roles before any routine runs. Migrations run at
		// plugins_loaded (under WP-CLI/admin), where $wp_roles may not yet be
		// initialized. On WP 7.0+, WP_User_Query::prepare_query() dereferences
		// the raw global directly for a `capability` query ($wp_roles->for_site()),
		// so a null global fatals with "Call to a member function for_site() on
		// null". wp_roles() lazily instantiates the global; calling it here makes
		// every present and future capability-based user query in the routines
		// below safe, regardless of how early the upgrade fires.
		wp_roles();

		// Run each applicable routine in order.
		foreach ( self::UPGRADES as $version => $method ) {
			if ( version_compare( $stored, $version, '<' ) && is_callable( array( $this, $method ) ) ) {
				$this->{$method}();
			}
		}

		// Mark as current.
		$this->set_db_version( WP_SUDO_VERSION );
	}

	/**
	 * Get the stored database version.
	 *
	 * Uses network-wide option on multisite, per-site option on single-site.
	 *
	 * @return string The stored version string.
	 */
	private function get_db_version(): string {
		return is_multisite()
			? (string) get_site_option( self::VERSION_OPTION, '0.0.0' )
			: (string) get_option( self::VERSION_OPTION, '0.0.0' );
	}

	/**
	 * Set the stored database version.
	 *
	 * Uses network-wide option on multisite, per-site option on single-site.
	 *
	 * @param string $version The version string to store.
	 * @return void
	 */
	private function set_db_version( string $version ): void {
		if ( is_multisite() ) {
			update_site_option( self::VERSION_OPTION, $version );
		} else {
			update_option( self::VERSION_OPTION, $version );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// Upgrade routines — add new private methods below, one per version.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * 2.0.0 migration: remove v1 role and settings that are no longer used.
	 *
	 * - Removes the `site_manager` custom role (v2 is role-agnostic).
	 * - Strips `allowed_roles` from the settings array.
	 * - Deletes the `wp_sudo_role_version` option used by v1's role syncer.
	 *
	 * @return void
	 */
	private function upgrade_2_0_0(): void {
		// Remove the Site Manager custom role.
		remove_role( 'site_manager' );

		// Clean up v1 settings keys that no longer exist.
		$settings = get_option( Admin::OPTION_KEY, array() );
		if ( isset( $settings['allowed_roles'] ) ) {
			unset( $settings['allowed_roles'] );
			update_option( Admin::OPTION_KEY, $settings );
		}

		// Remove the role version tracking option.
		delete_option( 'wp_sudo_role_version' );
	}

	/**
	 * 2.1.0 migration: remove unfiltered_html from the Editor role.
	 *
	 * Ensures KSES content filtering is always active for editors.
	 * On multisite, WordPress core already restricts unfiltered_html
	 * to Super Admins, so the helper method is a no-op there.
	 *
	 * @return void
	 */
	private function upgrade_2_1_0(): void {
		Plugin::strip_editor_unfiltered_html();
	}

	/**
	 * 2.2.0 migration: convert entry-point policies to three-tier model.
	 *
	 * Maps the old binary values to the new three-tier equivalents:
	 * - 'block' → 'limited'
	 * - 'allow' → 'unrestricted'
	 *
	 * Already-valid values ('disabled', 'limited', 'unrestricted') are
	 * preserved. Skips the database write if no changes are needed.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	private function upgrade_2_2_0(): void {
		$settings = is_multisite()
			? get_site_option( Admin::OPTION_KEY, array() )
			: get_option( Admin::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$policy_keys = array(
			Gate::SETTING_REST_APP_PASS_POLICY,
			Gate::SETTING_CLI_POLICY,
			Gate::SETTING_CRON_POLICY,
			Gate::SETTING_XMLRPC_POLICY,
		);

		$migration_map = array(
			'block' => Gate::POLICY_LIMITED,
			'allow' => Gate::POLICY_UNRESTRICTED,
		);

		$changed = false;

		foreach ( $policy_keys as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}

			if ( isset( $migration_map[ $settings[ $key ] ] ) ) {
				$settings[ $key ] = $migration_map[ $settings[ $key ] ];
				$changed          = true;
			}
		}

		if ( $changed ) {
			if ( is_multisite() ) {
				update_site_option( Admin::OPTION_KEY, $settings );
			} else {
				update_option( Admin::OPTION_KEY, $settings );
			}

			Admin::reset_cache();
		}
	}

	/**
	 * 2.15.0 migration: create the shared events table for dashboard visibility.
	 *
	 * @return void
	 */
	private function upgrade_2_15_0(): void {
		Event_Store::create_table();
		Plugin::schedule_prune_cron();
	}

	/**
	 * 3.0.0 migration: add performance indexes for event pruning and filtered reads.
	 *
	 * Re-running create_table() is safe and lets dbDelta / SQLite IF NOT EXISTS
	 * backfill indexes without dropping existing data.
	 *
	 * @return void
	 */
	private function upgrade_3_0_0(): void {
		Event_Store::create_table();
	}

	/**
	 * 3.3.0 migration: preserve Sudo access for existing single-site admins.
	 *
	 * Fresh activations grant the dedicated governance capabilities to the
	 * activating admin, but already-active installs do not fire the activation
	 * callback during a code update. Backfill the initial governance holders so
	 * strict mode does not lock existing operators out of Sudo settings.
	 *
	 * HISTORY — do not re-key to the feature's release version. Governance
	 * shipped in 3.2.0 but this routine was originally keyed at 3.1.0, a
	 * version that never had a public release (tags went 3.1.1 → 3.1.3 →
	 * 3.2.0). Sites stored at any public 3.1.x therefore skipped the backfill
	 * (3.1.x is not < 3.1.0) and were locked out of Settings → Sudo in strict
	 * mode, and sites that then upgraded already have 3.2.0 stamped. Keying at
	 * 3.3.0 runs the routine once more for both cohorts. The existing-holder
	 * guard below makes the re-run a no-op on sites where governance already
	 * works, so deliberate Access-tab grants/revocations are preserved.
	 *
	 * Multisite remains super-admin governed via wp_sudo_can()'s explicit
	 * super-admin short-circuit, so no per-site cap grant is needed here.
	 * Sites with no users in the administrator role get no backfill; recovery
	 * there remains the WP_SUDO_RECOVERY_MODE constant.
	 *
	 * @return void
	 */
	private function upgrade_3_3_0(): void {
		if ( is_multisite() ) {
			return;
		}

		// Governance is already configured if anyone holds the settings-access
		// cap (individually or via a role; an explicit deny entry also matches,
		// but the plugin's own revoke path uses remove_cap() and never writes
		// deny entries). Zero holders is exactly the lockout this fixes.
		$holders = get_users(
			array(
				'capability' => 'manage_wp_sudo',
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		if ( ! empty( $holders ) ) {
			return;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'all',
			)
		);

		foreach ( $admins as $admin ) {
			if ( ! $admin instanceof \WP_User ) {
				continue;
			}

			foreach ( Admin::GOVERNANCE_CAPS as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
