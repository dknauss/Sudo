# Release Status (Canonical Current State)

Last verified: 2026-06-27

This file is the canonical source for **current release state** in this repository:

- the latest GitHub/tagged WP Sudo version
- whether the plugin has been published to the WordPress.org plugin repository
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo package metadata should advertise
- the forward WordPress lane used in CI, Playground, and manual verification
- the current WordPress 7.0 GA posture and next forward-lane assumptions

## Latest GitHub/tagged release

- **Latest tagged release:** `4.2.0`
- **Latest git tag observed:** `v4.2.0` (annotated, cut 2026-06-27 at commit `e1f9fe2`).

## Current `main` release state

- **Current `main` version:** `4.2.0` — tagged as `v4.2.0` after the post-`v4.1.0` fixes and feature hardening.
- **Runtime version constant:** `4.2.0` on `main`. `WP_SUDO_VERSION` is set in `wp-sudo.php` (header + constant), `tests/bootstrap.php`, and `phpstan-bootstrap.php`; `readme.txt` Stable tag is `4.2.0`. All five version-sync points are in sync.
- **Current package metadata (on `main`):** `readme.txt` Stable tag `4.2.0` == header Version (no `stable_tag_mismatch`); `Requires at least 6.4`, `Requires PHP 8.2`, `Tested up to 7.0`. WordPress.org listing name: **"Sudo – Admin Action Gating"** (UI brand "Sudo"; slug/text-domain stay `wp-sudo` — lock the slug at submission).
- **Last archived release checklist:** `docs/archive/release-3.0.0-checklist.md`

## WordPress.org publication status

- **WordPress.org plugin repository:** not published.
- **`readme.txt` stable tag:** package/release metadata for generated plugin zips and future WordPress.org publication; it does not indicate that this plugin is currently live in the WordPress.org repository.

## Latest release contents

`4.2.0` is a post-4.1.0 hardening and integration release:

- **Two Factor bridge hardening:** REST factor-management operations in the optional Two Factor bridge are gated behind WP Sudo.
- **WSAL bridge expansion:** the optional WP Activity Log sensor bridge maps the newer security/governance audit hooks into WSAL events, including escalation blocks, session revocation, recovery-mode use, governance-capability changes, missing built-in rules, and regex-rule failures.
- **Gutenberg REST UX groundwork:** cookie-authenticated REST `sudo_required` responses include a `challenge_url` so editor clients can send the user to reauthenticate without using server-side Request_Stash replay; headless REST policy responses remain unchanged.
- **Test hardening:** integration coverage was added for activation/deactivation lifecycle behavior, `WP_Session_Tokens::destroy_all()` login-session-binding invariants, and live admin-escalation guard hooks.
- **Planning/reference updates:** the Gutenberg route inventory, build-free Phase 2 decision, API-only config surfaces, and accepted blog-invariant Connectors matcher cache behavior are documented.

`4.1.0` (tagged 2026-06-24) remains the security-hardening release that closed the coordinated-disclosure gate-completeness findings and introduced the opt-in admin-escalation guard.

Canonical source for post-tag drift after `v4.2.0`: `git log v4.2.0..main --oneline`.

## Unreleased `main` work

No unreleased work is expected immediately after tagging `v4.2.0`. Canonical source for drift after the tag: `git log v4.2.0..main --oneline`.

**Pre-tag checklist reminder:** before tagging a future release, confirm the five version-sync points are still in sync, re-verify external claims added since the previous tag, ensure the CHANGELOG/`readme.txt` release entries are dated/current, and update this file's "Latest tagged release" once the tag is cut.

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
