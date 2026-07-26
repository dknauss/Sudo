# Carry-over: what the Codex + Fable reviews mean for Sudo and Consequential Actions

Consolidated from the two design reviews of the core recent-auth-gate pitch (2026-07-25).
Source findings are tagged `[D1-n]` / `[D2-n]` (Fable) and `[Cx]` (Codex). Several
plugin items are **verify-first** (a red-team hypothesis against the plugin, not a
confirmed bug) — check the named code before filing.

---

## A. Consequential Actions (core proposal / spec / PoC)

The pitch, spec §0, and PoC note now carry these; listed here as the complete set.

### Blocking design changes
1. **Session/assurance = per-session-verifier, HMAC-signed, separate proof; no rotation.** `[D1-1..6]`
   - Not the shared-token `reauth_at` stamp (elevates the thief — same token). `[Cx]`
   - Not rotation: `wp_create_nonce()` hashes the session token, so rotation nukes every open-tab nonce and the replayed POST's own; core re-issues against the same token on password change for exactly this reason; `WP_User_Meta_Session_Tokens` has a lost-update race; Remember-Me absolute expiry would be silently reset/downgraded.
   - Record: `hash_hmac('sha256', "$user_id|$verifier|$reauth_at|$scope", wp_salt('auth'))`, keyed to the login-session **verifier** (concurrency), read from the **store**, written **only** by the challenge handler, with reserved `reauth_*` keys stripped from the `attach_session_information` filter. Degrades when salts live in `wp_options` — document it.
2. **Enforce at `WP_Upgrader::install_package()` + `activate_plugin()` + `wp_edit_theme_plugin_file()`.** `[D2-1,2,5,7]` Naming `Plugin_Upgrader::install()` misses bulk/update/AJAX-updaters/auto-updater/language-packs; REST `update_item` reaches `activate_plugin()` directly with `install_plugins`+`activate_plugins`.
3. **Branch on actor class, not transport.** `[D2-3,4]` challenge (interactive cookie) / block+log (API credential) / allow (no-actor core auto-updater from the configured source) / allow-by-default+configurable (WP-CLI — shell dominates the gate; a CLI block is theater). Spec §9's CLI hard-block is the wrong default.
4. **Group B credential pivots are in v1.** `[D2-8]` A code-effects-only cut is bypassable per the closure invariant. Defer only the policy UI and non-interactive challenge *rendering*.
5. **Reconcile the pitch/spec `update` contradiction.** `[D2-2]` Pitch gates update; spec catalog omits it. `install_package()` resolves it.

### Correctness / hardening
6. **Register the catalog at file load, not `init`.** `[D1-11]` Fail-closed + `init` registration bricks pre-`init` chokepoints (install/import `wp_insert_user`, CLI).
7. **Lockout must not become a remediation-DoS.** `[D1-10]` An in-origin XSS can burn 5 bad passwords to hard-block the real admin from *every* gated action (incl. changing the password / deactivating the malicious plugin). Make it out-of-band clearable; prefer escalating delay over a terminal block for the *action* decision.
8. **Teardown binds to a credential change for the target user**, clears only `reauth_*`, never `destroy_all()`. `[D1-9]`
9. **Be honest about XSS.** `[D1-7]` The session design defends cookie-*copy*; what limits session-riding XSS is the challenge + short TTL. Match spec §2's "Partially."
10. **Trac:** cite #20140 only; drop #37593 / #39174 (multisite role terminology). `[Cx, verified]`

