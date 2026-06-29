---
gsd_state_version: 1.0
milestone: v4.3.1
milestone_name: E2E Shard Rebalance
status: planned
last_updated: "2026-06-29T21:45:00Z"
last_activity: "2026-06-29 — v4.3.1 E2E Shard Rebalance milestone started; active phase directory created."
progress:
  total_phases: 1
  completed_phases: 0
  total_plans: 1
  completed_plans: 0
---

## Current Position

Phase: 20 — Baseline E2E Shard Rebalance is planned and ready for discussion/planning.
Plan: 20-01 planned, not yet written.
Status: Defining/planning next execution phase.
Branch: main — latest pushed commit archives v4.3.0 planning milestone after PR #127 merge. Latest tagged plugin release remains `v4.2.2`; product version metadata was not bumped.
Done & merged to main: v4.3.0 planning milestone archived; PR #127 Playwright 1.61.1 bump merged and green.
Open threads: Implement the no-coverage-loss E2E shard-rebalance follow-up. WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Patchstack paid-fixture/manual runtime testing remains a separate pending candidate.
Resume file: `.planning/phases/20-baseline-e2e-shard-rebalance/20-CONTEXT.md` — Phase 20 context exists. Next command: `$gsd-discuss-phase 20` or `$gsd-plan-phase 20`.
Last activity: 2026-06-29 — v4.3.1 E2E Shard Rebalance milestone started; requirements, roadmap, and active phase directory created.

## Project Reference

Canonical current facts:

- `docs/e2e-runtime-review.md` — runtime evidence and rebalance decision source.
- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — product roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** v4.3.1 E2E Shard Rebalance. Refresh Actions runtime evidence, rebalance one small low-risk test slice within the existing four required E2E groups, and document CI results. Latest tagged release is **4.2.2** (see `docs/release-status.md`). WordPress.org submission (`docs/wporg-submission-checklist.md`) is delayed/on hold, but readiness should be maintained.

## Active Priorities

1. ○ **20 — Baseline E2E Shard Rebalance** (E2E-01 through E2E-07): planned — refresh current GitHub Actions runtimes, rebalance one small group-1 test slice within the current four-group matrix, and verify/document the CI outcome.

## Accumulated Context

- Historical GSD phase directories were moved out of active `.planning/phases/` to `.planning/milestones/historical-phases/` when v4.3.1 started, so the active phase directory tracks only the current milestone.
- v4.3.0 is archived in `.planning/milestones/v4.3.0-ROADMAP.md` and `.planning/milestones/v4.3.0-REQUIREMENTS.md`.
- Current test and size counts are centralized in `docs/current-metrics.md`.
- WordPress 7.0 GA shipped May 20, 2026; package metadata says `Tested up to: 7.0`.
- This plugin is not currently published to the WordPress.org plugin repository.

### Pending Todos

- Patchstack runtime testing remains pending: acquire a paid Patchstack-enabled fixture plus manual challenge/lifecycle runtime tests before making runtime support claims.
- E2E shard rebalance is now the active milestone: refresh Actions data before editing `.github/workflows/e2e.yml`.

## Key Decisions

- GitHub Actions job timestamps are the source of truth for E2E runtime tuning decisions; no local Playwright/browser timings should drive the rebalance.
- Rebalance within the existing four required E2E groups before adding parallelism because each group pays the fixed `wp-env` startup floor.
- Keep WordPress.org submission delayed/on hold while maintaining readiness.
- Do not create a product release tag from a GSD planning milestone unless plugin version metadata is intentionally bumped.
