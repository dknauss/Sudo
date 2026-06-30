---
gsd_state_version: 1.0
milestone: v4.4
milestone_name: milestone
status: Phase 23 needs a plan before execution.
stopped_at: Completed 23-bridge-documentation-and-release-posture-closure-01-PLAN.md
last_updated: "2026-06-30T05:24:21.998Z"
progress:
  total_phases: 3
  completed_phases: 3
  total_plans: 4
  completed_plans: 4
---

## Current Position

Phase: 23 complete — Bridge Documentation and Release Posture Closure.
Summary: `.planning/phases/23-bridge-documentation-and-release-posture-closure/23-01-SUMMARY.md`
Status: Phase 23 Plan 01 executed and committed. v4.4.0 Two Factor Lifecycle Bridge milestone documentation closed.
Branch: `main` — all Phase 21, 22, 23 work committed locally.
Done: v4.4.0 Two Factor Lifecycle Bridge complete — REST and classic profile-provider lifecycle gating implemented (Phase 22), source-evidence checked (Phase 21), documentation and metrics closed (Phase 23).
Product release state: Latest tagged plugin release remains `v4.2.2`; v4.4.0 is a GSD milestone and does not create a product release tag unless release metadata is intentionally bumped.
Open threads: WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Patchstack paid-fixture/manual runtime testing remains fixture-blocked and out of runtime-claim scope.
Resume file: None

## Project Reference

Canonical current facts:

- `docs/e2e-runtime-review.md` — runtime evidence, Phase 20 implementation, validation run, and keep decision.
- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — product roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.
- `.planning/MILESTONES.md` — archived GSD milestone history.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** v4.4.0 — Two Factor Lifecycle Bridge. Latest tagged release is **4.2.2** (see `docs/release-status.md`). WordPress.org submission (`docs/wporg-submission-checklist.md`) is delayed/on hold, but readiness should be maintained.

## Active Priorities

1. Keep WordPress.org readiness maintained while submission remains delayed/on hold.
2. Keep Patchstack runtime testing as fixture-blocked/manual-test work until a paid fixture exists.
3. Any future Two Factor source refresh should precede v4.5+ implementation changes.

## Accumulated Context

- Phase 23 context is complete in `.planning/phases/23-bridge-documentation-and-release-posture-closure/23-CONTEXT.md`; defaults captured: update Two Factor integration/ecosystem docs, refresh current metrics, add concise unreleased/current-main notes if warranted, preserve Patchstack fixture-blocked wording, and avoid version/tag/submission actions.
- Phase 22 has two verified execution plans: `22-01-PLAN.md` for design-reviewed TDD implementation and `22-02-PLAN.md` for required validation/scope-boundary checks.
- Phase 22 verification passed in `.planning/phases/22-profile-provider-guard-implementation/22-VERIFICATION.md` with 9/9 must-haves verified.
- Phase 22 plan 22-01 implemented `two_factor.profile_provider_lifecycle` in the optional Two Factor lifecycle bridge with RED/GREEN unit coverage; targeted bridge tests, `composer test`, `composer analyse`, and `composer lint` passed.
- Profile-provider guard replay now preserves source-verified core profile fields so mixed profile plus Two Factor saves replay as complete core profile updates after the WP Sudo challenge.
- Phase 22 context is complete in `.planning/phases/22-profile-provider-guard-implementation/22-CONTEXT.md`; defaults captured: extend the optional Two Factor lifecycle bridge, use normal WP Sudo admin challenge/replay, and gate only proven normalized provider lifecycle changes.
- Phase 21 source evidence is complete in `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md`; upstream WordPress/two-factor commit `c515462d51ac92941685e39293673c08538e16c8` was checked 2026-06-30.
- v4.3.1 is archived in `.planning/milestones/v4.3.1-ROADMAP.md` and `.planning/milestones/v4.3.1-REQUIREMENTS.md`.
- Phase 20 execution files are archived in `.planning/milestones/historical-phases/20-baseline-e2e-shard-rebalance/`.
- v4.3.0 is archived in `.planning/milestones/v4.3.0-ROADMAP.md` and `.planning/milestones/v4.3.0-REQUIREMENTS.md`.
- Current test and size counts are centralized in `docs/current-metrics.md`.
- WordPress 7.0 GA shipped May 20, 2026; package metadata says `Tested up to: 7.0`.
- This plugin is not currently published to the WordPress.org plugin repository.
- PR #129 validation run: https://github.com/dknauss/Sudo/actions/runs/28406226487.

### Pending Todos

- Patchstack runtime testing remains pending: acquire a paid Patchstack-enabled fixture plus manual challenge/lifecycle runtime tests before making runtime support claims.
- Upstream WordPress/two-factor source must be refreshed before v4.4.0 implementation changes, even though Phase 19 evidence was current on 2026-06-29.

## Key Decisions

- GitHub Actions job timestamps are the source of truth for E2E runtime tuning decisions; no local Playwright/browser timings should drive rebalances.
- Rebalance within the existing four required E2E groups before adding parallelism because each group pays the fixed `wp-env` startup floor.
- Move `tests/e2e/specs/admin-bar-timer.spec.ts` / `TIMR` from group 1 to group 2 based on refreshed Actions evidence.
- Keep the rebalance because PR #129 passed all four E2E shard jobs and the final gate on run 28406226487.
- Keep WordPress.org submission delayed/on hold while maintaining readiness.
- Do not create product release tags from GSD milestone names unless plugin version metadata/readme stable tag have been intentionally bumped.
- v4.4.0 scope targets upstream WordPress Two Factor profile-provider lifecycle changes; Patchstack remains fixture-blocked and out of implementation scope.
- Keep the Two Factor profile lifecycle guard in the optional bridge and fail open when upstream lifecycle data cannot prove a meaningful change.
- Preserve source-verified core profile fields in the replay allowlist for mixed profile plus Two Factor saves.
- Do not infer classic-form TOTP secret replacement from unverified POST fields; gate verified TOTP provider lifecycle changes against existing `_two_factor_totp_key` state.
- Leave public docs, canonical metrics, release metadata, and Patchstack runtime claims unchanged in Phase 22; defer documentation and metrics refresh to Phase 23.
- Document Two Factor integration as three distinct surfaces (challenge-time, REST lifecycle, classic profile-provider lifecycle) with the optional bridge serving surfaces 2 and 3.
- Keep Patchstack fixture-blocked/manual-test-only in all docs; no runtime support claims added in Phase 23.
- Do not bump WP_SUDO_VERSION, plugin header Version, readme.txt Stable tag, or create a product git tag at Phase 23; latest tagged release remains 4.2.2.

## Performance Metrics

| Date | Phase | Plan | Duration | Tasks | Files |
|------|-------|------|----------|-------|-------|
| 2026-06-30 | 22-profile-provider-guard-implementation | 01 | 19min | 3 | 5 |
| 2026-06-30 | 22-profile-provider-guard-implementation | 02 | 1min | 2 | 1 |
| 2026-06-30 | 23-bridge-documentation-and-release-posture-closure | 01 | 8min | 3 | 5 |

## Last Session

- **Stopped at:** Completed 23-bridge-documentation-and-release-posture-closure-01-PLAN.md
- **Next:** v4.4.0 milestone complete. No immediate next phase queued; maintain WordPress.org readiness posture.
