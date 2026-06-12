# Release Status (Canonical Current State)

Last verified: 2026-06-12

This file is the canonical source for **current release state** in this repository:

- the latest public/stable WP Sudo version
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo should advertise in public metadata
- the forward WordPress lane used in CI, Playground, and manual verification
- the current status of the delayed/rescheduled WordPress 7.0 final release

## Latest public/tagged release

- **Latest tagged release:** `3.3.0`
- **Latest git tag observed:** `v3.3.0`

## Current `main` release target

- **Next planned release:** TBD
- **Current `main` runtime version constant:** `3.3.0`
- **Current metadata should match:** `readme.txt` stable tag, `wp-sudo.php`, `tests/bootstrap.php`, `phpstan-bootstrap.php`
- **Current public stable metadata:** `readme.txt` stable tag `3.3.0`
- **Last completed release checklist:** `docs/release-3.0.0-checklist.md`

## Latest release contents

`3.3.0` is a targeted fix release addressing a strict-mode lockout:

- governance capability backfill re-keyed from the phantom `3.1.0` (never released; tags went v3.1.1 → v3.1.3 → v3.2.0) to `3.3.0`, with an existing-holder guard; sites stored at any public 3.1.x or 3.2.0 now receive the `manage_wp_sudo` and related governance capabilities on next page load
- `Event_Store` audit column clamping before insert
- `wp_sudo_grant_session_on_login` filter (audit register item F17)

Canonical source for post-tag drift after `3.3.0`: `git log v3.3.0..main --oneline`

## Unreleased `main` work

None — `main` is at `3.3.0`.

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

## Public metadata rule

WordPress 7.0 is now the latest stable release:

- update `readme.txt` **Tested up to** to `7.0`
- update README support badges to the `7.0` line
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

1. latest tagged public release or current `main` target version
2. `CHANGELOG.md` unreleased feature list in a way that changes current `main` status
3. latest stable WordPress release line
4. forward WordPress lane (`7.0-RC1`, final `7.0`, etc.)
5. WordPress release-date posture (delays, final date publication, GA completion)
