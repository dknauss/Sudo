---
gsd_state_version: 1.0
milestone: post-v4.5 — In-Editor Gutenberg Reauth (Milestones A + B both shipped to `main`, unreleased)
milestone_name: — In-Editor Reauth (tracked on `.planning/gutenberg-editor-reauth-*`, not the GSD phase counter)
status: v4.5 (Session Governance & Admin UX) complete and RELEASED — tagged v4.5.0 (2026-07-05) and folded into v4.6.0 (2026-07-06). In-Editor Reauth Milestone A (password path, full modal) merged via PR #178 (2026-07-07); demo/readme/research follow-ups #179–#183 merged 2026-07-15. Milestone B (in-modal 2FA) is now COMPLETE and merged: PR #185 (implementation, 7c3ccf3), PR #186 (full-page `_ajax_nonce` strip fix, 83fdf57), PR #187 (2FA demo blueprint, 9a378b7), and PR #189 (demo login-gate fix — the deferred Playground auto-login was hitting Two Factor's own login-screen challenge; user-confirmed working, 361538a) — all **released in the `v4.7.0` tag** (cut 2026-07-16, on `3cf7ee7`; MINOR by maintainer product-signaling override — see `VERSIONING.md`). `main` is at the tag; nothing is unreleased past it. No feature track is currently active; next candidates are the two filed research todos (#182 in-editor session indicator, #179 MU-plugin lockdown) plus environment-gated deferred items.
stopped_at: "In-Editor Reauth Milestone A complete and merged (PR #178, 2026-07-07): the full password-path modal in the block editor — modal grant + re-dispatch, the 2FA-bypass security invariant, stale-nonce recovery, owner-scoped concurrent re-dispatch, degradation guards, and the codex-flagged 2FA double-prompt fix (link out once). Execution checklist Steps 0–8 all done; two items deferred by design — the manual password-manager × 2FA matrix (needs real managers) and the full CI single-site+multisite E2E sweep (owner/CI). Follow-ups merged 2026-07-15: self-guiding demo (#180/#181), and two filed research todos — in-editor session indicator (#182) and MU-plugin role/cap lockdown mode (#179). Milestone B (in-modal 2FA) has a reviewed brief + 7-step TDD order in the execution checklist and is not yet started."
last_updated: "2026-07-16"
last_activity: 2026-07-16 — **Released `v4.7.0`** (in-editor reauth modal: Milestone A password path + Milestone B in-modal 2FA, completing what 4.6.0 deferred). Two-phase cut: #199 version-sync (4 code points + readme.txt Stable tag, CHANGELOG, VERSIONING override note, ~18k size) → annotated tag `v4.7.0` on `3cf7ee7` → `release.yml` published the GitHub Release with `wp-sudo.zip` → #200 post-tag (blueprint.json → v4.7.0.zip, release-status.md, readme.txt Changelog/Upgrade Notice). MINOR was a documented maintainer product-signaling override (strict rule → patch). Earlier same day: demo/docs polish (#193/#194) and multisite scenario blueprint #196/#197 (issue #72 closed). No feature track active. Open scenario-blueprint issue **#67 (lockout-state, good first issue) is intentionally left unclaimed** for a possible external contributor — do NOT auto-pick it.
progress:
  gsd_milestone: v4.5 — complete (Phases 24–25; released in v4.5.0 / v4.6.0)
  active_track: none — In-Editor Reauth Milestones A + B both merged (A: #178; B: #185/#186/#187 + #189 demo fix); next track undecided
  total_phases: 2
  completed_phases: 2
  total_plans: 3
  completed_plans: 3
  percent: 100
---

## Current Position

Active track: **In-Editor Gutenberg Reauth** (own planning docs: `gutenberg-editor-reauth-milestone-plan.md`, `-execution-checklist.md`, `-milestone-b-2fa-partial-brief.md`).
Milestone A: **complete and merged** (PR #178, 2026-07-07) — the full password-path reauth modal in the block editor. Checklist Steps 0–8 all done. **Released in the `v4.7.0` tag (2026-07-16).**
Milestone B: **complete and merged.** In-modal 2FA: a shared `render_two_factor_fields` renderer, the `handle_ajax_2fa_partial` endpoint (pending-gated, default-deny provider allowlist, email shared send-throttle), and the client (fetch partial → inject into a non-form node + neutralize native submits → generic serialize → POST to the unchanged validator → owner re-dispatch). Q-B1 resolved to a private default-deny allowlist (no public filter in v1). Shipped across PR #185 (implementation, 7c3ccf3), PR #186 (full-page challenge `_ajax_nonce` strip — the parallel gap the partial work surfaced, 83fdf57), and PR #187 (in-editor 2FA demo blueprint + readme/CONTRIBUTING/pressshipignore, 9a378b7).
Status: Milestone A shipped the modal grant + re-dispatch, the 2FA-bypass invariant (2FA users get `2fa_pending`, never a session), stale-nonce recovery, owner-scoped concurrent re-dispatch, degradation/no-safe-URL guards, and the 2FA double-prompt fix. Milestone B added in-place OTP-family 2FA: pre-implementation design review + security-scoped pre-commit review (C1–C4 + the email render-send HIGH finding) both clean; gates green (integration single-site AND multisite, editor E2E 17/17 vs live Studio); Codex reviewed #185/#186/#187 clean. Filed research todos remain: #182 in-editor session-indicator, #179 MU-plugin lockdown.
Progress: [██████████] 100% of the prior GSD milestone (v4.5); In-Editor Reauth Milestones A and B both merged.
Last activity: 2026-07-16 — Milestone B track **closed out** (#185/#186/#187 + #189 demo login-gate fix merged); STATE reconciled.
Branch: `main` (all Milestone B PRs merged and branches deleted). Milestone A was #178; Milestone B was #185 (`feat/gutenberg-reauth-milestone-b-2fa`), with #186/#187/#189 as follow-ons.
Origin: block-editor reauthentication UX track — see `gutenberg-editor-reauth-design-brief.md` and the phase-2 plan.
Product release state: Latest tagged plugin release is **`v4.7.0`** (cut 2026-07-16, on `3cf7ee7`; ships In-Editor Reauth Milestones A + B; GitHub Release published with `wp-sudo.zip` attached; `main` is at the tag). Canonical: `docs/release-status.md`. v4.6.0 (2026-07-06) shipped the in-editor link-out increment + alert bridge; v4.5 shipped as `v4.5.0` (2026-07-05) and was folded into `v4.6.0`.
Open threads: WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Milestone A carries two deferred items — the manual password-manager × 2FA matrix (needs real managers; `docs/password-manager-compatibility.md`) and the full CI single-site+multisite E2E sweep (owner/CI). Patchstack 2FA is runtime-validated as bridgeable offline (licensed Pro 2.3.6 fixture); remaining Patchstack work is the live login-form / profile-save / WooCommerce lifecycle runs and the bridge-vs-upstream decision.
Resume file: no active track. `.planning/gutenberg-editor-reauth-execution-checklist.md` (Milestone A ✅ Steps 0–8; Milestone B ✅) is now fully done. Next work is a new track — see Active Priorities.

## Project Reference

Canonical current facts:

- `docs/e2e-runtime-review.md` — runtime evidence, Phase 20 implementation, validation run, and keep decision.
- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — product roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.
- `.planning/MILESTONES.md` — archived GSD milestone history.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** In-Editor Gutenberg Reauth track complete (Milestone A password modal + Milestone B in-modal 2FA) and **released as `v4.7.0`** (2026-07-16). No feature track active; next candidates are #182 (in-editor session indicator) and #179 (MU-plugin lockdown). Latest tagged release is **4.7.0** (see `docs/release-status.md`). WordPress.org submission (`docs/wporg-submission-checklist.md`) is delayed/on hold, but readiness should be maintained.

## Active Priorities

1. **Pick the next feature track.** In-Editor Reauth (A + B) is fully shipped. Leading candidates: #182 in-editor session indicator (self-contained UX, no external deps — strongest autonomous next step) and #179 MU-plugin role/cap lockdown (security-sensitive, needs an operator/recovery story first). One deferred verification remains available when CI/wp-env allows: the full 16-file E2E sweep single-site + `WP_MULTISITE=1` (Milestone B's own `editor-reauth.spec.ts` is 17/17; no other spec is affected by the scoped change).
2. Milestone A/B deferred items (do when the environment allows): the manual password-manager × 2FA matrix (needs real password managers; fill `docs/password-manager-compatibility.md`). Note: in-modal email *resend* is intentionally not wired in v1 — a resend links out.
3. Keep WordPress.org readiness maintained while submission remains delayed/on hold.
4. Patchstack core bridge path is runtime-validated offline; remaining Patchstack work is the live login-form / profile-save / WooCommerce lifecycle runs and the bridge-vs-upstream decision — no shipped support claim until those land.
5. Two research todos filed 2026-07-15 and awaiting answers before any implementation: in-editor session indicator (#182) and MU-plugin role/cap lockdown mode (#179).

## Accumulated Context

- 2026-07-16 (release): **`v4.7.0` cut and published.** Ships In-Editor Reauth Milestones A + B (in-place password modal over the block editor with owner-scoped re-dispatch, and the in-modal second factor via a server-rendered provider partial validated by the unchanged challenge validator) — completing the capability `v4.6.0` explicitly deferred. Two-phase process: PR #199 (version sync across the 4 code points + readme.txt Stable tag; CHANGELOG 4.7.0; a VERSIONING.md worked example documenting the **MINOR-as-product-signaling override** — the strict rule yields patch since no *new* declared public API was added, the one 2FA render hook being pre-existing and documented; readme size ~17k→~18k) → annotated tag `v4.7.0` on `3cf7ee7` → `release.yml` auto-published the GitHub Release with `wp-sudo.zip` (stable `releases/latest/download/wp-sudo.zip` now points at it) → PR #200 post-tag (blueprint.json → `archive/refs/tags/v4.7.0.zip`, docs/release-status.md, readme.txt Changelog/Upgrade Notice). Pre-commit review caught and corrected a VERSIONING.md drafting confabulation (had called the pre-existing/documented `wp_sudo_render_two_factor_fields` hook "new/undocumented") before commit. Known non-blocking debt: docs/release-status.md lines ~118–124 still carry a stale v4.5-era "staged 4.6.0 unreleased" narrative (historical, not a current-state claim) — tidy in a future pass.
- 2026-07-16: Post-Milestone-B demo/docs polish arc (no feature-track work). Landed on `main`: **#193** recaptured the in-editor 2FA modal screenshot with the Pixel Managed Platform (pmp) admin notice suppressed (`assets/editor-2fa-modal.png` + `.wordpress-org/screenshot-10.png`, byte-identical, 1280×536); **#194** clarified the reauth-demo readme link as "(no 2FA)"; **#196** added `blueprint-multisite.json` (multisite/network-admin scenario — reuses the `blueprint-main.json` seed verbatim, appends Playground `enableMultisite` LAST, network-activates WP Sudo, lands the super admin on `/wp-admin/network/settings.php?page=wp-sudo-settings`), closing issue **#72**; **#197** promoted it to a 5th "Try multisite" readme Playground badge (tier bumped to Public/reviewer in CONTRIBUTING). **Not self-verified:** headless Playground renders blank to automation, so the multisite blueprint's actual landing page needs a human browser click on the badge to confirm — if it lands wrong, revisit the network-activation `runPHP` step. Open scenario-blueprint issue **#67 (lockout-state, good first issue) is intentionally left unclaimed** for a possible external contributor — do NOT auto-pick it. Modeled after `blueprint-recovery-mode.json` / `blueprint-user-switching.json` (derive from `blueprint-main.json` so the seed stays intact); its checklist target is `docs/ui-ux-testing-prompts.md` §6 (add a new §6x).
- v4.5 roadmap created 2026-06-30: Phase 24 (Session Revocation UI) covers REVK-01..05; Phase 25 (Governance Coverage Panel Fix) covers GCOV-01..02.
- 2026-07-04: v4.5 follow-up arc complete. PR #139 (widget records/labels session_revoked events; i18n label fix), PR #140 (bulk-action revocation redesign + nonce interceptor + membership guards + badge invalidation + registry scrub, hardened through Copilot/Codex/maintainer review rounds), and PR #141 (Phase 25 GCOV-01/02) opened; #139/#140 merged same day. Manual REVK-01..05 browser verification completed with all PASS (24-VERIFICATION.md: verified); confabulation entries #29/#30 logged in docs/llm-lies-log.md.
- Phase 24 plan 03 complete 2026-07-01: Access-tab "Revoke Session" button/column removed and the orphaned wp_ajax_handle_revoke_session registration, AJAX wrapper, and dead JS handler deleted across 2 commits (941 tests green). REVK-04 now satisfied alongside REVK-01/02/03/05 (already Complete). Phase 24 was feature-complete (3/3 plans) with the automated gate green; the then-deferred manual browser verification was completed 2026-07-04 (see entry above).
- Phase 24 plan 02 complete 2026-06-30: Users-list "Revoke sudo session" row action, revoke-all button + interstitial confirm, and centralized wp_admin_notice() result-code map shipped across 4 commits (943 tests green). REVK-01 now fully satisfied alongside REVK-02/03/05 (already Complete from plan 01).
- v4.4.0 milestone completed 2026-06-30 (Phases 21–23 all complete). Archive: `.planning/milestones/v4.4.0-ROADMAP.md`.
- Phase 23 context is complete in `.planning/phases/23-bridge-documentation-and-release-posture-closure/23-CONTEXT.md`; defaults captured: update Two Factor integration/ecosystem docs, refresh current metrics, add concise unreleased/current-main notes if warranted, preserve Patchstack fixture-blocked wording (superseded 2026-07-05 by PR #145 — the Patchstack fixture blocker is now resolved), and avoid version/tag/submission actions.
- Phase 22 has two verified execution plans: `22-01-PLAN.md` for design-reviewed TDD implementation and `22-02-PLAN.md` for required validation/scope-boundary checks.
- Phase 22 verification passed in `.planning/phases/22-profile-provider-guard-implementation/22-VERIFICATION.md` with 9/9 must-haves verified.
- Phase 22 plan 22-01 implemented `two_factor.profile_provider_lifecycle` in the optional Two Factor lifecycle bridge with RED/GREEN unit coverage; targeted bridge tests, `composer test`, `composer analyse`, and `composer lint` passed.
- Profile-provider guard replay now preserves source-verified core profile fields so mixed profile plus Two Factor saves replay as complete core profile updates after the WP Sudo challenge.
- Phase 21 source evidence is complete in `.planning/phases/21-two-factor-source-refresh-and-guard-design/21-SOURCE-EVIDENCE.md`; upstream WordPress/two-factor commit `c515462d51ac92941685e39293673c08538e16c8` was checked 2026-06-30.
- v4.3.1 is archived in `.planning/milestones/v4.3.1-ROADMAP.md` and `.planning/milestones/v4.3.1-REQUIREMENTS.md`.
- Phase 20 execution files are archived in `.planning/milestones/historical-phases/20-baseline-e2e-shard-rebalance/`.
- v4.3.0 is archived in `.planning/milestones/v4.3.0-ROADMAP.md` and `.planning/milestones/v4.3.0-REQUIREMENTS.md`.
- Current test and size counts are centralized in `docs/current-metrics.md`.
- WordPress 7.0 GA shipped May 20, 2026; package metadata says `Tested up to: 7.0`.
- This plugin is not currently published to the WordPress.org plugin repository.
- PR #129 validation run: https://github.com/dknauss/Sudo/actions/runs/28406226487.

### Pending Todos

- Patchstack runtime testing: the core detection/validation bridge path is runtime-validated offline against a legitimately licensed Pro 2.3.6 fixture (PR #145). Remaining: the live login-form challenge, `profile.php` save, and WooCommerce lifecycle runs, plus the decision on shipping a dedicated bridge vs. keeping Patchstack behind the upstream Two Factor lifecycle bridge — before any shipped Patchstack support claim.
- (Resolved 2026-07-05 via PR #143) Public screenshot refresh: the v4.5 Access-tab, widget "Revoked" pills, and Users-list "Revoke sudo sessions" bulk-action surfaces were swapped into the 9-image set within the 6-9-image budget, with readme.txt captions and the README gallery kept in sync.

(Resolved 2026-07-04: the REVK-01..05 manual browser verification and the Phase 25 design review, both formerly listed here — see 24-VERIFICATION.md and PR #141.)

## Key Decisions

- GitHub Actions job timestamps are the source of truth for E2E runtime tuning decisions; no local Playwright/browser timings should drive rebalances.
- Keep WordPress.org submission delayed/on hold while maintaining readiness.
- Do not create product release tags from GSD milestone names unless plugin version metadata/readme stable tag have been intentionally bumped.
- Revocation surface for v4.5 = Users-list row action (not an Access-tab panel); chosen because it reuses existing `_wp_sudo_expires > time()` enumeration and puts revocation where admins already manage users.
- (Superseded 2026-07-04 by PR #140) The revoke-all button + confirmation interstitial were replaced by a native "Revoke sudo sessions" bulk action (selection is the confirmation, per core's password-reset precedent); site-wide revoke-everything is CLI-only (`wp sudo revoke --all`). Single-user revocation continues to act immediately.
- Access-tab capability-holder table loses the "Revoke Session" button in Phase 24; session revocation and capability administration become separate concerns.
- (Revised by the Phase 25 design review, implemented in PR #141) The coverage panel KEEPS the raw `allcaps` predicate (recovery-remap immunity, viewer independence) and adds an `is_multisite()`-gated `is_super_admin()` exclusion mirroring `wp_sudo_can()`'s short-circuit — rather than switching wholesale to `wp_sudo_can()`, which would re-introduce the viewer-dependent recovery remap.
- `Sudo_Session::is_active()` requires a cookie-bound token; the Users-list enumeration must use `_wp_sudo_expires > time()` meta query (browser-independent) — keep that distinction explicit in Phase 24 code.
- v4.4.0 scope targeted upstream WordPress Two Factor profile-provider lifecycle changes; Patchstack was out of v4.4.0 implementation scope. (Update 2026-07-05 via PR #145: the Patchstack fixture blocker is resolved — core bridge path runtime-validated offline against a licensed Pro 2.3.6 fixture; still not shipped.)
- Keep the Two Factor profile lifecycle guard in the optional bridge and fail open when upstream lifecycle data cannot prove a meaningful change.
- Document Two Factor integration as three distinct surfaces (challenge-time, REST lifecycle, classic profile-provider lifecycle) with the optional bridge serving surfaces 2 and 3.
- (Superseded 2026-07-05 by PR #145) Phase 23 kept Patchstack fixture-blocked/manual-test-only with no runtime claims. The fixture blocker is now resolved: the core bridge path is runtime-validated offline against a licensed Pro 2.3.6 fixture, while the live login/profile/WooCommerce flows remain manual-test and no shipped support is claimed.
- (Superseded — was a v4.5-development directive: "do not bump WP_SUDO_VERSION or create a product tag at any v4.5 phase; latest tagged release remains 4.2.2." That held during v4.5 development but no longer applies: v4.5 shipped as `v4.5.0` (2026-07-05) and was folded into `v4.6.0` (2026-07-06). **Latest tagged release is now `v4.7.0`** (cut 2026-07-16) — `docs/release-status.md` is canonical.)
- `Sudo_Session::revoke_all_active_sessions()` enumerates via a `_wp_sudo_expires > time()` (NUMERIC) meta_query, not the old meta-key-presence form, so revoke-all acts on exactly the live set the "Sudo Active (N)" count advertises (Phase 24 plan 01).
- `Admin::revoke_session_core()` check order is cap -> self_target -> target_expired -> rate_limited -> success, with cap checked first so a capless caller can never distinguish target_expired from no_cap (Phase 24 plan 01).
- The bulk revoke-all enumerator stays gate-free (no cap/rate logic in `Sudo_Session`) so WP-CLI can call it un-gated; the UI revoke-all's own cap+rate wrapper is deferred to Phase 24 plan 02 (Phase 24 plan 01).
- Phase 24 plan 02's revoke-all UI perform handler implements the cap+rate wrapper directly (not in `Sudo_Session`), confirming design-review objection C: one rate slot is consumed per batch, not per revoked session.
- The Users-list "Revoke sudo session" row action is shown even when the operator has no active sudo session of their own; clicking then fails with a distinct actionable message rather than hiding the action (Phase 24 plan 02).
- A target whose session expires mid-batch during revoke-all is silently skipped by the enumeration (not an error); the target_expired outcome is a per-user-path concern surfaced only by the row-action handler, not a revoke-all error path (Phase 24 plan 02).
- `Admin::select_revoke_result_notice()` returns `null` for unknown/absent result codes rather than a generic fallback message, so no code can render a fabricated notice (Phase 24 plan 02, REVK-05).
- Access-tab capability-holder table's "Revoke Session" button and the orphaned `wp_ajax_handle_revoke_session` registration/wrapper/JS handler are removed (Phase 24 plan 03); `revoke_session_core()` and the two plan-02 admin-post handlers are the sole live revocation entry points.
- Phase 24's manual browser-based UI verification (REVK-01..05) was deferred at plan 24-03 time and COMPLETED 2026-07-04 against main (post #139/#140) with all PASS; 24-VERIFICATION.md is the record.

## Performance Metrics

| Date | Phase | Plan | Duration | Tasks | Files |
|------|-------|------|----------|-------|-------|
| 2026-06-30 | 22-profile-provider-guard-implementation | 01 | 19min | 3 | 5 |
| 2026-06-30 | 22-profile-provider-guard-implementation | 02 | 1min | 2 | 1 |
| 2026-06-30 | 23-bridge-documentation-and-release-posture-closure | 01 | 8min | 3 | 5 |
| 2026-06-30 | 24-session-revocation-ui | 01 | 25min | 2 | 6 |
| 2026-06-30 | 24-session-revocation-ui | 02 | ~50min | 3 | 3 |
| 2026-07-01 | 24-session-revocation-ui | 03 | unspecified | 2 | 3 |

## Last Session

- **Stopped at:** 2026-07-05 — v4.5 milestone complete and **merged**: Phase 25 (GCOV-01/02) landed via PR #141 (merged 2026-07-04), following PRs #139/#140 (widget revocation visibility + i18n labels; bulk-action revocation redesign) and the REVK-01..05 manual browser verification (all PASS; 24-VERIFICATION.md status: verified). Session follow-ups also merged: PR #143 (public screenshot refresh) and PR #144 (Access-tab capability-table readability + per-capability accessible names + translatable labels). Patchstack 2FA compatibility runtime-validated offline against a legitimately licensed Pro 2.3.6 fixture and documented in PR #145 (in review).
- **Next:** v4.5 is closed — archive the milestone per GSD convention. Remaining follow-ups: the Patchstack live login-form / profile-save / WooCommerce lifecycle runs plus the bridge-vs-upstream decision (core bridge path already runtime-validated offline, PR #145); larger roadmap items per docs/ROADMAP.md (Gutenberg reauth UX design is the top major-feature track).
