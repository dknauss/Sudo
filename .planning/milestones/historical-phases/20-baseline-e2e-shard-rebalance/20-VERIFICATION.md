---
phase: 20-baseline-e2e-shard-rebalance
verified: 2026-06-29T22:41:10Z
status: passed
score: 6/6 must-haves verified
---

# Phase 20: Baseline E2E Shard Rebalance Verification Report

**Phase Goal:** The required four-group E2E matrix has a better-balanced critical path while preserving the same coverage, same required gate, and same startup-cost-aware group count.
**Verified:** 2026-06-29T22:41:10Z
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Maintainer can see a fresh GitHub Actions runtime snapshot collected before the workflow edit. | ✓ VERIFIED | `docs/e2e-runtime-review.md` has `## Phase 20 refresh`, collected `2026-06-29T22:10:22Z`, with `gh run list` / `gh run view` commands and eight exact run IDs/URLs. |
| 2 | The rebalance decision names the current long pole and shortest suitable destination group from refreshed Actions data. | ✓ VERIFIED | Docs identify `E2E Tests 1/4 (challenge-basic-admin)` as the 407.9s average long pole and `E2E Tests 2/4 (challenge-2fa-ui)` as the 265.8s shortest suitable destination. |
| 3 | The required baseline workflow still has exactly four E2E matrix groups and the final required `E2E Tests` gate. | ✓ VERIFIED | Static inspection found group indexes `{1,2,3,4}`, every group has `group-total: 4`, and `e2e-tests` remains `name: "E2E Tests"` with `needs: [changes, e2e-group]`. |
| 4 | A small low-risk slice leaves group 1 without deleting, skipping, or manualizing spec coverage. | ✓ VERIFIED | `.github/workflows/e2e.yml` removes `admin-bar-timer.spec.ts` / `TIMR` from group 1 and adds `admin-bar-timer.spec.ts` to group 2. Diff adds no `test.skip`, `--grep-invert`, or manual-only behavior. |
| 5 | GitHub CI passes after the workflow change, including all four baseline E2E groups and the final gate. | ✓ VERIFIED | Run `28406226487` concluded `success`; all four `E2E Tests N/4` jobs and final `E2E Tests` gate succeeded. Latest PR #129 required checks are also green; PR merge state is `CLEAN` at head `b29ce647fb13916944d7893ec34b2ba958b6280a`. |
| 6 | `docs/e2e-runtime-review.md` records implementation, validation run, and keep/revert decision. | ✓ VERIFIED | Docs include `## Phase 20 validation`, PR #129, run URL `https://github.com/dknauss/Sudo/actions/runs/28406226487`, per-group conclusions/durations, final gate result, and `Keep/revert decision: keep`. |

**Score:** 6/6 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `.github/workflows/e2e.yml` | Four-group required baseline E2E matrix with rebalanced test slice | ✓ VERIFIED | Exactly four groups remain; `TIMR`/timer slice moved from group 1 to group 2; final gate unchanged. |
| `docs/e2e-runtime-review.md` | Runtime evidence, implementation note, CI validation result | ✓ VERIFIED | Contains Phase 20 refresh evidence, rebalance decision, implementation details, validation run, durations, conclusions, and keep decision. |
| `.planning/REQUIREMENTS.md`, `.planning/ROADMAP.md`, `.planning/STATE.md`, `20-01-SUMMARY.md` | Planning/state traceability | ✓ VERIFIED | Phase 20 is marked complete; all seven E2E requirements are mapped and no Phase 20 requirement is orphaned. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `docs/e2e-runtime-review.md` | `.github/workflows/e2e.yml` | Refreshed Actions data drives destination group | ✓ WIRED | Docs identify group 1 as long pole and group 2 as destination; workflow reflects the timer slice move to group 2. |
| `.github/workflows/e2e.yml` | GitHub required checks | Matrix group names and final gate semantics | ✓ WIRED | PR #129 rollup shows `E2E Tests 1/4` through `4/4` and final `E2E Tests` succeeded. |
| GitHub Actions CI run | `docs/e2e-runtime-review.md` | Exact run URL and durations recorded | ✓ WIRED | Run `28406226487` live GitHub data matches documented success for all four groups and final gate. |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| E2E-01 | 20-01-PLAN.md | Refresh latest E2E group durations before workflow change. | ✓ SATISFIED | Phase 20 refresh section includes commands, timestamps, run IDs, URLs, and per-group durations. |
| E2E-02 | 20-01-PLAN.md | Identify long pole and destination from Actions data, not local assumptions. | ✓ SATISFIED | Docs explicitly state Actions timestamps are source of truth and local Playwright timing assumptions were not used. |
| E2E-03 | 20-01-PLAN.md | Preserve four-group matrix and final `E2E Tests` gate. | ✓ SATISFIED | Static workflow inspection confirms four groups, `group-total: 4`, final gate name and dependencies. |
| E2E-04 | 20-01-PLAN.md | Move small slice without skipping/removing/manualizing coverage. | ✓ SATISFIED | Timer spec moved from group 1 to group 2; no added skip/manual-only patterns. |
| E2E-05 | 20-01-PLAN.md | Explain startup-floor rationale. | ✓ SATISFIED | Runtime review explains staying within four groups because each group pays fixed `wp-env` startup floor. |
| E2E-06 | 20-01-PLAN.md | Required GitHub CI passes after workflow change. | ✓ SATISFIED | Run `28406226487` passed all groups/final gate; latest PR required checks pass. |
| E2E-07 | 20-01-PLAN.md | Record implementation, validation, and keep/revert decision. | ✓ SATISFIED | Phase 20 validation section documents PR, run, job results, durations, final gate, and keep decision. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | — | — | No blocker anti-patterns found. Grep hits for `manual-only`/`skip` are negative assertions in docs, not added skip behavior. No production code files were modified. |

### Human Verification Required

None. The phase goal is CI/workflow/documentation evidence and was verified programmatically against local files plus live GitHub PR/run status.

### GitHub CI Evidence

- Branch/head verified locally: `ci/e2e-shard-rebalance` at `b29ce647fb13916944d7893ec34b2ba958b6280a`.
- PR #129 verified live: `OPEN`, non-draft, base `main`, merge state `CLEAN`, head `b29ce647fb13916944d7893ec34b2ba958b6280a`.
- Initial implementation validation run verified live: `28406226487`, workflow `E2E Tests`, head SHA `316bc604c4869fc84bba6416e1dfc0f417a1b7aa`, conclusion `success`.
- Latest required PR checks verified live with `gh pr checks 129 -R dknauss/Sudo --required`: `CodeQL`, `E2E Nginx Smoke`, `E2E Tests`, `PHPUnit`, and `Psalm` all pass.

### Gaps Summary

No gaps found. Phase 20 achieved the goal: the baseline E2E matrix remains four groups with the same final required gate, the timer slice was moved from the long-pole group to the shortest suitable group without coverage loss, docs record the evidence and keep decision, and GitHub CI is green.

---

_Verified: 2026-06-29T22:41:10Z_
_Verifier: Claude (gsd-verifier)_
