---
gsd_state_version: 1.0
milestone: post-v4.5 — In-Editor Gutenberg Reauth (Milestone A shipped; Milestone B next)
milestone_name: — In-Editor Reauth (tracked on `.planning/gutenberg-editor-reauth-*`, not the GSD phase counter)
status: v4.5 (Session Governance & Admin UX) complete and RELEASED — tagged v4.5.0 (2026-07-05) and folded into v4.6.0 (2026-07-06). In-Editor Reauth Milestone A (password path, full modal) merged via PR #178 (2026-07-07) and is unreleased on `main` past the v4.6.0 tag; demo/readme/research follow-ups #179–#183 merged 2026-07-15. Milestone B (in-modal 2FA) is the next feature track.
stopped_at: "In-Editor Reauth Milestone A complete and merged (PR #178, 2026-07-07): the full password-path modal in the block editor — modal grant + re-dispatch, the 2FA-bypass security invariant, stale-nonce recovery, owner-scoped concurrent re-dispatch, degradation guards, and the codex-flagged 2FA double-prompt fix (link out once). Execution checklist Steps 0–8 all done; two items deferred by design — the manual password-manager × 2FA matrix (needs real managers) and the full CI single-site+multisite E2E sweep (owner/CI). Follow-ups merged 2026-07-15: self-guiding demo (#180/#181), and two filed research todos — in-editor session indicator (#182) and MU-plugin role/cap lockdown mode (#179). Milestone B (in-modal 2FA) has a reviewed brief + 7-step TDD order in the execution checklist and is not yet started."
last_updated: "2026-07-15"
last_activity: 2026-07-15 — cleanup pass: reconciled stale STATE/ROADMAP/PROJECT release facts (latest tag is v4.6.0, not v4.2.2) after In-Editor Reauth Milestone A (PR #178) and follow-ups #179–#183 merged. v4.5 GSD milestone remains complete (Phases 24–25).
progress:
  gsd_milestone: v4.5 — complete (Phases 24–25; released in v4.5.0 / v4.6.0)
  active_track: In-Editor Reauth — Milestone A merged (checklist Steps 0–8 done); Milestone B not started
  total_phases: 2
  completed_phases: 2
  total_plans: 3
  completed_plans: 3
  percent: 100
---

## Current Position

Active track: **In-Editor Gutenberg Reauth** (own planning docs: `gutenberg-editor-reauth-milestone-plan.md`, `-execution-checklist.md`, `-milestone-b-2fa-partial-brief.md`).
Milestone A: **complete and merged** (PR #178, 2026-07-07) — the full password-path reauth modal in the block editor. Checklist Steps 0–8 all done. Unreleased on `main` past the v4.6.0 tag.
Milestone B: **not started** — in-modal 2FA. Reviewed brief + 7-step RED→GREEN TDD order sit at the bottom of `gutenberg-editor-reauth-execution-checklist.md`.
Status: Milestone A shipped the modal grant + re-dispatch, the 2FA-bypass invariant (2FA users get `2fa_pending`, never a session), stale-nonce recovery, owner-scoped concurrent re-dispatch, degradation/no-safe-URL guards, and the 2FA double-prompt fix (2FA users link out once instead of entering a password twice). Follow-ups #180/#181 made the demo self-guiding; #182 filed the in-editor session-indicator research todo; #179 filed the MU-plugin lockdown research todo.
Progress: [██████████] 100% of the prior GSD milestone (v4.5, Phases 24–25); In-Editor Reauth Milestone A checklist Steps 0–8 done, Milestone B not started.
Last activity: 2026-07-15 — In-Editor Reauth Milestone A + follow-ups #179–#183 merged; this cleanup pass reconciled stale release facts across STATE/ROADMAP/PROJECT.
Branch: `main` is current (no open PRs). Milestone A work was developed on `feat/gutenberg-reauth-increment-3` (PR #178, merged).
Origin: block-editor reauthentication UX track — see `gutenberg-editor-reauth-design-brief.md` and the phase-2 plan.
Product release state: Latest tagged plugin release is **`v4.6.0`** (cut 2026-07-06; `main` is past it with Milestone A unreleased). Canonical: `docs/release-status.md`. v4.5 shipped as `v4.5.0` (2026-07-05) and was folded into `v4.6.0`.
Open threads: WordPress.org submission remains intentionally delayed/on hold; keep `docs/wporg-submission-checklist.md` ready. Milestone A carries two deferred items — the manual password-manager × 2FA matrix (needs real managers; `docs/password-manager-compatibility.md`) and the full CI single-site+multisite E2E sweep (owner/CI). Patchstack 2FA is runtime-validated as bridgeable offline (licensed Pro 2.3.6 fixture); remaining Patchstack work is the live login-form / profile-save / WooCommerce lifecycle runs and the bridge-vs-upstream decision.
Resume file: `.planning/gutenberg-editor-reauth-execution-checklist.md` (Milestone A ✅ Steps 0–8; Milestone B section at the bottom is the next work) and PR #178.

## Project Reference

Canonical current facts:

- `docs/e2e-runtime-review.md` — runtime evidence, Phase 20 implementation, validation run, and keep decision.
- `docs/release-status.md` — tagged/package release state and WordPress.org publication status.
- `docs/current-metrics.md` — current test, size, and architectural counts.
- `docs/ROADMAP.md` — product roadmap and priority order.
- `CHANGELOG.md` — shipped release contents.
- `.planning/MILESTONES.md` — archived GSD milestone history.

**Core value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Current focus:** In-Editor Gutenberg Reauth — Milestone A (password modal) shipped; Milestone B (in-modal 2FA) is next. Latest tagged release is **4.6.0** (see `docs/release-status.md`). WordPress.org submission (`docs/wporg-submission-checklist.md`) is delayed/on hold, but readiness should be maintained.

## Active Priorities

1. **In-Editor Reauth Milestone B (in-modal 2FA)** — the next build. Follow the reviewed brief (`gutenberg-editor-reauth-milestone-b-2fa-partial-brief.md`) and the 7-step TDD order in the execution checklist; do the mandatory pre-implementation design review before TDD.
2. Milestone A deferred items (do when the environment allows): the manual password-manager × 2FA matrix (needs real password managers; fill `docs/password-manager-compatibility.md`) and the full CI single-site + multisite E2E sweep.
3. Keep WordPress.org readiness maintained while submission remains delayed/on hold.
4. Patchstack core bridge path is runtime-validated offline; remaining Patchstack work is the live login-form / profile-save / WooCommerce lifecycle runs and the bridge-vs-upstream decision — no shipped support claim until those land.
5. Two research todos filed 2026-07-15 and awaiting answers before any implementation: in-editor session indicator (#182) and MU-plugin role/cap lockdown mode (#179).

## Accumulated Context

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
- (Superseded — was a v4.5-development directive: "do not bump WP_SUDO_VERSION or create a product tag at any v4.5 phase; latest tagged release remains 4.2.2." That held during v4.5 development but no longer applies: v4.5 shipped as `v4.5.0` (2026-07-05) and was folded into `v4.6.0` (2026-07-06). **Latest tagged release is now `v4.6.0`** — `docs/release-status.md` is canonical.)
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
