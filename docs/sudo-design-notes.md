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
- Post-POST interception with request stash and replay (shipped historical
  design; #322 showed why it is not the preferred forward architecture)
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

**Standard wp-admin preflight and action confirmation**
This is the preferred forward UX, not merely an optional modal. An opted-in
screen intercepts the user's action before submission, asks the server to
canonicalize its action and target, reauthenticates and confirms that exact
intent, then sends the mutation once. The server keeps a short-lived,
single-use approval record; it does not return a reusable bearer to JavaScript.
Form state and file selections stay in the browser; nothing executable is
placed in a server replay stash. Unintegrated screens fall back to
reauthenticate-then-resubmit.

The server-side effect veto remains authoritative. A same-origin modal or iframe
is not a security boundary against active XSS, and the browser binding does not
survive a complete cookie-jar clone. The demonstrated claim is consequently
narrower: another browser holding the WordPress authentication cookie alone
cannot use the first browser's approval. See the core spec §5.1 and §7.1.

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

**General action identity / registry — deferred**
Do not add an `action_id` compatibility seam now. The core gate Cut 1 proposes no
public Actions API, and its security does not depend on one. If core later ships
a demonstrated, consumer-driven catalog, WP Sudo can evaluate a small mapping
then. The design should not assume an Abilities-shaped API or preserve
stash/replay merely to consume it.

### Discarded ideas

| Idea | Reason discarded |
|------|-----------------|
| IP binding as default | Too many false positives (mobile, IPv6 rotation, proxies, CDNs). Acceptable as an opt-in constant/filter, wrong as a default. |
| `sudo-{$cap}` capability wrapper | Backward-incompatible on older WP versions, conflates authentication state with role capabilities. |
| Process-scoped tokens (UAC style) | No session persistence per HTTP request. The session model is correct for web. |
