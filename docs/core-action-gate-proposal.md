# A Recent-Authentication Gate for WordPress Core

**Status:** Draft proposal, not adopted by WordPress core.
**Drafted:** 2026-04-17 · **Consolidated:** July 2026 (the standalone security pitch was merged into this document; see the note below).
**Author context:** Derived from WP Sudo's production implementation and its comparative analysis in [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md).
**Intended audience:** WordPress core contributors and security reviewers, plugin authors evaluating adoption, and WP Sudo operators assessing a plausible migration path.

> ⚠️ **Known gaps — read before implementing (July 2026).** This document states the *direction and rationale*; several mechanisms it describes are **not ready to land**. The tracked findings live in [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) §12 and the [v1-readiness milestone](https://github.com/dknauss/Sudo/milestone/3). Two are load-bearing for readers of *this* document: the "a copied cookie cannot act" property asserted in §4 is **undercut by auto-replay** ([#315](https://github.com/dknauss/Sudo/issues/315)), and the self-email pivot §3 lists as non-deferrable currently has **no working enforcement seam** ([#316](https://github.com/dknauss/Sudo/issues/316)). Treat §4's session design and §3's Group-B coverage as **provisional** until those close. The illustrative patches in [`core-sudo-gate-poc-patches.md`](core-sudo-gate-poc-patches.md) are **superseded** — see the banner at the top of that file.

> **Consolidation note (July 2026).** This document now leads with the *gate* (a built-in recent-authentication requirement for a small, provable closure of consequential effects), which is the security goal. The **Consequential-Actions registry** (an "Actions API") it originally opened with is retained as an **optional, independently-valuable companion** (§8), not as a mandatory Phase 1 the gate depends on. Rationale: a threat-motivated recent-auth gate is more landable in core than a speculative registry, and the gate enforces at the effect chokepoint whether or not the registry ships. The engineering detail lives in the companion docs (§12); this document gives the rationale and the overall shape.

---

## 1. Summary

> Give WordPress core a built-in **recent-authentication ("sudo") gate** that stops an illegitimate administrator session from introducing attacker-controlled executable code or manufacturing a credential it controls — enforced at core's **shared effect sinks**, with the decision branching on **actor class** rather than transport.

An XSS in a `wp-admin` origin is now routinely classified as *RCE-facilitating*, but only **indirectly**: compromise an admin session → reach the plugin/theme editor or the installer/uploader (or their REST equivalents) → write and execute PHP. Every XSS inherits an RCE severity because of a route WordPress leaves open by default, not because of the XSS itself. Closing that route de-escalates the entire class.

The gate requires **fresh proof of the actor's own factor** before a consequential effect proceeds — evaluated *at the effect*, regardless of the actor's capabilities or the transport the request arrived on. Possession of an ordinary admin session becomes **insufficient** to introduce executable code or take over an account *through an interactive, actor-driven request*. It is deliberately narrow: it is not a permission system, a sandbox, a 2FA framework, a WAF, or an audit log (§5, §6).

**Scope of the guarantee.** This closes the *actor-driven* code-introduction and account-takeover paths — the dominant and cheapest attack, a stolen or ridden session. It does **not** close the *automated-update channel*: `wp-cron.php` is unauthenticated-triggerable and runs `WP_Automatic_Updater` with no actor to challenge, so a party who can poison the update transient (via the SQLi / object-cache-poison class this proposal is written against) can still reach the package sink there. That is a code-*provenance* problem, not a proof-of-intent one — and WordPress currently has **no working package signing** to lean on: the signed-updates keys lapsed in 2021, core-update downloads do not request signature verification, and verification soft-fails by default (verified against `wordpress-develop` trunk). Closing that path needs a provenance primitive, tracked separately (core-gate issue [#307](https://github.com/dknauss/Sudo/issues/307)). This gate is **complementary to, not a substitute for, package signing** — and notably, for the actor-driven attack it targets, signing would not help at all (the attacker clicks *install* on legitimately-signed code, or writes PHP through the file editor).

This is the failure mode behind Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140) (recent auth for consequential actions), generalized past the account-change cases it argued to the full set of terminal effects. It is also the most-*exploited* vulnerability class in current data: Patchstack's [*State of WordPress Security 2026*](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/) reports **broken access control as the single most-exploited category** of the 11,334 vulnerabilities found in 2025, and notes such exploits *"look like normal authenticated traffic with no obvious injection patterns"*. That is precisely why a proof-of-intent gate, not traffic inspection, is the right defense.

---

## 2. The problem

WordPress's capability system answers *"is this principal authorized in general to perform this kind of action?"* That is necessary but not always sufficient. For some operations what matters is whether the principal is **currently, intentionally, and interactively** performing the action now — a distinction WordPress has no first-class primitive for.

Two properties of a compromised session decide the defense:

- It is **an authenticated session.** The attacker loads any admin page, so **nonces are not a defense** (any nonce the UI would render, they harvest), and REST/AJAX are reachable *(verified: `wp_create_nonce()` hashes the session token, so a session holder mints valid nonces)*.
- It does **not** carry the account **password** or any **second factor**.

The threat this closes is specific. WordPress auth cookies are **HttpOnly**, so an in-origin XSS usually *session-rides* the victim's browser rather than exfiltrating the cookie. Against session-riding, what closes the route is **the challenge plus a short window**, not any property of the session record; against genuine cookie *copy* (infostealer malware, header-logging proxies, backups/logs, shared devices), the session design in §4 denies replay. The result is bounded: possession of an ordinary admin session becomes **insufficient** to introduce executable code. It does not "solve XSS" — an active same-origin XSS can still act inside the window. The worked adversary and full route enumeration live in [`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md).

---

## 3. The invariant that keeps the scope small, and the minimal closure

The defense holds under one condition:

> **It holds if and only if there is no ungated route by which the attacker can obtain, set, or reset a credential**, and no ungated route to write/execute code. A credential the attacker controls lets them answer the challenge, and every gate downstream collapses.

So the gated set is a **provable minimal closure**, not a wishlist. It is exactly two route classes: those that **write or execute code**, and those that **manufacture or take over a password-known principal**. Anything outside it (gating posts, comments, general settings) is a different risk discussion and should be declined.

**Group A — terminal code effects.** Gate at the shared sinks so route multiplicity is a non-issue *(verified sinks)*:

| Effect | Shared sink to gate |
|---|---|
| Plugin/theme file editor (incl. AJAX) | `wp_edit_theme_plugin_file()` — the one function `plugin-editor.php`, `theme-editor.php`, and `wp_ajax_edit_theme_plugin_file()` all converge on |
| Install / upload ZIP / update / bulk-update, plugin **and** theme, **and language packs** | `WP_Upgrader::install_package()` — the single package-write funnel beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, `WP_Automatic_Updater`, `wp_ajax_update_plugin/theme`, and REST |
| **Activate** a plugin (its activation hook runs PHP) | `activate_plugin()` — reachable directly via REST `update_item` on an already-installed plugin with only `install_plugins`+`activate_plugins` |
| **Switch** to an installed theme (makes its code active) | `switch_theme()` — activating a malicious already-installed theme is a terminal code-execution effect, so it belongs alongside plugin activation |

Gating `install_package()` (not `Plugin_Upgrader::install()`) matters: `install()` alone misses bulk, update, the AJAX updaters, and the auto-updater, and the update package URL comes from mutable transient state, not a fixed .org constant. Provenance ("is this a .org slug") is the wrong key: `plugins_api()` results and the download link are filterable; key on the **package write**.

**Group B — credential/principal pivots (in v1, not deferrable).** A Group-A-only release is bypassable per the invariant: mint a known credential, log in fresh, pass every Group-A challenge.

⚠️ **The invariant above is stated as an iff, and v1 does not satisfy it.** Group B is gated in v1 *for interactive actors* — a cookie session cannot mint an Application Password or change a password without a challenge. But an **Application-Password-authenticated** caller reaches both the credential pivots and the Group-A code effects ungated, because that actor class is deferred (§4, [#320](https://github.com/dknauss/Sudo/issues/320)). So the closure this section proves is the closure of the *actor-driven* attack, not the unconditional closure the iff describes. Note the distinction the deferral turns on: App-Password **issuance** from a browser is in v1; App-Password-**authenticated callers** are not.

| Pivot | Note |
|---|---|
| **Change own password** | Core requires *no old password* — a session holder sets one they now know. The single most important entry. |
| Change any user's password / **email → reset** | email change on `user-edit.php` commits immediately; self-service confirms to the *attacker's* new address. ⚠️ **No working enforcement seam yet** — the spec's proposed self-email hook is observational and cannot veto the write ([#316](https://github.com/dknauss/Sudo/issues/316)) |
| Create a code-capable user / promote into that authority (incl. bulk) | |
| Create an **Application Password** (REST controller **and** `authorize-application.php` no-JS flow) | durable REST credential that can then change a main password |
| (Multisite) grant Super Admin | on multisite only Super Admins can reach the code effects (`map_meta_cap` denies plugin/theme file caps to non-super-admins) — so gate Super-Admin grants and *their* credential changes, not ordinary site-admin ones |
| Public registration with a code-capable default role | close with a server-side **invariant** (a code-capable role can never be the registration default), validated by effective capability, not role name |

**"Code-capable" means effective capability across *every* gated execution route** — `edit_plugins`/`edit_themes`/`upload_plugins`/`upload_themes` (which map to `install_*`) **and** `activate_plugins` and theme-switch authority (`switch_themes`/`edit_theme_options`), **not** role names. Omitting activation would misclassify a custom registration-default role that can activate an already-installed plugin as "safe." This makes custom roles and the multisite distinction fall out correctly.

**Critical-origin settings are in the closure too.** A cookie-authenticated `POST /wp/v2/settings` repointing `siteurl`/`home` loads attacker-origin scripts **same-origin** in `wp-admin` — an XSS-as-RCE primitive whose script can *fake or keylog the full-page challenge itself*, which would otherwise defeat the gate ([`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md) §6). So the critical-option write (`siteurl`/`home`, and the connector-credential rotation) is gated — the same critical-settings chokepoint WP Sudo already gates as `options.critical`. It is not set aside as a "different primitive."

The WP 7.0 Connectors credential path is the concrete instance: a single `POST /wp/v2/settings` swapping a `connectors_*_api_key` is a credential-**integrity** failure reachable with no filesystem access and no code execution, and WP Sudo already gates it in production via its `connectors.update_credentials` rule (verified against WordPress 7.0 GA, released 2026-05-20; sources: the official [Connectors API dev note](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/) and core `src/wp-includes/connectors.php`). It belongs inside the closure, not in the architectural framing: a manifest authorizes the *class* of operation while saying nothing about whether a human is intentionally replacing an API key *now*.

The exact chokepoints, per-function return contracts, and the full catalog are in [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) §4.1 / §5.

---

## 4. How it lands in core

**Enforce at the shared effect sinks, not at `map_meta_cap()`.** *(verified)* `upload_plugins`→`install_plugins` and `upload_themes`→`install_themes`, so a capability-layer gate cannot distinguish an attacker ZIP from a repository install and would disrupt CLI, automation, and introspection. Gate the effects in §3. This is the load-bearing design choice — **gate the effect, not the form field**. A hijacked session skips the form and calls the mutation directly, so the guard must sit at the data-layer chokepoint every surface funnels through; browser, REST, and programmatic callers are then covered by one insertion.

**Branch the decision on actor class, not transport.** This is how non-interactive surfaces are eventually handled without an exemption and without inventing a headless challenge UX. **v1 scopes enforcement to the interactive class only** ([#320](https://github.com/dknauss/Sudo/issues/320)); the remaining rows are the design intent for a separately-scoped provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4)), recorded so the seam is built to accommodate them:

| Actor at the sink | Decision | In v1? |
|---|---|---|
| Interactive cookie session, no recent-auth window | **Challenge** (full-page; below) | ✅ **Yes — the v1 scope** |
| API credential (Application Password) / REST, no window | **Block + log** | ❌ Deferred ([#306](https://github.com/dknauss/Sudo/issues/306)) — a default hard-block is a back-compat regression |
| No actor **and** core's own automatic updater **and** package from the site's configured update source | **Allow** (background security updates keep working) — but `install_package()` receives an *unpacked local path*, not the source URL, so **provenance must be decided upstream** (at the update offer / `upgrader_pre_download`) and threaded in as a trusted flag | ❌ Deferred ([#307](https://github.com/dknauss/Sudo/issues/307)) — needs a provenance primitive core lacks |
| WP-CLI | **Allow by default, operator-configurable** — shell access already dominates the gate; a CLI block is security theater with real deployment cost | ❌ Deferred |

**In v1, every deferred row passes through ungated — as in current core.** That leaves the REST-over-Application-Password install path open, which is a real gap, not an argument that the credential channel is safe. v1 closes the *actor-driven* paths (the dominant, cheapest attack); the credential channel is the follow-on project's job.

The decision object never encodes transport: business functions return a decision (as a `WP_Error` in practice), and surface adapters localize the UX — admin UI redirects, REST returns 403, AJAX returns JSON. That keeps transport handling out of privileged business functions (the spec's §5.2 adapters).

**Session / assurance design — a per-session, HMAC-signed, separate proof (no rotation).** The obvious designs fail, and two independent reviews rejected them:

- *Rotate the session token on step-up* — breaks core: `wp_create_nonce()` hashes the token, so rotation invalidates every nonce in every open tab **and** the replayed POST's own nonce; core deliberately re-issues the cookie against the *same* token on password change to preserve nonces; and `WP_User_Meta_Session_Tokens` has a lost-update race that can silently drop the rotation, leaving the thief's copy valid.
- *Stamp `reauth_at` on the shared session record* — the stolen cookie **is** that record; it elevates the thief too.
- *Trust a fresh `login` timestamp* — `wp_signon('','')` mints a fresh stamp from a held cookie with no credential entered.

The design that survives — **provisional; items 1 and 6 are undercut by [#315](https://github.com/dknauss/Sudo/issues/315)** (auto-replay lets a copied session weaponize the victim's reauth, so "the proof lives only in the browser that answered the challenge" does not yet hold end-to-end; the fix under discussion is to drop auto-replay for consequential actions in favour of a canonical confirmation page bound to a one-time, proof-bound intent token):

1. The proof secret lives only in the browser that answered the challenge; stored server-side only as a hash, keyed to the **current login-session token verifier** (`hash('sha256', wp_get_session_token())`) — **not per-user**, so concurrent sessions do not overwrite each other. Its cookie is scoped to the site root (`COOKIEPATH`), not `/wp-admin`, so it also reaches cookie-authenticated `/wp-json` REST.
2. The assurance record is **self-authenticating**, and the MAC must cover the **proof hash too**: `hash_hmac('sha256', "$user_id|$verifier|$reauth_at|$scope|$proof_hash", wp_salt('auth'))`, verified before any field is trusted. (Signing only user/verifier/time/scope would let a cache-poisoning attacker keep the valid MAC and swap in the hash of a cookie *they* hold — defeating the separate proof.) `session_tokens` lives in the persistent, poisonable `user_meta` cache group (the wp2shell class; §10), which is why the record must be signed at all. Degradation: weaker when the `AUTH_SALT` family lives in `wp_options` rather than `wp-config.php`.
3. **Only the challenge handler writes it**; core strips reserved `reauth_*` keys from the `attach_session_information` filter result.
4. The read consults the **session store**, so "log out everywhere" revokes within the same request.
5. Teardown clears only the `reauth_*` keys, bound to a credential change **for the target user** — never `destroy_all()`, and never on an admin editing another user.
6. Full-page **top-level** challenge — never a modal/iframe inside a possibly-XSS-compromised admin document. For AJAX/REST, return a stable `reauthentication_required` 403 + challenge URL; **never auto-replay** a password/role/email change or an executable upload.

If core wants the thief's existing session *gone*, offer rotation as an explicit "sign out other sessions" affordance after step-up — not an implicit side effect of every elevation. The full mechanics (per-verifier keying, atomic write, lockout-as-remediation-hazard) are in the spec's §4.2.

---

## 5. Non-goals — and what is *deferred* vs *excluded*

This is a gate, not a permission system. **Not** a `current_user_can()` replacement (it sits *above* capabilities and never grants authority), a sandbox, a 2FA framework, a WAF, or an audit log. It cannot stop code already running in-process, it does not fix a missing `current_user_can()` (that is an authz bug, orthogonal), and it does not inspect traffic. See §6 for the boundary claim.

**Deferred to a later cut** (not excluded — these are not safe): the per-surface **policy UI**, scope-bound windows, any interactive challenge *rendering* on non-interactive surfaces, and — per [#320](https://github.com/dknauss/Sudo/issues/320) — **the non-interactive routes themselves**. v1 enforces on interactive cookie sessions (browser + cookie-authenticated REST) only; Application Passwords, WP-CLI, cron, and XML-RPC pass through ungated and are the subject of a separately-scoped provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4)). Deferred means *not yet defended*, not *safe*. Note that **"programmatic" is not one of these classes**: a plugin calling a gated sink inside a cookie-authenticated request is an interactive actor and is challenged like any other. Actor class is a property of the request, never of the call site — see the spec §1 and [#357](https://github.com/dknauss/Sudo/issues/357).

**Bracketed, on purpose** — a *different* primitive or *not reachable through the core code effects above*: media-upload-to-PHP (`upload_filetypes`, multisite); the WXR importer; `wp-config.php` / drop-ins / direct DB writes; and hosting/FTP/SSH access (which strictly dominates the gate). (The `siteurl`/`home` repointing pivot is **not** bracketed; it is gated as part of the closure, §3, because it can defeat the challenge.) **Not** bracketed: wordpress.org installs, updates, and activation — those are Group-A effects covered by the `install_package()`/`activate_plugin()` seams under the actor-class policy.

---

## 6. Threat model and security boundaries

The gate addresses **proof of human intent for consequential operations**. It is not a general security cure and should not be evaluated as one. The row-by-row threat model (which actor classes are defended, and how completely) is maintained in [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) §2; the concentrated value is on the interactive-session rows (stolen/replayed cookie, walk-away device, hijacked Editor/Admin), which are exactly the broken-access-control / privilege-escalation / session-hijack categories that dominate the exploited-vulnerability data (§1).

**In scope:** stolen-session abuse of legitimate privileged operations; high-consequence operations in authenticated browser contexts (plugin/theme install/activate/switch/delete, promotion, deletion, the file editor); and credential-**integrity** state changes such as rotating connector/provider API keys (an integrity failure, not a confidentiality one).

**Out of scope** (the runtime-trust problem the gate deliberately does *not* solve):

- **Plugin sandboxing and runtime isolation.** A gate running in the same process as core does not prevent malicious or compromised plugin code from doing things that never pass through declared chokepoints. Whichever way WordPress modernizes (§7), a proof-of-intent layer remains a distinct concern; it is not a substitute for isolation.
- **Missing authorization checks.** If a path fails to call `current_user_can()` where it should, the gate does not fix that bug; it is additive to authorization, not a replacement.
- **WAF-style exploit detection.** The gate names and gates *declared* consequential operations; it does not classify malicious requests or inspect payloads.
- **Authentication replacement.** No new login system; the gate builds on existing authenticated identity and session infrastructure.
- **Audit logging, monitoring, SIEM.** The gate **enforces** at the chokepoint; it does not observe, correlate, retain, or alert. Collecting and analyzing that stream is a plugin/host concern — an explicit core non-goal.

**The boundary claim:**

> A recent-auth gate can reduce the risk of stolen-session abuse and integrity-sensitive state changes for declared consequential operations. It cannot, by itself, solve WordPress's full plugin-runtime trust problem.

That distinction is what keeps the proposal useful: it is not a substitute for structural modernization, and it is not made irrelevant by it.

---

## 7. Architectural context

The security case above does not depend on which direction WordPress takes architecturally. The extended discussion — how a proof-of-intent gate relates to the "WP Classic / WP Next" split argument (Peralty), the refactor-in-place view (de Valk), the practitioner signal (Coords), and where the gate would sit in a future WP-Next `wp-kernel` — is strategic context, not part of the security ask, so it lives in [`core-gate-architectural-context.md`](core-gate-architectural-context.md). The short version: even the most detailed WP-Next plan omits a proof-of-intent layer, so the gate is orthogonal to the split and worth landing under any architectural future.

---

## 8. The optional companion: a Consequential-Actions registry

Everything above is the gate. This section is the **companion** the earlier draft of this proposal led with: a small **registry** that names consequential operations so core, plugins, audit tools, UI, and policy systems can identify them consistently. It is **independently valuable and independently landable** (a queryable catalog has standalone value for auditability, Site Health, and admin UI even if the gate never ships), but it is **not a prerequisite** for the gate, which enforces at the effect chokepoint regardless of whether any entry is registered. Ship it alongside, before, or after the gate.

### 8.1 Why a registry has standalone value

Security and policy plugins today each reinvent their own ad-hoc catalog of "dangerous operations," with different identifiers and no interoperability surface. A shared registry gives the ecosystem a stable taxonomy, execution hooks for audit/observability, queryable metadata for UI and diagnostics, and a foundation for later manifests or AI-agent boundaries — **without** requiring core to standardize challenge UX, recent-auth semantics, replay, or non-interactive policy in the same release. That is what makes it a cheap, low-risk first primitive on its own track.

### 8.2 Naming and the relationship to the Abilities API

Use namespaced, action-oriented identifiers following the official Abilities API shape `namespace/action-name` (lowercase alphanumerics, hyphens, one forward slash) — e.g. `core/activate-plugin`, `core/delete-user`, `core/update-connector-credentials`, `woocommerce/refund-order`. Do **not** claim dotted identifiers like `core/plugins.activate` are the Abilities convention; they are not.

A registration carries: **id**, **label**, **capabilities**, **category**, and a nested **`consequence`** block holding the risk metadata — `class` (a five-value set: code-execution, privilege-escalation, account-takeover, destructive-deletion, external-credential-mutation), `scope` (a reuse grouping a future gate may key on), and `annotations` (booleans/strings such as `destructive`, `requires_recent_auth`, `consent_required`). The `consequence` block is **nested** on purpose: the same block is portable to a consequential ability's `consequence` annotation later without reshaping.

The Abilities API is adjacent prior art, not a substitute. Abilities are executable units (input, permission, output); "actions" here are consequential operations worth naming, observing, and gating even when they are not naturally one self-contained ability object. There is also a decisive *enforcement* reason the two cannot simply merge: the Abilities execution hook `wp_before_execute_ability` is **observational, not a gate**. `WP_Ability::execute()` fires it and then calls the ability on the very next line, discarding whatever the hook returned. A proof-of-intent gate must therefore enforce at the **data-layer chokepoint** regardless of whether an operation is an ability; the registry only lets it *name* the operation the same way either way. The settled architecture is a **standalone** consequential-actions registry, Abilities-*aligned* in its ID convention and its nested `consequence` block shape, with reading consequence-annotated abilities left as a cheap, deferred extension (nothing populates the ability side today). The full decision — including why both pure forms fail and the one-ID-one-record collision contract — is folded into [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) §4.1.

The public **name** for the API is still open: "action" collides with `do_action()`/`add_action()`, so a name that reads as "consequential operation" (e.g. `wp_register_consequential_action`) may be preferable to a bare "Actions API." This question is cosmetic relative to the architecture.

### 8.3 Mock registry API

```php
wp_register_action(
	'core/activate-plugin',
	[
		'label'        => __( 'Activate a plugin' ),
		'capabilities' => [ 'activate_plugins' ],
		'category'     => 'plugin-management',
		'consequence'  => [
			'class'       => 'code-execution',
			'scope'       => 'plugins',
			'annotations' => [
				'destructive'          => false,
				'requires_recent_auth' => true,
			],
		],
	]
);

wp_get_action( 'core/activate-plugin' );    // array|null
wp_get_actions();                           // array<string, array>
wp_action_exists( 'core/activate-plugin' ); // bool

do_action( 'wp_before_execute_action', 'core/activate-plugin', $context );          // observability only
do_action( 'wp_after_execute_action', 'core/activate-plugin', $context, $result );  // not a gate — see §8.2
```

Phase 1 (the registry) should register, expose metadata, and fire execution hooks; it should **not** require challenge UI, stash/replay, sudo sessions, or non-interactive policy. A Site Health consumer that reports the registered actions and whether gating is enabled demonstrates value before any enforcement exists.

### 8.4 The initial catalog

Keep the first catalog small, explicit, and clearly human-driven — no generic `update_option()` mappings, no speculative "all destructive abilities" umbrella. The canonical catalog (account changes, the code effects, the credential/critical-setting/registration-policy pivots) is maintained in the spec's §4.1 so it stays in one place; it aligns with the closure in §3. The account-change entries reflect #20140: the security boundary is not the target user's old password but recent authentication by the *actor* performing the change.

### 8.5 The gate as a consumer of the registry

If both ship, the gate is a **consumer** of the registry, answering a narrower question — *given a registered consequential action, allow now, require fresh proof, or block by policy?* It builds on the action metadata rather than introducing a second registry. But the ordering is not load-bearing: because enforcement lives at the chokepoint (§4), the gate works whether or not the registry is present, which is why this proposal leads with the gate and keeps the registry optional.

---

## 9. Relationship to WP Sudo

WP Sudo is the most relevant production prior art. It already proves a catalog of consequential operations is useful, that a browser-scoped proof-of-intent model is operationally viable, that request interruption and later resumption can be made usable, that audit hooks for gate outcomes are valuable, and that per-surface policy is a real operator need.

This document is **not** a verbatim transliteration of WP Sudo into core. It separates the registry from the gate, does not assume WP Sudo's storage model is automatically the right core choice, does not assume its `Disabled/Limited/Unrestricted` policy vocabulary should become the core surface language, and keeps early phases smaller and more browser-focused than WP Sudo's full multi-surface implementation.

**If core shipped this,** WP Sudo would evolve from "full sudo implementation" into: opinionated stricter defaults; operator UI and diagnostics; audit logging and privilege-drift / anomaly detection (the SIEM-adjacent observability core leaves out by design, §6, including the lockdown-audit backstop for out-of-band `$wpdb` privilege writes the gate cannot see); per-surface policy for the deferred surfaces; richer multisite and 2FA/passkey tooling; and compatibility bridges. The registry and the recent-auth primitive move to core; the policy and UX product stays in the plugin. Seen from the other side, once core owns the primitive, this posture layer becomes WP Sudo's reason to exist for enterprise and multisite networks: the intended identity of what would otherwise look like scope creep while the plugin also has to *be* the primitive.

---

## 10. Related: pre-authentication chains (e.g. wp2shell) and `WP_LANG_DIR`

A gate against illegitimate *sessions* is not a defense against a *pre-authentication* core vulnerability, and this proposal does not claim to be one — but they intersect at the chokepoint.

**wp2shell** (CVE-2026-63030 batch-route auth bypass + CVE-2026-60137 `WP_Query` SQL injection; unauthenticated RCE, patched in the emergency 6.8.6 / 6.9.5 / 7.0.2 releases). Per public reconstructions (the original advisory **withheld detail**), the chain drives WordPress's **normal REST user-creation path** under the bypass (`401` then re-evaluated as admin → `201`), with the SQLi used as a *read* to poison the object cache; RCE then follows an ordinary plugin install. If that holds, the privileged step routes through the `core/create-user` chokepoint (§3) — defense-in-depth. **But the same object-cache-poisoning primitive is why §4 requires an HMAC-signed assurance**: an unsigned `reauth_at` in the `user_meta` cache group would be forgeable by exactly this primitive. The gate is a layer, subject to its own state being unforgeable — not a substitute for patching the bypass.

**`WP_LANG_DIR` is a PHP-execution directory** *(verified against WP 7.0.2)*: `WP_Translation_File_PHP::parse_file()` loads a `.l10n.php` translation with `$result = include $this->file;` (default on WP 6.5+). Choosing `WP_Upgrader::install_package()` as the code-write seam (§3) covers language-pack installs, which write there; a plugin/theme-`install()`-only seam would miss them. This is neither a session-reachable route nor a new finding. Language-pack packages come from api.wordpress.org and are not cryptographically verified per package — the same long-standing, by-design property as WordPress's plugin/theme **and core** update paths. **Core updates are not an exception** (see `GB-CORE-NOSIG` in [`upstream-sources.md`](upstream-sources.md)); §1 states the mechanism once, and this section does not restate it. (An earlier revision of this paragraph claimed the opposite — that `Core_Upgrader` requests signature verification. That was false and is recorded as [`llm-lies-log.md`](llm-lies-log.md) #39.) `.l10n.php` is a legitimate translation format that `Language_Pack_Upgrader::check_package()` correctly accepts; there is no validation flaw. A stolen-session attacker cannot control the bytes without a separate primitive it does not have. It matters only because the destination executes PHP: a defense-in-depth consideration for the update mechanism generally, not something this gate addresses or needs to. See [`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md) and the spec §4.1/§5.4.

---

## 11. Open questions

1. The public **name** for the registry API (the `do_action()` collision; §8.2) — cosmetic relative to the architecture, still unsettled.
2. Should the recent-auth window build on `WP_Session_Tokens` or a **dedicated store**? Core already has a session-token abstraction that binds, revokes, and stores attached session info; a separate store may still be justified if gate state must be modeled apart from login sessions, at the cost of two session-adjacent models.
3. Correct integration point for cookie-authenticated REST gating (the spec resolves this at the chokepoint; confirm against target core).
4. Scope-bound sudo window vs. flat recent-auth freshness for v1 (the spec recommends flat freshness + optional scope tag).
5. What should replace the ambiguous `Disabled / Limited / Unrestricted` per-surface vocabulary if core later adds surface policy? (Kept **out** of core v1; core v1 is binary per action.)
6. Which replay classes are supported early vs. deferred (the spec's stash/replay vs. reauth-then-resubmit split).
7. The minimal challenge-provider contract core can support without overcommitting to every 2FA/passkey flow in v1.

The registry-vs-Abilities fork (formerly the headline open question) is **resolved** — a standalone, Abilities-aligned registry with union deferred; see the spec §4.1.

---

## 12. References and source notes

### Companion docs
- [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) — *what to change in core* (files, functions, APIs; the registry-vs-Abilities decision; the recent-auth session design).
- [`core-sudo-gate-poc-patches.md`](core-sudo-gate-poc-patches.md) — ⚠️ **superseded** illustrative patches at the chokepoints; retained for shape only, known-vulnerable (see its banner). Do not implement.
- [`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md) — the worked adversary and full route enumeration.
- [`core-gate-architectural-context.md`](core-gate-architectural-context.md) — the strategic "which architectural future" context (split vs. refactor; WP-Next kernel), kept out of this proposal.
- [`wordpress-core-authentication.md`](wordpress-core-authentication.md) — how WordPress core authentication actually works.
- [`abilities-api-assessment.md`](abilities-api-assessment.md) — WP Sudo's runtime posture toward the Abilities API.

### Official WordPress references
- [Abilities API PHP reference](https://developer.wordpress.org/apis/abilities-api/php-reference/) · [`wp_register_ability()`](https://developer.wordpress.org/reference/functions/wp_register_ability/) · [`wp_before_execute_ability`](https://developer.wordpress.org/reference/hooks/wp_before_execute_ability/) · [`WP_Ability::execute()`](https://developer.wordpress.org/reference/classes/wp_ability/execute/)
- [`WP_REST_Server::dispatch()`](https://developer.wordpress.org/reference/classes/wp_rest_server/dispatch/) · [`WP_REST_Server::respond_to_request()`](https://developer.wordpress.org/reference/classes/wp_rest_server/respond_to_request/)
- [Application Passwords handbook](https://developer.wordpress.org/advanced-administration/security/application-passwords/) · [`WP_Session_Tokens`](https://developer.wordpress.org/reference/classes/wp_session_tokens/)

### WP Sudo project references
`includes/class-gate.php` · `includes/class-sudo-session.php` · `includes/class-action-registry.php` · `includes/class-challenge.php` · [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md) · [`security-model.md`](security-model.md) · [`abilities-api-assessment.md`](abilities-api-assessment.md) · [`wordpress-core-authentication.md`](wordpress-core-authentication.md)

### Provenance & verification
Capability names, sinks, `map_meta_cap` mappings, the nonce/session-token coupling, the `user_meta` persistent cache group, and the REST plugins-controller behavior were checked against WordPress-develop pinned at [`07b1f8b`](https://github.com/WordPress/wordpress-develop/commit/07b1f8b1d25db182d1ac4c2529d97e3d0cb04aea) (files: `capabilities.php`, `wp-admin/update.php`, `pluggable.php` `wp_create_nonce()`, `load.php` cache-group registration, `class-wp-upgrader.php`, `wp-admin/includes/file.php`, `l10n/class-wp-translation-file-php.php`, `class-language-pack-upgrader.php`, `rest-api/endpoints/class-wp-rest-plugins-controller.php`). Trac topics confirmed against [#20140](https://core.trac.wordpress.org/ticket/20140). The Patchstack figure is [*State of WordPress Security 2026*](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/). **wp2shell**: CVE IDs/versions/patch releases are from the coordinated disclosure ([Searchlight Cyber advisory](https://slcyber.io/research-center/wp2shell-pre-authentication-rce-in-wordpress-core/), which **withheld** technical detail); the §10 mechanism is a **third-party reconstruction** ([Picus Security](https://www.picussecurity.com/resource/blog/cve-2026-63030-and-cve-2026-60137-wp2shell-wordpress-rce-explained)), explicitly **not authoritative** — confirm against the eventual full write-up and patch diffs. Re-verify every signature and line against the target core checkout before patching, per this project's standing rule against trusting cited detail without a fresh source check.

### Ecosystem commentary and structural-debate context
Moved to the strategy companion — see [`core-gate-architectural-context.md`](core-gate-architectural-context.md) (Peralty's WP-Next series, de Valk, Coords).
