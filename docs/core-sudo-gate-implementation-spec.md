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
- **Interactive surfaces first — this is the v1 scope boundary, decided in [#320](https://github.com/dknauss/Sudo/issues/320).** The v1 enforcement cut targets **browser + cookie-authenticated REST only**. Application Passwords, WP-CLI, cron, and XML-RPC are **out of v1 scope entirely** — not challenged, not blocked, not allowed-by-policy. They pass through ungated, exactly as they do in current core, and their policy is deferred to the separately-scoped provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4): [#306](https://github.com/dknauss/Sudo/issues/306) API-credential policy, [#307](https://github.com/dknauss/Sudo/issues/307) auto-updater provenance). **This is the single normative statement of surface scope; §3.6, §5.2, §9, and §10 defer to it.**

  **"Programmatic" is not an actor class, and must not be treated as one.** A plugin calling `activate_plugin()` or `install_package()` from inside an admin-screen load or a cookie-authenticated REST request is *an interactive actor* — the request has a cookie session behind it. Classifying by *call origin* ("this came from PHP, not a form") would hand any hijacked session a one-line bypass: call the sink from a plugin hook instead of posting the form. **Classification is a property of the request, not of the call site.** The gate resolves actor class once per request from the authenticated identity and how it authenticated; a nested programmatic call inherits that class. The exemption above therefore covers only genuinely **actorless** contexts (no authenticated user for the request — cron, CLI) and **non-cookie credential** contexts (App Password, XML-RPC), never "a function called from PHP." Specifying the trusted classification signal — and proving no cookie-authenticated request can be resolved as actorless — is [#357](https://github.com/dknauss/Sudo/issues/357); §10 AC7 is the acceptance criterion for it.

  **Residual risk, stated plainly:** a v1 gate leaves the REST-over-Application-Password install/activate path open. That is a real gap, and earlier drafts of this spec argued (correctly) that excluding non-interactive routes from enforcement is a category error. #320 decided the trade anyway: hard-blocking API credentials by default is a back-compat regression across the deployment ecosystem (#306), and the auto-updater ALLOW branch depends on a provenance primitive core does not have (#307). v1 therefore closes the *actor-driven* paths — the dominant and cheapest attack — and is explicit that it does not close the credential-channel path. It is not a claim that the credential channel is safe.
- **Not code-provenance for the automated-update channel.** The gate's "an admin session is insufficient to introduce code" guarantee is scoped to *actor-driven* paths. The cron/auto-update path installs code with **no actor to challenge**, so its integrity is a package-signing / provenance problem this gate does not solve. WordPress has **no working package signing** today — the signed-updates trusted keys lapsed in 2021, `Core_Upgrader` requests no verification (`check_signatures=false`), and verification soft-fails by default (verified against `wordpress-develop` trunk; see `docs/llm-lies-log.md` #39 for the dated citations). Deferred to a provenance primitive (issue #307); the gate is complementary to, not a substitute for, signing.

---

## 2. Threat model

| Actor | Has valid session? | Has capability? | Defended by this spec? |
|---|---|---|---|
| Stolen/replayed admin cookie | yes | yes | **Yes, outside an open window** — no recent auth ⇒ challenge; a cookie stolen *while a window is open* inherits that elevation until it expires (window TTL) |
| XSS running in an authed admin origin | yes | yes | **Partially** — cannot silently satisfy an interactive reauth, but in-origin XSS can free-ride on (or trigger, or keylog) any window already open |
| Walk-away / shared workstation | yes | yes | **Yes** — window expires; consequential action re-challenges |
| Malicious Editor escalating to Admin | yes | no promote cap — reaches the path via a broken-access-control bug | **Partial** — a *hijacked* Editor session is blocked (the attacker can't pass the Editor's own reauth); a legitimately-authenticated malicious insider who *can* pass their own reauth is **not** stopped — the gate only forces a fresh, loggable step. Fixing the BAC bug is the authz layer's job (rows below); the gate is defense-in-depth for the stolen-session case, not a substitute |
| Attacker who knows the password | yes | yes | **Partially** — reauth still forces a deliberate, loggable step and blocks silent replay |
| Stolen Application Password / API credential | yes (API credential, not a human session) | yes | **No — out of v1 scope** (§1, [#320](https://github.com/dknauss/Sudo/issues/320)). No interactive reauth is possible over a credential channel; hard-blocking by default is a back-compat regression ([#306](https://github.com/dknauss/Sudo/issues/306)). Deferred to the provenance/automation project. **v1 does not defend this row.** |
| Attacker with mailbox access (password-reset channel) | no session needed | n/a | **No — and this is load-bearing, so it is stated rather than implied.** `retrieve_password()` → the emailed key → `reset_password()` is an ungated credential-manufacture route by design: the mail channel is the proof. The §3 invariant is therefore not "no ungated route to a credential change" but **"no ungated route that does not already require possession of the user's mailbox."** Gating the reset path would not close this (the attacker holds the key) and would break every legitimate reset (§6.2) |
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
6. **Transport-agnostic decision, actor-class dispatch.** The chokepoint returns a decision (as a `WP_Error` in practice, §5.2); the *challenge* is only one rendering of it. The decision branches on **actor class**, not transport — so the architecture accommodates a per-class policy without re-plumbing. **In v1 exactly one class is in scope:** an interactive cookie session (browser or cookie-authenticated REST) is challenged. Every other actor class is out of scope and passes through ungated (§1, [#320](https://github.com/dknauss/Sudo/issues/320)).

   Earlier drafts argued here that excluding non-interactive routes from enforcement is a category error, because it leaves REST install/activate open. **That argument is still sound and is not retracted** — it is why #306/#307 exist and why the provenance/automation project is scoped rather than dropped. #320 decided that shipping the actor-driven closure first, without a default hard-block that breaks existing integrations, is the landable sequence. The category error is deferred, not resolved.

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
| `core/change-own-email` | profile self-email change — **gate the initiating request**, not only `wp_update_user()`: with confirmation enabled, `send_confirmation_on_profile_email()` writes `_new_email` and restores `$_POST['email']` to the old value *before* `wp_update_user()` runs, so the chokepoint sees no change; gate at a **veto-capable** seam. `personal_options_update` is a `do_action` (`wp-admin/user-edit.php:149`, verified) whose return is discarded, so it can only observe. The admin path therefore needs a **core patch** to `edit_user()` short-circuiting *before* `send_confirmation_on_profile_email()` (`wp-includes/user.php:3864`) writes `_new_email`; the REST self-update can veto today via its `permission_callback`. On multisite the confirmation path writes the signup row before `wp_update_user()`, so gate the confirmation endpoint too |
| `core/change-user-email` | `wp_update_user()` other user, `user_email` changes |
| `core/create-user` | `wp_insert_user()` (returns `WP_Error`); on multisite `wpmu_create_user()` returns `int\|false`, so the gate needs a distinct adapter there (REST multisite create checks `if ( ! $user_id )`) |
| `core/delete-user` | `wp_delete_user()` / `wpmu_delete_user()` |
| `core/promote-user` | role change granting administrator / network-admin authority (see §5.3) |
| `core/edit-plugin-file` / `core/edit-theme-file` | `wp_edit_theme_plugin_file()` — the single sink `plugin-editor.php`, `theme-editor.php`, and `wp_ajax_edit_theme_plugin_file()` all funnel through; the most direct code-write path |
| `core/install-plugin` / `core/install-theme` / `core/update-plugin` / `core/update-theme` | `WP_Upgrader::install_package()` — the shared package-write funnel beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, the AJAX updaters, and the REST plugins controller. Gate here, **not** `Plugin_Upgrader::install()` (which misses bulk/update/AJAX/auto-updater), and **not** at the capability layer (`upload_plugins`→`install_plugins` in `map_meta_cap`, so it cannot distinguish an attacker ZIP from a repository install). Also covers language-pack writes into `WP_LANG_DIR`, which core executes (`.l10n.php` is `include`d) |
| `core/update-core` | `Core_Upgrader::upgrade()` → `update_core()`. **A separate seam is required: core update does NOT pass through `install_package()`.** Verified against trunk — `class-core-upgrader.php` calls `update_core( $working_dir, $wp_dir )` at `:178` and the string `install_package(` does not occur in the file at all. So the row above does not cover it, and `update-core.php?action=do-core-upgrade` (including the one-click *Re-install version X*) writes arbitrary files under `ABSPATH` from an ordinary cookie session. This is the largest single code-write in core; omitting it left the catalog gating plugin and theme code while leaving core itself open |
| `core/activate-plugin` | `activate_plugin()` — reachable directly via REST `update_item` on an already-installed plugin |
| `core/delete-plugin` | `delete_plugins()` |
| `core/switch-theme` | `switch_theme()` |
| `core/delete-theme` | `delete_theme()` |
| `core/update-connector-credentials` | `/wp/v2/settings` write of a `connectors_*_api_key` value — the WP 7.0 Connectors **credential-integrity** threat; an options / REST-settings write chokepoint (proposal §3, §8) |
| `core/change-critical-setting` | `/wp/v2/settings` write of `siteurl` / `home` (keyed `url` / `home` via `show_in_rest`) — repointing the origin loads attacker scripts **same-origin** in `wp-admin`, which can fake/keylog the challenge itself; a critical-option write chokepoint (WP Sudo's `options.critical`) |
| `core/create-application-password` | **`WP_Application_Passwords::create_new_application_password()`** — the shared issuance sink both the REST controller and the `authorize-application.php` no-JS approve flow call. Verified against trunk: declared at `:89` and it already returns `WP_Error` (`:95`, `:116`), so one insertion covers both surfaces *and* any third-party or future caller. This is a durable API credential a cookie session can mint and then use to change a password via REST and log in fresh, so it is the pivot the closure exists to seal |
| `core/set-registration-policy` | write of `users_can_register` / `default_role` — enforce a **server-side invariant**: a **code-capable** role (by effective capability, §5.3 — incl. `activate_plugins` / theme-switch, not just `edit/install_*`) can never be the public-registration default; classify by capability, not role name, and cover the omitted-role case in REST user create |

The account-change rows are the direct #20140 deliverable. The code rows (file editor, package install/upload/update, and activation) are the terminal RCE routes a stolen admin session walks to, and gating them at the **shared effect sinks** (rather than per-page or per-capability) is what makes route multiplicity a non-issue: a REST install, a bulk update, the AJAX updater, and a programmatic call all funnel through `install_package()`, and both editors through `wp_edit_theme_plugin_file()`. Themes are gated alongside plugins because installing/switching a malicious theme is an equivalent code-execution path, so gating one but not the other would just relocate the "install a backdoor" bypass. The file editor is **in** the v1 catalog (not deferred as an earlier draft had it): it is the most direct code-write path, and although hardened sites disable it via `DISALLOW_FILE_EDIT`, most do not. The connector-credentials row extends the same proof-of-intent boundary to a credential-**integrity** threat: a `/wp/v2/settings` write swapping an AI-provider API key is a stolen-session abuse of a legitimate operation, reachable with no filesystem access and no code execution (proposal §3). WP Sudo already gates it in production via its `connectors.update_credentials` rule.

#### 4.1.1 Registry vs. Abilities API — the decision (folded from the decision memo)

The one blocking question before the first patch: a new registry, or consequence-metadata layered on the **Abilities API** (which now exists and provides namespacing + execution hooks)? **Resolved (July 2026): ship a standalone consequential-actions registry now** (small, pure-data, backed by the plain core functions the gate already protects), kept **Abilities-*aligned*** (reuse the `namespace/name` ID convention **and** the nested `consequence` block shape) so that if core later grows consequential abilities, the same getters can be extended to read their `consequence` annotations. That union is a documented future extension, not part of the first patch: nothing populates the ability side today. This rejects *both* pure forms:

- **Pure "abilities-only" does not survive contact with the catalog.** The operations the gate must protect (`wp_update_user()`, `wp_insert_user()`, `wp_delete_user()`, `activate_plugin()`, `delete_plugins()`, the upgrader/theme/role mutations) are **not abilities and are not registered in the Abilities API** (as of WP 7.0 it registers three *read-only* abilities: `core/get-site-info`, `core/get-user-info`, `core/get-environment-info`). "Annotate the ability" has nothing to annotate for any catalog member. Making it work would first require abilitizing core's entire write path — a far larger, separately contested project with no consensus or timeline, exactly the entanglement proposal §5–§6 warn against.
- **The one strong argument for abilities, enforcement economy (one execution hook vs. the ~15 chokepoint insertions in §6), fails on a verified fact.** `WP_Ability::execute()` fires `wp_before_execute_ability` via a plain `do_action()` and then calls `$this->do_execute( $input )` on the very next line, discarding whatever the hook returned (verified — see `GB-ABILITY-NOVETO` in [`upstream-sources.md`](upstream-sources.md), which pins the `do_action` line so upstream drift invalidates this claim automatically). So the hook is **observational, not a gate**: a callback cannot return a `WP_Error` or a challenge to stop the ability — only `wp_die()`/throw would, a blunt request-kill rather than the structured challenge-and-replay a reauth gate needs. Abilities buys **no** clean enforcement seam. And the chokepoint model is strictly more complete: it covers a *programmatic* `wp_update_user()` caller, which ability-execution hooks never see.

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
wp_issue_action_proof( string $digest, int $user_id = 0 ): string|WP_Error; // after a challenge; mints a ONE-TIME proof bound to $digest
wp_redeem_action_proof( string $token, string $digest, int $user_id = 0 ): bool; // at the chokepoint; consumes it, or false
wp_end_reauth_proofs( int $user_id = 0 ): void;                            // on logout / credential change / explicit drop
```

Mechanics — each numbered point is a required invariant, not an implementation detail:

1. **Separate proof secret.** On a successful challenge, mint a fresh random secret and set it as a dedicated **httponly** cookie (distinct from the auth cookie), storing only its hash server-side. A session copy that predates the challenge, or a theft that captured the auth cookie but not this proof, cannot present it. (This does *not* stop a session-riding XSS or a full-cookie-jar theft *during* the window; that residual is stated in §2 and is not what the session design defends.)
2. **Keyed per session-token verifier, not per user.** Store the record under the current login session's **verifier** — `hash( 'sha256', wp_get_session_token() )`. Note the distinction: `wp_get_session_token()` returns the *raw* token from the auth cookie, while `WP_Session_Tokens` keys its stored sessions by that token's hash (the verifier). Keying per verifier means a second browser reauthenticating does not overwrite the first's proof. Core supports many concurrent sessions per user; the record must too. (WP Sudo's single per-user proof key is a known limitation — plugin issue #279.)
3. **Self-authenticating (HMAC), binding the proof hash.** `session_tokens` is stored in `user_meta`, a **persistent, poisonable** object-cache group, so an unsigned `reauth_at` is forgeable by a cache-poisoning primitive — exactly the wp2shell class (SQLi used to poison the object cache). Bind the record with `hash_hmac( 'sha256', "$user_id|$verifier|$reauth_at|$scope|$proof_hash", wp_salt( 'auth' ) )`, where `$proof_hash` is the stored `hash('sha256', $proof)` of the browser proof secret, and reject on mismatch. The MAC **must** cover `$proof_hash`: otherwise an attacker who can poison the record keeps its valid signature and swaps in the hash of a cookie they hold, defeating the separate-proof requirement. **A cache-bypassing `$wpdb` read on the enforcement path is REQUIRED, not a complement.** The motivating primitive (wp2shell, §10) is a SQLi *read* used to poison the **object cache** — not a DB-row write — so reading the row directly defeats it **regardless of where the salts live**. The HMAC additionally defends the direct-DB-*write* attacker, who is already out of scope (§2).
   Salt placement is therefore **defense-in-depth, not a precondition**: verified against trunk, when the salt constants are undefined `wp_salt()` reads `get_site_option( "{$scheme}_{$type}" )` and, when empty, generates one with `wp_generate_password( 64, true, true )` and persists it with `update_site_option()` — so on those installs the same SQLi read can obtain the salt and forge the MAC. Surface that state in Site Health; do **not** make the gate's security claim conditional on it, and do not chase a salt-independent binding against a DB-read adversary (any stored key is readable — the scope of the claim is what needs stating, not the key).
4. **Only the challenge handler writes it.** Core must strip reserved `reauth_*` keys from the `attach_session_information` filter result (that filter sets the *base* session array on every `WP_Session_Tokens::create()`, so any registered callback could otherwise pre-elevate every new session), and the HMAC must cover the verifier so an injected stamp cannot validate.
4b. **The session store is pluggable, so the proof record must not assume it (#328).** `WP_Session_Tokens::get_instance()` resolves its class through the `session_token_manager` filter (`class-wp-session-tokens.php:58`, `:222`, verified), and Redis/Memcached/DB-backed managers are free to persist only the fields they know about. If a manager silently drops the proof record, `wp_redeem_action_proof()` never succeeds and — under default-on fail-closed — **every gated action becomes an unsatisfiable loop**: the user is challenged, passes, and is challenged again, with no in-product escape. That is a worse outcome than the gap the gate closes.
   Required: **detect the capability rather than assume it.** Write a probe record through the active manager and read it back at activation (and in Site Health); if the round-trip fails, enter a **documented degraded mode that is not fail-closed** — log, surface the condition prominently, and allow — or use a dedicated store instead of `WP_Session_Tokens` (§11-Q2). A security control that cannot be satisfied is not a control; it is an outage, and it will be removed by whoever is on call.

5. **Redemption consults the session store, not a cookie string.** `wp_redeem_action_proof()` reads the pending proof record from the store and checks the presented proof hash, the HMAC, the bound digest, and single-use state. Because it reads the store, `destroy_all()` revokes outstanding proofs within the same request (an improvement over the plugin's cookie-string bind, which lags one request — issue #279).
6. **A fresh `login` timestamp is not proof.** `wp_signon( '', '' )` mints a new session token with a current `login` stamp from a *held cookie* with no credential entered (and SSO / magic-link plugins call `wp_set_auth_cookie()` with no password). So a "forced-login" variant that infers freshness from the new session's `login` time is the rejected shared-token approach in another form. Freshness must come from the explicit, challenge-written record above.
7. **Teardown on credential change clears only `reauth_*` for the target user — never `destroy_all()`.** A password change (`after_password_reset`, and the password path of `wp_update_user()` / `wp_set_password()`, which leave the token record intact) must end the window. Clear the `reauth_*` keys for the affected user's sessions; do **not** call `destroy_all()` (that logs the user out of the very request that changed the password), and do **not** hook `profile_update` blindly — it fires when an admin edits *another* user and on non-password writes (WP Sudo guards this by comparing the password hash; core must too).

- **Multisite: the window's reach follows the auth cookie's domain, not the network.** The record lives with the *current login session*, so it travels exactly as far as the auth cookie that presents that token: shared across a subdirectory network (one cookie domain), isolated per domain on subdomain/mapped-domain networks. Deterministic per-site scoping regardless of cookie domain is deferred to the `consequence.scope` tag and Phase 2 policy (§8).
- **No reusable window for in-scope actions.** A proof is minted for **one action digest**, is single-use, and is discarded on redemption. There is no `wp_has_recent_auth()`-style freshness query on the consequential path, so no benign action can authorise a consequential one, and a forged or stolen proof authorises **one** operation for seconds rather than every catalog entry for fifteen minutes.
- **A short grace (≈2 min) applies to the SAME digest only**, so a multi-step form is not re-challenged mid-flow. It is not cross-action reuse.
- **Why the window was dropped (this reverses an earlier recommendation).** The minimal closure (§3) *is* {code effects, credential/principal pivots, critical settings} — there is no low-consequence tier inside it. Applying #315 (a per-action proof-bound token, no reuse) and #308 (code-execution and credential classes must not be satisfied by a benign challenge) to that set leaves **no in-scope action that benefits from window reuse**: every one needs its own challenge anyway. A "load-bearing" 15-minute window that is load-bearing only for actions the proposal declines to gate is machinery without a job — and it is the single largest attack surface in the design (cross-authorisation, forgery blast radius, teardown races). Shipping per-action step-up closes #315 and #308 by construction and shrinks #310 and #319 to one action's worth of exposure. The cost is UX, addressed in §5.1 and §8.
- **Lockout is a remediation hazard, not just a rate limit.** Port the plugin's failure model (progressive delay → 5 failures ⇒ 300s lockout, per-user and per-IP), but with two corrections from review: (a) an in-origin XSS from the victim's own IP can burn failed attempts to lock the admin out of the *very actions needed to remediate* (change password, deactivate a plugin), so a lockout must be **clearable out-of-band** (a `WP_DISABLE_ACTION_GATE`-style escape / recovery path — plugin issue #280); and (b) prefer escalating delay over a terminal block for the *action-gate* decision (distinct from the auth-attempt decision).

Open question **closed**: scope-bound vs. flat freshness no longer applies — v1 has no reusable window to scope (§11-Q3).

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

> **Naming (open, see §11-Q1 / proposal §8.2).** The error code `sudo_reauth_required` (and the §5.2 surface responses) inherit WP Sudo's *"sudo"* brand. The rest of this API is core-neutral (`wp_check_action_gate`, `wp_register_action`, `wp_issue_action_proof`), so this is a deliberate but unsettled choice. Decide `sudo_reauth_required` vs a neutral `reauth_required` / `recent_auth_required` as part of the public-name resolution before the patch, and apply it consistently across the error code, the `wp-login.php?action=reauth` slug, and the REST `code`.

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
- One insertion *reaches* admin UI, REST, CLI, and programmatic writes — every caller funnels through the same seam, which is what makes the defense effect-deep rather than form-deep (the #20140 lesson made mechanical). What the seam **reaches** is not what v1 **enforces**: v1 evaluates only interactive cookie sessions (§1), so CLI and programmatic writes pass through ungated. The seam placement is what makes extending enforcement to them later a policy change rather than a re-plumbing.
- Self vs. other, password vs. email vs. role are all just fields in `$changed`, mapped to distinct action IDs by `wp_map_user_changes_to_actions()`.

The interactive layer stays thin. `edit_user()` in `wp-admin/includes/user.php` inspects the returned `WP_Error`; if code is `sudo_reauth_required`, it **stashes** the submitted (allowlisted, secrets-redacted) POST and redirects to the challenge, which **replays** on success. That stash/replay is exactly `class-request-stash.php` + `class-challenge.php` and should port near-verbatim (GET ⇒ redirect, POST ⇒ self-submitting form; per-field allowlist; sensitive-suffix redaction; 5-min TTL; ≤5 stashes/user). **No consequential action auto-replays.** Every catalog action completes as **reauth → confirm → redeem**: the challenge mints a proof bound to the exact action digest, the canonical confirmation page (§7) shows the action and its target, and the user's deliberate confirmation POSTs the one-time token, which the chokepoint redeems. This replaces the earlier split in which non-account actions "replay normally" — that split is **withdrawn**. A stash keyed to the user alone is releasable by any session of that user, so a copied session can plant an action and the victim's reauth executes it (#315); the plugin's own shipping instance of that bug is issue #322. A password's secret *is* the mutation, and auto-executing an email/role/create pivot the instant after reauth is the silent principal-manufacture step the gate exists to make deliberate — the same argument now simply applies to code effects too.

**Bulk operations are ONE action, not N.** A bulk plugin update or multi-select deactivate computes a single digest over the whole batch and takes one challenge and one confirmation. Without this rule per-action step-up would make routine maintenance a per-item re-do, which core review would rightly reject; with it, the common maintenance path costs exactly one prompt. The request stash survives only as the mechanism that carries the *pending* request across the challenge — it never executes it.

### 5.2 Surface adapters

| Surface | Sees | Renders challenge as |
|---|---|---|
| Admin UI (`edit_user`, profile update, plugin actions) | `WP_Error sudo_reauth_required` | Stash request → `wp_safe_redirect()` to `wp-login.php?action=reauth` interstitial → replay |
| Cookie-authed REST | same `WP_Error` from controller | HTTP **403** with `code: sudo_reauth_required`, `data.challenge_url`, `data.expires_in`, `data.digest`. **The client re-submits with the redeemed token** (`X-WP-Action-Proof` header or a `_action_proof` body field) once the user completes the browser challenge and confirmation for that digest. Without this redemption path a block-editor plugin install (`POST /wp/v2/plugins`) is not merely challenged but unsatisfiable from the client, which is a functional regression rather than a security control |
| App-Password REST / XML-RPC (API credential) | *not evaluated in v1* | **Out of v1 scope** — passes through ungated ([#306](https://github.com/dknauss/Sudo/issues/306), deferred by [#320](https://github.com/dknauss/Sudo/issues/320)) |
| wp-cron / WP-CLI / programmatic | *not evaluated in v1* | **Out of v1 scope** — passes through ungated ([#307](https://github.com/dknauss/Sudo/issues/307) and the deferred CLI/cron policy) |

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
  **It does not cover core update.** `Core_Upgrader::upgrade()` reaches `update_core()` directly — `install_package(` never appears in `class-core-upgrader.php` (verified) — so "gate the shared package sink" is true of plugin, theme and language packages and false of core. `core/update-core` therefore takes its own insertion at `Core_Upgrader::upgrade()`/`update_core()`; treating the sink as universal is exactly how the most powerful write in core came to be the one the catalog missed (#302).
- **`wp_edit_theme_plugin_file()`** — the single write sink for both the plugin and theme file editors, including the `wp_ajax_edit_theme_plugin_file()` path (`core/edit-plugin-file` / `-theme-file`). Gate the sink *and* challenge early at editor-open so a user does not write a draft only to discover the requirement at save.
- **`activate_plugin()`** (`core/activate-plugin`) — reachable directly via REST `update_item` on an already-installed plugin; success returns **`null`**, `WP_Error` on failure, so adapters key on `is_wp_error()`, not a truthy return.

`delete_plugins()`, `delete_theme()` (`bool|null|WP_Error` — its callers test `is_wp_error()`), and the remaining code effects take the same top-of-function guard returning `WP_Error`; the bulk/AJAX surfaces already thread `WP_Error`, so the interactive adapter stashes and challenges as above. Two chokepoints do not fit the `WP_Error` return path (the §5.1 per-chokepoint rule): `switch_theme()` returns **void** and performs several option writes, so it needs an **in-function pre-check — a core patch**, not a hook-based adapter (§6 row 15, §6.1); and the multipart ZIP upload cannot be stashed/replayed — reauthenticate before file selection where possible, otherwise require explicit reselection (§8).

**Non-interactive callers of these sinks are out of v1 scope entirely (§1) and pass through ungated** — core's own automatic updater drawing from the site's configured update source must keep running, or the gate is a net security regression by stopping background security updates. (The actor-class policy sketched in §9 is the follow-on project's design, not v1 behaviour.) This is the second half of closing the "admin can just install a backdoor" objection that stalled #20140 for a decade — now covering themes and the file editor, not only plugin install.

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

**Gate the option write itself** for these keys, via `pre_update_option_{$option}`. §6.3 records
why that mechanism is adequate *here* and not for `switch_theme()`: it is adequate exactly
when the option write **is** the whole effect, which is the case for every row above — the
attacker's goal is the stored value, and nothing else needs to happen. The known limitation
still applies (a refusal is reported as `false`, indistinguishable from "no change needed"),
so the gate must not infer success from the return.

Alternatively, **explicitly bracket** any of these as out of scope — but bracket them in §5
by name, with the reason. What is not acceptable is the current silence, which reads as
coverage the design does not have.

## 6. Concrete core change list

| # | File | Function / hook | Change |
|---|---|---|---|
| 1 | `wp-includes/actions-api.php` (new) | `wp_register_action`, `wp_get_action(s)`, `wp_action_exists` | Registry API (§4.1) |
| 2 | `wp-includes/class-wp-consequential-actions.php` (new) | registry storage | Pure-data store + core catalog registered **at file load, not `init`** — fail-closed gating plus `init`-time registration would brick any chokepoint reached before `init` (install/import `wp_insert_user`, a `plugins_loaded`-time `wp_update_user`, CLI paths) |
| 3 | `wp-includes/user.php` | `wp_update_user()` | Detect consequential field changes; gate; return `WP_Error` (§5.1) |
| 4 | `wp-includes/user.php` | `wp_insert_user()` | Gate `core/create-user` for authenticated privileged-context inserts only — **not** anonymous registration/guest-checkout (actor 0 → fail-closed) and **not during `wp_installing()`** (the first admin is created before any actor/window exists — the guard must exempt install or it bricks setup); needs a reliable admin-context signal, see §8 |
| 4d | `wp-includes/class-wp-application-passwords.php` | **`WP_Application_Passwords::create_new_application_password()`** (`:89`; returns `WP_Error` at `:95`/`:116` — verified) | Gate the **shared sink**, not the two surfaces. Enumerating the REST controller and `authorize-application.php` was the same mistake §3 decision 1 rejects: it covers the routes someone thought of and misses the next one. The sink already returns `WP_Error`, so no core patch is needed — this is the §6.3 veto audit's row 4d, previously deferred (#326) |
| 4e | `wp-admin/options.php` / `options-general.php` + REST settings + `wp-includes/user.php` (`default_role` read) | `users_can_register` / `default_role` write | Enforce the `core/set-registration-policy` invariant (§4.1): reject a code-capable default role, by effective capability |
| 5 | `wp-admin/includes/user.php` | `wp_delete_user()` (**bool** return; `do_action( 'delete_user' )` at `:381`, `'deleted_user'` at `:464`, no `pre_delete_user` filter — verified) | **Core patch required.** No existing hook can veto: `delete_user` is observational. Either change the return contract to `bool\|WP_Error` (and update `WP_REST_Users_Controller::delete_item()`, whose `if ( ! $result )` would read a `WP_Error` as success) or add a short-circuiting `pre_delete_user` filter. Per-surface gating (REST permission callback + admin handler) is a **fallback that leaves programmatic and WP-CLI callers ungated** — a documented residual, not chokepoint coverage |
| 6 | `wp-includes/class-wp-user.php` / `wp-includes/meta.php` | `WP_User::set_role`/`add_role` and **`update_user_metadata` on the `{prefix}capabilities` key** — **not `map_meta_cap`** (#309) | Escalation guard (§5.3). The meta hook is what carries it: `update_{$meta_type}_metadata` is a genuine short-circuit filter (§6.3), while `WP_User::set_role()` returns `void` and cannot be vetoed at the function. `map_meta_cap` enforcement is **withdrawn** — it makes `current_user_can()` false, so affordances vanish instead of challenging, it recurses via `user_can()`, and it runs on ordinary requests |
| 7 | `wp-includes/ms-functions.php` (`add_user_to_blog`) · `wp-includes/capabilities.php` (`grant_super_admin`) | `add_user_to_blog`: **`can_add_user_to_blog` is a real veto filter** — `ms-functions.php:183` applies it and `if ( true !== $can_add_user ) { … return $can_add_user; }` hands the `WP_Error` back to the caller (verified). `grant_super_admin`: writes `update_site_option( 'site_admins', … )` and **discards the return**, then fires `do_action( 'granted_super_admin' )` and `return true` (verified) | **Split verdict.** `add_user_to_blog` is gateable **today, no core patch** — return a `WP_Error` from `can_add_user_to_blog`. `grant_super_admin` **needs a core patch**: a `pre_update_site_option_site_admins` veto is silently ignored — the write's failure is not checked, so `granted_super_admin` still fires and the function still returns `true`, reporting a **false success**. It also returns `false` early when `$GLOBALS['super_admins']` is defined or the install is single-site, so gating there is moot in that configuration |
| 8 | `wp-includes/user.php` | `wp_start/has/end_reauth_window` | Per-session, HMAC-signed, separate-proof recent-auth record (§4.2): mint a proof cookie, key per session-verifier, sign with `wp_salt('auth')`, read from the store; **no token rotation** |
| 9 | `wp-includes/class-wp-session-tokens.php` | token record schema + `attach_session_information` | Persist the signed `reauth_*` record keyed per verifier; **strip reserved `reauth_*` keys from the `attach_session_information` filter result** so only the challenge handler can write them; clear on destroy / credential change (not `destroy_all`) |
| 10 | `wp-includes/gate.php` (new) | `wp_check_action_gate()` + decision class | Gate evaluation (§4.3) |
| 11 | `wp-admin/includes/user.php` | `edit_user()` | Catch `sudo_reauth_required` → stash + redirect (§5.1) |
| 12 | `wp-login.php` | new `action=reauth` | Challenge interstitial: password (+2FA hook), rate-limit, replay |
| 13 | `wp-includes/rest-api/endpoints/class-wp-rest-users-controller.php` | update/create/delete | Surface `sudo_reauth_required` as 403 + challenge metadata |
| 14 | `wp-admin/includes/class-wp-upgrader.php` | **`WP_Upgrader::install_package()`** | Gate the shared package-write sink (§5.4) — one insertion covering plugin/theme install, ZIP upload, single + bulk update, and language packs, on every surface; **not** `Plugin_Upgrader::install()` |
| 14b | `wp-admin/includes/file.php` | **`wp_edit_theme_plugin_file()`** | Gate the shared plugin/theme file-editor write sink (both editors + the AJAX path), plus an editor-open early challenge (§5.4) |
| 14c | `wp-admin/includes/plugin.php` | `activate_plugin` (`null`\|`WP_Error`), `delete_plugins` | Gate activation (REST `update_item` reaches it) and delete (§5.4) |
| 15 | `wp-includes/theme.php` · `wp-admin/includes/theme.php` | `delete_theme()` (`bool\|null\|WP_Error`), `switch_theme()` (**void**) | `delete_theme` takes the early `WP_Error` guard (callers check `is_wp_error()`). `switch_theme` **needs a core patch** — vetoing `pre_update_option_template`/`_stylesheet` is **not sufficient**: verified against trunk, `switch_theme()` also writes `theme_switch_menu_locations`, `template_root`/`stylesheet_root` (or deletes them), `current_theme`, and mutates sidebar-widget state, then fires `switch_theme`/`after_switch_theme`. Vetoing two of those writes leaves a **half-switched site** that reports the new theme while running the old one, with no error surfaced (the function returns `void`). Theme *install/update* is covered by row 14's shared sink |
| 16 | `wp-includes/request-stash.php` (new) | stash/replay | Port `class-request-stash.php` (allowlist, redaction, TTL, per-user cap) |
| 17 | Site Health | new async test | Report registered actions + whether gating is enabled |

Rows 1–2 (the registry) are independently shippable; the gate (rows 3–16) enforces at the chokepoint whether or not the registry has landed. Row 17 is a registry consumer that demonstrates value before any enforcement exists.

---

### 6.1 What this costs in core patches — stated plainly

Of the five contested seams, **one is gateable with an existing hook and four are core changes.** A proposal that implies otherwise is not landable as written, so the ask is itemised here rather than left to the reader:

| Seam | Ask |
|---|---|
| `add_user_to_blog` | **None.** Return a `WP_Error` from the existing `can_add_user_to_blog` filter (`ms-functions.php:183`). |
| `grant_super_admin` | Check the `update_site_option()` result (or add a `pre_grant_super_admin` short-circuit) so a refused write stops `granted_super_admin` firing and stops the function returning `true`. |
| `wp_delete_user` | A `bool\|WP_Error` return contract, or a short-circuiting `pre_delete_user` filter. Callers testing `if ( ! $result )` must be updated with it. |
| `switch_theme` | An in-function pre-check. Option-level vetoes cannot work here — the function performs several writes and returns `void`. |
| self-email (admin path) | A short-circuit in `edit_user()` before `send_confirmation_on_profile_email()`. |

Everything else in §6 already returns `WP_Error` and needs no contract change.

### 6.3 Veto audit — every chokepoint, checked rather than assumed (#358)

§6.1 covers the five seams a reviewer challenged. This is the sweep across the rest, because the two failures found there — a discarded write-return reported as success, and an option veto that left the remaining writes applied — show that a plausible-looking return contract is not evidence. Each row below was checked against `wordpress-develop` trunk.

| Row | Seam | Can it refuse? | Evidence |
|---|---|---|---|
| 3 | `wp_update_user()` | **Yes** | Calls `wp_insert_user()` and returns early on `is_wp_error()`, so a `WP_Error` propagates to every caller. |
| 4 | `wp_insert_user()` | **Yes** | Returns `WP_Error` on eleven distinct paths; callers already branch on it. |
| 4e | `users_can_register` / `default_role` writes | **Yes, with a caveat** | `pre_update_option_{$option}` (`option.php:901`) — returning the old value trips the `$value === $old_value` check and `update_option()` returns `false` without writing. **Caveat:** `false` is indistinguishable from "no change needed", so the caller cannot tell a refusal from a no-op. Sufficient here only because the option write *is* the whole effect — the opposite of `switch_theme`, where the same technique leaves later writes applied (§6.1). |
| 6 | capability escalation | **Yes — via the meta hook, not the method** | `WP_User::set_role()` returns `void` and only fires `do_action`s, so it cannot be vetoed at the function. The row is sound because it targets the `{prefix}capabilities` write: `update_{$meta_type}_metadata` is a genuine short-circuit filter (`meta.php:251`, `if ( null !== $check )`). Keep the guard on the meta write; do not move it to `set_role()`. |
| 14 | `WP_Upgrader::install_package()` | **Yes** | Six `WP_Error` returns; the upgrader's callers already thread them. |
| 14b | `wp_edit_theme_plugin_file()` | **Yes** | Twenty-one `WP_Error` returns — the most defensively written sink in the set. |
| 14c | `activate_plugin()`, `delete_plugins()` | **Yes** | Four `WP_Error` returns each. |
| 15 | `delete_theme()` | **Yes** | Returns `WP_Error`; callers test `is_wp_error()`. (`switch_theme()` in the same row cannot — §6.1.) |

**Result:** no further blockers. The core-patch tally in §6.1 stands at four, and every other chokepoint can genuinely refuse. Two properties are now recorded rather than assumed: an option-level veto is only adequate where the option write is the entire effect, and row 6's enforcement lives on the meta write because the role setter returns `void`.

**Row 4d is now covered** (#326): issuance moved to the shared sink `WP_Application_Passwords::create_new_application_password()`, which is declared at `:89` and already returns `WP_Error` at `:95`/`:116` — **yes, it can refuse, and no core patch is needed**. That completes the sweep: every row in §6 has been checked.

### 6.2 No-actor and self-heal carve-outs — required for every veto seam

A gate that fails closed on a path with **no actor to challenge** does not protect that path; it breaks it. Every seam added here inherits this rule, which is stated once:

- **Self-heal.** `validate_current_theme()` (`theme.php:898`) calls `switch_theme( WP_DEFAULT_THEME )` at `:927` with no actor, guarded only by `wp_installing()` and the `validate_current_theme` filter. A fail-closed theme gate without a carve-out leaves a site whose theme directory vanished unable to recover through any in-product route.
- **Multisite lifecycle.** `get_active_blog_for_user()` calls `add_user_to_blog()` on the login/redirect path; `add_existing_user_to_blog()` runs from an invitation; `wpmu_activate_signup()` creates users as actor 0. Gating these fails ordinary login and signup.
- **Install and recovery.** `wp_installing()` (already carved out for `core/create-user`, §8) and recovery mode.
- **The key-verified password reset.** `reset_password()` (`wp-includes/user.php:3511`) calls `wp_set_password()` at `:3526` on the **actor-0** lost-password completion path, where the emailed reset key *is* the proof and no session exists. Gating `wp_set_password()` without carving this out refuses every legitimate reset site-wide under default-on fail-closed — the single most damaging false positive in the design, because it locks out exactly the users least able to route around it. Gate the **authenticated** password changes (`core/change-own-password`, `core/change-user-password`, which run through `wp_update_user()`); exempt the key-verified reset the same way `wp_installing()` is exempted.

The rule: a gate applies when there is an **authenticated actor whose intent can be proven**. With no actor, the correct behaviour is to allow and — where it matters — log, never to block. This is the same boundary §9 draws for non-interactive callers, applied to core's own internal calls.

## 7. Challenge model

Phase 2 baseline (proposal §4, §11) — start small:

- Browser-first interstitial at `wp-login.php?action=reauth`, in the existing authenticated context.
- Password verification against the current user **where a usable password is the account's actual factor**; if a 2FA plugin is present, expose a `wp_reauth_second_factor` hook so it can add **and validate** its factor (the plugin integrates the Two-Factor plugin exactly this way). Core ships no 2FA of its own.
- **A password challenge cannot be mandatory (#305).** SSO-only, passkey-first and magic-link accounts have no password the user knows — often a random string set at provisioning — so a password-plus-optional-factor design does not inconvenience them, it **locks them out of every gated action permanently**, for the entire admin population of such an organisation, under default-on. That contradicts the proposal's own framing that the gate *consumes existing authenticated identity* rather than introducing a new one.
  So the contract is **at least one provider-verified factor**, not "password, plus extras": a registered provider may **satisfy** the challenge in place of the password, not merely add to it. Where a password is genuinely the account's factor, it remains required, and where both exist both are required (the "both factors passed" guarantee below is unchanged for those accounts).
  **Do not infer this from the stored hash.** Every WordPress account has one, including SSO accounts, so `user_pass` emptiness is not a signal — the provider must declare that it owns authentication for this user. Getting that backwards fails in the dangerous direction: silently accepting a factor-only challenge for a password account.
  State the consequence honestly: for an SSO-only account the gate's guarantee becomes *fresh proof from the factor that actually authenticates this user*, not *knowledge of a WordPress password*. That is the correct guarantee — the password was never the security boundary for those accounts — but it should be written down rather than discovered.
- **The second-factor hook must report an explicit result, not merely render fields.** For the "both factors passed" guarantee below to be implementable, the hook's contract yields one of *pass* / *fail* / *pending* (e.g. a filter returning `true` / `WP_Error` / a pending sentinel); the challenge handler treats a missing or non-affirmative result as **not passed**. The exact signature is a Phase-2 detail; the load-bearing invariant is that *rendering a field ≠ validating it*.
- On success — the password **and** an affirmative *pass* from any factor registered via `wp_reauth_second_factor` — call `wp_issue_action_proof( $digest )` and render the confirmation page below. No proof is minted on the password step alone when a second factor is present, and **no action executes at this point**.
- Nonce-protected, rate-limited, lockout on repeated failure.

### 7.1 The confirmation page is a security control, not chrome

With no reusable window, the one-time token is what authorises the action — and a token bound to a digest only proves *this* approval matches *this* request. It cannot prove the request was the user's idea: the pending action may have been planted by another session of the same user before the challenge (#315, and the shipping plugin instance #322). The confirmation screen is therefore the boundary where a human distinguishes their own action from someone else's, and it needs a normative contract rather than a design suggestion:

- **Render every gated field.** The action id, a human label, the target, and **all** parameters the gate evaluated. A screen that says "Confirm plugin activation" without naming the plugin is not a confirmation.
- **No attacker-controlled chrome.** Labels, targets and URLs come from the registry and from server-side state, never from request input rendered as trusted text. Untrusted values are displayed as data, escaped and clearly delimited.
- **No auto-submit, no auto-focus on the confirm control**, and no `Refresh`/`onload` path that completes the action without a deliberate act.
- **The token binds to the digest of exactly what was displayed.** If the rendered parameters and the redeemed request disagree, the redemption fails.
- **Distinguish initiation from approval.** The prompt-initiation nonce is *not* a security boundary here — a session clone mints valid nonces (proposal §2). The human's confirmation of the displayed action is the boundary, which is why the content rules above are normative.

**Residual, stated plainly:** this does not defend against in-origin XSS. A script in an authenticated admin origin can drive the confirmation page and POST the token without a human, and the httponly proof cookie is attached automatically. That is the residual §2 already records for a script running in the authed origin; per-action step-up narrows the *ambient-bearer / copied-session* case, not this one, and the two must not be conflated.

Explicitly deferred: WebAuthn ceremonies, external IdP redirects, multi-step TOTP/recovery flows, async/pending challenges, consent overlays.

---

## 8. Defaults, config, back-compat

- **Default state.** Ship Phase 1 registry **always on** (inert; naming only). Ship Phase 2 gating **on for the core catalog by default**, because a security default that must be discovered protects almost no one. The UX cost is **one challenge plus one confirmation click per consequential action**, with bulk operations batched to a single digest (§5.1) so routine maintenance stays at one prompt. Nothing auto-replays. Provide `WP_DISABLE_ACTION_GATE` for emergencies and a per-action `wp_action_gate_enabled` filter. **Exception — `core/create-user`:** default-on gating would fail-closed on (a) unauthenticated self-registration / guest checkout (actor 0, no window possible) **and (b) WordPress installation itself** — the first administrator is created via `wp_insert_user()` before any actor or recent-auth proof can exist, so the file-load fail-closed guard must **exempt `wp_installing()`** or it bricks install. Gate `create-user` only for *authenticated, privileged-context* inserts — never the anonymous registration path and never during install (`wp_installing()`) — or default it off. This needs a reliable admin-context signal (`is_admin()` is not one; scope by an authenticated actor holding `create_users` via a non-registration entry point). Open: §11-Q4/Q5.
- **Config surface.** `WP_REAUTH_WINDOW` (ttl), `wp_reauth_window_ttl` / `wp_action_gate_enabled` / `wp_consequential_actions` (catalog) filters. Keep the plugin's `Disabled/Limited/Unrestricted` per-surface policy vocabulary **out** of core v1 (proposal §11-Q5); core v1 is binary per action.
- **Back-compat.** Because enforcement returns existing `WP_Error` types from functions that already return them, non-updated callers degrade safely to "action refused with an actionable error," never a fatal or a silent pass. In v1 programmatic callers need no bypass at all — they are out of scope and never evaluated (§1). The escape hatch matters only once the follow-on project extends enforcement to them: at that point migrations and trusted automation under WP-CLI/cron (which have **no auth cookie or session token**, so `wp_issue_action_proof()` — a browser-session API — cannot help them) short-circuit via the `wp_action_gate_enabled` filter or a scoped constant, **not** the session-window API.
- **Multisite terminology** (#37593/#39174): "network administrator" for ordinary network authority, "super admin" only for core's technical concept, "sudo mode" for the temporary window. No permanent role is introduced.

---

### 8.1 Fail-closed recovery — the triggers need hardening (#329)

Fail-closed gating is only safe if the escapes are reachable and the carve-outs cannot be
turned on by an attacker. Three of them do not currently hold up.

- **`wp_installing()` is a mutable runtime switch, not a constant.** Verified: it holds a
  `static $installing` seeded from `WP_INSTALLING` but reassignable by any caller passing
  an argument (`load.php:1634`), and core itself flips it `true` during DB-upgrade
  routines. So the §6.2 install carve-out must **not** rest on `wp_installing()` alone —
  anything running in-process can set it and walk through the exemption. Pair it with the
  actor test that carve-out actually means (**no authenticated actor**), and treat
  `wp_installing()` as a hint about context, never as authorisation.
- **The catalog filter must union with the built-ins, not replace them.** A
  `wp_consequential_actions` callback returning a reduced array silently disables gating
  for everything it omitted; returning an empty array disables the gate site-wide while CI
  and Site Health still report it enabled. Core must re-add any missing built-in entry
  after filtering. This is not hypothetical — WP Sudo ships exactly this guard in
  `normalize_filtered_rules()` for the same filter, after the same reasoning.
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

> **v1 scope (normative, per §1 and [#320](https://github.com/dknauss/Sudo/issues/320)):** everything in this section except the first table row is **deferred out of v1**. The actor-class *dispatch* is part of the v1 architecture; the non-interactive *policies* below are the design intent for the follow-on provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4)), recorded here so the seam is built to accommodate them. In v1, any actor class other than an interactive cookie session passes through **ungated**.

The gate's *decision* ("may this effect proceed now?") is transport-agnostic; only the *rendering* of a challenge is interactive (§3.6). Excluding non-interactive routes from **enforcement** because they cannot render a **challenge** is a category error that leaves the modern scriptable routes open (REST install/activate is reachable with a stolen cookie plus a harvested `wp_rest` nonce). That reasoning stands — it is the reason the follow-on project exists — but #320 decided the v1 cut ships before it, because a default hard-block of API credentials is a back-compat regression (#306) and the auto-updater ALLOW branch needs a provenance primitive core lacks (#307).

| Actor at the sink | Decision | In v1? |
|---|---|---|
| Interactive cookie session, no window | **Challenge** (full-page, §7) | ✅ **Yes — the v1 scope** |
| API credential (Application Password) / XML-RPC, no window | **Block + log** — no interactive reauth is possible over a credential channel | ❌ Deferred ([#306](https://github.com/dknauss/Sudo/issues/306)) |
| No actor, **and** core's own automatic updater, **and** package from the site's configured update source | **Allow** — background security updates must keep working; a blanket block here is a net security regression | ❌ Deferred ([#307](https://github.com/dknauss/Sudo/issues/307)) |
| WP-CLI | **Allow by default, operator-configurable** — shell access already dominates the gate (anyone who can `wp plugin install` can `cp` a PHP file into `WP_PLUGIN_DIR`), so a CLI block buys nothing against that attacker and costs every deployment pipeline | ❌ Deferred |

What v1 additionally defers, even within the interactive class, is the **per-surface policy UI** and any attempt to render an interactive challenge on a non-interactive surface.

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
7. Non-interactive callers (API credential, WP-CLI, cron, programmatic) behave **exactly as they do in current core** — v1 neither challenges nor blocks them (§1, §9, [#320](https://github.com/dknauss/Sudo/issues/320)). What must be proven is the converse: **no interactive-class request is ever misclassified as non-interactive** and thereby passed without a window. Test the misclassification boundary specifically — a cookie-authenticated REST call must not be resolved as an API-credential caller.
8. With gating disabled, behavior is byte-for-byte current core.
9. Application-Password issuance is gated at the **shared sink** `WP_Application_Passwords::create_new_application_password()`, so a cookie session cannot mint a durable API credential without a fresh challenge **by any route** — the REST controller, the `authorize-application.php` no-JS approve flow, or a caller neither of those enumerates. Asserting the two known surfaces is not sufficient evidence for this criterion; the test must reach the sink.
10. `users_can_register` / `default_role` **cannot** be set to a code-capable role (by effective capability, including `activate_plugins` / theme-switch) — enforced server-side, including via REST and the omitted-role case, not just hidden in the UI.
11. **WordPress installation and CLI setup succeed**: the first administrator is created during `wp_installing()` with no gate, and no pre-`init` chokepoint is bricked by the file-load fail-closed catalog.

A Playground blueprint reproducing a stolen-session takeover and showing where the challenge lands (as in `dknauss/consequential-actions`) should accompany the patch.

---

## 11. Open questions (for core review)

1. Registry-in-core vs. consequence-metadata layered on the **Abilities API** (which now exists and already provides namespacing + execution hooks). The lighter landing may be to *not* build a second registry — annotate abilities instead. Strongest fresh argument since the old #20140 comments; worth settling first. **Resolved (July 2026):** a **standalone** consequence-actions registry now, Abilities-aligned in its ID convention, with reading consequence-annotated abilities left as a deferred extension (nothing populates the ability side yet). Not "abilities-only," and not a registry needlessly incompatible with Abilities. The decision is folded into §4.1.1 above (full memo archived at [`archive/core-actions-registry-vs-abilities-decision.md`](archive/core-actions-registry-vs-abilities-decision.md)). The public name for the API remains open (proposal §8.2).
2. `WP_Session_Tokens` extension vs. a dedicated store (proposal §11-Q2).
3. ~~Flat recent-auth freshness vs. scope-bound windows for v1.~~ **Closed by §4.2's move to per-action step-up:** with no reusable window there is nothing to scope, and the multisite cross-site-reach problem disappears with it (a proof authorises one action on one site). Re-opens only if a reusable window is ever reintroduced, in which case the signed scope must be a compound, versioned key (`v1|{site_id}|{class}`) so the site dimension is not spent on consequence-class grouping.
4. Should `core/create-user` gate *all* inserts or only privileged-context ones (registration/import would otherwise trip it)?
5. Default-on vs. default-off for Phase 2 gating (§8) — the single biggest adoption/impact tradeoff.

---

## 12. Reviewer findings / known gaps (design review, July 2026)

Adversarial design reviews — two-model (Fable + Opus), then a **Codex** pass — of this spec and the proposal. They **validated the problem framing and identified a candidate mechanism** (gate-at-the-sink, per-session HMAC separate-proof, actor-class branch, fail-closed) — **not a ready-to-land security design.** Several open items are **P1**, so the closure claim should not be presented as settled while they stand: some are mechanical, but the ambient-bearer / auto-replay problem (#315) and the self-email seam (#316) undercut load-bearing claims until resolved. Tracked as GitHub issues **#302–#320** (high-severity below; #311 medium/low tracker; **#320 CLOSED — the scoping split was decided and is now applied in §1/§3.6/§5.2/§9/§10**). The high-severity gaps:

1. **Core update is ungated (#302).** No `core/update-core` entry; `Core_Upgrader::upgrade()` calls `update_core()` without routing through `install_package()` — the attack-tree already gates it as `core.update`. Add the entry; gate `update_core()` explicitly.
2. **The `do_action()` pre-op adapters cannot block (#303) — resolved in §6 with a verified seam inventory.** Each seam was checked against `wordpress-develop` trunk rather than assumed: `add_user_to_blog` is gateable **today** via the `can_add_user_to_blog` veto filter (no core patch); `grant_super_admin`, `wp_delete_user`, `switch_theme` and the admin self-email path each **require a core patch**, for different reasons (a discarded write-return that reports false success; a `bool` return contract; partial state left by a two-option veto; an observational `do_action`). The earlier suggestion to veto `pre_update_option_template`/`_stylesheet` is **withdrawn** — it leaves a half-switched site. See §6 rows 5/7/15 and the core-patch list in §6.
3. **Gating `wp_set_password()` bricks the unauthenticated reset (#304).** The key-verified `reset_password()` path runs at actor 0; add an explicit carve-out, and state the residual mail-channel trust in §3's invariant.
4. **The mandatory password step locks out SSO/passkey/passwordless accounts (#305).** §7 needs a factor-only / provider-supplied-proof path where no core password exists.
5. **Default-on hard-block of App-Password/automation callers is a back-compat regression (#306).** §9 + §8 default-on silently break headless/CI/deploy/cron ecosystems and silently fail programmatic `WP_Error`-ignoring callers. Reconsider the default or add an opt-in allowance for authenticated non-interactive callers.
6. **The auto-updater ALLOW branch depends on a provenance primitive core lacks (#307).** Define a non-filterable trusted flag (set inside `WP_Automatic_Updater`) or narrow the ALLOW to core-signed packages.
7. **Flat freshness lets a trivial action authorize the most consequential ones (#308).** **Resolved by construction** — §4.2 drops the reusable window entirely, so no challenge can satisfy an action other than the one it was minted for. `consequence.scope` is no longer load-bearing for v1.
8. **`map_meta_cap` enforcement conflates authz with proof-of-intent and invites recursion (#309).** **Resolved:** §5.3 and §6 row 6 now enforce at the `wp_update_user` / caps-meta-write chokepoint only, and the `map_meta_cap` insertion is withdrawn. It bought no coverage the chokepoint lacks, and it broke §1's own boundary — denying the capability hides the affordance instead of challenging for it.
9. **HMAC forgery-resistance is conditional on `AUTH_SALT` placement (#310).** With the salts in `wp_options`, the wp2shell SQLi read (§10) forges the MAC. State salts-in-`wp-config.php` as a hard precondition (§4.2), not a footnote.

A later **Codex** pass added two more high-severity design blockers not covered above:

10. **The proof cookie is an ambient bearer — a pre-window clone can drive the reauthed browser (#315, P1).** Preserving the shared session token (to keep nonces valid) lets a cookie clone harvest valid nonces; with the proof cookie `SameSite=Lax`, an attacker harvests an activation nonce pre-reauth, then navigates the *victim's* browser (top-level GET) to a crafted action URL after the victim reauths — the victim supplies the fresh proof, the attacker the nonce, and the gate passes. The auto-replay stash (keyed per-`user_id` only) is a second vector. **This breaks the "a pre-reauth copied cookie cannot act" claim through the legitimate browser.** **Resolved by construction in §4.2/§5.1/§7.1:** auto-replay is gone for every consequential action, proofs are single-use and bound to one action digest, and the canonical confirmation page carries a normative content contract whose deliberate confirmation POSTs a one-time, proof-bound intent token over the action/target digest — **not** a WP nonce, which a clone can mint. Note what this does and does not close — it removes the ambient-bearer / copied-session vector; it does **not** close in-origin XSS, which can drive the confirmation page itself (§7.1, §2). Those two residuals must not be reported as one.
11. **The self-email gate is on an observational `do_action` (#316, P1).** §4.1 routes `core/change-own-email` to `personal_options_update`, whose return is discarded — it cannot veto the pending-email write, so this Group-B pivot has no working enforcement on the profile surface; and on multisite the confirmation path writes the signup row *before* `wp_update_user()`, leaving partial state. **Resolved in §4.1**, which now names the seam and the cost: `personal_options_update` is a `do_action` (`wp-admin/user-edit.php:149`, verified) and cannot veto, so the admin path takes a **core patch** to `edit_user()` short-circuiting before `send_confirmation_on_profile_email()` (`wp-includes/user.php:3864`); the REST self-update vetoes today via its `permission_callback`; the multisite confirmation endpoint is gated separately.
12. **Proof issuance is not atomic with cookie delivery (#319, medium).** The PoC writes the server proof hash, skips `setcookie()` when `headers_sent()`, ignores its return, and returns `true` regardless — so on a header-already-sent or cookie failure the server invalidates the old proof but the browser gets no new one → an unrecoverable reauth loop. It also covers only `COOKIEPATH`/`ADMIN_COOKIE_PATH` (missing `PLUGINS_COOKIE_PATH`/`SITECOOKIEPATH`). **The fix is ordering, not a return check.** `setcookie()` returns `false` only when headers are already sent; it cannot report the failures that actually matter here — the browser silently dropping the cookie (Secure/SameSite mismatch, ITP, private mode). So: **never invalidate the prior proof until a subsequent request presents the new one** (issue-then-confirm, with a previous-proof slot), rather than trusting the write.
    The cookie policy must mirror the **logged-in** cookie, not the auth cookie: verified against trunk, `wp_get_session_token()` reads `wp_parse_auth_cookie( '', 'logged_in' )`, and `pluggable.php:1195-1197` sets that cookie on `COOKIEPATH` **and** `SITECOOKIEPATH` using a separate `$secure_logged_in_cookie` — while the auth cookie uses `ADMIN_COOKIE_PATH`/`PLUGINS_COOKIE_PATH`. Using `is_ssl()` or the auth-cookie paths produces a proof that exists in wp-admin and vanishes on the front end and on cookie-authenticated REST — the same unrecoverable loop from a different cause.

These interact with §11's open questions (notably Q2 session store, Q3 scope-bound windows, Q5 default-on). The medium/low items — app-password shared sink, the registration invariant being point-in-time, actor-class detection, option-write activation bypass, `WP_Session_Tokens` pluggability, multisite super-admin/network-option, the REST silent-200, and four mechanism gaps — are tracked in #311. **Strategic scoping (#320) — DECIDED, split approved.** The minimal closure's breadth (official installs, updates, language packs, activation, deletion, theme switch, connector credentials, origin settings) drove #302/#306/#307. The approved split: **v1** = recent-auth primitive + provenance-blind gating of the package write on browser/cookie-auth paths + identity pivots; a **separately scoped** provenance/policy project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4)) takes auto-updates ([#307](https://github.com/dknauss/Sudo/issues/307)), API credentials ([#306](https://github.com/dknauss/Sudo/issues/306)), and CLI/cron/generic programmatic-caller policy. **[#302](https://github.com/dknauss/Sudo/issues/302) stays in v1** — the `update_core()` seam is in scope. Applied throughout §1, §2, §3.6, §5.2, §9, §10.

---

*Contributor credits for WP Sudo's design lineage (Blackbourn, Nash, Alkan) live in the plugin readme's Acknowledgements section. Prior art is cited impersonally in the body — principally Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140) (recent auth for consequential actions) and [#16470](https://core.trac.wordpress.org/ticket/16470) (single-site email-change confirmation, whose `send_confirmation_on_profile_email()` flow §4.1 must accommodate).*
