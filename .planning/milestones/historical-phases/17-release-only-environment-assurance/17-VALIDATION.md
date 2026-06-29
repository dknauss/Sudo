---
phase: 17
slug: release-only-environment-assurance
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-29
---

# Phase 17 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Docs-only validation using repository scripts and manual read-through |
| **Config file** | none — no runtime test framework changes |
| **Quick run command** | `git diff --check` |
| **Full suite command** | `composer verify:metrics` plus targeted documentation read-through |
| **Estimated runtime** | ~5–15 seconds for commands; read-through time depends on reviewer |

---

## Sampling Rate

- **After every task commit:** Run `git diff --check`.
- **After every plan wave:** Run `composer verify:metrics` if any canonical metrics or release-state prose could affect current docs; otherwise record why it was not necessary.
- **Before `$gsd-verify-work`:** Confirm the four affected docs tell one consistent story:
  - `docs/release-environment-log.md`
  - `tests/MANUAL-TESTING.md`
  - `docs/wporg-submission-checklist.md`
  - `docs/release-status.md`
- **Max feedback latency:** under 60 seconds for command checks; manual read-through is the controlling quality gate.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 17-01-01 | 01 | 1 | REL-01 | docs contract | `test -f docs/release-environment-log.md` | ✅ W0 | ⬜ pending |
| 17-01-02 | 01 | 1 | REL-01 | grep/read-through | `grep -n "Release Environment Log\|release-environment-log" tests/MANUAL-TESTING.md` | ✅ W0 | ⬜ pending |
| 17-01-03 | 01 | 1 | REL-02 | grep/read-through | `grep -n "delayed/on hold\|on hold" docs/wporg-submission-checklist.md` | ✅ W0 | ⬜ pending |
| 17-01-04 | 01 | 1 | REL-03 | grep/read-through | `grep -n "Pre-tag\|WordPress.org-only\|WordPress.org-only" docs/wporg-submission-checklist.md docs/release-status.md` | ✅ W0 | ⬜ pending |
| 17-01-05 | 01 | 1 | REL-01, REL-02, REL-03 | patch hygiene | `git diff --check` | ✅ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Existing infrastructure covers all phase requirements:

- [x] Markdown documentation already exists for release status, manual testing, and WordPress.org readiness.
- [x] `git diff --check` is available.
- [x] `composer verify:metrics` is available for canonical metrics drift checks.
- [x] No new test harness, CI lane, browser automation, managed-host provisioning, or production dependency is required.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Environment-log evidence quality | REL-01 | The deliverable is reader-facing release evidence/deferral prose, not executable code. | Read `docs/release-environment-log.md` and confirm it has per-version status, per-lane details, owner/timing for deferrals, and no false claim that `v4.2.2` lanes were rerun. |
| WordPress.org delayed/on-hold posture | REL-02 | Intent can be contradicted by wording even when commands pass. | Read `docs/wporg-submission-checklist.md` and confirm it remains executable but does not imply publication/submission is happening now. |
| Pre-tag versus `.org` gate split | REL-03 | Gate taxonomy is semantic documentation. | Read `docs/wporg-submission-checklist.md` and `docs/release-status.md`; confirm pre-tag/core release gates are separated from WordPress.org-only upload/submission gates. |
| Procedure/evidence separation | REL-01 | Duplicate manual procedures would create maintenance drift. | Confirm `tests/MANUAL-TESTING.md` remains the executable procedure and `docs/release-environment-log.md` records outcomes/deferrals only. |

---

## Validation Sign-Off

- [x] All tasks have command-backed or manual verification.
- [x] Sampling continuity: docs-only phase has feedback after every task/wave.
- [x] Wave 0 covers all MISSING references.
- [x] No watch-mode flags.
- [x] Feedback latency target documented.
- [x] `nyquist_compliant: true` set in frontmatter.

**Approval:** approved 2026-06-29
