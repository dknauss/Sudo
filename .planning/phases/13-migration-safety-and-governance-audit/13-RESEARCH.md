# Phase 13: Migration Safety and Governance Audit - Research

**Researched:** 2026-06-16
**Domain:** WordPress plugin upgrade routines, capability governance, uninstall cleanup, integration testing
**Confidence:** HIGH — all findings verified against live source files in this repository

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Stale `compatibility` option cleanup (MIG-01)**
- Auto-delete via `upgrade_4_0_0()` on the 3.x → 4.0.0 upgrade boundary: deletes `wp_sudo_governance_mode` outright (no normalize, no value is meaningful now). Delete on both single-site (`delete_option`) and multisite (`delete_site_option`).
- ALSO clear-on-detection via a small `admin_init` hook that runs before `admin_notices`, so the option self-heals on the next admin page load even if the version-gated routine has already run (e.g. option re-added manually or any non-upgrade path).
- Notice becomes a one-time "fixed" confirmation: brief, dismissible, fires when the option is found and deleted. Wording uses plain language ("leftover permission-mode setting", not "governance mode", not "custom"). Suggested copy: *"WP Sudo removed a leftover permission-mode setting left over from before version 4.0.0. WP Sudo now always enforces strict, role-based permission checks. No action is needed."*
- The `_doing_it_wrong()` signal is semantically wrong (it passes the option name as the function argument, rendering "Function wp_sudo_governance_mode was called incorrectly"). Phase 13 decision: pass a real function/method context, reword to fit the fixed prefix, or switch to `error_log()`/`trigger_error()` or an audit action hook. Must fire at most once per admin request.

**Multisite option scope (MIG-04)**
- Verify + add multisite regression tests. No behavior change unless a real bug is found. Confirm runtime read (`get_option`, main-site scope) and cleanup (upgrade_4_0_0 + uninstall) are consistent on multisite. Add multisite integration coverage for upgrade and uninstall paths.

**Capability audit + first-run (MIG-05, MIG-06)**
- Verify-then-lock with tests + a brief recovery doc. Add/confirm tests proving every admin/settings/widget surface routes through dedicated Sudo caps (`manage_wp_sudo` etc.) with no bare `manage_options` fallback. Document the recovery path for a misconfigured grant (`WP_SUDO_RECOVERY_MODE` is the sole break-glass) in existing security/developer docs. No new safeguards or capability mechanisms.

**Test coverage (MIG-07)**
- Cover the lockout scenario (first activation with no super admin) and the compatibility-mode-upgrade recovery path.

**Pre-Implementation Design Review**
- Required before the upgrade routine (`upgrade_4_0_0()`): it touches migration/upgrade, interacts with uninstall, and the Phase 12 notice. Write a brief covering: when the routine fires and its idempotency/version gating; that deleting the option is safe because governance is always strict; single-site vs multisite delete parity; routine↔notice interaction.

### Claude's Discretion
- Whether the dev signal is `error_log()`, `trigger_error()`, or an audit action hook (any is acceptable; must fire at most once per admin request and must NOT use the option name as the function argument to `_doing_it_wrong()`).
- Exact wording and styling of the one-time confirmation notice (within the plain-language, dismissible, brief constraint).
- How to structure tests — whether to extend existing test files or add new methods within UpgraderTest, AdminTest, UninstallTest, MultisiteTest, GovernanceTest.

### Deferred Ideas (OUT OF SCOPE)
- WordPress.org readiness (Phase 14).
- Manual environment-matrix checklist (Phase 15).
- No new governance features or break-glass mechanisms.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| MIG-01 | Upgrades from 3.0–3.4 to 4.0.0 complete cleanly with no orphaned governance state | upgrade_4_0_0() follows established UPGRADES pattern; clear-on-detection via admin_init; notice becomes "fixed" confirmation |
| MIG-02 | `WP_SUDO_RECOVERY_MODE` break-glass still works after compatibility mode removal and is the only remaining break-glass path | Verified: wp_sudo_can() path 2 is break-glass only; no other path remains |
| MIG-03 | Uninstall cleanup remains correct after removals on both single-site and multisite | Verified: uninstall.php already deletes wp_sudo_governance_mode on both paths (line 104, line 180); existing UninstallTest covers it |
| MIG-04 | Multisite upgrade behavior verified — network governance state and super-admin capabilities remain intact | MultisiteTest exists; needs upgrade + option-scope tests added |
| MIG-05 | Post-removal capability audit — all settings/admin/widget screens use dedicated Sudo capabilities with no fallback to bare `manage_options` | Verified: all admin surfaces use wp_sudo_can() / manage_wp_sudo; manage_options appears only in docblock, recovery-mode gate, and informational UI |
| MIG-06 | First-run governance is clear and lockout-safe — on first activation an administrator holds manage_wp_sudo, and the recovery path is documented | Verified: activation hook + upgrade_3_3_0 backfill grant manage_wp_sudo; WP_SUDO_RECOVERY_MODE documented as sole break-glass |
| MIG-07 | Tests cover lockout and misconfiguration-recovery scenarios introduced or affected by the governance simplification | No integration test currently seeds the "no super admin" lockout or the "stale option post-upgrade-stamp" clear-on-detection scenario |
</phase_requirements>

