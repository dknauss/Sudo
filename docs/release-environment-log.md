# Release Environment Log

This file is the durable record of release-grade manual environment matrix outcomes for WP Sudo packages.

It records what was actually run, what was deferred, who owns follow-up, and whether any deferral blocks the next release or publication decision. It does **not** perform a WordPress.org upload, managed-host provisioning, release tag, or publication approval. WordPress.org submission remains intentionally delayed/on hold until the maintainer explicitly approves publication.

Keep the executable smoke-test procedure in [`tests/MANUAL-TESTING.md`](../tests/MANUAL-TESTING.md). This log is the record of record for per-version outcomes; do not duplicate the full smoke steps here.

## Status by package

| Package/version | Date | Overall status | Summary | WordPress.org posture |
|-----------------|------|----------------|---------|-----------------------|
| `v4.2.2` | 2026-06-29 | Deferred | Release environment matrix was documented for future execution; lanes were not rerun in Phase 17. | Submission/upload remains delayed/on hold. |
| `4.5.0` (staged, no tag yet) | 2026-07-05 | Deferred (lanes) + supporting local smoke recorded | The three release-grade lanes (Apache, managed host, minimum WordPress) remain maintainer-owned and are **not** completed. A non-lane supporting smoke run of the full core smoke set passed on a local PHP-built-in-server + SQLite + WordPress 7.0 replica against the merged 4.5.0 code. | Submission/upload remains delayed/on hold. |

## `v4.2.2` environment matrix

The `v4.2.2` package already exists, but Phase 17 did not rerun release-grade manual environment lanes. Each row below is therefore an explicit deferral, not evidence of a completed smoke run.

| Environment lane | Status | Owner | Timing | Blocks next public tag/publication decision? | Notes |
|------------------|--------|-------|--------|---------------------------------------------|-------|
| Apache stack | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Run the core smoke set from the manual guide on DDEV, MAMP, Local, or an Apache staging host and record evidence here. |
| Managed WordPress host | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Provision or use an approved staging/trial managed WordPress host only when the maintainer chooses to execute the release-grade lane. |
| Minimum supported WordPress version | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Confirm the current floor in `docs/release-status.md`, run the manual smoke set there, and record exact WordPress/PHP/site-mode evidence here. |

## `4.5.0` environment matrix

The `4.5.0` package is staged on `main` (version-synced; no `v4.5.0` tag cut yet).
The three release-grade lanes below are **not** completed — each is an explicit
deferral owned by the maintainer, because they require real environments that the
build/CI sandbox cannot provide (a real Apache stack, a real managed WordPress host).

| Environment lane | Status | Owner | Timing | Blocks next public tag/publication decision? | Notes |
|------------------|--------|-------|--------|---------------------------------------------|-------|
| Apache stack | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Recommended runner: **Local (by Flywheel)** with the site's web server set to **Apache**, or DDEV/MAMP/an Apache staging host. The distinctive check this lane protects is `mod_rewrite` passing the `Authorization` header to REST/Application-Password auth (§4.1, §5.2), which the non-Apache smoke below does not exercise. Run core smoke sections 1.1, 2.1, 2.9, 4.1, 5.2, 9.1 and record host/tool, PHP, database, browser, and rewrite/auth-header notes. |
| Managed WordPress host | Deferred | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | Run core smoke sections 1.1, 2.1, 2.9, 9.1, 10.1 on an approved staging/trial managed host; record plan/trial type, caching or security features enabled, and any blocked filesystem or mu-plugin operations. |
| Minimum supported WordPress version (6.4) | Deferred (manual) — partially covered by CI | Maintainer | Before next public tag/publication decision | Yes unless explicitly waived | The automated Integration matrix already runs the full suite on the **6.4 floor** (PHP 8.2/8.3, single-site and multisite), so the functional dimension is covered. The manual browser smoke (sections 1.1, 2.1, 2.9, 4.1, 5.2, 9.1) on a 6.4 site is still outstanding; record the exact WordPress/PHP version and single-site vs multisite. |

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
