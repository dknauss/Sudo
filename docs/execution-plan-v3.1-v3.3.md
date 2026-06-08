# Execution Plan (v3.1–v3.3)

*Status: active planning, April 20, 2026. Revised April 20, 2026 to reflect the clean-launch framing for internal admin governance (no public install base, strict-from-day-one in v3.1 rather than three-phase migration). Revised June 7, 2026 to put post-v3.1.3 security-review remediation ahead of lower-priority feature work.*

This plan organizes open roadmap/backlog work into a prioritized sequence for
execution after v3.0.0.

## Priority Stack

## P0 — Release/Timing-Critical (WordPress 7.0 GA window)

1. Post-v3.1.3 security remediation pack:
   - Fix 2FA failure-counter reset before second-factor success.
   - Harden WPGraphQL Limited-mode fallback classification for decoded,
     escaped, batched, and persisted-operation request shapes.
   - Update security documentation to name the exact WPGraphQL fallback limits.
   - Treat as patch-release eligible if tests validate the auth/surface fixes.
2. WordPress 7.0 GA cleanup pack:
   - `Tested up to` updates in readmes.
   - Connectors GA parity verification.
   - Connectors reference split (reference vs security analysis).
   - Registry-aware Connectors matcher follow-up.
   - Remove `handle_err_admin_role()` workaround once core fix is confirmed in GA.

## P1 — Highest Security + Operator Value

3. Internal admin governance, Phase 1:
   - Ship strict-capability mode as the default.
   - Full capability surface: `manage_wp_sudo`, `view_wp_sudo_activity`,
     `export_wp_sudo_activity`, `revoke_wp_sudo_sessions`.
   - `sudo_can()` helper, `options.wp_sudo_access` gated rule, Access tab with
     drift detection, "last manager" guard, `WP_SUDO_RECOVERY_MODE`
     break-glass, audit hooks for all access-model transitions.
   - See [`docs/internal-admin-governance-spec.md`](internal-admin-governance-spec.md).
4. Request-stash replay/data-minimization follow-up:
   - Add pattern-based redaction for non-standard secret names.
   - Add custom-rule metadata for redacted, replay-safe, and non-replayable
     fields/actions.
5. Dedicated Sudo Activity screen (list-table MVP):
   - server-side pagination/filter/sort over `wpsudo_events`.
6. Audit-visibility integrity warnings:
   - explicit warnings when logging visibility is reduced by code-level overrides.

## P2 — Multisite Operator Controls

7. Super-admin widget visibility controls.
8. Network dashboard widget (cross-site aggregation).
9. Cross-site session revocation.

## P3 — Governance Polish (optional, v3.2)

10. Internal admin governance, Phase 2:
   - Integrity warnings when effective visibility is broader than intended.
   - Opt-in 2FA-enrollment requirement for `manage_wp_sudo` holders.
   - Audit visibility on governance-mode transitions (`strict` ↔ `compatibility`).
   - External Audit Mode: let operators route Sudo events solely to Stream or
     WSAL bridges and suppress `wpsudo_events` writes, with bridge-presence
     preflight and integrity warnings. See
     [`docs/external-audit-mode-spec.md`](external-audit-mode-spec.md).

   Phase 2 is scope-bounded and non-blocking; none of it is required to close
   the governance story, but real delegation patterns will surface edge cases
   that read-only drift detection misses.

## P4 — Architecture / Scale (conditional, not scheduled)

11. Session-store architecture implementation follow-up:
    - Execute recommended Option 1 (authoritative table + usermeta shadow)
      from [`docs/session-store-evaluation.md`](session-store-evaluation.md).
    - **Conditional on reaching Tier 2 in practice** (≥ ~1,000 concurrently
      sudo-active users per site; see the Scale and Load Analysis section of
      the session-store evaluation). Not scheduled for a specific version.

## P5 — UX / Platform Expansion

12. Gutenberg block-editor sudo UX integration.
13. Network policy hierarchy (after multisite operator controls stabilize).

## P6 — Long-Horizon Design Backlog