---

## Summary

Phase 13 is an audit-plus-test-coverage phase with one piece of new production code: `upgrade_4_0_0()` in `Upgrader`. The governance simplification (Phase 12) has already landed cleanly. All major surfaces have been verified against the live source:

The uninstall is already correct — `uninstall.php` deletes `wp_sudo_governance_mode` on both single-site (line 104, `delete_option`) and multisite (line 180, `delete_site_option`), and `UninstallTest` seeds and asserts the governance option deletion. MIG-03 is essentially verify-then-lock: confirm the existing tests cover this option and add a targeted assertion if any gap exists.

The capability audit (MIG-05) starts from a clean base. Verified via `grep`: no bare `current_user_can('manage_options')` or `user_can($id, 'manage_options')` exists in any admin surface as an access gate. Every admin/settings/widget surface calls `wp_sudo_can()` with a dedicated Sudo cap. The `manage_options` references that do exist are: (a) the break-glass gate in `wp_sudo_can()` (intentional, correct), (b) the `wp_sudo_map_governance_meta_cap()` recovery-mode mapping (intentional), (c) an informational "users-holding-manage_options" panel in the Access tab (reads, not gates), and (d) docblock text. None are bare access gates.

The critical new production work is `upgrade_4_0_0()` plus the `admin_init` clear-on-detection hook and the reworded notice. The WP 7.0 `wp_roles()` priming in `maybe_upgrade()` (line 86) is a verified fatal-fix that must be preserved — it is positioned before the UPGRADES loop and is version-independent.

**Primary recommendation:** Add `upgrade_4_0_0()` following the exact UPGRADES map pattern, add the `admin_init` clear-on-detection hook in `Admin::register()`, reword the notice to a dismissible "fixed" confirmation, fix the `_doing_it_wrong()` semantic mismatch, and add integration tests for the four currently-uncovered MIG scenarios.

---

## Standard Stack

No new libraries. This phase uses the project's established testing stack.

### Core (verified against live source)
| Component | Version | Purpose | Status |
|-----------|---------|---------|--------|
| PHPUnit | 9.6 | Unit and integration test runner | Already installed |
| Brain\Monkey | current | WP function/hook mocking in unit tests | Already installed |
| Mockery | current | Object mocking in unit tests | Already installed |
| WP_UnitTestCase | core | Real DB integration test base | Already installed |

### Existing Test Infrastructure
| File | What it covers | Gap for Phase 13 |
|------|---------------|------------------|
| `tests/Integration/UpgraderTest.php` | Migration chain through 3.3.0 | Needs 4.0.0 routine test |
| `tests/Integration/UninstallTest.php` | Single-site and multisite uninstall | Already asserts `wp_sudo_governance_mode` deleted; may need targeted multisite governance option assertion |
| `tests/Integration/MultisiteTest.php` | Network-wide settings, session isolation | Needs upgrade and governance-option scope tests |
| `tests/Integration/GovernanceTest.php` | sudo_can() removal, stale option is inert | Needs recovery-mode-only and lockout-scenario tests |
| `tests/Unit/AdminTest.php` | render_compatibility_mode_notice() — 4 tests | All 4 must be updated for new notice contract (dismissible, "fixed" wording, clear-on-detect, fixed dev signal) |

---

## Architecture Patterns

### Pattern 1: Adding a new Upgrader routine

Verified against `includes/class-upgrader.php` (lines 50-96, 128-312):

The `UPGRADES` constant is an ordered `array<string, string>` mapping version → method name. `maybe_upgrade()` iterates it, calling each method whose version string is greater than the stored DB version. The stored version is read/written via `get_site_option`/`update_site_option` on multisite and `get_option`/`update_option` on single-site. The WP 7.0 `wp_roles()` priming call (line 86) is placed BEFORE the loop and must not move.

```php
// In the UPGRADES constant — append after '3.3.0':
'4.0.0' => 'upgrade_4_0_0',

// New private method:
private function upgrade_4_0_0(): void {
    // Delete the now-inert governance mode option introduced in v3.2.0.
    // governance is always strict in 4.0.0; the stored value is meaningless.
    if ( is_multisite() ) {
        delete_site_option( 'wp_sudo_governance_mode' );
    } else {
        delete_option( 'wp_sudo_governance_mode' );
    }
}
```

**Critical preservation requirement:** The `wp_roles()` call at line 86 in `maybe_upgrade()` must remain unchanged. It pre-initializes the global `$wp_roles` before any routine runs, preventing a WP 7.0 fatal when `upgrade_3_3_0()` runs a `capability` user query at `plugins_loaded`. Adding `upgrade_4_0_0()` to the UPGRADES map does not affect this — the call is unconditional before the loop.

