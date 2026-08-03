<?php
/**
 * Plugin Name: Capability Floor Prototype (EXPERIMENTAL -- dispatch-shape gate)
 * Description: A falsification experiment, not a proposed design. Tests
 *              whether the real native "Delete User" flow -- confirmation
 *              form, real POST, real wp_delete_user() call -- can be let
 *              through for exactly one approved, single-use request,
 *              without touching the approval/floor mechanism's own
 *              invariants.
 *
 * WHY THIS FILE EXISTS, AND WHY IT IS NOT THE SAME PATTERN AS THE OTHER
 * TWO WIRED EFFECTS:
 *
 * install-plugin and create-user (harness.php) sidestep the intent-signal
 * problem structurally: they are the ONLY code paths in the entire install
 * that ever pass a string target_hash as a meta-cap argument, so nothing
 * incidental can ever match an approval by accident. That property is
 * simple, verified, and does not depend on reasoning about WordPress's
 * dispatch surface at all.
 *
 * Native wp-admin/users.php's real "Delete" flow cannot be unlocked that
 * same way. Its actual mutating handler (action=dodelete, reached only via
 * a real POST from the confirmation form) checks the BARE, targetless
 * current_user_can( 'delete_users' ) first (wp-admin/users.php line ~199),
 * before it ever loops per-user and checks the targeted
 * current_user_can( 'delete_user', $id ). The master-key fix
 * (capability-floor-approvals.php) makes it a hard, load-bearing rule that
 * a null-target check can NEVER match an approval -- that rule is what
 * closed round 1's finding. Native code's own first gate on this path IS a
 * null-target check. There is no way to let it through without either (a)
 * weakening that rule for this one call site, or (b) doing a SEPARATE,
 * earlier check that recognizes "this exact request, right now, already
 * matches an approved target" and grants a request-scoped override -- which
 * is what this file does.
 *
 * (b) is not a hook on a dispatch point in the way that phrase might imply
 * ("hook wp_delete_user() and gate it there"). It is a request-shape match
 * at admin_init, before users.php's own code runs at all, that recognizes
 * one specific action name on one specific screen via one specific request
 * method, extracts the exact fields the approval was scoped to, and only
 * then grants a temporary, single-request capability override -- narrower
 * in scope than WP Sudo's original Gate architecture (this matches exactly
 * one action on exactly one screen, not a general request-pattern registry
 * covering every admin/AJAX/REST/CLI surface), but built from the same
 * underlying idea that session concluded was fragile. Treat every claim in
 * this file as unverified until BOUNDARY.md records what adversarial
 * testing against it actually found.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_init',
	static function (): void {
		if ( 'users.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		// wp-admin/users.php's own dodelete case wp_die()s unconditionally
		// on multisite ("User deletion is not allowed from this screen") --
		// multisite routes single-site removal through a structurally
		// different action ('remove', gating on remove_users, not
		// delete_users) instead. Without this guard a matching approval
		// would still be consumed here, at admin_init, moments before
		// core's own unconditional die -- burning a real password check on
		// a request that could never have succeeded on this screen type,
		// regardless of anything else being correct. This experiment does
		// not attempt the 'remove' action; it is single-site-only by scope,
		// not by accident.
		if ( is_multisite() ) {
			return;
		}
		// Native code never reaches dodelete via GET. Requiring POST here
		// too is defense-in-depth, not reliance on it: cap_floor_binding()'s
		// SameSite=Strict cookie already means a cross-site request -- GET
		// or POST -- never carries the binding, so cap_floor_valid_approval_id()
		// below would return null regardless. This just refuses to even
		// look for a matching approval on a request shape native code would
		// never itself produce.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( 'dodelete' !== ( $_REQUEST['action'] ?? '' ) ) {
			return;
		}

		// Real native code always submits users[] here, even for a single
		// ID (wp-admin/users.php's confirmation form embeds one hidden
		// users[] field per target, unconditionally) -- verified against
		// that file's own source, not assumed. This experiment is scoped
		// to exactly one target; a genuine bulk delete of more than one
		// user is refused outright rather than partially honored, since
		// this prototype's digest scheme has no defined meaning for a
		// multi-user batch.
		$user_ids = isset( $_REQUEST['users'] ) ? array_map( 'intval', (array) $_REQUEST['users'] ) : array();
		if ( 1 !== count( $user_ids ) ) {
			return;
		}
		$user_id = $user_ids[0];

		$delete_option = (string) ( $_REQUEST['delete_option'] ?? '' );
		$reassign      = ( 'reassign' === $delete_option && isset( $_REQUEST['reassign_user'] ) )
			? (int) $_REQUEST['reassign_user']
			: 0;

		// Identical digest scheme to the REST /delete-user route -- the
		// same approval can be redeemed through either path, whichever the
		// request actually takes.
		$target_hash = hash( 'sha256', $user_id . "\n" . $reassign );

		// A pure, side-effect-free read here -- safe to call from
		// admin_init, before we have committed to anything.
		$approval_id = cap_floor_valid_approval_id( 'delete_users', $target_hash );
		if ( null === $approval_id ) {
			return; // No matching approval; native code's own checks will correctly deny.
		}

		// Consumed here, at admin_init -- BEFORE native code's own
		// check_admin_referer( 'delete-users' ) call runs (that happens
		// later, inside the dodelete case body). If the nonce check
		// subsequently fails for any reason, this approval is already
		// spent and the deletion never happens -- a real, named cost of
		// this pattern, not hidden here. See BOUNDARY.md.
		if ( ! cap_floor_consume_approval( $approval_id, 'delete_users', $target_hash ) ) {
			return; // Lost a race to consume it; native code's own checks will correctly deny.
		}

		// A request-scoped override, not a standing grant: read only by
		// the map_meta_cap filter below, only for the rest of this one
		// request, only for this one exact user_id. Nothing persists past
		// this request finishing.
		$GLOBALS['cap_floor_dispatch_override'] = array( 'user_id' => $user_id );
	},
	1
);

/**
 * Lifts the floor's denial for exactly the two checks native code performs
 * on the dodelete path -- the bare 'delete_users' gate and the per-user
 * 'delete_user' meta-cap -- but ONLY when admin_init above already matched
 * this exact request to an approved, now-consumed target. Runs after
 * cap_floor_allow_with_approval() (priority 1000000): that filter can never
 * fire for these checks anyway, since neither passes a string target_hash,
 * so ordering relative to it does not matter in practice.
 */
add_filter(
	'map_meta_cap',
	static function ( array $caps, string $cap, int $user_id, array $args ): array {
		$override = $GLOBALS['cap_floor_dispatch_override'] ?? null;
		if ( null === $override ) {
			return $caps;
		}
		if ( 'delete_users' === $cap ) {
			return array_values( array_diff( $caps, array( 'do_not_allow' ) ) );
		}
		if ( 'delete_user' === $cap && isset( $args[0] ) && (int) $args[0] === $override['user_id'] ) {
			return array_values( array_diff( $caps, array( 'do_not_allow' ) ) );
		}
		return $caps;
	},
	1000001,
	4
);
