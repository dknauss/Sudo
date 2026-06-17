---
phase: 13
slug: migration-safety-and-governance-audit
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-16
---

# Phase 13 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 9.6 (Brain\Monkey + Mockery for unit; WP_UnitTestCase for integration) |
| **Config file** | `phpunit.xml.dist` (unit) / `phpunit-integration.xml.dist` (integration) |
| **Quick run command** | `composer test:unit` |
| **Full suite command** | `composer test:unit && composer test:integration` |
| **Estimated runtime** | ~3–4s unit; integration requires MySQL + WP test suite |

---

## Sampling Rate

- **After every task commit:** Run `composer test:unit`
- **After every plan wave:** Run `composer test:integration` (real-DB integration suite)
- **Before `/gsd:verify-work`:** Full unit + integration suite must be green; `composer analyse` (PHPStan L6) clean
- **Max feedback latency:** ~4 seconds (unit)

---

## Per-Task Verification Map

> Task IDs are filled in by the planner. This maps each MIG requirement to its test signal and type.

| Req | Behavior | Wave | Test Type | Automated Command | File Exists | Status |
|-----|----------|------|-----------|-------------------|-------------|--------|
| MIG-01 | `upgrade_4_0_0()` deletes `wp_sudo_governance_mode` (single-site) | 1 | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | ✅ (new test) | ⬜ pending |
| MIG-01 | `upgrade_4_0_0()` deletes `wp_sudo_governance_mode` (multisite sitemeta) | 1 | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | ✅ (new test) | ⬜ pending |
| MIG-01 | `upgrade_4_0_0()` idempotent when option absent | 1 | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | ✅ (new test) | ⬜ pending |
| MIG-01 | WP 7.0 `wp_roles()` priming survives the new routine | 1 | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | ✅ (regression guard) | ⬜ pending |
| MIG-01 | Clear-on-detection removes inert option on same admin request | 1 | unit | `./vendor/bin/phpunit tests/Unit/AdminTest.php` | ✅ (new test) | ⬜ pending |
| MIG-01 | Notice renders "fixed" confirmation (dismissible, plain language) | 1 | unit | `./vendor/bin/phpunit tests/Unit/AdminTest.php` | ⚠️ 4 existing tests updated | ⬜ pending |
| MIG-01 | Dev signal semantically correct (no option-name-as-function-arg) | 1 | unit | `./vendor/bin/phpunit tests/Unit/AdminTest.php` | ⚠️ existing test updated | ⬜ pending |
| MIG-02 | `WP_SUDO_RECOVERY_MODE` grants access; no other break-glass | 2 | integration | `./vendor/bin/phpunit tests/Integration/GovernanceTest.php` | ✅ (new/extended) | ⬜ pending |
| MIG-03 | Uninstall deletes governance option (single-site) | 2 | integration | `./vendor/bin/phpunit tests/Integration/UninstallTest.php` | ✅ covered (line 166) | ⬜ pending |
| MIG-03 | Uninstall deletes governance option (multisite sitemeta) | 2 | integration | `./vendor/bin/phpunit tests/Integration/UninstallTest.php` | ✅ (new test) | ⬜ pending |
| MIG-04 | Multisite upgrade deletes `wp_sudo_governance_mode` from sitemeta | 2 | integration | `./vendor/bin/phpunit tests/Integration/MultisiteTest.php` | ✅ (new test) | ⬜ pending |
| MIG-04 | Multisite upgrade: super-admin capabilities intact, no cross-site bleed | 2 | integration | `./vendor/bin/phpunit tests/Integration/MultisiteTest.php` | ✅ (new test) | ⬜ pending |
| MIG-05 | No bare `manage_options` access gate in any admin surface | 1 | grep + unit | `composer lint` + `./vendor/bin/phpunit tests/Unit/AdminTest.php` | ✅ (grep primary) | ⬜ pending |
| MIG-06 | First activation grants `manage_wp_sudo` to an administrator | 2 | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | ✅ (new/explicit) | ⬜ pending |
| MIG-06 | Recovery path documented in security/developer docs | 3 | docs | Manual review | n/a | ⬜ pending |
| MIG-07 | First activation with NO super admin — recovery mode works (lockout) | 2 | integration | `./vendor/bin/phpunit tests/Integration/GovernanceTest.php` | ✅ (new test) | ⬜ pending |
| MIG-07 | Compatibility-mode upgrade recovery path (stale option post-stamp) | 2 | integration | `./vendor/bin/phpunit tests/Integration/GovernanceTest.php` | ✅ (new test) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- No new test files required — all new tests belong in existing files (`UpgraderTest.php`, `UninstallTest.php`, `MultisiteTest.php`, `GovernanceTest.php`, `AdminTest.php`).
- No new framework installs — PHPUnit 9.6 already installed.
- **Note:** The four existing `AdminTest.php` notice tests encode the Phase 12 contract (non-dismissible, option-name as `_doing_it_wrong` arg, old wording). They MUST be updated in Wave 1 alongside the production change — they are not pre-existing green coverage of the new behavior.

*Existing infrastructure covers all phase requirements; no Wave 0 scaffolding needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Recovery-path documentation accuracy | MIG-06 | Prose correctness, not code behavior | Review security-model.md / developer-reference.md: `WP_SUDO_RECOVERY_MODE` is the sole break-glass; recovery steps are correct and reproducible |
| Notice plain-language wording | MIG-01 | Copy quality / non-jargon judgment | Confirm notice avoids "governance mode"/"custom" jargon; reads as a one-time "fixed" confirmation per CONTEXT decisions |

*All testable behaviors have automated verification; the above are review-only.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (none required)
- [ ] No watch-mode flags
- [ ] Feedback latency < 4s (unit)
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