### Pattern 2: admin_init clear-on-detection

The clear-on-detection hook deletes the inert option on the next admin page load regardless of version stamp state. It must hook on `admin_init` (runs before `admin_notices`) and must be authority-gated (only delete for users who can manage Sudo governance — same gate as the notice).

```php
// In Admin::register(), add alongside the existing admin_notices hook:
add_action( 'admin_init', array( $this, 'cleanup_inert_governance_mode_option' ), 1, 0 );

// New public method (hooked, so must be public):
public function cleanup_inert_governance_mode_option(): void {
    if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
        return;
    }
    if ( false === get_option( 'wp_sudo_governance_mode' ) ) {
        return;  // already gone
    }
    delete_option( 'wp_sudo_governance_mode' );
}
```

**Design note:** The notice and the cleanup are separate responsibilities. The cleanup runs on `admin_init` (priority 1), which fires before `admin_notices`. The notice renders conditionally on `admin_notices` — after cleanup has run, `get_option('wp_sudo_governance_mode')` returns `false`, so the notice's early-return fires and no output is produced on the same request where cleanup deleted the option.

Wait — that means on the cleanup request the notice renders NOTHING (because the option is gone). The "fixed" confirmation is a one-time message that appears on the request WHEN the option is detected and deleted. This requires the cleanup method to set a transient or flag before deleting, then the notice renders based on that flag, not the option. Alternatively: the notice renders before admin_init cleanup (by using a lower priority on admin_init, or by hooking the cleanup to the same admin_notices with a lower priority number so it fires before the notice render).

**Correct sequence:**
- Priority 1 on `admin_init`: detect the option, store a transient/flag if found, then delete the option.
- The notice on `admin_notices` checks the transient/flag (not the option) and renders the one-time "fixed" message, then deletes the transient.

OR: Keep the current notice hook structure. The notice checks `get_option('wp_sudo_governance_mode')`. The cleanup hook on `admin_init` (priority 1) sets a transient `wp_sudo_governance_mode_cleared` if the option was found, then deletes the option. The notice on `admin_notices` checks this transient instead of the option.

This is the design decision the planner must lock in. Both approaches are valid; the second (transient) is cleaner for the "fixed" one-time message UX.

### Pattern 3: Fixing the _doing_it_wrong() semantic mismatch

Current code passes `'wp_sudo_governance_mode'` (an option name) as the `$function_name` argument to `_doing_it_wrong()`. WordPress renders this as *"Function wp_sudo_governance_mode was called incorrectly"* — which is false (nothing was called incorrectly; a stale DB value exists).

Verified options (all fire at most once per request in the hooked context):
- **Option A:** Pass a real method name: `_doing_it_wrong( __METHOD__, 'message', '4.0.0' )` — renders *"Function WP_Sudo\Admin::cleanup_inert_governance_mode_option was called incorrectly"* which is still imprecise but at least references a real function.
- **Option B:** Use `error_log()` — no WordPress integration, fires unconditionally on PHP error log.
- **Option C:** Use an audit action hook: `do_action( 'wp_sudo_inert_governance_mode_detected' )` — clean, allows external logging, no WordPress WP_DEBUG noise. This is consistent with the project's audit hook pattern.

Option C is preferred given the project's audit hook conventions. The existing signal intent (developer warning when stale option detected) maps cleanly to an audit action.

### Pattern 4: Multisite option scope

Verified: `render_compatibility_mode_notice()` reads `get_option('wp_sudo_governance_mode')` (line 2499). On multisite, `get_option` reads the current blog's options table (not sitemeta). The stale option would have been stored in sitemeta (via the governance mode setting path which used `update_site_option` on multisite). This is a potential scope mismatch: the notice reads `get_option` but the option may have been written to `wp_sitemeta`.

The upgrade_4_0_0 routine handles this correctly by deleting from both paths. The clear-on-detection hook must also delete from both paths (or just the matching path for the current blog context). The uninstall already handles both (lines 104 and 180).

**Verified:** The `cleanup_inert_governance_mode_option` method should mirror the existing uninstall pattern — delete from both single-site and network:
```php
delete_option( 'wp_sudo_governance_mode' );  // per-site options table
if ( is_multisite() ) {
    delete_site_option( 'wp_sudo_governance_mode' );  // sitemeta
}
```

### Pattern 5: Admin capability surface audit

Full verified grep of `manage_options` in production:

