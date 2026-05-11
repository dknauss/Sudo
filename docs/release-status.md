# Release Status (Canonical Current State)

Last verified: 2026-05-11

This file is the canonical source for **current release state** in this repository:

- the latest public/stable WP Sudo version
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo should advertise in public metadata
- the forward WordPress lane used in CI, Playground, and manual verification
- the current status of the delayed/rescheduled WordPress 7.0 final release

## Latest public/tagged release

- **Latest tagged release:** `3.1.3`
- **Latest git tag observed:** `v3.1.3`

## Current `main` release target

- **Next planned release:** `3.2.0` (planning lane; post-release development bump pending)
- **Current `main` runtime version constant:** `3.1.3`
- **Current metadata should match:** `readme.txt` stable tag, `wp-sudo.php`, `tests/bootstrap.php`, `phpstan-bootstrap.php`
- **Current public stable metadata:** `readme.txt` stable tag `3.1.3`
- **Last completed release checklist:** `docs/release-3.0.0-checklist.md`

## Latest release contents

`3.1.3` includes the post-`v3.1.2` Playground release-link fixes that landed on `main`:

- Stable release Playground links use the tagged raw Blueprint and install the tag ZIP through `pluginData`.
- Current `main` Playground links use `blueprint-main.json` and install the main branch ZIP.
- Pull request Playground previews continue to use the checked-in Blueprint but pin the plugin install to the PR commit.
- Demo admin password seeding uses WordPress core's `wp_set_password()` API instead of a direct `$wpdb->users` update.

Canonical source for post-tag drift after `3.1.3`: `git log v3.1.3..main --oneline`

## WordPress release posture

### Latest stable WordPress release

- **Latest stable major/minor branch:** `6.9`
- **Latest stable patch release observed:** `6.9.4` (released March 11, 2026; verified as latest stable on May 11, 2026)

### Forward lane used by this repository

- **Forward WordPress lane in CI/local previews:** `7.0-RC1`
- This is a forward-compatibility target for testing and preview workflows, **not** the current public `Tested up to` value.

### WordPress 7.0 final release status

- The previously scheduled **April 9, 2026** WordPress 7.0 final release was delayed on **March 31, 2026**.
- The updated Make/Core schedule published on **April 22, 2026** sets the new WordPress 7.0 final release date at **May 20, 2026**.
- Current final-stretch checkpoints are **RC3 on May 8, 2026**, **RC4 on May 14, 2026**, **dry run/code freeze on May 19, 2026**, and **general release on May 20, 2026**.
- As of **May 11, 2026**, WordPress 7.0 is still unreleased; the latest stable public release remains **6.9.4**.

## Public metadata rule

Until WordPress 7.0 final actually ships:

- keep `readme.txt` **Tested up to** at `6.9`
- keep README support badges aligned with the latest stable release line
- record forward 7.0 readiness in `CHANGELOG.md`, `docs/ROADMAP.md`, `tests/MANUAL-TESTING.md`, and this file instead of claiming a final 7.0 public support bump early

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
