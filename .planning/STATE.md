---
gsd_state_version: 1.0
milestone: v4.5
milestone_name: — Session Governance & Admin UX
status: verifying
stopped_at: "Phase 24 plan 03 (Access-tab "Revoke Session" button removal + orphaned AJAX consolidation) fully implemented and committed across 2 commits (c40ddde, 4fc0203); automated gate green (941 tests/2831 assertions, PHPStan L6, Psalm, PHPCS, verify:metrics synced via af07dc1); Phase 24 is now feature-complete (3/3 plans, all REVK-01..05 requirements implemented). Manual browser-based UI verification of REVK-01..05 (Task 3 how-to-verify) is OUTSTANDING/deferred to a browser-capable session. SUMMARY.md and STATE/ROADMAP/REQUIREMENTS doc updates left uncommitted pending orchestrator commit."
last_updated: "2026-07-01T15:45:21.733Z"
last_activity: 2026-07-01 — Phase 24 plan 03 (Access-tab button removal, orphaned AJAX consolidation) implemented via TDD across 2 commits, automated gate green; manual UI verification deferred
progress:
  total_phases: 2
  completed_phases: 1
  total_plans: 3
  completed_plans: 3
  percent: 100
---

## Current Position

Phase: 24 — Session Revocation UI (complete, pending deferred manual verification)
Plan: 03 — Access-tab cleanup + orphaned AJAX removal complete; Phase 24 fully implemented (3/3 plans)
Status: Plan 24-03 fully implemented and committed (2 commits: c40ddde, 4fc0203); automated gate green (composer test 941/2831 assertions, analyse PHPStan L6 + Psalm, lint, verify:metrics synced via af07dc1); SUMMARY.md and STATE/ROADMAP/REQUIREMENTS doc updates pending orchestrator commit. Manual browser-based UI verification of REVK-01..05 (Task 3's how-to-verify) is OUTSTANDING — deferred to a future browser-capable session by explicit user choice.
Progress: [██████████] 100% of Phase 24 (3/3 plans complete); Phase 25 (Governance Coverage Panel Fix) not yet started
Last activity: 2026-07-01 — Phase 24 plan 03 (Access-tab button removal, orphaned AJAX consolidation) implemented via TDD across 2 commits, automated gate green; manual UI verification deferred
Branch: `gsd/v4.4.0-two-factor-lifecycle-bridge` — predecessor v4.4.0 work committed locally; new branch expected for v4.5 work.
Origin: session-revocation UI review on 2026-06-30 (`.planning/todos/pending/2026-06-30-session-revocation-surfaces.md`).
Product release state: Latest tagged plugin release remains `v4.2.2`; v4.5 is a GSD milestone and does not create a product release tag unless release metadata is intentionally bumped.
Open threads: WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Patchstack paid-fixture/manual runtime testing remains fixture-blocked and out of runtime-claim scope.
Resume file: .planning/phases/24-session-revocation-ui/24-CONTEXT.md

## Project Reference

Canonical current facts:

- `docs/e2e-runtime-review.md` — runtime evidence, Phase 20 implementation, validation run, and keep decision.
- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — product roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.
- `.planning/MILESTONES.md` — archived GSD milestone history.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** v4.5 — Session Governance & Admin UX. Latest tagged release is **4.2.2** (see `docs/release-status.md`). WordPress.org submission (`docs/wporg-submission-checklist.md`) is delayed/on hold, but readiness should be maintained.

## Active Priorities

1. Keep WordPress.org readiness maintained while submission remains delayed/on hold.
2. Keep Patchstack runtime testing as fixture-blocked/manual-test work until a paid fixture exists.
3. Phase 24 and Phase 25 both require a Pre-Implementation Design Review before TDD (per CLAUDE.md — new UI surface, capability checks, security-sensitive panel logic).

## Accumulated Context

- v4.5 roadmap created 2026-06-30: Phase 24 (Session Revocation UI) covers REVK-01..05; Phase 25 (Governance Coverage Panel Fix) covers GCOV-01..02.
- Phase 24 plan 03 complete 2026-07-01: Access-tab "Revoke Session" button/column removed and the orphaned wp_ajax_handle_revoke_session registration, AJAX wrapper, and dead JS handler deleted across 2 commits (941 tests green). REVK-04 now satisfied alongside REVK-01/02/03/05 (already Complete). Phase 24 is feature-complete (3/3 plans) with the automated gate green; manual browser-based UI verification of REVK-01..05 is DEFERRED to a future browser-capable session — see Pending Todos.
- Phase 24 plan 02 complete 2026-06-30: Users-list "Revoke sudo session" row action, revoke-all button + interstitial confirm, and centralized wp_admin_notice() result-code map shipped across 4 commits (943 tests green). REVK-01 now fully satisfied alongside REVK-02/03/05 (already Complete from plan 01).
- v4.4.0 milestone completed 2026-06-30 (Phases 21–23 all complete). Archive: `.planning/milestones/v4.4.0-ROADMAP.md`.
- Phase 23 context is complete in `.planning/phases/23-bridge-documentation-and-release-posture-closure/23-CONTEXT.md`; defaults captured: update Two Factor integration/ecosystem docs, refresh current metrics, add concise unreleased/current-main notes if warranted, preserve Patchstack fixture-blocked wording, and avoid version/tag/submission actions.
- Phase 22 has two verified execution plans: `22-01-PLAN.md` for design-reviewed TDD implementation and `22-02-PLAN.md` for required validation/scope-boundary checks.
- Phase 22 verification passed in `.planning/phases/22-profile-provider-guard-implementation/22-VERIFICATION.md` with 9/9 must-haves verified.
- Phase 22 plan 22-01 implemented `two_factor.profile_provider_lifecycle` in the optional Two Factor lifecycle bridge with RED/GREEN unit coverage; targeted bridge tests, `composer test`, `composer analyse`, and `composer lint` passed.
- Profile-provider guard replay now preserves source-verified core profile fields so mixed profile plus Two Factor saves replay as complete core profile updates after the WP Sudo challenge.
- Phase 21 source evidence is complete in `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md`; upstream WordPress/two-factor commit `c515462d51ac92941685e39293673c08538e16c8` was checked 2026-06-30.
- v4.3.1 is archived in `.planning/milestones/v4.3.1-ROADMAP.md` and `.planning/milestones/v4.3.1-REQUIREMENTS.md`.
- Phase 20 execution files are archived in `.planning/milestones/historical-phases/20-baseline-e2e-shard-rebalance/`.
- v4.3.0 is archived in `.planning/milestones/v4.3.0-ROADMAP.md` and `.planning/milestones/v4.3.0-REQUIREMENTS.md`.
- Current test and size counts are centralized in `docs/current-metrics.md`.
- WordPress 7.0 GA shipped May 20, 2026; package metadata says `Tested up to: 7.0`.
- This plugin is not currently published to the WordPress.org plugin repository.
- PR #129 validation run: https://github.com/dknauss/Sudo/actions/runs/28406226487.

### Pending Todos

- **Outstanding: manual UI verification of REVK-01..05 deferred to a browser session.** Phase 24's automated gate (composer test/analyse/lint/verify:metrics) is green, but the Task 3 manual browser-based UI verification steps in `24-03-PLAN.md` (row-action visibility/behavior for cap-holder with/without an active session, no-cap absence, revoke-all confirm/count, distinct per-path notices) were NOT performed against a live Studio/wp-env multisite — deferred by explicit user choice ("Proceed on automated"). Run these steps from a browser-capable session (`claude-playwright` / `claude-browser-handoff`) before treating REVK-01..05 as fully verified end-to-end. See `.planning/phases/24-session-revocation-ui/24-03-SUMMARY.md` "Outstanding: Manual UI Verification".
- Patchstack runtime testing remains pending: acquire a paid Patchstack-enabled fixture plus manual challenge/lifecycle runtime tests before making runtime support claims.
- Design review required before Phase 25 TDD: resolve "effective `wp_sudo_can()` access" vs "raw stored governance state" tension and confirm the fix does not re-introduce the recovery-mode `map_meta_cap` remap.

## Key Decisions

- GitHub Actions job timestamps are the source of truth for E2E runtime tuning decisions; no local Playwright/browser timings should drive rebalances.
- Keep WordPress.org submission delayed/on hold while maintaining readiness.
- Do not create product release tags from GSD milestone names unless plugin version metadata/readme stable tag have been intentionally bumped.
- Revocation surface for v4.5 = Users-list row action (not an Access-tab panel); chosen because it reuses existing `_wp_sudo_expires > time()` enumeration and puts revocation where admins already manage users.
- Network-wide revoke-all gains a UI confirmation step in v4.5 (was CLI-only); single-user revocation continues to act immediately.
- Access-tab capability-holder table loses the "Revoke Session" button in Phase 24; session revocation and capability administration become separate concerns.
- Coverage panel must measure effective `wp_sudo_can()` access in Phase 25, not raw `allcaps`, to eliminate multisite super-admin false positives.
- `Sudo_Session::is_active()` requires a cookie-bound token; the Users-list enumeration must use `_wp_sudo_expires > time()` meta query (browser-independent) — keep that distinction explicit in Phase 24 code.
- v4.4.0 scope targeted upstream WordPress Two Factor profile-provider lifecycle changes; Patchstack remains fixture-blocked and out of implementation scope.
- Keep the Two Factor profile lifecycle guard in the optional bridge and fail open when upstream lifecycle data cannot prove a meaningful change.
- Document Two Factor integration as three distinct surfaces (challenge-time, REST lifecycle, classic profile-provider lifecycle) with the optional bridge serving surfaces 2 and 3.
- Keep Patchstack fixture-blocked/manual-test-only in all docs; no runtime support claims added in Phase 23.
- Do not bump WP_SUDO_VERSION, plugin header Version, readme.txt Stable tag, or create a product git tag at any v4.5 phase; latest tagged release remains 4.2.2.
- `Sudo_Session::revoke_all_active_sessions()` enumerates via a `_wp_sudo_expires > time()` (NUMERIC) meta_query, not the old meta-key-presence form, so revoke-all acts on exactly the live set the "Sudo Active (N)" count advertises (Phase 24 plan 01).
- `Admin::revoke_session_core()` check order is cap -> self_target -> target_expired -> rate_limited -> success, with cap checked first so a capless caller can never distinguish target_expired from no_cap (Phase 24 plan 01).
- The bulk revoke-all enumerator stays gate-free (no cap/rate logic in `Sudo_Session`) so WP-CLI can call it un-gated; the UI revoke-all's own cap+rate wrapper is deferred to Phase 24 plan 02 (Phase 24 plan 01).
- Phase 24 plan 02's revoke-all UI perform handler implements the cap+rate wrapper directly (not in `Sudo_Session`), confirming design-review objection C: one rate slot is consumed per batch, not per revoked session.
- The Users-list "Revoke sudo session" row action is shown even when the operator has no active sudo session of their own; clicking then fails with a distinct actionable message rather than hiding the action (Phase 24 plan 02).
- A target whose session expires mid-batch during revoke-all is silently skipped by the enumeration (not an error); the target_expired outcome is a per-user-path concern surfaced only by the row-action handler, not a revoke-all error path (Phase 24 plan 02).
- `Admin::select_revoke_result_notice()` returns `null` for unknown/absent result codes rather than a generic fallback message, so no code can render a fabricated notice (Phase 24 plan 02, REVK-05).
- Access-tab capability-holder table's "Revoke Session" button and the orphaned `wp_ajax_handle_revoke_session` registration/wrapper/JS handler are removed (Phase 24 plan 03); `revoke_session_core()` and the two plan-02 admin-post handlers are the sole live revocation entry points.
- Phase 24's manual browser-based UI verification (REVK-01..05) is deferred to a future browser-capable session; the automated gate (tests/PHPStan/Psalm/lint/metrics) is the only verification completed for plan 24-03 (Phase 24 plan 03).

## Performance Metrics

| Date | Phase | Plan | Duration | Tasks | Files |
|------|-------|------|----------|-------|-------|
| 2026-06-30 | 22-profile-provider-guard-implementation | 01 | 19min | 3 | 5 |
| 2026-06-30 | 22-profile-provider-guard-implementation | 02 | 1min | 2 | 1 |
| 2026-06-30 | 23-bridge-documentation-and-release-posture-closure | 01 | 8min | 3 | 5 |
| 2026-06-30 | 24-session-revocation-ui | 01 | 25min | 2 | 6 |
| 2026-06-30 | 24-session-revocation-ui | 02 | ~50min | 3 | 3 |
| 2026-07-01 | 24-session-revocation-ui | 03 | unspecified | 2 | 3 |

## Last Session

- **Stopped at:** Phase 24 plan 03 (Access-tab "Revoke Session" button removal + orphaned AJAX consolidation) fully implemented and committed across 2 commits (c40ddde, 4fc0203); automated gate green (941 tests/2831 assertions, PHPStan L6, Psalm, PHPCS, verify:metrics synced via af07dc1); Phase 24 is now feature-complete (3/3 plans, all REVK-01..05 requirements implemented). Manual browser-based UI verification of REVK-01..05 (Task 3 how-to-verify) is OUTSTANDING/deferred to a browser-capable session. SUMMARY.md and STATE/ROADMAP/REQUIREMENTS doc updates left uncommitted pending orchestrator commit.
- **Next:** Orchestrator commits the plan 24-03 doc updates (SUMMARY.md, STATE.md, ROADMAP.md, REQUIREMENTS.md). When a browser-capable session is available, run the deferred manual UI verification (REVK-01..05) per `24-03-SUMMARY.md`. Then begin Phase 25 (Governance Coverage Panel Fix; GCOV-01..02) — requires a Pre-Implementation Design Review before TDD per CLAUDE.md.