| Location | Use | Assessment |
|----------|-----|-----------|
| `functions-governance.php` line 26 | Docblock: "rather than calling current_user_can('manage_options')" | Safe — documentation |
| `functions-governance.php` lines 40-42, 72 | Docblock explaining break-glass blast radius | Safe — documentation |
| `functions-governance.php` line 77 | `user_can( $user_id, 'manage_options' )` inside break-glass gate | Intentional, correct |
| `functions-governance.php` lines 130, 137 | `wp_sudo_map_governance_meta_cap()` break-glass mapping | Intentional, correct |
| `class-admin.php` line 1412, 1419, 1441 | Access tab "users holding manage_options" informational panel | Read-only, not a gate |
| `class-admin.php` line 2467 | Recovery mode notice text mentioning manage_options | Informational string |

No bare `manage_options` access gate exists anywhere. MIG-05 starts from a clean base.

All admin surfaces verified to use `wp_sudo_can('manage_wp_sudo')`:
- `add_settings_page()` / `add_network_settings_page()` — capability arg: `'manage_wp_sudo'` (lines 284, 303) ✓
- `render_settings_page()` — calls `wp_sudo_can('manage_wp_sudo')` (line 326) ✓
- AJAX handlers (grant_cap, revoke_cap, revoke_session, mu_install, mu_uninstall) — all gate via `wp_sudo_can('manage_wp_sudo')` (lines 1149, 1483, 1533, 1902, 1960) ✓
- Dashboard widget — gates via `wp_sudo_can('view_wp_sudo_activity')` (class-dashboard-widget.php line 53) ✓
- `Site_Health` — no `manage_options` gate found (capability checks delegate via WordPress core Site Health integration) ✓

### Pattern 6: Upgrader test structure

Existing `UpgraderTest.php` uses `update_wp_sudo_option()` to set a starting version, instantiates `new Upgrader()`, calls `maybe_upgrade()`, then asserts DB state. New test for `upgrade_4_0_0()` follows the same pattern:

```php
public function test_upgrade_4_0_0_deletes_stale_governance_mode_option(): void {
    // Arrange: version below 4.0.0, option present.
    $this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.4.0' );
    update_option( 'wp_sudo_governance_mode', 'compatibility' );
    if ( is_multisite() ) {
        update_site_option( 'wp_sudo_governance_mode', 'compatibility' );
    }

    // Act.
    ( new Upgrader() )->maybe_upgrade();

    // Assert: option gone on single-site, and on sitemeta for multisite.
    $this->assertFalse(
        get_option( 'wp_sudo_governance_mode' ),
        'upgrade_4_0_0() must delete wp_sudo_governance_mode from options table.'
    );
    if ( is_multisite() ) {
        $this->assertFalse(
            get_site_option( 'wp_sudo_governance_mode' ),
            'upgrade_4_0_0() must delete wp_sudo_governance_mode from sitemeta.'
        );
    }
}
```

Also test idempotency: option absent before routine runs (e.g. fresh install upgrading through 4.0.0 with option never set) — must not error.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Multisite-aware option read/write | Custom abstraction | Follow the is_multisite() → get_site_option/get_option pattern already established in Upgrader::get_db_version() | Consistent with all existing routines |
| Test isolation for WP_Roles global | Global teardown in set_up/tear_down | Copy the try/finally snapshot pattern from UpgraderTest::test_3_3_0_backfill_survives_uninitialized_wp_roles_global | Already proven safe in this project |
| "One-time notice" state | Transient or option flag | Use `set_transient('wp_sudo_compat_option_cleared', 1, HOUR_IN_SECONDS)` pattern | Avoids custom state mechanism; already in project stack |

**Key insight:** Every pattern needed for Phase 13 already exists in the codebase. The upgrade routine, multisite-aware deletion, admin_init hooks, and integration test structure are all modeled in existing code. No new abstractions required.

---

## Common Pitfalls

### Pitfall 1: Moving the wp_roles() priming call in maybe_upgrade()
**What goes wrong:** If the priming call is moved inside the loop or removed, `upgrade_3_3_0()` fatals on WP 7.0 under WP-CLI where `$wp_roles` is null at `plugins_loaded`. This is a proven, tested fatal (see project memory: Upgrader WP 7.0 $wp_roles fatal — FIXED 2975d31).
**Why it happens:** `upgrade_4_0_0()` is added to UPGRADES, which requires touching `maybe_upgrade()` or UPGRADES constant. If the developer refactors `maybe_upgrade()` while adding the entry, the priming call may be displaced.
**How to avoid:** The `wp_roles()` call is at line 86, before `foreach ( self::UPGRADES as ... )`. When adding `'4.0.0' => 'upgrade_4_0_0'` to the UPGRADES constant, touch only the constant — do NOT restructure `maybe_upgrade()`.
**Warning signs:** Any refactor of the `maybe_upgrade()` method body.

### Pitfall 2: Wrong version key ordering in UPGRADES
**What goes wrong:** PHP's `version_compare()` determines which routines run. If `'4.0.0'` is inserted before `'3.3.0'` in the UPGRADES array, the loop order breaks (the array is not auto-sorted; it is traversed as declared).
**Why it happens:** Developer inserts new entry at the top or middle of the constant.
**How to avoid:** UPGRADES entries MUST be in ascending version order. `'4.0.0'` appends after `'3.3.0'` at the end of the array.

