<?php
/**
 * Plugin Name: Capability Floor Prototype (increment 1 — deny only)
 * Description: Denies a fixed set of dangerous capabilities to every account,
 *              unconditionally, via map_meta_cap(). No unlock mechanism yet —
 *              this increment exists to verify the denial itself is airtight,
 *              including on multisite, before anything is layered on top.
 *
 * Corrected design per WP Sudo's docs/finding.md §4.9 (rev. 9): denial must
 * happen in map_meta_cap(), not user_has_cap(). WP_User::has_cap() returns
 * early for a multisite Super Admin BEFORE the user_has_cap filter ever runs
 * (wp-includes/class-wp-user.php), so a user_has_cap-based floor is silently
 * bypassed on multisite. map_meta_cap() runs first, and its result ($caps) is
 * exactly what that early-return branch itself checks for 'do_not_allow' — so
 * injecting it here is seen by both the Super Admin branch and the ordinary
 * branch, verified against wp-includes/class-wp-user.php on WordPress 7.0.1:
 *
 *   if ( is_multisite() && is_super_admin( $this->ID ) ) {
 *       if ( in_array( 'do_not_allow', $caps, true ) ) { return false; }
 *       return true;
 *   }
 *   ...
 *   unset( $capabilities['do_not_allow'] ); // in the ordinary branch too
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The capability set this floor denies by default.
 *
 * Modelled on core's own DISALLOW_FILE_MODS cluster
 * (wp-includes/capabilities.php), which is the file-mod/core-update set,
 * plus the account-takeover-relevant capabilities DISALLOW_FILE_MODS does not
 * cover: promoting, creating, or editing users, and granting unfiltered HTML.
 *
 * Deliberately a flat, named, auditable list — not "all capabilities except
 * a safe list" — so adding to it is a visible, reviewable diff.
 */
const CAP_FLOOR_DENIED_CAPS = array(
	// Core's own DISALLOW_FILE_MODS cluster.
	'edit_files',
	'edit_plugins',
	'edit_themes',
	'install_plugins',
	'update_plugins',
	'delete_plugins',
	'install_themes',
	'update_themes',
	'delete_themes',
	'upload_plugins',
	'upload_themes',
	'update_core',
	// Account-takeover surface DISALLOW_FILE_MODS does not cover.
	'promote_users',
	'create_users',
	'edit_users',
	'delete_users',
	'unfiltered_html',
);

/**
 * Deny every capability in CAP_FLOOR_DENIED_CAPS, for every account,
 * unconditionally. No exemption exists yet in this increment.
 *
 * Hooked on `map_meta_cap`, not `user_has_cap` — see the file docblock for
 * why that distinction is load-bearing rather than stylistic.
 *
 * @param string[] $caps    Primitive capabilities required, as already
 *                          resolved by core and any earlier map_meta_cap
 *                          filter.
 * @param string   $cap     The originally requested (possibly meta) capability.
 * @param int      $user_id The user being checked.
 * @param array    $args    Extra arguments passed to the original check.
 * @return string[]
 */
function cap_floor_deny( array $caps, string $cap, int $user_id, array $args ): array {
	if ( in_array( $cap, CAP_FLOOR_DENIED_CAPS, true ) ) {
		$caps[] = 'do_not_allow';
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'cap_floor_deny', 999999, 4 );
