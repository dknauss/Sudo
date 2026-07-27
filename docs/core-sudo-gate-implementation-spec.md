# Core Implementation Spec: A Recent-Auth Gate for Consequential Actions

**Status:** Superseded broad engineering inventory; not an implementation plan
and not adopted by WordPress core.
**Drafted:** July 2026
**Companion to:** [`core-action-gate-proposal.md`](core-action-gate-proposal.md).
**Relates to:** Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140) (the recent-auth-for-consequential-actions discussion — the directly relevant ticket) and [#16470](https://core.trac.wordpress.org/ticket/16470) (single-site email-change confirmation, fixed in 4.9 — the `send_confirmation_on_profile_email()` flow §4.1 must accommodate). Multisite *terminology* here follows [#37593](https://core.trac.wordpress.org/ticket/37593) / [#39174](https://core.trac.wordpress.org/ticket/39174) (network-role naming, §8) — those are **not** recent-auth prior art.
**Research prior art:** WP Sudo — `includes/class-gate.php`,
`class-sudo-session.php`, `class-action-registry.php`,
`class-request-stash.php`, `class-challenge.php`.

> **Current authority and scope (July 2026).** The
> [Action Gate Research Program](../.planning/action-gate-architecture-charter.md)
> and its [GSD roadmap](../.planning/ROADMAP.md) supersede this document for
> implementation. The active vertical slice contains only plugin/theme upload
> and plugin/theme file-editor save. This file preserves the broader seam,
> attacker, and route inventory so later phases do not have to rediscover it.
> Identity pivots, settings, activation/deletion, core update, automation
> policy, a dedicated session store, and a public registry are deferred. The
> spec becomes normative again only after Phase 32 rewrites it from executable
> evidence.

---

## 0. Terminology — three things that were all called "v1"

This document proposes a change to **WordPress core**. It is not a WP Sudo release, and the
overlap in names has already caused a real misreading, so the terms are fixed here.

| Term | Means | Does **not** mean |
|---|---|---|
| **Cut 1** | The two-effect research slice defined by the GSD program: plugin/theme upload and file-editor save. **No implementation exists.** | The broader catalog retained in this file |
| **WP Sudo 4.8.0 / 5.0.0** | Releases of the *plugin* in this repository, which does ship and is the prior art the spec cites | The core proposal |
| **Cut 1 gate** | Effect-level vetoes, the preflight protocol, challenge-provider contract, and action-bound proof redemption | A general Actions/Consequential-Actions registry |
| **future registry** | A possible later catalog, considered only when concrete consumers justify a public API (§4.1) | A prerequisite or parallel deliverable for Cut 1 |

Fix iterations of a single bug are **not** versioned either — name them by what they do
(the plugin's #322 work shipped as *fail-closed replay* and then *informed confirmation*,
not "v1" and "v2"), because "#322 v2" and "Cut 1" and "5.0.0" are three unrelated axes and
bare version numbers cannot tell you which one is meant.

## 1. Goal and non-goals

**Goal.** Produce executable evidence that WordPress can refuse plugin/theme
upload and file-editor save at early effect boundaries, then allow exactly one
server-canonical operation after a trusted step-up flow. Broader account,
privilege, settings, and automation coverage remains route inventory, not Cut 1.

The server boundary is the early veto. Preflight is progressive UX, and the
single-use proof connects fresh authentication to the exact effect. The first
claim is not “all consequential operations are protected”; it is that these two
effects can be proven without request replay or a reusable elevation window.

**Non-goals** (kept deliberately out so this can land):

- Not a replacement for `current_user_can()`. This sits *above* capabilities; it never grants authority, only demands a fresh proof before an already-authorized action runs.
- Not a plugin sandbox or runtime isolation. It constrains *declared operations that pass through core chokepoints*; it cannot stop arbitrary code already running in-process. (See proposal §5, §6.)
- Not a new login system or 2FA framework. It consumes existing authenticated identity and existing session infrastructure.
- Not a WAF. It gates named operations; it does not inspect traffic.
- Not an audit log, monitor, or SIEM. It **enforces** proof of intent at the chokepoint; it does not observe, correlate, or alert on events. Detection and logging are a separate concern — useful in a plugin, out of scope for core.
- **Interactive surfaces first — this is the Cut 1 scope boundary, decided in [#320](https://github.com/dknauss/Sudo/issues/320).** The Cut 1 enforcement cut targets **browser + cookie-authenticated REST only**. Application Passwords, WP-CLI, cron, and XML-RPC are **out of Cut 1 scope entirely** — not challenged, not blocked, not allowed-by-policy. They pass through ungated, exactly as they do in current core, and their policy is deferred to the separately-scoped provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4): [#306](https://github.com/dknauss/Sudo/issues/306) API-credential policy, [#307](https://github.com/dknauss/Sudo/issues/307) auto-updater provenance). **This is the single normative statement of surface scope; §3.6, §5.2, §9, and §10 defer to it.**

  **"Programmatic" is not an actor class, and must not be treated as one.** A plugin calling `activate_plugin()` or `install_package()` from inside an admin-screen load or a cookie-authenticated REST request is *an interactive actor* — the request has a cookie session behind it. Classifying by *call origin* ("this came from PHP, not a form") would hand any hijacked session a one-line bypass: call the sink from a plugin hook instead of posting the form. **Classification is a property of the request, not of the call site.** The gate resolves actor class once per request from the authenticated identity and how it authenticated; a nested programmatic call inherits that class. The exemption above therefore covers only genuinely **actorless** contexts (no authenticated user for the request — cron, CLI) and **non-cookie credential** contexts (App Password, XML-RPC), never "a function called from PHP." Specifying the trusted classification signal — and proving no cookie-authenticated request can be resolved as actorless — is [#357](https://github.com/dknauss/Sudo/issues/357); §10 AC7 is the acceptance criterion for it.

  **Residual risk, stated plainly:** the two-effect Cut 1 leaves
  Application-Password, automation, identity-pivot, and other catalog paths
  unchanged. It tests a mechanism; it does not close the complete actor-driven
  route set or imply that deferred channels are safe.
- **Not code-provenance for the automated-update channel.** The gate's "an admin session is insufficient to introduce code" guarantee is scoped to *actor-driven* paths. The cron/auto-update path installs code with **no actor to challenge**, so its integrity is a package-signing / provenance problem this gate does not solve. WordPress has **no working package signing** today — the signed-updates trusted keys lapsed in 2021, `Core_Upgrader` requests no verification (`check_signatures=false`), and verification soft-fails by default (verified against `wordpress-develop` trunk; see `docs/llm-lies-log.md` #39 for the dated citations). Deferred to a provenance primitive (issue #307); the gate is complementary to, not a substitute for, signing.

---

## 2. Threat model

| Actor | Has valid session? | Has capability? | Defended by this spec? |
|---|---|---|---|
| Stolen/replayed admin cookie | yes | yes | **Candidate claim, pending Phase 27:** a copy taken before the challenge must not inherit the action-bound authority produced by the trusted flow. The selected handoff determines what later browser-state theft can capture and therefore the exact limit of this claim |
| XSS running in an authed admin origin | yes | yes | **Undecided pending Phase 27.** Test separately: script confined to the original document, script reaching ordinary admin pages, script reaching the chosen confirmation route, and script observing the proof handoff. Do not collapse these into arbitrary server-side PHP or claim general XSS closure before the browser spike |
| Walk-away / shared workstation | yes | yes | **Candidate protection:** short-lived action authority expires; a later consequential action re-challenges |
| Malicious Editor escalating to Admin | yes | no promote cap — reaches the path via a broken-access-control bug | **Partial** — a *hijacked* Editor session is blocked (the attacker can't pass the Editor's own reauth); a legitimately-authenticated malicious insider who *can* pass their own reauth is **not** stopped — the gate only forces a fresh, loggable step. Fixing the BAC bug is the authz layer's job (rows below); the gate is defense-in-depth for the stolen-session case, not a substitute |
| Attacker who knows the password | yes | yes | **Partially** — reauth still forces a deliberate, loggable step and blocks silent replay |
| Stolen Application Password / API credential | yes (API credential, not a human session) | yes | **No — out of Cut 1 scope** (§1, [#320](https://github.com/dknauss/Sudo/issues/320)). No interactive reauth is possible over a credential channel; hard-blocking by default is a back-compat regression ([#306](https://github.com/dknauss/Sudo/issues/306)). Deferred to the provenance/automation project. **Cut 1 does not defend this row.** |
| Attacker with mailbox access (password-reset channel) | no session needed | n/a | **No — and this is load-bearing, so it is stated rather than implied.** `retrieve_password()` → the emailed key → `reset_password()` is an ungated credential-manufacture route by design: the mail channel is the proof. The §3 invariant is therefore not "no ungated route to a credential change". Stated precisely, and scoped to what Cut 1 actually covers: **within the interactive cookie surface, no ungated route to a credential change exists that does not already require possession of the user's mailbox.** It is not a statement about every route — Application Passwords, WP-CLI and cron are out of Cut 1 scope entirely (§1, §9) and are ungated for different reasons. Gating the reset path would not close this (the attacker holds the key) and would break every legitimate reset (§6.2) |
| Compromised plugin executing in-process | n/a | n/a | **No** — out of scope (needs runtime isolation) |
| Missing `current_user_can()` in a handler | n/a | n/a | **No** — that's an authz bug, orthogonal |

The value is concentrated on the interactive-session rows, which are precisely the "broken access control / privilege escalation / session hijack" categories that are the most-exploited class in current WordPress security data (§1).

---

## 3. Core design decisions

These are candidate load-bearing choices derived from WP Sudo's research and
failure history; the GSD phase gates determine which survive implementation:

1. **Gate the effect, not the form field.** Gating one password input is security theater: a hijacked session skips the form and calls the mutation directly (create a new admin, change email + reset, use the installer). Enforcement must sit at the **data-layer chokepoint every surface funnels through**, so browser, REST, and programmatic callers are covered by one guard. (§5.)
2. **Role-agnostic.** Any logged-in user attempting a gated action is challenged; the gate never reasons about roles. Capability checks remain core's job and run unchanged.
3. **Per-action step-up, not forced re-login or a reusable elevation window.**
   Terminating the login session is heavier than the problem needs, while a
   reusable sudo window lets one approval authorize unrelated effects. Cut 1
   issues a short-lived, revocable, single-use proof for one canonical action
   digest.
4. **No public registry in Cut 1.** The gate needs two private descriptors to
   compute stable action/target digests and render confirmations, but it does
   not need a general Actions API. Enforcement comes from vetoes at the effect
   chokepoints. A public registry is future work and must earn its scope through
   demonstrated consumers rather than landing alongside the security fix.
5. **Fail closed for core actions.** If a built-in consequential action cannot be evaluated (malformed matcher, storage error), the mutation is refused, not allowed.
6. **Transport-agnostic decision, actor-class dispatch.** The chokepoint returns a decision (as a `WP_Error` in practice, §5.2); the *challenge* is only one rendering of it. The decision branches on **actor class**, not transport — so the architecture accommodates a per-class policy without re-plumbing. **In Cut 1 exactly one class is in scope:** an interactive cookie session (browser or cookie-authenticated REST) is challenged. Every other actor class is out of scope and passes through ungated (§1, [#320](https://github.com/dknauss/Sudo/issues/320)).

   Earlier drafts argued here that excluding non-interactive routes from enforcement is a category error, because it leaves REST install/activate open. **That argument is still sound and is not retracted** — it is why #306/#307 exist and why the provenance/automation project is scoped rather than dropped. #320 decided that shipping the actor-driven closure first, without a default hard-block that breaks existing integrations, is the landable sequence. The category error is deferred, not resolved.

These decisions produce three deliberately separate layers:

1. The effect chokepoint veto provides security.
2. The wp-admin preflight client provides continuity and preserves unsent state.
3. The one-use digest-bound proof connects fresh authentication to the exact
   effect the chokepoint is about to permit.

The client may be absent; the veto may not. A missing client integration
degrades to explicit resubmission, never to bypass or automatic replay.

---

## 4. New core APIs

### 4.1 Private descriptors for the two-effect slice; public registry deferred

Cut 1 keeps only the private descriptors required by the two-effect experiment:
a stable internal identifier, a server-owned label, the digest schema, and the
effect chokepoint.
It does **not** add `wp_register_action()`, `wp_get_action()`, or a public
`WP_Consequential_Actions` class. Conceptually, each private definition contains
only what the gate consumes:

```php
$core_action_gate_actions['core/change-user-password'] = [
    'label'         => __( 'Change another user’s password' ),
    'digest_fields' => [ 'target_user_id' ],
    'chokepoint'    => 'wp_update_user',
];
```

This is illustrative internal shape, not a registration API.

**Active Cut 1 descriptors:**

| Action ID | Backing core chokepoint |
|---|---|
| `core/edit-plugin-file` / `core/edit-theme-file` | `wp_edit_theme_plugin_file()` |
| `core/upload-plugin` / `core/upload-theme` | The final pre-download/pre-unpack veto or vetoes established empirically in Phase 28; Slice A proves `install_package()` alone is too late |

**Broader route inventory—deferred, not Cut 1:**

The following table is retained as research evidence for later milestones. Its
entries must not be treated as current implementation requirements merely
because they have identifiers.

| Action ID | Backing core chokepoint |
|---|---|
| `core/change-own-password` | `wp_update_user()` when `$user_id === get_current_user_id()` and `user_pass` changes |
| `core/change-user-password` | `wp_update_user()` on another user, `user_pass` changes |
| `core/change-own-email` | profile self-email change — **gate the initiating request**, not only `wp_update_user()`: with confirmation enabled, `send_confirmation_on_profile_email()` writes `_new_email` and restores `$_POST['email']` to the old value *before* `wp_update_user()` runs, so the chokepoint sees no change; gate at a **veto-capable** seam. `personal_options_update` is a `do_action` (`wp-admin/user-edit.php:149`, verified) whose return is discarded, so it can only observe. The admin path therefore needs a **core patch** to `edit_user()` short-circuiting *before* `send_confirmation_on_profile_email()` (`wp-includes/user.php:3864`) writes `_new_email`; the REST self-update can veto today via its `permission_callback`. On multisite the confirmation path writes the signup row before `wp_update_user()`, so gate the confirmation endpoint too |
| `core/change-user-email` | `wp_update_user()` other user, `user_email` changes |
| `core/create-user` | `wp_insert_user()` (returns `WP_Error`); on multisite `wpmu_create_user()` returns `int\|false`, so the gate needs a distinct adapter there (REST multisite create checks `if ( ! $user_id )`) |
| `core/delete-user` | `wp_delete_user()` / `wpmu_delete_user()` |
| `core/promote-user` | role change granting administrator / network-admin authority (see §5.3) |
| `core/edit-plugin-file` / `core/edit-theme-file` | `wp_edit_theme_plugin_file()` — the single sink `plugin-editor.php`, `theme-editor.php`, and `wp_ajax_edit_theme_plugin_file()` all funnel through; the most direct code-write path |
| `core/install-plugin` / `core/install-theme` / `core/update-plugin` / `core/update-theme` | `WP_Upgrader::install_package()` — the shared package-write funnel beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, the AJAX updaters, and the REST plugins controller. Gate here, **not** `Plugin_Upgrader::install()` (which misses bulk/update/AJAX/auto-updater), and **not** at the capability layer (`upload_plugins`→`install_plugins` in `map_meta_cap`, so it cannot distinguish an attacker ZIP from a repository install). Also covers language-pack writes into `WP_LANG_DIR`, which core executes (`.l10n.php` is `include`d) |
| `core/update-core` | `Core_Upgrader::upgrade()` → `update_core()`. **A separate seam is required: core update does NOT pass through `install_package()`.** Verified against trunk — `class-core-upgrader.php` calls `update_core( $working_dir, $wp_dir )` at `:178` and the string `install_package(` does not occur in the file at all. So the row above does not cover it, and `update-core.php?action=do-core-upgrade` — and the one-click *Re-install version X*, which posts a **distinct** `do-core-reinstall` action (WP Sudo's own registry gates both; a rule matching only `do-core-upgrade` misses the reinstall path entirely) — writes arbitrary files under `ABSPATH` from an ordinary cookie session. This is the largest single code-write in core; omitting it left the catalog gating plugin and theme code while leaving core itself open |
| `core/activate-plugin` | `activate_plugin()` — reachable directly via REST `update_item` on an already-installed plugin |
| `core/delete-plugin` | `delete_plugins()` |
| `core/switch-theme` | `switch_theme()` |
| `core/delete-theme` | `delete_theme()` |
| `core/update-connector-credentials` | `/wp/v2/settings` write of a `connectors_*_api_key` value — the WP 7.0 Connectors **credential-integrity** threat; an options / REST-settings write chokepoint (proposal §3, §8) |
| `core/change-critical-setting` | `/wp/v2/settings` write of `siteurl` / `home` (keyed `url` / `home` via `show_in_rest`) — repointing the origin loads attacker scripts **same-origin** in `wp-admin`, which can fake/keylog the challenge itself; a critical-option write chokepoint (WP Sudo's `options.critical`) |
| `core/create-application-password` | **`WP_Application_Passwords::create_new_application_password()`** — the shared issuance sink both the REST controller and the `authorize-application.php` no-JS approve flow call. Verified against trunk: declared at `:89` and it already returns `WP_Error` (`:95`, `:116`), so one insertion covers both surfaces *and* any third-party or future caller. This is a durable API credential a cookie session can mint and then use to change a password via REST and log in fresh, so it is the pivot the closure exists to seal |
| `core/set-registration-policy` | write of `users_can_register` / `default_role` — enforce a **server-side invariant**: a **code-capable** role (by effective capability, §5.3 — incl. `activate_plugins` / theme-switch, not just `edit/install_*`) can never be the public-registration default; classify by capability, not role name, and cover the omitted-role case in REST user create |

The account and remaining code rows explain why a later complete closure becomes
broad. They do not expand the active slice. The file editor and upload cases are
the only rows promoted into the active descriptor table above.

#### 4.1.1 Registry vs. Abilities API — no Cut 1 decision required

The earlier draft treated “new registry or Abilities metadata?” as a blocking
fork. It is not: Cut 1 ships neither public form. The protected operations are
plain core mutations, and the enforcement must be inserted at their effect
chokepoints in either case. The following findings remain useful constraints on
any later catalog:

- **Pure "abilities-only" does not survive contact with the catalog.** The operations the gate must protect (`wp_update_user()`, `wp_insert_user()`, `wp_delete_user()`, `activate_plugin()`, `delete_plugins()`, the upgrader/theme/role mutations) are **not abilities and are not registered in the Abilities API** (as of WP 7.0 it registers three *read-only* abilities: `core/get-site-info`, `core/get-user-info`, `core/get-environment-info`). "Annotate the ability" has nothing to annotate for any catalog member. Making it work would first require abilitizing core's entire write path — a far larger, separately contested project with no consensus or timeline, exactly the entanglement proposal §5–§6 warn against.
- **The one strong argument for abilities, enforcement economy (one execution hook vs. the ~15 chokepoint insertions in §6), fails on a verified fact.** `WP_Ability::execute()` fires `wp_before_execute_ability` via a plain `do_action()` and then calls `$this->do_execute( $input )` on the very next line, discarding whatever the hook returned (verified — see `GB-ABILITY-NOVETO` in [`upstream-sources.md`](upstream-sources.md), which pins the `do_action` line so upstream drift invalidates this claim automatically). So the hook is **observational, not a gate**: a callback cannot return a `WP_Error` or a challenge to stop the ability — only `wp_die()`/throw would, a blunt request-kill rather than the structured challenge-and-replay a reauth gate needs. Abilities buys **no** clean enforcement seam. And the chokepoint model is strictly more complete: it covers a *programmatic* `wp_update_user()` caller, which ability-execution hooks never see.

The earlier ID convention and nested `consequence` shape are archived design
options, not Cut 1 contracts.

The ID shape, metadata schema, union behavior, and collision contract are all
deferred. They should be derived from real consumers rather than stabilized by
the gate patch.

The full memo (with the MVP-status detail) is archived at [`archive/core-actions-registry-vs-abilities-decision.md`](archive/core-actions-registry-vs-abilities-decision.md).

**Historical demo shape.** The `consequential-actions` registry metadata is
preserved in its tagged MVP. Cut 1 has no corresponding public shape to reconcile.
The successor demonstrator reuses the Playground narrative and harness, not the
registry schema.

### 4.2 Per-action step-up: a per-session, HMAC-signed, separate proof

This is the load-bearing security mechanism, and two independent reviews rejected the two obvious designs. The governing invariant: **step-up must give the browser that answered the challenge a secret the stolen/ridden session copy does not already hold, and the server's assurance record must not be forgeable by anything short of that secret.** Two rejected approaches and why:

- **Stamping `reauth_at` on the shared session-token record — rejected.** The stolen auth cookie *is* a copy of the same session token, so stamping that record elevates the thief's requests exactly as much as the legitimate browser's. An assurance keyed only to the session token separates nothing.
- **Rotating the session token on step-up — rejected.** `wp_create_nonce()` hashes `wp_get_session_token()`, so rotating the token invalidates **every nonce already rendered into every open admin tab**, including the `_wpnonce` inside the stashed POST the challenge replays (§5.1). Core deliberately re-issues the auth cookie against the *same* token on password change (`wp_update_user()`) precisely to preserve nonces; rotation reverses that. `WP_User_Meta_Session_Tokens::update_session()` is also a non-atomic read-modify-write of the whole `session_tokens` array, so concurrent admin XHRs can lose the rotation and silently leave the thief's copy valid. Rotation, if wanted at all, belongs as an explicit "sign out other sessions" affordance *after* step-up — not an implicit side effect of every elevation.

**Prior candidate design, retained for review history.** Earlier reviews arrived
at a separate, per-session, self-authenticating proof alongside the login
session. Phase 27 has not selected that transport or storage model. The old
helper sketch is preserved only so its assumptions remain inspectable:

New helpers in a new `wp-includes/user.php` block:

```php
wp_issue_action_proof( string $digest, int $user_id = 0 ): string|WP_Error; // after a challenge; mints a ONE-TIME proof bound to $digest
wp_redeem_action_proof( string $token, string $digest, int $user_id = 0 ): bool; // at the chokepoint; consumes it, or false
wp_end_reauth_proofs( int $user_id = 0 ): void;                            // on logout / credential change / explicit drop
```

> **Phase-27 coupled blocker:** the transport and XSS claim are one decision,
> not parallel wording tasks. A proof cannot be both
> exclusively HttpOnly and directly attached by JavaScript as
> `X-WP-Action-Proof` without a second client-visible bearer. Before
> implementation, specify whether the browser submits an ambient cookie, a
> response-delivered token, or a trusted-surface redemption handle, and test
> what active same-origin script can read, invoke, redirect, replay, or redeem.
> The permitted XSS claim must then be derived from that result. Digest binding
> does not make this handoff question disappear.

**Superseded candidate mechanics.** The numbered material below records the
reviewed cookie/HMAC/session-store design so its lessons are not lost. It is not
the Cut 1 implementation contract. Cookie transport, proof visibility,
confirmation/issuance order, and storage remain Phase-27/29 decisions. Only
transport-independent properties—action binding, short lifetime, atomic
single-use redemption, revocation, and fail-safe behavior—carry forward.

1. **Candidate separate proof secret.** The prior design minted a fresh random secret as a dedicated **httponly** cookie (distinct from the auth cookie), storing only its hash server-side. A session copy that predates the challenge, or a theft that captured the auth cookie but not this proof, could not present it. This candidate does *not* settle what session-riding XSS can exercise, and it must not be implemented unless the coupled Phase-27 handoff-and-claim decision selects it.
2. **Keyed per session-token verifier, not per user.** Store the record under the current login session's **verifier** — `hash( 'sha256', wp_get_session_token() )`. Note the distinction: `wp_get_session_token()` returns the *raw* token from the auth cookie, while `WP_Session_Tokens` keys its stored sessions by that token's hash (the verifier). Keying per verifier means a second browser reauthenticating does not overwrite the first's proof. Core supports many concurrent sessions per user; the record must too. (WP Sudo's single per-user proof key is a known limitation — plugin issue #279.)
3. **Self-authenticating (HMAC), binding the proof hash.** `session_tokens` is stored in `user_meta`, a **persistent, poisonable** object-cache group, so an unsigned `reauth_at` is forgeable by a cache-poisoning primitive — exactly the wp2shell class (SQLi used to poison the object cache). Bind the record with `hash_hmac( 'sha256', "$user_id|$verifier|$reauth_at|$scope|$proof_hash", wp_salt( 'auth' ) )`, where `$proof_hash` is the stored `hash('sha256', $proof)` of the browser proof secret, and reject on mismatch. The MAC **must** cover `$proof_hash`: otherwise an attacker who can poison the record keeps its valid signature and swaps in the hash of a cookie they hold, defeating the separate-proof requirement. **A cache-bypassing `$wpdb` read on the enforcement path is REQUIRED, not a complement.** The motivating primitive (wp2shell, §10) is a SQLi *read* used to poison the **object cache** — not a DB-row write — so reading the row directly defeats it **regardless of where the salts live**. The HMAC additionally defends the direct-DB-*write* attacker, who is already out of scope (§2).
   Salt placement is therefore **defense-in-depth, not a precondition**: verified against trunk, when the salt constants are undefined `wp_salt()` reads `get_site_option( "{$scheme}_{$type}" )` and, when empty, generates one with `wp_generate_password( 64, true, true )` and persists it with `update_site_option()` — so on those installs the same SQLi read can obtain the salt and forge the MAC. Surface that state in Site Health; do **not** make the gate's security claim conditional on it, and do not chase a salt-independent binding against a DB-read adversary (any stored key is readable — the scope of the claim is what needs stating, not the key).
4. **Only the challenge handler writes it.** Core must strip reserved `reauth_*` keys from the `attach_session_information` filter result (that filter sets the *base* session array on every `WP_Session_Tokens::create()`, so any registered callback could otherwise pre-elevate every new session), and the HMAC must cover the verifier so an injected stamp cannot validate.
4b. **The session store is pluggable, so the proof record must not assume it (#328).** `WP_Session_Tokens::get_instance()` resolves its class through the `session_token_manager` filter (`class-wp-session-tokens.php:58`, `:222`, verified), and Redis/Memcached/DB-backed managers are free to persist only the fields they know about. If a manager silently drops the proof record, `wp_redeem_action_proof()` never succeeds and — under default-on fail-closed — **every gated action becomes an unsatisfiable loop**: the user is challenged, passes, and is challenged again, with no in-product escape. That is a worse outcome than the gap the gate closes.
   **These two requirements collide, and the collision must be resolved rather than left to the implementer.** Point 3 mandates a cache-bypassing `$wpdb` read of the record; a Redis-, Memcached- or custom-DB-backed `session_token_manager` may round-trip the proof fields perfectly while storing them somewhere `$wpdb` cannot see, so the probe passes and the enforcement read fails. The rule is therefore: **the cache-bypassing read is required only where the store is the WordPress database.** For any other manager, the equivalent requirement is a read path the manager itself guarantees is not served from a poisonable cache — and if it cannot guarantee that, the degraded mode below applies rather than a read that always fails.

   Required: **detect the capability rather than assume it.** Core has no plugin-style activation event, so name the checkpoints: run the probe on **upgrade/install** (`wp_upgrade()`), from the **Site Health** async test, and — decisively — **round-trip the proof during challenge issuance, before redirecting into a flow that depends on it**, so an incompatible manager surfaces at the point of use rather than after the user is already in the loop. Write a probe record through the active manager for the **current session's** subject and read it back; if the round-trip fails, enter a **documented degraded mode that is not fail-closed** — log, surface the condition prominently, and allow — or use a dedicated store instead of `WP_Session_Tokens` (§11-Q2). A security control that cannot be satisfied is not a control; it is an outage, and it will be removed by whoever is on call.

4c. **Four mechanism details that are wrong by default (#311).**
   - **Hook `add_user_metadata` as well as `update_user_metadata`.** A brand-new user's first `{prefix}capabilities` write is an *add*, not an update, so an escalation guard on the update filter alone misses user creation. Both are short-circuit filters (`meta.php:90` and `:250`, verified).
   - **Gate second-factor enrollment and removal.** If reauth can be satisfied by a factor (§7), then adding a factor the attacker controls, or stripping the victim's, is itself a credential pivot. A gate that protects the door and not the lock is bypassed by changing the lock.
   - **Keep the MAC encoding injective.** It currently is, even though `scope` is caller-supplied and may contain `|`: every other field is fixed-length or numeric (user id, timestamp, 64-hex verifier, 64-hex proof hash), so no two distinct field sets serialise identically. The requirement is therefore the **property**, not length-prefixing per se — but it is load-bearing and undocumented, so state it, and re-derive it before adding any variable-length field, at which point delimiter concatenation stops being safe.
   - **Emit the proof cookie once, on a path that covers every surface that must redeem it.** Two cookies of the same name on overlapping paths (`COOKIEPATH` and `ADMIN_COOKIE_PATH`) have browser- and order-dependent precedence, so the server may verify one while the browser sends the other. This is in tension with §12's requirement to mirror the **logged-in** cookie, which core sets on both `COOKIEPATH` **and** `SITECOOKIEPATH` when they differ (subdirectory and mapped-domain installs). Resolve it by scope, not by duplication: pick the single widest path that still reaches every redeeming surface, and treat "same name on two overlapping paths" as the thing forbidden — not "the proof must be narrower than the session". If no single path covers them, the cookie name must differ per path so precedence is never ambiguous.

5. **Redemption consults the session store and consumes atomically.**
   `wp_redeem_action_proof()` checks the presented proof hash, HMAC, and bound
   digest, then performs compare-and-delete or an equivalently linearizable
   consume operation **before** the effect. A read followed by a separate delete
   is insufficient: two concurrent requests can both observe success. The
   acceptance test must coordinate two redemptions and prove at most one
   succeeds. Because redemption consults the store, `destroy_all()` revokes
   outstanding proofs within the same request.
6. **A fresh `login` timestamp is not proof.** `wp_signon( '', '' )` mints a new session token with a current `login` stamp from a *held cookie* with no credential entered (and SSO / magic-link plugins call `wp_set_auth_cookie()` with no password). So a "forced-login" variant that infers freshness from the new session's `login` time is the rejected shared-token approach in another form. Freshness must come from the explicit, challenge-written record above.
7. **Teardown on credential change clears only `reauth_*` for the target user — never `destroy_all()`.** A password change (`after_password_reset`, and the password path of `wp_update_user()` / `wp_set_password()`, which leave the token record intact) must end the window. Clear the `reauth_*` keys for the affected user's sessions; do **not** call `destroy_all()` (that logs the user out of the very request that changed the password), and do **not** hook `profile_update` blindly — it fires when an admin edits *another* user and on non-password writes (WP Sudo guards this by comparing the password hash; core must too).

- **Multisite: a proof's reach follows the auth cookie's domain, not the network.** The record lives with the *current login session*, so it travels exactly as far as the auth cookie that presents that token: shared across a subdirectory network (one cookie domain), isolated per domain on subdomain/mapped-domain networks. Deterministic per-site scoping regardless of cookie domain is deferred to the `consequence.scope` tag and the gate track policy (§8).
- **No reusable window for in-scope actions.** A proof is minted for **one action digest**, is single-use, and is discarded on redemption. There is no `wp_has_recent_auth()`-style freshness query on the consequential path, so no benign action can authorise a consequential one, and a forged or stolen proof authorises **one** operation for seconds rather than every catalog entry for fifteen minutes.
- **A short grace (≈2 min) applies to the SAME digest only**, so a multi-step form is not re-challenged mid-flow. It is not cross-action reuse.
- **Why the window was dropped (this reverses an earlier recommendation).**
  Neither active effect benefits from cross-action freshness. A reusable window
  adds cross-authorisation, forgery blast radius, and teardown races without a
  Cut-1 job. Later catalog expansion does not inherit a window automatically.
- **Per-IP lockout collapses behind a proxy, CDN or NAT.** Every request then shares one bucket, so five failures from any one low-privilege user lock **every** administrator out of the actions needed to remediate — a denial of service that an attacker can trigger deliberately and cheaply. Either drop the per-IP dimension or gate it on a client-IP signal the site actually trusts (a configured trusted-proxy header), and never treat `REMOTE_ADDR` as identifying behind a reverse proxy.
- **The escape hatch must be reachable by the person locked out.** `WP_DISABLE_ACTION_GATE` in `wp-config.php` assumes filesystem access; a large share of managed-hosting operators do not have it. Fail-closed plus a file-only escape means the documented recovery path does not exist for them. Provide a recovery route that survives the lockout (§8.1) and treat "the operator can edit `wp-config.php`" as an assumption to state, not one to rely on.
- **Lockout is a remediation hazard, not just a rate limit.** Port the plugin's failure model (progressive delay → 5 failures ⇒ 300s lockout, per-user and per-IP), but with two corrections from review: (a) an in-origin XSS from the victim's own IP can burn failed attempts to lock the admin out of the *very actions needed to remediate* (change password, deactivate a plugin), so a lockout must be **clearable out-of-band** (a `WP_DISABLE_ACTION_GATE`-style escape / recovery path — plugin issue #280); and (b) prefer escalating delay over a terminal block for the *action-gate* decision (distinct from the auth-attempt decision).

Open question **closed**: scope-bound vs. flat freshness no longer applies — Cut 1 has no reusable window to scope (§11-Q3).

> **The PoC slice and this section currently disagree — deliberately recorded, not resolved.**
> `poc/install-package-gate/` (#380) implements a **session-scoped proof with a TTL**, and says
> so plainly: its header notes the proof "is not action- or target-bound (#308)". This section
> is normative for **per-action step-up** (§4.2, adopted in #367) — single-use proofs bound to
> one action digest, no reusable window.
>
> The slice landed after that change, so it demonstrates the *earlier* mechanism. Nothing is
> wrong with it as a slice — it proved the browser-binding property it set out to prove, and it
> produced the extraction finding in §5.4 that no sketch could have. But the disagreement must
> not be left implicit: **the spec is normative for mechanism, the slice is evidence about
> behaviour**, and a reader comparing them should know which is which. Reconciling the slice to
> per-action proofs, or narrowing its README to say which mechanism it is testing, is tracked
> with #360.

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

`wp_check_action_gate()` returns *passed* when gating is globally off or the
request redeems a valid proof. A missing built-in definition fails closed: the
guarded mutation must not silently proceed. The global
`WP_DISABLE_ACTION_GATE` / `wp_action_gate_enabled` kill-switch is checked
before that branch so an operator can recover from a broken catalog load.

**`as_wp_error()` carries two distinct contracts, and this matters for the adapters.** A `needs_challenge()` decision yields code `sudo_reauth_required` **with** a `challenge_url`, which the admin adapter (§5.2) redirects to the interstitial. A `blocked()` decision (rate-limited or fail-closed) yields a **terminal** code (`sudo_rate_limited` / `sudo_blocked`) **without** a `challenge_url`, so adapters render a hard refusal — never a redirect to `wp-login.php?action=reauth`, which for a hard-blocked actor would be an unsatisfiable challenge or a redirect loop. Surface adapters branch on the code, not on the mere presence of a `WP_Error`.

> **Naming (open, see §11-Q1 / proposal §8.2).** The illustrative error code
> `sudo_reauth_required` inherits WP Sudo's *"sudo"* brand. Cut 1 proposes no
> public registration API, and none of the helper names in this superseded
> inventory are stable. Resolve neutral public names only after the Phase-27
> mechanism decision.

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
- One insertion *reaches* admin UI, REST, CLI, and in-process callers — every caller funnels through the same seam, and each inherits **the actor class of the request it runs in** (§1, #357), never an exemption for being a PHP call, which is what makes the defense effect-deep rather than form-deep (the #20140 lesson made mechanical). What the seam **reaches** is not what Cut 1 **enforces**: Cut 1 evaluates only interactive cookie sessions (§1), so CLI and programmatic writes pass through ungated. The seam placement is what makes extending enforcement to them later a policy change rather than a re-plumbing.
- Self vs. other, password vs. email vs. role are all just fields in `$changed`, mapped to distinct action IDs by `wp_map_user_changes_to_actions()`.

The preferred interactive path starts **before** this error. A standard
wp-admin client intercepts an opted-in action before submission and sends a
preflight containing the action identifier, target, and digest-relevant
parameters. The server canonicalizes that intent and either allows immediate
submission or returns `sudo_reauth_required` with the canonical digest and
challenge information. After trusted reauthentication and action-specific
confirmation, the client sends the original mutation **once** with authority
bound to that digest. Phase 27 decides whether confirmation causes proof
issuance, precedes it, or is combined with redemption inside the trusted flow;
this inventory does not choose a JavaScript-readable token or ambient cookie.

The effect-level guard remains authoritative and repeats the digest calculation
when redeeming the proof. Client preflight is an experience layer, never an
enforcement substitute.

For a legacy screen that has not adopted preflight, the guard refuses the first
submission, grants authentication through the challenge, returns the user to a
safe screen, and asks them to review and submit again. Core does not retain the
submitted body for later execution. In particular, it never stores passwords,
secrets, or executable uploads. This fallback is less smooth but has a simple
security meaning: **reauthenticate, then resubmit**.

**Bulk operations are ONE action, not N.** A bulk plugin update or multi-select
operation computes a digest over the whole displayed batch and takes one
challenge and confirmation. The client keeps the unsent operation; the server
does not stash it.

### 5.2 Surface adapters

| Surface | Sees | Renders challenge as |
|---|---|---|
| Preflight-enabled admin UI | Preflight decision before mutation | Pause locally → trusted reauthentication and action/target confirmation → submit once with action-bound authority; transport pending Phase 27 |
| Legacy admin UI | `WP_Error sudo_reauth_required` from the attempted mutation | Challenge → return to a safe screen with an explicit “review and submit again” notice. No request body is retained or replayed |
| Cookie-authed REST | same decision/error from controller | HTTP **403** with `code: sudo_reauth_required`, `data.challenge_url`, `data.expires_in`, `data.digest`. An integrated client obtains a proof and sends the mutation once; a generic client must explicitly resubmit |
| App-Password REST / XML-RPC (API credential) | *not evaluated in Cut 1* | **Out of Cut 1 scope** — passes through ungated ([#306](https://github.com/dknauss/Sudo/issues/306), deferred by [#320](https://github.com/dknauss/Sudo/issues/320)) |
| wp-cron / WP-CLI / programmatic | *not evaluated in Cut 1* | **Out of Cut 1 scope** — passes through ungated ([#307](https://github.com/dknauss/Sudo/issues/307) and the deferred CLI/cron policy) |

The decision object never encodes transport. Business functions return errors; adapters localize UX. (Proposal §4.)

### 5.3 Privilege-escalation guard (the promote-to-admin case)

Role changes are the subtlest path and need a dedicated guard, mirroring the plugin's `arm_escalation_guard()` / `newly_grants_administrator()`:

- Hook the capability-meta write, not just `set_role`. Promotion can arrive via `WP_User::set_role()`, `add_role()`, `update_user_meta()` writes to `wp_capabilities`, or `add_user_to_blog()` on multisite. Compare **new effective caps vs. current** and gate only when the delta **newly grants administrator / network-administrator authority**, so demotions and lateral moves are not challenged. This hook covers `update_user_meta`-level writes (the REST `add_role` path, plugin/AJAX caps writes); a **raw `$wpdb` write** to the caps meta fires no hook and is out of the gate's reach — an in-process write, out of scope per §2, and *detecting* it is a non-core concern (§1). (WP Sudo closes that gap in the plugin layer with its lockdown-audit detection; core does not.)
- Enforce at the `wp_update_user` / caps-meta-write chokepoint **only. Do not enforce at `map_meta_cap` (#309).** A REST role change and an admin-UI role change already meet at that chokepoint, so the `map_meta_cap` insertion buys no coverage and costs three things:
  1. **It breaks this design's own boundary.** §1 states the gate sits *above* capabilities and is *not* a replacement for `current_user_can()`. Denying the capability makes `current_user_can()` return false, so role controls **disappear from the UI** instead of producing a challenge — a silent capability regression that reads to everyone downstream as a permissions change, not a pending reauth.
  2. **Recursion.** The escalation test calls `user_can()`, which re-enters `map_meta_cap`, with no re-entrancy guard specified.
  3. **Hot-path cost.** `edit_user` is mapped on ordinary user-facing requests, so the gate would run far more often than the effects it protects.
  The chokepoint produces the right user experience anyway: the affordance stays visible, the action returns `sudo_reauth_required`, and the surface adapter turns that into a challenge.
- Multisite: gate `grant_super_admin()` unconditionally (highest-consequence promotion) — noting it needs a core patch to be gateable at all, and is a no-op when `$GLOBALS['super_admins']` is defined (§6 row 7, §6.1).

### 5.4 Code effects — the shared sinks

The terminal code routes are gated at **two shared sinks plus one effect**, not per-page or per-capability, so route multiplicity (admin UI, bulk, AJAX updaters, REST, programmatic) collapses to a handful of insertions:

- **`WP_Upgrader::install_package()`** — the package-write funnel beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, the AJAX updaters, and the REST plugins controller (`core/install-plugin` / `-theme` / `core/update-plugin` / `-theme`). Gate here rather than `Plugin_Upgrader::install()` (which misses bulk/update/AJAX/auto-updater) and rather than the capability layer (`upload_plugins`→`install_plugins` in `map_meta_cap`, so a capability gate cannot separate an attacker ZIP from a repository install and would disrupt CLI/automation). It also covers language-pack writes into `WP_LANG_DIR`, a directory core executes (`.l10n.php` is `include`d).
  **Core already ships a veto here, so this seam needs no core patch (#380).**
  `install_package()` opens with `apply_filters( 'upgrader_pre_install', true, $args['hook_extra'] )`
  and returns immediately when the result `is_wp_error()` (verified, `class-wp-upgrader.php:556-560`).
  A plugin can therefore refuse a package write today, without patching core — which is what
  makes the PoC slice possible at all, and which strengthens the case that the gate is
  demonstrable before core accepts anything.

  **But it fires too late to prevent extraction — found by running it (#380).** `WP_Upgrader::run()`
  calls `unpack_package()`, which extracts the archive into `wp_content_dir() . 'upgrade/'`,
  **before** `install_package()` is reached (verified against trunk). So a gate at
  `install_package()` refuses the final move into `plugins/` while attacker-controlled PHP is
  already sitting in `wp-content/upgrade/` — a directory many hosts serve and execute. A
  session-riding attacker who can request a known path has execution regardless of the refusal
  that follows.
  This corrects §3's claim that `install_package()` is *the* code-write seam: for the
  interactive branch it is **necessary but not sufficient** — and `upgrader_pre_install`
  inherits the same limitation exactly, since it fires inside the function that runs after
  extraction. A seam being conveniently available is not evidence that it is early enough. Either gate earlier — at
  `unpack_package()` or before the download in `run()` — or state the residual plainly. The
  PoC slice (`poc/install-package-gate/`) is what surfaced this; it is exactly the class of
  error a pseudocode sketch cannot produce, and the reason #360 asked for real code.

  **It does not cover core update.** `Core_Upgrader::upgrade()` reaches `update_core()` directly — `install_package(` never appears in `class-core-upgrader.php` (verified) — so "gate the shared package sink" is true of plugin, theme and language packages and false of core. `core/update-core` therefore takes its own insertion at `Core_Upgrader::upgrade()`/`update_core()`; treating the sink as universal is exactly how the most powerful write in core came to be the one the catalog missed (#302).
- **`wp_edit_theme_plugin_file()`** — the single write sink for both the plugin and theme file editors, including the `wp_ajax_edit_theme_plugin_file()` path (`core/edit-plugin-file` / `-theme-file`). Gate the sink *and* challenge early at editor-open so a user does not write a draft only to discover the requirement at save.
- **`activate_plugin()`** (`core/activate-plugin`) — reachable directly via REST `update_item` on an already-installed plugin; success returns **`null`**, `WP_Error` on failure, so adapters key on `is_wp_error()`, not a truthy return.

`delete_plugins()`, `delete_theme()` (`bool|null|WP_Error` — its callers test `is_wp_error()`), and the remaining code effects take the same top-of-function guard returning `WP_Error`; the bulk/AJAX surfaces already thread `WP_Error`, so the interactive adapter stashes and challenges as above. Two chokepoints do not fit the `WP_Error` return path (the §5.1 per-chokepoint rule): `switch_theme()` returns **void** and performs several option writes, so it needs an **in-function pre-check — a core patch**, not a hook-based adapter (§6 row 15, §6.1); and the multipart ZIP upload cannot be stashed/replayed — reauthenticate before file selection where possible, otherwise require explicit reselection (§8).

**Non-interactive callers of these sinks are out of Cut 1 scope entirely (§1) and pass through ungated — "non-interactive" meaning the request authenticated by a non-cookie credential or by nothing at all, never "the call arrived from PHP" (§1, #357). A plugin calling one of these sinks inside a cookie-authenticated request is interactive and is gated** — core's own automatic updater drawing from the site's configured update source must keep running, or the gate is a net security regression by stopping background security updates. (The actor-class policy sketched in §9 is the follow-on project's design, not Cut 1 behaviour.) This is the second half of closing the "admin can just install a backdoor" objection that stalled #20140 for a decade — now covering themes and the file editor, not only plugin install.

---

### 5.5 Direct option writes reach the same effects without the named seam (#327)

Every code/capability seam in §5.4 has an option-shaped back door, because core reads
the option rather than asking the function that normally writes it. Gating
`activate_plugin()` and `switch_theme()` while leaving their options writable gates the
*front door of a room with two doors*.

| Option | Effect reached without the seam |
|---|---|
| `active_plugins` / `active_sitewide_plugins` | **Plugin activation.** Verified: `wp_get_active_and_valid_plugins()` reads `get_option( 'active_plugins', array() )` (`load.php:1015`) and loads what it finds. A direct write activates code on the next request without `activate_plugin()` ever running — so §6 row 14c's seam is bypassed entirely. |
| `template` / `stylesheet` | **Theme switch**, without `switch_theme()` and therefore without row 15's guard. |
| `wp_user_roles` | **Capability grant.** Rewriting the role definitions adds capabilities to existing roles, which defeats both the escalation guard (§5.3, which watches the per-user caps meta) and the registration-default invariant. |
| `admin_email` / `new_admin_email` | Site-owner identity, the recovery-address pivot. |

**Gate the consequential *transition*, not every write to these keys.** Removing an entry from `active_plugins` (deactivation), demoting a role, or dropping a capability are the safe directions and must not be challenged — the escalation guard already makes this distinction for role changes (§5.3) and the same rule applies here, or routine deactivation becomes a reauth prompt and the gate trains people to click through it. Compare new value against old and gate only when the change *adds* code or authority. Then hook on the **right API for each key**. `pre_update_option_{$option}` covers per-site options only. `active_sitewide_plugins` is a **network** option (`get_site_option()`/`update_site_option()`), so it needs `pre_update_site_option_{$option}` (`option.php:2418`); the same applies to any network-scoped key. Prescribing the per-site hook alone leaves the multisite variants of these effects ungated, which is the failure mode this section exists to name. §6.3 records
why that mechanism is adequate *here* and not for `switch_theme()`: it is adequate exactly
when the option write **is** the whole effect, which is the case for every row above — the
attacker's goal is the stored value, and nothing else needs to happen. The known limitation
still applies (a refusal is reported as `false`, indistinguishable from "no change needed"),
so the gate must not infer success from the return.

Alternatively, **explicitly bracket** any of these as out of scope — but bracket them in §5
by name, with the reason. What is not acceptable is the current silence, which reads as
coverage the design does not have.

### 5.5b Actor class needs a provenance signal core does not currently have

§1 and AC7 say actor class is resolved from *the mechanism that authenticated the current
user*. **That mechanism is not recoverable at the sink today.** `determine_current_user`
resolves to a user **ID**, and once `wp_set_current_user()` has run, a chokepoint cannot tell
whether the ID arrived from `wp_validate_logged_in_cookie()` or
`wp_validate_application_password()` — cookie REST and Application-Password REST present an
identical current user. Without a signal, an implementation must either gate the deferred API
callers (breaking §9's pass-through) or let cookie requests through ungated (the #357 bypass).

So the signal is part of the ask, not an implementation detail:

- **Set provenance in the successful authentication callback**, where the mechanism is still
  known — a request-scoped value recording which validator resolved the user.
- **Lifecycle:** set once per request at authentication, immutable thereafter, and **absent
  rather than defaulted** — an unset value must resolve to "unknown", which fails closed for
  gating rather than defaulting to a passing class.
- **Not inferrable from the call stack**, per §1: a nested call inherits the request's value.
- This needs its own **§6 change-list row** against the authentication path; it is the
  precondition for every actor-class decision in §9, and none of them are implementable
  without it.

### 5.6 The registration-policy invariant, stated as an invariant (#311)

`core/set-registration-policy` is currently enforced **at the moment `default_role` or
`users_can_register` is written**. That is a point-in-time check, not an invariant, and it
is defeated without ever touching either option:

- **Grant the capability later.** Set `default_role` to a safe role, pass the gate, then
  `add_cap()` that role a code-capable capability — or rewrite `wp_user_roles` wholesale
  (§5.5). The stored policy still reads "subscriber"; the role it names is now dangerous,
  and nothing re-validates. **Fix:** the invariant must also fire on capability writes to
  whichever role is *currently* the registration default, not only on writes to the policy
  options.
- **Multisite signs users up by a different route entirely.** `wpmu_activate_signup()` fires
  `wpmu_activate_user` with the signup row's unserialised meta, and `add_new_user_to_blog()`
  — hooked to that action — reads the role straight out of that meta and hands it to
  `add_user_to_blog()`. `default_role` is never consulted on this path. A policy enforced
  only on that option therefore does not constrain network signups at all. See
  `GB-MS-SIGNUP-ROLE` in `docs/upstream-sources.md`, whose row also records the wrong line
  this bullet cited until #421's audit (`llm-lies-log.md` #59). **Fix:** gate the
  signup-meta role on the same invariant, or state plainly that the policy is single-site
  only.

The general shape of the bug is worth naming, because it recurs: **enforcing a policy where
it is *written* rather than where it is *used* leaves every other route to the same state
open.** It is the option-write bypass of §5.5 in a different dress.

### 5.7 REST role promotion must yield a challenge, not a silent success (#311)

The escalation guard catches promotion at the capabilities meta write (§5.3, §6 row 6). That
seam short-circuits the **write** — but `WP_User::set_role()` returns `void`, so the REST
users controller has nothing to test and reports **HTTP 200 with the role unchanged**. The
caller is told the promotion succeeded; it did not. That is worse than a refusal, because
nothing downstream learns the truth, and it breaks the §5.2 contract that a gated REST call
returns **403 with `code: sudo_reauth_required` and a `challenge_url`**.

The gate must therefore produce its refusal at a seam the controller can observe: check the
gate in `WP_REST_Users_Controller::update_item()` (which already returns `WP_Error`) **before**
the role write, with the meta-write guard kept as the backstop for callers that bypass the
controller. Backstop and contract are different jobs; one seam cannot do both here.

## 6. Concrete core change list

| # | File | Function / hook | Change |
|---|---|---|---|
| 1 | `wp-includes/gate.php` (new) | internal action definitions | Small private catalog used for labels, canonical digest schemas, and chokepoint mapping; no public registry API (§4.1) |
| 2 | `wp-includes/gate.php` (new) | catalog bootstrap | Load the built-in definitions early enough for every guarded chokepoint; do not expose third-party registration in Cut 1 |
| 3 | `wp-includes/user.php` | `wp_update_user()` | Detect consequential field changes; gate; return `WP_Error` (§5.1) |
| 4 | `wp-includes/user.php` | `wp_insert_user()` | Gate `core/create-user` for authenticated privileged-context inserts only — **not** anonymous registration/guest-checkout (actor 0 → fail-closed) and **not during `wp_installing()`** (the first admin is created before any actor/window exists — the guard must exempt install or it bricks setup); needs a reliable admin-context signal, see §8 |
| 4d | `wp-includes/class-wp-application-passwords.php` | **`WP_Application_Passwords::create_new_application_password()`** (`:89`; returns `WP_Error` at `:95`/`:116` — verified) | Gate the **shared sink**, not the two surfaces. Enumerating the REST controller and `authorize-application.php` was the same mistake §3 decision 1 rejects: it covers the routes someone thought of and misses the next one. The sink already returns `WP_Error`, so no core patch is needed — this is the §6.3 veto audit's row 4d, previously deferred (#326) |
| 4e | `wp-admin/options.php` / `options-general.php` + REST settings + `wp-includes/user.php` (`default_role` read) | `users_can_register` / `default_role` write | Enforce the `core/set-registration-policy` invariant (§4.1): reject a code-capable default role, by effective capability |
| 5 | `wp-admin/includes/user.php` | `wp_delete_user()` (**bool** return; `do_action( 'delete_user' )` at `:381`, `'deleted_user'` at `:464`, no `pre_delete_user` filter — verified) | **Core patch required.** No existing hook can veto: `delete_user` is observational. Either change the return contract to `bool\|WP_Error` (and update `WP_REST_Users_Controller::delete_item()`, whose `if ( ! $result )` would read a `WP_Error` as success) or add a short-circuiting `pre_delete_user` filter. Per-surface gating (REST permission callback + admin handler) is a **fallback that leaves programmatic and WP-CLI callers ungated** — a documented residual, not chokepoint coverage |
| 6 | `wp-includes/class-wp-user.php` / `wp-includes/meta.php` | `WP_User::set_role`/`add_role` and **`update_user_metadata` on the `{prefix}capabilities` key** — **not `map_meta_cap`** (#309) | Escalation guard (§5.3). The meta hook is what carries it: `update_{$meta_type}_metadata` is a genuine short-circuit filter (§6.3), while `WP_User::set_role()` returns `void` and cannot be vetoed at the function. `map_meta_cap` enforcement is **withdrawn** — it makes `current_user_can()` false, so affordances vanish instead of challenging, it recurses via `user_can()`, and it runs on ordinary requests |
| 7 | `wp-includes/ms-functions.php` (`add_user_to_blog`) · `wp-includes/capabilities.php` (`grant_super_admin`) | `add_user_to_blog`: **`can_add_user_to_blog` is a real veto filter** — `ms-functions.php:183` applies it and `if ( true !== $can_add_user ) { … return $can_add_user; }` hands the `WP_Error` back to the caller (verified). `grant_super_admin`: writes `update_site_option( 'site_admins', … )` and **discards the return**, then fires `do_action( 'granted_super_admin' )` and `return true` (verified) | **Split verdict.** `add_user_to_blog` is gateable **today, no core patch** — return a `WP_Error` from `can_add_user_to_blog`. `grant_super_admin` **needs a core patch**: a `pre_update_site_option_site_admins` veto is silently ignored — the write's failure is not checked, so `granted_super_admin` still fires and the function still returns `true`, reporting a **false success**. It also returns `false` early when `$GLOBALS['super_admins']` is defined or the install is single-site, so gating there is moot in that configuration |
| 8 | proof primitive, location and API unsettled | historical helpers: `wp_issue_action_proof`, `wp_redeem_action_proof`, `wp_end_reauth_proofs` | Preserve the required properties—canonical action binding, short lifetime, and atomic single use—while Phase 27/29 select transport, storage, and API; **no token rotation or reusable window** |
| 9 | storage location unsettled | historical candidate: token record schema + `attach_session_information` | Preserve the lesson that plugins must not be able to pre-authorize a session. The signed `reauth_*` record and session-token storage are prior candidate mechanics, not Cut 1 decisions |
| 10 | `wp-includes/gate.php` (new) | `wp_check_action_gate()` + decision class | Gate evaluation (§4.3) |
| 11 | `wp-admin` common client + opted-in screens | preflight action intent | Pause before submission, obtain the server-canonical digest, invoke the trusted confirmation flow, and submit once with action-bound authority through the Phase-27 handoff (§5.1) |
| 12 | `wp-login.php` or isolated provider surface | new `action=reauth` | Verify the account's real factor(s), rate-limit, and issue a proof for the canonical digest; never receive or execute the mutation body |
| 13 | `wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php` | update/create/delete | Surface `sudo_reauth_required` as 403 + challenge metadata |
| 14 | `wp-admin/includes/class-wp-upgrader.php` | **`WP_Upgrader::install_package()`** | Gate the shared package-write sink (§5.4) — one insertion covering plugin/theme install, ZIP upload, single + bulk update, and language packs, on every surface; **not** `Plugin_Upgrader::install()` |
| 14-pre | `wp-admin/includes/class-wp-upgrader.php` | **`upgrader_pre_download`** (`:322`) | **The primary seam for the interactive branch — it fires before the download and therefore before `unpack_package()`.** Returning a `WP_Error` short-circuits `download_package()` (`if ( false !== $reply ) { return $reply; }`), so nothing is fetched and nothing is extracted. Existing filter, **no core patch**. Row 14 stays as the **backstop for direct callers** that never pass through `run()` — `Plugin_Upgrader::install()` with a local path, or anything invoking `install_package()` itself. Two seams, not one |
| 14b | `wp-admin/includes/file.php` | **`wp_edit_theme_plugin_file()`** | Gate the shared plugin/theme file-editor write sink (both editors + the AJAX path), plus an editor-open early challenge (§5.4) |
| 14c | `wp-admin/includes/plugin.php` | `activate_plugin` (`null`\|`WP_Error`), `delete_plugins` | Gate activation (REST `update_item` reaches it) and delete (§5.4) |
| 4f | `wp-admin/includes/class-core-upgrader.php` | `Core_Upgrader::upgrade()` → `update_core()` | Gate `core/update-core` on this path — `install_package()` is never reached here (`GB-CORE-UPDATE-SINK`). **But not at `update_core()` itself: that is too late for the same reason `install_package()` is (§5.4).** Verified against trunk, `Core_Upgrader::upgrade()` calls the inherited `unpack_package()` at `:156` and only reaches `update_core()` at `:178`, so by the sink the archive is already extracted under `wp-content/upgrade/`. The guard belongs **before the unpack**, early in `upgrade()`; `update_core()`'s own `WP_Error` returns remain the backstop for callers that reach it directly. Covers both `do-core-upgrade` and `do-core-reinstall`; a rule matching one action misses the other (§4.1) |
| 4g | `wp-includes/option.php` · `wp-includes/ms-blogs.php` | `pre_update_option_{$option}` · **`pre_update_site_option_{$option}`** | The §5.5 option-write guards, which are otherwise absent from this map. Per-site: `active_plugins`, `template`, `stylesheet`, `{$wpdb->get_blog_prefix( $site_id )}user_roles` (**not** literally `wp_user_roles` — it is prefix- and site-dependent), `admin_email`, `new_admin_email`. Network: `active_sitewide_plugins` via the **site-option** hook. Veto by returning the old value; note the caller sees `false`, indistinguishable from "no change needed" (§6.3) |
| 6b | `wp-includes/meta.php` | **`add_{$meta_type}_metadata`** | Backstop for the *first* `{prefix}capabilities` write, which is an **add**, not an update — an escalation guard hooked only on update misses user creation (`GB-ADD-META-SHORTCIRCUIT`) |
| 6c | `wp-includes/class-wp-user.php` | `WP_User::set_role()` / `add_role()` | **Core patch.** An in-method check *before* the object's `caps`/`roles` are mutated, returning the refusal to the caller. The meta hook cannot serve here: the object is elevated for the rest of the request regardless of whether the write lands (§6.1, §6.3) |
| 13b | `wp-admin/authorize-application.php` | no-JS approve flow | **Legacy surface adapter.** Gating at the shared issuance sink reliably refuses; the adapter returns through reauthentication and asks the user to review and submit the approval again. It does not retain the original approval request |
| 5b | `wp-admin/includes/ms.php` | `wpmu_delete_user()` (`:145`) | Multisite user deletion, which `wp_delete_user()` does not cover. Same `bool` return-contract problem as row 5, and the same core-patch ask (§6.1). `GB-MSDELUSER-BOOL` |
| 12b | second-factor management | factor enrollment / removal | Gate them. If a factor can satisfy the challenge (§7), then adding an attacker-controlled factor or stripping the victim's *is* a credential pivot — a gate on the door and not the lock (§4.2 point 4c) |
| 15 | `wp-includes/theme.php` · `wp-admin/includes/theme.php` | `delete_theme()` (`bool\|null\|WP_Error`), `switch_theme()` (**void**) | `delete_theme` takes the early `WP_Error` guard (callers check `is_wp_error()`). `switch_theme` **needs a core patch** — vetoing `pre_update_option_template`/`_stylesheet` is **not sufficient**: verified against trunk, `switch_theme()` also writes `theme_switch_menu_locations`, `template_root`/`stylesheet_root` (or deletes them), `current_theme`, and mutates sidebar-widget state, then fires `switch_theme`/`after_switch_theme`. Vetoing two of those writes leaves a **half-switched site** that reports the new theme while running the old one, with no error surfaced (the function returns `void`). Theme *install/update* is covered by row 14's shared sink |
| 16 | preflight endpoint + admin client | pending intent, not request stash | Retain only a bounded canonical digest and display metadata on the server; the browser retains the unsent form/editor state. Legacy screens use reauthenticate-then-resubmit (§5.1, §7.1) |
| 17 | Site Health | new test | Report whether gating and its challenge provider are usable. Also report an aggregate, privacy-preserving diagnostic when a screen declared as preflight-integrated repeatedly reaches the veto without a valid preflight correlation. Store no target, filename, request body, credential, upload byte, or proof material |

This table is the preserved broad change inventory. Cut 1 implements only the
rows required by the two active descriptors, their proof/preflight mechanism,
and their diagnostics. The identity, settings, activation/deletion, core-update,
and other catalog rows are deferred. A general registry is not in Cut 1.

---

### 6.1 What this costs in core patches — stated plainly

Of the six contested seams, **one is gateable with an existing hook and five are core changes.** A proposal that implies otherwise is not landable as written, so the ask is itemised here rather than left to the reader:

| Seam | Ask |
|---|---|
| `add_user_to_blog` | **None.** Return a `WP_Error` from the existing `can_add_user_to_blog` filter (`ms-functions.php:183`). |
| `grant_super_admin` | Check the `update_site_option()` result (or add a `pre_grant_super_admin` short-circuit) so a refused write stops `granted_super_admin` firing and stops the function returning `true`. |
| `wp_delete_user` **and `wpmu_delete_user`** | A `bool\|WP_Error` return contract, or a short-circuiting `pre_delete_user` filter. Callers testing `if ( ! $result )` must be updated with it. **Both functions, not one:** network-admin deletion reaches `wpmu_delete_user()` (`wp-admin/includes/ms.php:145`, `GB-MSDELUSER-BOOL`) without passing through `wp_delete_user()`, and carries the identical `bool` contract, so patching only the single-site function leaves deletion ungated on multisite — the surface with the most privilege (§6 row 5b). |
| `switch_theme` | An in-function pre-check. Option-level vetoes cannot work here — the function performs several writes and returns `void`. |
| self-email (admin path) | A short-circuit in `edit_user()` before `send_confirmation_on_profile_email()`. |
| `WP_User::set_role()` | An early check **before** the object's `caps`/`roles` are mutated. Vetoing the `{prefix}capabilities` write is not enough: `set_role()` mutates in memory first, ignores the write's return, and recomputes from the mutated state, so the request continues with the new role in effect (§6.3). |

**Five asks — but six patch sites.** The deletion row is one contract change applied in two functions, so a committer working from this table should expect six edits, not five. (A different six from the six contested seams above, which counts `add_user_to_blog` — the one needing no patch at all.) The distinction is not pedantry: an earlier revision of this table named only `wp_delete_user`, which would have had a committer close single-site deletion and leave the multisite route open.

Everything else in §6 already returns `WP_Error` and needs no contract change.

### 6.3 Veto audit — every chokepoint, checked rather than assumed (#358)

§6.1 covers the five seams a reviewer challenged. This is the sweep across the rest, because the two failures found there — a discarded write-return reported as success, and an option veto that left the remaining writes applied — show that a plausible-looking return contract is not evidence. Each row below was checked against `wordpress-develop` trunk.

| Row | Seam | Can it refuse? | Evidence |
|---|---|---|---|
| 3 | `wp_update_user()` | **Yes** | Calls `wp_insert_user()` and returns early on `is_wp_error()`, so a `WP_Error` propagates to every caller. |
| 4 | `wp_insert_user()` | **Yes** | Returns `WP_Error` on eleven distinct paths; callers already branch on it. |
| 4e | `users_can_register` / `default_role` writes | **Yes, with a caveat** | `pre_update_option_{$option}` (`GB-OPTION-PRE-VETO`) — returning the old value trips the `$value === $old_value` check and `update_option()` returns `false` without writing. **Caveat:** `false` is indistinguishable from "no change needed", so the caller cannot tell a refusal from a no-op. Sufficient here only because the option write *is* the whole effect — the opposite of `switch_theme`, where the same technique leaves later writes applied (§6.1). |
| 6 | capability escalation | **Partly — the meta hook stops the write, not the request** | The `{prefix}capabilities` write can be vetoed: `update_{$meta_type}_metadata` is a genuine short-circuit filter (`GB-META-SHORTCIRCUIT`). **That is not sufficient when the change arrives through `WP_User::set_role()`.** Verified against trunk: `set_role()` mutates `$this->caps` and `$this->roles` **before** calling `update_user_meta()`, **ignores that call's return**, then recalculates from the mutated state via `get_role_caps()` / `update_user_level_from_caps()` and fires `remove_user_role` / `add_user_role` / `set_user_role`. So a vetoed write leaves the in-memory user reporting the **new** role for the rest of the request, and every `current_user_can()` in that request answers from it — the same partial-state failure as `switch_theme` (§6.1). `set_role()` therefore needs a **core patch** as well: an early check before the object is mutated. The meta hook remains the right backstop for `update_user_meta()`-level writes that never reach `set_role()`. |
| 14-pre | `upgrader_pre_download` | **Yes, and early enough** | Returns the filter value when not `false`, so a `WP_Error` stops the download before `unpack_package()` runs. The only seam in this family that refuses *before* attacker-controlled bytes reach disk. |
| 14 | `WP_Upgrader::install_package()` | **Yes** | Six `WP_Error` returns; the upgrader's callers already thread them. |
| 14b | `wp_edit_theme_plugin_file()` | **Yes** | Twenty-one `WP_Error` returns — the most defensively written sink in the set. |
| 14c | `activate_plugin()`, `delete_plugins()` | **Yes** | Four `WP_Error` returns each. |
| 4f | core update (`Core_Upgrader::upgrade()`) | **Yes, but only before unpack** | `update_core()` returns `WP_Error` on six paths, so a guard there refuses — too late, since `unpack_package()` ran at `:156`. A guard placed early in `upgrade()` refuses before extraction. |
| 5b | `wpmu_delete_user()` | **No — core patch** | Same `bool` return contract as `wp_delete_user()`; network-admin deletion reaches it without passing through row 5's seam. |
| 15 | `delete_theme()` | **Yes** | Returns `WP_Error`; callers test `is_wp_error()`. (`switch_theme()` in the same row cannot — §6.1.) |

**Result:** one further core-patch ask. `WP_User::set_role()` joins §6.1 — the tally is **five**, not four — and every other chokepoint can genuinely refuse. Two properties are now recorded rather than assumed: an option-level veto is only adequate where the option write is the entire effect, and row 6's enforcement lives on the meta write because the role setter returns `void`.

**Row 4d is now covered** (#326): issuance moved to the shared sink `WP_Application_Passwords::create_new_application_password()`, which is declared at `:89` and already returns `WP_Error` at `:95`/`:116` — **yes, it can refuse, and no core patch is needed**. That completes the sweep: every row in §6 has been checked.


### 6.2 No-actor and self-heal carve-outs — required for every veto seam

A gate that fails closed on a path with **no actor to challenge** does not protect that path; it breaks it. Every seam added here inherits this rule, which is stated once:

- **Self-heal.** `validate_current_theme()` (`theme.php:898`) calls `switch_theme( WP_DEFAULT_THEME )` at `:927` with no actor, guarded only by `wp_installing()` and the `validate_current_theme` filter. A fail-closed theme gate without a carve-out leaves a site whose theme directory vanished unable to recover through any in-product route.
- **Multisite lifecycle.** `get_active_blog_for_user()` calls `add_user_to_blog()` on the login/redirect path; `add_existing_user_to_blog()` runs from an invitation; `wpmu_activate_signup()` creates users as actor 0. Gating these fails ordinary login and signup.
- **Install and recovery.** `wp_installing()` (already carved out for `core/create-user`, §8) and recovery mode.
- **The key-verified password reset.** `reset_password()` (`wp-includes/user.php:3511`) calls `wp_set_password()` at `:3526` on the **actor-0** lost-password completion path, where the emailed reset key *is* the proof and no session exists. Gating `wp_set_password()` without carving this out refuses every legitimate reset site-wide under default-on fail-closed — the single most damaging false positive in the design, because it locks out exactly the users least able to route around it. Gate the **authenticated** password changes (`core/change-own-password`, `core/change-user-password`, which run through `wp_update_user()`).
  **Exempt the reset on a positive signal, not on actorlessness.** `reset_password()` neither receives nor validates the emailed key and does not guarantee the caller is actor 0, so "no actor" is the wrong test in both directions: a low-level call that is not a reset would inherit the exemption, and a *logged-in* browser completing a legitimate reset would be challenged. The caller — `wp-login.php`'s reset flow — must set a trusted, request-scoped signal **after `check_password_reset_key()` succeeds**, and the exemption keys on that. Same shape as the provenance signal in §5.5b, and it fails closed if absent.

The rule: a gate applies when there is an **authenticated actor whose intent can be proven**. With no actor, the correct behaviour is to allow and — where it matters — log, never to block. This is the same boundary §9 draws for non-interactive callers, applied to core's own internal calls.

## 7. Challenge model

Gate-track baseline (proposal §4, §11) — start small:

- Browser-first interstitial at `wp-login.php?action=reauth`, in the existing authenticated context.
- Password verification against the current user **where a usable password is the account's actual factor**; if a 2FA plugin is present, expose a `wp_reauth_second_factor` hook so it can add **and validate** its factor (the plugin integrates the Two-Factor plugin exactly this way). Core ships no 2FA of its own.
- **A password challenge cannot be mandatory (#305).** SSO-only, passkey-first and magic-link accounts have no password the user knows — often a random string set at provisioning — so a password-plus-optional-factor design does not inconvenience them, it **locks them out of every gated action permanently**, for the entire admin population of such an organisation, under default-on. That contradicts the proposal's own framing that the gate *consumes existing authenticated identity* rather than introducing a new one.
  So the contract is **at least one provider-verified factor**, not "password, plus extras": a registered provider may **satisfy** the challenge in place of the password, not merely add to it. Where a password is genuinely the account's factor, it remains required, and where both exist both are required (the "both factors passed" guarantee below is unchanged for those accounts).
  **Do not infer this from the stored hash.** Every WordPress account has one, including SSO accounts, so `user_pass` emptiness is not a signal — the provider must declare that it owns authentication for this user. Getting that backwards fails in the dangerous direction: silently accepting a factor-only challenge for a password account.
  State the consequence honestly: for an SSO-only account the gate's guarantee becomes *fresh proof from the factor that actually authenticates this user*, not *knowledge of a WordPress password*. That is the correct guarantee — the password was never the security boundary for those accounts — but it should be written down rather than discovered.
- **The second-factor hook must report an explicit result, not merely render fields.** For the "both factors passed" guarantee below to be implementable, the hook's contract yields one of *pass* / *fail* / *pending* (e.g. a filter returning `true` / `WP_Error` / a pending sentinel); the challenge handler treats a missing or non-affirmative result as **not passed**. The exact signature is a Phase-2 detail; the load-bearing invariant is that *rendering a field ≠ validating it*.
- On success — **every factor the account actually authenticates with**: the password when it is one of them, plus an affirmative *pass* from each factor registered via `wp_reauth_second_factor`. For an account whose provider declares it owns authentication, the provider's pass alone is success; requiring a password there is the permanent lockout §7 exists to prevent (#305). Continue into the trusted action-confirmation/handoff selected by Phase 27. No action executes merely because authentication succeeded.
- Nonce-protected, rate-limited, lockout on repeated failure.

### 7.1 Preflight and confirmation contract

With no reusable window, the one-time token authorises exactly one digest. The
smooth path obtains that digest before the mutation is sent, so the browser
retains the user's form state while the server retains only a bounded pending
intent—not an executable request. The confirmation UI needs a normative
contract:

- **Treat preflight as a security-sensitive read endpoint.** Apply the same
  underlying action capability before describing the target, minimize response
  differences that amplify enumeration, and rate-limit repeated probes.
  Preflight must not disclose target state the caller could not already read.
- **Render every gated field.** The action id, a human label, the target, and **all** parameters the gate evaluated. A screen that says "Confirm plugin activation" without naming the plugin is not a confirmation.
- **No attacker-controlled chrome.** Labels come from core's internal catalog.
  Targets and parameters are canonicalized server-side; untrusted values are
  escaped and clearly delimited as data.
- **No auto-submit, no auto-focus on the confirm control**, and no `Refresh`/`onload` path that completes the action without a deliberate act.
- **The token binds to the digest of exactly what was displayed.** If the rendered parameters and the redeemed request disagree, the redemption fails.
- **Distinguish initiation from approval.** A prompt-initiation nonce is not a
  security boundary—a session clone mints valid nonces. Only the proof issued
  after fresh authentication can redeem the displayed digest.
- **Do not store the executable request.** The client retains unsent form or
  editor state. The server may retain a short-lived canonical digest and display
  metadata, but not a request body that can later be released.
- **Make broken integration observable.** When a screen declares preflight
  support but reaches the veto without a valid correlation, fail closed and
  increment only a privacy-preserving aggregate suitable for Site Health or
  development diagnostics. Silent fallback makes client breakage indistinguishable
  from intentional legacy behavior.

**Residual, stated plainly:** an ordinary same-origin modal or iframe is not a
trusted security surface against active in-origin XSS. Such a script can imitate
the UI, observe password entry, or drive confirmation. The effect veto and
action-bound proof still prevent a copied cookie from silently reusing an
approval, but stronger credential protection requires browser-mediated
authentication such as WebAuthn/passkeys or a genuinely isolated provider
surface. A top-level page is useful fallback UX, not by itself an XSS boundary.

Explicitly deferred: WebAuthn ceremonies, external IdP redirects, multi-step TOTP/recovery flows, async/pending challenges, consent overlays.

---

## 8. Defaults, config, back-compat

- **Default state.** The demonstrator enables the gate for the two active
  descriptors. Default-on behavior for a future core patch is a Phase-32
  proposal decision, not something established by this inventory. Previously
  this section proposed shipping the broader private catalog by default,
  because a security default that must be discovered protects almost no one.
  There is no public registry in Cut 1. Preflight-enabled screens pay one
  challenge plus one confirmation; legacy screens pay reauthentication plus
  explicit resubmission. Nothing auto-replays. Provide
  `WP_DISABLE_ACTION_GATE` for emergencies and a per-action
  `wp_action_gate_enabled` filter in the demonstrator. Recovery/default behavior
  for any broader catalog is deferred.
- **Config surface.** `wp_action_gate_enabled` plus the emergency
  `WP_DISABLE_ACTION_GATE` constant. Do not expose a reusable-window TTL or a
  public catalog filter in Cut 1. Keep the plugin's
  `Disabled/Limited/Unrestricted` per-surface vocabulary out of core; Cut 1 is
  binary per built-in effect.
- **Back-compat.** Because enforcement returns existing `WP_Error` types from functions that already return them, non-updated callers degrade safely to "action refused with an actionable error," never a fatal or a silent pass. In Cut 1 programmatic callers need no bypass at all — they are out of scope and never evaluated (§1). The escape hatch matters only once the follow-on project extends enforcement to them: at that point migrations and trusted automation under WP-CLI/cron (which have **no browser authentication session**) short-circuit via the `wp_action_gate_enabled` filter or a scoped constant, **not** a browser proof API.
- **Multisite terminology** (#37593/#39174): "network administrator" for
  ordinary network authority, "super admin" only for core's technical concept,
  and "step-up authentication" for the per-action proof. No permanent role or
  reusable elevation window is introduced.

---

### 8.1 Fail-closed recovery — the triggers need hardening (#329)

Fail-closed gating is only safe if the escapes are reachable and the carve-outs cannot be
turned on by an attacker. Two retained Cut 1 concerns do not currently hold up.

- **`wp_installing()` is a mutable runtime switch, not a constant.** Verified: it holds a
  `static $installing` seeded from `WP_INSTALLING` but reassignable by any caller passing
  an argument (`load.php:1634`), and core itself flips it `true` during DB-upgrade
  routines. So the §6.2 install carve-out must **not** rest on `wp_installing()` alone —
  anything running in-process can set it and walk through the exemption. Pair it with the
  actor test that carve-out actually means (**no authenticated actor**), and treat
  `wp_installing()` as a hint about context, never as authorisation.
- **`wp_action_gate_enabled` cannot rescue an early failure.** The catalog registers at
  file load (§6 row 2) so that fail-closed gating cannot be defeated by a plugin
  unhooking `init`. A filter cannot exist that early, so at file-load time only
  `WP_DISABLE_ACTION_GATE` — a constant in `wp-config.php` — can disable the gate. Say so:
  documenting a filter as the recovery path, when the failure it must recover from happens
  before filters exist, is a recovery path that does not work when it is needed.

The general rule behind all three: **an escape hatch is part of the security design, not an
afterthought.** Each one needs to be reachable by the operator who is locked out, and
unreachable by the attacker who caused it.

## 9. Non-interactive callers: branch on actor class, not transport

> **Cut 1 scope (normative, per §1 and [#320](https://github.com/dknauss/Sudo/issues/320)):** everything in this section except the first table row is **deferred out of Cut 1**. The actor-class *dispatch* is part of the Cut 1 architecture; the non-interactive *policies* below are the design intent for the follow-on provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4)), recorded here so the seam is built to accommodate them. In Cut 1, any actor class other than an interactive cookie session passes through **ungated**.

The gate's *decision* ("may this effect proceed now?") is transport-agnostic; only the *rendering* of a challenge is interactive (§3.6). Excluding non-interactive routes from **enforcement** because they cannot render a **challenge** is a category error that leaves the modern scriptable routes open (REST install/activate is reachable with a stolen cookie plus a harvested `wp_rest` nonce). That reasoning stands — it is the reason the follow-on project exists — but #320 decided the Cut 1 cut ships before it, because a default hard-block of API credentials is a back-compat regression (#306) and the auto-updater ALLOW branch needs a provenance primitive core lacks (#307).

| Actor at the sink | Decision | In Cut 1? |
|---|---|---|
| Interactive cookie session, no proof for this action | **Preflight → challenge → confirm → submit once**; legacy clients reauthenticate and resubmit | ✅ **Yes — the Cut 1 scope** |
| API credential (Application Password) / XML-RPC, no window | **Block + log** — no interactive reauth is possible over a credential channel | ❌ Deferred ([#306](https://github.com/dknauss/Sudo/issues/306)) |
| No actor, **and** core's own automatic updater, **and** package from the site's configured update source | **Allow** — background security updates must keep working; a blanket block here is a net security regression | ❌ Deferred ([#307](https://github.com/dknauss/Sudo/issues/307)) |
| WP-CLI | **Allow by default, operator-configurable** — shell access already dominates the gate (anyone who can `wp plugin install` can `cp` a PHP file into `WP_PLUGIN_DIR`), so a CLI block buys nothing against that attacker and costs every deployment pipeline | ❌ Deferred |

What Cut 1 additionally defers, even within the interactive class, is the **per-surface policy UI** and any attempt to render an interactive challenge on a non-interactive surface.

Third-party transports (WPGraphQL, custom REST/RPC endpoints) are **not** core surfaces to enumerate: the chokepoint gate applies to their mutations regardless, and each request resolves to one of the actor classes above. Richer per-surface policy for them is the plugin's job (proposal §9).

---

## 10. Acceptance criteria

A conforming Cut 1 implementation must show:

1. Plugin/theme upload and plugin/theme file-editor save are refused before the
   first irreversible code-write effect when no valid proof is present.
2. A refused package operation leaves `wp-content/upgrade/` untouched. Removing
   the pre-download guard must fail the named test; asserting only a returned
   error is insufficient.
3. Browser B, holding a byte-for-byte copy of Browser A's login cookie from
   before approval and able to mint valid WP nonces, cannot redeem Browser A's
   action proof.
4. Two concurrent redemptions of one proof execute at most once, demonstrated
   against the real compare-and-delete or equivalent storage primitive.
5. Changing the action, target, security-relevant parameters, editor contents,
   or upload digest after approval refuses the effect.
6. Integrated screens keep the original operation unsent until approval and
   transmit it once. No executable request, secret, editor body, or ZIP upload is
   retained server-side for later release.
7. Legacy/no-JavaScript screens fail closed and use reauthenticate → return →
   explicit resubmission. Nothing auto-replays.
8. Preflight applies the underlying action capability, minimizes target
   disclosure, rate-limits probes, and never trusts client-supplied labels.
9. A deliberately broken declared integration produces an aggregate diagnostic
   without retaining sensitive action or target data.
10. Every claimed guard has a focused mutant that the named test kills.
11. The evidence report states exactly which active-XSS persistence/reach cases
    were tested and does not generalize beyond them.
12. With the experimental gate disabled, the two effects behave observably as
    current core; no public registry runs independently.

A pinned Playground/wp-env demonstrator should preserve the original
`consequential-actions` attack narrative and Slice A differential controls while
using neither project's superseded window/registry mechanism.

---

## 11. Open questions (for core review)

1. A public registry is deferred beyond Cut 1. Revisit only when concrete
   consumers justify a stable API; do not treat Abilities compatibility as a
   prerequisite or assume the eventual design should copy Abilities (§4.1.1).
2. `WP_Session_Tokens` extension vs. a dedicated store (proposal §11-Q2).
3. ~~Flat recent-auth freshness vs. scope-bound windows for Cut 1.~~ **Closed by §4.2's move to per-action step-up:** with no reusable window there is nothing to scope, and the multisite cross-site-reach problem disappears with it (a proof authorises one action on one site). Re-opens only if a reusable window is ever reintroduced, in which case the signed scope must be a compound, versioned key (`Cut 1|{site_id}|{class}`) so the site dimension is not spent on consequence-class grouping.
4. Should `core/create-user` gate *all* inserts or only privileged-context ones (registration/import would otherwise trip it)?
5. Default-on vs. default-off for gating (§8) — the single biggest adoption/impact tradeoff.

---

## 12. Reviewer findings / known gaps (design review, July 2026)

Adversarial design reviews — two-model (Fable + Opus), then a **Codex** pass — of this spec and the proposal. They **validated the problem framing and identified a candidate mechanism** (gate-at-the-sink, per-session HMAC separate-proof, actor-class branch, fail-closed) — **not a ready-to-land security design.** Several open items are **P1**, so the closure claim should not be presented as settled while they stand: some are mechanical, but the ambient-bearer / auto-replay problem (#315) and the self-email seam (#316) undercut load-bearing claims until resolved. Tracked as GitHub issues **#302–#320** (high-severity below; #311 medium/low tracker; **#320 CLOSED — the scoping split was decided and is now applied in §1/§3.6/§5.2/§9/§10**). The high-severity gaps:

1. **Core update is ungated (#302).** No `core/update-core` entry; `Core_Upgrader::upgrade()` calls `update_core()` without routing through `install_package()` — the attack-tree already gates it as `core.update`. Add the entry; gate `update_core()` explicitly.
2. **The `do_action()` pre-op adapters cannot block (#303) — resolved in §6 with a verified seam inventory.** Each seam was checked against `wordpress-develop` trunk rather than assumed: `add_user_to_blog` is gateable **today** via the `can_add_user_to_blog` veto filter (no core patch); `grant_super_admin`, `wp_delete_user`, `switch_theme` and the admin self-email path each **require a core patch**, for different reasons (a discarded write-return that reports false success; a `bool` return contract; partial state left by a two-option veto; an observational `do_action`). The earlier suggestion to veto `pre_update_option_template`/`_stylesheet` is **withdrawn** — it leaves a half-switched site. See §6 rows 5/7/15 and the core-patch list in §6.
3. **Gating `wp_set_password()` bricks the unauthenticated reset (#304).** The key-verified `reset_password()` path runs at actor 0; add an explicit carve-out, and state the residual mail-channel trust in §3's invariant.
4. **The mandatory password step locks out SSO/passkey/passwordless accounts (#305).** §7 needs a factor-only / provider-supplied-proof path where no core password exists.
5. **Default-on hard-block of App-Password/automation callers is a back-compat regression (#306).** §9 + §8 default-on silently break headless/CI/deploy/cron ecosystems and silently fail programmatic `WP_Error`-ignoring callers. Reconsider the default or add an opt-in allowance for authenticated non-interactive callers.
6. **The auto-updater ALLOW branch depends on a provenance primitive core lacks (#307).** Define a non-filterable trusted flag (set inside `WP_Automatic_Updater`) or narrow the ALLOW to core-signed packages.
7. **Flat freshness lets a trivial action authorize the most consequential ones (#308).** **Resolved by construction** — §4.2 drops the reusable window entirely, so no challenge can satisfy an action other than the one it was minted for. `consequence.scope` is no longer load-bearing for Cut 1.
8. **`map_meta_cap` enforcement conflates authz with proof-of-intent and invites recursion (#309).** **Resolved:** §5.3 and §6 row 6 now enforce at the `wp_update_user` / caps-meta-write chokepoint only, and the `map_meta_cap` insertion is withdrawn. It bought no coverage the chokepoint lacks, and it broke §1's own boundary — denying the capability hides the affordance instead of challenging for it.
9. **HMAC forgery-resistance is conditional on `AUTH_SALT` placement (#310).** With the salts in `wp_options`, the wp2shell SQLi read (§10) forges the MAC. State salts-in-`wp-config.php` as a hard precondition (§4.2), not a footnote.

A later **Codex** pass added two more high-severity design blockers not covered above:

10. **The proof cookie is an ambient bearer — a pre-window clone can drive the reauthed browser (#315, P1).** Preserving the shared session token (to keep nonces valid) lets a cookie clone harvest valid nonces; with the proof cookie `SameSite=Lax`, an attacker harvests an activation nonce pre-reauth, then navigates the *victim's* browser to a crafted action after the victim reauths. A user-keyed auto-replay stash is a second vector. **The candidate resolution for the copied-session case** is to retain no executable request, pause before submission, and bind single-use authority to the server-canonical action/target digest—not merely a WP nonce. Legacy screens require explicit resubmission. Whether the selected handoff actually supplies that property, and what in-origin XSS can exercise, is the coupled Phase-27 blocker (§4.2, §7.1, §2), not a resolved wording question.
11. **The self-email gate is on an observational `do_action` (#316, P1).** §4.1 routes `core/change-own-email` to `personal_options_update`, whose return is discarded — it cannot veto the pending-email write, so this Group-B pivot has no working enforcement on the profile surface; and on multisite the confirmation path writes the signup row *before* `wp_update_user()`, leaving partial state. **Resolved in §4.1**, which now names the seam and the cost: `personal_options_update` is a `do_action` (`wp-admin/user-edit.php:149`, verified) and cannot veto, so the admin path takes a **core patch** to `edit_user()` short-circuiting before `send_confirmation_on_profile_email()` (`wp-includes/user.php:3864`); the REST self-update vetoes today via its `permission_callback`; the multisite confirmation endpoint is gated separately.
12. **Proof issuance is not atomic with cookie delivery (#319, medium).** The PoC writes the server proof hash, skips `setcookie()` when `headers_sent()`, ignores its return, and returns `true` regardless — so on a header-already-sent or cookie failure the server invalidates the old proof but the browser gets no new one → an unrecoverable reauth loop. It also covers only `COOKIEPATH`/`ADMIN_COOKIE_PATH` (missing `PLUGINS_COOKIE_PATH`/`SITECOOKIEPATH`). **The fix is ordering, not a return check.** `setcookie()` returns `false` only when headers are already sent; it cannot report the failures that actually matter here — the browser silently dropping the cookie (Secure/SameSite mismatch, ITP, private mode). So: **never invalidate the prior proof until a subsequent request presents the new one** (issue-then-confirm, with a previous-proof slot), rather than trusting the write.
    The cookie policy must mirror the **logged-in** cookie, not the auth cookie: verified against trunk, `wp_get_session_token()` reads `wp_parse_auth_cookie( '', 'logged_in' )`, and `pluggable.php:1195-1197` sets that cookie on `COOKIEPATH` **and** `SITECOOKIEPATH` using a separate `$secure_logged_in_cookie` — while the auth cookie uses `ADMIN_COOKIE_PATH`/`PLUGINS_COOKIE_PATH`. Using `is_ssl()` or the auth-cookie paths produces a proof that exists in wp-admin and vanishes on the front end and on cookie-authenticated REST — the same unrecoverable loop from a different cause.

These findings remain the review history of the broad design. Under the current
GSD program, Cut 1 is the two-effect mechanism slice. Identity pivots, core
update, automation/provenance, API-credential policy, and the remaining catalog
are deferred until the slice passes.

---

*Contributor credits for WP Sudo's design lineage (Blackbourn, Nash, Alkan) live in the plugin readme's Acknowledgements section. Prior art is cited impersonally in the body — principally Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140) (recent auth for consequential actions) and [#16470](https://core.trac.wordpress.org/ticket/16470) (single-site email-change confirmation, whose `send_confirmation_on_profile_email()` flow §4.1 must accommodate).*
