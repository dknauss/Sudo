---
phase: 21
slug: two-factor-source-refresh-and-guard-design
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-30
---

# Phase 21 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 9.6 + shell/static evidence checks |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php` |
| **Full suite command** | `composer test && composer analyse && composer lint && composer verify:metrics` |
| **Estimated runtime** | Targeted: ~2 seconds; full local gates: project-dependent |

---

## Sampling Rate

- **After every task commit:** Run the task’s listed static/evidence check plus the targeted bridge test when bridge comments or tests change.
- **After every plan wave:** Run targeted bridge tests and `git diff --check`.
- **Before `$gsd-verify-work`:** Targeted bridge tests must be green; full standard checks are required only if execution changes implementation behavior beyond comments/planning docs.
- **Max feedback latency:** 60 seconds for targeted checks.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 21-01-01 | 01 | 1 | TFA-SRC-01, TFA-SRC-02, TFA-REST-01, TFA-REST-02 | source/static | `test -f .planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md && grep -Eq 'c[0-9a-f]{39}|[0-9a-f]{40}' .planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md` | ✅ | ⬜ pending |
| 21-01-02 | 01 | 1 | TFA-GUARD-06 | static/design | `grep -E 'unrelated profile|no-op|enabled-provider|primary provider|first enrollment|Patchstack' .planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md` | ✅ | ⬜ pending |
| 21-01-03 | 01 | 1 | TFA-REST-01, TFA-REST-02 | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Existing infrastructure covers all phase requirements:

- [x] `tests/Unit/TwoFactorLifecycleBridgeTest.php` exists for targeted REST bridge assurance.
- [x] `phpunit.xml.dist` exists for targeted PHPUnit runs.
- [x] Shell/grep/static checks cover evidence artifact completeness.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Upstream source interpretation | TFA-SRC-01, TFA-SRC-02, TFA-GUARD-06 | Human review is required to decide whether upstream drift is material enough to re-scope. | Read `21-SOURCE-EVIDENCE.md`; confirm it names proceed vs stop/re-scope and gives source URLs/SHA/date. |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies.
- [x] Sampling continuity: no 3 consecutive tasks without automated verify.
- [x] Wave 0 covers all MISSING references.
- [x] No watch-mode flags.
- [x] Feedback latency < 60s for targeted checks.
- [x] `nyquist_compliant: true` set in frontmatter.

**Approval:** approved 2026-06-30
