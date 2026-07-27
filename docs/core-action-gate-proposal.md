# A Recent-Authentication Gate for WordPress Core

**Status:** Draft proposal, not adopted by WordPress core.
**Drafted:** 2026-04-17 · **Consolidated:** July 2026 (the standalone security pitch was merged into this document; see the note below).
**Author context:** Derived from WP Sudo's research implementation, failure
history, and comparative analysis in
[`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md).
**Intended audience:** WordPress core contributors and security reviewers, plugin authors evaluating adoption, and WP Sudo operators assessing a plausible migration path.

> ⚠️ **Design status — read before implementing (July 2026).** This document
> states the direction and rationale, not a ready-to-land patch. The normative
> interaction is now **pre-submit preflight**, not server-side request replay:
> an integrated admin client pauses the operation before sending it, obtains a
> one-use proof bound to that operation, and sends the request once. A legacy
> surface that cannot preflight safely falls back to reauthenticate, return,
> and ask the user to submit again. The tracked findings live in
> [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md)
> §12 and the [Cut 1-readiness milestone](https://github.com/dknauss/Sudo/milestone/3).
> The illustrative patches in
> [`core-sudo-gate-poc-patches.md`](archive/core-sudo-gate-poc-patches.md) are
> superseded and must not be implemented.

> **Current program authority (July 2026).** The
> [Action Gate Research Program](../.planning/action-gate-architecture-charter.md)
> supersedes this document for implementation order and active scope. The first
> executable slice contains only **plugin/theme upload** and **plugin/theme
> file-editor save**. The identity pivots, critical settings, activation,
> deletion, core update, automation policy, and other effects inventoried below
> are deferred research. They are not Cut 1 prerequisites. This proposal will be
> rewritten from the evidence after Phases 27–31; until then it is rationale and
> route analysis, not a complete implementation contract.

> **Consolidation note (July 2026).** This document now proposes only the
> security primitive: a recent-authentication gate at a small set of effect
> chokepoints, plus the client protocol needed to make it usable. A general
> Consequential-Actions registry is **not part of Cut 1** and is not needed by
> the gate. It remains a possible later complement only if a concrete consumer
> establishes its value (§8).

---

## 1. Summary

> Test whether WordPress core can require action-bound reauthentication at two
> early effect vetoes—plugin/theme upload and file-editor save—without retaining
> or replaying the original request.

An XSS in a `wp-admin` origin is now routinely classified as *RCE-facilitating*,
but only **indirectly**: compromise an admin session → reach the plugin/theme
editor or installer/uploader → write and execute PHP. The research asks whether
two early vetoes plus an action-bound approval can cut that route. It does not
yet claim to de-escalate the entire XSS class; Phase 27 must establish which
active-XSS persistence and confirmation-surface assumptions the flow survives.

The candidate gate requires **fresh, action-bound proof** before either in-scope
effect proceeds, evaluated at the effect. The first evidence target is narrower:
a copied authentication cookie must not inherit another browser's approval, and
an integrated screen must preserve unsent work without weakening the server
veto. Active same-origin XSS remains a separate Phase-27 trust decision.

**Scope of the first evidence claim.** No closure claim exists until the vertical
slice passes its cloned-cookie, atomic-redemption, changed-digest, direct-request,
and trusted-flow tests. Automated updates and package provenance are separate,
deferred work. Signed packages would not prevent an authorized browser from
installing legitimate but attacker-selected code or writing through the editor,
so provenance and proof of intent remain complementary questions.

This is the failure mode behind Core Trac [#20140](https://core.trac.wordpress.org/ticket/20140) (recent auth for consequential actions), generalized past the account-change cases it argued to the full set of terminal effects. It is also the most-*exploited* vulnerability class in current data: Patchstack's [*State of WordPress Security 2026*](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/) reports **broken access control as the single most-exploited category** of the 11,334 vulnerabilities found in 2025, and notes such exploits *"look like normal authenticated traffic with no obvious injection patterns"*. That is precisely why a proof-of-intent gate, not traffic inspection, is the right defense.

---

## 2. The problem

WordPress's capability system answers *"is this principal authorized in general to perform this kind of action?"* That is necessary but not always sufficient. For some operations what matters is whether the principal is **currently, intentionally, and interactively** performing the action now — a distinction WordPress has no first-class primitive for.

Two properties of a compromised session decide the defense:

- It is **an authenticated session.** The attacker loads any admin page, so **nonces are not a defense** (any nonce the UI would render, they harvest), and REST/AJAX are reachable *(verified: `wp_create_nonce()` hashes the session token, so a session holder mints valid nonces)*.
- It does **not** carry the account **password** or any **second factor**.

The threat cases must remain separate. A copied cookie in another browser, a
script session-riding from one compromised admin document, a stored script that
also reaches the confirmation route, and arbitrary server-side PHP do not have
the same powers. The first slice must demonstrate the copied-cookie property.
Phase 27 decides which browser-XSS cases the trusted approval flow can honestly
claim. The worked route inventory lives in
[`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md).

