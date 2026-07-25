---
status: current
applies_to: 4.8.x
last_verified: 2026-07-25
canonical_for: forward roadmap (Now / Next / Later / Non-goals)
---

# Roadmap

Forward-looking only. Shipped work lives in [`CHANGELOG.md`](../CHANGELOG.md); open
work is tracked as GitHub issues; design analyses live in their own docs. Current
release state is canonical in [`release-status.md`](release-status.md). Near-term work
is grouped in the [**v5.0.0** milestone](https://github.com/dknauss/Sudo/milestone/1);
the full backlog is in the [issues](https://github.com/dknauss/Sudo/issues), labeled
`priority: high` / `medium` / `low`.

## Now

- **Network-wide role/capability lockdown sweep** ([#219](https://github.com/dknauss/Sudo/issues/219)) —
  the 4.8.0 lockdown audit covers only the current blog
  (`Role_Audit::collect_current_state()` uses `get_current_blog_id()`; `diff()`
  iterates a single site), so subsite privilege drift is invisible to one
  `wp sudo manifest diff` / Site Health / cron run. Walk the network (manifest sites,
  or all blogs via `switch_to_blog()`), keep the cache-bypass reads per-blog, and
  consider a `--site=<id|url>` scope flag with batching for large networks.
  `priority: high`, in the [v5.0.0 milestone](https://github.com/dknauss/Sudo/milestone/1); documented MVP limitation until shipped.

## Next

- **Cross-site (network-admin) session revocation** ([#239](https://github.com/dknauss/Sudo/issues/239), `priority: high`, v5.0.0) —
  network revoke-all / by-user / by-site for incident response, reusing
  `Sudo_Session::deactivate()`.
- **Session-store architecture** — evaluate and likely implement a dedicated
  sudo-session table (authoritative table + usermeta shadow writes). Design:
  [`session-store-evaluation.md`](session-store-evaluation.md). Snicco Fortress's
  per-token session-row model (one row per token via the `session_token_manager`
  drop-in) independently validates this direction — see the design-borrowing
  assessment in
  [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md#design-borrowing-assessment-fortress-session--sudo-patterns).
- **Sudo Activity screen + export surface** — a dedicated list-table Activity admin
  screen (search, sortable columns, CSV export with capability + nonce checks) that
  gives the reserved `export_wp_sudo_activity` capability a UI surface. Keep it lean
  (recent events, short retention) and prepare it for External Audit Mode delegation.
  Include audit-visibility integrity warnings (flag when local passed-event logging is
  disabled or delegated coverage is missing).
- **Multisite terminology + coverage pass** — remaining Core-Trac-alignment work:
  standardize "network administrator" vs. "super admin"; review network-level
  gated-action coverage. Maps to Trac [#20140](https://core.trac.wordpress.org/ticket/20140).
- **Scoped single-user recovery form** ([#240](https://github.com/dknauss/Sudo/issues/240), `priority: high`, v5.0.0) —
  `define( 'WP_SUDO_RECOVERY_MODE', <user> )`, plus a `Site_Health::test_recovery_mode()`
  status; the open follow-up to the hardened break-glass (Phase R3).
- **Test-scaffolding hardening** — blueprint rot-guard smoke lane (do first),
  tag-pinned blueprint copies at each release, and run the release environment matrix
  every release.
- **In-editor sudo session-status indicator — client UI** ([#262](https://github.com/dknauss/Sudo/issues/262), `priority: low`) —
  complete the half-shipped indicator. The server-side feed (localized `remaining`,
  gated on `is_active()`) and a design brief
  ([`in-editor-session-indicator-design-brief.md`](../.planning/in-editor-session-indicator-design-brief.md))
  shipped in #204; the client UI remains — a `core/notices` snackbar baseline (WP 6.4+
  floor) plus a feature-detected `PluginSidebar`/header countdown (WP 6.6+, degrading to
  the snackbar). Distinct from the shipped in-editor reauth *modal* (Milestones A/B,
  v4.6/4.7).

## Later (need design work)

- **Client-side modal challenge** (GitHub-style inline reauth) — explicitly deferred:
  design-heavy, no security gain over stash → challenge → replay. If built, re-evaluate
  the password-first OS-autofill decision (see
  [`security-model.md`](security-model.md#reauthentication-flow-password-first-design-rationale)).
- **REST sudo-grant endpoint** (`POST /wp/v2/sudo`) for headless clients.
- **Per-session / device sudo isolation** via `WP_Session_Tokens` — deferred:
  architectural, not a hardening item.
- **Per-rule / per-action sudo TTL** ([#269](https://github.com/dknauss/Sudo/issues/269), `priority: low`) —
  a shorter sudo window for the highest-risk
  rules (e.g. `user.delete`, `options.critical`) than for routine ones (e.g.
  `plugin.activate`), instead of one global `session_duration`. Borrowed (adapted to
  WP Sudo's per-rule, role-agnostic model) from Fortress's per-capability timeout
  tiering; see the design-borrowing assessment in
  [`sudo-architecture-comparison-matrix.md`](sudo-architecture-comparison-matrix.md#design-borrowing-assessment-fortress-session--sudo-patterns).
  Small, unscheduled; needs a design pass on how a per-rule TTL interacts with an
  already-active broader window before any implementation.
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

## Backlog

Feature/enhancement candidates and open remediation items are tracked as
**GitHub issues**, not in this file. See the
[open issues](https://github.com/dknauss/Sudo/issues) — filter by
[`enhancement`](https://github.com/dknauss/Sudo/labels/enhancement) or
[`security`](https://github.com/dknauss/Sudo/labels/security).

The former roadmap backlog (feature backlog, multisite network-admin tools, and
internal-admin governance) plus the open post-v3.1.3 remediation items were migrated
to issues [#238](https://github.com/dknauss/Sudo/issues/238)–[#257](https://github.com/dknauss/Sudo/issues/257),
alongside the earlier [#219](https://github.com/dknauss/Sudo/issues/219) (network-wide
lockdown sweep) and [#67](https://github.com/dknauss/Sudo/issues/67) (lockout blueprint).
Shipped work is in the [CHANGELOG](../CHANGELOG.md); the archived v3.1–v3.3
security/governance execution record is
[`archive/execution-plan-v3.1-v3.3.md`](archive/execution-plan-v3.1-v3.3.md).
