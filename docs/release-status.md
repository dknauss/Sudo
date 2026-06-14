# Release Status (Canonical Current State)

Last verified: 2026-06-13

This file is the canonical source for **current release state** in this repository:

- the latest GitHub/tagged WP Sudo version
- whether the plugin has been published to the WordPress.org plugin repository
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo package metadata should advertise
- the forward WordPress lane used in CI, Playground, and manual verification
- the current status of the delayed/rescheduled WordPress 7.0 final release

## Latest GitHub/tagged release

- **Latest tagged release:** `3.4.0`
- **Latest git tag observed:** `v3.4.0`

## Current `main` release target

- **Next planned release:** TBD
- **Current `main` runtime version constant:** `3.4.0`
- **Current metadata should match:** `readme.txt` stable tag, `wp-sudo.php`, `tests/bootstrap.php`, `phpstan-bootstrap.php`
- **Current package metadata:** `readme.txt` stable tag `3.4.0`
- **Last completed release checklist:** `docs/release-3.0.0-checklist.md`

## WordPress.org publication status

- **WordPress.org plugin repository:** not published.
- **`readme.txt` stable tag:** package/release metadata for generated plugin zips and future WordPress.org publication; it does not indicate that this plugin is currently live in the WordPress.org repository.

## Latest release contents

`3.4.0` is a hardening release focused on recovery-mode containment, CI reliability, and documentation accuracy:

- `WP_SUDO_RECOVERY_MODE` is role-gated to existing administrators/super administrators, visible on the Sudo settings screen while active, and auditable through the new `wp_sudo_recovery_mode_active` hook.
- Psalm analysis is repaired so the type-coverage gate fails loudly if it stops reporting coverage; `uninstall.php` is excluded from Psalm analysis because its top-level uninstall guard exits by design.
- CI workflows now declare least-privilege permissions and documentation-only pull requests skip heavy jobs without deadlocking required checks.
- The documentation audit corrected stale/confabulated technical details and points drift-prone counts at `docs/current-metrics.md`.
- Playground demo coverage expanded with recovery-mode and user-switching scenarios.

Canonical source for post-tag drift after `3.4.0`: `git log v3.4.0..main --oneline`

## Unreleased `main` work

Current commits ahead of `v3.4.0`:

- Roadmap additions for a Connectors registry-aware matcher, a v4.0.0 breaking-change milestone, and Gutenberg design scope.
- E2E CI balancing that replaces opaque Playwright sharding with explicit
  challenge/admin, 2FA/UI, lockout/surface, and replay/multisite groups.

## WordPress release posture

### Latest stable WordPress release

- **Latest stable major/minor branch:** `7.0`
- **Latest stable patch release observed:** `7.0` GA (released May 20, 2026; verified as latest stable on June 8, 2026)

### Forward lane used by this repository

- **Forward WordPress lane in CI/local previews:** `7.0`
- CI and browser smoke lanes have been updated from `7.0-RC1` to the `7.0` GA release.

### WordPress 7.0 final release status

- WordPress 7.0 **shipped on May 20, 2026** per the updated Make/Core schedule published April 22, 2026.
- The `Tested up to` value in `readme.txt` and README support badges should now reflect `7.0`.

## Package metadata rule

WordPress 7.0 is now the latest stable release for package compatibility metadata:

- keep `readme.txt` **Tested up to** at `7.0`
- keep README support badges aligned with the `7.0` line
- CI forward lane is already `7.0` GA; no further lane change needed until 7.1 development opens

## Canonical sources

### Repository sources

- `wp-sudo.php`
- `readme.txt`
- `CHANGELOG.md`
- `docs/current-metrics.md`
- `tests/MANUAL-TESTING.md`

### External sources

- WordPress release archive: <https://wordpress.org/download/releases/>
- WordPress download page: <https://wordpress.org/download/>
- WordPress 7.0 release page: <https://make.wordpress.org/core/7-0/>
- WordPress 7.0 updated release party schedule: <https://make.wordpress.org/core/2026/04/22/wordpress-7-0-release-party-updated-schedule/>
- Delay announcement: <https://make.wordpress.org/core/2026/03/31/extending-the-7-0-cycle/>

## Update procedure

Update this file whenever any of the following changes:

1. latest tagged release, WordPress.org publication status, or current `main` target version
2. `CHANGELOG.md` unreleased feature list in a way that changes current `main` status
3. latest stable WordPress release line
4. forward WordPress lane (`7.0-RC1`, final `7.0`, etc.)
5. WordPress release-date posture (delays, final date publication, GA completion)
