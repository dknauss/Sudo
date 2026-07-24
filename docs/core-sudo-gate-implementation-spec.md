# Core Implementation Spec: A Recent-Auth Gate for Consequential Actions

**Status:** Draft engineering spec, not adopted by WordPress core.
**Drafted:** July 2026
**Companion to:** [`core-action-gate-proposal.md`](core-action-gate-proposal.md) (the *why* and phasing). This document is the *what to change in core*.
**Relates to:** Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140), [#37593](https://core.trac.wordpress.org/ticket/37593), [#39174](https://core.trac.wordpress.org/ticket/39174).
**Prior art:** WP Sudo 4.7.0 — `includes/class-gate.php`, `class-sudo-session.php`, `class-action-registry.php`, `class-request-stash.php`, `class-challenge.php`.

---

## 1. Goal and non-goals

**Goal.** Give WordPress core a built-in way to require *fresh proof of human intent from the actor* before a small set of consequential operations proceed — regardless of which role the actor holds or which surface the request enters through. The concrete target is the failure mode behind #20140 and the broader class of incidents dominating recent WordPress security reports: **an authenticated-but-illegitimate session** (stolen cookie, walked-away device, XSS in an admin origin, a hijacked or maliciously-created Editor/Admin) performing account changes and privilege escalation.

The security boundary is **recent authentication of the actor**, not the target user's old password. That reframing is what lets an admin change another user's password without knowing it while still proving who is at the keyboard, and it unifies three cases that #20140 argued separately: changing your own password, changing another user's password/email, and promoting a user.

**Non-goals** (kept deliberately out so this can land):

- Not a replacement for `current_user_can()`. This sits *above* capabilities; it never grants authority, only demands a fresh proof before an already-authorized action runs.
- Not a plugin sandbox or runtime isolation. It constrains *declared operations that pass through core chokepoints*; it cannot stop arbitrary code already running in-process. (See proposal §2.5, §4.5.)
- Not a new login system or 2FA framework. It consumes existing authenticated identity and existing session infrastructure.
- Not a WAF. It gates named operations; it does not inspect traffic.
- Phase 1 targets **browser + cookie-authenticated REST**. Application Passwords, WP-CLI, cron, XML-RPC are explicitly deferred (§9).

---

## 2. Threat model

| Actor | Has valid session? | Has capability? | Defended by this spec? |
|---|---|---|---|
| Stolen/replayed admin cookie | yes | yes | **Yes** — no recent auth ⇒ challenge |
| XSS running in an authed admin origin | yes | yes | **Yes** — cannot silently satisfy an interactive reauth |
| Walk-away / shared workstation | yes | yes | **Yes** — window expires; consequential action re-challenges |
| Malicious Editor escalating to Admin | yes | yes (edit/promote) | **Yes** — promotion is a gated effect |
| Attacker who knows the password | yes | yes | Partially — reauth still forces a deliberate, loggable step and blocks silent replay |
| Compromised plugin executing in-process | n/a | n/a | **No** — out of scope (needs runtime isolation) |
| Missing `current_user_can()` in a handler | n/a | n/a | **No** — that's an authz bug, orthogonal |

The value is concentrated on the top five rows, which are precisely the "broken access control / privilege escalation / session hijack" categories driving current WordPress CVEs.

---

## 3. Core design decisions

These are the load-bearing choices, each validated in WP Sudo production:

1. **Gate the effect, not the form field.** Gating one password input is security theater — a hijacked session skips the form and calls the mutation directly (create a new admin, change email + reset, use the installer). Enforcement must sit at the **data-layer chokepoint every surface funnels through**, so browser, REST, and programmatic callers are covered by one guard. (§5.)
2. **Role-agnostic.** Any logged-in user attempting a gated action is challenged; the gate never reasons about roles. Capability checks remain core's job and run unchanged.
3. **Recent-auth window ("sudo mode"), not forced re-login.** Terminating the session on every sensitive change is heavier than the problem needs. The primitive is a short, revocable elevated window — the GitHub sudo-mode pattern, and the model jeremyfelt/johnjamesjacoby sketched in #37593/#39174. This walks back the "terminate session" idea floated earlier in #20140.
4. **Registry separate from gate.** A queryable catalog of consequential actions (Phase 1) has standalone value for audit, Site Health, and UI, and is far more landable than a challenge framework. The gate (Phase 2) is a *consumer* of it.
5. **Fail closed for core actions.** If a built-in consequential action cannot be evaluated (malformed matcher, storage error), the mutation is refused, not allowed.
6. **Transport-agnostic decision, transport-specific rendering.** The chokepoint returns a decision (as a `WP_Error` in practice, §5.2). Each surface adapter decides how to present it: browser redirects to a challenge, REST emits a 403 with challenge metadata, CLI prints an instruction.

---

## 4. New core APIs

### 4.1 Consequential Actions registry (Phase 1)

A pure-data registry. No hooks, no enforcement — just naming and metadata. New file `wp-includes/actions-api.php` + `wp-includes/class-wp-consequential-actions.php`.

```php
wp_register_action( 'core/change-user-password', [
    'label'             => __( 'Change another user’s password' ),
    'capabilities'      => [ 'edit_user' ],
    'category'          => 'user-management',
    'consequence_class' => 'account-takeover',      // privilege-escalation | account-takeover | code-execution | destructive
    'scope'             => 'users',                 // reauth reuse grouping for the gate
    'annotations'       => [
        'destructive'          => false,
        'requires_recent_auth' => true,
    ],
] );

wp_get_action( 'core/change-user-password' ); // array|null
wp_get_actions();                             // array<string,array>
wp_action_exists( $id );                      // bool
```

Naming follows the Abilities API shape `namespace/action-name` (lowercase, hyphens, one slash) — see proposal §6. Actions are *not* forced into the Abilities object model; some map to abilities later, some wrap legacy flows. Per [`core-actions-registry-vs-abilities-decision.md`](core-actions-registry-vs-abilities-decision.md), `wp_get_action(s)` is a **union** query surface: it returns these standalone entries *and* any registered ability carrying a `consequence` annotation, so the gate reads one surface and never branches on the source. The catalog below is registered as standalone entries because none of these operations are abilities today.

**Initial core catalog** (small on purpose — proposal §8):

| Action ID | Backing core chokepoint |
|---|---|
| `core/change-own-password` | `wp_update_user()` when `$user_id === get_current_user_id()` and `user_pass` changes |
| `core/change-user-password` | `wp_update_user()` on another user, `user_pass` changes |
| `core/change-own-email` | profile self-email change — **gate the initiating request**, not only `wp_update_user()`: with confirmation enabled, `send_confirmation_on_profile_email()` writes `_new_email` and restores `$_POST['email']` to the old value *before* `wp_update_user()` runs, so the chokepoint sees no change; detect at `personal_options_update` / the REST self-update |
| `core/change-user-email` | `wp_update_user()` other user, `user_email` changes |
| `core/create-user` | `wp_insert_user()` (returns `WP_Error`); on multisite `wpmu_create_user()` returns `int\|false`, so the gate needs a distinct adapter there (REST multisite create checks `if ( ! $user_id )`) |
| `core/delete-user` | `wp_delete_user()` / `wpmu_delete_user()` |
| `core/promote-user` | role change granting administrator / network-admin authority (see §5.3) |
| `core/activate-plugin` | `activate_plugin()` |
| `core/install-plugin` | `Plugin_Upgrader::install()` |
| `core/delete-plugin` | `delete_plugins()` |

The account-change rows are the direct #20140 deliverable. The plugin/user rows close the bypass paths that make field-only gating theater.

### 4.2 Recent-auth window, built on `WP_Session_Tokens`

**Recommendation: store reauth state inside the login session token record, not in user meta.** WP Sudo uses user meta + a browser-bound cookie because a plugin cannot extend core's session store; core can and should. Extending `WP_Session_Tokens` gives revocation, logout teardown, and cross-session replay resistance for free.

New helpers in `wp-includes/pluggable.php` / a new `wp-includes/user.php` block:

```php
wp_start_reauth_window( int $user_id = 0, string $scope = '' ): bool; // after a challenge; TTL comes from WP_REAUTH_WINDOW/filter, not an arg
wp_has_recent_auth( int $user_id = 0, string $scope = '' ): bool;     // gate query (v1: pass no scope — flat freshness)
wp_end_reauth_window( int $user_id = 0 ): void;                   // on logout / explicit drop
```

Mechanics:

- On a successful challenge, write `reauth_at` (unix ts) and optional `reauth_scope` into the **current** session token's stored array (`WP_Session_Tokens::update`). Binding to the session token means the elevated window is destroyed automatically by `wp_logout()`, `WP_Session_Tokens::destroy()`, and "log out everywhere."
- **A password change must clear the window explicitly** — the session-token binding does *not* cover it. `wp_update_user()` clears and immediately re-issues the auth cookie against the *same* token, and `wp_set_password()` doesn't touch session-token records, so `reauth_at` can survive a password change and violate the acceptance criterion that a password change invalidates the window. Hook `after_password_reset` and the password path of `wp_update_user()` / `wp_set_password()` to call `wp_end_reauth_window()` (or destroy the record).
- `wp_has_recent_auth()` reads the *current request's* session token record and checks `reauth_at >= time() - $ttl`. Because it consults the session store (not just a cookie string), `destroy_all()` revokes the window on the next request — an improvement over the plugin's cookie-string bind, which is documented there as taking effect one request later.
- Default TTL: **15 minutes**, filterable via `wp_reauth_window_ttl` and definable with `WP_REAUTH_WINDOW` in `wp-config.php`. A short **grace** (≈2 min, as in the plugin) prevents a multi-step form from re-challenging mid-flow.
- Rate limiting / lockout on failed challenges: port the plugin's model (5 failures ⇒ 300s lockout, progressive delays, per-user and per-IP) into the challenge handler.

Open question deferred to review (proposal §12, §18-Q4): scope-bound window vs. flat recent-auth freshness. Spec recommends **flat freshness + optional scope tag** for v1: simpler, and scope can tighten later without a data migration.

### 4.3 The gate helper

```php
$gate = wp_check_action_gate( 'core/change-user-password', [
    'actor'   => get_current_user_id(),
    'target'  => $user_id,
    'context' => [ 'changed' => [ 'user_pass' ] ],
] );

$gate->passed();          // bool  — recent auth present (or action not gated / gating disabled)
$gate->needs_challenge(); // bool
$gate->reason();          // 'passed' | 'no_recent_auth' | 'expired' | 'rate_limited' | 'blocked'
$gate->challenge_url( $return_to ); // string — browser interstitial URL, nonce-protected
$gate->as_wp_error();     // WP_Error 'sudo_reauth_required' with challenge data in $error_data
```

`wp_check_action_gate()` returns *passed* when gating is globally off, a valid window exists, or an **unknown third-party** action is unregistered — so unguarded callers are never broken. But an unregistered **`core/`** action fails **closed** (`blocked`): a missing built-in means the catalog failed to load, and the guarded mutation must not silently proceed. The global `WP_DISABLE_ACTION_GATE` / `wp_action_gate_enabled` kill-switch is checked *before* that fail-closed branch, so an operator can still recover from a broken catalog load.

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

The interactive layer stays thin. `edit_user()` in `wp-admin/includes/user.php` inspects the returned `WP_Error`; if code is `sudo_reauth_required`, it **stashes** the submitted (allowlisted, secrets-redacted) POST and redirects to the challenge, which **replays** on success. That stash/replay is exactly `class-request-stash.php` + `class-challenge.php` and should port near-verbatim (GET ⇒ redirect, POST ⇒ self-submitting form; per-field allowlist; sensitive-suffix redaction; 5-min TTL; ≤5 stashes/user). **Password (and other secret-bearing) changes are non-replayable** (`stash_no_replay`): the secret *is* the mutation, so a "secrets-redacted" replay would either drop the password (silent no-op) or persist/replay plaintext — those rules use reauth-then-resubmit, while non-secret changes (email, role, plugin actions) replay normally.

### 5.2 Surface adapters

| Surface | Sees | Renders challenge as |
|---|---|---|
| Admin UI (`edit_user`, profile update, plugin actions) | `WP_Error sudo_reauth_required` | Stash request → `wp_safe_redirect()` to `wp-login.php?action=reauth` interstitial → replay |
| Cookie-authed REST | same `WP_Error` from controller | HTTP **403** with `code: sudo_reauth_required`, `data.challenge_url`, `data.expires_in` |
| WP-CLI / cron / XML-RPC / App-Password REST | same `WP_Error` | **Phase 1: hard-block + log** (no interactive challenge possible); interactive reauth deferred to Phase 2 (§9) |

The decision object never encodes transport. Business functions return errors; adapters localize UX. (Proposal §11 transport separation.)

### 5.3 Privilege-escalation guard (the promote-to-admin case)

Role changes are the subtlest path and need a dedicated guard, mirroring the plugin's `arm_escalation_guard()` / `newly_grants_administrator()`:

- Hook the capability-meta write, not just `set_role`. Promotion can arrive via `WP_User::set_role()`, `add_role()`, direct `wp_capabilities` usermeta writes, or `add_user_to_blog()` on multisite. Compare **new effective caps vs. current** and gate only when the delta **newly grants administrator / network-administrator authority** — so demotions and lateral moves aren't challenged.
- Enforce at `map_meta_cap` for `promote_user`/`edit_user` **and** at the `wp_update_user`/`set_role` chokepoint, so a REST role change and an admin-UI role change hit the same guard.
- Multisite: gate `grant_super_admin()` unconditionally (highest-consequence promotion).

### 5.4 Plugin/theme actions

`activate_plugin()`, `delete_plugins()`, and `Plugin_Upgrader::install()` get the same top-of-function guard returning `WP_Error`. The bulk/AJAX plugin surfaces already thread `WP_Error`, so the interactive adapter stashes and challenges as above. This is the second half of closing the "admin can just install a backdoor" objection that stalled #20140 for a decade.

---

## 6. Concrete core change list

| # | File | Function / hook | Change |
|---|---|---|---|
| 1 | `wp-includes/actions-api.php` (new) | `wp_register_action`, `wp_get_action(s)`, `wp_action_exists` | Registry API (§4.1) |
| 2 | `wp-includes/class-wp-consequential-actions.php` (new) | registry storage | Pure-data store + core catalog bootstrap on `init` |
| 3 | `wp-includes/user.php` | `wp_update_user()` | Detect consequential field changes; gate; return `WP_Error` (§5.1) |
| 4 | `wp-includes/user.php` | `wp_insert_user()` | Gate `core/create-user` for admin-context inserts |
| 5 | `wp-admin/includes/user.php` | `wp_delete_user()` (**bool** return) | Gate `core/delete-user` via a **pre-delete adapter** (hook `delete_user` / REST `delete_item`), not a `WP_Error` return |
| 6 | `wp-includes/class-wp-user.php` / `wp-includes/meta.php` | `WP_User::set_role`/`add_role`, `map_meta_cap`, **and `update_user_metadata` on the `{prefix}capabilities` key** | Escalation guard (§5.3); the meta hook catches the REST `add_role` path and any AJAX/plugin write to the caps meta directly |
| 7 | `wp-includes/ms-functions.php` | `add_user_to_blog`, `grant_super_admin` (**bool/void** — no `WP_Error`) | Multisite promotion gate via a pre-grant adapter (hook `grant_super_admin` / `add_user_to_blog`), not a return value |
| 8 | `wp-includes/pluggable.php` | `wp_start/has/end_reauth_window` | Recent-auth window on `WP_Session_Tokens` (§4.2) |
| 9 | `wp-includes/class-wp-session-tokens.php` | token record schema | Persist `reauth_at`, `reauth_scope`; clear on destroy |
| 10 | `wp-includes/gate.php` (new) | `wp_check_action_gate()` + decision class | Gate evaluation (§4.3) |
| 11 | `wp-admin/includes/user.php` | `edit_user()` | Catch `sudo_reauth_required` → stash + redirect (§5.1) |
| 12 | `wp-login.php` | new `action=reauth` | Challenge interstitial: password (+2FA hook), rate-limit, replay |
| 13 | `wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php` | update/create/delete | Surface `sudo_reauth_required` as 403 + challenge metadata |
| 14 | `wp-admin/includes/plugin.php` + `wp-admin/includes/class-plugin-upgrader.php` | `activate_plugin`, `delete_plugins`, **`Plugin_Upgrader::install()`** (`core/install-plugin`) | Gate plugin actions (§5.4) |
| 15 | `wp-includes/request-stash.php` (new) | stash/replay | Port `class-request-stash.php` (allowlist, redaction, TTL, per-user cap) |
| 16 | Site Health | new async test | Report registered actions + whether gating is enabled |

Rows 1–2 are Phase 1 and shippable alone. Rows 3–15 are Phase 2. Row 16 is a Phase-1 consumer that demonstrates value before any enforcement exists.

---

## 7. Challenge model

Phase 2 baseline (proposal §13) — start small:

- Browser-first interstitial at `wp-login.php?action=reauth`, in the existing authenticated context.
- Password verification against the current user; if a 2FA plugin is present, expose a `wp_reauth_second_factor` hook so it can add its factor (the plugin integrates the Two-Factor plugin exactly this way). Core ships no 2FA of its own.
- On success: `wp_start_reauth_window()`, then replay the stashed request.
- Nonce-protected, rate-limited, lockout on repeated failure.

Explicitly deferred: WebAuthn ceremonies, external IdP redirects, multi-step TOTP/recovery flows, async/pending challenges, consent overlays.

---

## 8. Defaults, config, back-compat

- **Default state.** Ship Phase 1 registry **always on** (inert; naming only). Ship Phase 2 gating **on for the core catalog by default**, because a security default that must be discovered protects almost no one — but make the window generous (15 min) and every consequential path stash-and-replay so the UX cost is one password prompt, not lost work. Provide `WP_DISABLE_ACTION_GATE` for emergencies and a per-action `wp_action_gate_enabled` filter.
- **Config surface.** `WP_REAUTH_WINDOW` (ttl), `wp_reauth_window_ttl` / `wp_action_gate_enabled` / `wp_consequential_actions` (catalog) filters. Keep the plugin's `Disabled/Limited/Unrestricted` per-surface policy vocabulary **out** of core v1 (proposal §18-Q5); core v1 is binary per action.
- **Back-compat.** Because enforcement returns existing `WP_Error` types from functions that already return them, non-updated callers degrade safely to "action refused with an actionable error," never a fatal or a silent pass. Programmatic callers that must bypass (migrations; trusted automation under WP-CLI/cron, which have **no auth cookie or session token**, so `wp_start_reauth_window()` — a browser-session API — cannot help them) short-circuit via the `wp_action_gate_enabled` filter or a scoped constant, **not** the session-window API.
- **Multisite terminology** (#37593/#39174): "network administrator" for ordinary network authority, "super admin" only for core's technical concept, "sudo mode" for the temporary window. No permanent role is introduced.

---

## 9. Deferred surfaces

Non-interactive surfaces cannot present an interactive challenge, so v1 **hard-blocks + logs** consequential actions from them rather than pretending to gate. Phase 2+ can add per-surface policy (allow/block/require-app-level-proof):

- Application-Password REST and XML-RPC (API credentials, not human sessions)
- WP-CLI (no browser)
- wp-cron (no actor)
- WPGraphQL

Rationale: these have materially different trust and operator expectations and should not be bundled into the first primitive (proposal §14).

---

## 10. Acceptance criteria

A conforming implementation must show:

1. A stolen-cookie session cannot change any catalog field or promote a user without a fresh challenge — verified identically via admin UI **and** cookie-REST.
2. An admin can change another user's password **without knowing it**, after reauthenticating themselves (the #20140 correctness requirement in #8/#9).
3. Logout / "log out everywhere" / password change **immediately** invalidate the reauth window (session-token binding).
4. A gated POST that is challenged replays with **no data loss** and **no secret leakage** into the stash.
5. Demotions and lateral role changes are **not** challenged; only new grants of admin/network-admin authority are.
6. A built-in action whose gate cannot be evaluated **fails closed**.
7. Non-interactive surfaces **block-and-log**, never silently pass.
8. With gating disabled, behavior is byte-for-byte current core.

A Playground blueprint reproducing a stolen-session takeover and showing where the challenge lands (as in `dknauss/consequential-actions`) should accompany the patch.

---

## 11. What WP Sudo becomes if core ships this

WP Sudo stops being a full sudo implementation and becomes (proposal §16): opinionated stricter defaults, operator UI and diagnostics, per-surface policy for the deferred surfaces core leaves as block-only, multisite policy hierarchy, richer 2FA/passkey challenge providers, and compatibility bridges. The registry and the recent-auth primitive move to core; the policy and UX product stays in the plugin.

---

## 12. Open questions (for core review)

1. Registry-in-core vs. consequence-metadata layered on the **Abilities API** (which now exists and already provides namespacing + execution hooks). The lighter landing may be to *not* build a second registry — annotate abilities instead. Strongest fresh argument since the old #20140 comments; worth settling first. **Resolved (July 2026):** neither pure form — a thin consequence-annotation schema with a single source-blind query surface populated by standalone entries *and* consequence-annotated abilities. See [`core-actions-registry-vs-abilities-decision.md`](core-actions-registry-vs-abilities-decision.md). The public name for the annotation API remains open (§4.0 of the proposal).
2. `WP_Session_Tokens` extension vs. a dedicated store (proposal §12).
3. Flat recent-auth freshness vs. scope-bound windows for v1 (§4.2).
4. Should `core/create-user` gate *all* inserts or only privileged-context ones (registration/import would otherwise trip it)?
5. Default-on vs. default-off for Phase 2 gating (§8) — the single biggest adoption/impact tradeoff.
