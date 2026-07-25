<?php
/**
 * Governance helper functions.
 *
 * Provides the centralized wp_sudo_can() capability-check helper used by all
 * WP Sudo admin surfaces. Separates Sudo management authority from general
 * WordPress site-admin privileges via dedicated capabilities.
 *
 * This file is loaded unconditionally at plugin boot (wp-sudo.php) and in
 * the unit-test bootstrap, making wp_sudo_can() and wp_sudo_is_recovery_mode()
 * available as testable global functions.
 *
 * @since      3.2.0
 * @package    WP_Sudo
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether a user has a Sudo governance capability.
 *
 * All WP Sudo admin surfaces MUST route capability checks through this
 * function rather than calling current_user_can('manage_options') or
 * current_user_can('manage_network_options') directly.
 *
 * Three decision paths, in priority order:
 *
 * 1. **Multisite super-admin short-circuit** — super admins always pass.
 *    WordPress core's current_user_can() works via map_meta_cap's super-admin
 *    bypass, but user_can($id, $cap) on a subsite context does not. The
 *    explicit check here ensures super-admin authority is honored regardless
 *    of which site the request is on.
 *
 * 2. **Break-glass recovery mode** — when WP_SUDO_RECOVERY_MODE is defined
 *    and truthy in wp-config.php, the current user receives effective
 *    `manage_wp_sudo` authority, but ONLY if they also still hold site/network
 *    admin authority (`manage_options` single-site, `manage_network_options`
 *    multisite). The governance model deliberately separates `manage_wp_sudo`
 *    from `manage_options`, so a locked-out manager who kept their admin role
 *    still passes while subscribers and editors gain nothing. Limited to that
 *    one cap and only for the user making the current request; does not bypass
 *    the reauth challenge. See docs/security-model.md §Break-glass recovery.
 *
 * 3. **Strict cap check** — delegates to user_can($user_id, $cap). This is the
 *    only governance behavior; the `compatibility` mode option was removed in
 *    4.0.0. A stale `wp_sudo_governance_mode` option value is inert.
 *
 * @since 3.2.0
 * @since 3.3.0 Renamed from sudo_can() to the wp_sudo_ prefix.
 * @since 4.0.0 Removed `compatibility` governance mode; strict is the only path.
 *
 * @param string   $cap     Governance capability slug. One of:
 *                          'manage_wp_sudo', 'view_wp_sudo_activity',
 *                          'export_wp_sudo_activity', 'revoke_wp_sudo_sessions'.
 * @param int|null $user_id User to check. Defaults to the current user.
 * @return bool
 */
function wp_sudo_can( string $cap, ?int $user_id = null ): bool {
	$user_id ??= get_current_user_id();

	// 1. Multisite super-admin short-circuit.
	if ( is_multisite() && is_super_admin( $user_id ) ) {
		return true;
	}

	// 2. Break-glass recovery mode — manage_wp_sudo only, for the user the
	// recovery scope matches (the current user in unscoped mode, or the named
	// target in scoped mode), and only if that user still holds site/network
	// admin authority. This contains the blast radius: while WP_SUDO_RECOVERY_MODE
	// is set, recovery rescues a locked-out admin (kept manage_options, lost
	// manage_wp_sudo) without handing governance to every authenticated
	// subscriber/editor. See wp_sudo_user_matches_recovery() for the scope rules.
	if ( 'manage_wp_sudo' === $cap
		&& wp_sudo_user_matches_recovery( $user_id )
		&& user_can( $user_id, is_multisite() ? 'manage_network_options' : 'manage_options' )
	) {
		return true;
	}

	// 3. Strict cap check.
	return user_can( $user_id, $cap );
}

/**
 * The WP Sudo governance capability slugs.
 *
 * Bootstrap-safe canonical source (this file loads before the Admin class is
 * autoloadable, e.g. during uninstall). Admin::GOVERNANCE_CAPS mirrors this list
 * for use in class contexts where the constant is more convenient.
 *
 * @since 3.3.0
 *
 * @return string[]
 */
function wp_sudo_governance_caps(): array {
	return array(
		'manage_wp_sudo',
		'view_wp_sudo_activity',
		'export_wp_sudo_activity',
		'revoke_wp_sudo_sessions',
	);
}

/**
 * Map WP Sudo governance meta capabilities into WordPress primitive caps.
 *
 * WordPress checks menu-page capabilities before page callbacks run, so
 * UI surfaces registered with `manage_wp_sudo` must be understood by core's
 * capability system as well as by wp_sudo_can().
 *
 * @since 3.2.0
 *
 * @param array<string> $caps    Primitive caps WordPress already mapped.
 * @param string        $cap     Requested capability.
 * @param int           $user_id User being checked.
 * @param array<mixed>  $args    Additional map_meta_cap arguments.
 * @return array<string>
 */