---

## 3. Broader route inventory (deferred beyond the two-effect slice)

The material below explains why a future complete closure becomes broad. It is
not the first implementation scope and must not be used to pull identity pivots
or policy work ahead of the GSD phase gates.

The defense holds under one condition:

> **It holds if and only if there is no ungated route by which the attacker can obtain, set, or reset a credential**, and no ungated route to write/execute code. A credential the attacker controls lets them answer the challenge, and every gate downstream collapses.

So the gated set is a **provable minimal closure**, not a wishlist. It is exactly two route classes: those that **write or execute code**, and those that **manufacture or take over a password-known principal**. Anything outside it (gating posts, comments, general settings) is a different risk discussion and should be declined.

**Group A — terminal code effects.** Gate at the shared sinks so route multiplicity is a non-issue *(verified sinks)*:

| Effect | Shared sink to gate |
|---|---|
| Plugin/theme file editor (incl. AJAX) | `wp_edit_theme_plugin_file()` — the one function `plugin-editor.php`, `theme-editor.php`, and `wp_ajax_edit_theme_plugin_file()` all converge on |
| Install / upload ZIP / update / bulk-update, plugin **and** theme, **and language packs** | `WP_Upgrader::install_package()` — the single package-write funnel (the *funnel*, but not the enforcement point for an interactive attacker — it fires after extraction, so the gate belongs upstream at `upgrader_pre_download`; spec §5.4, §6 row 14-pre) beneath `Plugin_Upgrader`/`Theme_Upgrader` `install()`/`upgrade()`/`bulk_upgrade()`, `WP_Automatic_Updater`, `wp_ajax_update_plugin/theme`, and REST |
| **Activate** a plugin (its activation hook runs PHP) | `activate_plugin()` — reachable directly via REST `update_item` on an already-installed plugin with only `install_plugins`+`activate_plugins` |
| **Switch** to an installed theme (makes its code active) | `switch_theme()` — activating a malicious already-installed theme is a terminal code-execution effect, so it belongs alongside plugin activation |

Gating `install_package()` (not `Plugin_Upgrader::install()`) matters: `install()` alone misses bulk, update, the AJAX updaters, and the auto-updater, and the update package URL comes from mutable transient state, not a fixed .org constant. Provenance ("is this a .org slug") is the wrong key: `plugins_api()` results and the download link are filterable; key on the **package write**.

**Group B — credential/principal pivots (deferred).** A future complete closure
must account for these bypasses, but the first slice deliberately tests the
mechanism on two terminal effects before widening the catalog.

⚠️ **The invariant above explains future closure, not Cut 1.** The two-effect
slice deliberately does not satisfy it: identity pivots and
Application-Password issuance are deferred. Cut 1 tests whether the veto,
proof, and trusted-flow mechanism is viable before attempting a closure claim.

