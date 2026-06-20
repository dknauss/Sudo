---
phase: 12
slug: breaking-changes-and-floor-bump
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-16
---

# Phase 12 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `12-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 9.6 (Brain\Monkey + Mockery + Patchwork) |
| **Config file** | `phpunit.xml.dist` (unit), `phpunit-integration.xml.dist` (integration) |
| **Quick run command** | `composer test:unit` |
| **Full suite command** | `composer test:unit && composer analyse` |
| **Estimated runtime** | ~0.3s (unit); PHPStan L6 a few seconds |

---

## Sampling Rate

- **After every task commit:** Run `composer test:unit`
- **After every plan wave:** Run `composer test:unit && composer analyse`
- **Before `/gsd:verify-work`:** Full unit suite green + PHPStan L6 clean
- **Max feedback latency:** ~1 second (unit)

---

## Per-Task Verification Map

> Task IDs are illustrative until the planner fixes plan/wave boundaries; the
> requirement→test mapping below is authoritative. Status updated during execution.

| Req | Behavior | Test Type | Automated Command | File Exists | Status |
|-----|----------|-----------|-------------------|-------------|--------|
| BRK-01 | `sudo_can()` no longer exists (`function_exists` false) | unit | `composer test:unit` | ✅ (replace `test_deprecated_sudo_can_alias_delegates_and_warns`) | ⬜ pending |
| BRK-01 | `wp_sudo_can()` strict/recovery/super-admin paths still pass | unit | `composer test:unit` | ✅ (existing) | ⬜ pending |
| BRK-02 | `wp_sudo_can()` treats stale `compatibility` option as strict | unit | `composer test:unit` | ✅ (new test) | ⬜ pending |
| BRK-02 | `wp_sudo_map_governance_meta_cap()` treats stale `compatibility` as strict | unit | `composer test:unit` | ✅ (new test) | ⬜ pending |
| BRK-02 | 7 compatibility-mode tests removed; suite still green | unit | `composer test:unit` | ✅ (delete in `GovernanceTest.php`) | ⬜ pending |
| BRK-03 | Notice renders when option=`compatibility` AND user has `manage_wp_sudo` | unit | `composer test:unit` | ✅ (new, `AdminTest.php`) | ⬜ pending |
| BRK-03 | Notice skips when user lacks `manage_wp_sudo` | unit | `composer test:unit` | ✅ (new) | ⬜ pending |
| BRK-03 | Notice skips when option ≠ `compatibility` | unit | `composer test:unit` | ✅ (new) | ⬜ pending |
| BRK-03 | `_doing_it_wrong()` fires only when condition true | unit | `composer test:unit` | ✅ (`Functions\expect`) | ⬜ pending |
| BRK-03 | Notice HTML is non-dismissible (`notice-warning`, no `is-dismissible`) | unit | `composer test:unit` | ✅ (new) | ⬜ pending |
| BRK-03 | Hooks registered on `admin_notices` + `network_admin_notices` | unit | `composer test:unit` | ✅ (`Actions\expectAdded`) | ⬜ pending |
| BRK-04 | Header + readme + CI floor lane declare WP 6.4 | grep | `grep "Requires at least" wp-sudo.php readme.txt` | n/a | ⬜ pending |
| BRK-05 | Header + readme + composer require + platform declare PHP 8.2 | grep | `grep "Requires PHP" wp-sudo.php readme.txt; grep -A2 platform composer.json` | n/a | ⬜ pending |
| BRK-06 | No `wp_get_admin_notice` shim in production code | grep | `grep -rn "wp_get_admin_notice" includes/ wp-sudo.php` → empty | n/a | ⬜ pending |
| BRK-07 | CHANGELOG 4.0.0 breaking block + readme Upgrade Notice + developer-reference migration section | review | `grep "Migrating to 4.0" docs/developer-reference.md` | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Existing infrastructure covers all phase requirements.* New test methods are added
to existing `tests/Unit/GovernanceTest.php` and `tests/Unit/AdminTest.php` following
established patterns (`render_recovery_mode_notice` test template; `ob_start()` +
`ReflectionMethod` + `expectOutputRegex`). No new test files, fixtures, or framework
config required.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Floor strings in non-PHP files (readme.txt, composer.json, CI YAML) | BRK-04, BRK-05 | Declarative config/metadata, not executable logic | grep each declaration site per the map above; confirm old 6.2/8.0 values gone |
| Migration-notes prose accuracy | BRK-07 | Documentation content | Visual review of CHANGELOG, readme.txt Upgrade Notice, `docs/developer-reference.md` "Migrating to 4.0" |
| BRK-06 obsolescence record | BRK-06 | Verify-then-close; no code change | Confirm empty grep, record satisfied/obsolete in SUMMARY + `REQUIREMENTS.md` |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or are grep/review manual checks above
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (none — existing infra suffices)
- [ ] No watch-mode flags
- [ ] Feedback latency < 2s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
