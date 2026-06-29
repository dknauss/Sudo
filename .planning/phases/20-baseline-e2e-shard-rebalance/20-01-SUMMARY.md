---
phase: 20-baseline-e2e-shard-rebalance
plan: 01
subsystem: ci
tags: [github-actions, e2e, playwright, wp-env, runtime-evidence]
requires:
  - phase: 18
    provides: Phase 18 runtime evidence and recommendation to rebalance within the existing four required E2E groups before adding parallelism
provides:
  - Refreshed GitHub Actions E2E runtime snapshot collected before the workflow edit
  - Four-group required E2E matrix with the TIMR/admin-bar-timer slice moved from group 1 to group 2
  - GitHub CI validation evidence for PR #129, including all four E2E shards and final gate
  - Documented keep decision for the rebalance
affects: [e2e, ci, release-readiness, github-actions]
tech-stack:
  added: []
  patterns:
    - Use GitHub Actions job timestamps, not local Playwright timings, for shard rebalance decisions
    - Preserve the four-group baseline E2E matrix and final required gate while redistributing small slices
key-files:
  created:
    - .planning/phases/20-baseline-e2e-shard-rebalance/20-01-SUMMARY.md
  modified:
    - .github/workflows/e2e.yml
    - docs/e2e-runtime-review.md
key-decisions:
  - "Moved `tests/e2e/specs/admin-bar-timer.spec.ts` / `TIMR` from E2E group 1 to group 2 because refreshed Actions evidence showed group 1 as the long pole and group 2 as the shortest suitable destination."
  - "Kept the rebalance after PR #129 passed all four E2E shard jobs and the final `E2E Tests` gate on GitHub Actions run 28406226487."
  - "Retained exactly four required E2E groups rather than adding parallelism because every group pays the fixed `wp-env` startup floor."
patterns-established:
  - "E2E runtime reviews should record exact Actions run IDs, group durations, group conclusions, and keep/revert decisions."
  - "Shard rebalances must preserve required-check semantics: four baseline groups plus final `E2E Tests` gate."
requirements-completed:
  - E2E-01
  - E2E-02
  - E2E-03
  - E2E-04
  - E2E-05
  - E2E-06
  - E2E-07
duration: 39min
completed: 2026-06-29
---

# Phase 20 Plan 01: Baseline E2E Shard Rebalance Summary

**GitHub Actions-backed E2E shard rebalance moved the timer slice from group 1 to group 2 while preserving the four-group required gate.**

## Performance

- **Duration:** 39 min
- **Started:** 2026-06-29T21:45:00Z
- **Completed:** 2026-06-29T22:24:30Z
- **Tasks:** 3 completed
- **Files modified:** 3

## Accomplishments

- Refreshed current GitHub Actions E2E group timing evidence immediately before editing the workflow.
- Identified `E2E Tests 1/4 (challenge-basic-admin)` as the current long pole and `E2E Tests 2/4 (challenge-2fa-ui)` as the shortest suitable destination group.
- Moved `tests/e2e/specs/admin-bar-timer.spec.ts` / `TIMR` from group 1 to group 2 without deleting specs, skipping coverage, changing group count, or changing the final required `E2E Tests` gate.
- Opened PR #129 and validated the changed workflow with GitHub Actions run 28406226487: all four E2E shard jobs and the final gate passed.
- Recorded the implementation, exact run URL, shard durations, conclusions, and keep/revert decision in `docs/e2e-runtime-review.md`.

## Task Commits

1. **Tasks 1-2: Refresh evidence and rebalance workflow** — `316bc60` (`ci: rebalance e2e baseline shard`)
2. **Task 3: Record GitHub CI validation evidence** — `20ca4cf` (`docs: record phase 20 e2e validation`)

**Plan metadata:** this summary commit.

## Files Created/Modified

- `.github/workflows/e2e.yml` — Removed `admin-bar-timer.spec.ts` / `TIMR` from group 1 and added the timer spec to group 2 while preserving the four-group matrix and final gate.
- `docs/e2e-runtime-review.md` — Added Phase 20 refresh evidence, implementation rationale, PR #129 validation run, per-shard durations/conclusions, and keep decision.
- `.planning/phases/20-baseline-e2e-shard-rebalance/20-01-SUMMARY.md` — Captures execution outcome and traceability.

## Decisions Made

- Moved the timer slice because refreshed Actions data showed group 1 averaging 407.9 seconds versus group 2 at 265.8 seconds across eight successful comparable observations.
- Kept the rebalance because PR #129 run 28406226487 passed with group durations of 367s, 320s, 329s, and 289s, plus a successful final gate.
- Stayed within four groups to avoid another fixed `wp-env` startup floor and preserve required-check semantics.

## Deviations from Plan

None — plan executed as written. The validation note was committed after the first implementation commit so the documentation could include the exact PR and CI run evidence.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

Phase 20 is ready for transition/completion. PR #129 contains the rebalance and validation documentation. If future E2E evidence shows a new long pole, use the same Actions-run-ID evidence pattern before any additional shard movement.

---
*Phase: 20-baseline-e2e-shard-rebalance*
*Completed: 2026-06-29*

## Self-Check: PASSED

- Summary frontmatter includes all Phase 20 requirement IDs.
- `docs/e2e-runtime-review.md` includes pre-edit refresh evidence and post-change validation evidence.
- GitHub Actions run 28406226487 passed all four E2E shard jobs and the final gate.
