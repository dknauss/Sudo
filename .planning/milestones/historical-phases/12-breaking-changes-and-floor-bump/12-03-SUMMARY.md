---
phase: 12-breaking-changes-and-floor-bump
plan: 03
status: complete
completed: 2026-06-16
requirements: [BRK-07]
commits:
  - 8f2c9b4  # docs(12-03): add 4.0.0 breaking-changes CHANGELOG block and readme Upgrade Notice
  - 600fc5d  # docs(12-03): add Migrating to 4.0 section to developer reference
---

# Plan 12-03 Summary — Integrator migration notes (BRK-07)

## What shipped

Migration notes for the v4.0.0 breaking changes in three durable, discoverable
places — and no new standalone file:

1. **`CHANGELOG.md`** — a bold "Breaking changes (4.0.0):" block leading the
   Unreleased section, with separate bullets for the `sudo_can()` removal, the
   `compatibility` governance-mode removal (now strict + inert stale option +
   persistent notice; `WP_SUDO_RECOVERY_MODE` sole break-glass), and the
   WordPress 6.4 / PHP 8.2 floor raises.
2. **`readme.txt`** — a `= 4.0.0 =` entry at the top of `== Upgrade Notice ==`
   with operator-facing action items (replace `sudo_can()` calls; expect strict
   governance + the migration notice if compatibility mode was used; ensure the
   host runs WP 6.4 / PHP 8.2). `Stable tag` unchanged (3.4.0).
3. **`docs/developer-reference.md`** — a new "## Migrating to 4.0" section
   covering the `sudo_can()` → `wp_sudo_can()` swap (identical signature), the
   compatibility-mode removal, and the raised minimums. Also corrected the stale
   Governance Capabilities text that still described `compatibility` mode and the
   deprecated alias as current, and linked it to the new migration section.

## Accuracy / anti-confabulation

Wording was written after reading `12-01-SUMMARY.md` and `12-02-SUMMARY.md` and
cross-checking live code:
- `wp_sudo_can()` signature `(string $cap, ?int $user_id = null): bool` — matches
  `includes/functions-governance.php`.
- The `_doing_it_wrong( 'wp_sudo_governance_mode', …, '4.0.0' )` reference matches
  the `Admin::render_compatibility_mode_notice()` shipped in 12-01.
- Floors (WP 6.4 / PHP 8.2, `composer.json php >=8.2`, CI drops 8.0/8.1) match
  what 12-02 shipped.
- The "6.4 guarantees `wp_get_admin_notice()`" note reflects the BRK-06 finding.

## Assumption stated

`4.0.0` is used as the documented breaking-change / migration-target version, but
this plan did NOT bump `WP_SUDO_VERSION` or `Stable tag` (both stay 3.4.0). The
version/tag bump happens at the release/tagging step.

## Verification at completion

- `grep -i "breaking" CHANGELOG.md` — found (4.0.0 block).
- `grep "= 4.0.0 =" readme.txt` — found (Upgrade Notice).
- `grep "Migrating to 4.0" docs/developer-reference.md` — found.
- Docs-only plan — pre-commit reviewer correctly skipped by the hook (only
  `.md`/`.txt` staged). No new standalone migration file created.
