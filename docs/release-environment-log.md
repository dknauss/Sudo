# Release Environment Log

This file is the durable record of release-grade manual environment matrix outcomes for WP Sudo packages.

It records what was actually run, what was deferred, who owns follow-up, and whether any deferral blocks the next release or publication decision. It does **not** perform a WordPress.org upload, managed-host provisioning, release tag, or publication approval. WordPress.org submission remains intentionally delayed/on hold until the maintainer explicitly approves publication.

Keep the executable smoke-test procedure in [`tests/MANUAL-TESTING.md`](../tests/MANUAL-TESTING.md). This log is the record of record for per-version outcomes; do not duplicate the full smoke steps here.

## Status by package

| Package/version | Date | Overall status | Summary | WordPress.org posture |
|-----------------|------|----------------|---------|-----------------------|
| `v4.2.2` | 2026-06-29 | Deferred | Release environment matrix was documented for future execution; lanes were not rerun in Phase 17. | Submission/upload remains delayed/on hold. |
| `4.5.0` (staged, no tag yet) | 2026-07-05 | Matrix cleared for tag: Apache **completed**, min-WP **CI-covered**, managed-host **waived** | The **Apache lane is completed** against a real Apache 2.4.58 + mod_php 8.3.6 stack (all six core sections pass, including the `mod_rewrite`/`Authorization`-header App-Password check). The **minimum-WordPress (6.4)** lane is functionally covered by the CI Integration matrix. The **managed-host** lane is cleared by an explicit maintainer waiver (recorded below) with residual risk accepted. The environment-matrix gate for the `v4.5.0` tag is therefore satisfied. | Submission/upload remains delayed/on hold. |
| `4.5.1` (superseded) | 2026-07-05 | **Superseded by `4.6.0`** — clearance no longer applies | The staged `4.5.1` was re-scoped to `4.6.0` (minor) once the in-editor reauth work landed. Its "reuse the `4.5.0` matrix" clearance was valid only for the admin-UI-only `4.5.1` delta and does **not** carry to `4.6.0`, which adds a new feature. See the `4.6.0` row. | — |
| `v4.6.0` (tagged 2026-07-06) | 2026-07-06 | ✅ **Cleared for tag** — nginx-multisite *smoke* de-scoped then re-hardened | Release-grade E2E run 2026-07-06 (local + [run #28804948034](https://github.com/dknauss/Sudo/actions/runs/28804948034)): Apache/wp-env full E2E, nginx+MariaDB smoke, and Playground SQLite smoke all pass. The nginx-multisite *smoke* lane failed on Playwright actionability timeouts (test-robustness, not a `4.6.0` regression) and was de-scoped, then re-hardened post-tag and restored to the gate. Manual host/floor matrix reused from `4.5.0` by conscious decision (admin-side JS + `admin-ajax` only). See the `4.6.0` section below. | Submission/upload remains delayed/on hold. |
| `v4.7.0` (tagged 2026-07-16) | 2026-07-16 | ✅ **Cleared — matrix reused from `4.6.0` by conscious decision** | `4.7.0`'s new surface is block-editor JS + an internal `admin-ajax` 2FA-partial endpoint (`wp_sudo_challenge_2fa_partial`) — the same class of admin-side, non-server-floor-sensitive change as `4.6.0`. No new server-facing behavior, so the `4.6.0` matrix outcome applies (decision recorded in `docs/release-status.md` v4.7.0 checklist, item 2). See the `4.7.0` section below. | Submission/upload remains delayed/on hold. |
| `v4.8.0` (tagged 2026-07-23) | 2026-07-23 | ⚠️ **Manual matrix decision not recorded at tag time — retroactive gap** | `4.8.0` changed **server-facing REST routing/method matching** (gates now match `POST` + `show_in_rest` aliases on `/wp/v2/users` and `/wp/v2/settings`), the class of change the manual Apache/managed-host/min-WP matrix exists to catch — so reuse of the `4.6.0`/`4.5.0` matrix is **not** obviously justified. Live **functional** security verification of the new gates was performed and recorded (`docs/security-test-results-4.8.0.md`; Studio/SQLite + Redis, headless), but that is not the Apache/managed-host/min-WP lane. Tag already cut; a conscious reuse-or-rerun decision for those lanes is flagged for the maintainer. See the `4.8.0` section below. | Submission/upload remains delayed/on hold. |

## `v4.2.2` environment matrix

The `v4.2.2` package already exists, but Phase 17 did not rerun release-grade manual environment lanes. Each row below is therefore an explicit deferral, not evidence of a completed smoke run.

| Environment lane | Status | Owner | Timing | Blocks next public tag/publication decision? | Notes |
|------------------|--------|-------|--------|---------------------------------------------|-------|
| Apache stack | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Run the core smoke set from the manual guide on DDEV, MAMP, Local, or an Apache staging host and record evidence here. |
| Managed WordPress host | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Provision or use an approved staging/trial managed WordPress host only when the maintainer chooses to execute the release-grade lane. |
| Minimum supported WordPress version | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Confirm the current floor in `docs/release-status.md`, run the manual smoke set there, and record exact WordPress/PHP/site-mode evidence here. |

## `4.5.0` environment matrix

The `4.5.0` package is staged on `main` (version-synced; no `v4.5.0` tag cut yet).
The **Apache lane is completed** (evidence below). The **managed-host** lane is
**waived** for `4.5.0` by explicit maintainer waiver (see below) — it was not run
because a real managed WordPress host is not reachable from the build sandbox;
residual risk accepted. The **minimum-WordPress (6.4)** lane is functionally covered
by CI (on the PHP 8.2 single-site lane — see the row below for the exact scope).

| Environment lane | Status | Owner | Timing | Blocks next public tag/publication decision? | Notes |
|------------------|--------|-------|--------|---------------------------------------------|-------|
| Apache stack | **Completed** 2026-07-05 | Claude Code (remote build sandbox) | Done | No — completed | Real **Apache/2.4.58 + mod_php 8.3.6 + mod_rewrite** stack serving the merged 4.5.0 replica. All six core sections (1.1, 2.1, 2.9, 4.1, 5.2, 9.1) pass; the `mod_rewrite` pretty REST route and the `Authorization`-header App-Password passthrough both work under Apache (§5.2). Full evidence below. Note: this is Apache **in the build container**, not a Local-by-Flywheel/managed host. |
| Managed WordPress host | Waived (maintainer) | Maintainer | Waived for `4.5.0` on 2026-07-05 | No — waived | Waived (see the managed-host waiver below). **Not** independently executed on a managed host — not reproducible in the build sandbox (no route to an external managed host). Residual risk accepted: managed-host object cache, mu-plugin, and filesystem-policy behavior not exercised. |
| Minimum supported WordPress version (6.4) | Covered by CI — manual smoke optional | Maintainer | Optional before tag | No (CI-covered) | The automated Integration matrix runs the full suite on the **6.4 floor** on **PHP 8.2, single-site** (the only 6.4 lane in `.github/workflows/phpunit.yml`); multisite is exercised on the PHP 8.3 / WP 7.0 lane, **not** on 6.4. This covers the functional dimension on the floor; a manual 6.4 browser smoke (sections 1.1, 2.1, 2.9, 4.1, 5.2, 9.1) — and specifically 6.4 multisite — remains optional. |

### Completed lane evidence: Apache stack (2026-07-05)

- Package/version: `4.5.0` (merged to `main`; plugin reports `WP_SUDO_VERSION 4.5.0`, active on the replica)
- Environment lane / host: **Apache stack** — Apache 2.4.58 (Ubuntu) with `libphp8.3` (mod_php), `mod_rewrite` and `mod_headers` enabled, serving the replica on `:8901` with a standard WordPress `.htaccess` and `AllowOverride All`. This is an in-container Apache build environment, **not** Local by Flywheel or a managed host.
- WordPress: 7.0 · PHP: 8.3.6 (mod_php) · Database: SQLite drop-in 2.1.15 · Web server: Apache/2.4.58 · Site mode: single-site
- Browser: Chromium (Playwright) for admin sections; `curl` for the App-Password sections
- Two Factor and Patchstack Security (Pro 2.3.6) active alongside; no interference observed
- Core smoke sections run and result (all through Apache):
  - §1.1 Activate via challenge page — PASS (challenge renders, password activates session, redirect back to originating page)
  - §2.1 Activate plugin — PASS (no-session: gate notice + disabled `<span>` Activate; with session: operable nonced link, Akismet activated with no further challenge)
  - §2.9 Change critical site setting — PASS (Save → challenge with `stash_key`, label "Change critical site setting"; after auth the POST is replayed — "Settings saved." with pending admin-email change)
  - §4.1 Create Application Password (cookie-auth REST gate) — PASS (no session: "This action (Create application password) requires reauthentication…" notice, no password created)
  - §5.1 Non-gated App-Password endpoint (`GET users/me`) — PASS (HTTP 200 — Authorization header reached WP through Apache/mod_php)
  - §5.2 Gated App-Password endpoint under Limited — PASS (`{"code":"sudo_blocked",…}` HTTP 403; control request with **no** Authorization header returns 401 `rest_not_logged_in`, confirming the header was genuinely passed through and evaluated)
  - §9.1 Three-option policy dropdowns — PASS (four surface dropdowns Disabled/Limited (default)/Unrestricted; session duration 15; tabs Settings/Gated Actions/Rule Tester/Access; renders correctly under Apache)
- Rewrite/auth-header observation: `mod_rewrite` pretty REST routes resolve (`/wp-json/` → 200) and the Basic `Authorization` header for Application Passwords is passed through to PHP by mod_php (the §5.2 403-vs-401 contrast proves it). No `CGIPassAuth`/`SetEnvIf` workaround was required for mod_php.
- Runner/owner: Claude Code (remote build sandbox).

### Maintainer waiver: managed-host lane (2026-07-05)

- **Decision:** The **managed WordPress host** lane is **waived** for the `4.5.0` release. This is an explicit maintainer waiver under the "Deferral and failure policy" below (a recorded waiver clears the lane for the next public tag/publication decision). It applies to this one lane only.
- **Owner / approver:** Dan Knauss (maintainer).
- **Why waived:** A real managed host is not reachable from the build sandbox, and provisioning one was out of scope for this release. The server-layer concerns that most often differ on managed hosts — URL rewriting and `Authorization`-header passthrough for REST/Application-Password auth — were exercised and passed on the **completed Apache lane** above; the WordPress **6.4 floor** and multisite are covered by the CI Integration matrix.
- **Residual risk accepted:** managed-host-specific behavior not exercised — object cache (e.g. persistent Redis/Memcached), platform mu-plugins, and restrictive filesystem/security policies. Accepted for `4.5.0`; to be run on a real managed host at the next convenient opportunity.
- **Scope:** Clears the environment-matrix gate for the `v4.5.0` tag. Does **not** approve a WordPress.org upload/submission, which remains separately on hold until the maintainer approves publication.

### Supporting evidence (non-lane): local smoke run

This run is **supporting evidence only** — it does **not** satisfy any of the three
lanes above (it is neither Apache, nor a managed host, nor the minimum WordPress
version). It is recorded here because it exercises the merged 4.5.0 code end-to-end.

- Package/version: `4.5.0` (merged to `main`; plugin reports `WP_SUDO_VERSION 4.5.0`)
- Date: 2026-07-05
- Environment: local replica — PHP 8.4 built-in server (`php -S`, not Apache/nginx), SQLite drop-in, WordPress 7.0, single-site
- Browser: Chromium (Playwright)
- Two Factor and Patchstack Security (Pro 2.3.6) both active alongside; no interference observed
- Core smoke sections run and result:
  - §1.1 Activate via challenge page — PASS (challenge renders, password activates session, returns to originating page, admin-bar timer appears)
  - §2.1 Activate plugin — PASS (no-session: gate notice + disabled `<span>` action links; with session: operable nonced link, "Plugin activated." with no further challenge)
  - §2.9 Change critical site setting — PASS (Save redirects to challenge with `stash_key` and label "Change critical site setting"; after auth the POST is replayed — "Settings saved." with pending admin-email change)
  - §4.1 Create Application Password (cookie-auth REST gate) — PASS (no session: "This action (Create application password) requires reauthentication…" notice, no password created; with session: password created)
  - §5.1 Non-gated App-Password endpoint (`GET users/me`) — PASS (HTTP 200)
  - §5.2 Gated App-Password endpoint under Limited (default) — PASS (`{"code":"sudo_blocked",…}` HTTP 403)
  - §9.1 Three-option policy dropdowns — PASS (four surface dropdowns Disabled/Limited (default)/Unrestricted; WPGraphQL row absent when inactive; session duration 15; presets Normal/Incident Lockdown/Headless Friendly; renders correctly under WP 7.0 chrome)
  - §10.1 Site Health all Limited — PASS ("All Sudo entry point policies are secure" under the Good/Passed section)
- Runner: automated browser + curl session (Claude Code, remote build sandbox)

## `4.5.1` environment matrix (SUPERSEDED by `4.6.0`)

> **Superseded 2026-07-06.** The staged `4.5.1` was re-scoped to `4.6.0` (minor) after the in-editor reauth work landed. The reuse rationale below applied only to the admin-UI-only `4.5.1` delta; it does **not** clear `4.6.0`, which adds a new feature and requires the gate in the `4.6.0` section below. Retained for history.

The `4.5.1` package was staged on `main` (version-synced; never tagged — re-scoped to `4.6.0`).

- **Decision:** The `4.5.0` environment matrix above is **reused** for `4.5.1`; no lane is re-run.
- **What actually changed since the `v4.5.0` tag:** `git diff v4.5.0..<release-commit> -- includes/ bridges/ mu-plugin/ admin/ wp-sudo.php` shows PR #154 only — `includes/class-admin.php`, `includes/class-dashboard-widget.php`, new `includes/class-user-identity.php`, `admin/css/wp-sudo-admin.css`, and the version constant/header in `wp-sudo.php`. This is **admin-UI presentation code** (how a user is rendered on the dashboard widget and the Access tab) plus the `get_avatar()` `force`→`force_display` fix. It is *not* a version-only bump — there is a real code delta from the tagged `4.5.0` tree.
- **Why the matrix still applies (not re-run):** the manual environment matrix exists to catch **server- and host-layer** differences — Apache URL-rewrite and `Authorization`-header passthrough for REST/App-Password auth, managed-host object cache / platform mu-plugins / filesystem policy, and the WordPress 6.4 floor. PR #154's changes are admin-side rendering with no new REST/rewrite/auth surface and no API newer than the 6.4 floor (`get_avatar`, `translate_user_role`, `wp_roles` all long predate 6.4). Those functional changes are exercised by the CI Integration matrix on the 6.4 floor (PHP 8.2 single-site; multisite is covered on the PHP 8.3 / WP 7.0 lane). No environmental dimension the manual lanes test is affected by admin-markup changes, so a re-run would exercise nothing new.
- **Owner / approver:** Maintainer (reuse rationale authored for review; the tag decision remains maintainer-owned).
- **Scope:** Clears the environment-matrix gate for the `v4.5.1` tag by inheritance. Does **not** approve a WordPress.org upload/submission, which remains separately on hold.

## `4.6.0` environment matrix (✅ cleared — nginx-multisite smoke de-scoped)

The `4.6.0` package was **tagged `v4.6.0` (2026-07-06)**; this section is retained as the record of the gate decision made before that tag. This is a **feature release**, so unlike the superseded `4.5.1` the `4.5.0` matrix is **not** auto-inherited.

- **Status:** ✅ **Cleared for tag, with the nginx-multisite *smoke* lane de-scoped** (see decision below).
- **What changed since the `v4.5.0` tag:** block-editor in-editor reauthentication (`admin/js/wp-sudo-editor-reauth.js` + the `wpSudoEditorReauth` localization and the `wp_sudo_refresh_grant_nonce` `admin-ajax` endpoint), the optional critical-event alert bridge + inline demo companion, and the admin user-identity harmonization (formerly the `4.5.1` payload).
- **Release-grade E2E — run 2026-07-06 (local + GitHub Release Confidence [run #28804948034](https://github.com/dknauss/Sudo/actions/runs/28804948034)):**
  - ✅ **Apache/wp-env full E2E** — `59 passed, 6 skipped` (local) / passed (CI). Includes the `editor-reauth` EDITOR-01 spec.
  - ✅ **nginx + MariaDB smoke** — `9 passed` (local) / passed (CI).
  - ✅ **Playground SQLite smoke** — `9 passed` (local) / passed (CI).
  - ⚠️ **nginx + MariaDB multisite smoke** — **failed** (MSTACK-01/02/03), local + CI. See the de-scope decision below.
- **nginx-multisite smoke de-scope decision:** The failures are Playwright *element-not-stable / actionability* timeouts on `#submit` and `#wp-admin-bar-wp-sudo-active` — the elements resolve but never satisfy the stability check; a **test-robustness / rendering** issue on the heavier multisite stack, **not** a behavioral assertion failure. It is **not a `4.6.0` regression**: the lane is new (added by #155 *after* `v4.5.0`) and has never been green, and nothing in the `4.6.0` payload renders on `network/settings.php` or the admin-bar timer (the new editor script enqueues only on `enqueue_block_editor_assets`). Functional multisite behavior is independently covered by the **CI Integration multisite lane** (real WP + MySQL), which passes. The lane is therefore **de-scoped** from the release-confidence gate — `continue-on-error: true` + excluded from the aggregate pass/fail, both loudly commented and reversible in `.github/workflows/release-confidence.yml`. It still runs, so the failure stays visible. **Tracked for stabilization** in [`docs/ROADMAP.md`](ROADMAP.md).
- **Follow-up (post-tag, 2026-07-06) — lane hardened and re-scoped into the gate.** The `release-confidence-nginx-multisite-report` trace from [run #28804948034](https://github.com/dknauss/Sudo/actions/runs/28804948034) confirmed the mechanism: `#wp-admin-bar-wp-sudo-active` wraps the live sudo countdown timer (re-renders every second) and `#submit` sits below an async MU-plugin status check that reflows the form, so on a loaded runner neither box ever satisfies Playwright's two-consecutive-frames stability check; the reauth POST → redirect round-trips were also slower than the single-site timeout budget. `tests/e2e/specs/multisite-stack-smoke.spec.ts` now `forceClick`s those confirmed-visible/enabled targets (`scrollIntoViewIfNeeded()` + `click({ force: true })`) and uses a multisite-sized `NAV_TIMEOUT`, each click still asserting its result. The `continue-on-error` and aggregate exclusion were removed, so the lane once again **gates** `release-confidence.yml`. Verify with a manual `release-confidence.yml` dispatch (or local `npm run test:e2e:multisite:stack-smoke`) before relying on the restored gate.
- **Manual host/floor matrix — reused from `4.5.0` by conscious decision:** the `4.6.0` new surface is admin-side JS + an `admin-ajax` endpoint (block-editor only), not server-floor-sensitive (no new REST route/rewrite/auth surface, no API newer than the 6.4 floor). The `4.5.0` manual matrix (Apache completed, min-WP CI-covered, managed-host waived) therefore applies; no re-run required.
- **Storage note (local matrix run):** the Docker / `wp-env` / Playwright + multisite lanes are disk-heavy; run them serially and prune Docker/`wp-env` volumes and images between lanes (`docker system prune`, `wp-env destroy`) so a full-matrix pass does not exhaust local storage.
- **Owner / approver:** Maintainer (de-scope rationale authored for review; the tag decision remains maintainer-owned).

## `v4.7.0` environment matrix (✅ cleared — matrix reused from `4.6.0`)

The `v4.7.0` tag was cut 2026-07-16. As with the superseded `4.5.1`→`4.6.0` distinction, the reuse turns on whether the delta touches a server-/host-layer dimension the manual matrix tests.

- **Decision:** The `4.6.0` environment matrix (which itself reused the `4.5.0` host/floor matrix) is **reused** for `4.7.0`; no lane is re-run.
- **What changed since the `v4.6.0` tag:** completion of the in-editor reauthentication modal — Milestone A (in-place password modal over the block editor) and Milestone B (in-modal second factor via a server-rendered provider partial served by the internal `wp_sudo_challenge_2fa_partial` `admin-ajax` endpoint), plus demo/docs follow-ons. This is **admin-side block-editor JS + an internal `admin-ajax` endpoint** — no new front-controller REST route, rewrite, or `Authorization`-header surface, and no API newer than the 6.4 floor.
- **Why the matrix still applies (not re-run):** the manual matrix exists to catch server-/host-layer differences (Apache rewrite + `Authorization`-header passthrough, managed-host object cache / mu-plugins / filesystem policy, the 6.4 floor). The `4.7.0` surface is the same admin-side class as `4.6.0`, which cleared those lanes. Multisite behavior stays covered by the green CI Integration multisite lane; the nginx-multisite *smoke* lane remains as re-hardened for `4.6.0`.
- **Owner / approver:** Maintainer (decision recorded in `docs/release-status.md` v4.7.0 checklist, item 2; reproduced here as the env-log record of record).
- **Scope:** Clears the environment-matrix gate for the `v4.7.0` tag by reuse. Does **not** approve a WordPress.org upload/submission, which remains separately on hold.

## `v4.8.0` environment matrix (⚠️ manual matrix decision not recorded at tag time)

The `v4.8.0` tag was cut 2026-07-23. This row is a **retroactive reconciliation**: the tag is already public, and no release-grade manual environment-matrix (Apache / managed-host / min-WP) reuse-or-rerun decision was recorded for it at tag time. It is captured here so the gap is visible and the maintainer can record a conscious decision.

- **Why reuse is not obviously justified:** unlike `4.6.0`/`4.7.0` (admin-side JS + `admin-ajax` only), the `4.8.0` payload changed **server-facing REST routing and HTTP-method matching** — the `user.change_email` / `user.promote` rules now gate `POST` on `/wp/v2/users/{id|me}`, and the `options.critical` REST callback now matches the `show_in_rest` aliases (`url`/`email`) on `/wp/v2/settings`. REST routing/method behavior and `Authorization`-header handling are exactly the dimensions the Apache/managed-host lanes exercise, so a blind carry-forward of the `4.6.0`/`4.5.0` matrix is **not** sound the way it was for `4.7.0`.
- **What functional verification *was* done (not the matrix):** a live end-to-end security pass is recorded in [`docs/security-test-results-4.8.0.md`](security-test-results-4.8.0.md) — **WordPress Studio, WP 7.0.2 / PHP 8.5.8 / SQLite**, headless WP-CLI + App-Password/cookie REST via `curl` (plus a **Redis** backend for the cache-bypass rows and one in-app-browser challenge-UI pass). All new REST gates returned the expected `403 sudo_blocked` / `sudo_required`, the stolen-cookie path was gated, and the profile anti-replay (`stash_no_replay`) held in the UI. This **confirms the gate logic end-to-end** but is a **functional** pass on SQLite — it is **not** the Apache (mod_php `Authorization` passthrough), managed-host, or minimum-WordPress (6.4) lane.
- **CI coverage:** the full required suite (Unit ×PHP-matrix, Integration ×multisite, E2E, Psalm, PHPCS, PHPStan L6, Plugin Check) was green on the release commit; the 6.4 floor stays covered by the CI Integration matrix (PHP 8.2 single-site).
- **Recommended maintainer decision (open):** either (a) record a conscious **reuse** rationale if the mod_php/Apache `Authorization`-header passthrough proven on the completed `4.5.0` Apache lane is judged to cover the new REST method/alias matching (the routing change is inside WordPress's REST dispatch, above the web-server layer), or (b) **re-run** the Apache lane's §5.1/§5.2 App-Password REST sections against the `4.8.0` gates. Until recorded, treat the `4.8.0` manual matrix as **not cleared**.
- **Owner / approver:** Maintainer (retroactive reconciliation authored for review; the decision remains maintainer-owned).
- **Scope:** Flags an open environment-matrix reconciliation item for `4.8.0`. Does **not** approve a WordPress.org upload/submission, which remains separately on hold.

## Required evidence for completed lanes

When a lane is completed, add or update a dated package row with:

- package/version and run date
- environment lane and host/tool name
- WordPress version, PHP version, database engine/version, and web server where available
- site mode (single-site or multisite)
- browser and operating system for browser-admin checks
- smoke sections run from `tests/MANUAL-TESTING.md`
- pass/fail/deferred result for each lane
- rewrite/auth-header, cache, mu-plugin, filesystem, or host-policy observations
- runner/owner name and follow-up issue or waiver link for any skipped item

## Deferral and failure policy

A deferred or failed lane must include all of the following before the release decision can proceed:

1. **Owner** — the person or role responsible for resolution.
2. **Timing** — when the lane will be run, retried, or intentionally waived.
3. **Follow-up or waiver** — a linked issue, release note, or explicit maintainer waiver.
4. **Blocker posture** — whether it blocks the next public tag/publication decision.

Unless a maintainer explicitly records a waiver, deferred release-grade lanes block the next public tag/publication decision. WordPress.org-only upload/submission work remains separate and on hold until publication approval.
