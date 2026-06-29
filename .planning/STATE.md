---
gsd_state_version: 1.0
milestone: v4.3.1
milestone_name: E2E Shard Rebalance
status: complete
last_updated: "2026-06-29T22:24:56Z"
last_activity: "2026-06-29 — Phase 20 completed on ci/e2e-shard-rebalance; PR #129 documents and validates the E2E shard rebalance."
progress:
  total_phases: 1
  completed_phases: 1
  total_plans: 1
  completed_plans: 1
---

## Current Position

Phase: 20 — Baseline E2E Shard Rebalance is complete.
Plan: 20-01 complete; summary written at `.planning/phases/20-baseline-e2e-shard-rebalance/20-01-SUMMARY.md`.
Status: Milestone v4.3.1 complete and ready for `$gsd-complete-milestone` after PR #129 is merged.
Branch: `ci/e2e-shard-rebalance` — pushed; PR #129 open against `main`. Latest tagged plugin release remains `v4.2.2`; product version metadata was not bumped.
Done & validated: TIMR/admin-bar-timer moved from E2E group 1 to group 2; GitHub Actions run 28406226487 passed all four E2E shards and final `E2E Tests` gate.
Open threads: Merge PR #129 when checks/review are acceptable. WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Patchstack paid-fixture/manual runtime testing remains a separate pending candidate.
Resume file: None.

## Project Reference

Canonical current facts:

- `docs/e2e-runtime-review.md` — runtime evidence, Phase 20 implementation, validation run, and keep decision.
- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — product roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** v4.3.1 E2E Shard Rebalance is complete. Latest tagged release is **4.2.2** (see `docs/release-status.md`). WordPress.org submission (`docs/wporg-submission-checklist.md`) is delayed/on hold, but readiness should be maintained.

## Active Priorities

1. ✓ **20 — Baseline E2E Shard Rebalance** (E2E-01 through E2E-07): complete — refreshed Actions evidence, rebalanced one small group-1 test slice within the current four-group matrix, and verified/documented CI outcome.

## Accumulated Context

- Historical GSD phase directories were moved out of active `.planning/phases/` to `.planning/milestones/historical-phases/` when v4.3.1 started, so the active phase directory tracks only the current milestone.
- v4.3.0 is archived in `.planning/milestones/v4.3.0-ROADMAP.md` and `.planning/milestones/v4.3.0-REQUIREMENTS.md`.
- Current test and size counts are centralized in `docs/current-metrics.md`.
- WordPress 7.0 GA shipped May 20, 2026; package metadata says `Tested up to: 7.0`.
- This plugin is not currently published to the WordPress.org plugin repository.
- PR #129 validation run: https://github.com/dknauss/Sudo/actions/runs/28406226487.

### Pending Todos

- Patchstack runtime testing remains pending: acquire a paid Patchstack-enabled fixture plus manual challenge/lifecycle runtime tests before making runtime support claims.

## Key Decisions

- GitHub Actions job timestamps are the source of truth for E2E runtime tuning decisions; no local Playwright/browser timings should drive the rebalance.
- Rebalance within the existing four required E2E groups before adding parallelism because each group pays the fixed `wp-env` startup floor.
- Move `tests/e2e/specs/admin-bar-timer.spec.ts` / `TIMR` from group 1 to group 2 based on refreshed Actions evidence.
- Keep the rebalance because PR #129 passed all four E2E shard jobs and the final gate on run 28406226487.
- Keep WordPress.org submission delayed/on hold while maintaining readiness.
- Do not create a product release tag from a GSD planning milestone unless plugin version metadata is intentionally bumped.
