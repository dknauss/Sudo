---
status: current
applies_to: 4.8.x
last_verified: 2026-07-25
---

# WP Sudo Design Notes

> Relocated from `ROADMAP.md` §10 on 2026-07-25. Design rationale, deferred
> feature analysis (incl. the `action_id` seam), and discarded ideas. The
> roadmap's Later / Non-goals items link here for depth.

## Core Sudo Design

*February 26, 2026*

### Already achieved

The following areas from our initial design planning and input from others are
fully implemented in WP Sudo (through v2.5.2):

- All five threat model scenarios (XSS→RCE, session theft, device compromise,
  device loss, undetected persistence)
- Post-POST interception with request stash and replay
- Multi-surface coverage with per-surface policy (three-tier model exceeds the
  document's binary treatment)
- Cryptographic token binding (cookie + SHA-256 in user meta)
- Rate limiting and progressive lockout
- Two Factor plugin integration with browser-bound challenge cookies
- GET request gating (theme switch, network site operations, data export)
- Network-wide multisite sessions and 8 multisite-specific rules
- Per-application-password policy overrides
- 10 audit hooks for external logging
- Proactive session-only authentication (no pending action required)
- `unfiltered_html` capability tamper detection
- WPGraphQL surface gating — three-tier policy for GraphQL mutations (Disabled / Limited / Unrestricted), mutation detection heuristic, headless authentication boundary documented (v2.5.0–v2.5.2)

### Features Shipped Since This Analysis

**Shipped in v2.12.0**

| Feature | Rationale | Effort |
|---------|-----------|--------|
| ~~**WP-CLI `wp sudo` subcommands**~~ ✅ | `wp sudo status`, `wp sudo revoke [--user=<id>]`, `wp sudo revoke --all` implemented for operator workflows. | Medium |
| ~~**Public `wp_sudo_check()` / `wp_sudo_require()` API**~~ ✅ | Third-party plugins can now require sudo without registering full Gate rules. | Medium |
| ~~**Stream bridge**~~ ✅ | Optional bridge `bridges/wp-sudo-stream-bridge.php` maps 10 WP Sudo audit hooks into Stream records. | Low |

### Features to consider (need design work)

These are high-value but architecturally complex. Each needs its own design phase
before implementation.

**Client-side modal challenge**
The ideal UX: `.needs-sudo` CSS class on forms, JS intercepts
submit, inline password prompt, original form submitted with sudo token. Preserves
form state, handles AJAX saves, matches GitHub/Silverstripe. An
iframe + postMessage architecture would support extensibility with 2FA/SSO providers. Major UX improvement
over redirect-to-challenge, but significant complexity: nonce handling, file uploads
(`$_FILES` not stashable in current model), modal-in-modal for the plugin file editor,
fallback server-side flow. Likely a milestone unto itself.

**Per-session sudo isolation**
Current model: one sudo token per user in user meta. The ideal is
"different devices, different sessions, different sudo mode state." Integration
with `WP_Session_Tokens` would provide per-browser isolation — Device A's sudo
would not affect Device B. Architecturally significant; also interacts with nonce
validity if the session layer changes.

**REST API sudo grant endpoint**
A `POST /wp/v2/sudo` endpoint for headless clients to enter sudo mode by providing
credentials. Currently headless clients can only be blocked or allowed by policy.
Threat model needs careful thought — the endpoint must require the credential itself,
not just a valid session, because XSS can obtain both `rest_nonce` and the auth cookie.

**SSO / SAML / OIDC provider framework**
SSO protocols support `IsPassive=true` (SAML) and `prompt=none` (OIDC) as
silent reauthentication mechanisms. Currently there is no formal registration or
dispatch for SSO providers in the challenge flow. Would need a provider interface
(register, render, validate) parallel to the existing 2FA hooks.

**`action_id` seam — reference a core Actions API identity (deferred / trigger-gated)**
Add an optional `action_id` field to each `Action_Registry` rule that points at a
core/CA-style semantic action ID (e.g. `user.promote` → `core/promote-user`), while
keeping the per-surface matching (`admin`/`ajax`/`rest`/`stash`) local to the rule.
This is the **union** the [registry-vs-Abilities decision](core-sudo-gate-implementation-spec.md) (spec §4.1.1; full memo archived at [`archive/core-actions-registry-vs-abilities-decision.md`](archive/core-actions-registry-vs-abilities-decision.md))
anticipates: WP Sudo becomes a *consumer* of the proposed core Actions API's identity
layer while remaining the enforcement + stash-and-replay layer that core defers.
Additive, breaks nothing, and does **not** mean adopting the `consequential-actions`
demo's pure-data architecture wholesale — that would discard the request-pattern
matching that powers stash-and-replay (a strict regression; the CA demo cannot replay).
Nor does it mean adopting CA's semantic metadata (`consequence_class`/`scope`/
`annotations`), which would be dead data no enforcement path reads.
**YAGNI until triggered:** build this only if/when WordPress core actually ships an
Actions API to key against. Until a real identity registry exists to reference, the
`action_id` field would point at nothing. (The effect-oriented robustness CA argues
for is already covered by the 4.1.0 effect-level backstops.)

### Discarded ideas

| Idea | Reason discarded |
|------|-----------------|
| IP binding as default | Too many false positives (mobile, IPv6 rotation, proxies, CDNs). Acceptable as an opt-in constant/filter, wrong as a default. |
| `sudo-{$cap}` capability wrapper | Backward-incompatible on older WP versions, conflates authentication state with role capabilities. |
| Process-scoped tokens (UAC style) | No session persistence per HTTP request. The session model is correct for web. |