### Pitfall 3: clear-on-detection deletes but notice never shows
**What goes wrong:** The `admin_init` cleanup hook deletes the option before `admin_notices` fires. The notice's condition check reads the option — finds it gone — and renders nothing. The user sees no confirmation that cleanup happened.
**Why it happens:** Sequence: admin_init (priority 1) → admin_init (priority 10) → admin_notices. If cleanup runs on admin_init and the notice checks the option on admin_notices, the option is already deleted.
**How to avoid:** Cleanup must store state (transient) before deleting the option. The notice checks the transient, renders, then deletes the transient. The option check in the old notice must be replaced with a transient check.

### Pitfall 4: _doing_it_wrong() with option name as first arg
**What goes wrong:** Current code passes `'wp_sudo_governance_mode'` as `$function_name`. WordPress error log shows *"Function wp_sudo_governance_mode was called incorrectly"* — misleading. Debug mode surfaces this to admin users unnecessarily.
**Why it happens:** The existing Phase 12 implementation used the option name as a proxy.
**How to avoid:** Either pass `__METHOD__` (or a real function name), use `error_log()`, or fire an audit action hook instead. Do not pass an option key as the function argument.

### Pitfall 5: UninstallTest multisite scope gap
**What goes wrong:** `test_multisite_uninstall_cleans_user_meta()` does not seed or assert `wp_sudo_governance_mode` deletion from sitemeta. If a future change breaks `delete_site_option('wp_sudo_governance_mode')` in uninstall.php, no test catches it.
**Why it happens:** The existing multisite uninstall test focuses on user meta, not governance options.
**How to avoid:** Verify the existing assertion at line 166 of UninstallTest (single-site): `$this->assertFalse( get_option( 'wp_sudo_governance_mode' ), ... )`. The multisite test currently does NOT assert this. Add: `update_site_option('wp_sudo_governance_mode', 'compatibility')` in arrange, `$this->assertFalse( get_site_option('wp_sudo_governance_mode') )` in assert.

### Pitfall 6: Admin unit tests encode the old notice contract
**What goes wrong:** `test_compatibility_notice_renders_when_option_is_stale()` expects `_doing_it_wrong` called once, `notice-warning` in output, NOT `is-dismissible`. The Phase 13 notice contract changes all three: the dev signal changes, the notice becomes dismissible, and the message text changes.
**Why it happens:** Phase 12 shipped notice tests; Phase 13 changes the notice contract.
**How to avoid:** Update all four existing `render_compatibility_mode_notice()` unit tests in `AdminTest.php` to match the new contract. The test for hook registration (`test_compatibility_notice_registers_admin_and_network_hooks`) may need update if the hook name or priority changes.

---

## Code Examples

### upgrade_4_0_0() idempotency contract
```php
// Source: verified against class-upgrader.php UPGRADES pattern
private function upgrade_4_0_0(): void {
    // Deleting a non-existent option is a no-op — safe for fresh installs.
    if ( is_multisite() ) {
        delete_site_option( 'wp_sudo_governance_mode' );
    } else {
        delete_option( 'wp_sudo_governance_mode' );
    }
}
```

### maybe_upgrade() with 4.0.0 entry — UPGRADES constant only changes
```php
// Append to the end of the UPGRADES constant — do NOT reorder existing entries
private const UPGRADES = array(
    '2.0.0'  => 'upgrade_2_0_0',
    '2.1.0'  => 'upgrade_2_1_0',
    '2.2.0'  => 'upgrade_2_2_0',
    '2.15.0' => 'upgrade_2_15_0',
    '3.0.0'  => 'upgrade_3_0_0',
    '3.3.0'  => 'upgrade_3_3_0',
    '4.0.0'  => 'upgrade_4_0_0',  // NEW: delete stale governance mode option
);
// The wp_roles() priming call in maybe_upgrade() is untouched.
```

