# Execution Plan (v3.1–v3.3)

*Status: active planning, April 20, 2026*

This plan organizes open roadmap/backlog work into a prioritized sequence for
execution after v3.0.0.

## Priority Stack

## P0 — Release/Timing-Critical (WordPress 7.0 GA window)

1. WordPress 7.0 GA cleanup pack:
   - `Tested up to` updates in readmes.
   - Connectors GA parity verification.
   - Connectors reference split (reference vs security analysis).
   - Registry-aware Connectors matcher follow-up.
   - Remove `handle_err_admin_role()` workaround once core fix is confirmed in GA.

## P1 — Highest Security + Operator Value

2. Internal admin governance, Phase 1:
   - capability scaffolding + compatibility mode.
3. Dedicated Sudo Activity screen (list-table MVP):
   - server-side pagination/filter/sort over `wpsudo_events`.
4. Audit-visibility integrity warnings:
   - explicit warnings when logging visibility is reduced by code-level overrides.

## P2 — Multisite Operator Controls

5. Super-admin widget visibility controls.
6. Network dashboard widget (cross-site aggregation).
7. Cross-site session revocation.

## P3 — Governance Hardening Rollout

8. Internal admin governance, Phase 2:
   - explicit grants + guided migration.
9. Internal admin governance, Phase 3:
   - strict post-migration defaults with rollback path.

## P4 — Architecture / Scale

10. Session-store architecture implementation follow-up:
    - execute recommended Option 1 (authoritative table + usermeta shadow).

## P5 — UX / Platform Expansion

11. Gutenberg block-editor sudo UX integration.
12. Network policy hierarchy (after multisite operator controls stabilize).

## P6 — Long-Horizon Design Backlog

13. Client-side modal challenge.
14. REST API sudo grant endpoint for headless clients.
15. Per-session sudo isolation (`WP_Session_Tokens` integration).
16. SSO/SAML/OIDC provider framework.

## Phased GSD Execution Plan

## Phase 0 — Backlog hygiene and execution baseline

- Reconcile roadmap sections that still describe already-shipped items as open.
- Establish one canonical execution tracker for v3.1–v3.3.
- Define acceptance criteria for each phase before implementation starts.

## Phase 1 — Governance foundation (P1.2)

- Implement centralized capability checks (`manage_wp_sudo`,
  `view_wp_sudo_activity`, `revoke_wp_sudo_sessions`).
- Keep compatibility fallback to current `manage_options` /
  `manage_network_options` model for upgrades.
- Add read-only Access Overview in settings.

Exit criteria:
- No access regressions for existing installs.
- Unit/integration coverage for strict and compatibility paths.

## Phase 2 — Sudo Activity screen MVP (P1.3)

- Add a dedicated list-table activity screen with pagination/filter/sort.
- Keep dashboard widget as preview and link to full activity screen.
- Use lean query shape and capped page sizes for predictable performance.

Exit criteria:
- Operators can review and triage full recent activity without dashboard limits.
- Performance remains acceptable on high-volume event tables.

## Phase 3 — Audit-visibility integrity warnings (P1.4)

- Detect and surface reduced event visibility (e.g., passed-event logging disabled).
- Show warnings in settings and activity surfaces with clear remediation text.

Exit criteria:
- Visibility-reduction states are explicit and auditable, not implicit.

## Phase 4 — Multisite operator controls (P2)

- Add super-admin widget visibility modes.
- Add network dashboard aggregation for cross-site visibility.
- Add cross-site session revocation actions.

Exit criteria:
- Super admins can monitor and respond across network sites without per-site pivoting.

## Phase 5 — Governance hardening rollout (P3)

- Implement guided migration to explicit grants for new + upgraded installs.
- Enable strict-mode defaults only after explicit migration completion.
- Keep documented rollback/break-glass path.

Exit criteria:
- Least-privilege governance model is operable without lockout risk.

## Phase 6 — Session-store architecture implementation (P4)

- Implement Option 1 from `docs/session-store-evaluation.md`.
- Migrate hot-path session reads to table-backed model with compatibility shadow.
- Validate rollback behavior and multisite semantics.

Exit criteria:
- Measurable hot-path read reduction.
- No regressions in session enforcement, audit hooks, or cleanup flows.

## Phase 7 — UX/platform expansions (P5)

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
