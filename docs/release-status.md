# Release Status (Canonical Current State)

Last verified: 2026-07-06

This file is the canonical source for **current release state** in this repository:

- the latest GitHub/tagged WP Sudo version
- whether the plugin has been published to the WordPress.org plugin repository
- unreleased work already present on `main`
- the latest stable WordPress release WP Sudo package metadata should advertise
- the forward WordPress lane used in CI, Playground, and manual verification
- the current WordPress 7.0 GA posture and next forward-lane assumptions

## Latest GitHub/tagged release

- **Latest tagged release:** `4.5.0` (the `v4.6.0` tag is **staged but not yet cut** — see below).
- **Latest git tag observed:** `v4.5.0` (annotated, cut 2026-07-05, on `70cddfe`).
- **Previous tag:** `v4.2.2` (annotated, cut 2026-06-28).
- **Unreleased work beyond the tag:** `main` is bumped to `4.6.0` across all five version-sync points, staged for a `v4.6.0` tag that has **not** been cut yet. Until it is, the latest actual tag remains `v4.5.0` and `main` is ahead of it. **The staged release was re-scoped `4.5.1` → `4.6.0` (MINOR)** after the block-editor in-editor reauthentication work (PRs #165, #168) landed on top of the original admin-UI-only `4.5.1` payload: a new user-facing capability, plus the optional critical-event alert bridge's documented public extension filters (#166), is a backward-compatible **addition**, not a patch (see `VERSIONING.md`). The `4.6.0` payload is: block-editor in-editor reauth (link-out increment), the optional critical-event alert bridge + inline demo companion, the admin-surface user-identity harmonization (PR #154), and the alerting/roadmap docs. The exact commit set is `git log v4.5.0..main --oneline`.

### `v4.5.0` tag checklist (completed 2026-07-05)

All steps done; retained as the release record.

1. **Release-environment matrix sign-off — ✅ done.** Apache lane completed (real Apache 2.4.58 + mod_php 8.3.6 run, all six core sections pass, `Authorization`-header passthrough confirmed), minimum-WordPress (6.4) lane CI-covered, managed-host lane cleared by explicit maintainer waiver — all recorded in `docs/release-environment-log.md`.
2. **Re-confirm version sync — ✅ done.** All five points at `4.5.0`; no drift (re-verified with the full gate run: unit, PHPStan L6, Psalm, PHPCS, metrics, i18n).
3. **Bump `blueprint.json` — ✅ done** (PR #150): stable-demo install target now `archive/refs/tags/v4.5.0.zip`, merged after the tag was cut.
4. **Cut the annotated tag — ✅ done.** `v4.5.0` (annotated, signed) on `70cddfe`; GitHub Release published, release-ZIP CI attaches the install asset.
5. **Update this file — ✅ done** (this edit): `4.5.0` recorded as Latest tagged release / `v4.5.0` as latest git tag observed.
6. **wordpress.org submission** remains independently on hold and is not gated by the GitHub tag.

### `v4.6.0` tag checklist (staged 2026-07-06, tag pending)

The tree is release-ready; the annotated tag and GitHub Release are maintainer-owned and not yet done.

1. **Release-environment matrix sign-off — ⏳ NOT auto-carried (re-scoped, feature release).** Unlike the superseded `4.5.1` (admin-UI presentation only, whose matrix was legitimately carried over from `4.5.0`), `4.6.0` adds a **new user-facing capability** — block-editor in-editor reauthentication (client JS + a logged-in-only `admin-ajax` nonce-refresh endpoint) — plus the optional critical-alert bridge. Before tagging, run the **full release-grade E2E** (the Release Confidence workflow: Apache/wp-env Playwright, nginx + MariaDB smoke, nginx multisite smoke, and Playground SQLite — see `docs/release-e2e-confidence.md`), which now includes the `editor-reauth` spec (EDITOR-01). The manual host/floor matrix (Apache / managed-host / min-WP) is a maintainer judgement: the new surface is admin-side JS + an `admin-ajax` endpoint, not server-floor-sensitive, so reusing the `4.5.0` host matrix is defensible — but confirm it **consciously** and record the decision in `docs/release-environment-log.md` rather than silently carrying it. **Storage note for the local matrix run:** the Docker / `wp-env` / Playwright + multisite lanes are disk-heavy; be parsimonious with Colima and env-VM disk allocation — prune stale `wp-env`/Docker volumes and images between lanes (`docker system prune`, `wp-env destroy`) so a full-matrix pass does not exhaust local storage.
2. **Version sync — ✅ done.** All five points at `4.6.0` (`wp-sudo.php` header + constant, `tests/bootstrap.php`, `phpstan-bootstrap.php`, `readme.txt` Stable tag).
3. **CHANGELOG + readme.txt — ✅ done.** `4.6.0` dated section in `CHANGELOG.md`; `readme.txt` Changelog and Upgrade Notice entries added.
4. **Cut the annotated tag — ⏳ pending (maintainer).** Cut `v4.6.0` from the release commit; publish the GitHub Release.
5. **Bump `blueprint.json` — ⏳ pending (post-tag).** Deliberately **not** bumped in this PR: the public "Try latest release" badges load `blueprint.json` from `main`, so pointing the install target at `archive/refs/tags/v4.6.0.zip` before the tag exists would make the public demo fetch a missing ZIP. It stays on `v4.5.0.zip` and is bumped to `v4.6.0.zip` **after** the tag is cut (the same post-tag ordering used for `4.5.0` via PR #150).
6. **Update this file — ⏳ pending.** After the tag is cut, record `4.6.0` as Latest tagged release / `v4.6.0` as latest git tag observed.

## Current `main` release state

- **Current `main` version:** `4.6.0` (runtime constant), staged for the `v4.6.0` tag (**not yet cut**). The latest actual tag is still `v4.5.0`; `main` is ahead of it by the `4.6.0` payload. (Re-scoped from the earlier staged `4.5.1` once the in-editor reauth work landed — see above.)
- **Runtime version constant:** `4.6.0` on `main`. `WP_SUDO_VERSION` is set in `wp-sudo.php` (header + constant), `tests/bootstrap.php`, and `phpstan-bootstrap.php`; `readme.txt` Stable tag is `4.6.0`. All five version-sync points are in sync at `4.6.0`.
- **Current package metadata (on `main`):** `readme.txt` Stable tag `4.6.0` == header Version (no `stable_tag_mismatch`); `Requires at least 6.4`, `Requires PHP 8.2`, `Tested up to 7.0`. WordPress.org listing name: **"Sudo – Admin Action Gating"** (UI brand "Sudo"; slug/text-domain stay `wp-sudo` — lock the slug at submission).
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

`4.5.0` (tagged 2026-07-05) is a recommended security update bundling the work since `v4.2.2`:

- **Security hardening:** escalation-guard authority (the opt-in guard now requires the actor's promoting capability, not just a sudo session) and session-revocation token-binding (a stolen cookie can't revoke sessions).
- **Session governance & admin UX:** native "Revoke sudo sessions" Users-list bulk action, dashboard revocation/escalation visibility, and an Access-tab capability-table readability/accessibility/i18n pass.
- **Two Factor lifecycle bridge:** gates classic profile/user-edit provider lifecycle changes behind an active sudo session.
- **Localization packaging:** WP-CLI-backed POT regenerate/verify commands and the committed release-grade `wp-sudo.pot`.

See the `CHANGELOG.md` `4.5.0` section for the full itemized list.

`4.2.2` was a release-readiness refresh after Phase 13.1 gap closure:

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

Canonical source for post-tag drift after `v4.5.0`: `git log v4.5.0..main --oneline`.

## `4.5.0` release contents (shipped)

The `v4.5.0` tag (2026-07-05) shipped the **substantial** body of work
accumulated since `v4.2.2` — two completed GSD milestones (v4.4.0 Two Factor
Lifecycle Bridge and v4.5 Session Governance & Admin UX) plus security
hardening — released as of the `v4.5.0` tag. `main` has since **advanced past**
`v4.5.0` (the staged `4.6.0` release plus post-tag docs/CI work), so there **is**
unreleased work beyond the tag; see "Unreleased `main` work" below. Drift source:
`git log v4.2.2..v4.5.0 --oneline`; see the `CHANGELOG.md` `4.5.0` section for
the curated feature list.

**Security hardening (backward-compatible fixes):**

- **Escalation-guard authority hardening:** the opt-in admin-escalation guard now requires the acting user to hold the promoting authority (`promote_users` for administrator grants, checked on the target blog; existing super-admin for `grant_super_admin`) *in addition to* an active/in-grace sudo session — closing a gap where a low-privilege account holding a sudo session could pass the backstop on a broken-access-control route.
- **Session-revocation hardening:** the Users-list revocation paths now require the operator's token-bound `Sudo_Session::is_active()` (not the browser-independent expiry check), so a stolen auth cookie or a second session without its own sudo cannot revoke other users' sessions; the operator's cookie is preserved across consecutive revokes.

**Features / UX (v4.5 Session Governance & Admin UX):**

- **Users-list bulk revocation:** native "Revoke sudo sessions" bulk action (replacing the revoke-all button + unstyled interstitial), nonce-verified via a `load-users.php` interceptor, one rate slot per batch, self-skip, current-site membership guards; site-wide revoke stays CLI-only.
- **Dashboard widget revocation visibility:** the Session Activity widget records/renders `session_revoked` and `escalation_blocked` events with distinct pills and translatable labels.
- **Governance coverage panel fix (multisite):** the Access-tab coverage panel names the context-correct capability and excludes multisite super admins from false "cannot access" listings.
- **Access-tab capability table readability + accessibility + i18n:** one row per capability with friendly labels (slugs demoted to tooltip + screen-reader text), per-capability accessible names on the Revoke controls, and translatable capability labels.
- **Sudo Active badge-count invalidation** on session grant/teardown from every execution context; registry scrub of the stale `wp_sudo_revoke_session` AJAX reference.
- **Settings-tab preservation** across a sudo reauth (single-site and multisite network settings).

**Integration / infrastructure (v4.4.0 + follow-ups):**

- **Two Factor profile-provider lifecycle bridge:** the optional bridge (`bridges/wp-sudo-two-factor-lifecycle-bridge.php`) gates meaningful classic `profile.php` / `user-edit.php` provider lifecycle changes behind an active sudo session, alongside the existing REST factor-management gates. Completes the v4.4.0 milestone.
- **Playground demo fixes + release CI:** PR-preview installs fetch plugin archives via the CORS proxy; an install ZIP asset is built/attached on version tags.
- **Localization packaging readiness:** WP-CLI-backed Composer commands for POT generation/verification and a committed `languages/wp-sudo.pot`.

**Documentation (no runtime impact):** Patchstack 2FA compatibility runtime-validated offline against a licensed Pro 2.3.6 fixture and documented (not a shipped integration); SSO/passwordless auth boundary clarified; Two Factor ecosystem/integration docs and canonical metrics refreshed. Current suite: **986 unit tests / 3,003 assertions** (see `docs/current-metrics.md`).

Canonical source for drift after the tag: `git log v4.5.0..main --oneline`.

### Release environment assurance

Phase 17 added [`docs/release-environment-log.md`](release-environment-log.md) as the record of record for release-grade manual environment matrix outcomes. The `v4.5.0` tag's environment-matrix gate was **cleared** (Apache lane completed, minimum-supported-WordPress (6.4) floor CI-covered, managed-host lane cleared by explicit maintainer waiver — see the log). For the staged **`4.6.0`** package the gate is **not auto-cleared**: because `4.6.0` adds the block-editor in-editor reauth capability, run the full release-grade E2E before tagging and record a **conscious** reuse-or-rerun decision for the host/floor matrix in the log (see the `v4.6.0` tag checklist above). The historical `v4.2.2` row remains **Deferred**.

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
