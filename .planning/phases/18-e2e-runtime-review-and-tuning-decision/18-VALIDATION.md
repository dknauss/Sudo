---
phase: 18
slug: e2e-runtime-review-and-tuning-decision
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-06-29
---

# Phase 18 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Docs/evidence validation with POSIX shell, `grep`, `gh`, and `jq` |
| **Config file** | `.github/workflows/e2e*.yml`; no new runtime test config required |
| **Quick run command** | `git diff --check` plus targeted `test`/`grep` checks for evidence docs and links |
| **Full suite command** | `composer test` and `composer analyse` only if non-doc/runtime files change; otherwise docs-smoke plus optional `composer verify:metrics` |
| **Estimated runtime** | ~10–60 seconds for docs/evidence checks; GitHub API calls depend on network |

---

## Sampling Rate

- **After every task commit:** Run `git diff --check` and the relevant targeted docs-smoke command for the file just changed.
- **After every plan wave:** Run all plan-level docs/evidence checks and `composer verify:metrics` if canonical release/metrics docs are touched.
- **Before `$gsd-verify-work`:** Ensure `docs/e2e-runtime-review.md` records Actions run/job provenance, final decision, refresh commands, and links from release/planning docs.
- **Max feedback latency:** under 60 seconds for local checks; GitHub API refresh is allowed to take longer but must be repeatable.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 18-01-01 | 01 | 1 | CI-01 | evidence collection | `gh run list -R dknauss/Sudo --workflow e2e.yml --limit 1 --json databaseId,status,conclusion,createdAt,url` | ✅ W0 | ⬜ pending |
| 18-01-02 | 01 | 1 | CI-01 | docs contract | `test -f docs/e2e-runtime-review.md` | ❌ W0 | ⬜ pending |
| 18-01-03 | 01 | 1 | CI-01 | provenance | `grep -q "gh run view" docs/e2e-runtime-review.md` | ❌ W0 | ⬜ pending |
| 18-01-04 | 01 | 1 | CI-02 | decision contract | `grep -Eq "exactly one|one follow-up|one proposed|No change|no-change|no change" docs/e2e-runtime-review.md` | ❌ W0 | ⬜ pending |
| 18-01-05 | 01 | 1 | CI-03 | final decision | `grep -Eq "Final decision|Decision" docs/e2e-runtime-review.md` | ❌ W0 | ⬜ pending |
| 18-01-06 | 01 | 1 | CI-01, CI-03 | release link | `grep -q "e2e-runtime-review.md" docs/release-status.md` | ✅ W0 | ⬜ pending |
| 18-01-07 | 01 | 1 | CI-01, CI-03 | planning link | `grep -q "e2e-runtime-review.md" .planning/ROADMAP.md` | ✅ W0 | ⬜ pending |
| 18-01-08 | 01 | 1 | CI-01, CI-02, CI-03 | patch hygiene | `git diff --check` | ✅ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `docs/e2e-runtime-review.md` — missing before execution; must be created by Phase 18 plan.
- [ ] `docs/release-status.md` link — existing file, link missing until execution.
- [ ] `.planning/ROADMAP.md` link/status update — existing file, Phase 18 link missing until execution.
- [ ] `.planning/REQUIREMENTS.md` / `.planning/STATE.md` — existing files, CI-01 through CI-03 pending until execution.
- [x] `gh` CLI authenticated for `dknauss/Sudo` Actions data.
- [x] E2E workflow files exist in `.github/workflows/`.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Evidence comparability | CI-01 | Automated grep can confirm data exists, but a human must confirm failed/cancelled/gate jobs are not treated as comparable green E2E performance. | Read the runtime review table and confirm it separates successful E2E jobs from skipped gate jobs and failed dependency-bump runs. |
| Long-pole decision quality | CI-02, CI-03 | The threshold is semantic: one narrow no-coverage-loss action if justified, otherwise no-change. | Confirm the final decision follows the ~25%/2+ observation rule or explicitly caveats sparse data. |
| Coverage preservation | CI-02 | No simple grep can prove a proposed tuning preserves coverage. | Confirm any proposed action does not drop E2E surfaces, required checks, or move coverage to manual-only validation. |

---

## Validation Sign-Off

- [x] All tasks have command-backed or manual verification.
- [x] Sampling continuity: no phase task should proceed without evidence/doc smoke checks.
- [x] Wave 0 identifies missing docs/links expected before execution.
- [x] No watch-mode flags.
- [x] Feedback latency target documented.
- [x] `nyquist_compliant: true` set in frontmatter.

**Approval:** approved 2026-06-29
