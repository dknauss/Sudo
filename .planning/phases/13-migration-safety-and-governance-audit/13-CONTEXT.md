# Phase 13: Migration Safety and Governance Audit - Context

**Gathered:** 2026-06-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Verify that upgrading a 3.0–3.4 install to 4.0.0 leaves no broken, undefined, or
lockout-prone state after Phase 12's removals; audit the capability model; confirm
uninstall correctness; and document a lockout-safe first run. This is primarily an
**audit + test-coverage** phase. The one piece of genuinely new production code is a
4.0.0 upgrade routine that deletes the now-meaningless stale governance option.

In scope (MIG-01 … MIG-07): stale-option auto-cleanup; recovery-path verification;
uninstall-correctness verification; multisite upgrade/capability verification;
capability audit (no bare `manage_options` fallback); first-run lockout-safety +
documented recovery; lockout/misconfiguration test coverage.

Out of scope: new governance features or new break-glass mechanisms;
WordPress.org-readiness work (Phase 14); environment checklist (Phase 15).

</domain>

<decisions>
## Implementation Decisions

### Stale `compatibility` option cleanup (MIG-01)
- **Auto-delete via an Upgrader routine** (e.g. `upgrade_4_0_0()`), running on the
  3.x → 4.0.0 upgrade boundary. Since governance is always strict and the option is
  inert, the routine deletes `wp_sudo_governance_mode` outright (no normalize-to-
  'strict' — the value is meaningless now). Delete on **both** single-site
  (`delete_option`) and multisite (`delete_site_option`) to match the existing
  uninstall cleanup.
- This closes the loop Phase 12 opened: the persistent migration notice (BRK-03)
  self-clears after upgrade because the option is gone. The notice remains as
  defense-in-depth for the edge case where the option is present without the routine
  having run (e.g. set manually post-upgrade, or before the upgrade fires).

### Multisite option scope (MIG-04)
- **Verify + add multisite regression tests** — no behavior change unless a real bug
  is found. Confirm the runtime read (`get_option`, main-site scope) and the cleanup
  (routine + uninstall) are consistent on multisite, and that network governance
  state + super-admin capabilities survive the upgrade. Add multisite integration
  coverage for the upgrade and uninstall paths.

### Capability audit + first-run (MIG-05, MIG-06)
- **Verify-then-lock with tests + a brief recovery doc.** Add/confirm tests proving
  every admin/settings/widget surface routes through the dedicated Sudo caps
  (`manage_wp_sudo` etc.) with no bare `manage_options` fallback, and that first
  activation grants `manage_wp_sudo` to an administrator. Document the recovery path
  for a misconfigured grant — `WP_SUDO_RECOVERY_MODE` is the sole break-glass — in
  the existing security/developer docs. No new safeguards or capability mechanisms.

### Test coverage (MIG-07)
- Cover the lockout scenario (first activation with no super admin) and the
  compatibility-mode-upgrade recovery path, per the ROADMAP success criteria.

</decisions>

<specifics>
## Specific Ideas

- **Pre-Implementation Design Review** is advisable before the upgrade routine: it
  touches the migration/upgrade path and interacts with uninstall and the Phase 12
  notice. Per CLAUDE.md (uninstall/capability paths are security-sensitive), write a
  short brief covering: when `upgrade_4_0_0()` fires and its idempotency/version
  gating; that deleting the option is safe because governance is always strict;
  single-site vs multisite delete parity; and the routine↔notice interaction (notice
  becomes defense-in-depth). The audit/test tasks (MIG-03/04/05/06/07) are
  verification and do not each need a separate design review.
- Anti-confabulation: verify the actual upgrader version-gating mechanism and the
  uninstall delete sites against live code before asserting; the WP 7.0 `wp_roles()`
  priming in `maybe_upgrade()` (see [[project-upgrader-wp-roles-fatal-wp70]]) must not
  regress when a new routine is added.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Upgrader** (`includes/class-upgrader.php`): version-keyed routines
  (`upgrade_2_0_0` … `upgrade_3_3_0`) dispatched by `maybe_upgrade()`. The new
  `upgrade_4_0_0()` follows this exact pattern. `maybe_upgrade()` primes `wp_roles()`
  before running routines (WP 7.0 fatal fix — must be preserved).
- **uninstall.php** already deletes `wp_sudo_governance_mode` on BOTH paths:
  `delete_option` (single-site, line ~104) and `delete_site_option` (multisite,
  line ~180), alongside the v1 `site_manager` role removal and `_wp_sudo_*` user-meta
  cleanup. MIG-03 is largely verify-then-lock against this.
- **Governance helpers** (`includes/functions-governance.php`): `wp_sudo_can()` is
  now strict-only; `wp_sudo_is_recovery_mode()` is the sole break-glass.
  `upgrade_3_3_0()` backfills initial `manage_wp_sudo` holders (first-run grant).

### Established Patterns
- No bare `current_user_can('manage_options')` checks exist in production (only a
  docblock mention in functions-governance.php) — MIG-05 starts from a clean base;
  the work is proving/locking it with tests, plus a deeper grep for spacing variants
  and `user_can()` fallbacks.
- Integration tests live in `tests/Integration/` (`UpgraderTest.php`,
  `UninstallTest.php`, `MultisiteTest.php`) — natural homes for MIG coverage.

### Integration Points
- New `upgrade_4_0_0()` deletes the stale option; the Phase 12
  `Admin::render_compatibility_mode_notice()` reads the same option — after the
  routine runs, the notice's condition is false (self-clears).

</code_context>

<deferred>
## Deferred Ideas

- WordPress.org readiness (readme validator, assets, SECURITY.md) — Phase 14.
- Manual environment-matrix checklist — Phase 15.
- No new governance features or break-glass mechanisms — explicitly out of scope.

</deferred>

---

*Phase: 13-migration-safety-and-governance-audit*
*Context gathered: 2026-06-16*
