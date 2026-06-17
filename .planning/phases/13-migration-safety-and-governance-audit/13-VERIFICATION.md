---
phase: 13-migration-safety-and-governance-audit
verified: 2026-06-17T00:00:00Z
status: human_needed
score: 11/12 must-haves verified
re_verification: false
human_verification:
  - test: "Install the WordPress integration test suite (MySQL + WP test library via bin/install-wp-tests.sh) and run composer test:integration"
    expected: "All 10 new integration test methods across UpgraderTest, GovernanceTest, MultisiteTest, UninstallTest pass green; no failures or PHP notices"
    why_human: "The WP integration test environment (MySQL + WP test library) was not installed in the build session. Integration tests are syntax-verified and structurally correct but were not executed. The unit suite (810 tests) and PHPStan L6 were run and are green."
---

# Phase 13: Migration Safety and Governance Audit Verification Report

**Phase Goal:** Migration Safety and Governance Audit — ensure the 3.x→4.0.0 upgrade safely removes the now-inert wp_sudo_governance_mode option, the migration notice is truthful/one-time, the capability model has no bare manage_options access gates (MIG-05), the lockout-safe first-run and recovery paths are documented (MIG-06), and real-DB integration coverage proves the migration-safety guarantees.
**Verified:** 2026-06-17
**Status:** human_needed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | upgrade_4_0_0() deletes wp_sudo_governance_mode on the 3.x->4.0.0 boundary (single-site and multisite) | VERIFIED | `includes/class-upgrader.php` lines 329-334: private method deletes from both stores; UPGRADES map has '4.0.0' => 'upgrade_4_0_0' as last entry (line 57) |
| 2 | An admin loading any admin page with the inert option present has it auto-deleted that same request (clear-on-detection) | VERIFIED | `includes/class-admin.php` lines 2514-2546: `cleanup_inert_governance_mode_option()` hooked on admin_init priority 1 (line 270); checks both stores; deletes from both; authority-gated |
| 3 | After clear-on-detection deletes the option, the admin sees a single dismissible "fixed" confirmation notice in plain language | VERIFIED | `includes/class-admin.php` lines 2571-2587: `render_compatibility_mode_notice()` gated on `$compat_option_cleared`; outputs `notice-success is-dismissible`; plain-language "WP Sudo removed a leftover permission-mode setting" copy |
| 4 | The developer signal no longer claims a function was called incorrectly (no option-name-as-function-arg _doing_it_wrong) | VERIFIED | No `_doing_it_wrong.*governance` in class-admin.php; signal moved to `do_action('wp_sudo_inert_governance_mode_detected')` at line 2546 |
| 5 | No admin/settings/widget surface gates on a bare manage_options check | VERIFIED | `add_options_page` at line 306 uses `manage_wp_sudo`; `add_submenu_page` at line 326 uses `manage_wp_sudo`; all AJAX handlers (lines 1506, 1556, 1925, 1983, 2703) use `wp_sudo_can('manage_wp_sudo')`; only non-gate use of manage_options is in the drift-detection info panel (line 1442, read-only display) and the break-glass authority check in functions-governance.php (documented exception) |
| 6 | WP_SUDO_RECOVERY_MODE is documented as the sole break-glass path | VERIFIED | `docs/security-model.md` line 45: "Break-glass recovery — the sole remaining escape hatch"; `docs/developer-reference.md` line 331: "WP_SUDO_RECOVERY_MODE... remains the sole" break-glass |
| 7 | A reproducible recovery procedure for a misconfigured manage_wp_sudo grant is documented | VERIFIED | `docs/developer-reference.md` lines 334-360: four-step procedure under "Recovery from a misconfigured manage_wp_sudo grant" including WP-CLI fallback |
| 8 | The capability-model audit outcome is recorded in the developer docs | VERIFIED | `docs/developer-reference.md` lines 263-277: surface-to-capability mapping table and named intentional non-gate exceptions |
| 9 | Integration tests encode the upgrade_4_0_0 deletion, idempotency, and wp_roles() regression guard | VERIFIED (syntax; execution pending) | `tests/Integration/UpgraderTest.php`: 3 methods targeting MIG-01 verified via php -l and code inspection; all exercise the exact production path; cannot confirm green (no WP test env) |
| 10 | Integration tests cover multisite upgrade sitemeta deletion and super-admin cap intactness | VERIFIED (syntax; execution pending) | `tests/Integration/MultisiteTest.php`: 2 MIG-04 methods; `tests/Integration/UninstallTest.php`: extended to seed and assert sitemeta governance option deletion (MIG-03); php -l passes all |
| 11 | Integration tests prove WP_SUDO_RECOVERY_MODE sole break-glass, lockout recovery, and clear-on-detection post-stamp | VERIFIED (syntax; execution pending) | `tests/Integration/GovernanceTest.php`: 3 methods covering MIG-02 and MIG-07; uses ReflectionProperty for static flag (not a test shim); uses conditional define for the constant; php -l passes |
| 12 | Unit suite and PHPStan L6 are green with no regression from Phase 13 production changes | VERIFIED | `composer test:unit` output: 810 tests, 2307 assertions, 0 failures; PHPStan L6: 0 errors |