| Pivot | Note |
|---|---|
| **Change own password** | Core requires *no old password* — a session holder sets one they now know. The single most important entry. |
| Change any user's password / **email → reset** | email change on `user-edit.php` commits immediately; self-service confirms to the *attacker's* new address. ⚠️ **No working enforcement seam yet** — the spec's proposed self-email hook is observational and cannot veto the write ([#316](https://github.com/dknauss/Sudo/issues/316)) |
| Create a code-capable user / promote into that authority (incl. bulk) | |
| Create an **Application Password** (REST controller **and** `authorize-application.php` no-JS flow) | durable REST credential that can then change a main password |
| (Multisite) grant Super Admin | on multisite only Super Admins can reach the code effects (`map_meta_cap` denies plugin/theme file caps to non-super-admins) — so gate Super-Admin grants and *their* credential changes, not ordinary site-admin ones |
| Public registration with a code-capable default role | close with a server-side **invariant** (a code-capable role can never be the registration default), validated by effective capability, not role name |

**"Code-capable" means effective capability across *every* gated execution route** — `edit_plugins`/`edit_themes`/`upload_plugins`/`upload_themes` (which map to `install_*`) **and** `activate_plugins` and theme-switch authority (`switch_themes`/`edit_theme_options`), **not** role names. Omitting activation would misclassify a custom registration-default role that can activate an already-installed plugin as "safe." This makes custom roles and the multisite distinction fall out correctly.

**Critical-origin settings are part of the broader inventory, not the first
slice.** Repointing `siteurl`/`home` and replacing connector credentials remain
important routes, but adding them before the proof and trusted-flow mechanism is
validated would repeat the scope-expansion failure this program is correcting.

The WP 7.0 Connectors credential path is one deferred example: a single
`POST /wp/v2/settings` swapping a `connectors_*_api_key` is a
credential-integrity failure reachable without filesystem access. WP Sudo's
research implementation has a `connectors.update_credentials` rule, but that
does not make the route part of the first core slice.

The exact chokepoints, per-function return contracts, and the full catalog are in [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) §4.1 / §5.

---

## 4. How it lands in core

**Enforce at the shared effect sinks, not at `map_meta_cap()`.** *(verified)* `upload_plugins`→`install_plugins` and `upload_themes`→`install_themes`, so a capability-layer gate cannot distinguish an attacker ZIP from a repository install and would disrupt CLI, automation, and introspection. Gate the effects in §3. This is the load-bearing design choice — **gate the effect, not the form field**. A hijacked session skips the form and calls the mutation directly, so the guard must sit at the data-layer chokepoint every surface funnels through; browser, REST, and programmatic callers are then covered by one insertion.