function wp_sudo_map_governance_meta_cap( array $caps, string $cap, int $user_id, array $args = array() ): array {
	unset( $args );

	if ( ! in_array( $cap, wp_sudo_governance_caps(), true ) ) {
		return $caps;
	}

	// Break-glass recovery mode maps manage_wp_sudo to the site/network admin
	// primitive cap, so WordPress core's own admin-page gate only admits users
	// who actually hold manage_options / manage_network_options. This delegates
	// the role check (including the multisite super-admin bypass) to core. The
	// mapping applies only to the user the recovery scope matches (current user
	// unscoped, named target scoped).
	if ( 'manage_wp_sudo' === $cap
		&& wp_sudo_user_matches_recovery( $user_id )
	) {
		return array( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	return array( $cap );
}

/**
 * Whether WP Sudo break-glass recovery mode is currently active.
 *
 * Implemented as a standalone function so unit tests can stub it with
 * Brain\Monkey without needing to define/undefine PHP constants at runtime.
 *
 * Operators activate recovery mode by adding one of the following to
 * wp-config.php:
 *   define( 'WP_SUDO_RECOVERY_MODE', true );        // unscoped — any admin
 *   define( 'WP_SUDO_RECOVERY_MODE', 'jane' );      // scoped — the login 'jane'
 *   define( 'WP_SUDO_RECOVERY_MODE', 42 );          // scoped — user ID 42
 *
 * This returns "is recovery active at all" and deliberately uses a LOOSE truthy
 * test, so a scoped value (a non-empty login string or a positive user ID) still
 * reports active. The scoped/unscoped distinction is drawn by
 * wp_sudo_recovery_mode_is_unscoped() (strict `=== true`), NOT here.
 *
 * Recovery mode is NOT the default; it is an emergency escape hatch for
 * the "last manager locked out" scenario. Leaving it enabled permanently
 * effectively bypasses the governance capability model.
 *
 * @since 3.2.0
 *
 * @return bool
 */
function wp_sudo_is_recovery_mode(): bool {
	return defined( 'WP_SUDO_RECOVERY_MODE' ) && WP_SUDO_RECOVERY_MODE;
}

/**
 * Whether recovery mode is UNSCOPED (the legacy "any admin" form).
 *
 * The unscoped form is the strict boolean `true` and ONLY that. Any scalar
 * user ID or login string is a scoped target, not unscoped. The strict `=== true`
 * comparison is essential: PHP loose comparison treats `1 == true`, so a loose
 * test would misread `define( 'WP_SUDO_RECOVERY_MODE', 1 )` (user ID 1) as
 * unscoped and grant every administrator — the exact blast radius scoping exists
 * to prevent. Keep this strict; keep wp_sudo_is_recovery_mode() loose.
 *
 * @since 5.0.0
 *
 * @return bool
 */
function wp_sudo_recovery_mode_is_unscoped(): bool {
	return defined( 'WP_SUDO_RECOVERY_MODE' ) && true === WP_SUDO_RECOVERY_MODE;
}

/**
 * Resolve a SCOPED recovery-mode target to a user ID.
 *
 * Returns the target user's ID when WP_SUDO_RECOVERY_MODE names a specific user,
 * or null when: the constant is undefined, recovery is unscoped (boolean `true`),
 * the value is otherwise empty/falsy, or the value resolves to no existing user
 * (a typo'd login or a non-existent ID). A null return means "no scoped target"
 * and callers MUST treat an unresolvable scoped value as matching NOBODY — never
 * as unscoped. See wp_sudo_user_matches_recovery().
 *
 * Resolution rule: an integer value is looked up as a user ID; any other
 * (string) value is looked up as a user login. This function performs only a
 * user lookup (get_user_by) and no capability checks, so it cannot re-enter
 * map_meta_cap.
 *
 * @since 5.0.0
 *
 * @return int|null Resolved target user ID, or null when there is no scoped target.
 */
function wp_sudo_recovery_mode_user(): ?int {
	if ( ! defined( 'WP_SUDO_RECOVERY_MODE' ) ) {
		return null;
	}

	$value = WP_SUDO_RECOVERY_MODE;

	// Unscoped (strict true) or any empty/falsy value has no scoped target.
	if ( true === $value || false === $value || null === $value || '' === $value || 0 === $value ) {
		return null;
	}

	$user = is_int( $value )
		? get_user_by( 'id', $value )
		: get_user_by( 'login', (string) $value );

	return $user ? (int) $user->ID : null;
}

/**
 * Whether a user is covered by the current recovery-mode scope.
 *
 * The single seam both governance callers (wp_sudo_can, the meta-cap mapper)
 * use to decide whether the break-glass grant applies to a given user. Three
 * explicit states, so an unresolvable scoped target can never be misread as the
 * unscoped "any admin" grant:
 *
 *   1. Recovery inactive        → false (nobody).
 *   2. Unscoped (boolean true)  → the CURRENT user only, preserving the
 *                                 pre-scoping contract (recovery rescued the
 *                                 acting admin, not arbitrary other users).
 *   3. Scoped (ID or login)     → only the resolved target user; an
 *                                 unresolvable target matches nobody
 *                                 (fail-closed).
 *
 * This decides scope membership only. The caller still applies the role gate
 * (the target must hold manage_options / manage_network_options), so a scoped
 * target who lost admin rights is still denied.
 *
 * @since 5.0.0
 *
 * @param int $user_id User being evaluated.
 * @return bool
 */
function wp_sudo_user_matches_recovery( int $user_id ): bool {
	if ( ! wp_sudo_is_recovery_mode() ) {
		return false;
	}

	if ( wp_sudo_recovery_mode_is_unscoped() ) {
		return get_current_user_id() === $user_id;
	}

	$target = wp_sudo_recovery_mode_user();

	return null !== $target && $user_id === $target;
}
