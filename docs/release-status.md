# Release Status (Canonical Current State)

Last verified: 2026-06-24

This file is the canonical source for **current release state** in this repository:

- the latest GitHub/tagged WP Sudo version
- whether the plugin has been published to the WordPress.org plugin repository
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo package metadata should advertise
- the forward WordPress lane used in CI, Playground, and manual verification
- the current WordPress 7.0 GA posture and next forward-lane assumptions

## Latest GitHub/tagged release

- **Latest tagged release:** `4.0.0`
- **Latest git tag observed:** `v4.0.0` (annotated, cut 2026-06-21 at commit `a4c1e67`)

## Current `main` release target

- **Current `main` development version:** `4.1.0` (**unreleased — not yet tagged**). `WP_SUDO_VERSION` was bumped from `4.0.0` to `4.1.0` on `main` to open the 4.1.0 line, matching the `@since 4.1.0` annotations and the `## 4.1.0 - unreleased` CHANGELOG section for the post-4.0.0 gate-completeness work (PRs #102, #104, #105, #107). The latest **tagged** release remains `v4.0.0` (2026-06-21); `v4.1.0` will be tagged when the 4.1.0 line is cut.
- **Latest tagged release:** `4.0.0` — milestone **v4.0.0 (Pre-Public Hardening Baseline)**, **tagged `v4.0.0` 2026-06-21**. Breaking release. All phases (11, 12, 13, 13.1, 14, 15) merged to `main`; PR [#99](https://github.com/dknauss/Sudo/pull/99) (Phase 14, WordPress.org readiness) merged as `7b7b85a`.
- **Runtime version constant:** `4.1.0` on `main`. `WP_SUDO_VERSION` is set in `wp-sudo.php` (header + constant), `tests/bootstrap.php`, and `phpstan-bootstrap.php`; `readme.txt` Stable tag is `4.1.0`. All five in sync. (Stable tag is package/zip metadata for the unreleased 4.1.0 line; see WordPress.org publication status below — the plugin is not published.)
- **Current package metadata (on `main`):** `readme.txt` Stable tag `4.1.0` == header Version (no `stable_tag_mismatch`); `Requires at least 6.4`, `Requires PHP 8.2`, `Tested up to 7.0`. WordPress.org listing name: **"Sudo – Admin Action Gating"** (UI brand "Sudo"; slug/text-domain stay `wp-sudo` — lock the slug at submission).
- **Last archived release checklist:** `docs/archive/release-3.0.0-checklist.md`

## WordPress.org publication status

- **WordPress.org plugin repository:** not published.
- **`readme.txt` stable tag:** package/release metadata for generated plugin zips and future WordPress.org publication; it does not indicate that this plugin is currently live in the WordPress.org repository.

## Latest release contents

`4.0.0` (tagged 2026-06-21) is a breaking, pre-public hardening release:

- **Breaking:** the `sudo_can()` alias was removed (use `wp_sudo_can()`); the `compatibility` governance mode was removed (governance is always strict; the inert `wp_sudo_governance_mode` option is auto-removed); the minimum platform floor was raised to WordPress 6.4 / PHP 8.2.
- **Connectors registry-aware matcher (WP 7.0):** `connectors.update_credentials` gates connector API-key writes to `POST /wp/v2/settings` via the WordPress 7.0 Connectors registry with a regex fallback, closing a false-negative on Akismet's `wordpress_api_key`.
- **WordPress 7.0 upgrade-path fatal fixed**, Plugin Check CI added, E2E groups rebalanced, and WordPress.org readiness completed (listing name "Sudo – Admin Action Gating", SECURITY.md, refreshed screenshots, manual environment matrix).

Canonical source for post-tag drift after `4.0.0`: `git log v4.0.0..main --oneline`

## Unreleased `main` work

Canonical source for `main`: `git log v4.0.0..main --oneline`. The unreleased
**4.1.0** line is security-hardening focused:

- **Gate-completeness fixes — coordinated disclosure ([#102](https://github.com/dknauss/Sudo/pull/102)).**
  An interactive effect-level backstop on `admin_init` hard-blocks the unambiguous
  destructive core effects (`delete_user`, `delete_plugin`, `delete_theme`,
  `activate_plugin`, `upgrader_pre_install`, `export_wp`) reached through a
  non-enumerated handler, and the sudo proof is now bound to the WordPress login
  session that created it (F1/F2; affected versions ≤ 4.0.0).
- **REST effect-level backstop ([#104](https://github.com/dknauss/Sudo/pull/104)).**
  `register_rest_backstop()` extends the effect-level block to custom REST routes,
  mirroring `intercept_rest()`'s App-Password policy / auth classification.
- **Login-session-binding integration tests + architectural-metrics enforcement
  ([#105](https://github.com/dknauss/Sudo/pull/105)).**
- **Bool REST auth classifier + unified `die_sudo_required()` helper
  ([#107](https://github.com/dknauss/Sudo/pull/107), behavior-preserving refactor).**
- **Role-aware admin-escalation guard** — analysis
  ([#109](https://github.com/dknauss/Sudo/pull/109)), implementation
  ([#111](https://github.com/dknauss/Sudo/pull/111)). Opt-in via the
  `wp_sudo_guard_escalation` filter (**default OFF**): blocks a *newly granted*
  administrator (single-site) / super-admin (multisite) without an active sudo
  session by hooking the `{prefix}capabilities` meta write and `grant_super_admin`;
  defers on CLI/Cron/XML-RPC and on Unrestricted REST Application-Password requests;
  allowlist filter `wp_sudo_allow_escalation` + `WP_SUDO_ALLOW_ESCALATION` constant;
  high-severity `wp_sudo_escalation_blocked` event. **Known limits:** administrator
  capabilities conferred at runtime via `user_has_cap`/`map_meta_cap` or written
  directly with `$wpdb` are not caught, and the residual window is an escalation
  during a legitimate admin's own active sudo session.
- **Changelog ([#112](https://github.com/dknauss/Sudo/pull/112))** documents the
  escalation guard under the 4.1.0 line.

All five version-sync points (three `WP_SUDO_VERSION` constants + plugin header +
`readme.txt` stable tag) read `4.1.0`.

**Pre-tag checklist reminder:** before tagging `v4.1.0`, confirm those five are
still in sync, re-verify external claims added since `v4.0.0`, ensure the
CHANGELOG/`readme.txt` 4.1.0 entries are dated, and update this file's "Latest
tagged release" once the tag is cut.

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