### clear-on-detection with transient flag
```php
// admin_init hook (priority 1 — before admin_notices)
public function cleanup_inert_governance_mode_option(): void {
    if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
        return;
    }
    // Check both option stores (option written single-site OR sitemeta on multisite).
    $found = ( false !== get_option( 'wp_sudo_governance_mode' ) );
    if ( is_multisite() ) {
        $found = $found || ( false !== get_site_option( 'wp_sudo_governance_mode' ) );
    }
    if ( ! $found ) {
        return;
    }
    // Set flag BEFORE deleting so the notice can read it on admin_notices.
    set_transient( 'wp_sudo_compat_option_cleared', 1, HOUR_IN_SECONDS );
    delete_option( 'wp_sudo_governance_mode' );
    if ( is_multisite() ) {
        delete_site_option( 'wp_sudo_governance_mode' );
    }
    // Audit signal — avoids the _doing_it_wrong() semantic mismatch.
    do_action( 'wp_sudo_inert_governance_mode_detected' );
}

// admin_notices hook: renders one-time "fixed" confirmation
public function render_compatibility_mode_notice(): void {
    if ( ! wp_sudo_can( 'manage_wp_sudo' ) ) {
        return;
    }
    if ( ! get_transient( 'wp_sudo_compat_option_cleared' ) ) {
        return;
    }
    delete_transient( 'wp_sudo_compat_option_cleared' );

    $message = __( 'WP Sudo removed a leftover permission-mode setting left over from before version 4.0.0. WP Sudo now always enforces strict, role-based permission checks. No action is needed.', 'wp-sudo' );

    printf(
        '<div class="notice notice-success is-dismissible wp-sudo-notice" role="alert"><p>%s</p></div>',
        wp_kses_post( $message )
    );
}
```

### Integration test: upgrade_4_0_0 routine
```php
// In UpgraderTest, following existing test structure
public function test_upgrade_4_0_0_deletes_stale_governance_mode_option(): void {
    $this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.4.0' );
    update_option( 'wp_sudo_governance_mode', 'compatibility' );

    ( new Upgrader() )->maybe_upgrade();

    $this->assertFalse(
        get_option( 'wp_sudo_governance_mode' ),
        'upgrade_4_0_0 must delete wp_sudo_governance_mode.'
    );
    $this->assertSame( WP_SUDO_VERSION, $this->get_wp_sudo_option( Upgrader::VERSION_OPTION ) );
}

public function test_upgrade_4_0_0_is_idempotent_when_option_absent(): void {
    $this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.4.0' );
    // Option not set — must not error.
    ( new Upgrader() )->maybe_upgrade();
    $this->assertSame( WP_SUDO_VERSION, $this->get_wp_sudo_option( Upgrader::VERSION_OPTION ) );
}

public function test_upgrade_4_0_0_does_not_regress_wp_roles_priming(): void {
    // The 4.0.0 upgrade routine MUST NOT move the wp_roles() priming call in maybe_upgrade().
    // This test reproduces the WP 7.0 fatal condition for the full chain.
    $this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '3.3.0' );
    update_option( 'wp_sudo_governance_mode', 'compatibility' );

    $saved_roles = $GLOBALS['wp_roles'] ?? null;
    try {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $GLOBALS['wp_roles'] = null;
        ( new Upgrader() )->maybe_upgrade();
    } finally {
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $GLOBALS['wp_roles'] = $saved_roles;
    }
    // Assert: no fatal; version stamped.
    $this->assertSame( WP_SUDO_VERSION, $this->get_wp_sudo_option( Upgrader::VERSION_OPTION ) );
}
```

### Integration test: clear-on-detection (post-upgrade-stamp edge case)
```php
// In GovernanceTest or a new MigrationNoticeTest in Integration/
// Tests the scenario where upgrade_4_0_0 already ran but option reappears
public function test_clear_on_detection_removes_option_when_already_at_4_0_0(): void {
    // Arrange: version is already 4.0.0, but option was manually re-added.
    $this->update_wp_sudo_option( Upgrader::VERSION_OPTION, '4.0.0' );
    update_option( 'wp_sudo_governance_mode', 'compatibility' );

    $admin = $this->make_admin();
    wp_set_current_user( $admin->ID );
    foreach ( Admin::GOVERNANCE_CAPS as $cap ) {
        $admin->add_cap( $cap );
    }
    wp_set_current_user( $admin->ID );

    // Act: trigger admin_init cleanup.
    $admin_obj = new Admin();
    $admin_obj->cleanup_inert_governance_mode_option();

    // Assert: option deleted, transient set.
    $this->assertFalse( get_option( 'wp_sudo_governance_mode' ) );
    $this->assertNotFalse( get_transient( 'wp_sudo_compat_option_cleared' ) );
}
```

---

## State of the Art

| Old State (Phase 12 output) | Phase 13 Target | Impact |
|-----------------------------|-----------------|--------|
| `render_compatibility_mode_notice()` reads option directly, persistent, non-dismissible | Notice checks transient, dismissible, "fixed" confirmation | Notice truthfully reflects auto-cleanup; no persistent nag |
| `_doing_it_wrong('wp_sudo_governance_mode', ...)` — option name as function arg | Audit action hook `wp_sudo_inert_governance_mode_detected` or `error_log()` | Semantically correct, extensible |
| No `upgrade_4_0_0()` routine — option cleanup only via uninstall | `upgrade_4_0_0()` deletes option on upgrade boundary | State is clean immediately on upgrade |
| No `admin_init` clear-on-detection | `cleanup_inert_governance_mode_option()` on `admin_init` priority 1 | Self-healing edge cases (manually re-added option, non-upgrade path) |
| `test_compatibility_notice_renders_when_option_is_stale` encodes old contract | Updated to encode new contract | Tests truthfully reflect current behavior |

