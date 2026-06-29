---
phase: 18-e2e-runtime-review-and-tuning-decision
plan: 01
subsystem: ci
tags: [github-actions, e2e, runtime-review, release-readiness, planning]

requires:
  - phase: 17-release-only-environment-assurance
    provides: release-readiness posture and delayed WordPress.org submission context
provides:
  - durable GitHub Actions E2E runtime evidence after v4.2.2
  - threshold-based E2E long-pole decision
  - one no-coverage-loss CI rebalance follow-up note
  - Phase 18 planning completion state for CI-01 through CI-03
affects: [phase-19-2fa-bridge-planning, release-readiness, ci-speed]

tech-stack:
  added: []
  patterns:
    - docs-only GitHub Actions evidence collection via gh and jq
    - job-level runtime decisions separate from local Playwright timing

key-files:
  created:
    - docs/e2e-runtime-review.md
  modified:
    - docs/release-status.md
    - .planning/ROADMAP.md
    - .planning/REQUIREMENTS.md
    - .planning/STATE.md

key-decisions:
  - "GitHub Actions job timestamps, collected with gh run list/view and jq, are the source of truth for Phase 18 E2E runtime tuning."
  - "E2E Tests 1/4 (challenge-basic-admin) is a repeatable post-v4.2.2 long pole and warrants exactly one no-coverage-loss follow-up: rebalance a small test slice within the existing four baseline groups."
  - "Sparse scheduled/manual workflow observations are evidence gaps with refresh/dispatch commands, not blockers for Phase 18 completion."

patterns-established:
  - "Keep failed/cancelled dependency runs separate from successful green-run performance tables."
  - "Link durable runtime evidence from release/planning docs without duplicating volatile timing tables."

requirements-completed: [CI-01, CI-02, CI-03]

duration: 5min
completed: 2026-06-29
---

# Phase 18 Plan 01: E2E Runtime Review and Tuning Decision Summary

**GitHub Actions job-level E2E runtime review after v4.2.2, with one no-coverage-loss four-group rebalance follow-up proposed from measured evidence.**

## Performance

- **Duration:** 5 min
- **Started:** 2026-06-29T15:28:03Z
- **Completed:** 2026-06-29T15:33:56Z
- **Tasks:** 3
- **Files modified:** 5

## Accomplishments

- Created `docs/e2e-runtime-review.md` with refreshed GitHub Actions run inventory for all five E2E workflow files:
  - `e2e.yml`
  - `e2e-nginx.yml`
  - `e2e-nginx-multisite.yml`
  - `e2e-sqlite.yml`
  - `e2e-visual.yml`
- Recorded exact `gh run list` / `gh run view` / `jq` refresh commands, run IDs, URLs, events, branches, statuses, conclusions, and job/group runtimes.
- Separated successful green-run performance from failed/cancelled dependency-bump observations and excluded helper/gate jobs from runtime comparison.
- Documented evidence gaps for scheduled/manual workflows with zero or one post-cutoff observations.
- Applied the Phase 18 long-pole rule and found `E2E Tests 1/4 (challenge-basic-admin)` to be repeatably material: 9 successful observations averaging 372.2 seconds, about 34% slower than the next comparable baseline groups.
- Chose exactly one no-coverage-loss follow-up proposal: rebalance a small test slice out of group 1 within the existing four baseline groups.
- Linked the review from `docs/release-status.md` and `.planning/ROADMAP.md`, marked CI-01 through CI-03 complete, and routed STATE to Phase 19 planning.

## Task Commits

Each task was committed atomically:

1. **Task 1: Refresh GitHub Actions runtime evidence and start the review document** — `9ff1916` (docs)
2. **Task 2: Apply the long-pole rule and document the final CI tuning decision** — `20baead` (docs)
3. **Task 3: Link the review and finalize Phase 18 planning state** — `98c991e` (docs)

**Plan metadata:** recorded in final docs commit after this summary is staged.

## Files Created/Modified

- `docs/e2e-runtime-review.md` — Durable E2E runtime evidence, refresh commands, findings, and final decision.
- `docs/release-status.md` — Short CI evidence note linking to the runtime review without creating a WordPress.org submission gate.
- `.planning/ROADMAP.md` — Phase 18 marked complete with plan and review link.
- `.planning/REQUIREMENTS.md` — CI-01, CI-02, and CI-03 marked complete.
- `.planning/STATE.md` — Phase 18 completion recorded, Phase 19 planning routed next, and the no-coverage-loss CI follow-up captured.

## Verification Results

- `gh run list -R dknauss/Sudo --workflow e2e.yml --limit 1 --json databaseId,status,conclusion,createdAt,url` — passed.
- `test -f docs/e2e-runtime-review.md` — passed.
- `grep -q "gh run view" docs/e2e-runtime-review.md` — passed.
- `grep -q "Run ID" docs/e2e-runtime-review.md` — passed.
- `grep -q "E2E Tests 1/4" docs/e2e-runtime-review.md` — passed.
- `grep -Eq "Final decision|## Decision" docs/e2e-runtime-review.md` — passed.
- `grep -Eqi "no change|no-change|one proposed|one follow-up|exactly one" docs/e2e-runtime-review.md` — passed.
- `grep -Eqi "no coverage|coverage-loss|coverage loss|preserve.*coverage" docs/e2e-runtime-review.md` — passed.
- `test -z "$(git diff --name-only -- .github/workflows)"` — passed.
- `grep -q "e2e-runtime-review.md" docs/release-status.md` — passed.
- `grep -q "e2e-runtime-review.md" .planning/ROADMAP.md` — passed.
- `grep -q "18-01-PLAN.md" .planning/ROADMAP.md` — passed.
- `grep -n "\[x\] \*\*CI-01" .planning/REQUIREMENTS.md` — passed.
- `grep -n "\[x\] \*\*CI-02" .planning/REQUIREMENTS.md` — passed.
- `grep -n "\[x\] \*\*CI-03" .planning/REQUIREMENTS.md` — passed.
- `git diff --check` — passed.
- `composer verify:metrics` — passed: `Metrics verified: docs/current-metrics.md is in sync.`

## Decisions Made

- Use official GitHub Actions data via `gh`/`jq` as the final decision source; do not rely on local Playwright/browser timings.
- Propose one narrow follow-up because refreshed evidence crossed the material long-pole threshold.
- Keep the follow-up to rebalancing inside the existing four baseline E2E groups; no workflow edits were made in this plan.
- Treat missing scheduled/manual data as documented evidence gaps with refresh commands, not as phase blockers.

## Deviations from Plan

None - plan executed exactly as written.

## Auth Gates

None.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required. No local browser automation, local Playwright timing run, workflow file edit, WordPress.org checklist edit, push, or release action was performed.

## Next Phase Readiness

Phase 19 can start from a completed Phase 18 CI evidence record. The remaining v4.3.0 planned work is 2FA bridge planning and compatibility matrix scoping. The Phase 18 CI rebalance is captured as a follow-up note for a future narrow CI-speed task.


## Self-Check: PASSED

- Confirmed key files exist: `docs/e2e-runtime-review.md`, `docs/release-status.md`, `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`, `.planning/STATE.md`, and this summary.
- Confirmed task commits `9ff1916`, `20baead`, and `98c991e` exist in git history.

---
*Phase: 18-e2e-runtime-review-and-tuning-decision*
*Completed: 2026-06-29*
