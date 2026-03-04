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
		'2.0.0' => 'upgrade_2_0_0',
		'2.1.0' => 'upgrade_2_1_0',
		'2.2.0' => 'upgrade_2_2_0',
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
		if ( version_compare( $stored, self::plugin_version(), '>=' ) ) {
			return;
		}

		// Run each applicable routine in order.
		foreach ( self::UPGRADES as $version => $method ) {
			if ( version_compare( $stored, $version, '<' ) && is_callable( array( $this, $method ) ) ) {
				$this->{$method}();
			}
		}

		// Mark as current.
		$this->set_db_version( self::plugin_version() );
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

	/**
	 * Resolve plugin version constant safely for static analysis and bootstrap edge cases.
	 *
	 * @return string
	 */
	private static function plugin_version(): string {
		return defined( 'WP_SUDO_VERSION' ) ? (string) WP_SUDO_VERSION : '0.0.0';
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
}
