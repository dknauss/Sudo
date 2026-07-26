# POC Patch Sketches: Recent-Auth Gate at the Core Chokepoints

**Status:** Illustrative sketches, not tested against a core checkout. Companion to [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md). Signatures verified against WordPress core: `wp_update_user` / `wp_insert_user` (returns `WP_Error`) in `wp-includes/user.php` and `wp_delete_user` (returns `bool`) in `wp-admin/includes/user.php`; `wp_set_password` in `wp-includes/pluggable.php`; `WP_User::set_role` / `add_role` in `wp-includes/class-wp-user.php`; `wpmu_create_user` (returns `int|false`) in `wp-includes/ms-functions.php`; the users controller in `wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php` (canonical: <https://github.com/WordPress/wordpress-develop/tree/trunk/src/wp-includes>). Line anchors are approximate.
**Purpose:** Make the spec's central claim concrete — that gating a handful of *data-layer chokepoints* covers admin UI, REST, and programmatic callers in one insertion, using error paths those functions already return.

The four pieces below are the minimum viable enforcement core:

1. The recent-auth record — a per-session, HMAC-signed, separate-proof primitive (§4.2)
2. The gate helper + decision object (the query)
3. The chokepoint guards — `wp_update_user()` for #20140's account cases, and the shared code sinks (`WP_Upgrader::install_package()`, `wp_edit_theme_plugin_file()`, `activate_plugin()`)
4. The admin-UI adapter that turns the error into a challenge + replay

---

## 1. The recent-auth record: per-session, HMAC-signed, separate proof

Two reviews rejected the two obvious designs (spec §4.2): stamping `reauth_at` on the shared session record elevates the stolen cookie too (it is the *same* token), and rotating the token invalidates every open-tab nonce (`wp_create_nonce()` hashes the token). What survives is a record that (1) requires a **separate proof secret** the browser holds, (2) is keyed **per session-token verifier**, and (3) is **self-authenticating** so a poisoned object cache cannot forge it (`session_tokens` lives in the persistent `user_meta` cache group).

```php
// wp-includes/user.php  (new functions)

// Httponly cookie carrying the per-session proof secret (DISTINCT from the auth cookie).
// The server stores only its hash; a session copy that never held it cannot pass.
const WP_REAUTH_PROOF_COOKIE = 'wp_reauth_proof';

/**
 * Open a recent-auth ("sudo") window on the CURRENT login session.
 *
 * Called by the challenge handler after the actor re-verifies. Issues a fresh
 * proof secret to this browser and writes a signed record keyed to this session's
 * token verifier. NO token rotation (that would invalidate nonces).
 */
function wp_start_reauth_window( $user_id = 0, $scope = '' ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$token   = wp_get_session_token();            // the current session-token VERIFIER (the key)
	if ( ! $user_id || ! $token ) {
		return false;
	}
	$manager = WP_Session_Tokens::get_instance( $user_id );
	$session = $manager->get( $token );
	if ( ! $session ) {
		return false;
	}

	$proof = wp_generate_password( 43, false );   // ~256 bits, url-safe — held only by the browser
	$at    = time();

	// `$token` is the RAW token from the auth cookie (wp_get_session_token()); the record is
	// keyed and MAC'd by its VERIFIER — the hash WP_Session_Tokens actually stores — not the
	// raw token. (WP_Session_Tokens::get/update take the raw token and hash it internally.)
	$verifier = hash( 'sha256', $token );

	$session['reauth_at']    = $at;
	$session['reauth_scope'] = (string) $scope;
	$session['reauth_proof'] = hash( 'sha256', $proof );   // a HASH, never the secret itself
	// (3) Self-authenticating: the MAC binds (user, verifier, time, scope, proof-hash) with
	// wp_salt('auth'). It MUST cover the proof hash — otherwise a cache-poisoning attacker
	// keeps a valid signature and swaps in the hash of a cookie THEY hold; and the verifier,
	// so an injected stamp copied onto a DIFFERENT session cannot validate.
	$session['reauth_sig']   = hash_hmac( 'sha256', "$user_id|$verifier|$at|$scope|{$session['reauth_proof']}", wp_salt( 'auth' ) );
	$manager->update( $token, $session );                  // keyed PER verifier — concurrent sessions don't collide

	if ( ! headers_sent() ) {
		// Core sets its auth cookies on more than one path; the proof must follow or it will be
		// absent on whichever surface the chosen path misses. COOKIEPATH (site root) reaches the
		// front end and cookie-authed /wp-json REST; ADMIN_COOKIE_PATH reaches wp-admin even on
		// installs where it sits outside the site root. Secret held only by the browser.
		foreach ( array_unique( array( COOKIEPATH, ADMIN_COOKIE_PATH ) ) as $cookie_path ) {
			setcookie( WP_REAUTH_PROOF_COOKIE, $proof, array(
				'expires'  => 0,             // session cookie; the signed record's TTL is authoritative
				'path'     => $cookie_path,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',         // Strict drops it on a top-level nav into wp-admin
			) );
		}
	}
	return true;
}

/**
 * Is the current request within a valid recent-auth window?
 * Verifies the HMAC (unforgeable), then the browser's proof secret, then the TTL.
 */
function wp_has_recent_auth( $user_id = 0, $scope = '' ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$token   = wp_get_session_token();
	if ( ! $user_id || ! $token ) {
		return false;
	}
	// (5) Read from the STORE, not a cookie string, so destroy_all() revokes within the request.
	$session = WP_Session_Tokens::get_instance( $user_id )->get( $token );
	if ( empty( $session['reauth_at'] ) || empty( $session['reauth_sig'] ) || empty( $session['reauth_proof'] ) ) {
		return false;
	}
	$at     = (int) $session['reauth_at'];
	$sscope = (string) ( $session['reauth_scope'] ?? '' );

	// (3) HMAC first — a forged (cache-poisoned) record without a valid signature dies here.
	// Uses the same verifier (hash of the raw token) and includes the proof hash, so an
	// attacker cannot keep the signature and swap the proof.
	$verifier = hash( 'sha256', $token );
	$expect = hash_hmac( 'sha256', "$user_id|$verifier|$at|$sscope|" . (string) $session['reauth_proof'], wp_salt( 'auth' ) );
	if ( ! hash_equals( $expect, (string) $session['reauth_sig'] ) ) {
		return false;
	}
	// (1) The browser must present the matching proof — a stolen auth cookie that never
	// held it cannot pass, even though it shares the session token.
	$proof = isset( $_COOKIE[ WP_REAUTH_PROOF_COOKIE ] ) ? (string) $_COOKIE[ WP_REAUTH_PROOF_COOKIE ] : '';
	if ( '' === $proof || ! hash_equals( (string) $session['reauth_proof'], hash( 'sha256', $proof ) ) ) {
		return false;
	}

	$ttl = defined( 'WP_REAUTH_WINDOW' ) ? (int) WP_REAUTH_WINDOW : 15 * MINUTE_IN_SECONDS;
	$ttl = (int) apply_filters( 'wp_reauth_window_ttl', $ttl, $user_id, $scope );

	// Scope-bound check — OPT-IN only (v1 = flat freshness; callers pass no scope).
	if ( '' !== $scope && $sscope !== $scope ) {
		return false;
	}
	return ( time() - $at ) <= $ttl;
}

/**
 * Drop the window. Two distinct modes, deliberately kept separate:
 *   (a) current-session drop  — logout / explicit "drop elevation": clear THIS session's
 *       record and expire the browser's proof cookie.
 *   (b) credential-wide teardown — a password change for $user_id: clear the reauth_* record
 *       on EVERY session of $user_id, but touch the browser cookie only if the current actor
 *       IS $user_id (an admin changing another user's password must not lose their own window).
 * NEVER destroy_all() (that logs the user out of the request that changed the password), and
 * teardown must key on an actual password-hash change for the TARGET user — not a blind
 * profile_update, which fires when an admin edits another user.
 */
function wp_end_reauth_window( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return;
	}
	// Server-side teardown (covers both modes): strip reauth_* from EVERY session record of
	// $user_id. WP_Session_Tokens has no public "update every session" method, so core adds one
	// that removes the reserved keys per record (not destroy_all). The password/reset path may
	// have no current token for $user_id, so clearing only the current session is insufficient.
	wp_reauth_clear_all_sessions( $user_id );   // new WP_Session_Tokens helper

	// Cookie teardown belongs ONLY to mode (a): expire the browser's proof cookie when ending
	// the CURRENT actor's own window. In mode (b), when an admin changes ANOTHER user's password
	// ($user_id = target), deleting the current browser's cookie would prematurely end the
	// admin's own window — the target's server-side records above are what matter. Clear on the
	// same paths the proof was set on.
	if ( get_current_user_id() === $user_id && ! headers_sent() ) {
		foreach ( array_unique( array( COOKIEPATH, ADMIN_COOKIE_PATH ) ) as $cookie_path ) {
			setcookie( WP_REAUTH_PROOF_COOKIE, '', array(
				'expires' => time() - YEAR_IN_SECONDS, 'path' => $cookie_path,
				'domain'  => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax',
			) );
		}
	}
}
```

Two core edits the sketch depends on: (a) **only the challenge handler may write `reauth_*`** — core must strip the reserved keys from the `attach_session_information` filter result, since that filter sets the base session array on every `WP_Session_Tokens::create()` and any registered callback could otherwise pre-elevate new sessions; and (b) the HMAC covers the verifier, so an injected or cache-poisoned stamp on any other session fails validation. A cache-bypassing `$wpdb` read of the record on the enforcement path is an optional complement (it does not survive a direct-DB *write*; the HMAC does). Note a fresh `login` timestamp is **not** a substitute for this record — `wp_signon('','')` mints one from a held cookie with no credential entered.

**Concurrency (required):** `$manager->update()` is a read-modify-write of the whole `session_tokens` array, so keying the record per verifier does not by itself make writes atomic — two simultaneous reauthentications, or a challenge write racing credential teardown, can drop one writer's `reauth_*` (a lost update that can also *restore* data teardown just removed). A core implementation needs a per-record write path (`WP_Session_Tokens::update_reauth( $token, $data )`) with compare-and-swap or row-level locking, not the whole-array merge, before relying on immediate revocation and concurrent-session isolation.

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
				// add_query_arg() URL-encodes values itself (via _http_build_query),
				// so pass RAW values — pre-encoding here would double-encode them.
				'action'    => 'reauth',
				'ca_action' => $this->action_id,
				'redirect'  => $return_to ?: wp_get_referer(),
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
	// The global kill-switch wins even over the fail-closed core/ branch, so an
	// operator can recover from a bad rollout or a broken catalog load.
	if ( ( defined( 'WP_DISABLE_ACTION_GATE' ) && WP_DISABLE_ACTION_GATE )
		|| ! apply_filters( 'wp_action_gate_enabled', true, $action_id, $args ) ) {
		return new WP_Action_Gate_Decision( $action_id, 'passed' );
	}
	// A built-in `core/` action that is not registered means the catalog failed to
	// load (or loaded too late) — fail CLOSED. Unknown third-party actions pass.
	// (`0 === strpos`; `str_starts_with()` is equivalent and core-safe — WP polyfills it in wp-includes/compat.php since 5.9.)
	if ( ! wp_action_exists( $action_id ) ) {
		return ( 0 === strpos( (string) $action_id, 'core/' ) )
			? new WP_Action_Gate_Decision( $action_id, 'blocked' )
			: new WP_Action_Gate_Decision( $action_id, 'passed' );
	}
	$user_id = isset( $args['actor'] ) ? (int) $args['actor'] : get_current_user_id();

	// Classify the ACTOR before any recent-auth check (spec §9). Only an interactive
	// cookie session can render/answer a challenge; the others get a policy, not a
	// challenge URL. This is what keeps unattended security updates and WP-CLI package
	// operations from being refused, and stops an API credential from getting an
	// (unanswerable) interactive challenge instead of the promised block-and-log.
	switch ( wp_action_gate_actor_class( $args ) ) {  // 'cookie' | 'api_credential' | 'system' | 'cli'
		case 'system':                                // wp-cron auto-updater, etc.
			// Allow ONLY core's own updater drawing from the configured update source;
			// otherwise block. Provenance must be decided where it is still available
			// (the update offer / package URL), NOT at install_package(), which sees only
			// an unpacked local path — see the adapter note in §3 and spec §5.4/§9.
			return new WP_Action_Gate_Decision(
				$action_id,
				wp_action_gate_trusted_autoupdate( $args ) ? 'passed' : 'blocked'
			);
		case 'cli':                                   // shell already dominates the gate
			return new WP_Action_Gate_Decision(
				$action_id,
				apply_filters( 'wp_action_gate_cli_policy', true, $action_id, $args ) ? 'passed' : 'blocked'
			);
		case 'api_credential':                        // App Password / XML-RPC — no interactive reauth possible
			return new WP_Action_Gate_Decision( $action_id, 'blocked' );
		// 'cookie' falls through to the interactive recent-auth path below.
	}

	// v1 = flat freshness: do NOT pass the action scope (spec §4.2). Check the
	// recent-auth WINDOW before the lockout: a user who already holds a valid
	// window should proceed even with recent failed attempts on other actions.
	if ( wp_has_recent_auth( $user_id ) ) {           // flat freshness (no scope) for v1
		return new WP_Action_Gate_Decision( $action_id, 'passed' );
	}
	if ( wp_reauth_is_rate_limited( $user_id ) ) {    // ported from Sudo lockout model
		return new WP_Action_Gate_Decision( $action_id, 'rate_limited' );
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

`wp_insert_user()` (create) and `activate_plugin()`/`delete_plugins()` take the identical three-line guard with their own action IDs. The **code effects** take the same guard at their *shared* sinks (spec §5.4): `WP_Upgrader::install_package()` for install / ZIP upload / update / bulk / theme / language packs (**not** `Plugin_Upgrader::install()`, which misses the update and auto-updater paths), and `wp_edit_theme_plugin_file()` for both file editors. Non-interactive callers of `install_package()` are resolved by the §9 actor-class policy — core's auto-updater from the configured source is *allowed*, not blocked. **`wp_delete_user()` cannot** take the `WP_Error` guard — it returns `bool`, so a returned `WP_Error` is truthy and callers that check `if ( ! $result )` read it as a *successful* delete; gate `core/delete-user` with a distinct adapter that intercepts **before** the delete (hook `delete_user`, or the REST `delete_item` permission callback). `switch_theme()` (void) and the multipart ZIP upload (unstashable) likewise need pre-op handling. `grant_super_admin()` gates unconditionally.

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

REST needs *no* adapter beyond letting the controller return the error — the 403 + `challenge_url` in `$error_data` is already REST-shaped. Non-interactive callers are resolved by actor class (spec §9): an API credential (App Password / XML-RPC) blocks-and-logs; core's automatic updater from the configured source is allowed; WP-CLI is allowed by default and operator-configurable.

The challenge page (`wp-login.php?action=reauth`) verifies the actor's password (+ a `wp_reauth_second_factor` hook for 2FA plugins), calls `wp_start_reauth_window()`, then replays the stash: GET ⇒ `wp_safe_redirect()`, POST ⇒ self-submitting form. Port `class-request-stash.php` + `class-challenge.php` near-verbatim.

---

## Why this is the whole thing

A handful of insertions at the core mutation chokepoints (`wp_update_user`, `wp_insert_user`, plugin actions) + the window/gate primitives. Most callers — REST, CLI, programmatic — are covered because they already handle the `WP_Error` these functions return. **The seam is not uniform**, though: `wp_delete_user()` returns `bool` (a returned `WP_Error` is truthy → gate it with a *pre-delete adapter*, not a return value), `WP_User::set_role()` returns `void`, and `wp_set_password()`/`reset_password()` bypass `wp_update_user()` — so each of those chokepoints specifies whether it pays the signature-change cost or an interstitial/`wp_die()` cost (see the §5.1 per-chokepoint caveat). That is the difference between gating the effect and gating the form — a bounded, per-chokepoint change, not a framework.
