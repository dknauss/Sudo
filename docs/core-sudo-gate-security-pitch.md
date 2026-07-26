# Require recent authentication for executable plugin and theme changes

A built-in *recent-authentication ("sudo") gate* that stops an illegitimate administrator session from introducing attacker-controlled executable plugin/theme code — enforced at core's shared effect sinks, with the decision branching on actor class rather than transport. The engineering detail lives in the companion docs ([§7](#7-where-the-detail-already-lives)); this document is the overview.

> Revision note: this draft incorporates two independent design reviews. The enforcement seam moved from narrow entry-points to the shared **`WP_Upgrader::install_package()`** effect; the session/assurance design moved from session-token *rotation* (which breaks core nonces) to a **per-session, HMAC-signed, separate-proof** record. Claims marked *(verified)* were checked against WordPress 7.0.2 source; re-verify against the target core checkout before patching (see [Provenance](#provenance--verification)).

---

## 1. The problem

An XSS in a `wp-admin` origin is now routinely classified as *RCE-facilitating*, but only **indirectly**. The route:

> compromise an admin session → reach the **plugin/theme editor** or the **plugin/theme installer/uploader** (or REST equivalents) → write and execute PHP.

Every XSS inherits an RCE severity because of a route WordPress leaves open by default, not because of the XSS itself. Close the route and the class de-escalates.

Two properties of a compromised session decide the defense:

- It is **an authenticated session**. The attacker loads any admin page, so **nonces are not a defense** — any nonce the UI would render, they harvest — and REST/AJAX are reachable *(verified: `wp_create_nonce()` hashes the session token, so a session holder mints valid nonces)*.
- It does **not** carry the account **password** or any **second factor**.

## 2. The fix, and exactly what it closes

> Require **fresh proof of the actor's own factor** before a consequential effect proceeds, evaluated at the effect, regardless of the actor's capabilities or the transport the request arrived on.

Be precise about the threat this closes. WordPress auth cookies are **HttpOnly**, so an in-origin XSS usually *session-rides* the victim's browser rather than exfiltrating the cookie. Against session-riding, what closes the route is **the challenge plus a short window**, not any property of the session record. Against genuine cookie *copy* (infostealer malware, header-logging proxies, backups/logs, shared devices), the session design in [§5](#5-how-it-lands-in-core) is what denies replay. State it honestly: this makes possession of an ordinary admin session **insufficient** to introduce executable code; it does not "solve XSS," and an active same-origin XSS can still act inside the window.

## 3. The invariant that keeps the scope small

The defense holds under one condition:

> **It holds iff there is no ungated route by which the attacker can obtain, set, or reset a credential**, and no ungated route to write/execute code. A credential the attacker controls lets them answer the challenge, and every gate downstream collapses.

So the gated set is a **provable minimal closure**, not a wishlist: **{ routes that write/execute code } ∪ { routes that manufacture or take over a password-known principal }**. Anything outside it (gating posts, comments, general settings) is a different risk conversation and should be declined.

## 4. What gets gated (the minimal closure)

**Group A — terminal code effects.** Gate at the shared sinks so route multiplicity is a non-issue *(verified sinks)*:

| Effect | Shared sink to gate |
|---|---|
| Plugin/theme file editor (incl. AJAX) | `wp_edit_theme_plugin_file()` — the one function `plugin-editor.php`, `theme-editor.php`, and `wp_ajax_edit_theme_plugin_file()` all converge on |
| Install / upload ZIP / update / bulk-update, plugin **and** theme, **and language packs** | `WP_Upgrader::install_package()` — the single package-write funnel beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, `WP_Automatic_Updater`, `wp_ajax_update_plugin/theme`, and REST |
| **Activate** a plugin (its activation hook runs PHP) | `activate_plugin()` — reachable directly via REST `update_item` on an already-installed plugin with only `install_plugins`+`activate_plugins` |

Gating `install_package()` (not `Plugin_Upgrader::install()`) matters: `install()` alone misses bulk, update, the AJAX updaters, and the auto-updater, and the update package URL comes from mutable transient state, not a fixed .org constant. Provenance ("is this a .org slug") is the wrong key — `plugins_api()` results and the download link are filterable; key on the **package write**.

**Group B — credential/principal pivots (in v1, not deferrable).** A Group-A-only release is bypassable per [§3](#3-the-invariant-that-keeps-the-scope-small): mint a known credential, log in fresh, pass every Group-A challenge.

| Pivot | Note |
|---|---|
| **Change own password** | Core requires *no old password* — a session holder sets one they now know. The single most important entry. |
| Change any user's password / **email → reset** | email change on `user-edit.php` commits immediately; self-service confirms to the *attacker's* new address |
| Create a code-capable user / promote into that authority (incl. bulk) | |
| Create an **Application Password** (REST controller **and** `authorize-application.php` no-JS flow) | durable REST credential that can then change a main password |
| (Multisite) grant Super Admin | on multisite only Super Admins can reach the code effects (`map_meta_cap` denies plugin/theme file caps to non-super-admins) — so gate Super-Admin grants and *their* credential changes, not ordinary site-admin ones |
| Public registration with a code-capable default role | close with a server-side **invariant** (a code-capable role can never be the registration default), validated by effective capability, not role name |

**"Code-capable" means effective capability across *every* gated execution route** — `edit_plugins`/`edit_themes`/`upload_plugins`/`upload_themes` (which map to `install_*`) **and** `activate_plugins` and theme-switch authority (`switch_themes`/`edit_theme_options`), **not** role names. Omitting activation would misclassify a custom registration-default role that can activate an already-installed plugin (terminal code execution) as "safe." This makes custom roles and the multisite distinction fall out correctly.

**Critical-origin settings are in the closure too.** A cookie-authenticated `POST /wp/v2/settings` repointing `siteurl`/`home` loads attacker-origin scripts **same-origin** in `wp-admin` — an XSS-as-RCE primitive whose script can *fake or keylog the full-page challenge itself*, which would otherwise defeat the gate ([`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md) §6). So the critical-option write (`siteurl`/`home`, and the connector-credential rotation) is gated — the same `core/update-connector-credentials`-style settings chokepoint WP Sudo already gates as `options.critical`. It is not a "different primitive" set aside.

## 5. How it lands in core

**Enforce at the shared effect sinks, not at `map_meta_cap()`.** *(verified)* `upload_plugins`→`install_plugins` and `upload_themes`→`install_themes`, so a capability-layer gate cannot distinguish an attacker ZIP from a repository install and would disrupt CLI, automation, and introspection. Gate the effects in [§4](#4-what-gets-gated-the-minimal-closure).

**Branch the decision on actor class, not transport** — this is how non-interactive surfaces are handled without an exemption and without inventing a headless challenge UX:

| Actor at the sink | Decision |
|---|---|
| Interactive cookie session, no recent-auth window | **Challenge** (full-page; see below) |
| API credential (Application Password) / REST, no window | **Block + log** |
| No actor **and** core's own automatic updater **and** package from the site's configured update source | **Allow** (background security updates keep working) — but `WP_Upgrader::install_package()` receives an *unpacked local path*, not the source URL, so **provenance must be decided upstream** (at the update offer / `upgrader_pre_download`), threaded into the sink as a trusted flag; deciding it at the sink alone forces a choice between blocking all background updates and trusting every no-actor write |
| WP-CLI | **Allow by default, operator-configurable** — shell access already dominates the gate; a CLI block is theater with real deploy cost |

**Session / assurance design — a per-session, HMAC-signed, separate proof (no rotation).** Rejected alternatives and why:

- *Rotate the session token on step-up* — breaks core: `wp_create_nonce()` hashes the token, so rotation invalidates every nonce in every open tab **and** the replayed POST's own nonce; core deliberately re-issues the cookie against the *same* token on password change to preserve nonces; and `WP_User_Meta_Session_Tokens` has a lost-update race that can silently drop the rotation, leaving the thief's copy valid.
- *Stamp `reauth_at` on the shared session record* — the stolen cookie **is** that record; it elevates the thief too.
- *Trust a fresh `login` timestamp* — `wp_signon('','')` mints a fresh stamp from a held cookie with no credential entered.

The design that survives:

1. The proof secret lives only in the browser that answered the challenge; stored server-side only as a hash, keyed to the **current login-session token verifier** (not per-user — concurrent sessions must not overwrite each other). Its cookie is scoped to the site root (`COOKIEPATH`), not `/wp-admin`, so it also reaches cookie-authenticated `/wp-json` REST — otherwise a reauthenticated REST mutation would 403 forever.
2. The assurance record is **self-authenticating**, and the MAC must cover the **proof hash too**: `hash_hmac('sha256', "$user_id|$verifier|$reauth_at|$scope|$proof_hash", wp_salt('auth'))`, verified before any field is trusted. (Signing only user/verifier/time/scope would let a cache-poisoning attacker keep the valid MAC and swap in the hash of a cookie *they* hold — defeating the separate proof.) `session_tokens` lives in the persistent, poisonable `user_meta` cache group (the wp2shell class; see [§9](#9-related-pre-authentication-chains-eg-wp2shell)), which is why the record must be signed at all. Degradation: weaker when the `AUTH_SALT` family lives in `wp_options` rather than `wp-config.php`. Because the write is a whole-`session_tokens` read-modify-write, core also needs a **per-record atomic** update (CAS/lock), or concurrent reauths and challenge-vs-teardown races lose writes.
3. **Only the challenge handler writes it**; core strips reserved `reauth_*` keys from the `attach_session_information` filter result (that filter sets the base session array and is otherwise an injection vector).
4. The read consults the **session store**, so "log out everywhere" revokes within the same request.
5. Teardown clears only the `reauth_*` keys, bound to a credential change **for the target user** — never `destroy_all()`, and never on an admin editing another user.
6. Full-page **top-level** challenge — never a modal/iframe inside a possibly-XSS-compromised admin document (an in-page script can keylog or fake it). For AJAX/REST, return a stable `reauthentication_required` 403 + challenge URL; **never auto-replay** a password/role/email change or an executable upload.

If core wants the thief's existing session *gone*, offer rotation as an explicit "sign out other sessions" affordance after step-up — not an implicit side effect of every elevation.

Note core's existing `reauth=1` clears only the browser cookie (`wp_clear_auth_cookie()`), leaving the token server-valid; any forced-login path must destroy the token server-side.

## 6. Non-goals — and what is *deferred* vs *excluded*

Gate, not permission system. **Not** a `current_user_can()` replacement (sits above capabilities), a sandbox, a 2FA framework, a WAF, or an audit log.

**Deferred to a later cut** (not excluded — do not frame them as safe): the per-surface **policy UI**, scope-bound windows, and any interactive challenge *rendering* on non-interactive surfaces. The non-interactive routes themselves are still **enforced** in v1 via the block/allow policy in [§5](#5-how-it-lands-in-core).

## 7. Where the detail already lives

- [`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md) — the worked adversary and full route enumeration.
- [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) — *what to change in core* (carries the post-review revision note).
- [`core-sudo-gate-poc-patches.md`](core-sudo-gate-poc-patches.md) — illustrative patches (the session-design section is superseded by [§5](#5-how-it-lands-in-core)).
- [`core-action-gate-proposal.md`](core-action-gate-proposal.md) — the *later, optional* consequential-actions registry. **Not part of this ask.**

Trac alignment: cite **[#20140](https://core.trac.wordpress.org/ticket/20140)** only. #37593 ("Replace 'Super Admin' with 'Network Administrator'") and #39174 ("Introduce network roles") are multisite role-terminology tickets, **not** recent-auth prior art.

## 8. Bracketed, on purpose

Named and set aside — a *different* primitive or *not reachable through the core code effects above*: media-upload-to-PHP (`upload_filetypes`, multisite); the WXR importer; `wp-config.php` / drop-ins / direct DB writes; and hosting/FTP/SSH access (which strictly dominates the gate). (The `siteurl`/`home` repointing pivot is **not** here — it is gated as part of the closure, §4, because it can defeat the challenge.) **Not** bracketed: wordpress.org installs, updates, and activation — those are Group-A effects covered by the `install_package()`/`activate_plugin()` seams under the actor-class policy.

## 9. Related: pre-authentication chains (e.g. wp2shell)

A gate against illegitimate *sessions* is not a defense against a *pre-authentication* core vulnerability, and this proposal does not claim to be one — but they intersect at the chokepoint.

**wp2shell** (CVE-2026-63030 batch-route auth bypass + CVE-2026-60137 `WP_Query` SQL injection; unauthenticated RCE, patched in the emergency 6.8.6 / 6.9.5 / 7.0.2 releases). Per public reconstructions — the original advisory **withheld detail** — the chain drives WordPress's **normal REST user-creation path** under the bypass (`401` then re-evaluated as admin → `201`), with the SQLi used as a *read* to poison the object cache; RCE then follows an ordinary plugin install.

If that holds, the privileged step routes through the `core/create-user` chokepoint in [§4](#4-what-gets-gated-the-minimal-closure) — defense-in-depth. **But the same object-cache-poisoning primitive is why [§5](#5-how-it-lands-in-core) requires an HMAC-signed assurance**: an unsigned `reauth_at` in the `user_meta` cache group would be forgeable by exactly this primitive. The gate is a layer, subject to its own state being unforgeable — not a substitute for patching the bypass.

## 10. Why the seam is `install_package()`, not plugin/theme install only

Choosing `WP_Upgrader::install_package()` as the code-write seam ([§4](#4-what-gets-gated-the-minimal-closure)) also covers **language-pack** installs, which write into `WP_LANG_DIR` — and *(verified against WP 7.0.2)* that is a **PHP-execution directory** since WP 6.5: `WP_Translation_File_PHP::parse_file()` loads a `.l10n.php` translation with `$result = include $this->file;`. A plugin/theme-`install()`-only seam would miss this genuine write-to-an-executed-directory path; `install_package()` does not. That is the point for this proposal.

For completeness, and to avoid overstating it: this is **not** a session-reachable route and **not** a new finding. Language-pack packages are sourced from api.wordpress.org and are not cryptographically verified per package — the same long-standing, by-design property as WordPress's **plugin and theme** update paths. (**Core** updates are the exception: `Core_Upgrader` *does* request package signature verification via `verify_file_signature()` — currently soft-fail and dependent on the signing keys being present — so it is not accurate to lump core in with plugin/theme/language packs here.) `.l10n.php` is a legitimate, expected translation format (`Language_Pack_Upgrader::check_package()` correctly accepts it; there is no validation flaw here). A stolen-session attacker cannot control the bytes without a separate primitive it does not have (a source-redirect filter needs code execution, transient poisoning needs a DB write, and MITM needs a network position). It is mentioned only because the destination executes PHP, which is a known defense-in-depth consideration for the update mechanism generally — not something this gate addresses or needs to.

## Provenance & verification

Capability names, sinks, `map_meta_cap` mappings, the nonce/session-token coupling, the `user_meta` persistent cache group, and the REST plugins-controller behavior were checked against WordPress-develop pinned at [`07b1f8b`](https://github.com/WordPress/wordpress-develop/commit/07b1f8b1d25db182d1ac4c2529d97e3d0cb04aea) (files: `src/wp-includes/capabilities.php`, `src/wp-admin/update.php`, `src/wp-includes/pluggable.php` `wp_create_nonce()`, `src/wp-includes/load.php` cache-group registration, `src/wp-admin/includes/class-wp-upgrader.php`, `src/wp-admin/includes/file.php`, `src/wp-includes/l10n/class-wp-translation-file-php.php`, `src/wp-admin/includes/class-language-pack-upgrader.php`, `src/wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php`). Trac topics confirmed against [#20140](https://core.trac.wordpress.org/ticket/20140) / [#37593](https://core.trac.wordpress.org/ticket/37593) / [#39174](https://core.trac.wordpress.org/ticket/39174). The Patchstack figure is [*State of WordPress Security 2026*](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/). **wp2shell**: CVE IDs/versions/patch releases are from the coordinated disclosure ([Searchlight Cyber advisory](https://slcyber.io/research-center/wp2shell-pre-authentication-rce-in-wordpress-core/), which **withheld** technical detail); the mechanism in §9 is a **third-party reconstruction** ([Picus Security](https://www.picussecurity.com/resource/blog/cve-2026-63030-and-cve-2026-60137-wp2shell-wordpress-rce-explained)), explicitly **not authoritative** — confirm it against the eventual full write-up and the patch diffs. Re-verify every signature and line against the target core checkout before patching — per this project's standing rule against trusting cited detail without a fresh source check.
