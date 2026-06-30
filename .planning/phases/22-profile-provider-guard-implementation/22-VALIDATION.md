---
phase: 22
slug: profile-provider-guard-implementation
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-30
---

# Phase 22 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 9.6 with Brain\Monkey and Mockery |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php` |
| **Full suite command** | `composer test` |
| **Static analysis** | `composer analyse` |
| **Lint** | `composer lint` |
| **Estimated runtime** | Targeted bridge test ~1s; full unit suite ~1s; PHPCS/PHPStan longer but required before code commit |

---

## Sampling Rate

- **After every task that edits tests or bridge code:** Run `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php`.
- **After every plan wave:** Run the targeted bridge test plus any nearby test file changed in that wave.
- **Before code commit:** Run `composer test`, `composer analyse`, and `composer lint` as separate commands.
- **Before `$gsd-verify-work`:** Standard validation must be green, and `composer verify:metrics` must run if implementation changes canonical counts.
- **Max feedback latency:** keep targeted feedback under 10 seconds where possible.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 22-01-01 | 01 | 1 | TFA-GUARD-01 | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest` | ✅ | ⬜ pending |
| 22-01-02 | 01 | 1 | TFA-GUARD-02 | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest` | ✅ | ⬜ pending |
| 22-02-01 | 02 | 2 | TFA-GUARD-03 | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest` | ✅ | ⬜ pending |
| 22-02-02 | 02 | 2 | TFA-GUARD-04 | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest` | ✅ | ⬜ pending |
| 22-02-03 | 02 | 2 | TFA-GUARD-05 | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/TwoFactorLifecycleBridgeTest.php --filter TwoFactorLifecycleBridgeTest` | ✅ | ⬜ pending |
| 22-03-01 | 03 | 3 | TFA-GUARD-01..05 | validation | `composer test`, `composer analyse`, `composer lint` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Existing infrastructure covers all phase requirements:

- [x] `tests/Unit/TwoFactorLifecycleBridgeTest.php` exists and already covers the bridge registration seam.
- [x] `bridges/wp-sudo-two-factor-lifecycle-bridge.php` exists and already registers via `wp_sudo_gated_actions`.
- [x] `phpunit.xml.dist`, Brain\Monkey, and Mockery are already configured.
- [x] Phase 21 source evidence exists at `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md`.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Pre-implementation design review | TFA-GUARD-01..05 | Project policy requires review for non-trivial security-sensitive request gating before code commit. | Review the planned predicate before implementation; do not commit non-doc code until reviewer approval is written by the reviewer agent. |

All functional phase behaviors should have automated unit coverage in Phase 22.

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies.
- [x] Sampling continuity: no 3 consecutive tasks without automated verify.
- [x] Wave 0 covers all missing references.
- [x] No watch-mode flags.
- [x] Feedback latency < 10s for targeted bridge checks.
- [x] `nyquist_compliant: true` set in frontmatter.

**Approval:** approved 2026-06-30 for planning; execution still requires security-sensitive design/code review before non-doc commit.