### New research items
11. **Language packs — VERIFIED (2026-07-25): seam-validation, not a new attacker route.** `[D2-7]`
    - **`WP_LANG_DIR` is a PHP-execution directory** (confirmed): `WP_Translation_File_PHP` loads `.l10n.php` via `$result = include $this->file;` — arbitrary PHP execution, default on WP 6.5+.
    - **Language-pack installs write there through `WP_Upgrader::install_package()`** with **no cryptographic per-package verification** (the same long-standing property as WP's plugin/theme/core update paths). Correction to an earlier draft: `Language_Pack_Upgrader::check_package()` (read directly, line 325-368) sets its accept flag only for `.l10n.php` files and accepting a `.l10n.php` pack is **correct, by-design** behavior — there is **no** validation flaw. `parse_file()` `include`s the file (`class-wp-translation-file-php.php:24`), confirmed.
    - **But the package source is api.wordpress.org**, populated via `wp_get_translation_updates()` into the translations transient. A stolen-session attacker **cannot** steer it to attacker content without an additional primitive — a source-redirect filter (`http_request_args`/`upgrader_package_options`/`translations_api`) needs code exec, or transient poisoning needs a DB write; both are outside the gate's threat model. There is no core endpoint that installs a language pack from an arbitrary URL.
    - **Net:** (a) **Confirms the enforcement seam belongs at `install_package()`** — language-pack writes flow through it, so it's covered; a plugin/theme-`install()`-only seam misses them. (b) A **latent fragility worth a defense-in-depth note to core** (no signature verification + a PHP-accepting `check_package()` writing to an executed directory), but *not* the gate's responsibility — the gate cannot stop content whose source is trusted. **Not** an unenumerated stolen-session RCE route.
12. **Provenance is not a safe key.** `[D2-6]` `plugins_api()` / `$api->download_link` are filterable; gate on the package write, not "is this a .org slug."

---

## B. WP Sudo plugin (this repo)

The plugin already does several things the core spec got wrong (separate proof token, gating `authorize-application.php` **and** REST app-passwords, gating `default_role`/`users_can_register` via `options.critical`). But the reviews surface real plugin items. **File each as its own issue after verifying.**

### Hardening
1. **Assurance is forgeable via object-cache poisoning.** `[D1-2]` `_wp_sudo_token` / `_wp_sudo_expires` / `_wp_sudo_session_bind` live in the persistent `user_meta` cache group (`includes/class-sudo-session.php:36-61`). A cache-poisoning primitive (the wp2shell class) could set `_wp_sudo_token` to the SHA-256 of an attacker-chosen cookie value → forge an active session. **Fix:** HMAC-sign the token record (self-authenticating), or read the session meta cache-bypassed (`$wpdb`) on the `is_active()`/`verify_token()` path. *Highest-value plugin item.*
2. **Per-user proof key overwrites concurrent sessions.** `[D1-8]` `TOKEN_META_KEY` is one key per user, so a second browser reauthenticating overwrites the first browser's proof and silently breaks its sudo. Key per login-session verifier to support concurrent sessions (`set_token()` / `verify_token()`, `class-sudo-session.php:851-961`).
3. **Lockout as a remediation-DoS.** `[D1-10]` Same shape as A.7: an in-origin XSS (from the victim's own IP, so the per-`(ip,user)` scoping at `:1125-1144` doesn't help) can hard-lock the admin out of the very actions needed to remediate. **Verify:** does `WP_SUDO_RECOVERY_MODE` (or any constant) clear an active lockout? If not, add an out-of-band clear, and consider escalating-delay vs terminal block for the *action* gate.
4. **Cookie scope / SameSite.** `[D1-8]` The `wp_sudo_token` cookie is set at `COOKIEPATH` (`:896-907`), so it rides front-end requests where the auth cookie isn't; and `SameSite=Strict` means a cross-site top-level nav into wp-admin arrives without it → spurious re-challenge. Consider `ADMIN_COOKIE_PATH` scope and `SameSite=Lax`.

### Verified (2026-07-25)
5. **Teardown on `profile_update` — DISPROVEN, no issue.** `[D1-9]` `Plugin::deactivate_session_on_profile_update()` (`class-plugin.php:494`) already fires `deactivate()` **only** when the password hash actually changed (`isset($userdata['user_pass']) && $old_user_data->user_pass !== $userdata['user_pass']`), on the **edited** user. Routine profile saves and editing another user's non-password fields do nothing; the actor's own session is never touched. The plugin got this right.
6. **`destroy_all()` timing — CONFIRMED but already documented; low.** `[D1-8]` `verify_token()` compares the cookie *string*, not the store (documented `class-sudo-session.php:52-57`), so "log out everywhere" lags one request. Intentional/known — a doc note at most, not a new issue. Optional: read the store on the enforcement path.
7. **Lockout as a remediation-DoS — CONFIRMED, file.** `[D1-10]` Verified there is **no** out-of-band clear: `reset_failed_attempts()` runs only on a *successful* reauth (impossible while locked) or on 5-min expiry — no CLI command, no constant, no admin action clears an active lockout. A party holding the session can burn 5 bad passwords (via the reauth AJAX, from the victim's own IP so the per-`(ip,user)` scoping doesn't help) to block the victim from obtaining a **new** sudo session for repeated 5-min windows, at zero cost to themselves (they can't reauth anyway). Only escapes are host-level (FTP; WP-CLI if the CLI surface policy allows). Fix: add a WP-CLI `wp sudo unlock --user` (and/or a break-glass constant), and consider escalating delay rather than a terminal block for the *action*-gate decision (distinct from the auth-attempt decision).

### Documentation
7. **Modal vs. active same-origin XSS.** `[Cx]` The in-editor modal (Milestones A/B) is rideable/keyloggable by an active same-origin XSS in the editor document; the full-page challenge is the stronger surface. Document this in the threat model — the modal is a UX tradeoff for the cookie-*replay* case, not an XSS defense.
8. **Capability-based "code-capable" framing.** `[Cx]` The threat-model doc can note the multisite site-admin-vs-super-admin distinction (only super admins hold the plugin/theme file caps) even though the plugin gates broadly.

### Already covered (no action — reference for the core spec)
- App-password issuance: `auth.app_password` gates **both** `authorize-application.php` (no-JS) and the REST endpoint.
- Registration pivot: `options.critical` gates `users_can_register` and `default_role`.
- Separate-proof-token: the plugin does **not** have the core spec's shared-token flaw.
