---
phase: 19
slug: 2fa-bridge-planning-and-compatibility-matrix
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-29
---

# Phase 19 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 9.6 with Brain\Monkey/Mockery for unit bridge tests; project docs and metrics verification via Composer scripts |
| **Config file** | `phpunit.xml.dist`, `phpstan.neon`, `phpcs.xml.dist`, `composer.json` |
| **Quick run command** | `composer test` |
| **Full suite command** | `composer test`, `composer analyse`, `composer lint`, `composer verify:metrics` |
| **Estimated runtime** | ~60-120 seconds for unit/static/docs verification in this local project context |

---

## Sampling Rate

- **After every task commit:** Run the most targeted relevant command, with `composer test` as the default quick check for bridge/test changes.
- **After every plan wave:** Run `composer test`, `composer analyse`, `composer lint`, and `composer verify:metrics`.
- **Before `$gsd-verify-work`:** Full suite above must be green; any docs-only execution may still run all four commands because Phase 19 touches source-verified security docs.
- **Max feedback latency:** Keep automated feedback below ~2 minutes for non-E2E checks.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 19-01-01 | 01 | 1 | 2FA-01 | source/docs audit | `composer verify:metrics` plus source-reference review | ✅ | ⬜ pending |
| 19-01-02 | 01 | 1 | 2FA-01 | unit | `./vendor/bin/phpunit tests/Unit/TwoFactorLifecycleBridgeTest.php` | ✅ | ⬜ pending |
| 19-01-03 | 01 | 1 | 2FA-01 | static/lint | `composer analyse`, `composer lint` | ✅ | ⬜ pending |
| 19-02-01 | 02 | 1 | 2FA-02 | docs/source audit | `composer verify:metrics` plus Patchstack source-reference review | ✅ | ⬜ pending |
| 19-02-02 | 02 | 1 | 2FA-03 | docs taxonomy review | `composer verify:metrics` | ✅ | ⬜ pending |
| 19-02-03 | 02 | 1 | 2FA-03 | full local gate | `composer test`, `composer analyse`, `composer lint`, `composer verify:metrics` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Existing infrastructure covers all Phase 19 requirements:

- [x] `tests/Unit/TwoFactorLifecycleBridgeTest.php` exists for current upstream Two Factor lifecycle bridge rule shape.
- [x] `composer test` exists for unit feedback.
- [x] `composer analyse` exists for PHPStan feedback.
- [x] `composer lint` exists for PHPCS/WPCS feedback.
- [x] `composer verify:metrics` exists for docs/current-metrics consistency.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Patchstack paid-fixture runtime behavior | 2FA-02 | Current source supports matrix tiering, but free-license mode prevents meaningful E2E 2FA hook coverage. | Do not claim Patchstack compatibility until a paid Patchstack-enabled fixture exercises enablement, challenge validation, invalid-code handling, and profile enable/disable lifecycle. |
| Upstream Two Factor profile-provider first-enrollment policy | 2FA-01 | Requires maintainer/security design judgment before writing a guard that could block legitimate first setup. | Plan must require TDD for no-op profile saves, provider changes, primary-provider changes, and first-enrollment vs modification/removal behavior before implementation. |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or explicit manual-only fixture caveats.
- [x] Sampling continuity: no 3 consecutive tasks without automated verify.
- [x] Wave 0 covers all existing automated infrastructure references.
- [x] No watch-mode flags.
- [x] Feedback latency target < 120s for local non-E2E validation.
- [x] `nyquist_compliant: true` set in frontmatter.

**Approval:** approved 2026-06-29 for planning; execution must update task statuses if implementation scope changes.
