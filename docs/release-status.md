# Release Status (Canonical Current State)

Last verified: 2026-06-30

This file is the canonical source for **current release state** in this repository:

- the latest GitHub/tagged WP Sudo version
- whether the plugin has been published to the WordPress.org plugin repository
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo package metadata should advertise
- the forward WordPress lane used in CI, Playground, and manual verification
- the current WordPress 7.0 GA posture and next forward-lane assumptions

## Latest GitHub/tagged release

- **Latest tagged release:** `4.2.2`
- **Latest git tag observed:** `v4.2.2` (annotated, cut 2026-06-28).

## Current `main` release state

- **Current `main` version:** `4.2.2` — tagged as `v4.2.2` after the Access-tab gap closure and release-planning refresh.
- **Runtime version constant:** `4.2.2` on `main`. `WP_SUDO_VERSION` is set in `wp-sudo.php` (header + constant), `tests/bootstrap.php`, and `phpstan-bootstrap.php`; `readme.txt` Stable tag is `4.2.2`. All five version-sync points are in sync.
- **Current package metadata (on `main`):** `readme.txt` Stable tag `4.2.2` == header Version (no `stable_tag_mismatch`); `Requires at least 6.4`, `Requires PHP 8.2`, `Tested up to 7.0`. WordPress.org listing name: **"Sudo – Admin Action Gating"** (UI brand "Sudo"; slug/text-domain stay `wp-sudo` — lock the slug at submission).
- **Last archived release checklist:** `docs/archive/release-3.0.0-checklist.md`

## WordPress.org publication status

- **WordPress.org plugin repository:** not published. Submission is intentionally delayed/on hold, but the repository should remain submission-ready at any time.
- **Readiness source:** `docs/wporg-submission-checklist.md` is the operational checklist to keep ready for publication approval.
- **Release environment assurance source:** `docs/release-environment-log.md` records per-version manual environment outcomes and deferrals.
- **E2E runtime evidence source:** [`docs/e2e-runtime-review.md`](e2e-runtime-review.md) records refreshed post-`v4.2.2` GitHub Actions E2E job runtimes and the current CI tuning decision; it is release-readiness evidence, not a WordPress.org submission gate.
- **Release confidence E2E source:** [`docs/release-e2e-confidence.md`](release-e2e-confidence.md) documents the manual release-grade E2E workflow across Apache/wp-env, nginx, nginx multisite, and Playground SQLite smoke targets.
- **Sudo fundamentals source:** [`docs/sudo-lite/fundamentals-cross-check.md`](sudo-lite/fundamentals-cross-check.md) preserves the Psudo Lite/Sudo Lite baseline used to review WP Sudo changes for fidelity to the core reauthentication model.
- **`readme.txt` stable tag:** package/release metadata for generated plugin zips and future WordPress.org publication; it does not indicate that this plugin is currently live in the WordPress.org repository.

## Latest release contents

`4.2.2` is a release-readiness refresh after Phase 13.1 gap closure:

- **Access tab polish:** Grant Capability now has a searchable administrator picker with tests while preserving the numeric `user_id` grant contract.
- **Canonical metrics and screenshot refresh:** `docs/current-metrics.md` verifies cleanly and `.wordpress-org/screenshot-6.png` shows the searchable picker.
- **Planning status refresh:** release/planning docs now state that WordPress.org submission is intentionally delayed/on hold while the repo remains submission-ready.

`4.2.1` was a WordPress.org package-readiness release:

- **Plugin Check input cleanup:** request values are unslashed at the flagged sites before sanitization, and the 4.0.0 upgrade notice fits directory limits.
- **Submission warning triage:** Pressship verifies with one documented slug warning for the intended `wp-sudo` slug; bridge/core-hook/prepared-SQL false positives are documented or scoped in code.
- **Release hygiene:** current metrics and Psalm baseline entries were refreshed after the cleanup.

`4.2.0` was the post-4.1.0 hardening and integration release:

