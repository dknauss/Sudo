# GSD Roadmap Context

> **Current routing note (2026-06-30):** Milestone v4.5 — Session Governance & Admin UX is active. Product release/package truth remains `../docs/release-status.md`; product roadmap truth remains `../docs/ROADMAP.md`. Latest tagged plugin release remains `v4.2.2`.

## Active Milestone: v4.5 — Session Governance & Admin UX

**Goal:** Separate sudo-session revocation from capability administration with an active-session-keyed revocation surface, and correct the governance-coverage panel's multisite behavior.

**Requirements:** 7 total in `.planning/REQUIREMENTS.md`.
**Phases:** 24–25.
**Research posture:** Skipped — internal refactor of existing capability/UI code. Design reviews are mandatory per CLAUDE.md before TDD on each phase (both are non-trivial/security-sensitive).

## Phases

- [x] **Phase 24: Session Revocation UI** — Add Users-list row action for per-user revocation and a UI-surfaced revoke-all with confirmation; remove the Access-tab "Revoke Session" button; provide clear feedback on every path including gated/no-session and missing-cap cases. (completed 2026-07-01)
- [ ] **Phase 25: Governance Coverage Panel Fix** — Correct the panel's capability name on multisite and eliminate super-admin false positives by measuring effective `wp_sudo_can()` access instead of raw `allcaps`.

## Phase Details

### Phase 24: Session Revocation UI

**Goal:** Users with `revoke_wp_sudo_sessions` can revoke any active sudo session from the Users list (not just from the capability-holder table), and the Access-tab table no longer conflates session revocation with capability revocation.

**Depends on:** Nothing (first phase of this milestone)

**Requirements:** REVK-01, REVK-02, REVK-03, REVK-04, REVK-05

**Success Criteria** (what must be TRUE):
1. An authorized admin sees a "Revoke sudo session" row action on the Users list for every user whose `_wp_sudo_expires > time()` — and only those users; the action does not appear on the revoker's own row.
2. An authorized admin can trigger revoke-all active sessions from the UI via a confirmation step (not just via CLI), and the action is absent or disabled when there are no active sessions.
3. Every revocation path — per-user row action and revoke-all — fires `wp_sudo_session_revoked`, enforces the `revoke_wp_sudo_sessions` capability check, and applies the per-revoker rate limit.
4. The Access-tab capability-holder table has no "Revoke Session" column or button; capability administration and session revocation are visually and functionally separate.
5. Every blocked or failed revocation path — operator lacks `revoke_wp_sudo_sessions`, operator has no active sudo session, target has no live session — surfaces a distinct, visible, actionable error message; no path fails silently.

> **REVK-02 scope note:** v4.5 implements **current-site-scoped** revoke-all (authoritative per `24-CONTEXT.md`), not the literal "network-wide" wording in REQUIREMENTS.md. True cross-site/network-wide enumeration is deferred as **REVK-F3**.

**Plans:** 3/3 plans complete

Plans:
- [ ] 24-01-PLAN.md — Shared revoke-all method + factored revocation core (cap + rate limit + audit hook) + CLI rewire; mandatory design-review gate (REVK-02, REVK-03)
- [ ] 24-02-PLAN.md — Users-list row action + revoke-all interstitial confirm + three distinct blocked-path messages via wp_admin_notice() (REVK-01, REVK-02, REVK-03, REVK-05)
- [ ] 24-03-PLAN.md — Remove Access-tab "Revoke Session" button + orphaned AJAX action; full gate + manual UI verification (REVK-04)

**Notes for planning:**
- Mandatory Pre-Implementation Design Review before TDD (per CLAUDE.md — new UI surface, capability checks, multisite/Users-list execution contexts).
- Primary files: `includes/class-admin.php` (row-action wiring, AJAX handler, revoke-all render), `admin/js/wp-sudo-admin.js` (feedback — currently passes `null` as result element so gate/cap errors surface only as `window.alert()`), `includes/class-sudo-session.php` (`handle_revoke_session`, `deactivate`, `revoke_all_active_sessions`).
- `Sudo_Session::is_active()` requires a cookie-bound token; the Users-list enumeration must use the `_wp_sudo_expires > time()` meta query (browser-independent) — keep that distinction explicit.
- On multisite the row action acts on the current site's session meta; super-admin short-circuit in `wp_sudo_can()` must remain respected.
- TDD: unit tests via Brain\Monkey/Mockery; `composer test` + `composer analyse` (PHPStan level 6) + `composer lint` must pass before every commit.

### Phase 25: Governance Coverage Panel Fix

**Goal:** The "Sudo governance coverage" panel accurately identifies the relevant capability for the context and does not flag users who have effective Sudo access via the `wp_sudo_can()` super-admin short-circuit.

**Depends on:** Phase 24 (Access-tab is modified in Phase 24; Phase 25 changes the same file's panel logic — sequential to avoid conflicts)

**Requirements:** GCOV-01, GCOV-02

**Success Criteria** (what must be TRUE):
1. On a multisite network the panel body names `manage_network_options` (not `manage_options`) as the capability that governs access to Sudo settings.
2. On single-site the panel body continues to name `manage_options` — the fix is context-aware, not a blanket rename.
3. A super admin on multisite who has effective access to Sudo settings via `wp_sudo_can()` does not appear in the panel's "cannot access" list.
4. A user who genuinely lacks effective Sudo access (fails `wp_sudo_can()`) still appears in the panel's coverage warning regardless of their raw `allcaps` state.

**Plans:** TBD

**Notes for planning:**
- Mandatory Pre-Implementation Design Review before TDD (per CLAUDE.md — security-sensitive: incorrect panel output mislabels access state; design review must resolve "effective access via `wp_sudo_can()`" vs "raw stored governance state" tension and confirm the fix does not re-introduce the recovery-mode `map_meta_cap` remap the original raw-cap check was written to dodge).
- Primary file: `includes/class-admin.php` (`render_drift_detection_panel()`), `includes/functions-governance.php` (`wp_sudo_can`).
- The fix must inject `$capability` into the body string via `printf`/`sprintf` or branch the message — do not hardcode either capability name as a constant.
- TDD: unit tests via Brain\Monkey/Mockery; `composer test` + `composer analyse` (PHPStan level 6) + `composer lint` must pass before every commit.

## Progress Table

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 24. Session Revocation UI | 3/3 | Complete    | 2026-07-01 |
| 25. Governance Coverage Panel Fix | 0/TBD | Not started | - |

## Milestones

- ◆ **v4.5 — Session Governance & Admin UX** — Phases 24–25 (active).
- ✅ **v4.4.0 — Two Factor Lifecycle Bridge** — Phases 21–23 (completed 2026-06-30). Archive: `.planning/milestones/v4.4.0-ROADMAP.md`.
- ✅ **v4.3.1 — E2E Shard Rebalance** — Phase 20 (completed 2026-06-30; merged via PR #129). Archive: `.planning/milestones/v4.3.1-ROADMAP.md`.
- ✅ **v4.3.0 — Post-.org Readiness Hardening** — Phases 16-19 (archived 2026-06-29). Archive: `.planning/milestones/v4.3.0-ROADMAP.md`.
- ✅ **v4.0.0 — Pre-Public Hardening Baseline** — Phases 11-15 plus 13.1 (archived). See `.planning/MILESTONES.md`.
- ✅ **v2.13 — Security Hardening Sprint** — Phases 01-05 (archived). See `.planning/MILESTONES.md`.
