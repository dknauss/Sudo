# Release Status (Canonical Current State)

Last verified: 2026-06-16

This file is the canonical source for **current release state** in this repository:

- the latest GitHub/tagged WP Sudo version
- whether the plugin has been published to the WordPress.org plugin repository
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo package metadata should advertise
- the forward WordPress lane used in CI, Playground, and manual verification
- the current WordPress 7.0 GA posture and next forward-lane assumptions

## Latest GitHub/tagged release

- **Latest tagged release:** `3.4.0`
- **Latest git tag observed:** `v3.4.0`

## Current `main` release target

- **Next planned release:** `4.0.0` — active milestone **v4.0.0 (Pre-Public Hardening Baseline)**, in development on `main`. Breaking release (see Unreleased work below).
- **Current `main` runtime version constant:** `3.4.0` — **not yet bumped.** Per the v4.0.0 plan, the platform *floor* declarations were raised on `main` (Requires at least `6.4`, Requires PHP `8.2`) while `WP_SUDO_VERSION` / `Stable tag` deliberately stay `3.4.0`; the version bump to `4.0.0` happens at tag time.
- **Current metadata should match:** `readme.txt` stable tag, `wp-sudo.php` (header + `WP_SUDO_VERSION`), `tests/bootstrap.php`, `phpstan-bootstrap.php` — all still `3.4.0` until the release bump.
- **Current package metadata:** `readme.txt` stable tag `3.4.0`; `Requires at least 6.4`, `Requires PHP 8.2`, `Tested up to 7.0`.
- **Last archived release checklist:** `docs/archive/release-3.0.0-checklist.md`

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

Commits ahead of `v3.4.0` (canonical source: `git log v3.4.0..main --oneline`).
This is the v4.0.0 milestone in progress. Phases 11–12 have landed; phases 13–15
(migration-safety audit, WordPress.org readiness, manual-testing environment matrix)
are planned/not yet executed.

**Breaking changes (v4.0.0 — not yet tagged):**

- **`sudo_can()` removed.** The deprecated unprefixed alias is gone; calling it is a
  fatal undefined-function error. Use `wp_sudo_can()` (identical signature).
- **`compatibility` governance mode removed.** Governance is always *strict*
  (capability checks against the dedicated `manage_wp_sudo` family). A stale
  `wp_sudo_governance_mode = 'compatibility'` option is inert and triggers a
  persistent admin notice plus a `_doing_it_wrong()` developer warning until removed
  (automatic cleanup is planned for a later v4.0.0 phase). `WP_SUDO_RECOVERY_MODE`
  remains the sole break-glass path.
- **Minimum platform floor raised:** WordPress `6.2 → 6.4`, PHP `8.0 → 8.2`
  (`composer.json` requires `php >=8.2`; CI drops the 8.0/8.1 lanes and the
  `php80-tests` infrastructure). `WP_SUDO_VERSION` itself is not bumped on `main`.

**New gating coverage:**

- **Connectors registry-aware matcher (WP 7.0).** `connectors.update_credentials`
  now matches connector API-key writes to `POST /wp/v2/settings` with a two-tier
  matcher: tier 1 reads the WordPress 7.0 Connectors registry (`wp_get_connectors()`,
  `function_exists()`-guarded) and gates every setting belonging to an `api_key`
  connector; tier 2 retains the `^connectors_[a-z0-9_]+_api_key$` regex as a union
  fallback. Closes a false-negative where Akismet's `wordpress_api_key` was ungated
  on WP 7.0. Verified against WordPress 7.0 GA.

**Reliability:**

- **WordPress 7.0 upgrade-path fatal fixed.** `Upgrader::maybe_upgrade()` primes
  `wp_roles()` before the capability-query migration, which otherwise fataled
  (`for_site() on null`) under WP-CLI/cron at `plugins_loaded` on WP 7.0.

**CI / tooling:**

- **Plugin Check CI** builds a clean production dist, runs the official Plugin Check
  plugin through `wp-env`, and fails on reported PCP errors.
- **E2E CI balancing** replaces opaque Playwright sharding with explicit
  challenge/admin, 2FA/UI, lockout/surface, and replay/multisite groups.

**Docs / assets:**

- v4.0.0 migration notes (CHANGELOG breaking-changes block, `readme.txt` Upgrade
  Notice, developer-reference "Migrating to 4.0") — including the rationale for why
  `compatibility` mode was added (3.2.0 transitional bridge) and removed.
- README / WordPress.org screenshot assets refreshed (settings, Access, Rule Tester,
  dashboard activity, recovery-mode screens); README copy clarified re: the sudo
  window. Historical planning docs moved under `docs/archive/`.

**Pre-tag checklist reminder:** before tagging `4.0.0`, bump `WP_SUDO_VERSION` in all
four places + `readme.txt` stable tag, re-verify external claims, and update this
file's "Latest tagged release".

## WordPress release posture

### Latest stable WordPress release

- **Latest stable major/minor branch:** `7.0`
- **Latest stable patch release observed:** `7.0` GA (released May 20, 2026; verified as latest stable on June 14, 2026)

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
4. forward WordPress lane (current GA release or future RC/preview lane)
5. WordPress release-date posture (delays, final date publication, GA completion)