**Branch the decision on actor class, not transport.** This is how non-interactive surfaces are eventually handled without an exemption and without inventing a headless challenge UX. **Cut 1 scopes enforcement to the interactive class only** ([#320](https://github.com/dknauss/Sudo/issues/320)); the remaining rows are the design intent for a separately-scoped provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4)), recorded so the seam is built to accommodate them:

| Actor at the sink | Decision | In Cut 1? |
|---|---|---|
| Interactive cookie session, no proof for this action | **Pause before submission → challenge → confirm → submit once**; legacy clients reauthenticate and resubmit manually | ✅ **Yes — the Cut 1 scope** |
| API credential (Application Password) / REST, no proof | **Block + log** | ❌ Deferred ([#306](https://github.com/dknauss/Sudo/issues/306)) — a default hard-block is a back-compat regression |
| No actor **and** core's own automatic updater **and** package from the site's configured update source | **Allow** (background security updates keep working) — but `install_package()` receives an *unpacked local path*, not the source URL, so **provenance must be decided upstream** (at the update offer / `upgrader_pre_download`) and threaded in as a trusted flag | ❌ Deferred ([#307](https://github.com/dknauss/Sudo/issues/307)) — needs a provenance primitive core lacks |
| WP-CLI | **Allow by default, operator-configurable** — shell access already dominates the gate; a CLI block is security theater with real deployment cost | ❌ Deferred |

**In Cut 1, every deferred row behaves as in current core.** That leaves real
gaps, including Application-Password and automation routes. Cut 1 is a mechanism
experiment, not an actor-driven closure claim.

The decision object never encodes transport: business functions return a decision (as a `WP_Error` in practice), and surface adapters localize the UX — admin UI redirects, REST returns 403, AJAX returns JSON. That keeps transport handling out of privileged business functions (the spec's §5.2 adapters).

**Session / assurance design — a per-session, HMAC-signed, separate proof (no rotation).** The obvious designs fail, and two independent reviews rejected them:

- *Rotate the session token on step-up* — breaks core: `wp_create_nonce()` hashes the token, so rotation invalidates every nonce in every open tab **and** the replayed POST's own nonce; core deliberately re-issues the cookie against the *same* token on password change to preserve nonces; and `WP_User_Meta_Session_Tokens` has a lost-update race that can silently drop the rotation, leaving the thief's copy valid.
- *Stamp `reauth_at` on the shared session record* — the stolen cookie **is** that record; it elevates the thief too.
- *Trust a fresh `login` timestamp* — `wp_signon('','')` mints a fresh stamp from a held cookie with no credential entered.

The surviving properties are narrower than an implementation: approval is
bound to one server-canonical action digest, unavailable to a browser holding
only a pre-challenge cookie copy, short-lived, and atomically consumed. Phase 27
must choose the proof transport, storage, and handoff. Earlier drafts proposed
an HttpOnly proof cookie plus a per-verifier HMAC record; those are retained in
the superseded implementation inventory as hypotheses, not decisions. Whether
same-origin JavaScript can read or exercise the selected proof is the same
question that determines the permitted XSS claim.

The interaction direction is settled even though proof issuance and final
confirmation ordering are not:

1. **Pre-submit interaction, with an honest fallback.** An integrated admin
   client pauses before sending the mutation, asks the server for the canonical
   action and target digest, completes trusted reauthentication and
   action-specific confirmation, obtains authority to submit that exact digest,
   and submits once. A
   legacy surface that cannot do this safely reauthenticates and then asks the
   user to submit again. Core never stores and later executes an already-submitted
   consequential request.
2. **The challenge chrome is not the security boundary.** A modal is excellent
   UX but an ordinary same-origin modal or iframe can be inspected or imitated by
   active XSS. The server-side veto and digest-bound proof remain authoritative;
   WebAuthn/passkeys or an isolated provider surface provide stronger credential
   protection than password entry inside a potentially compromised document.

In plain terms, the practical architecture has three layers:

1. **Early server veto:** core stops the sensitive effect unless this request
   carries valid approval. This is the non-bypassable boundary.
2. **Standard wp-admin preflight:** integrated screens pause before submission,
   preserve the user's local state, and start reauthentication. This is the
   smooth experience.
3. **Action-bound, single-use proof:** reauthentication approves the exact
   displayed operation and target, not a general period of elevated authority.

The intended interaction is:

`click → pause locally → server preflight → trusted reauthentication and action-specific confirmation → submit once with action-bound authority`

Phase 27 decides whether confirmation precedes proof issuance, is the act that
causes issuance, or is combined with redemption inside an isolated flow. The
document deliberately does not promise a JavaScript-readable token or an
ambient proof cookie before that decision.

If core wants the thief's existing session *gone*, offer rotation as an explicit
"sign out other sessions" affordance after step-up—not an implicit side effect
of every elevation. The superseded spec's §4.2 preserves prior mechanism
research, but the GSD program controls what carries forward.

---

## 5. Non-goals — and what is *deferred* vs *excluded*

This is a gate, not a permission system. **Not** a `current_user_can()` replacement (it sits *above* capabilities and never grants authority), a sandbox, a 2FA framework, a WAF, or an audit log. It cannot stop code already running in-process, it does not fix a missing `current_user_can()` (that is an authz bug, orthogonal), and it does not inspect traffic. See §6 for the boundary claim.

**Deferred to a later cut** (not excluded — these are not safe): the per-surface **policy UI**, scope-bound windows, any interactive challenge *rendering* on non-interactive surfaces, and — per [#320](https://github.com/dknauss/Sudo/issues/320) — **the non-interactive routes themselves**. Cut 1 enforces on interactive cookie sessions (browser + cookie-authenticated REST) only; Application Passwords, WP-CLI, cron, and XML-RPC pass through ungated and are the subject of a separately-scoped provenance/automation project ([milestone 4](https://github.com/dknauss/Sudo/milestone/4)). Deferred means *not yet defended*, not *safe*. Note that **"programmatic" is not one of these classes**: a plugin calling a gated sink inside a cookie-authenticated request is an interactive actor and is challenged like any other. Actor class is a property of the request, never of the call site — see the spec §1 and [#357](https://github.com/dknauss/Sudo/issues/357).

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

## 8. Possible future complement: a Consequential-Actions registry

Everything above is the proposal. A previous draft also proposed a general
registry naming consequential operations for audit, UI, and policy consumers.
That abstraction increased scope without improving enforcement: a name in a
catalog cannot stop a mutation, while a veto at the effect can. Cut 1 therefore
contains **no public registry API** and must not be blocked on one.

A registry can be reconsidered later if at least one independently useful
consumer, a stable metadata contract, and a maintenance owner exist. It should
then be designed from those demonstrated needs—not patterned mechanically on the
Abilities API and not introduced as a prerequisite for recent authentication.

### 8.1 What would justify revisiting it

A later registry would need to demonstrate more value than a private list inside
the gate implementation. Plausible consumers include audit tooling, Site Health,
or a standard action-confirmation UI, but none requires a public general-purpose
registry in Cut 1. Observation hooks, if ever added, must be documented as
observational only and never mistaken for veto points.

### 8.2 Relationship to the Abilities API

The Abilities API is adjacent prior art, not a substitute or a required template.
Abilities are executable units; the protected effects in Cut 1 are existing core
mutations that are not abilities. More importantly,
`wp_before_execute_ability` is observational rather than veto-capable:
`WP_Ability::execute()` fires it and then executes the ability regardless of a
callback's return value. The gate must therefore enforce at the data-layer
chokepoint whether or not a future catalog shares Abilities naming or metadata.

### 8.3 Relationship to the gate

The gate owns two private descriptors to compute action digests and render
confirmation. If a public registry ships later, the two may share
identifiers, but Cut 1 must not expose or stabilize a general taxonomy merely to
avoid an internal list.

---

## 9. Relationship to WP Sudo

WP Sudo is the most relevant research prior art. It demonstrates useful
mechanisms and, just as importantly, records why post-submit stash/replay and
several apparently sound assurance designs failed. It is not production
validation of the proposed core boundary.

This document is **not** a verbatim transliteration of WP Sudo into core. It
omits a public registry, does not assume WP Sudo's storage model is automatically
the right core choice, does not assume its `Disabled/Limited/Unrestricted`
policy vocabulary should become the core surface language, and keeps the first
cut smaller and more browser-focused than WP Sudo's full implementation.

**If core shipped this,** WP Sudo would evolve from "full sudo implementation"
into opinionated stricter defaults, operator UI and diagnostics, audit logging
and privilege-drift detection, per-surface policy for deferred actors, richer
multisite and factor tooling, and compatibility bridges. The effect veto,
preflight protocol, and proof primitive move to core; the policy and posture
product stays in the plugin.

---

## 10. Related: pre-authentication chains (e.g. wp2shell) and `WP_LANG_DIR`

A gate against illegitimate *sessions* is not a defense against a *pre-authentication* core vulnerability, and this proposal does not claim to be one — but they intersect at the chokepoint.

**wp2shell** (CVE-2026-63030 batch-route auth bypass + CVE-2026-60137 `WP_Query` SQL injection; unauthenticated RCE, patched in the emergency 6.8.6 / 6.9.5 / 7.0.2 releases). Per public reconstructions (the original advisory **withheld detail**), the chain drives WordPress's **normal REST user-creation path** under the bypass (`401` then re-evaluated as admin → `201`), with the SQLi used as a *read* to poison the object cache; RCE then follows an ordinary plugin install. If that holds, the privileged step routes through the `core/create-user` chokepoint (§3) — defense-in-depth. **But the same object-cache-poisoning primitive is why §4 requires an HMAC-signed assurance**: an unsigned `reauth_at` in the `user_meta` cache group would be forgeable by exactly this primitive. The gate is a layer, subject to its own state being unforgeable — not a substitute for patching the bypass.

**`WP_LANG_DIR` is a PHP-execution directory** *(verified against WP 7.0.2)*: `WP_Translation_File_PHP::parse_file()` loads a `.l10n.php` translation with `$result = include $this->file;` (default on WP 6.5+). Choosing `WP_Upgrader::install_package()` as the code-write seam (§3) covers language-pack installs, which write there; a plugin/theme-`install()`-only seam would miss them. This is neither a session-reachable route nor a new finding. Language-pack packages come from api.wordpress.org and are not cryptographically verified per package — the same long-standing, by-design property as WordPress's plugin/theme **and core** update paths. **Core updates are not an exception** (see `GB-CORE-NOSIG` in [`upstream-sources.md`](upstream-sources.md)); §1 states the mechanism once, and this section does not restate it. (An earlier revision of this paragraph claimed the opposite — that `Core_Upgrader` requests signature verification. That was false and is recorded as [`llm-lies-log.md`](llm-lies-log.md) #39.) `.l10n.php` is a legitimate translation format that `Language_Pack_Upgrader::check_package()` correctly accepts; there is no validation flaw. A stolen-session attacker cannot control the bytes without a separate primitive it does not have. It matters only because the destination executes PHP: a defense-in-depth consideration for the update mechanism generally, not something this gate addresses or needs to. See [`stolen-cookie-rce-attack-tree.md`](stolen-cookie-rce-attack-tree.md) and the spec §4.1/§5.4.

---

## 11. Open questions

1. Whether a public registry ever earns inclusion through a concrete consumer;
   its name and metadata shape are deliberately not Cut 1 questions (§8).
2. Should the proof record build on `WP_Session_Tokens` or a **dedicated store**? Core already has a session-token abstraction that binds, revokes, and stores attached session info; a separate store may still be justified if gate state must be modeled apart from login sessions, at the cost of two session-adjacent models.
3. Correct integration point for cookie-authenticated REST gating (the spec resolves this at the chokepoint; confirm against target core).
4. ~~Scope-bound sudo window vs. flat recent-auth freshness for Cut 1.~~ **Closed** — the spec (§4.2) drops the reusable window for Cut 1 in favour of per-action step-up, so there is no window to scope. Reopens only if a reusable window returns.
5. What should replace the ambiguous `Disabled / Limited / Unrestricted` per-surface vocabulary if core later adds surface policy? (Kept **out** of core Cut 1; core Cut 1 is binary per action.)
6. Which wp-admin screens adopt preflight in the first demonstrator, and which
   use the reauthenticate-then-resubmit fallback.
7. The minimal challenge-provider contract core can support without overcommitting to every 2FA/passkey flow in Cut 1.

The registry-vs-Abilities fork is no longer a Cut 1 decision: Cut 1 proposes no
public registry. Any later catalog must be designed from demonstrated consumers,
with Abilities treated as adjacent prior art rather than a template.

---

## 12. References and source notes

### Companion docs
- [`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) — *what to change in core* (effect vetoes, preflight protocol, proofs, fallback behavior, and challenge providers).
- [`archive/core-sudo-gate-poc-patches.md`](archive/core-sudo-gate-poc-patches.md) — ⚠️ **superseded** illustrative patches at the chokepoints; retained for shape only, known-vulnerable (see its banner). Do not implement.
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