14. Client-side modal challenge.
15. REST API sudo grant endpoint for headless clients.
16. Per-session sudo isolation (`WP_Session_Tokens` integration).
17. SSO/SAML/OIDC provider framework.

## Phased GSD Execution Plan

## Phase 0 — Backlog hygiene and execution baseline

- Reconcile roadmap sections that still describe already-shipped items as open.
- Establish one canonical execution tracker for v3.1–v3.3.
- Define acceptance criteria for each phase before implementation starts.

## Phase 1 — Post-v3.1.3 security remediation pack (P0.1)

Ship the newly found auth and surface hardening fixes before lower-priority
feature work.

- 2FA lockout fix:
  - Do not clear failed-attempt counters after password success when 2FA is
    still pending.
  - Reset attempts only after final sudo activation succeeds.
  - Add tests for repeated `password -> bad 2FA -> password` cycles reaching
    lockout, plus no-2FA success behavior.
- WPGraphQL fallback classifier fix:
  - Decode JSON/form-encoded request payloads before mutation detection.
  - Treat batched payloads conservatively: any mutation gates the whole request.
  - Define fail-closed behavior for unknown persisted operations where strict
    mutation blocking is required.
  - Update `docs/security-model.md` and tests for escaped `mutation`, batches,
    classifier precedence, and persisted-operation caveats.

Exit criteria:
- `composer test:unit`, `composer lint`, and `composer analyse` pass.
- Integration coverage exists for the cross-class auth path or a documented
  reason explains why a focused unit test is sufficient.
- No browser E2E required unless the implementation changes challenge/replay UI.

## Phase 2 — Governance foundation (P1.3)

Ship strict governance as the default in a single coordinated release.

- Capability constants: `manage_wp_sudo`, `view_wp_sudo_activity`,
  `export_wp_sudo_activity`, `revoke_wp_sudo_sessions`.
- Centralized `sudo_can()` helper (with super-admin short-circuit and
  compatibility-mode fallback).
- `wp_sudo_governance_mode` option (default `strict`; `compatibility`
  available as opt-in).
- `options.wp_sudo_access` gated rule covering grants, revokes, and session
  revocations.
- Access tab with drift detection panel and "last manager" guard.
- `WP_SUDO_RECOVERY_MODE` break-glass constant with logged usage.
- Audit hooks: `wp_sudo_capability_granted`, `wp_sudo_capability_revoked`,
  `wp_sudo_session_revoked`, `governance.recovery_mode` event type.
- Rate limit on session revocations (≤ 10/hour per user).
- Replace all direct `current_user_can('manage_options')` /
  `current_user_can('manage_network_options')` checks in Sudo governance
  surfaces with `sudo_can()`.

Exit criteria:
- Zero direct `manage_options` / `manage_network_options` checks remain in
  governance-sensitive surfaces.
- Unit + integration coverage for strict and compatibility paths, including
  multisite super-admin behavior and the "last manager" guard.
- Query-invariant test: site-scope `view_wp_sudo_activity` cannot read
  cross-site events from `wpsudo_events`.
- Recovery-mode path is documented in FAQ and surfaces a permanent admin
  notice while active.

## Phase 3 — Request-stash replay/data-minimization follow-up (P1.4)

- Add pattern-based redaction for non-standard secret field names.
- Add custom-rule metadata for redacted fields, replay-safe fields, and actions
  that should not replay POST bodies.
- Add explicit failure behavior when redaction makes a replay unsafe.

Exit criteria:
- Unit tests cover camelCase, snake_case, dashed, nested, and array secret keys.
- Replay tests prove built-in safe forms still work and unsafe custom forms fail
  with a clear operator-facing message.

## Phase 4 — Sudo Activity screen MVP (P1.5)

- Add a dedicated list-table activity screen with pagination/filter/sort.
- Keep dashboard widget as preview and link to full activity screen.
- Use lean query shape and capped page sizes for predictable performance.

Exit criteria:
- Operators can review and triage full recent activity without dashboard limits.
- Performance remains acceptable on high-volume event tables.