---

## Open Questions

1. **Transient vs. request-scoped flag for clear-on-detection notice**
   - What we know: `set_transient()` persists to DB; a request-scoped static property does not.
   - What's unclear: If cleanup runs on admin_init and the notice renders on admin_notices in the SAME request, a static property (no DB write) would work and be simpler. But if they fire in the same request the static property is trivially true.
   - Recommendation: Use a static boolean flag on the Admin class (set in cleanup, read in notice), since both hooks fire in the same request. This avoids a transient DB write entirely. If the notice must persist to a redirect, use `set_transient()`. The CONTEXT.md description ("self-heals on the next admin page load") implies same-request rendering is expected — static flag is sufficient.

2. **Authority gate on clear-on-detection**
   - What we know: render_compatibility_mode_notice() already gates on `wp_sudo_can('manage_wp_sudo')`. The cleanup hook should also gate.
   - What's unclear: If only the cleanup method is authority-gated and a non-admin page fires admin_init, the option would linger. This is acceptable (only admins trigger cleanup, which is appropriate since only admins can manage Sudo).
   - Recommendation: Gate cleanup on `wp_sudo_can('manage_wp_sudo')` — same as notice. This is consistent.

3. **Multisite: which option table does the migration notice check?**
   - What we know: `render_compatibility_mode_notice()` at line 2499 uses `get_option()` (not `get_site_option()`). On multisite, `wp_sudo_governance_mode` was stored in sitemeta if set via the Admin settings path (which uses `update_site_option` on multisite). `get_option` would read from the current blog's options table, NOT sitemeta — so the notice may never fire on multisite even if the option exists in sitemeta.
   - What's unclear: The Phase 12 notice behavior on multisite (did anyone set it via sitemeta? Was the option stored in sitemeta or per-blog options?).
   - Recommendation: The cleanup method should delete from both tables (delete_option + delete_site_option on multisite). The notice should check both (`get_option` OR `get_site_option`). Alternatively, fix `get_option` to `is_multisite() ? get_site_option() : get_option()`. Verify the existing uninstall pattern (line 180 uses `delete_site_option`) for consistency.

---

## Validation Architecture

> Nyquist validation is enabled (config.json does not set `workflow.nyquist_validation` to false).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 9.6 |
| Config file | `phpunit.xml.dist` (unit) / `phpunit-integration.xml.dist` (integration) |
| Quick run (unit) | `composer test:unit` (~3-4s) |
| Full unit suite | `composer test:unit` |
| Integration suite | `composer test:integration` (requires MySQL + WP test suite) |
| Single file | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | Gap? |
|--------|----------|-----------|-------------------|------|
| MIG-01 | upgrade_4_0_0() deletes wp_sudo_governance_mode on single-site | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | Gap — test does not exist |
| MIG-01 | upgrade_4_0_0() deletes wp_sudo_governance_mode on multisite | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | Gap — test does not exist |
| MIG-01 | upgrade_4_0_0() is idempotent when option absent | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | Gap — test does not exist |
| MIG-01 | WP 7.0 wp_roles() priming survives adding upgrade_4_0_0 | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | Gap (regression guard) |
| MIG-01 | clear-on-detection removes option on same admin request | unit/integration | `./vendor/bin/phpunit tests/Unit/AdminTest.php` | Gap — new behavior |
| MIG-01 | Notice renders "fixed" confirmation (dismissible, plain language) | unit | `./vendor/bin/phpunit tests/Unit/AdminTest.php` | Existing 4 tests must be updated |
| MIG-01 | Dev signal is semantically correct (not option-name as function arg) | unit | `./vendor/bin/phpunit tests/Unit/AdminTest.php` | Existing test expects _doing_it_wrong — must update |
| MIG-02 | WP_SUDO_RECOVERY_MODE grants access; no other break-glass | integration | `./vendor/bin/phpunit tests/Integration/GovernanceTest.php` | Partial — GovernanceTest has stale-option test; recovery mode grant is not tested here |
| MIG-03 | Uninstall deletes wp_sudo_governance_mode single-site | integration | `./vendor/bin/phpunit tests/Integration/UninstallTest.php` | Covered (line 166 assertion) |
| MIG-03 | Uninstall deletes wp_sudo_governance_mode multisite (sitemeta) | integration | `./vendor/bin/phpunit tests/Integration/UninstallTest.php` | Gap — multisite test does not seed/assert this |
| MIG-04 | Multisite upgrade: wp_sudo_governance_mode deleted from sitemeta | integration | `./vendor/bin/phpunit tests/Integration/MultisiteTest.php` | Gap |
| MIG-04 | Multisite upgrade: super-admin capabilities intact after upgrade | integration | `./vendor/bin/phpunit tests/Integration/MultisiteTest.php` | Gap |
| MIG-05 | No bare manage_options access gate in any admin surface | unit (grep is primary; unit test confirms wp_sudo_can routing) | `./vendor/bin/phpunit tests/Unit/AdminTest.php` | Verified via grep — no gaps; unit tests routing is HIGH confidence |
| MIG-06 | First activation grants manage_wp_sudo to administrator | integration | `./vendor/bin/phpunit tests/Integration/UpgraderTest.php` | Partial — upgrade_3_3_0 tested; fresh-activation grant not explicitly tested as "first-run" scenario |
| MIG-06 | Recovery path documented in security/developer docs | docs-only | Manual review | Gap — needs doc update |
| MIG-07 | First activation with NO super admin — recovery mode works | integration | `./vendor/bin/phpunit tests/Integration/GovernanceTest.php` | Gap — no "lockout scenario" test |
| MIG-07 | Compatibility-mode upgrade recovery path (stale option post-stamp) | integration | `./vendor/bin/phpunit tests/Integration/GovernanceTest.php` | Gap — GovernanceTest tests stale-option-is-inert but not clear-on-detection |

