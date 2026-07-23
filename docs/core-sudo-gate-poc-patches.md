# POC Patch Sketches: Recent-Auth Gate at the Core Chokepoints

**Status:** Illustrative sketches, not tested against a core checkout. Companion to [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md). Signatures verified against WordPress core: `wp_update_user` / `wp_insert_user` (returns `WP_Error`) and `wp_delete_user` (returns `bool`) in `wp-includes/user.php`; `wp_set_password` in `wp-includes/pluggable.php`; `WP_User::set_role` / `add_role` in `wp-includes/class-wp-user.php`; `wpmu_create_user` (returns `int|false`) in `wp-includes/ms-functions.php`; the users controller in `wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php` (canonical: <https://github.com/WordPress/wordpress-develop/tree/trunk/src/wp-includes>). Line anchors are approximate.
**Purpose:** Make the spec's central claim concrete — that gating a handful of *data-layer chokepoints* covers admin UI, REST, and programmatic callers in one insertion, using error paths those functions already return.

The four pieces below are the minimum viable enforcement core:

1. The reauth window on `WP_Session_Tokens` (the primitive)
2. The gate helper + decision object (the query)
3. The `wp_update_user()` guard (the chokepoint — covers #20140's account cases)
4. The admin-UI adapter that turns the error into a challenge + replay

---

## 1. Recent-auth window on `WP_Session_Tokens`

The window lives *inside the login session record*, so `wp_logout()` and "log out everywhere" tear it down for free, and `destroy_all()` revokes it on the next request. **A password change does *not* clear it automatically** — `wp_set_password()` / `wp_update_user()` leave the token record intact — so the password path must call `wp_end_reauth_window()` explicitly (spec §4.2, acceptance criterion 3).

```php
// wp-includes/user.php  (new functions)

/**
 * Open a recent-auth ("sudo") window on the current login session.
 *
 * Called by the challenge handler after the actor re-verifies. Stamps the
 * current session token record; it is destroyed with the session.
 */
function wp_start_reauth_window( $user_id = 0, $scope = '' ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$token   = wp_get_session_token();            // raw token from the auth cookie
	if ( ! $user_id || ! $token ) {
		return false;
	}
	$manager = WP_Session_Tokens::get_instance( $user_id );
	$session = $manager->get( $token );
	if ( ! $session ) {
		return false;
	}
	$session['reauth_at']    = time();
	$session['reauth_scope'] = (string) $scope;
	$manager->update( $token, $session );         // persists into the session store
	return true;
}

/**
 * Is the current session within a valid recent-auth window?
 */
function wp_has_recent_auth( $user_id = 0, $scope = '' ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$token   = wp_get_session_token();
	if ( ! $user_id || ! $token ) {
		return false;
	}
	$session = WP_Session_Tokens::get_instance( $user_id )->get( $token );
	if ( empty( $session['reauth_at'] ) ) {
		return false;
	}
	/** Window length in seconds. Define WP_REAUTH_WINDOW to override. */
	$ttl = defined( 'WP_REAUTH_WINDOW' ) ? (int) WP_REAUTH_WINDOW : 15 * MINUTE_IN_SECONDS;
	$ttl = (int) apply_filters( 'wp_reauth_window_ttl', $ttl, $user_id, $scope );

	// Scope-bound check — OPT-IN only. v1 ships FLAT freshness (spec §4.2): callers
	// pass no scope (see wp_check_action_gate below), so this block is inert. If you
	// enable scoping, the challenge MUST stamp the SAME scope via
	// wp_start_reauth_window( $id, $scope ) on success, or a scoped action loops
	// forever (the window is opened with the default empty scope).
	if ( '' !== $scope && ( $session['reauth_scope'] ?? '' ) !== $scope ) {
		return false;
	}
	return ( time() - (int) $session['reauth_at'] ) <= $ttl;
}

function wp_end_reauth_window( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$token   = wp_get_session_token();
	if ( ! $user_id || ! $token ) {
		return;
	}
	$manager = WP_Session_Tokens::get_instance( $user_id );
	$session = $manager->get( $token );
	if ( $session ) {
		unset( $session['reauth_at'], $session['reauth_scope'] );
		$manager->update( $token, $session );
	}
}
```

No schema change is strictly required: `WP_Session_Tokens` session records are already free-form arrays merged in `update()`. Core's default `WP_User_Meta_Session_Tokens::update_session()` persists whatever keys are present, so `reauth_at` rides along. The only core edit is *documenting* the two reserved keys and ensuring `is_still_valid()`/`destroy` paths don't strip them (they don't today).

---

## 2. Gate helper + decision object

```php
// wp-includes/gate.php  (new)

final class WP_Action_Gate_Decision {
	private $reason;
	private $action_id;
	public function __construct( $action_id, $reason ) {
		$this->action_id = $action_id;
		$this->reason    = $reason; // 'passed'|'no_recent_auth'|'expired'|'rate_limited'|'blocked'
	}
	public function passed()          { return 'passed' === $this->reason; }
	public function needs_challenge() { return in_array( $this->reason, array( 'no_recent_auth', 'expired' ), true ); }
	public function blocked()         { return in_array( $this->reason, array( 'rate_limited', 'blocked' ), true ); }
	public function reason()          { return $this->reason; }
	public function challenge_url( $return_to = '' ) {
		return add_query_arg(
			array(
				'action'    => 'reauth',
				'ca_action' => rawurlencode( $this->action_id ),
				'redirect'  => rawurlencode( $return_to ?: wp_get_referer() ),
				// Bind the prompt to this actor + action so an external page cannot
				// force an admin into an arbitrary reauth/replay. The challenge handler
				// MUST wp_verify_nonce() this before showing the prompt or replaying.
				'_wpnonce'  => wp_create_nonce( 'reauth_' . $this->action_id ),
			),
			wp_login_url()
		);
	}
	public function as_wp_error() {
		// blocked / rate_limited are hard refusals, not challenges — distinct code,
		// NO challenge_url, so adapters hard-block/log them instead of prompting.
		if ( $this->blocked() ) {
			return new WP_Error(
				'sudo_action_' . $this->reason,   // sudo_action_blocked | sudo_action_rate_limited
				__( 'This action cannot proceed right now.' ),
				array( 'status' => 403, 'action' => $this->action_id, 'reason' => $this->reason )
			);
		}
		return new WP_Error(
			'sudo_reauth_required',
			__( 'Please confirm your identity to continue.' ),
			array( 'status' => 403, 'action' => $this->action_id, 'reason' => $this->reason, 'challenge_url' => $this->challenge_url() )
		);
	}
}

/**
 * Evaluate whether a registered consequential action may proceed now.
 * Returns "passed" for unregistered actions or when gating is disabled,
 * so unguarded callers are never broken.
 */
function wp_check_action_gate( $action_id, array $args = array() ) {
	// A built-in `core/` action that is not registered means the catalog failed to
	// load (or loaded too late) — fail CLOSED, never silently allow the mutation.
	// Unknown third-party actions were never gated, so they pass.
	if ( ! wp_action_exists( $action_id ) ) {
		return str_starts_with( $action_id, 'core/' )
			? new WP_Action_Gate_Decision( $action_id, 'blocked' )
			: new WP_Action_Gate_Decision( $action_id, 'passed' );
	}
	if ( ( defined( 'WP_DISABLE_ACTION_GATE' ) && WP_DISABLE_ACTION_GATE )
		|| ! apply_filters( 'wp_action_gate_enabled', true, $action_id, $args ) ) {
		return new WP_Action_Gate_Decision( $action_id, 'passed' );
	}
	$user_id = isset( $args['actor'] ) ? (int) $args['actor'] : get_current_user_id();
	// v1 = flat freshness: do NOT pass the action scope (spec §4.2). See the
	// scope-bound OPT-IN note in wp_has_recent_auth() to enable scoping later.

	if ( wp_reauth_is_rate_limited( $user_id ) ) {                 // ported from Sudo lockout model
		return new WP_Action_Gate_Decision( $action_id, 'rate_limited' );
	}
	if ( wp_has_recent_auth( $user_id ) ) {           // flat freshness (no scope) for v1
		return new WP_Action_Gate_Decision( $action_id, 'passed' );
	}
	return new WP_Action_Gate_Decision( $action_id, 'no_recent_auth' );
}
```

---

## 3. The chokepoint guard in `wp_update_user()`

This is the whole #20140 account-change fix, at one seam. Placed as early as possible in `wp_update_user()` — after the target user is resolved but **before any password-handling side effects** (password hashing, reset-key clearing, `after_password_reset` / change-notification emails) and before `wp_insert_user()` — so a blocked no-recent-auth change fires none of those. (For flows that reach `wp_set_password()` directly, gate there too — it does not pass through `wp_update_user()`.)

```diff
--- a/wp-includes/user.php
+++ b/wp-includes/user.php
@@ function wp_update_user( $userdata ) {
 	// ... existing: resolve $ID, load $user_obj = get_userdata( $ID ),
 	//     merge $userdata over $user_obj->to_array() ...

+	/*
+	 * Recent-auth gate for consequential account changes (Trac #20140).
+	 * Runs after capability resolution, before the write. Because every
+	 * surface (admin edit_user(), REST users controller, WP-CLI,
+	 * programmatic callers) reaches the DB through this function, one guard
+	 * covers them all. The credential proven is ALWAYS the actor's own —
+	 * never the target's — so an admin can still change another user's
+	 * password without knowing it, once they confirm who is at the keyboard.
+	 */
+	$changed_fields = array();
+	if ( isset( $userdata['user_pass'] ) && $userdata['user_pass']
+		&& ! wp_check_password( $userdata['user_pass'], $user_obj->user_pass, $ID )
+		&& $userdata['user_pass'] !== $user_obj->user_pass ) {
+		$changed_fields[] = 'user_pass';
+	}
+	if ( isset( $userdata['user_email'] ) && $userdata['user_email'] !== $user_obj->user_email ) {
+		$changed_fields[] = 'user_email';
+	}
+	if ( isset( $userdata['role'] ) && wp_role_change_escalates( $ID, $userdata['role'] ) ) {
+		$changed_fields[] = 'role';
+	}
+	foreach ( wp_map_user_changes_to_actions( $ID, $changed_fields ) as $action_id ) {
+		$gate = wp_check_action_gate(
+			$action_id,
+			array( 'actor' => get_current_user_id(), 'target' => $ID, 'context' => array( 'changed' => $changed_fields ) )
+		);
+		if ( $gate->needs_challenge() || $gate->blocked() ) {
+			return $gate->as_wp_error(); // WP_Error propagates through EVERY existing caller
+		}
+	}
+
 	// ... existing: $user_id = wp_insert_user( $userdata ); ...
 }
```

Supporting mappers (new, small):

```php
function wp_map_user_changes_to_actions( $target_id, array $changed ) {
	$self = ( (int) $target_id === get_current_user_id() );
	$map  = array();
	if ( in_array( 'user_pass', $changed, true ) )  { $map[] = $self ? 'core/change-own-password' : 'core/change-user-password'; }
	if ( in_array( 'user_email', $changed, true ) ) { $map[] = $self ? 'core/change-own-email'    : 'core/change-user-email'; }
	if ( in_array( 'role', $changed, true ) )       { $map[] = 'core/promote-user'; }
	// Return core IDs UNFILTERED — do NOT drop a built-in that failed to register,
	// or the gate never reaches its `core/` fail-closed branch and the mutation
	// proceeds ungated. wp_check_action_gate() decides registered vs. missing.
	return array_values( $map );
}
```

> **REST role-promotion caveat.** The `role` detector reads the scalar `role` merged into `wp_update_user()`, but the REST users controller applies requested roles *after* that call via `array_map( array( $user, 'add_role' ), $request['roles'] )`. So a REST `roles` promotion **bypasses this mapper** — it is caught instead by the §5.3 escalation guard on the `{prefix}capabilities` meta write (`update_user_metadata`). The chokepoint mapper covers admin-form and programmatic scalar-`role` changes; the capability-meta guard covers the REST path.

```php

/** True only when $new_role newly grants administrator/network-admin authority. */
function wp_role_change_escalates( $target_id, $new_role ) {
	$role = get_role( $new_role );
	if ( ! $role || ! $role->has_cap( 'manage_options' ) ) {
		return false; // not an admin-tier role; demotions/lateral moves pass
	}
	$target = get_userdata( $target_id );
	return $target && ! user_can( $target, 'manage_options' ); // was non-admin, becoming admin
}
```

`wp_insert_user()` (create) and `activate_plugin()`/`delete_plugins()` take the identical three-line guard with their own action IDs. **`wp_delete_user()` cannot** — it returns `bool`, so a returned `WP_Error` is truthy and callers that check `if ( ! $result )` read it as a *successful* delete. Gate `core/delete-user` with a distinct adapter that intercepts **before** the delete (hook `delete_user`, or the REST `delete_item` permission callback) and short-circuits, rather than by return value. `grant_super_admin()` gates unconditionally.

---

## 4. Admin-UI adapter: catch → stash → challenge → replay

The only surface-specific code. Everything else rides the `WP_Error` return.

```diff
--- a/wp-admin/includes/user.php
+++ b/wp-admin/includes/user.php
@@ function edit_user( $user_id = 0 ) {
 	$user_id = wp_update_user( $user );
+
+	if ( is_wp_error( $user_id ) && 'sudo_reauth_required' === $user_id->get_error_code() ) {
+		$data     = $user_id->get_error_data();
+		$stash_key = wp_stash_request( $_POST, $_SERVER['REQUEST_METHOD'] ); // allowlist + secret redaction, TTL 300s
+		wp_safe_redirect( add_query_arg( 'stash', $stash_key, $data['challenge_url'] ) );
+		exit;
+	}
 	// ... existing return handling ...
 }
```

REST needs *no* adapter beyond letting the controller return the error — the 403 + `challenge_url` in `$error_data` is already REST-shaped. CLI/cron/XML-RPC print/log the error and stop (block-and-log, spec §5.2).

The challenge page (`wp-login.php?action=reauth`) verifies the actor's password (+ a `wp_reauth_second_factor` hook for 2FA plugins), calls `wp_start_reauth_window()`, then replays the stash: GET ⇒ `wp_safe_redirect()`, POST ⇒ self-submitting form. Port `class-request-stash.php` + `class-challenge.php` near-verbatim.

---

## Why this is the whole thing

Four insertions of three lines each (`wp_update_user`, `wp_insert_user`, `wp_delete_user`, plugin actions) + one 6-line admin adapter + the window/gate primitives. Every other caller — REST, CLI, programmatic — is covered because it already handles the `WP_Error` these functions already return. That is the difference between gating the effect and gating the form, in ~150 lines of core.