**Score:** 12/12 truths substantively correct; 1 item (integration test execution) requires human verification to close.

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/class-upgrader.php` | upgrade_4_0_0() routine + UPGRADES map entry | VERIFIED | Contains `upgrade_4_0_0` at line 329; UPGRADES map entry `'4.0.0' => 'upgrade_4_0_0'` is the final entry (line 57); method is substantive (dual-store delete, not a stub) |
| `includes/class-admin.php` | cleanup_inert_governance_mode_option() on admin_init + reworked render_compatibility_mode_notice() | VERIFIED | cleanup method at line 2514 (33 lines, substantive); admin_init hook at line 270 with priority 1; notice at line 2571 is static-flag-gated, notice-success, is-dismissible; no _doing_it_wrong; both wired through register() |
| `tests/Unit/UpgraderTest.php` | 4 new 4.0.0 unit tests; UPGRADES map assertion | VERIFIED | cleanup_inert_governance_mode_option pattern found; 4 upgrade_4_0_0 tests confirmed; setUp stubs for delete_option/delete_site_option present; UPGRADES last-entry assertion at line 837 |
| `tests/Unit/AdminTest.php` | cleanup behavior tests, notice contract (notice-success, is-dismissible, no _doing_it_wrong), MIG-05 lock | VERIFIED | 11 new tests confirmed; reflection helper for private static flag; `add_options_page` capability assertion at line 422; no _doing_it_wrong called assertions |
| `docs/security-model.md` | Break-glass/recovery section stating WP_SUDO_RECOVERY_MODE is sole break-glass | VERIFIED | Line 45: "sole remaining escape hatch" heading present; compatibility mode paragraph removed per SUMMARY |
| `docs/developer-reference.md` | Recovery procedure + capability-model audit note (manage_wp_sudo, no bare manage_options) | VERIFIED | Lines 334-360: 4-step recovery procedure; lines 263-277: surface-to-cap mapping table; "sole" at line 331 |
| `tests/Integration/UpgraderTest.php` | upgrade_4_0_0 deletion (single/multisite), idempotency, wp_roles() regression guard, first-run grant | SYNTAX VERIFIED | php -l passes; 9 total test methods; contains `upgrade_4_0_0`, `wp_roles`, and GLOBALS snapshot pattern; execution requires WP DB env |
| `tests/Integration/UninstallTest.php` | Multisite sitemeta governance-option deletion assertion | SYNTAX VERIFIED | php -l passes; contains get_site_option assertion at line 258; 2 total test methods |
| `tests/Integration/MultisiteTest.php` | Multisite upgrade governance-option scope + super-admin capability intactness | SYNTAX VERIFIED | php -l passes; contains governance_mode and sitemeta assertions at lines 238-252; 7 total test methods |
| `tests/Integration/GovernanceTest.php` | Recovery-mode sole break-glass, lockout scenario, clear-on-detection post-stamp | SYNTAX VERIFIED | php -l passes; contains RECOVERY_MODE, cleanup_inert_governance_mode_option, ReflectionProperty; 5 total test methods |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `includes/class-admin.php::cleanup_inert_governance_mode_option (admin_init, priority 1)` | `includes/class-admin.php::render_compatibility_mode_notice (admin_notices)` | `self::$compat_option_cleared` static boolean flag | WIRED | `cleanup` sets `self::$compat_option_cleared = true` at line 2535; `render_` reads `self::$compat_option_cleared` at line 2575; `reset_cache()` at line 822 resets it for test hygiene |
| `includes/class-upgrader.php::UPGRADES` | `includes/class-upgrader.php::upgrade_4_0_0` | version-gated dispatch in maybe_upgrade() | WIRED | `'4.0.0' => 'upgrade_4_0_0'` is the last entry in UPGRADES (line 57); `maybe_upgrade()` dispatches via `$this->{$method}()` at line 92; `upgrade_4_0_0` is an `is_callable` private method at line 329 |
| `docs/security-model.md` | `docs/developer-reference.md` | consistent statement that WP_SUDO_RECOVERY_MODE is the sole break-glass | WIRED | security-model.md line 45: "sole remaining escape hatch"; developer-reference.md line 331: "remains the sole"; both point to the same constant and no other mechanism |
| `tests/Integration/GovernanceTest.php` | `Admin::cleanup_inert_governance_mode_option` | post-4.0.0-stamp clear-on-detection assertion | WIRED (syntax) | GovernanceTest directly calls `$admin_instance->cleanup_inert_governance_mode_option()` and asserts via ReflectionProperty; execution not confirmed |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|---------|
| MIG-01 | 13-01, 13-03 | Upgrades from 3.0–3.4 to 4.0.0 complete cleanly with no orphaned governance state | SATISFIED | upgrade_4_0_0() in class-upgrader.php (unit tests green, integration tests syntax-verified); UPGRADES map entry appended last; idempotent |
| MIG-02 | 13-03 | WP_SUDO_RECOVERY_MODE break-glass still works after compatibility mode removal and is the only remaining break-glass path | SATISFIED (execution pending) | GovernanceTest::test_recovery_mode_is_sole_break_glass encodes this; functions-governance.php line 74-79 confirms the only break-glass is the WP_SUDO_RECOVERY_MODE branch; no other escape hatch in code |
| MIG-03 | 13-03 | Uninstall cleanup remains correct on both single-site and multisite | SATISFIED (execution pending) | UninstallTest extended to seed and assert `get_site_option('wp_sudo_governance_mode') === false` after uninstall (line 258); existing single-site assertion retained |
| MIG-04 | 13-03 | Multisite upgrade behavior verified — network governance state and super-admin capabilities remain intact | SATISFIED (execution pending) | MultisiteTest::test_multisite_upgrade_deletes_governance_mode_from_sitemeta and test_multisite_upgrade_preserves_super_admin_capabilities encoded |
| MIG-05 | 13-01, 13-02 | Post-removal capability audit — all settings/admin/widget screens use dedicated Sudo capabilities with no fallback to bare manage_options | SATISFIED | add_options_page/add_submenu_page both use 'manage_wp_sudo'; all AJAX handlers use wp_sudo_can('manage_wp_sudo'); no bare manage_options access gate found in includes/ or wp-sudo.php; unit tests at AdminTest lock this |
| MIG-06 | 13-01, 13-02, 13-03 | First-run governance is clear and lockout-safe; recovery path documented | SATISFIED | developer-reference.md has 4-step recovery procedure; upgrade_3_3_0 backfill covers existing installs; UpgraderTest::test_first_activation_grants_manage_wp_sudo_to_administrator encoded (execution pending) |
| MIG-07 | 13-03 | Tests cover lockout and misconfiguration-recovery scenarios | SATISFIED (execution pending) | GovernanceTest covers test_no_sudo_lockout_is_recoverable_via_recovery_mode and test_clear_on_detection_removes_option_when_already_at_4_0_0 |

No orphaned requirements found. All seven MIG-01..MIG-07 IDs are claimed by plans and have corresponding implementation or documentation.

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| None found | — | — | — | — |

Scanned: `includes/class-upgrader.php`, `includes/class-admin.php`, all four integration test files. No TODOs, FIXMEs, empty return stubs, or placeholder comments found in Phase 13-added code. The `manage_options` uses in class-admin.php (lines 1442, 2490) are correctly classified as non-gate uses (info panel and notice text), not access gates.

---

### Human Verification Required

#### 1. Integration Test Execution

**Test:** Provision the WordPress integration test suite (`bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host>`) and run `composer test:integration` targeting the four files modified in Plan 03.

**Expected:** All 10 new integration test methods green. Key assertions to watch:
- `test_upgrade_4_0_0_deletes_stale_governance_mode_option`: `get_option('wp_sudo_governance_mode') === false` after `maybe_upgrade()`.
- `test_upgrade_4_0_0_does_not_regress_wp_roles_priming`: no fatal with `$GLOBALS['wp_roles'] = null`.
- `test_recovery_mode_is_sole_break_glass`: `WP_SUDO_RECOVERY_MODE` constant strategy works in the test process (defined once; all assertions hold).
- `test_clear_on_detection_removes_option_when_already_at_4_0_0`: ReflectionProperty reads `$compat_option_cleared = true` after direct call to `cleanup_inert_governance_mode_option()`.
- Multisite tests: require a multisite fixture; confirm `is_multisite()` returns true in the test context.

**Why human:** The WP integration test environment (MySQL + WordPress test library) was not installed in the build session. `php -l` passes all four files and code inspection confirms structural correctness, but the assertions cannot be confirmed green without a real database.

---

### Gaps Summary

No blocking gaps. All production code is substantive and wired. All unit tests are green (810/810). PHPStan L6 clean. Documentation is in place with correct content. The single `human_needed` item is the integration test execution gate — the tests are correctly authored and structurally sound, but their RED-to-GREEN arc on a real database has not been observed in this session.

The phase goal is achievable and all code-level evidence supports it. Status is `human_needed` rather than `passed` solely because the integration test suite cannot run without a provisioned MySQL environment, and the verifier cannot assert these DB-dependent tests green without execution evidence.

---

_Verified: 2026-06-17_
_Verifier: Claude (gsd-verifier)_