### Distinguishing Unit vs Integration

**Unit tests (Brain\Monkey — fast, ~3-4s):**
- Notice rendering: output HTML, notice classes, dismissibility — can mock `get_option`, `get_transient`, `wp_sudo_can`, `delete_option`, `delete_transient`
- Dev signal: `_doing_it_wrong()` never called / audit action fired — mockable
- Hook registration: `admin_init` at priority 1 + `admin_notices` hooks registered correctly
- `manage_wp_sudo` routing in page registration (capability argument to `add_options_page`)

**Integration tests (real DB + WordPress):**
- `upgrade_4_0_0()` deletes from real options/sitemeta tables
- WP 7.0 `wp_roles()` regression (requires real `$GLOBALS['wp_roles']` nulling)
- Uninstall multisite sitemeta deletion
- First-activation grant on real user with administrator role
- Lockout scenario (no super admin, `WP_SUDO_RECOVERY_MODE` is the only access)
- Clear-on-detection deletes from both option stores on multisite

### Sampling Rate
- **Per task commit:** `composer test:unit` (unit tests, ~3-4s, sufficient for Admin/Upgrader unit changes)
- **Per wave merge:** `composer test:integration` (full real-DB integration suite)
- **Phase gate:** Full unit + integration suite green before `/gsd:verify-work`

### Wave 0 Gaps
- No new test files required — all new tests belong in existing files
- No new framework installs — PHPUnit 9.6 already installed
- The existing `AdminTest.php` unit tests for `render_compatibility_mode_notice()` must be updated as part of Wave 1 (they currently encode the Phase 12 contract, not the Phase 13 contract)

---

## Sources

### Primary (HIGH confidence — live source verification)
- `includes/class-upgrader.php` — UPGRADES constant structure, maybe_upgrade() loop, wp_roles() priming at line 86, all six existing routines, version-gating pattern
- `includes/class-admin.php` — render_compatibility_mode_notice() at lines 2492-2515, admin_init hooks at lines 240-254, page registration capability args at lines 284, 303, all wp_sudo_can() call sites
- `includes/functions-governance.php` — wp_sudo_can() (3 paths), wp_sudo_map_governance_meta_cap(), wp_sudo_is_recovery_mode()
- `uninstall.php` — wp_sudo_cleanup_site() at lines 86-105 (single-site), multisite loop at lines 152-187, delete_site_option at line 180
- `tests/Integration/UpgraderTest.php` — existing test structure, tear_down pattern, try/finally for $wp_roles nulling
- `tests/Integration/UninstallTest.php` — existing assertions, governance option assertion at line 166
- `tests/Integration/MultisiteTest.php` — existing multisite tests, missing upgrade/governance coverage
- `tests/Integration/GovernanceTest.php` — BRK-01 and BRK-02 tests; no MIG-07 lockout scenario
- `tests/Unit/AdminTest.php` lines 2369-2432 — four existing notice tests encoding Phase 12 contract
- `tests/Integration/TestCase.php` — make_admin(), update_wp_sudo_option(), get_wp_sudo_option() helpers

### Secondary (MEDIUM confidence)
- Project memory: `project-upgrader-wp-roles-fatal-wp70.md` — WP 7.0 fatal confirmed in production; fix proven on real 7.0 GA

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all libraries already installed; verified test file locations
- Architecture: HIGH — upgrade pattern, uninstall pattern, and notice structure all verified against live source
- Pitfalls: HIGH — all pitfalls are derived from live code inspection and verified gaps
- Capability audit: HIGH — exhaustive grep confirms no bare manage_options gates exist
- Test gaps: HIGH — each gap identified against live test file content

**Research date:** 2026-06-16
**Valid until:** Until any of the following files are modified: class-upgrader.php, class-admin.php, uninstall.php, tests/Integration/UpgraderTest.php, tests/Unit/AdminTest.php
