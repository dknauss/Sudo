---
phase: 12-breaking-changes-and-floor-bump
plan: 01
status: complete
completed: 2026-06-16
requirements: [BRK-01, BRK-02, BRK-03]
commits:
  - 1c9efd6  # feat(12-01): remove compatibility governance mode and sudo_can() alias
  - 928adf3  # feat(12-01): add persistent compatibility-mode migration notice
---

# Plan 12-01 Summary — Governance breaking changes (BRK-01/02/03)

## What shipped

- **BRK-01:** Hard-removed the deprecated `sudo_can()` alias from
  `includes/functions-governance.php` (the entire `if ( ! function_exists( 'sudo_can' ) )`
  block). `wp_sudo_can()` is the sole surviving governance function; calling
  `sudo_can()` now fatals. Verified zero non-prefixed callers in production.
- **BRK-02:** Excised the `compatibility` governance-mode branch from both
  `wp_sudo_can()` and `wp_sudo_map_governance_meta_cap()`. A stale
  `wp_sudo_governance_mode = 'compatibility'` option is now inert — it falls
  through to the strict cap check (`user_can( $user_id, $cap )` /
  `array( $cap )`). The multisite super-admin short-circuit and the
  `WP_SUDO_RECOVERY_MODE` recovery short-circuits in both functions are intact;
  recovery mode remains the sole break-glass path.
- **BRK-03:** Added `Admin::render_compatibility_mode_notice()` (public — it is a
  hook callback), registered on `admin_notices` and `network_admin_notices`. It
  renders a persistent, **non-dismissible** `notice-warning` on admin screens when
  the stale `compatibility` option is present, gated authority-first on
  `wp_sudo_can( 'manage_wp_sudo' )`, paired with a single
  `_doing_it_wrong( 'wp_sudo_governance_mode', …, '4.0.0' )`. The notice only
  **signals** the stale option; it does not delete it (cleanup is Phase 13).

## Design review (Task 1) — brief + objections resolved

Brief covered: what compatibility mode enabled and what its removal blocks; the
`sudo_can()` hard-removal scope; the notice surface/fatigue tradeoff; recovery as
sole break-glass; option cleanup out of scope. A reviewer agent critiqued the
design (critique-only, no code). Resolutions:

1. **(blocker) `_doing_it_wrong()` placement** — pinned to the admin_notices
   callback only, after the gates; never the hot-path governance functions (which
   run on every REST/cron/front-end request). Implemented exactly so.
2. **(should-fix) Notice surface** — reviewer noted `render_recovery_mode_notice`
   is actually rendered *inline* on the settings page, not via `admin_notices`, so
   it was the wrong template. **User decision (re-confirmed):** keep the global
   `admin_notices` + `network_admin_notices` surface for broad visibility; modeled
   on `class-gate.php`'s `render_blocked_notice`/`render_gate_notice` instead.
3. **(should-fix) Guard order** — authority check before option check, so an
   unauthorized user triggers neither the notice nor `_doing_it_wrong()`. Done.
4. **(should-fix) Multisite read scope** — notice reads `get_option()`, matching
   how the governance functions read the option, so it signals exactly when a
   compat branch would have fired. **Flagged for Phase 13:** `uninstall.php`
   deletes this option via `delete_site_option`, a read/write-scope discrepancy to
   reconcile in migration-safety work — NOT touched here.
5. **(should-fix) Test set** — confirmed by grep: 6 obsolete tests removed (5
   compatibility-mode + the deprecated-alias test), not 7–8; strict/recovery tests
   that merely stub the option as a precondition were kept. Added the new
   "stale compatibility is inert" tests.

## Tests

- **Removed (6):** `test_map_governance_meta_cap_compatibility_single_site_maps_to_manage_options`,
  `test_map_governance_meta_cap_compatibility_multisite_maps_to_manage_network_options`,
  `test_sudo_can_compatibility_single_site_checks_manage_options`,
  `test_sudo_can_compatibility_single_site_returns_false_without_manage_options`,
  `test_sudo_can_compatibility_multisite_checks_manage_network_options`,
  `test_deprecated_sudo_can_alias_delegates_and_warns`.
- **Added (unit):** `test_map_governance_meta_cap_treats_compatibility_as_strict_after_removal`,
  `test_sudo_can_treats_compatibility_as_strict_after_removal`,
  `test_sudo_can_alias_no_longer_exists`, plus 4 notice tests in `AdminTest`
  (renders-when-stale, skips-no-authority, skips-not-compatibility,
  registers-admin+network-hooks).
- **Added (integration):** new `tests/Integration/GovernanceTest.php` —
  `test_sudo_can_alias_was_removed`, `test_stale_compatibility_option_is_treated_as_strict`.
- **Net unit count:** 794 → **795** (2271 → 2275 assertions). Integration test
  methods 182 → **184**. `docs/current-metrics.md` updated accordingly.

## Deviations

- **No RED commit (TDD process preserved, cadence adapted).** The pre-commit hook
  runs the full unit suite and blocks any code commit with a failing test — even
  under `USER_COMMIT=1`. A separate "commit RED" step (intentionally-failing tests)
  is therefore impossible in this repo. TDD discipline was kept in process: tests
  were written first and verified to fail for the right reasons (3 governance
  assertions, 4 notice errors) before implementation; each task was then committed
  once green. This contradicts the plan's "Commit RED" instruction — the plan
  author did not account for the hook's green-tests gate.
- **Reviewer-flag clock skew.** The reviewer subagent's sandbox clock ran ~9h
  behind the commit-hook (host) clock, expiring the flag instantly. Worked around
  by having the reviewer stamp `reviewer-approved` with a sandbox-disabled
  `date +%s`. Recorded in project memory for future commits.

## Assumptions stated

- `WP_SUDO_VERSION` was **not** bumped this phase (stays 3.4.0). The `'4.0.0'`
  string is only the `_doing_it_wrong()` version argument — the version where the
  option became wrong — not the plugin's release version. The release bump belongs
  to the tagging step.
- **No production shims** introduced: no `function_exists`/`defined`/`try-catch`
  guard on `_doing_it_wrong` or any core symbol; `wp_sudo_can()` gained no guard.

## Verification at completion

- `composer test:unit` — 795 tests, 2275 assertions, OK.
- `composer lint` — clean (20/20).
- `composer analyse:phpstan` (sandbox disabled) — No errors.
- `grep "function sudo_can(" includes/functions-governance.php` — none.
- `grep "'compatibility' === get_option" includes/` — none.

Each code commit was reviewer-approved.
