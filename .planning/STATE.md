---
gsd_state_version: 1.0
milestone: v4.4.0
milestone_name: Two Factor Lifecycle Bridge
status: context_gathered
last_updated: "2026-06-30T01:30:00Z"
last_activity: "2026-06-30 — Phase 22 context gathered; profile-provider guard defaults captured for planning."
progress:
  total_phases: 3
  completed_phases: 1
  total_plans: 1
  completed_plans: 1
---

## Current Position

Phase: 22 context gathered — Profile Provider Guard Implementation.
Context: `.planning/phases/22-profile-provider-guard-implementation/22-CONTEXT.md` — ready for planning.
Status: Phase 22 needs a plan before implementation.
Branch: `main` — v4.4.0 planning docs and Phase 21 execution are committed locally.
Done & merged: v4.3.1 TIMR/admin-bar-timer moved from E2E group 1 to group 2; GitHub Actions validated all four E2E shards and final `E2E Tests` gate.
Product release state: Latest tagged plugin release remains `v4.2.2`; v4.4.0 is a GSD milestone and does not create a product release tag unless release metadata is intentionally bumped.
Open threads: WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Patchstack paid-fixture/manual runtime testing remains fixture-blocked and out of runtime-claim scope.
Resume file: `.planning/phases/22-profile-provider-guard-implementation/22-CONTEXT.md`

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

1. Plan Phase 22: implement the profile-provider guard with TDD using Phase 21 source evidence and Phase 22 context.
2. Keep Phase 21 evidence current if upstream WordPress/two-factor changes before behavior implementation.
3. Keep WordPress.org readiness maintained while submission remains delayed/on hold.
4. Keep Patchstack runtime testing as fixture-blocked/manual-test work until a paid fixture exists.

## Accumulated Context

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
