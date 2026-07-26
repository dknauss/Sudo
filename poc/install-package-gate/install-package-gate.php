<?php
/**
 * Plugin Name:       Core Gate PoC — install_package()
 * Description:       Vertical slice of the proposed core recent-auth gate, enforced at WP_Upgrader::install_package(). Proof of concept for docs/core-action-gate-proposal.md. NOT a product.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * License:           GPL-2.0-or-later
 *
 * WHAT THIS IS
 *
 * The proposal (docs/core-action-gate-proposal.md §3) argues that a recent-auth
 * gate should sit at core's *shared effect sinks*, and names
 * WP_Upgrader::install_package() as the single package-write funnel beneath
 * plugin/theme install, upload, update, bulk-update, the AJAX updaters, the
 * auto-updater, and language packs.
 *
 * This slice proves that claim is implementable **without a single line of core
 * change**, because core already ships a vetoing seam there.
 * `WP_Upgrader::install_package()` (wp-admin/includes/class-wp-upgrader.php)
 * opens with:
 *
 *     $res = apply_filters( 'upgrader_pre_install', true, $args['hook_extra'] );
 *     if ( is_wp_error( $res ) ) {
 *         return $res;
 *     }
 *
 * That is the same short-circuit shape as `pre_delete_post` — a filter whose
 * non-null/error return aborts the operation AND becomes the return value. So
 * the entire Group-A code-execution closure is reachable from a plugin today.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * - No stash, no auto-replay. A blocked install returns the WP_Error that the
 *   installer UI already knows how to render; the operator re-runs the action
 *   after proving intent. Auto-replay is the defect tracked as #315.
 * - No policy for non-interactive callers. Per #320 the v1 scope is interactive
 *   cookie sessions only; cron/CLI/API-credential callers pass through exactly
 *   as they do in stock WordPress. That is a deliberate v1 gap, not an oversight.
 * - No challenge UI. Proving intent is out of scope for this slice; the test
 *   suite grants the proof directly. The full-page challenge is a separate
 *   concern (proposal §4).
 *
 * @package WP_Sudo\PoC
 */

declare( strict_types = 1 );

namespace WP_Sudo\PoC\InstallPackageGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a proof of intent stays good. */
const PROOF_TTL = 900;

/**
 * Actor classes the gate distinguishes.
 *
 * Deliberately NOT derived from the call site. A plugin calling install_package()
 * from inside an admin request is an interactive actor, because the *request*
 * carries a cookie session — see #357. Classification is a property of the
 * request, so a nested programmatic call inherits it and cannot be used as a
 * bypass.
 */
const ACTOR_INTERACTIVE = 'interactive';
const ACTOR_NONE        = 'none';

/**
 * Resolve the actor class for the current request.
 *
 * v1 recognises exactly two outcomes: an interactive browser session, or
 * everything else. "Everything else" (cron, WP-CLI, API credentials) is out of
 * v1 scope per #320 and passes through ungated, so it does not need to be
 * subdivided yet — the follow-on provenance/automation project does that.
 *
 * @return string One of the ACTOR_* constants.
 */
function actor_class(): string {
	// A verified login session is the only thing that makes a request
	// interactive. Note this is verified, not merely parsed: see proof_key().
	return '' !== verified_session_token() ? ACTOR_INTERACTIVE : ACTOR_NONE;
}

/**
 * The current request's login-session token, but only if it is genuinely one of
 * this user's live sessions.
 *
 * wp_get_session_token() is NOT a validator. It reads wp_parse_auth_cookie(),
 * which splits the cookie on '|', checks there are four parts, and returns them
 * — verifying no HMAC and no expiry. Any caller can make it non-empty with a
 * junk cookie, so testing it for emptiness is a check an attacker can switch
 * off. WP_Session_Tokens::verify() is the real test.
 *
 * @return string Verified token, or '' when the request has no live session.
 */
function verified_session_token(): string {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return '';
	}

	$token = wp_get_session_token();
	if ( '' === $token ) {
		return '';
	}

	return \WP_Session_Tokens::get_instance( $user_id )->verify( $token ) ? $token : '';
}

/**
 * Storage key for a proof of intent, bound to the session that produced it.
 *
 * Per-session, never per-user. A per-user key is what lets a cloned cookie or a
 * second device inherit the elevation the legitimate browser earned — the
 * failure the proposal exists to close (§4). The token is hashed because this
 * string becomes an option name and a session token is a credential.
 *
 * @return string Key, or '' when there is no session to bind to.
 */
function proof_key(): string {
	$token = verified_session_token();
	if ( '' === $token ) {
		return '';
	}

	return 'wp_sudo_poc_proof_' . get_current_user_id() . '_' . substr( hash( 'sha256', $token ), 0, 20 );
}

/**
 * Record that the actor proved intent in this session.
 *
 * In a real implementation this is what the challenge handler calls on a
 * successful password/2FA answer. The slice exposes it directly so tests can
 * grant the proof without a challenge UI.
 */
function grant_proof(): bool {
	$key = proof_key();
	if ( '' === $key ) {
		return false; // No session ⇒ nothing to bind a proof to.
	}

	return (bool) set_transient( $key, time(), PROOF_TTL );
}

/** Revoke this session's proof. */
function revoke_proof(): void {
	$key = proof_key();
	if ( '' !== $key ) {
		delete_transient( $key );
	}
}

/** @return bool Whether this session currently holds a valid proof of intent. */
function has_proof(): bool {
	$key = proof_key();

	return '' !== $key && false !== get_transient( $key );
}

/**
 * The gate.
 *
 * Returning a WP_Error here aborts the package write and becomes
 * install_package()'s return value, so every caller that already handles that
 * error — the installer UI, the AJAX updaters, REST — surfaces it with no
 * further change.
 *
 * @param bool|\WP_Error $response   Filter response so far; a WP_Error already
 *                                   aborts, so leave another handler's veto alone.
 * @param array          $hook_extra Context: 'type' (plugin|theme), 'action'
 *                                   (install|update), and for updates the slug.
 * @return bool|\WP_Error Unchanged response to proceed, or WP_Error to refuse.
 */
function gate_install_package( $response, $hook_extra = array() ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// Out of v1 scope (#320): no interactive actor to challenge, so behave
	// exactly as stock WordPress. This is the branch that keeps background
	// security updates working — WP_Automatic_Updater reuses Plugin_Upgrader and
	// adds no marker of its own to $hook_extra, so the only sound signal is the
	// absence of a session on the request, not anything in the payload.
	if ( ACTOR_INTERACTIVE !== actor_class() ) {
		return $response;
	}

	if ( has_proof() ) {
		return $response;
	}

	$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : 'package';
	$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : 'write';

	return new \WP_Error(
		'wp_sudo_reauth_required',
		sprintf(
			/* translators: 1: package type (plugin/theme), 2: action (install/update). */
			__( 'Recent authentication is required before this %1$s %2$s can write to the filesystem. Confirm your identity, then run the action again.', 'wp-sudo-poc' ),
			$type,
			$action
		),
		array(
			'status'      => 403,
			'type'        => $type,
			'action'      => $action,
			// No stash key, and nothing to replay: the operator re-runs the
			// action deliberately after proving intent (#315).
			'replayable'  => false,
		)
	);
}

add_filter( 'upgrader_pre_install', __NAMESPACE__ . '\\gate_install_package', 10, 2 );
