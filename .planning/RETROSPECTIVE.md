# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v4.3.0 — Post-.org Readiness Hardening

**Completed:** 2026-06-29
**Phases:** 4 | **Plans:** 4 documented plans plus direct Phase 16 implementation | **Sessions:** multiple GSD execution sessions

### What Was Built
- Release-ready localization tooling, committed POT source, translator-comment cleanup, and i18n verification hooks.
- Release-environment evidence and docs that keep WordPress.org submission ready while clearly delayed/on hold.
- GitHub Actions E2E runtime evidence with exactly one narrow no-coverage-loss rebalance follow-up.
- 2FA bridge planning that separates shippable upstream Two Factor bridge work from docs/manual compatibility targets.
- Patchstack compatibility matrix entry that remains fixture-blocked/manual-test only until a paid fixture exists.

### What Worked
- Keeping volatile release counts and status centralized avoided stale docs while multiple readiness docs changed.
- Source-refreshing third-party 2FA evidence before writing claims prevented support overreach.
- GitHub CI was the reliable verifier for Playwright 1.61 behavior when local wp-env state was inconsistent.

### What Was Inefficient
- Phase 16 was executed directly from captured context, so it lacks a normal `16-*-SUMMARY.md` file even though the roadmap and requirements are complete.
- The milestone archive workflow's default CLI phase detector sees historical roadmap phases too; manual archival was safer for this repository's retained historical roadmap.
- A milestone audit file was not present before archival; this was accepted explicitly rather than backfilled.

### Patterns Established
- Treat WordPress.org readiness as a maintained state, not an immediate submission trigger.
- Represent paid/closed third-party compatibility targets as fixture-blocked manual-test rows until runtime evidence exists.
- Avoid creating product release tags from GSD milestone names unless version constants/readme stable tag have been intentionally bumped.

### Key Lessons
1. Planning milestone names can resemble product versions; check plugin version metadata before creating git tags.
2. CI/runtime tuning should start from recent GitHub Actions evidence, not local browser timing assumptions.
3. Compatibility matrices should distinguish built-in support, bridge-exists artifacts, bridge candidates, docs-only examples, and manual-test targets.

### Cost Observations
- Model mix: inherited session model with reviewer/validation subagents where commits required review.
- Notable: The highest-value cost saver was using source-backed summaries and existing docs instead of broad re-analysis.

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Sessions | Phases | Key Change |
|-----------|----------|--------|------------|
| v4.3.0 | multiple | 4 | Post-release readiness treated as small hardening/planning work, not publication or product redesign. |

### Cumulative Quality

| Milestone | Tests | Coverage | Zero-Dep Additions |
|-----------|-------|----------|-------------------|
| v4.3.0 | See `docs/current-metrics.md` | PHPUnit + Playwright + release checks | i18n tooling and documentation gates without production dependencies |

### Top Lessons (Verified Across Milestones)

1. Keep volatile project facts in canonical docs and link to them instead of duplicating counts.
2. Use live/source verification for third-party integration claims before committing docs or bridge plans.
3. Separate release readiness from release publication to avoid accidental `.org` or tag commitments.
