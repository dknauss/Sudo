# Requirements: WP Sudo — v4.5 Session Governance & Admin UX

**Defined:** 2026-06-30
**Core Value:** Every destructive WordPress admin action requires proof that the person at the keyboard is still the authenticated user — not a hijacked session, XSS payload, or unattended browser.

## v4.5 Requirements

Requirements for the Session Governance & Admin UX milestone. Each maps to a roadmap phase.

### Session Revocation

- [x] **REVK-01**: An authorized admin can revoke another user's active sudo session via a "Revoke sudo session" Users-list row action, shown only for users in the active-session set (the same `_wp_sudo_expires > time()` enumeration as the existing "Sudo Active" filter). On multisite it acts on the current site's session; the operator's own row is excluded because the admin bar already ends one's own session.
- [x] **REVK-02**: An authorized admin can revoke all active sudo sessions **on the current site** from the UI, behind a confirmation step. Single-user revocation acts immediately; only revoke-all confirms. (Scope decision, per 24-CONTEXT.md: v4.5 revoke-all is current-site-scoped — the same `_wp_sudo_expires > time()` enumeration the CLI and the per-site "Sudo Active" view use. True cross-site/network-wide revoke-all is deferred to REVK-F3.)
- [x] **REVK-03**: Session revocation honors existing guardrails on every UI path — the `revoke_wp_sudo_sessions` capability, the per-revoker rate limit, and the `wp_sudo_session_revoked` audit hook.
- [x] **REVK-04**: The Access-tab capability-holder table no longer offers per-holder session revocation (the "Revoke Session" button is removed). That table governs capabilities only; session revocation lives on the Users list.
- [x] **REVK-05**: Every session-revocation path gives the operator clear, visible success and failure feedback — including a distinct, actionable message when the action is blocked because the operator has no active sudo session (the action is itself gated) or lacks `revoke_wp_sudo_sessions`. Never a silent no-op.

### Governance Coverage Panel

- [ ] **GCOV-01**: The "Sudo governance coverage" panel names the correct admin capability for the context (`manage_options` on single-site, `manage_network_options` on multisite).
- [ ] **GCOV-02**: The coverage panel measures effective `wp_sudo_can()` access rather than raw `allcaps` — correct on single-site (listing unchanged) and multisite (super admins with effective access are not listed).

## Future Requirements

Deferred to a later milestone. Tracked but not in current roadmap.

### Session Revocation

- **REVK-F1**: Access-tab "Active Sessions" panel — an in-settings list of all session-holders with per-row revoke (v4.5 uses the Users-list row action instead).
- **REVK-F2**: Session metadata in the revoke UI — expiry countdown and bound context shown alongside the revoke control.
- **REVK-F3**: Cross-site selective session revocation beyond the network-wide "revoke all" (e.g. per-site revocation from network admin).

## Out of Scope

Explicitly excluded for v4.5. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Access-tab "Active Sessions" panel | Chose the Users-list row action for v4.5 — it reuses the existing "Sudo Active" enumeration and lives where admins already manage users. |
| Session metadata in revoke UI (countdown, bound IP) | Not required to perform a revocation; adds UI surface and potential info exposure — deferred. |
| Cross-site selective revocation | Network-wide revoke-all covers the bulk case; per-site selection is a larger multisite feature. |
| REST API session-revocation endpoint | Feature work; revocation stays an authenticated admin-UI/CLI action. |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| REVK-01 | Phase 24 | Complete |
| REVK-02 | Phase 24 | Complete |
| REVK-03 | Phase 24 | Complete |
| REVK-04 | Phase 24 | Complete |
| REVK-05 | Phase 24 | Complete |
| GCOV-01 | Phase 25 | Pending |
| GCOV-02 | Phase 25 | Pending |

**Coverage:**
- v4.5 requirements: 7 total
- Mapped to phases: 7
- Unmapped: 0 ✓

---
*Requirements defined: 2026-06-30*
*Last updated: 2026-07-01 — Phase 24 plan 03 complete: REVK-04 marked Complete (Access-tab "Revoke Session" button removed, orphaned AJAX path consolidated). Phase 24 is now fully complete (REVK-01..05). Manual browser-based UI verification of REVK-01..05 remains deferred to a browser-capable session — see STATE.md Pending Todos.*