## Phase 5 — Audit-visibility integrity warnings (P1.6)

- Detect and surface reduced event visibility (e.g., passed-event logging disabled).
- Show warnings in settings and activity surfaces with clear remediation text.

Exit criteria:
- Visibility-reduction states are explicit and auditable, not implicit.

## Phase 6 — Multisite operator controls (P2)

- Add super-admin widget visibility modes.
- Add network dashboard aggregation for cross-site visibility.
- Add cross-site session revocation actions.

Exit criteria:
- Super admins can monitor and respond across network sites without per-site pivoting.

## Phase 7 — Governance polish (P3, v3.2, optional)

Additive improvements that benefit from production field data from Phase 1.

- Integrity warnings when effective visibility is broader than intended.
- Opt-in 2FA-enrollment requirement for `manage_wp_sudo` holders.
- Audit visibility for governance-mode transitions (`strict` ↔ `compatibility`).
- External Audit Mode for Stream / WSAL operators. See
  [`docs/external-audit-mode-spec.md`](external-audit-mode-spec.md).
  - New `wp_sudo_external_audit` option (`off` default, `stream`, `wsal`).
  - Preflight: refuse activation when the chosen bridge is not loaded.
  - Event-store writes short-circuit while audit hooks keep firing.
  - Dashboard widget Recent Events panel swaps to a bridge-status tile;
    Active Sessions and Policy Summary panels unchanged.
  - Integrity warning when bridge deactivates while mode is active.
  - New audit hooks: `wp_sudo_external_audit_enabled`,
    `wp_sudo_external_audit_disabled`; event type
    `governance.external_audit_toggled`.
  - Gated by `options.wp_sudo_access` (changing audit destination is a
    privilege-sensitive operation).

Exit criteria:
- Drift-detection panel (Phase 1) can fire active warnings, not only passive
  display.
- Operators can opt in to 2FA requirements for management caps without
  breaking single-site installs that don't use a 2FA plugin.
- Stream and WSAL operators can fully delegate Sudo audit without losing
  session or policy visibility in the dashboard widget, and cannot silently
  lose audit coverage if the bridge is deactivated.

Phase 7 is non-blocking; the governance model is complete after Phase 2.

## Phase 8 — Session-store architecture (P4, conditional, not scheduled)

- Implement Option 1 from
  [`docs/session-store-evaluation.md`](session-store-evaluation.md)
  (authoritative session table + usermeta shadow).
- Migrate hot-path session reads to the table-backed model; keep usermeta
  shadow for one minor-version soak period before retiring.
- Validate rollback behavior and multisite semantics.

Exit criteria:
- Measurable hot-path read reduction on deployments at Tier 2+ scale.
- No regressions in session enforcement, audit hooks, or cleanup flows.

**Conditional execution.** This phase is not scheduled for a specific
version. The scale-tier analysis in the session-store evaluation places the
inflection point around ~1,000 concurrently sudo-active users per site.
Execute Phase 8 only when real deployments approach that threshold; ship
interim transient-cache mitigations (already done for widget and Users-list
count) if they arise sooner.

## Phase 9 — UX/platform expansions (P5)

- Implement Gutenberg reauth UX design.
- Re-evaluate and implement network policy hierarchy.

Exit criteria:
- Stable editor flow under gated operations.
- Predictable multisite policy governance outcomes.

## Continuous quality lane (parallel)

Across all phases:

- keep CI green (`test`, `lint`, static analysis),
- expand Playwright coverage where transport/UI changes occur,
- run release-signoff checks for WP 7.0 GA milestones,
- defer mutation testing expansion until architecture refactors settle.

## Source mapping

- Primary roadmap source: [`docs/ROADMAP.md`](ROADMAP.md)
- Governance spec: [`docs/internal-admin-governance-spec.md`](internal-admin-governance-spec.md)
- Session-store options: [`docs/session-store-evaluation.md`](session-store-evaluation.md)
- External Audit Mode spec: [`docs/external-audit-mode-spec.md`](external-audit-mode-spec.md)
