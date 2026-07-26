# Core Implementation Spec: A Recent-Auth Gate for Consequential Actions

**Status:** Draft engineering spec, not adopted by WordPress core.
**Drafted:** July 2026
**Companion to:** [`core-action-gate-proposal.md`](core-action-gate-proposal.md) (the *why* and phasing). This document is the *what to change in core*.
**Relates to:** Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140) (the recent-auth-for-consequential-actions discussion — the directly relevant ticket) and [#16470](https://core.trac.wordpress.org/ticket/16470) (single-site email-change confirmation, fixed in 4.9 — the `send_confirmation_on_profile_email()` flow §4.1 must accommodate). Multisite *terminology* here follows [#37593](https://core.trac.wordpress.org/ticket/37593) / [#39174](https://core.trac.wordpress.org/ticket/39174) (network-role naming, §8) — those are **not** recent-auth prior art.
**Prior art:** WP Sudo 4.8.0 — `includes/class-gate.php`, `class-sudo-session.php`, `class-action-registry.php`, `class-request-stash.php`, `class-challenge.php`.

---

## 1. Goal and non-goals

**Goal.** Give WordPress core a built-in way to require *fresh proof of human intent from the actor* before a small set of consequential operations proceed — regardless of which roles and capabilities the actor holds or which surface the request enters through. The concrete target is the failure mode behind #20140 and the broader class of incidents dominating recent WordPress security reports: **an authenticated-but-illegitimate session** (stolen cookie, walked-away device, XSS in an admin origin, a hijacked or maliciously-created Editor/Admin) performing account changes and privilege escalation. This is the most *exploited* vulnerability class, even though not the most disclosed: Patchstack's [*State of WordPress Security 2026*](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/) reports **broken access control as the single most-exploited category** of the 11,334 vulnerabilities found in 2025 (up 42% year over year; figures verified against that report, July 2026), and notes such exploits *"look like normal authenticated traffic with no obvious injection patterns"*. That is precisely why a proof-of-intent gate, not traffic inspection, is the right defense.

The security boundary is **recent, deliberate authentication of the actor at the moment a consequential effect is about to occur** — not knowledge of the target's credentials, and not the actor's original login (which may be hours stale or hijacked). This unifies the cases #20140 argued separately (changing your own password, changing another user's password or email, promoting a user), but it generalizes past them to every catalog effect (plugin install, user deletion, and the rest). The proof is *point-in-time knowledge of the actor's own factor*, not continuous presence: it forces a fresh, loggable, deliberate step, so a stolen cookie or walked-away session cannot silently drive a consequential effect. It does not prove a human is still at the screen.

**Non-goals** (kept deliberately out so this can land):

- Not a replacement for `current_user_can()`. This sits *above* capabilities; it never grants authority, only demands a fresh proof before an already-authorized action runs.
- Not a plugin sandbox or runtime isolation. It constrains *declared operations that pass through core chokepoints*; it cannot stop arbitrary code already running in-process. (See proposal §5, §6.)
- Not a new login system or 2FA framework. It consumes existing authenticated identity and existing session infrastructure.
- Not a WAF. It gates named operations; it does not inspect traffic.
- Not an audit log, monitor, or SIEM. It **enforces** proof of intent at the chokepoint; it does not observe, correlate, or alert on events. Detection and logging are a separate concern — useful in a plugin, out of scope for core.
- **Interactive surfaces first.** The first enforcement cut targets browser + cookie-authenticated REST; Application Passwords, WP-CLI, cron, and XML-RPC are explicitly deferred (§9).

---

## 2. Threat model

| Actor | Has valid session? | Has capability? | Defended by this spec? |
|---|---|---|---|
| Stolen/replayed admin cookie | yes | yes | **Yes, outside an open window** — no recent auth ⇒ challenge; a cookie stolen *while a window is open* inherits that elevation until it expires (window TTL) |
| XSS running in an authed admin origin | yes | yes | **Partially** — cannot silently satisfy an interactive reauth, but in-origin XSS can free-ride on (or trigger, or keylog) any window already open |
| Walk-away / shared workstation | yes | yes | **Yes** — window expires; consequential action re-challenges |
| Malicious Editor escalating to Admin | yes | no promote cap — reaches the path via a broken-access-control bug | **Partial** — a *hijacked* Editor session is blocked (the attacker can't pass the Editor's own reauth); a legitimately-authenticated malicious insider who *can* pass their own reauth is **not** stopped — the gate only forces a fresh, loggable step. Fixing the BAC bug is the authz layer's job (rows below); the gate is defense-in-depth for the stolen-session case, not a substitute |
| Attacker who knows the password | yes | yes | **Partially** — reauth still forces a deliberate, loggable step and blocks silent replay |
| Stolen Application Password / API credential | yes (API credential, not a human session) | yes | **Block-and-log** — no interactive reauth is possible over a credential channel, so these surfaces are hard-blocked, not challenged (§9) |
| Compromised plugin executing in-process | n/a | n/a | **No** — out of scope (needs runtime isolation) |
| Missing `current_user_can()` in a handler | n/a | n/a | **No** — that's an authz bug, orthogonal |

The value is concentrated on the interactive-session rows, which are precisely the "broken access control / privilege escalation / session hijack" categories that are the most-exploited class in current WordPress security data (§1).

---

## 3. Core design decisions

These are the load-bearing choices, each validated in WP Sudo production:

1. **Gate the effect, not the form field.** Gating one password input is security theater: a hijacked session skips the form and calls the mutation directly (create a new admin, change email + reset, use the installer). Enforcement must sit at the **data-layer chokepoint every surface funnels through**, so browser, REST, and programmatic callers are covered by one guard. (§5.)
2. **Role-agnostic.** Any logged-in user attempting a gated action is challenged; the gate never reasons about roles. Capability checks remain core's job and run unchanged.
3. **Recent-auth window ("sudo mode"), not forced re-login.** Terminating the session on every sensitive change is heavier than the problem needs. The primitive is a short, revocable elevated window — the GitHub sudo-mode pattern. This walks back the "terminate session" idea floated earlier in #20140.
4. **Registry and gate are separable — neither blocks the other.** A queryable catalog of consequential actions has standalone value for auditability, Site Health, and UI, and is independently landable. The gate *consumes* the registry when present, but **enforces at the effect chokepoint regardless of whether the registry has shipped**, so it does not depend on the registry landing first (proposal §8). This is why the proposal leads gate-first and treats the registry as an optional companion rather than a mandatory precursor. (The labels "Phase 1"/"Phase 2" below name the registry track and the gate track for convenience; they are not a required order.)
5. **Fail closed for core actions.** If a built-in consequential action cannot be evaluated (malformed matcher, storage error), the mutation is refused, not allowed.
6. **Transport-agnostic decision, actor-class policy.** The chokepoint returns a decision (as a `WP_Error` in practice, §5.2); the *challenge* is only one rendering of it. The decision branches on **actor class**, not transport (§9): an interactive cookie session is challenged; an API credential (App Password / XML-RPC) is blocked and logged; a no-actor call is allowed only when it is core's own automatic updater from the configured source; WP-CLI is allowed by default and operator-configurable. Excluding non-interactive routes from *enforcement* because they cannot render a *challenge* is a category error (it leaves REST install/activate open), so they get a policy, not an exemption.

---

## 4. New core APIs

### 4.1 Consequential Actions registry (the optional companion)

A pure-data registry. No hooks, no enforcement — just naming and metadata. New file `wp-includes/actions-api.php` + `wp-includes/class-wp-consequential-actions.php`.

```php
wp_register_action( 'core/change-user-password', [
    'label'        => __( 'Change another user’s password' ),
    'capabilities' => [ 'edit_user' ],
    'category'     => 'user-management',
    // The portable `consequence` block — the *same* shape a consequential ability
    // would carry as its `consequence` annotation, so one source-blind getter can
    // read standalone entries and annotated abilities without reshaping (decision memo).
    'consequence'  => [
        'class'       => 'account-takeover',   // privilege-escalation | account-takeover | code-execution | destructive | external-credential-mutation
        'scope'       => 'users',              // reauth reuse grouping for the gate
        'annotations' => [
            'destructive'          => false,
            'requires_recent_auth' => true,
        ],
    ],
] );

wp_get_action( 'core/change-user-password' ); // array|null
wp_get_actions();                             // array<string,array>
wp_action_exists( $id );                      // bool
```

Naming follows the Abilities API shape `namespace/action-name` (lowercase, hyphens, one slash) — see proposal §8.2. Actions are *not* forced into the Abilities object model; some map to abilities later, some wrap legacy flows. Phase 1 is a **standalone** registry, Abilities-aligned in its ID convention **and** in the nested `consequence` block shape (so the same block can annotate a consequential ability later); the catalog below is registered as standalone entries because none of these operations are abilities today. Teaching `wp_get_actions()` to *also* return consequence-annotated abilities is a deferred extension (there are none to read yet, and the gate enforces at the chokepoint regardless of source), not part of the first patch. The full decision, including why both pure forms fail and the collision contract, is in §4.1.1 below.

**Initial core catalog** (small on purpose; proposal §8):

| Action ID | Backing core chokepoint |
|---|---|
| `core/change-own-password` | `wp_update_user()` when `$user_id === get_current_user_id()` and `user_pass` changes |
| `core/change-user-password` | `wp_update_user()` on another user, `user_pass` changes |
| `core/change-own-email` | profile self-email change — **gate the initiating request**, not only `wp_update_user()`: with confirmation enabled, `send_confirmation_on_profile_email()` writes `_new_email` and restores `$_POST['email']` to the old value *before* `wp_update_user()` runs, so the chokepoint sees no change; detect at `personal_options_update` / the REST self-update |
| `core/change-user-email` | `wp_update_user()` other user, `user_email` changes |
| `core/create-user` | `wp_insert_user()` (returns `WP_Error`); on multisite `wpmu_create_user()` returns `int\|false`, so the gate needs a distinct adapter there (REST multisite create checks `if ( ! $user_id )`) |
| `core/delete-user` | `wp_delete_user()` / `wpmu_delete_user()` |
| `core/promote-user` | role change granting administrator / network-admin authority (see §5.3) |
| `core/edit-plugin-file` / `core/edit-theme-file` | `wp_edit_theme_plugin_file()` — the single sink `plugin-editor.php`, `theme-editor.php`, and `wp_ajax_edit_theme_plugin_file()` all funnel through; the most direct code-write path |
| `core/install-plugin` / `core/install-theme` / `core/update-plugin` / `core/update-theme` | `WP_Upgrader::install_package()` — the shared package-write funnel beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, the AJAX updaters, and the REST plugins controller. Gate here, **not** `Plugin_Upgrader::install()` (which misses bulk/update/AJAX/auto-updater), and **not** at the capability layer (`upload_plugins`→`install_plugins` in `map_meta_cap`, so it cannot distinguish an attacker ZIP from a repository install). Also covers language-pack writes into `WP_LANG_DIR`, which core executes (`.l10n.php` is `include`d) |
| `core/activate-plugin` | `activate_plugin()` — reachable directly via REST `update_item` on an already-installed plugin |
| `core/delete-plugin` | `delete_plugins()` |
| `core/switch-theme` | `switch_theme()` |
| `core/delete-theme` | `delete_theme()` |
| `core/update-connector-credentials` | `/wp/v2/settings` write of a `connectors_*_api_key` value — the WP 7.0 Connectors **credential-integrity** threat; an options / REST-settings write chokepoint (proposal §3, §8) |
| `core/change-critical-setting` | `/wp/v2/settings` write of `siteurl` / `home` (keyed `url` / `home` via `show_in_rest`) — repointing the origin loads attacker scripts **same-origin** in `wp-admin`, which can fake/keylog the challenge itself; a critical-option write chokepoint (WP Sudo's `options.critical`) |
| `core/create-application-password` | REST `POST /wp/v2/users/(?:\d+\|me)/application-passwords` **and** the `authorize-application.php` no-JS approve flow — a durable API credential a cookie session can mint to then change a password via REST and log in fresh (both issuance paths must gate) |
| `core/set-registration-policy` | write of `users_can_register` / `default_role` — enforce a **server-side invariant**: a **code-capable** role (by effective capability, §5.3 — incl. `activate_plugins` / theme-switch, not just `edit/install_*`) can never be the public-registration default; classify by capability, not role name, and cover the omitted-role case in REST user create |

The account-change rows are the direct #20140 deliverable. The code rows (file editor, package install/upload/update, and activation) are the terminal RCE routes a stolen admin session walks to, and gating them at the **shared effect sinks** (rather than per-page or per-capability) is what makes route multiplicity a non-issue: a REST install, a bulk update, the AJAX updater, and a programmatic call all funnel through `install_package()`, and both editors through `wp_edit_theme_plugin_file()`. Themes are gated alongside plugins because installing/switching a malicious theme is an equivalent code-execution path, so gating one but not the other would just relocate the "install a backdoor" bypass. The file editor is **in** the v1 catalog (not deferred as an earlier draft had it): it is the most direct code-write path, and although hardened sites disable it via `DISALLOW_FILE_EDIT`, most do not. The connector-credentials row extends the same proof-of-intent boundary to a credential-**integrity** threat: a `/wp/v2/settings` write swapping an AI-provider API key is a stolen-session abuse of a legitimate operation, reachable with no filesystem access and no code execution (proposal §3). WP Sudo already gates it in production via its `connectors.update_credentials` rule.

#### 4.1.1 Registry vs. Abilities API — the decision (folded from the decision memo)

The one blocking question before the first patch: a new registry, or consequence-metadata layered on the **Abilities API** (which now exists and provides namespacing + execution hooks)? **Resolved (July 2026): ship a standalone consequential-actions registry now** (small, pure-data, backed by the plain core functions the gate already protects), kept **Abilities-*aligned*** (reuse the `namespace/name` ID convention **and** the nested `consequence` block shape) so that if core later grows consequential abilities, the same getters can be extended to read their `consequence` annotations. That union is a documented future extension, not part of the first patch: nothing populates the ability side today. This rejects *both* pure forms:

- **Pure "abilities-only" does not survive contact with the catalog.** The operations the gate must protect (`wp_update_user()`, `wp_insert_user()`, `wp_delete_user()`, `activate_plugin()`, `delete_plugins()`, the upgrader/theme/role mutations) are **not abilities and are not registered in the Abilities API** (as of WP 7.0 it registers three *read-only* abilities: `core/get-site-info`, `core/get-user-info`, `core/get-environment-info`). "Annotate the ability" has nothing to annotate for any catalog member. Making it work would first require abilitizing core's entire write path — a far larger, separately contested project with no consensus or timeline, exactly the entanglement proposal §5–§6 warn against.
- **The one strong argument for abilities, enforcement economy (one execution hook vs. the ~15 chokepoint insertions in §6), fails on a verified fact.** `WP_Ability::execute()` fires `wp_before_execute_ability` via a plain `do_action()` and then calls `$this->do_execute( $input )` on the very next line, discarding whatever the hook returned (verified against WordPress/abilities-api [`class-wp-ability.php`](https://github.com/WordPress/abilities-api/blob/trunk/includes/abilities-api/class-wp-ability.php)). So the hook is **observational, not a gate**: a callback cannot return a `WP_Error` or a challenge to stop the ability — only `wp_die()`/throw would, a blunt request-kill rather than the structured challenge-and-replay a reauth gate needs. Abilities buys **no** clean enforcement seam. And the chokepoint model is strictly more complete: it covers a *programmatic* `wp_update_user()` caller, which ability-execution hooks never see.

Two shared shape choices — the **ID convention** and the **nested `consequence` block** (§4.1) — are the whole of "Abilities-aligned"; they keep a future union possible without standing up a subsystem before it has a second source.

**Collision contract (only relevant once the ability side exists).** One ID resolves to one record: an operation is registered standalone *or* as an ability, never both, and a duplicate ID is rejected with `_doing_it_wrong()` at *registration time* on whichever path registers it — without force-loading the whole Abilities registry per request. Overlap is never permitted; no precedence-based merging. That is the entire contract, and it need not exist until the ability side does.

The full memo (with the MVP-status detail) is archived at [`archive/core-actions-registry-vs-abilities-decision.md`](archive/core-actions-registry-vs-abilities-decision.md).

**Demo shape delta (tracked).** The `dknauss/consequential-actions` demonstrator carries the consequence fields **flat** (`consequence_class` / `scope` / `annotations` at the top level) while this spec **nests** them under a `consequence` block; same fields, same model — a field-shape delta only, and a cheap demo follow-up (wrap the three fields in a `consequence` key). Recorded in [`archive/core-sudo-gate-vs-demo-reconciliation.md`](archive/core-sudo-gate-vs-demo-reconciliation.md).

### 4.2 Recent-auth window: a per-session, HMAC-signed, separate-proof record

This is the load-bearing security mechanism, and two independent reviews rejected the two obvious designs. The governing invariant: **step-up must give the browser that answered the challenge a secret the stolen/ridden session copy does not already hold, and the server's assurance record must not be forgeable by anything short of that secret.** Two rejected approaches and why:

- **Stamping `reauth_at` on the shared session-token record — rejected.** The stolen auth cookie *is* a copy of the same session token, so stamping that record elevates the thief's requests exactly as much as the legitimate browser's. An assurance keyed only to the session token separates nothing.
- **Rotating the session token on step-up — rejected.** `wp_create_nonce()` hashes `wp_get_session_token()`, so rotating the token invalidates **every nonce already rendered into every open admin tab**, including the `_wpnonce` inside the stashed POST the challenge replays (§5.1). Core deliberately re-issues the auth cookie against the *same* token on password change (`wp_update_user()`) precisely to preserve nonces; rotation reverses that. `WP_User_Meta_Session_Tokens::update_session()` is also a non-atomic read-modify-write of the whole `session_tokens` array, so concurrent admin XHRs can lose the rotation and silently leave the thief's copy valid. Rotation, if wanted at all, belongs as an explicit "sign out other sessions" affordance *after* step-up — not an implicit side effect of every elevation.

**The design that survives.** A recent-auth record still lives alongside the login session (so `wp_logout()` / `destroy()` / "log out everywhere" tear it down), but it is a **separate proof**, keyed per session, and **self-authenticating**:

New helpers in a new `wp-includes/user.php` block:

```php
wp_start_reauth_window( int $user_id = 0, string $scope = '' ): bool; // after a challenge; issues the proof, writes the signed record
wp_has_recent_auth( int $user_id = 0, string $scope = '' ): bool;     // gate query (v1: pass no scope — flat freshness)
wp_end_reauth_window( int $user_id = 0 ): void;                       // on logout / credential change / explicit drop
```

Mechanics — each numbered point is a required invariant, not an implementation detail:

1. **Separate proof secret.** On a successful challenge, mint a fresh random secret and set it as a dedicated **httponly** cookie (distinct from the auth cookie), storing only its hash server-side. A session copy that predates the challenge, or a theft that captured the auth cookie but not this proof, cannot present it. (This does *not* stop a session-riding XSS or a full-cookie-jar theft *during* the window; that residual is stated in §2 and is not what the session design defends.)
2. **Keyed per session-token verifier, not per user.** Store the record under the current login session's **verifier** — `hash( 'sha256', wp_get_session_token() )`. Note the distinction: `wp_get_session_token()` returns the *raw* token from the auth cookie, while `WP_Session_Tokens` keys its stored sessions by that token's hash (the verifier). Keying per verifier means a second browser reauthenticating does not overwrite the first's proof. Core supports many concurrent sessions per user; the record must too. (WP Sudo's single per-user proof key is a known limitation — plugin issue #279.)
3. **Self-authenticating (HMAC), binding the proof hash.** `session_tokens` is stored in `user_meta`, a **persistent, poisonable** object-cache group, so an unsigned `reauth_at` is forgeable by a cache-poisoning primitive — exactly the wp2shell class (SQLi used to poison the object cache). Bind the record with `hash_hmac( 'sha256', "$user_id|$verifier|$reauth_at|$scope|$proof_hash", wp_salt( 'auth' ) )`, where `$proof_hash` is the stored `hash('sha256', $proof)` of the browser proof secret, and reject on mismatch. The MAC **must** cover `$proof_hash`: otherwise an attacker who can poison the record keeps its valid signature and swaps in the hash of a cookie they hold, defeating the separate-proof requirement. Documented degradation: weaker when the `AUTH_SALT` family lives in `wp_options` rather than `wp-config.php`. A cache-bypassing `$wpdb` read on the enforcement path is a complement, not a substitute (it does not survive a direct-DB *write*; the HMAC does).
4. **Only the challenge handler writes it.** Core must strip reserved `reauth_*` keys from the `attach_session_information` filter result (that filter sets the *base* session array on every `WP_Session_Tokens::create()`, so any registered callback could otherwise pre-elevate every new session), and the HMAC must cover the verifier so an injected stamp cannot validate.
5. **Read consults the session store, not a cookie string.** `wp_has_recent_auth()` reads the current session record from the store and checks the presented proof hash, the HMAC, and `reauth_at >= time() - $ttl`. Because it reads the store, `destroy_all()` revokes the window within the same request (an improvement over the plugin's cookie-string bind, which lags one request — issue #279).
6. **A fresh `login` timestamp is not proof.** `wp_signon( '', '' )` mints a new session token with a current `login` stamp from a *held cookie* with no credential entered (and SSO / magic-link plugins call `wp_set_auth_cookie()` with no password). So a "forced-login" variant that infers freshness from the new session's `login` time is the rejected shared-token approach in another form. Freshness must come from the explicit, challenge-written record above.
7. **Teardown on credential change clears only `reauth_*` for the target user — never `destroy_all()`.** A password change (`after_password_reset`, and the password path of `wp_update_user()` / `wp_set_password()`, which leave the token record intact) must end the window. Clear the `reauth_*` keys for the affected user's sessions; do **not** call `destroy_all()` (that logs the user out of the very request that changed the password), and do **not** hook `profile_update` blindly — it fires when an admin edits *another* user and on non-password writes (WP Sudo guards this by comparing the password hash; core must too).

- **Multisite: the window's reach follows the auth cookie's domain, not the network.** The record lives with the *current login session*, so it travels exactly as far as the auth cookie that presents that token: shared across a subdirectory network (one cookie domain), isolated per domain on subdomain/mapped-domain networks. Deterministic per-site scoping regardless of cookie domain is deferred to the `consequence.scope` tag and Phase 2 policy (§8).
- **TTL: 15 minutes**, filterable via `wp_reauth_window_ttl` and definable with `WP_REAUTH_WINDOW`. A short **grace** (≈2 min) prevents a multi-step form from re-challenging mid-flow.
- **Lockout is a remediation hazard, not just a rate limit.** Port the plugin's failure model (progressive delay → 5 failures ⇒ 300s lockout, per-user and per-IP), but with two corrections from review: (a) an in-origin XSS from the victim's own IP can burn failed attempts to lock the admin out of the *very actions needed to remediate* (change password, deactivate a plugin), so a lockout must be **clearable out-of-band** (a `WP_DISABLE_ACTION_GATE`-style escape / recovery path — plugin issue #280); and (b) prefer escalating delay over a terminal block for the *action-gate* decision (distinct from the auth-attempt decision).

Open question deferred to review: scope-bound window vs. flat recent-auth freshness. Spec recommends **flat freshness + optional scope tag** for v1.

### 4.3 The gate helper

```php
$gate = wp_check_action_gate( 'core/change-user-password', [
    'actor'   => get_current_user_id(),
    'target'  => $user_id,
    'context' => [ 'changed' => [ 'user_pass' ] ],
] );

$gate->passed();          // bool  — recent auth present (or action not gated / gating disabled)
$gate->needs_challenge(); // bool  — no_recent_auth | expired (an interactive reauth can satisfy it)
$gate->blocked();         // bool  — rate_limited | fail-closed (hard refusal; no challenge offered)
$gate->reason();          // 'passed' | 'no_recent_auth' | 'expired' | 'rate_limited' | 'blocked'
$gate->challenge_url( $return_to ); // string — browser interstitial URL, nonce-protected
$gate->as_wp_error();     // WP_Error — code depends on state (see below): a challengeable
                          //   'sudo_reauth_required' (+ challenge_url) for needs_challenge();
                          //   a terminal 'sudo_blocked' / 'sudo_rate_limited' (NO challenge data)
                          //   for a blocked() decision
```

`wp_check_action_gate()` returns *passed* when gating is globally off, a valid window exists, or an **unknown third-party** action is unregistered — so unguarded callers are never broken. But an unregistered **`core/`** action fails **closed** (`blocked`): a missing built-in means the catalog failed to load, and the guarded mutation must not silently proceed. The global `WP_DISABLE_ACTION_GATE` / `wp_action_gate_enabled` kill-switch is checked *before* that fail-closed branch, so an operator can still recover from a broken catalog load.

**`as_wp_error()` carries two distinct contracts, and this matters for the adapters.** A `needs_challenge()` decision yields code `sudo_reauth_required` **with** a `challenge_url`, which the admin adapter (§5.2) redirects to the interstitial. A `blocked()` decision (rate-limited or fail-closed) yields a **terminal** code (`sudo_rate_limited` / `sudo_blocked`) **without** a `challenge_url`, so adapters render a hard refusal — never a redirect to `wp-login.php?action=reauth`, which for a hard-blocked actor would be an unsatisfiable challenge or a redirect loop. Surface adapters branch on the code, not on the mere presence of a `WP_Error`.

> **Naming (open, see §11-Q1 / proposal §8.2).** The error code `sudo_reauth_required` (and the §5.2 surface responses) inherit WP Sudo's *"sudo"* brand. The rest of this API is core-neutral (`wp_check_action_gate`, `wp_register_action`, "recent-auth window"), and §8 endorses *"sudo mode"* for the window — so this is a deliberate but unsettled choice. Decide `sudo_reauth_required` vs a neutral `reauth_required` / `recent_auth_required` as part of the public-name resolution before the patch, and apply it consistently across the error code, the `wp-login.php?action=reauth` slug, and the REST `code`.

---

## 5. Enforcement architecture

### 5.1 The chokepoint model (the centerpiece)

Every consequential mutation in the catalog already funnels through a small number of core functions that *all* surfaces call. Gate there, once:

```
wp-admin/user-edit.php ─┐
profile.php            ─┤
REST users controller  ─┼──►  wp_update_user() / wp_insert_user()  ──► [GATE]
WP-CLI user update     ─┤
programmatic callers   ─┘
```

Insert the guard near the top of each backing function, **before the write**. Note these functions do not perform capability checks themselves — callers (`edit_user()`, the REST controller) authorize upstream — so the guard is a proof-of-intent layer *on top of* the caller's authorization, not an in-function cap check:

```php
// wp-includes/user.php — inside wp_update_user( $userdata )
$changed = wp_detect_consequential_user_changes( $user_id, $userdata ); // ['user_pass','user_email',...]
foreach ( wp_map_user_changes_to_actions( $user_id, $changed ) as $action_id ) {
    $gate = wp_check_action_gate( $action_id, [
        'actor'  => get_current_user_id(),
        'target' => $user_id,
        'context'=> [ 'changed' => $changed ],
    ] );
    if ( $gate->needs_challenge() || $gate->blocked() ) { // blocked() = rate_limited or fail-closed
        return $gate->as_wp_error(); // a challenge, or a hard-block/rate-limit refusal — both stop the write
    }
}
```

Why this is the right seam:

- **`wp_update_user()` and `wp_insert_user()` already return `WP_Error`**, and every caller — admin `edit_user()`, `WP_REST_Users_Controller::update_item()`, `WP_CLI\...\User`, plugin code — already handles that, so the challenge propagates through existing error paths with no new contract at *those* call sites. **`wp_delete_user()` is the exception — it returns `bool`** (the REST controller's `if ( ! $result )` would read a returned `WP_Error` as *success* → "200 deleted, nothing deleted"), so gating `core/delete-user` needs a distinct adapter or a return-contract change, not the uniform `WP_Error` path. (`WP_User::set_role()` returns `void` and `wp_set_password()` bypasses `wp_update_user()` — the spec must state enforcement **per chokepoint**, §5.1, rather than assume one `WP_Error` return everywhere.)
- One insertion covers admin UI **and** REST **and** CLI **and** programmatic writes. This is what makes the defense complete rather than form-deep — the #20140 lesson made mechanical.
- Self vs. other, password vs. email vs. role are all just fields in `$changed`, mapped to distinct action IDs by `wp_map_user_changes_to_actions()`.

The interactive layer stays thin. `edit_user()` in `wp-admin/includes/user.php` inspects the returned `WP_Error`; if code is `sudo_reauth_required`, it **stashes** the submitted (allowlisted, secrets-redacted) POST and redirects to the challenge, which **replays** on success. That stash/replay is exactly `class-request-stash.php` + `class-challenge.php` and should port near-verbatim (GET ⇒ redirect, POST ⇒ self-submitting form; per-field allowlist; sensitive-suffix redaction; 5-min TTL; ≤5 stashes/user). **Account and credential/principal mutations (password, email, role change, and user creation) are non-replayable** (`stash_no_replay`) and use **reauth-then-resubmit** (this is the single policy; the pitch's "never auto-replay a password/role/email change" and WP Sudo's shipping `stash_no_replay` rules agree). A password's secret *is* the mutation (a redacted replay drops it or persists plaintext); and auto-executing an email/role/create pivot the instant after reauth is exactly the silent principal-manufacture step the gate exists to make deliberate. Only **non-account** actions with no sensitive body (plugin/theme install/activate/switch/delete, re-triggered) replay normally.

### 5.2 Surface adapters

| Surface | Sees | Renders challenge as |
|---|---|---|
| Admin UI (`edit_user`, profile update, plugin actions) | `WP_Error sudo_reauth_required` | Stash request → `wp_safe_redirect()` to `wp-login.php?action=reauth` interstitial → replay |
| Cookie-authed REST | same `WP_Error` from controller | HTTP **403** with `code: sudo_reauth_required`, `data.challenge_url`, `data.expires_in` |
| App-Password REST / XML-RPC (API credential) | same `WP_Error` | **Block + log** (no interactive reauth over a credential channel) |
| wp-cron / WP-CLI / programmatic | evaluated by actor class | **Policy, not blanket block** (§9): core's auto-updater from the configured source is allowed; WP-CLI is allowed by default and operator-configurable; other no-window authenticated calls block |

The decision object never encodes transport. Business functions return errors; adapters localize UX. (Proposal §4.)

### 5.3 Privilege-escalation guard (the promote-to-admin case)

Role changes are the subtlest path and need a dedicated guard, mirroring the plugin's `arm_escalation_guard()` / `newly_grants_administrator()`:

- Hook the capability-meta write, not just `set_role`. Promotion can arrive via `WP_User::set_role()`, `add_role()`, `update_user_meta()` writes to `wp_capabilities`, or `add_user_to_blog()` on multisite. Compare **new effective caps vs. current** and gate only when the delta **newly grants administrator / network-administrator authority**, so demotions and lateral moves are not challenged. This hook covers `update_user_meta`-level writes (the REST `add_role` path, plugin/AJAX caps writes); a **raw `$wpdb` write** to the caps meta fires no hook and is out of the gate's reach — an in-process write, out of scope per §2, and *detecting* it is a non-core concern (§1). (WP Sudo closes that gap in the plugin layer with its lockdown-audit detection; core does not.)
- Enforce at `map_meta_cap` for `promote_user`/`edit_user` **and** at the `wp_update_user`/`set_role` chokepoint, so a REST role change and an admin-UI role change hit the same guard.
- Multisite: gate `grant_super_admin()` unconditionally (highest-consequence promotion).

### 5.4 Code effects — the shared sinks

The terminal code routes are gated at **two shared sinks plus one effect**, not per-page or per-capability, so route multiplicity (admin UI, bulk, AJAX updaters, REST, programmatic) collapses to a handful of insertions:

- **`WP_Upgrader::install_package()`** — the package-write funnel beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, the AJAX updaters, and the REST plugins controller (`core/install-plugin` / `-theme` / `core/update-plugin` / `-theme`). Gate here rather than `Plugin_Upgrader::install()` (which misses bulk/update/AJAX/auto-updater) and rather than the capability layer (`upload_plugins`→`install_plugins` in `map_meta_cap`, so a capability gate cannot separate an attacker ZIP from a repository install and would disrupt CLI/automation). It also covers language-pack writes into `WP_LANG_DIR`, a directory core executes (`.l10n.php` is `include`d).
- **`wp_edit_theme_plugin_file()`** — the single write sink for both the plugin and theme file editors, including the `wp_ajax_edit_theme_plugin_file()` path (`core/edit-plugin-file` / `-theme-file`). Gate the sink *and* challenge early at editor-open so a user does not write a draft only to discover the requirement at save.
- **`activate_plugin()`** (`core/activate-plugin`) — reachable directly via REST `update_item` on an already-installed plugin; success returns **`null`**, `WP_Error` on failure, so adapters key on `is_wp_error()`, not a truthy return.

`delete_plugins()`, `delete_theme()` (`bool|null|WP_Error` — its callers test `is_wp_error()`), and the remaining code effects take the same top-of-function guard returning `WP_Error`; the bulk/AJAX surfaces already thread `WP_Error`, so the interactive adapter stashes and challenges as above. Two chokepoints do not fit the `WP_Error` return path and need a **pre-op adapter** (the §5.1 per-chokepoint rule): `switch_theme()` returns **void**, and the multipart ZIP upload cannot be stashed/replayed — reauthenticate before file selection where possible, otherwise require explicit reselection (§8).

**Non-interactive callers of these sinks are governed by the actor-class policy (§9), not blanket-blocked** — core's own automatic updater drawing from the site's configured update source must keep running, or the gate is a net security regression by stopping background security updates. This is the second half of closing the "admin can just install a backdoor" objection that stalled #20140 for a decade — now covering themes and the file editor, not only plugin install.

---

## 6. Concrete core change list

| # | File | Function / hook | Change |
|---|---|---|---|
| 1 | `wp-includes/actions-api.php` (new) | `wp_register_action`, `wp_get_action(s)`, `wp_action_exists` | Registry API (§4.1) |
| 2 | `wp-includes/class-wp-consequential-actions.php` (new) | registry storage | Pure-data store + core catalog registered **at file load, not `init`** — fail-closed gating plus `init`-time registration would brick any chokepoint reached before `init` (install/import `wp_insert_user`, a `plugins_loaded`-time `wp_update_user`, CLI paths) |
| 3 | `wp-includes/user.php` | `wp_update_user()` | Detect consequential field changes; gate; return `WP_Error` (§5.1) |
| 4 | `wp-includes/user.php` | `wp_insert_user()` | Gate `core/create-user` for authenticated privileged-context inserts only — **not** anonymous registration/guest-checkout (actor 0 → fail-closed) and **not during `wp_installing()`** (the first admin is created before any actor/window exists — the guard must exempt install or it bricks setup); needs a reliable admin-context signal, see §8 |
| 4d | `wp-includes/rest-api/.../class-wp-rest-application-passwords-controller.php` + `wp-admin/authorize-application.php` | issuance | Gate `core/create-application-password` on **both** the REST controller and the no-JS approve flow (§4.1) |
| 4e | `wp-admin/options.php` / `options-general.php` + REST settings + `wp-includes/user.php` (`default_role` read) | `users_can_register` / `default_role` write | Enforce the `core/set-registration-policy` invariant (§4.1): reject a code-capable default role, by effective capability |
| 5 | `wp-admin/includes/user.php` | `wp_delete_user()` (**bool** return) | Gate `core/delete-user` via a **pre-delete adapter** (hook `delete_user` / REST `delete_item`), not a `WP_Error` return |
| 6 | `wp-includes/class-wp-user.php` / `wp-includes/meta.php` | `WP_User::set_role`/`add_role`, `map_meta_cap`, **and `update_user_metadata` on the `{prefix}capabilities` key** | Escalation guard (§5.3); the meta hook catches the REST `add_role` path and `update_user_meta` writes to the caps key — **not** a raw `$wpdb` write, which fires no hook and is out of scope (§5.3) |
| 7 | `wp-includes/ms-functions.php` (`add_user_to_blog`) · `wp-includes/capabilities.php` (`grant_super_admin`) | `add_user_to_blog` (returns `true\|WP_Error` since 5.4); `grant_super_admin` (returns `void`) | Multisite promotion gate via a pre-grant adapter (hook `grant_super_admin` / `add_user_to_blog`) — intercept before the grant, not by return value (a returned `WP_Error` from `add_user_to_blog` is truthy) |
| 8 | `wp-includes/user.php` | `wp_start/has/end_reauth_window` | Per-session, HMAC-signed, separate-proof recent-auth record (§4.2): mint a proof cookie, key per session-verifier, sign with `wp_salt('auth')`, read from the store; **no token rotation** |
| 9 | `wp-includes/class-wp-session-tokens.php` | token record schema + `attach_session_information` | Persist the signed `reauth_*` record keyed per verifier; **strip reserved `reauth_*` keys from the `attach_session_information` filter result** so only the challenge handler can write them; clear on destroy / credential change (not `destroy_all`) |
| 10 | `wp-includes/gate.php` (new) | `wp_check_action_gate()` + decision class | Gate evaluation (§4.3) |
| 11 | `wp-admin/includes/user.php` | `edit_user()` | Catch `sudo_reauth_required` → stash + redirect (§5.1) |
| 12 | `wp-login.php` | new `action=reauth` | Challenge interstitial: password (+2FA hook), rate-limit, replay |
| 13 | `wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php` | update/create/delete | Surface `sudo_reauth_required` as 403 + challenge metadata |
| 14 | `wp-admin/includes/class-wp-upgrader.php` | **`WP_Upgrader::install_package()`** | Gate the shared package-write sink (§5.4) — one insertion covering plugin/theme install, ZIP upload, single + bulk update, and language packs, on every surface; **not** `Plugin_Upgrader::install()` |
| 14b | `wp-admin/includes/file.php` | **`wp_edit_theme_plugin_file()`** | Gate the shared plugin/theme file-editor write sink (both editors + the AJAX path), plus an editor-open early challenge (§5.4) |
| 14c | `wp-admin/includes/plugin.php` | `activate_plugin` (`null`\|`WP_Error`), `delete_plugins` | Gate activation (REST `update_item` reaches it) and delete (§5.4) |
| 15 | `wp-includes/theme.php` · `wp-admin/includes/theme.php` | `delete_theme()` (`bool\|null\|WP_Error`), `switch_theme()` (**void**) | `delete_theme` takes the early `WP_Error` guard (callers check `is_wp_error()`); `switch_theme` (void) uses a **pre-switch adapter** (§5.4). Theme *install/update* is covered by row 14's shared sink |
| 16 | `wp-includes/request-stash.php` (new) | stash/replay | Port `class-request-stash.php` (allowlist, redaction, TTL, per-user cap) |
| 17 | Site Health | new async test | Report registered actions + whether gating is enabled |

Rows 1–2 (the registry) are independently shippable; the gate (rows 3–16) enforces at the chokepoint whether or not the registry has landed. Row 17 is a registry consumer that demonstrates value before any enforcement exists.

---

## 7. Challenge model

Phase 2 baseline (proposal §4, §11) — start small:

- Browser-first interstitial at `wp-login.php?action=reauth`, in the existing authenticated context.
- Password verification against the current user; if a 2FA plugin is present, expose a `wp_reauth_second_factor` hook so it can add **and validate** its factor (the plugin integrates the Two-Factor plugin exactly this way). Core ships no 2FA of its own.
- **The second-factor hook must report an explicit result, not merely render fields.** For the "both factors passed" guarantee below to be implementable, the hook's contract yields one of *pass* / *fail* / *pending* (e.g. a filter returning `true` / `WP_Error` / a pending sentinel); the challenge handler treats a missing or non-affirmative result as **not passed**. The exact signature is a Phase-2 detail; the load-bearing invariant is that *rendering a field ≠ validating it*.
- On success — the password **and** an affirmative *pass* from any factor registered via `wp_reauth_second_factor` — call `wp_start_reauth_window()`, then replay the stashed request. The window never opens on the password step alone when a second factor is present.
- Nonce-protected, rate-limited, lockout on repeated failure.

Explicitly deferred: WebAuthn ceremonies, external IdP redirects, multi-step TOTP/recovery flows, async/pending challenges, consent overlays.

---

## 8. Defaults, config, back-compat

- **Default state.** Ship Phase 1 registry **always on** (inert; naming only). Ship Phase 2 gating **on for the core catalog by default**, because a security default that must be discovered protects almost no one. Make the window generous (15 min) and every **non-account** consequential path stash-and-replay so the UX cost is one reauth prompt, not lost work. Account/credential/principal mutations (password, email, role, create) are **reauth-then-resubmit** per §5.1, never auto-replayed, so the cost there is one reauth plus re-submitting the form. Provide `WP_DISABLE_ACTION_GATE` for emergencies and a per-action `wp_action_gate_enabled` filter. **Exception — `core/create-user`:** default-on gating would fail-closed on (a) unauthenticated self-registration / guest checkout (actor 0, no window possible) **and (b) WordPress installation itself** — the first administrator is created via `wp_insert_user()` before any actor or recent-auth proof can exist, so the file-load fail-closed guard must **exempt `wp_installing()`** or it bricks install. Gate `create-user` only for *authenticated, privileged-context* inserts — never the anonymous registration path and never during install (`wp_installing()`) — or default it off. This needs a reliable admin-context signal (`is_admin()` is not one; scope by an authenticated actor holding `create_users` via a non-registration entry point). Open: §11-Q4/Q5.
- **Config surface.** `WP_REAUTH_WINDOW` (ttl), `wp_reauth_window_ttl` / `wp_action_gate_enabled` / `wp_consequential_actions` (catalog) filters. Keep the plugin's `Disabled/Limited/Unrestricted` per-surface policy vocabulary **out** of core v1 (proposal §11-Q5); core v1 is binary per action.
- **Back-compat.** Because enforcement returns existing `WP_Error` types from functions that already return them, non-updated callers degrade safely to "action refused with an actionable error," never a fatal or a silent pass. Programmatic callers that must bypass (migrations; trusted automation under WP-CLI/cron, which have **no auth cookie or session token**, so `wp_start_reauth_window()` — a browser-session API — cannot help them) short-circuit via the `wp_action_gate_enabled` filter or a scoped constant, **not** the session-window API.
- **Multisite terminology** (#37593/#39174): "network administrator" for ordinary network authority, "super admin" only for core's technical concept, "sudo mode" for the temporary window. No permanent role is introduced.

---

## 9. Non-interactive callers: branch on actor class, not transport

The gate's *decision* ("may this effect proceed now?") is transport-agnostic; only the *rendering* of a challenge is interactive (§3.6). Excluding non-interactive routes from **enforcement** because they cannot render a **challenge** is a category error that leaves the modern scriptable routes open (REST install/activate is reachable with a stolen cookie plus a harvested `wp_rest` nonce). So the chokepoint evaluates **actor class**, and non-interactive routes get a **policy**, not an exemption:

| Actor at the sink | Decision |
|---|---|
| Interactive cookie session, no window | **Challenge** (full-page, §7) |
| API credential (Application Password) / XML-RPC, no window | **Block + log** — no interactive reauth is possible over a credential channel |
| No actor, **and** core's own automatic updater, **and** package from the site's configured update source | **Allow** — background security updates must keep working; a blanket block here is a net security regression |
| WP-CLI | **Allow by default, operator-configurable** — shell access already dominates the gate (anyone who can `wp plugin install` can `cp` a PHP file into `WP_PLUGIN_DIR`), so a CLI block buys nothing against that attacker and costs every deployment pipeline |

What v1 defers is not whole surfaces but the **per-surface policy UI** and any attempt to render an interactive challenge on a non-interactive surface. The block/allow decisions above still enforce in v1.

Third-party transports (WPGraphQL, custom REST/RPC endpoints) are **not** core surfaces to enumerate: the chokepoint gate applies to their mutations regardless, and each request resolves to one of the actor classes above. Richer per-surface policy for them is the plugin's job (proposal §9).

---

## 10. Acceptance criteria

A conforming implementation must show:

1. A stolen-cookie session cannot perform **any** initial-catalog action — every account change (own/other password, own/other email), user create/delete, promotion, and plugin/theme install / activate / switch / delete — without a fresh challenge, exercised on **each surface that exposes it** (admin UI, and cookie-REST where the route exists). No catalog action may be left untested; add coverage as the catalog grows.
2. An admin can change another user's password **without knowing it**, after reauthenticating themselves (the #20140 correctness requirement in #8/#9).
3. Logout / "log out everywhere" / password change **immediately** invalidate the reauth window (session-token binding).
4. A gated **non-account** POST (plugin/theme action) that is challenged **replays** with **no data loss**; every **account/credential/principal** mutation (password, email, role change, user creation) is **reauth-then-resubmit**, never auto-replayed, with any secret **never written to the stash** (§5.1, §8). An executable ZIP upload is likewise not stashed — reauth then reselect.
5. Demotions and lateral role changes are **not** challenged; only new grants of admin/network-admin authority are.
6. A built-in action whose gate cannot be evaluated **fails closed**.
7. Non-interactive callers are resolved by **actor class** (§9): an API credential blocks-and-logs; core's automatic updater from the configured source is allowed; WP-CLI is allowed by default. None silently passes an *interactive*-class request without a window.
8. With gating disabled, behavior is byte-for-byte current core.
9. Application-Password issuance is gated on **both** the REST controller and the `authorize-application.php` no-JS approve flow — a cookie session cannot mint a durable API credential without a fresh challenge.
10. `users_can_register` / `default_role` **cannot** be set to a code-capable role (by effective capability, including `activate_plugins` / theme-switch) — enforced server-side, including via REST and the omitted-role case, not just hidden in the UI.
11. **WordPress installation and CLI setup succeed**: the first administrator is created during `wp_installing()` with no gate, and no pre-`init` chokepoint is bricked by the file-load fail-closed catalog.

A Playground blueprint reproducing a stolen-session takeover and showing where the challenge lands (as in `dknauss/consequential-actions`) should accompany the patch.

---

## 11. Open questions (for core review)

1. Registry-in-core vs. consequence-metadata layered on the **Abilities API** (which now exists and already provides namespacing + execution hooks). The lighter landing may be to *not* build a second registry — annotate abilities instead. Strongest fresh argument since the old #20140 comments; worth settling first. **Resolved (July 2026):** a **standalone** consequence-actions registry now, Abilities-aligned in its ID convention, with reading consequence-annotated abilities left as a deferred extension (nothing populates the ability side yet). Not "abilities-only," and not a registry needlessly incompatible with Abilities. The decision is folded into §4.1.1 above (full memo archived at [`archive/core-actions-registry-vs-abilities-decision.md`](archive/core-actions-registry-vs-abilities-decision.md)). The public name for the API remains open (proposal §8.2).
2. `WP_Session_Tokens` extension vs. a dedicated store (proposal §11-Q2).
3. Flat recent-auth freshness vs. scope-bound windows for v1 (§4.2) — on multisite the window's cross-site reach follows the auth cookie's domain (carries across a shared-cookie subdirectory network, isolated per domain otherwise); scope-bound windows are the lever for deterministic per-site scoping.
4. Should `core/create-user` gate *all* inserts or only privileged-context ones (registration/import would otherwise trip it)?
5. Default-on vs. default-off for Phase 2 gating (§8) — the single biggest adoption/impact tradeoff.

---

## 12. Reviewer findings / known gaps (design review, July 2026)

Adversarial design reviews — two-model (Fable + Opus), then a **Codex** pass — of this spec and the proposal. They **validated the problem framing and identified a candidate mechanism** (gate-at-the-sink, per-session HMAC separate-proof, actor-class branch, fail-closed) — **not a ready-to-land security design.** Several open items are **P1**, so the closure claim should not be presented as settled while they stand: some are mechanical, but the ambient-bearer / auto-replay problem (#315) and the self-email seam (#316) undercut load-bearing claims until resolved. Tracked as GitHub issues **#302–#320** (high-severity below; #311 medium/low tracker; #320 a strategic scoping recommendation). The high-severity gaps:

1. **Core update is ungated (#302).** No `core/update-core` entry; `Core_Upgrader::upgrade()` calls `update_core()` without routing through `install_package()` — the attack-tree already gates it as `core.update`. Add the entry; gate `update_core()` explicitly.
2. **The `do_action()` pre-op adapters cannot block (#303).** §5.4 / §6 gate `delete_user` / `grant_super_admin` / `add_user_to_blog` / `switch_theme` via hooks whose return is discarded — the same defect §4.1.1 uses to reject the Abilities API. Gate in-function or via *vetoing* filters (e.g. `pre_update_option_template`/`_stylesheet`).
3. **Gating `wp_set_password()` bricks the unauthenticated reset (#304).** The key-verified `reset_password()` path runs at actor 0; add an explicit carve-out, and state the residual mail-channel trust in §3's invariant.
4. **The mandatory password step locks out SSO/passkey/passwordless accounts (#305).** §7 needs a factor-only / provider-supplied-proof path where no core password exists.
5. **Default-on hard-block of App-Password/automation callers is a back-compat regression (#306).** §9 + §8 default-on silently break headless/CI/deploy/cron ecosystems and silently fail programmatic `WP_Error`-ignoring callers. Reconsider the default or add an opt-in allowance for authenticated non-interactive callers.
6. **The auto-updater ALLOW branch depends on a provenance primitive core lacks (#307).** Define a non-filterable trusted flag (set inside `WP_Automatic_Updater`) or narrow the ALLOW to core-signed packages.
7. **Flat freshness lets a trivial action authorize the most consequential ones (#308).** Use the `consequence.scope` field for code-execution and credential-issuance classes in v1 (moves §11-Q3 toward scoped for those classes).
8. **`map_meta_cap` enforcement conflates authz with proof-of-intent and invites recursion (#309).** Drop the §5.3 `map_meta_cap` insertion (the caps-meta-write + `wp_update_user`/`set_role` seams already cover the writes) or make it non-blocking.
9. **HMAC forgery-resistance is conditional on `AUTH_SALT` placement (#310).** With the salts in `wp_options`, the wp2shell SQLi read (§10) forges the MAC. State salts-in-`wp-config.php` as a hard precondition (§4.2), not a footnote.

A later **Codex** pass added two more high-severity design blockers not covered above:

10. **The proof cookie is an ambient bearer — a pre-window clone can drive the reauthed browser (#315, P1).** Preserving the shared session token (to keep nonces valid) lets a cookie clone harvest valid nonces; with the proof cookie `SameSite=Lax`, an attacker harvests an activation nonce pre-reauth, then navigates the *victim's* browser (top-level GET) to a crafted action URL after the victim reauths — the victim supplies the fresh proof, the attacker the nonce, and the gate passes. The auto-replay stash (keyed per-`user_id` only) is a second vector. **This breaks the "a pre-reauth copied cookie cannot act" claim through the legitimate browser.** Fix: drop auto-replay for **all** consequential actions; after reauth render a canonical confirmation page and require a one-time, **proof-bound intent token** over an action/target digest by POST — **not** a WP nonce (a clone can mint those).
11. **The self-email gate is on an observational `do_action` (#316, P1).** §4.1 routes `core/change-own-email` to `personal_options_update`, whose return is discarded — it cannot veto the pending-email write, so this Group-B pivot has no working enforcement on the profile surface; and on multisite the confirmation path writes the signup row *before* `wp_update_user()`, leaving partial state. Gate at a veto-capable seam before `send_confirmation_on_profile_email()` runs, gate the confirmation endpoint, mark pending `_new_email` as initiated-under-recent-auth, and write only after the gate passes.
12. **Proof issuance is not atomic with cookie delivery (#319, medium).** The PoC writes the server proof hash, skips `setcookie()` when `headers_sent()`, ignores its return, and returns `true` regardless — so on a header-already-sent or cookie failure the server invalidates the old proof but the browser gets no new one → an unrecoverable reauth loop. It also covers only `COOKIEPATH`/`ADMIN_COOKIE_PATH` (missing `PLUGINS_COOKIE_PATH`/`SITECOOKIEPATH`). Fail before mutating server state; roll back on cookie-write failure; one canonical cookie policy.

These interact with §11's open questions (notably Q2 session store, Q3 scope-bound windows, Q5 default-on). The medium/low items — app-password shared sink, the registration invariant being point-in-time, actor-class detection, option-write activation bypass, `WP_Session_Tokens` pluggability, multisite super-admin/network-option, the REST silent-200, and four mechanism gaps — are tracked in #311. **Strategic scoping (#320):** the minimal closure's breadth (official installs, updates, language packs, activation, deletion, theme switch, connector credentials, origin settings) drives most of #302/#306/#307. A more landable split is a **v1** recent-auth primitive + browser/cookie-auth direct-code effects + identity pivots, with a **separately scoped** provenance/policy project for auto-updates, API credentials, CLI, cron, and generic programmatic callers.

---

*Contributor credits for WP Sudo's design lineage (Blackbourn, Nash, Alkan) live in the plugin readme's Acknowledgements section. Prior art is cited impersonally in the body — principally Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140) (recent auth for consequential actions) and [#16470](https://core.trac.wordpress.org/ticket/16470) (single-site email-change confirmation, whose `send_confirmation_on_profile_email()` flow §4.1 must accommodate).*