- **Two Factor bridge hardening:** REST factor-management operations in the optional Two Factor bridge are gated behind WP Sudo.
- **WSAL bridge expansion:** the optional WP Activity Log sensor bridge maps the newer security/governance audit hooks into WSAL events, including escalation blocks, session revocation, recovery-mode use, governance-capability changes, missing built-in rules, and regex-rule failures.
- **Gutenberg REST UX groundwork:** cookie-authenticated REST `sudo_required` responses include a `challenge_url` so editor clients can send the user to reauthenticate without using server-side Request_Stash replay; headless REST policy responses remain unchanged.
- **Test hardening:** integration coverage was added for activation/deactivation lifecycle behavior, `WP_Session_Tokens::destroy_all()` login-session-binding invariants, and live admin-escalation guard hooks.
- **Planning/reference updates:** the Gutenberg route inventory, build-free Phase 2 decision, API-only config surfaces, and accepted blog-invariant Connectors matcher cache behavior are documented.

`4.1.0` (tagged 2026-06-24) remains the security-hardening release that closed the coordinated-disclosure gate-completeness findings and introduced the opt-in admin-escalation guard.

Canonical source for post-tag drift after `v4.2.2`: `git log v4.2.2..main --oneline`.

## Unreleased `main` work

Unreleased work after `v4.2.2` currently includes:

- **Two Factor profile-provider lifecycle bridge:** the optional Two Factor lifecycle bridge (`bridges/wp-sudo-two-factor-lifecycle-bridge.php`) now gates meaningful classic `profile.php` / `user-edit.php` provider lifecycle changes behind an active WP Sudo session, alongside the existing REST factor-management gates. Unrelated profile saves and normalized no-op resubmissions are not gated. This completes the v4.4.0 Two Factor Lifecycle Bridge milestone.
- **Canonical metrics refresh:** `docs/current-metrics.md` updated to reflect Phase 22 unit test additions (893 tests / 2,676 assertions) and bridge production line growth.
- **Localization packaging readiness:** WP-CLI-backed Composer commands for POT generation/freshness verification, a committed `languages/wp-sudo.pot`, targeted translator-comment cleanup, and release documentation updates.
- **E2E runtime evidence:** refreshed post-`v4.2.2` GitHub Actions E2E job runtimes in [`docs/e2e-runtime-review.md`](e2e-runtime-review.md).

Canonical source for drift after the tag: `git log v4.2.2..main --oneline`.

### Release environment assurance

Phase 17 added [`docs/release-environment-log.md`](release-environment-log.md) as the record of record for release-grade manual environment matrix outcomes. The current `v4.2.2` package row is explicitly **Deferred**: Apache stack, managed WordPress host, and minimum-supported-WordPress lanes were not rerun in Phase 17 and each is owned by the Maintainer before the next public tag/publication decision, blocking unless explicitly waived.

Release readiness now distinguishes **Pre-tag/core** gates from **WordPress.org-only** gates. Pre-tag/core gates cover version sync, Composer validation commands, external-claim audit, changelog/readme/release-status sanity, package metadata, and the release environment matrix/log gate. WordPress.org-only gates cover the readme validator, clean-package Plugin Check, SVN layout/upload, listing assets, screenshot/caption parity, slug-lock decision, and final publication approval. WordPress.org submission/upload remains delayed/on hold.

### E2E runtime evidence

Phase 18 added [`docs/e2e-runtime-review.md`](e2e-runtime-review.md) as the durable GitHub Actions E2E runtime review. Refreshed post-`v4.2.2` evidence found `E2E Tests 1/4 (challenge-basic-admin)` to be the repeatable long pole and records exactly one no-coverage-loss follow-up proposal: rebalance a small test slice within the existing four baseline groups. No workflow file was edited in Phase 18, and this review remains CI evidence rather than a WordPress.org publication gate.

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
- `docs/release-environment-log.md`

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
