---
phase: 20
slug: baseline-e2e-shard-rebalance
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-29
---

# Phase 20 — Validation Strategy

> Per-phase validation contract for the baseline E2E shard rebalance follow-up.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | GitHub Actions + Playwright (`@playwright/test`) + wp-env |
| **Config file** | `.github/workflows/e2e.yml`, `.wp-env.e2e.json`, `playwright.config.ts` |
| **Quick run command** | `git diff --check` and workflow/test-slice inspection |
| **Full suite command** | Required GitHub checks for `E2E Tests 1/4` through `4/4` plus final `E2E Tests` gate |
| **Estimated runtime** | ~7 minutes for required E2E long pole based on Phase 18 evidence |

---

## Sampling Rate

- **After evidence refresh:** Record current `E2E Tests` 1/4 through 4/4 job durations before editing the workflow.
- **After workflow edit:** Run `git diff --check` and inspect `.github/workflows/e2e.yml` to confirm exactly four groups remain.
- **After documentation update:** Verify `docs/e2e-runtime-review.md` includes the rebalance implementation and validation run.
- **Before `$gsd-verify-work`:** Required GitHub CI must be green for all four baseline E2E groups and final gate.
- **Max feedback latency:** Local static feedback < 30 seconds; CI feedback bounded by GitHub Actions runtime.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 20-01-01 | 01 | 1 | E2E-01, E2E-02 | evidence | `gh run list` / `gh run view` commands from `docs/e2e-runtime-review.md` | ✅ | ⬜ pending |
| 20-01-02 | 01 | 1 | E2E-03, E2E-04, E2E-05 | static workflow | `git diff --check` plus matrix inspection | ✅ | ⬜ pending |
| 20-01-03 | 01 | 1 | E2E-07 | docs | `grep -n "Rebalance" docs/e2e-runtime-review.md` | ✅ | ⬜ pending |
| 20-01-04 | 01 | 1 | E2E-06 | CI | `gh pr checks --watch` or `gh run watch` for the implementation branch/PR | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Existing infrastructure covers all phase requirements:

- `.github/workflows/e2e.yml` already defines the required four-group baseline matrix.
- `docs/e2e-runtime-review.md` already contains the refresh commands and Phase 18 runtime baseline.
- `tests/e2e/specs/*.ts` already contains the specs being redistributed; no new test framework is needed.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Choose the smallest safe test slice to move | E2E-02, E2E-04 | Requires judgment from refreshed job durations and workflow grouping | Compare current group runtimes, inspect group 1 commands, move only a low-risk slice that preserves coverage. |
| Decide whether observed runtime supports keeping the rebalance | E2E-06, E2E-07 | CI runtime varies; one run may be noisy | Record CI run URL and group durations, then state whether the change improved the long pole enough to keep. |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or documented CI/manual verification.
- [x] Sampling continuity: every task has a validation checkpoint.
- [x] Wave 0 covers all missing references.
- [x] No watch-mode flags in local commands.
- [x] Feedback latency documented for local and CI checks.
- [x] `nyquist_compliant: true` set in frontmatter.

**Approval:** approved 2026-06-29 for planning; execution still requires fresh Actions evidence and green CI.
