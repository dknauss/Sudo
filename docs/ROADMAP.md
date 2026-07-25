---
status: current
applies_to: 4.8.x
last_verified: 2026-07-25
canonical_for: forward roadmap (Now / Next / Later / Non-goals)
---

# Roadmap

Forward-looking only. Shipped work lives in [`CHANGELOG.md`](../CHANGELOG.md); open
work is tracked as GitHub issues; design analyses live in their own docs. Current
release state is canonical in [`release-status.md`](release-status.md). Nothing is in
flight right now — `main` is clean with no open PRs.

## Now

- **Network-wide role/capability lockdown sweep** ([#219](https://github.com/dknauss/Sudo/issues/219)) —
  the 4.8.0 lockdown audit covers only the current blog
  (`Role_Audit::collect_current_state()` uses `get_current_blog_id()`; `diff()`
  iterates a single site), so subsite privilege drift is invisible to one
  `wp sudo manifest diff` / Site Health / cron run. Walk the network (manifest sites,
  or all blogs via `switch_to_blog()`), keep the cache-bypass reads per-blog, and
  consider a `--site=<id|url>` scope flag with batching for large networks.
  Priority: medium; documented MVP limitation until shipped.

## Next

- **Session-store architecture** — evaluate and likely implement a dedicated
  sudo-session table (authoritative table + usermeta shadow writes). Design:
  [`session-store-evaluation.md`](session-store-evaluation.md).
- **Sudo Activity screen + export surface** — a dedicated list-table Activity admin
  screen (search, sortable columns, CSV export with capability + nonce checks) that
  gives the reserved `export_wp_sudo_activity` capability a UI surface. Keep it lean
  (recent events, short retention) and prepare it for External Audit Mode delegation.
  Include audit-visibility integrity warnings (flag when local passed-event logging is
  disabled or delegated coverage is missing).
- **Multisite terminology + coverage pass** — remaining Core-Trac-alignment work:
  standardize "network administrator" vs. "super admin"; review network-level
  gated-action coverage. Maps to Trac [#20140](https://core.trac.wordpress.org/ticket/20140).
- **Scoped single-user recovery form** — `define( 'WP_SUDO_RECOVERY_MODE', <user> )`,
  the open follow-up to the hardened break-glass (Phase R3).
- **Test-scaffolding hardening** — blueprint rot-guard smoke lane (do first),
  tag-pinned blueprint copies at each release, and run the release environment matrix
  every release.

## Later (need design work)

- **Client-side modal challenge** (GitHub-style inline reauth) — explicitly deferred:
  design-heavy, no security gain over stash → challenge → replay. If built, re-evaluate
  the password-first OS-autofill decision (see
  [`security-model.md`](security-model.md#reauthentication-flow-password-first-design-rationale)).
- **REST sudo-grant endpoint** (`POST /wp/v2/sudo`) for headless clients.
- **Per-session / device sudo isolation** via `WP_Session_Tokens` — deferred:
  architectural, not a hardening item.
- **SSO / SAML / OIDC provider framework** — a provider interface parallel to the 2FA hooks.
- **Third-party bridge discovery mode** — a report-only scanner for plugin
  AJAX / admin-post / REST entry points (not a generic hook firewall).
- **Network admin tools** — cross-site session widget, super-admin visibility
  controls, cross-site session revocation, and a network policy hierarchy (site admins
  can only tighten).
- **Abilities API / MCP** — monitor-only; add a REST rule to `Action_Registry` when a
  destructive core ability (`DELETE` on `/run`) appears. Analysis:
  [`abilities-api-assessment.md`](abilities-api-assessment.md).
- **Internal admin least-privilege governance** — see
  [`archive/internal-admin-governance-spec.md`](archive/internal-admin-governance-spec.md).
- **Request-stash conservative pattern redaction** — broaden beyond the suffix list
  (camelCase secrets such as `clientSecret`); a future option, not an open gap.
- **Environment-diversity and mutation testing** — deferred testing milestones.
- **WordPress core recent-auth gate proposal** — the strategic core work
  ([`core-sudo-gate-implementation-spec.md`](core-sudo-gate-implementation-spec.md) and
  companions; Trac #20140).

## Non-goals / Declined

- **Session extension without reauth** — undermines the time-bounded trust model.
- **Passkey / WebAuthn as a standalone reauth factor** — declined 2026-02-28; OS
  biometric autofill already covers the UX. (Key registration/deletion *gating* is a
  separate concern, shipped via the WebAuthn bridge.)
- **`compatibility` governance mode** — removed in v4.0.0; not returning.
- **`enforce_editor_unfiltered_html` relocation / REST early-exit micro-opts** — no
  measured benefit; relocation would add a front-end detection gap.

---

## Reference (pending relocation)

> **Not the forward roadmap.** The feature backlog below (§§11–11.2) is pending
> migration to GitHub issues (Pass 3); the completed-remediation detail under §12.1
> is slated for deletion once its open Phase R3 work is tracked as an issue. Legacy
> section numbers are kept only until then. Design analyses that used to live here have
> moved to standalone docs — see the [documentation index](README.md). Historical
> v3.1–v3.3 remediation record:
> [`archive/execution-plan-v3.1-v3.3.md`](archive/execution-plan-v3.1-v3.3.md). Full
> shipped history is in [`CHANGELOG.md`](../CHANGELOG.md).

| Assumed roles / role-switching | Facilitating Editor-by-default → assume-Admin is a different product concern, closer to User Switching. Out of scope. |
| Require sudo to access Network Admin | Low value relative to complexity. Gate specific destructive actions instead. |

### Relationship to other roadmap sections

- **Section 1 (Integration Tests):** New features should have integration tests from
  the start — "login grants sudo" and "grace period" are both testable against real
  bcrypt and `wp_login` hooks.
- **Section 2 (WP 7.0 Prep):** The Abilities API (section 2) and the REST sudo grant
  endpoint (above) are complementary — both expand WP Sudo's coverage of non-browser
  surfaces. Monitor together.
- **Section 5 (Environment Diversity):** The modal challenge (if implemented) is the
  feature most sensitive to environment differences — JavaScript, nonce handling, and
  cookie behavior vary across caching layers, reverse proxies, and hosting stacks.

---

## 11. Feature Backlog

Items carried forward from the pre-v2.4 roadmap. Features completed in v2.0.0–v2.3.1
(Site Health integration, progressive rate limiting, CSP-compatible assets, lockout
countdown, admin notice fallback, gated actions table, v2 architecture, editor
`unfiltered_html` restriction, per-app-password policies, PHPStan level 6, CycloneDX
SBOM, accessibility roadmap) are documented in the [CHANGELOG](../CHANGELOG.md).

### ✓ Shipped

**~~WP Activity Log (WSAL) Sensor Extension~~** — shipped v2.11.0 as `bridges/wp-sudo-wsal-sensor.php`. It maps audit hooks to WSAL events (IDs 1900001–1900013), including `wp_sudo_escalation_blocked` (1900012) and `wp_sudo_session_revoked` (1900013); the **Stream** bridge still lacks the newer hooks. Inert when WSAL absent.
**~~Stream bridge~~** — implemented on `main` for v2.12.0 as `bridges/wp-sudo-stream-bridge.php`. Optional mu-plugin mapping for those 11 audit hooks (same coverage caveat as the WSAL bridge).
**~~WP-CLI `wp sudo` commands~~** — implemented on `main` for v2.12.0 (`status`, `revoke --user`, `revoke --all`).
**~~Public `wp_sudo_check()` / `wp_sudo_require()` API~~** — implemented on `main` for v2.12.0 for third-party action gating integrations.
**~~Multi-Dimensional Rate Limiting (IP + User)~~** — shipped v2.13.0. Per-IP tracking via transients alongside per-user tracking, combined lockout policy, and the triggering IP address added as the third `wp_sudo_lockout` hook argument.

### Open — Ecosystem & compatibility research

**Password-manager / 2FA interaction with reauth (research).** How password managers
(1Password, Bitwarden, iCloud Keychain, browser built-ins, Dashlane) interact with WP
Sudo's reauth surfaces — the full-page `wp-sudo-challenge` form and, if the Tier 2
block-editor feature ships, an in-editor modal — is unverified. Managers key off
login-form heuristics (`autocomplete` tokens, field semantics, a real `<form>`), and
our custom challenge handler is deliberately not `wp-login.php`; a `wp.components.Modal`
field is the most autofill-hostile surface. **1Password is reportedly/anecdotally harder
with two-factor lately** (unverified — needs a concrete version + repro before it is
treated as fact). Also covers how WordPress/two-factor renders each provider (TOTP,
WebAuthn/passkey, backup codes) at our challenge time. Deliverable: a per-manager ×
per-surface autofill matrix plus any cheap markup fixes. Tracked in
`.planning/todos/pending/2026-07-05-password-manager-2fa-interaction.md`; feeds the
Tier 2 modal autofill check in `.planning/gutenberg-editor-reauth-phase2-plan.md`.

### Open — Escalation guard follow-ups

**Orphaned-user sweep (optional, opt-in)** — When the admin-escalation guard
(`wp_sudo_guard_escalation`, opt-in / default OFF) blocks a one-shot administrator
*creation*, the user row is inserted before the role is applied, so the blocked
request can leave a **roleless, powerless** user record (never an admin). Per the
escalation-guard analysis §11 "Orphan-cleanup decision (Option A)"
(`admin-escalation-guard-analysis.md`), we deliberately do **not** delete that row
in-hook — mid-request `wp_delete_user()` on a possibly-unauthenticated request
carries real risks (admin-file loading, multisite `wpmu_delete_user`, deletion /
post-reassignment hooks firing during an attack). Backlog: design an **optional,
opt-in sweep** (scheduled or `shutdown`-time) that reclaims never-completed roleless
users left by a blocked escalation — scoped to rows provably created-and-left-roleless
in a blocked request, reversible, and off by default. The guard shipped in 4.1.0
(opt-in, default OFF), so this sweep is relevant whenever the guard is enabled.

**Escalation-guard blind spots (future mitigation — tracked, not yet scoped).**
The 4.1.0 admin-escalation guard hooks the `{prefix}capabilities` meta write and
`grant_super_admin`, so by construction it does **not** see three escalation
shapes. Tracked here so they are not forgotten:

1. **Runtime capability grants via `user_has_cap` / `map_meta_cap` filters.** A
   plugin can confer administrator-equivalent capabilities per-request without ever
   writing role/capabilities meta. A meta-write guard cannot observe this; a
   capability-based check would catch custom admin-equivalent roles but carries a
   high false-positive cost (it must distinguish legitimate per-request grants).
   Needs a design before any attempt.
2. **Direct `$wpdb` writes to the usermeta table.** Raw SQL that sets the
   capabilities row bypasses the metadata filters entirely. Anything with direct DB
   access has broader reach already, so this is low-priority; a periodic
   capabilities-integrity audit (compare stored caps against a known-good baseline)
   is the only plausible mitigation and is out of scope for now.
3. **In-session residual window.** An escalation that fires *during* a legitimate
   admin's own active sudo window (e.g. CSRF or stored XSS landing mid-session) is
   allowed, because the actor holds a valid session. Mitigations would be
   orthogonal (per-action re-prompt for the highest-risk grants, CSRF hardening) and
   trade UX for coverage; tracked, not scheduled.

**✅ MVP shipped in v4.8.0: role/capability lockdown audit** (`includes/class-role-audit.php` + `class-role-manifest.php`; `wp sudo manifest generate|diff`, Site Health, `wp_sudo_role_drift_detected`, #206). The remaining open item is the network-wide multisite sweep (#219). *(Original research notes retained below as history.)*
Explore whether the optional MU-plugin should support a high-assurance mode that
loads an operator-reviewed file manifest of trusted privileged principals and
denies or repairs database-stored role/capability drift at runtime. This would be
aimed at **database-only compromise or accidental role-editor drift**, where an
attacker can mutate `wp_user_roles`, `{prefix}capabilities`, or multisite
`site_admins` but cannot write PHP/files. It should be treated as an
audit-first / enforce-later hardening feature, not a default behavior:

- start with administrator, super-admin, and WP Sudo governance-cap holders;
- explicitly account for per-user capabilities, role definitions, and multisite
  super admins rather than only locking `wp_user_roles`;
- provide a safe snapshot/generate workflow plus filesystem-only break-glass;
- document blind spots: filesystem compromise, runtime grants via
  `user_has_cap` / `map_meta_cap`, SSO/directory sync, role-management plugins,
  and legitimate provisioning changes.

Do not scope implementation until research answers whether enforcement should
filter effective capabilities, rewrite stored state, or only alert; how false
positives are avoided on sites with dynamic roles; and how recovery works if the
manifest is stale. Research tracker:
`.planning/todos/pending/2026-07-15-mu-role-cap-lockdown-mode.md`.

Bridge coverage is partial: the **Stream** bridge does not yet map the
4.1.0 `wp_sudo_escalation_blocked` event (the WSAL sensor now maps it — 1900012) — adding it would let SIEM/audit tools
alert on escalation blocks directly. See the bridge-coverage backlog below.

### ✅ Shipped: Two Factor lifecycle bridge (source notes retained as history)

**Two Factor lifecycle bridge**

Add an optional bridge, guarded by `class_exists( 'Two_Factor_Core' )`, that gates
security-sensitive factor lifecycle operations in the upstream Two Factor plugin.
This is a concrete gap in WP Sudo's current coverage: a user can have no active
WP Sudo session while Two Factor's own session/revalidation logic still permits
factor management. If a browser session is compromised and the attacker also
knows or phishes the password, newly generated recovery codes can satisfy WP
Sudo's later 2FA step.

Verified against WordPress/two-factor commit
[`38cd183`](https://github.com/WordPress/two-factor/tree/38cd183d099ca3597d9bd0f6152a08e824f02a54)
on 2026-04-29:

- Recovery-code generation is `POST /two-factor/1.0/generate-backup-codes`,
  registered in [`providers/class-two-factor-backup-codes.php`](https://github.com/WordPress/two-factor/blob/38cd183d099ca3597d9bd0f6152a08e824f02a54/providers/class-two-factor-backup-codes.php#L74-L83).
  The callback generates plaintext codes, stores hashed codes in
  `_two_factor_backup_codes`, and returns plaintext `codes` in the REST response
  ([same file](https://github.com/WordPress/two-factor/blob/38cd183d099ca3597d9bd0f6152a08e824f02a54/providers/class-two-factor-backup-codes.php#L257-L338)).
- TOTP lifecycle uses `POST` and `DELETE` on `/two-factor/1.0/totp`, registered
  in [`providers/class-two-factor-totp.php`](https://github.com/WordPress/two-factor/blob/38cd183d099ca3597d9bd0f6152a08e824f02a54/providers/class-two-factor-totp.php#L94-L140).
  Setup writes `_two_factor_totp_key` and can enable the provider; deletion
  disables the provider and deletes the key
  ([same file](https://github.com/WordPress/two-factor/blob/38cd183d099ca3597d9bd0f6152a08e824f02a54/providers/class-two-factor-totp.php#L206-L255)).
- Profile-form updates save `_two_factor_enabled_providers` and
  `_two_factor_provider` during `personal_options_update` /
  `edit_user_profile_update`; Two Factor's own `current_user_can_update_two_factor_options()`
  uses its revalidation window, not WP Sudo's session state
  ([`class-two-factor-core.php`](https://github.com/WordPress/two-factor/blob/38cd183d099ca3597d9bd0f6152a08e824f02a54/class-two-factor-core.php#L1478-L1552),
  [`class-two-factor-core.php`](https://github.com/WordPress/two-factor/blob/38cd183d099ca3597d9bd0f6152a08e824f02a54/class-two-factor-core.php#L2404-L2492)).

Recommended bridge rules:

1. `auth.two_factor_backup_codes.generate`
   - REST: route `#^/two-factor/1\.0/generate-backup-codes$#`, method `POST`
   - Label: Generate Two Factor recovery codes
2. `auth.two_factor_totp.manage`
   - REST: route `#^/two-factor/1\.0/totp$#`, methods `POST`, `DELETE`
   - Label: Configure Two Factor authenticator app
3. `auth.two_factor_profile.update`
   - Admin: `profile.php` / `user-edit.php`, `action=update`, method `POST`
   - Callback: gate only when Two Factor form fields are present, such as
     `_nonce_user_two_factor_options`, `_two_factor_enabled_providers`, or
     `_two_factor_provider`
   - Label: Change Two Factor settings

Implementation notes:

- Ship as a bridge first, parallel to the existing WebAuthn bridge, rather than
  expanding core rules unconditionally.
- Keep the rule labels focused on factor lifecycle, not ordinary profile edits.
- Add unit coverage for rule injection and matching; add integration/manual tests
  for recovery-code generation without sudo, TOTP setup/delete, and profile-form
  provider changes.
- Document that Two Factor's own revalidation is still useful, but WP Sudo should
  require its own active sudo session before creating or replacing a factor that
  can satisfy future sudo challenges.

*Impact:* High for session-compromise defense. This closes a concrete bridge gap
where factor-management actions can create credentials used to pass later sudo
2FA checks.

**Patchstack 2FA compatibility target**

Patchstack Security includes its own paid-feature TOTP flow rather than using the
upstream `WordPress/two-factor` provider API. Confirmed first-hand against
Patchstack Pro 2.3.6 source (file last-changed SVN revision 3433693, 2026-01-06):
the login integration is enabled by the `patchstack_login_2fa` option, stores
per-user state in `webarx_2fa_enabled`, `webarx_2fa_secretkey`, and
`webarx_2fa_secretkey_nonce`, collects the login code from `patchstack_2fa`, and
validates with `TokenAuth6238::verify()` in
[`includes/login.php`](https://plugins.svn.wordpress.org/patchstack/trunk/includes/login.php).
2FA registration is limited to licensed Pro installs (the constructor returns
early in free-license mode). The core bridge path is now **runtime-validated
offline** against a legitimately licensed Pro 2.3.6 fixture; the live login-form
challenge, `profile.php` save, and WooCommerce lifecycle remain manual-test. See
[`docs/two-factor-ecosystem.md`](two-factor-ecosystem.md) → Patchstack for the
full evidence.

Recommended scope:

- Track Patchstack as a **second-tier 2FA compatibility target**, behind the
  upstream Two Factor lifecycle bridge.
- Remaining manual compatibility testing: exercise the live login-form
  challenge, the `profile.php` save flow, and the WooCommerce account-form
  lifecycle (the core detection/validation path is already runtime-validated
  offline).
- If demand justifies a bridge, detect enrollment via the public
  `webarx_2fa_enabled` user option and validate through Patchstack's own
  `TokenAuth6238::verify()`. Verification needs the plaintext secret, which
  Patchstack exposes only through the private `P_Login::tfa_get_secret()`, so a
  bridge must reach it via reflection or replicate the libsodium decrypt of
  `webarx_2fa_secretkey` / `_nonce` — validating "without reading the secret" is
  not possible for this plugin's design.
- Treat Patchstack profile 2FA enable/disable as a factor-lifecycle action that
  should be considered for gating in the same design pass as other 2FA lifecycle
  bridges.

*Impact:* Medium. This broadens compatibility coverage for a security-plugin
audience, but is less urgent than the upstream Two Factor lifecycle bridge. The
core detection/validation path is now runtime-validated offline against a
legitimately licensed Pro 2.3.6 fixture; the remaining work is the live
login-form challenge, `profile.php` save, and WooCommerce account-form lifecycle
runs, plus the decision on shipping a dedicated bridge vs. keeping Patchstack
behind the upstream Two Factor lifecycle bridge.

### Open — Medium Effort

**Session Activity Dashboard Widget** — ✅ **Shipped in v3.0.0** (`includes/class-dashboard-widget.php` + `class-event-store.php`, `wpsudo_events` table); spec retained below as history.

Admin dashboard widget showing active sudo sessions (count + user list), recent
gated operations (last 24 h from audit hooks), and policy summary. On multisite,
a network admin widget could show activity across all sites.

Requires storing audit data — currently the hooks fire-and-forget with no
persistence. A lightweight custom table or transient-based ring buffer would
be needed.

*Impact:* Medium — useful visibility for site administrators, but not a security
improvement.

**Recommended MVP slice**

Start with the smallest operator-visible version:

1. **Active sudo sessions**
   - current count
   - top 5 users with active sessions
   - expiry time remaining for each visible session
2. **Recent gated operations**
   - last 10 gated or replayed actions
   - action label, user, site (on multisite), timestamp, outcome
3. **Current policy summary**
   - session duration
   - entry-point policy summary (`REST`, `CLI`, `Cron`, `XML-RPC`, `WPGraphQL`)

Do **not** start with:
- long-term analytics
- charts
- per-IP forensics
- custom filtering UI
- full historical reporting

The MVP goal is fast operational visibility, not a reporting subsystem.

**Recommended data model**

The preferred first implementation is a small custom audit table rather than a
network of transients:

- table name: `{$wpdb->base_prefix}wpsudo_events`
- columns:
  - `id`
  - `site_id`
  - `user_id`
  - `event`
  - `rule_id`
  - `surface`
  - `object_type`
  - `object_id`
  - `ip`
  - `created_at`
  - `context` (`longtext` JSON for small optional metadata)

Why a table first:
- recent-activity widgets are query-shaped, not cache-shaped
- multisite summaries are much easier from one table than from per-site transient shards
- retention and pruning are straightforward
- future dashboard, export, and CLI/reporting features can share the same source

Recommended write scope for the first pass:
- persist only the small subset of events the widget needs:
  - `wp_sudo_action_gated`
  - `wp_sudo_action_replayed`
  - `wp_sudo_action_allowed`
  - `wp_sudo_action_blocked`
  - `wp_sudo_lockout`

Recommended retention for the MVP:
- prune rows older than 7 or 14 days via opportunistic cleanup or a lightweight scheduled task

This keeps the schema useful without turning WP Sudo into a full audit-log product.

**Third-Party Bridge Discovery Mode (report-only)**

Opt-in scanner/assistant that helps operators and maintainers find third-party plugin
mutation surfaces that WP Sudo does not know about yet. This is a bridge-coverage
feature, not an enforcement engine: it should produce evidence, confidence levels,
and candidate rules for human review.

Recommended MVP:

1. **Static entry-point scan**
   - detect `wp_ajax_*`, `wp_ajax_nopriv_*`, `admin_post_*`, and `admin_post_nopriv_*` registrations
   - classify unauthenticated `*_nopriv_*` surfaces as advisory/hard-deny candidates, not sudo-challenge candidates
   - detect `register_rest_route()` registrations, including namespace/route/method hints when statically readable
   - flag generic dispatchers on `admin_init`, `init`, `template_redirect`, `parse_request`, and `rest_api_init`
2. **Dangerous sink scan**
   - flag callbacks/files that call user, role/capability, critical-option, application-password, plugin/theme, filesystem, or direct `$wpdb` write APIs
   - distinguish high-confidence direct calls from low-confidence generic dispatchers
3. **Bridge-rule suggestions**
   - generate disabled-by-default candidate snippets for `wp_sudo_gated_actions` when the surface is authenticated and challengeable
   - include plugin/file/line evidence, suspected surface, suspected sink, and confidence
   - never auto-enable generated rules without operator or maintainer review
4. **Update/activation review signal**
   - after plugin activation or update, optionally report newly discovered mutation surfaces
   - prefer admin notice + report link over automatic blocking

Non-goals for the MVP:

- no per-hook allow/deny matrix
- no continuous `all`-hook runtime tracking on every request
- no vulnerability/reputation scanner
- no automatic enforcement from static-analysis guesses
- no claim that every custom dangerous action can be detected

*Impact:* Medium to high for third-party bridge coverage and operator awareness.
It directly addresses the known limitation that WP Sudo gates known request shapes
and core consequence hooks, while custom plugin actions may be named differently.
Keep the implementation report-only so it adds coverage intelligence without
turning WP Sudo into a generic plugin firewall.

### Open — High Effort

**Gutenberg Block Editor Integration** — ✅ **Shipped** (Milestone A in-editor reauth modal + Milestone B in-modal 2FA, v4.6.0/v4.7.0); the snackbar approach below is superseded, retained as history.

Detect block editor context and queue the reauthentication requirement instead of
interrupting save. Show a snackbar-style notice using the `@wordpress/notices`
API. Expected to require extracting challenge rendering from `class-challenge.php`
into a reusable component. The snackbar flow needs a different UI surface but the
same auth verification and stash-replay machinery. This is also the natural moment
to add Playwright E2E tests covering both the existing challenge page and the new
editor integration.

*Impact:* Medium — improves UX for block editor users, but the current
stash-replay pattern already works for most editor operations.

**Why this stays behind the dashboard widget**

This is not just a UI polish item. It requires decisions about:

- how a save interruption maps to a snackbar or modal flow
- how request stash/replay works when the editor owns the request lifecycle
- how 2FA provider rendering works outside the current full-page challenge
- how file uploads, nonces, and nested dialogs behave in the editor UI
- how the browser test matrix expands once challenge transport is no longer page-based
- **transport choice:** an `apiFetch` middleware that catches the `sudo_required`
  REST error, opens an in-editor challenge, then re-dispatches the original request
  (client-side retry) — likely cleaner than server-side stash/replay for REST-driven
  editor saves. Weigh client-retry vs. reusing the existing stash/replay machinery.
- **surface inventory (do first):** enumerate *which* editor flows actually hit the
  gate — most post saves are not gated; it's specific gated options/operations
  reachable from editor panels. Scope the real trigger set before designing UI.
- **build tooling:** the plugin currently has **no build step**; an editor bundle
  introduces one (e.g. `@wordpress/scripts`). That is a standalone architecture
  decision, not a detail.

So the recommended order is:
- ship the dashboard widget first
- use that work to establish durable audit persistence
- then start a dedicated design phase for block-editor/modal challenge transport before writing production code

**Network Policy Hierarchy for Multisite**

Super admins set minimum session duration and maximum allowed entry-point policies
at the network level. Site admins can only tighten (not loosen) these constraints.
Expected to require extracting a `Settings` or `Policy` class from `class-admin.php`.
The current direct `get_site_option()` access would need to merge network-level
floors with per-site overrides and enforce the "can only tighten" constraint.

*Impact:* Medium — valuable for large multisite networks with delegated site
administration. Not relevant for single-site installs.

### REST / Surface Controls Backlog

These ideas are inspired by REST-surface management tooling, but only the parts that strengthen WP Sudo's own operator experience belong here. The guiding rule is: **improve visibility, testing, and emergency control without weakening the role-agnostic reauthentication model**.

#### Priority 1 — High-value operator tools

**Priority Lockdown Presets — MVP shipped on `main` (unreleased)**

One-click emergency presets for remote or non-interactive surfaces are now implemented in the settings UI for:

- `REST App Passwords`
- `XML-RPC`
- `WP-CLI`
- `Cron`
- `WPGraphQL`

The shipped MVP uses a small set of opinionated presets, not a policy wizard:

- **Normal** — current recommended defaults
- **Incident Lockdown** — tighten all remote surfaces to the most restrictive safe policy
- **Headless Friendly** — preserve intentional API surfaces while tightening legacy or rarely used ones

**Current implementation**
- Adds a preset layer above the existing per-surface settings in `class-admin.php`
- Stores a `policy_preset` marker in `wp_sudo_settings`, while concrete per-surface values remain the source of truth after application
- Requires explicit apply confirmation on save before a preset overwrites the per-surface controls
- Fires a dedicated `wp_sudo_policy_preset_applied` audit hook so logging plugins can record preset application
- Includes unit, integration, Stream/WSAL bridge, and browser happy-path coverage for preset application
- Does not add per-user, per-role, or per-IP bypasses

*Impact:* High during incident response and support. Low implementation risk because it reuses the existing policy model.

**Follow-on improvements**

- Consider a richer diff/confirmation experience only if operators ask for more than the current explicit apply checkbox + summary notice.
- Evaluate whether future Site Health output should surface the active preset marker.
- Keep avoiding silent changes to current recommended defaults, underspecified preset semantics, and duplicated policy logic.

**Request / Rule Tester — MVP shipped on `main` (unreleased)**

An internal diagnostic panel now appears on **Settings → Sudo** for evaluating
representative request shapes without executing them.

**Current implementation**
- Adds a Request / Rule Tester panel to the existing settings screen
- Supports MVP request simulation for `admin`, `ajax`, and `rest`
- Accepts method, URL, authenticated/active-sudo toggles, network-admin toggle,
  and REST auth mode (`cookie`, `application_password`, `bearer`, `none`)
- Reuses `Gate` matching logic through a side-effect-free
  `evaluate_diagnostic_request()` helper instead of reimplementing rule parsing
  in JavaScript
- Returns matched rule information, decision (`allow`, `gate`, `soft-block`,
  `hard-block`), stash/replay eligibility, and explanatory notes
- Includes unit coverage for the pure evaluator and settings-page rendering

**Follow-on improvements**
- Decide whether the network-admin toggle should simulate more of the multisite
  runtime context than the current MVP does
- Add deeper request/body simulation only if real operator use cases justify the
  complexity
- Consider future support for `cli`, `cron`, `xmlrpc`, or `wpgraphql` once a
  reliable diagnostic model exists for those surfaces

*Impact:* High for maintainers, site operators, and third-party integrators trying to understand why a request was gated.

**Protection Status Panel**

An at-a-glance operator panel summarizing whether WP Sudo is in a healthy, fully-protective state.

Recommended first-pass indicators:
- MU-plugin installed or not
- active sudo session / grace window state for the current user
- 2FA integration detected or not
- current surface policy summary
- recent lockouts or recently gated actions
- whether the current environment is using HTTPS

**Implementation details**
- Prefer a compact settings-page panel or dashboard widget summary, not a new top-level admin screen
- Reuse existing Site Health checks where possible instead of duplicating diagnostics logic
- Pull recent activity from the planned `wpsudo_events` table when available; until then, show only live configuration/state
- Keep the first version read-only; no inline policy editing from the status panel

*Impact:* High for day-to-day operability and support triage.

#### Priority 2 — Strong supporting UX

**Expanded Diagnostics / Site Health Coverage**

Broaden WP Sudo's existing diagnostics so operators can quickly confirm that the plugin is enforcing what they think it is enforcing.

Suggested additions:
- challenge URL reachability
- cookie security flags / HTTPS posture
- REST and AJAX fail-closed sanity checks
- detected 2FA bridge/plugin state
- multisite network-policy visibility

**Implementation details**
- Extend `Site_Health` rather than building a parallel diagnostics subsystem
- Separate hard failures from advisory warnings
- Make each diagnostic link back to the exact setting or doc section that explains remediation
- Keep destructive checks simulated where possible; use passive introspection before active probes

*Impact:* Medium-high. Helps support and release verification without changing enforcement behavior.

**Clearer Inline Help / Operator Guidance**

Surface more of the existing docs directly inside the settings UI so admins understand the consequences of each policy without leaving the page.

**Implementation details**
- Add concise help text beside each surface policy and sensitive setting
- Reuse the existing help-tab pattern instead of inventing a new docs UI
- Link to `security-model.md`, `developer-reference.md`, `two-factor-integration.md`, and the new authentication deep references where relevant
- Prioritize “why this matters” guidance over long feature descriptions

*Impact:* Medium. Reduces operator mistakes and support load.

#### Priority 3 — Cautious, opt-in enhancements

**Custom REST / AJAX Sudo Error Semantics**

Allow careful customization of the error message and possibly the response code used when WP Sudo denies a request for lack of an active sudo session.

**Implementation details**
- Prefer filter-based customization first; do not start with a broad admin UI
- Preserve stable machine-readable identifiers such as `sudo_required`
- Do not make the default more permissive or ambiguous for clients
- Restrict any response-code customization to safe alternatives and document the interoperability risks

*Impact:* Medium for advanced integrators, low for typical site owners.

**HTTPS / TLS Posture Warnings**

Warn when WP Sudo is operating in conditions that weaken the security story for challenge and session cookies.

**Implementation details**
- Start with warnings only; do not hard-block or silently rewrite site behavior
- Detect non-HTTPS admin usage, insecure cookie context, or proxy/header misconfiguration where practical
- Surface warnings in Site Health and the settings status panel
- Link to remediation docs instead of trying to auto-fix server configuration

*Impact:* Medium. Valuable security guidance, but should stay advisory unless a future design phase justifies stricter behavior.

#### Explicit non-goals for this backlog slice

To keep WP Sudo aligned with its core model, this backlog does **not** include:

- per-role REST route matrices
- user or IP whitelisting for sudo bypass
- arbitrary REST data exposure features
- broad API-management behavior unrelated to reauthentication or fail-closed enforcement

### Possible Features

**SBOM Enhancements**

The CycloneDX SBOM (`.sbom/bom.json`) currently reflects only the PHP/Composer dependency
graph (zero production dependencies). Options:
- GitHub Action for CI-generated SBOMs on every release tag.
- JS dependency tracking if Gutenberg integration introduces an npm build step.
- Whole-site SBOM tooling references in security documentation.

**JS Testing with Playwright**

No JS tests exist today. The vanilla JS files have no build step and limited
surface area, so the cost-benefit of Jest + JSDOM mocks is low. The natural
trigger is Gutenberg integration, which would require browser-level testing anyway.

### Declined

| Feature | Reason |
|---------|--------|
| Session extension (extend without reauth) | Undermines the time-bounded trust model and violates zero-trust principles. The keyboard shortcut (`Cmd+Shift+S` / `Ctrl+Shift+S`) makes re-authentication fast enough. |
| Passkey/WebAuthn as a standalone reauthentication method | Evaluated and declined (2026-02-28). OS-level biometric autofill (Touch ID, Windows Hello, Face ID) already provides a smooth passwordless-like UX for the password field — a custom WebAuthn button saves one click at significant engineering cost. TOTP-only reauth is the strongest alternative but requires bridge hook redesign. Email OTP standalone has enumeration risk; backup codes standalone are too weak. The password-first + optional 2FA flow is correct for reauthentication. See [the password-first reauthentication rationale](security-model.md#reauthentication-flow-password-first-design-rationale). WebAuthn key *registration/deletion gating* is a separate concern, addressed by the bridge plugin (`bridges/wp-sudo-webauthn-bridge.php`, shipped v2.10.0). |

---

## 11.1 Multisite Network Admin Tools

*Added April 19, 2026*

For multisite networks, WP Sudo's current dashboard widget operates at the per-site level only. Super admins managing large networks need cross-site visibility and control. This section outlines the multisite-specific feature roadmap.

### Scheduled: Network-wide role/capability lockdown sweep ([#219](https://github.com/dknauss/Sudo/issues/219))

**Priority: medium.** The 4.8.0 role/capability lockdown audit (#179) audits only the current blog: `Role_Audit::collect_current_state()` builds state for `get_current_blog_id()` and `diff()` iterates only that site, so subsite privilege drift is invisible to a single `wp sudo manifest diff` / Site Health / cron run. Scheduled for a multisite-focused cycle: walk the manifest sites (or all blogs via `switch_to_blog()`), compare every site in `diff()`, and keep the cache-bypass reads (`cache_results => false`, cache-busted `{prefix}user_roles`) per-blog. Consider a `--site=<id|url>` scope flag and batching for large networks. Tracked as a documented MVP limitation until then.


### Network Dashboard Widget

**Problem:** The current `Dashboard_Widget` (`includes/class-dashboard-widget.php`) shows active sessions and recent events for the current site only. Super admins in the network admin dashboard have no aggregated view of sudo activity across all sites.

**Proposed solution:** Add a network-level dashboard widget at `wp-admin/network/index.php` for super admins only.

**Network widget sections:**

1. **Network-wide active sessions**
   - Total count of active sudo sessions across all sites
   - Top 5 users with active sessions (any site)
   - Per-user breakdown showing which sites they have active sessions on
   - Click-through to individual site dashboards

2. **Network event aggregation**
   - Last 10–20 events from `wpsudo_events` table across all sites
   - Site column showing which site each event occurred on
   - Filter by event type (lockout, gated, blocked, allowed, replayed)
   - Optional date range filter

3. **Network policy overview**
   - Summary of which sites diverge from the network default policy (if Network Policy Hierarchy is implemented)
   - Count of sites using each preset (Normal, Incident Lockdown, Headless Friendly)
   - Warning indicators for sites with Unrestricted policies on sensitive surfaces

**Implementation details:**

- Register widget on `wp_network_dashboard_setup` hook
- Require `manage_network_options` capability
- Query `wpsudo_events` table without `site_id` filter for cross-site aggregation
- Active sessions query: iterate sites with `get_sites()` or use a network-wide user meta query
- Consider pagination or lazy-load for networks with many sites (100+)
- Reuse `Dashboard_Widget` rendering patterns where possible

**Effort:** Medium. The data layer (`Event_Store`) already supports cross-site queries. Main work is the network dashboard hook integration and UI expansion.

### Super Admin Widget Visibility Controls

**Problem:** Site admins (users with `manage_options` but not `manage_network`) see the per-site dashboard widget. Some super admins may want to:
- Hide the widget from site admins entirely (security through obscurity, or to reduce UI clutter)
- Restrict visibility to super admins only across the network
- Allow site admins to see their own site's activity but not network-aggregated data

**Proposed solution:** Add network-level settings for widget visibility.

**Setting options:**

| Setting | Behavior |
|---------|----------|
| **Default** (current) | Widget visible to any user with `manage_options` on their site |
| **Super Admins only** | Widget hidden from site admins; only super admins see it (even on per-site dashboards) |
| **Network Dashboard only** | Per-site widget hidden from all users; only the network dashboard widget is available (super admins only) |
| **Disabled** | No dashboard widgets anywhere |

**Implementation details:**

- Store setting in `wp_sitemeta` as `wp_sudo_network_widget_visibility`
- Check setting in `Dashboard_Widget::register()` before adding widget
- For "Super Admins only" mode: `current_user_can( 'manage_network' )` instead of `manage_options`
- Network settings page: add a Widget Visibility section under Network Settings → Sudo (if network settings page exists) or as a field on the network admin settings screen
- Graceful fallback: if setting is missing, use current behavior (visible to `manage_options`)

**Effort:** Low. This is a capability check and network option, not a new data layer.

### Cross-Site Session Revocation (Network Admin)

**Problem:** Super admins may need to revoke sudo sessions network-wide during an incident (e.g., compromised super admin account, coordinated attack).

**Proposed solution:** Add network-level revocation controls.

**Features:**

1. **Revoke All Network Sessions** — One-click button in network dashboard widget or network settings to expire all active sudo sessions across all sites
2. **Revoke by User** — Search for a user and revoke their sessions on all sites where they have one
3. **Revoke by Site** — Select a site and revoke all sessions on that site

**Implementation details:**

- Reuse `Sudo_Session::deactivate()` per-user
- For network-wide revocation: iterate all users with `_wp_sudo_expires` meta > now
- Consider batching for large networks to avoid timeout
- Fire `wp_sudo_deactivated` hook for each revocation (audit trail)
- Add `wp_sudo_network_session_revoked` action for network-level logging

**Effort:** Medium. The revocation logic exists; this is UI + network iteration.

### Network Policy Hierarchy Enhancements

The existing Network Policy Hierarchy concept (§11 Feature Backlog) defines super admin policy floors. This section adds complementary multisite controls.

**Additional controls:**

1. **Network-level preset enforcement** — Super admin can lock all sites to a specific preset (e.g., "Incident Lockdown" network-wide during an active threat)
2. **Per-site policy overrides visibility** — Network settings page shows which sites have custom policies diverging from the network default
3. **Policy audit log** — Record when site admins change policies, visible in network dashboard widget

**Implementation details:**

- Store network preset lock in `wp_sitemeta`
- On site settings load: if network lock is active, disable per-site controls and show notice
- Policy change logging: extend `Event_Recorder` to capture `wp_sudo_settings` option updates

**Effort:** High. This touches the settings architecture and adds a new audit event type.

### Recommended Implementation Order

1. **Network Dashboard Widget** — Highest value, reuses existing data layer
2. **Super Admin Widget Visibility Controls** — Low effort, addresses immediate operator need
3. **Cross-Site Session Revocation** — Medium effort, valuable for incident response
4. **Network Policy Hierarchy Enhancements** — High effort, defer until base multisite tooling is stable

### Non-Goals for This Slice

To avoid scope creep, this multisite expansion does **not** include:

- Per-site customization of the dashboard widget layout
- Real-time activity streaming / WebSocket push
- Site grouping or tagging for partial network views
- Integration with third-party network management plugins
- Cross-network federation (multiple WordPress networks)

---

## 11.2 Internal Admin Least-Privilege & Governance

*Added April 20, 2026. Revised April 20, 2026 to reflect clean-launch framing (no public install base to migrate).*

WP Sudo's threat model already includes compromised admin sessions, but current
control surfaces are still tied to broad admin capabilities (`manage_options`
on single-site and `manage_network_options` on multisite). This section
tracks the hardening plan to make Sudo governance explicit and least-privilege.

### Framing

As of `docs/release-status.md`, the plugin has not yet shipped to the WordPress.org directory; submission is intentionally delayed/on hold while readiness is maintained. There is no
existing install base to protect from a capability-model transition, so the
rollout is compressed from the original three-phase compatibility-first plan:
strict-capability mode ships as the default in v3.1. Compatibility mode exists
as an opt-in (for single-admin smoke testing and CI fixtures), but is not a
migration endpoint.

### Spec and design source

- [`docs/archive/internal-admin-governance-spec.md`](archive/internal-admin-governance-spec.md)

### Rollout And Follow-Up

1. **Phase 1 (v3.1, shipped) — ship strict governance as the default.**
   - Introduce the full capability surface (`manage_wp_sudo`,
     `view_wp_sudo_activity`, `export_wp_sudo_activity`,
     `revoke_wp_sudo_sessions`) with a centralized `sudo_can()` helper.
   - Strict mode is the default; compatibility mode available via
     `wp_sudo_governance_mode` option.
   - Add the `options.wp_sudo_access` gated rule so grants, revokes, and
     session revocations are all subject to the reauth challenge.
   - Add Access tab with drift detection, "last manager" guard, and audit
     hooks (`wp_sudo_capability_granted`, `wp_sudo_capability_revoked`,
     `wp_sudo_session_revoked`).
   - Add `WP_SUDO_RECOVERY_MODE` break-glass constant per the spec's
     Break-glass section.
   - Replace all `current_user_can('manage_options')` checks in governance
     surfaces with `sudo_can()`. Exit criterion: zero direct checks remain.

2. **Phase 2 (optional future polish) — governance polish.**
   - Integrity warnings when effective visibility is broader than intended.
   - Opt-in 2FA-enrollment requirement for `manage_wp_sudo` holders.
   - Audit visibility on governance-mode transitions.

3. **Phase 3 (post-v3.4 follow-up) — access tab UX.**

   - **User picker instead of user ID field.** The Grant Capability interface
     currently requires a numeric user ID. Replace with a searchable dropdown
     (or typeahead) populated from real WordPress users. Default scope:
     administrator-role users only, since only admins can hold Sudo governance
     capabilities in practice — there is no current use case for granting
     `manage_wp_sudo` to an editor or subscriber who would be unable to reach
     the settings page anyway. Leave the question open in case a future role
     model warrants wider eligibility (e.g., a custom role with `manage_options`
     that should be able to receive `view_wp_sudo_activity`).

   - **Human-readable capability labels in the dropdown.** The grant interface
     currently presents raw capability strings (`manage_wp_sudo`,
     `view_wp_sudo_activity`, etc.). Replace with plain English descriptions and
     demote the capability name to secondary label text:

     | Capability slug | Suggested display label |
     |---|---|
     | `manage_wp_sudo` | Manage Sudo settings and policies |
     | `view_wp_sudo_activity` | View sudo activity and sessions |
     | `export_wp_sudo_activity` | Export sudo activity data |
     | `revoke_wp_sudo_sessions` | Revoke other users' active sessions |

     The capability slug should remain visible (as subtitle or tooltip) so
     developers and integrators can identify capabilities unambiguously without
     relying on display strings.

### Priority rationale

- This is a security-governance control, not a cosmetic UX feature.
- It reduces insider-risk and accidental policy drift in organizations with many admins.
- It complements (not replaces) external logging/audit systems.
- Shipping strict from v3.1 is practical specifically because there is no install base to migrate; deferring to a multi-version guided migration would buy no safety and add real complexity.

---

## 12. Security Hardening Sprint

*Added March 4, 2026 — based on independent assessments by Codex, Gemini, and Claude. Full analysis in `.planning/PROPOSED-NEXT-STEPS-{codex,gemini,Claude}.md` and `.planning/WORKING-ASSESSMENT-Codex.md`.*

WP Sudo ran a focused hardening sprint before new UX or architecture expansion. Sprint A and Sprint B are complete (request stash redaction/cap, upload-action coverage, and non-blocking rate limiting). Remaining work should prioritize reliability hardening and observability over lower-impact feature expansion.

### 12.1 Post-v3.1.3 Security Review Remediation (Open)

*Added June 7, 2026 — based on a deep manual security review of `main` at
`67008e4`.*

The review found no obvious unauthenticated RCE, SQL injection, stored XSS, CSRF,
open redirect, or plain privilege escalation in the core paths reviewed. It did
identify two code-level weaknesses and two product-security hardening items that
should shape the next phases.

#### Phase R1: Patch-grade auth and surface fixes

**Finding: 2FA failure lockout can be reset before 2FA succeeds.** ✅ Shipped in v3.2.0.

- Failure counters now reset only after final sudo activation succeeds.
- Repeated `password -> bad 2FA -> password` cycles continue accumulating failures and reach lockout.
- No-2FA password success still clears prior failed attempts after activation.

**Finding: WPGraphQL Limited mode can fail open on encoded or persisted mutations.**

- The fallback classifier searches the raw POST body for the literal substring
  `mutation`.
- JSON-escaped operation text such as `\u006dutation` can decode to a GraphQL
  mutation while bypassing the raw-body fallback.
- Persisted query/APQ requests can also omit the operation text unless a site
  supplies a classifier through `wp_sudo_wpgraphql_classification`.

**Fix (shipped in v3.2.0):**
- Decode JSON bodies, GET/form `query` params, and multipart `operations`
  GraphQL request payloads before fallback classification.
- Handle batched GraphQL payloads conservatively: any decoded mutation should
  classify the request as a mutation.
- Fail closed on unknown persisted operations unless a classifier identifies the
  operation as a query.
- Update `docs/security-model.md` so the heuristic caveat names encoded payloads,
  multipart operations, batches, and persisted operations precisely.

**Tests:**
- Unit tests for inline mutation, JSON-escaped `mutation`, query false-positive
  strings, batched mutation/query mixtures, GET `query` params, multipart
  `operations`, classifier override precedence, and unknown persisted operation
  handling.
- Integration coverage for WPGraphQL Limited mode once a representative request
  fixture can be exercised.

#### Phase R2: Data minimization and custom-rule replay hardening

**Finding (reconciled 2026-06-21): request-stash redaction is exact-match + suffix-based, not exact-key only.**

- `Request_Stash::is_sensitive_key()` (`includes/class-request-stash.php`, `@since 2.11.0`)
  matches each lowercased field name against an exact list (`sensitive_field_keys()`) AND
  28 secret-value suffixes (`SENSITIVE_KEY_SUFFIXES`) such as `_api_key`, `-secret`,
  `accesstoken`, and `apisecret`, via `str_ends_with`.
- Residual: third-party camelCase names whose lowercased form ends in no listed suffix —
  e.g. `clientSecret`, `refreshToken`, `authorization`, `bearer`, `totp_seed` — are still
  not redacted. The suffix list deliberately omits bare `secret`/`token` to avoid false
  positives. Broadening this is intentionally out of scope (REQUIREMENTS); the fix sketch
  below is retained as a future option, not an open gap.

**Fix:**
- Add conservative pattern-based redaction for common secret-like substrings and
  suffixes.
- Add custom-rule metadata for fields that must be redacted, fields that are safe
  to replay, and actions that should not replay POST bodies after reauth.
- Fail explicitly when a redacted required field makes replay unsafe.

**Tests:**
- Unit tests for camelCase, snake_case, dashed, nested, and array secret keys.
- Replay tests proving built-in safe forms still work and unsafe custom forms fail
  with an operator-readable message.

#### Phase R3: Governance and purpose alignment

**Finding: Sudo governance still relies on broad administrator capabilities.**

- This is documented as an internal governance boundary, not a bug, but it limits
  WP Sudo's effectiveness for organizations with many administrators.
- A user who can satisfy sudo and manage options can weaken WP Sudo's policies,
  remove optional hardening, or widen headless surfaces.

**Fix:**
- Continue the dedicated capability plan in §11.2 before adding lower-impact
  features, and evolve governance with dedicated `manage_wp_sudo`-style
  capabilities, stronger policy-change protections, and safer custom-rule
  replay/redaction controls.
- Require explicit `manage_wp_sudo`-style authority for settings, policy changes,
  activity export, and session revocation.
- Gate policy weakening and governance-mode transitions as high-risk actions.
- Keep a documented recovery/break-glass path so least-privilege controls do not
  create unrecoverable lockouts.
- Audit product/docs language for the authentication/authorization boundary,
  starting with the admin Help dropdown copy and public FAQ/readme language.
  Preferred framing: "Sudo verifies that the current user is still the account
  holder; WordPress still decides whether that user is allowed to perform the
  action." Avoid wording that implies WordPress role/capability checks happen
  only after a post-auth replay.

**Tests:**
- Capability matrix tests for single-site and multisite.
- Tests proving ordinary administrators without Sudo management authority cannot
  relax policies, view restricted activity, export logs, or revoke sessions.
- Break-glass tests that prove recovery mode is explicit, auditable, and bounded.

**Finding: `WP_SUDO_RECOVERY_MODE` break-glass is uncontained.** *(Found June 13, 2026; ✅ contained June 13, 2026 — items 1, 3, 4 below shipped in v3.4.0. Item 2, the scoped single-user form, remains.)*

Verified against the code path and WordPress core admin-page routing (describes the *pre-fix* behavior):

- The recovery branch in `wp_sudo_can()` and the cap mapping in
  `wp_sudo_map_governance_meta_cap()` (`includes/functions-governance.php`) have
  **no role gate**. While `WP_SUDO_RECOVERY_MODE` is defined truthy, any logged-in
  user checking their own `manage_wp_sudo` receives it — Subscribers and Editors
  included, single-site and per-site multisite users alike. (Multisite super
  admins already short-circuit earlier and are unaffected.)
- `manage_wp_sudo` is the **master governance capability**: a holder can open the
  Access tab and self-grant the other three caps and change gating policy. So the
  open surface is "every authenticated account → full Sudo governance" for as long
  as the constant is set.
- WordPress core gates the Sudo submenu page by its own capability
  (`user_can_access_admin_page()` → `current_user_can('manage_wp_sudo')`, mapped to
  `exist` in recovery mode), so a non-admin reaching
  `options-general.php?page=wp-sudo-settings` directly passes — the hidden Settings menu is
  not a real barrier.
- The promised safeguards never existed: no admin notice and no
  `governance.recovery_mode` audit event are implemented anywhere in `includes/`.
  `docs/security-model.md`, `docs/FAQ.md`, and `readme.md` previously asserted them
  (corrected docs-only; see `docs/llm-lies-log.md` #20).

This violates Phase R3's own acceptance criterion that recovery mode be "explicit,
auditable, and bounded." The practical risk is operator error rather than remote
escalation (defining the constant requires `wp-config.php` write access), but a
break-glass mechanism should minimize the blast radius of its open window.

**Fix (the intended features):**

1. ✅ **Role-gate the grant (primary).** *Shipped.* In *both* `wp_sudo_can()` and
   `wp_sudo_map_governance_meta_cap()`, the recovery branch now also requires
   `manage_options` (single-site) / `manage_network_options` (multisite). A
   legitimate locked-out admin still passes while non-admins no longer gain control.
   The multisite super-admin short-circuit is intact.
2. **Optional scoped form.** *Remaining follow-up.* Support
   `define('WP_SUDO_RECOVERY_MODE', <user_id_or_login>)` to grant only that user;
   `true` retains the (now admin-only) broad behavior. Tightest containment for
   operators who want surgical recovery — and the proper fix for the non-admin Sudo
   manager that item 1 deliberately does not rescue.
3. ✅ **Surface a non-dismissible admin notice** on the Sudo settings screen
   whenever recovery mode is active. *Shipped* (`Admin::render_recovery_mode_notice()`).
4. ✅ **Emit a `wp_sudo_recovery_mode_active` audit hook** (stored as a sampled
   `recovery_mode` event). *Shipped.* `docs/current-metrics.md` audit-hook count and
   `docs/developer-reference.md` updated. The stored event is unprefixed
   `recovery_mode`, not the archived `governance.recovery_mode` literal (that spec
   never shipped; consistency with existing Event_Store names wins).
5. **Visibility / monitoring follow-up.** *Remaining — design reviewed June 13, 2026.*
   The notice (item 3) and audit event (item 4) both fire only inside
   `Admin::render_settings_page()`, so an operator who enables recovery, regains
   access, and navigates away is never reminded again, and an external SIEM never
   learns the bypass is active unless someone opens the Sudo settings page.
   - **Ship:** a `Site_Health::test_recovery_mode()` returning a **critical** status
     keyed strictly on `wp_sudo_is_recovery_mode()` — pull-based, REST-exposed (so
     monitoring polls it), reflects live state, no cron dependence. Register it in
     `Site_Health::register_tests()` alongside `test_stale_sessions`; confirm it
     surfaces to the responsible operator on multisite (recovery maps to
     `manage_network_options`).
   - **Dropped:** a daily-cron audit heartbeat. It is unreliable exactly on the
     neglected sites where recovery is most likely left on (opportunistic WP-Cron /
     `DISABLE_WP_CRON`), is redundant with the Site Health REST status, and would
     force a `user_id = 0` row that corrupts both the per-user sampling bucket and the
     documented "user accessing the Sudo surface" semantics of
     `wp_sudo_recovery_mode_active`. If a recorded daily signal is ever required, it
     must be a *dedicated* `recovery_mode_heartbeat` hook + event type, never a reuse
     of `recovery_mode_active`.
   - **Deferred:** broadening the notice to `all_admin_notices`. Notice fatigue plus
     multisite capability nuance outweigh the marginal reminder value once the Site
     Health flag exists.
   - **Naming / disambiguation.** WP Sudo's "recovery mode" shares the phrase with
     WordPress core's own recovery mode (`WP_Recovery_Mode`, the fatal-error handler;
     e.g. the core cron event `recovery_mode_clean_expired_keys`), which confuses
     readers — a tester mistook a core Site Health cron warning for a WP Sudo fault.
     **Decision: do not rename the public API.** Every WP Sudo symbol is already
     prefixed (`WP_SUDO_RECOVERY_MODE`, `wp_sudo_is_recovery_mode()`,
     `wp_sudo_recovery_mode_active`), so there is no code collision — only the English
     phrase overlaps — and renaming would break operator `wp-config.php` constants,
     external SIEM hook subscribers, and the stored `recovery_mode` event type (and its
     historical rows). Instead: (a) standardize **"break-glass"** as the lead term in
     all human-facing text (notice, FAQ, security-model, the future Site Health test
     description) so "recovery" rarely appears unqualified; (b) one safe, non-breaking
     relabel — the dashboard event label "Recovery" (a display string, not a stored
     value or API symbol) → **"Break-glass"**; (c) a one-line FAQ/security-model note
     disambiguating from core's `WP_Recovery_Mode`. Any true symbol rename is deferred
     to a major version with deprecation aliases.

**Process:** Shipped via a Pre-Implementation Design Review (reviewer agent + design
brief), strict TDD, and the Pre-Commit Reviewer Workflow, per `CLAUDE.md`.

**Tests:**
- `wp_sudo_can()` / `wp_sudo_map_governance_meta_cap()`: recovery mode grants
  `manage_wp_sudo` to an admin (has `manage_options`) but **not** to a
  non-admin (Subscriber/Editor); other three caps are never granted by recovery
  mode; behavior is per-current-user only.
- Multisite: super-admin short-circuit still passes; per-site non-admin denied;
  per-site admin (`manage_network_options`) passes.
- Scoped-constant form (if built): only the named user/ID is granted; others denied.
- Notice renders on Sudo admin pages while active and is absent when the constant
  is removed (no DB persistence).
- Audit event fires once per recovery-mode admin page load (or per access grant —
  decide in design review) with the expected payload.
- Site Health (item 5): `test_recovery_mode()` returns `critical` only when the
  constant is set; returns `good`/absent in both strict and compatibility governance
  mode (no false positives); surfaces on multisite where the network operator sees it.

**Docs reconciled (shipped):**
- ✅ Restored the (now accurate) notice/event/role-gate descriptions in
  `docs/security-model.md`, `docs/FAQ.md`, and `readme.md`.
- ✅ Updated `docs/llm-lies-log.md` #20 notes to mark the containment as built.
- ✅ `tests/MANUAL-TESTING.md` §23.8 documents the contained behavior — it expects
  denial for non-administrators and includes steps for the non-dismissible notice and
  the sampled `recovery_mode` audit event.

#### Phase R4: E2E CI acceleration and release-assurance tuning

**Goal:** shorten feedback loops without weakening the release-grade browser
coverage that protects challenge, replay, admin UI, and WordPress 7.0 behavior.

**Completed first slice:**
- Shard the default Chromium Playwright suite across four GitHub runners.
- Preserve a final aggregate check named `E2E Tests` so branch protection can keep
  relying on the existing required status.
- Upload per-run artifacts and cancel stale same-branch E2E runs after new
  pushes.

**Completed second slice:**
- Replace opaque Playwright `--shard` assignment with four explicit CI groups:
  `challenge-basic-admin`, `challenge-2fa-ui`, `challenge-lockout-surfaces`, and
  `challenge-replay-multisite`.
- Split the heavy `challenge.spec.ts` coverage by behavior using `--grep`
  ranges, without moving or rewriting the test bodies.
- Keep the aggregate `E2E Tests` required status check unchanged.

**Next implementation options, in priority order:**
- Measure the explicit-group runtime on GitHub Actions. If a new long pole
  appears, adjust group membership before adding caching or custom images.
- Cache Playwright browser binaries (`~/.cache/ms-playwright`) with keys derived
  from `package-lock.json` and the pinned Playwright version. Keep Linux system
  dependency installation explicit unless a runner image proves it is stable.
- Evaluate a prebuilt CI image containing Node, Playwright system dependencies,
  Chromium, Docker tooling, and the `wp-env` compatibility patch. Adopt only if
  image maintenance is cheaper than repeated runner bootstrap time.
- Split smoke and full E2E policy: keep fast smoke coverage required on ordinary
  PRs, keep the full suite required for release-grade deployments, admin/browser
  UX changes, request gating/replay changes, and manual dispatches.
- Add conservative path-based skipping only for low-risk docs, metadata,
  dependency-lockfile, or Blueprint-only changes. Use a required placeholder check
  rather than silently omitting required status checks, and never skip full E2E
  for security, request gating, replay, wp-env/Playwright infrastructure, or
  release-candidate changes.
- Audit test isolation before enabling Playwright workers inside a single
  WordPress environment. Do not raise `workers` above `1` until shared database,
  cookie, user, nonce, and option state are proven isolated or reset per test.
- Keep `retries: 2` until grouped CI establishes a lower flake baseline; reducing
  retries is a later optimization, not the first stability lever.

**Acceptance criteria:**
- Each optimization must preserve a visible required status for branch protection.
- Full E2E must remain easy to trigger manually before tags and release branches.
- Low-risk skips must be documented in the E2E Validation Policy and must fail
  closed when changed paths are ambiguous.
- Record explicit-group distribution and runtime after rebalancing; no group
  should own all challenge lockout, 2FA, and replay coverage.
- Measure before/after wall-clock duration for `E2E Tests` and record material
  changes in `docs/release-status.md` or the release notes when they affect
  release process expectations.

#### Phase R5: README screenshot refresh and visual docs

**Status:** completed on `main` after v3.4.0. The refreshed public screenshots
use the WordPress Playground demo on WordPress 7.0, default admin color scheme,
1280x760 CSS viewport cropped to the WordPress frame, 2x device scale, and the
seeded demo users/sessions/events from `blueprint-main.json` plus the recovery
state from `blueprint-recovery-mode.json`. Future UI-changing phases should add
their own screenshot-refresh task when public docs would otherwise drift.

**Goal:** bring public screenshots back in line with the v3.2 UI and make the
README/readme assets show the screens operators now use.

**Scope:**
- Inventory current README, `readme.txt`, WordPress.org asset, and docs image
  references before deciding whether to patch or fully replace screenshots.
- Capture a consistent screenshot set for Settings -> Sudo, the Access tab,
  Session Activity dashboard widget, Rule Tester, Help dropdown where useful,
  and any other new screens or widgets added since the last visual refresh.
- Prefer redoing the full set when old screenshots use stale admin styling,
  inconsistent viewport sizes, or no longer explain the current product surface.
- Document the capture environment, viewport, admin color scheme, WordPress
  version, and demo data assumptions so future screenshot updates are repeatable.
- Check that no passwords, nonces, session tokens, real user emails, API keys, or
  private site URLs appear in screenshots.

**Acceptance criteria:**
- README/readme screenshots match current UI and cover the new screens/widgets.
- Images are optimized, accessible with useful alt text/captions, and suitable
  for both GitHub README and WordPress.org plugin asset expectations.
- Visual QA confirms the screenshots are readable on common desktop widths and
  do not contradict current Playground/demo behavior.

#### Phase R6: Localization and translation packaging readiness

**Goal:** make the plugin cleanly translation-ready before the next public
packaging push. Current PHP UI coverage is mostly good, but the repository does
not yet ship a generated POT catalog and a few operator/JavaScript strings still
need explicit localization.

**Scope:**
- Add a `languages/wp-sudo.pot` generation workflow, preferably via
  `wp i18n make-pot`, and document the command.
- Add a Composer script such as `composer i18n:pot` so release prep can refresh
  the POT without remembering the raw command.
- Localize admin-bar countdown JavaScript strings through PHP-provided script
  data, or move them to `@wordpress/i18n` if the JavaScript build/runtime grows
  enough to justify that dependency.
- Localize WP-CLI operator messages, including plural forms with `_n()` where
  counts are displayed.
- Add translator comments for placeholder-heavy strings and any security text
  where word order matters.
- Add a lightweight CI or release-prep check that detects stale/generated POT
  output, or explicitly documents POT regeneration as a required pre-release
  manual step.

**Acceptance criteria:**
- User-facing PHP, JavaScript, and WP-CLI strings are either translatable or
  deliberately excluded because they are technical identifiers, selectors, or
  developer-only diagnostics.
