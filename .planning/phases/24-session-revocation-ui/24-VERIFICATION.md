---
phase: 24-session-revocation-ui
verified: 2026-07-01T15:43:27Z
status: human_needed
score: 5/5 must-haves verified (code-level); manual browser UI verification outstanding
human_verification:
  - test: "As an operator WITH revoke_wp_sudo_sessions and an active sudo session, on the Users list: confirm the 'Revoke sudo session' row action appears only for users with a live session (per 'Sudo Active (N)'), never on your own row; click it and confirm the session revokes immediately with a dismissible success notice and the row drops from 'Sudo Active'."
    expected: "Row action visible only for live-session, non-self rows; click causes immediate revoke and a success notice; user disappears from the active-session set."
    why_human: "Requires a live Studio/wp-env multisite session, real login state, and visual/DOM confirmation of row-action rendering and notice display — not verifiable via static code/grep analysis alone."
  - test: "As a cap-holder WITHOUT an active sudo session of their own, confirm the row action is still visible, and clicking it shows the distinct 'Start a sudo session before revoking others.' notice (no-operator-session path)."
    expected: "Row action renders (visibility not gated on operator's own session); click fails with the distinct message, never a silent no-op."
    why_human: "Requires simulating an operator with capability but expired own sudo session in a live browser session."
  - test: "As an operator WITHOUT revoke_wp_sudo_sessions, confirm the row action and the 'Revoke all active sessions' button are both entirely absent from the Users list."
    expected: "Neither UI surface renders for a capless user."
    why_human: "Requires a live login as a non-privileged user and visual confirmation of absence."
  - test: "Revoke-all: confirm the button is visible only when the current-site active-session count > 0, opens an interstitial stating the exact count with Confirm/Cancel, and Confirm revokes all current-site sessions except the operator's own, returning to users.php with a success+count notice."
    expected: "Button absent at count 0; interstitial shows correct count; Confirm excludes operator; success notice shows accurate count."
    why_human: "Requires multiple real users with live sessions on a Studio/wp-env multisite to observe count accuracy and the confirm/cancel flow end-to-end."
  - test: "Confirm every blocked path (no-cap, no-operator-session, target-already-expired, rate-limited) and the success paths render a visually distinct, dismissible notice with correct assistive-tech semantics (role=alert/aria-live=assertive for errors, role=status/aria-live=polite for success)."
    expected: "Each notice is visibly distinct, dismissible, and exposes correct ARIA semantics in the rendered DOM."
    why_human: "ARIA/role rendering and visual dismissibility require a real browser DOM inspection; unit tests only assert the arguments passed to wp_admin_notice(), not the final rendered markup."
  - test: "Access tab: confirm no 'Revoke Session' button/column renders anywhere in the capability-holder table, and capability grant/revoke controls are visually unchanged."
    expected: "No session-revoke button visible on the Access tab; capability administration UI otherwise unchanged."
    why_human: "Requires visual inspection of the rendered Access-tab screen."
---

# Phase 24: Session Revocation UI Verification Report

**Phase Goal:** Add Users-list row action for per-user revocation and a UI-surfaced revoke-all with confirmation; remove the Access-tab "Revoke Session" button; provide clear feedback on every path including gated/no-session and missing-cap cases.

**Verified:** 2026-07-01T15:43:27Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | A per-user "Revoke sudo session" Users-list row action exists, shown only for users in the active-session set, never on the operator's own row, absent without the cap | ✓ VERIFIED (code) | `includes/class-admin.php:1270-1303` `user_row_actions()` — checks `wp_sudo_can('revoke_wp_sudo_sessions')` first, then `get_current_user_id() === $row_user_id` (own-row exclusion), then `Sudo_Session::is_session_live($row_user_id)` (same predicate as the "Sudo Active" enumeration). Hooked via `add_filter('user_row_actions', ...)` at line 369. |
| 2 | UI revoke-all is behind a confirmation step; single-user revoke acts immediately, only revoke-all confirms | ✓ VERIFIED (code) | Per-user handler (`handle_revoke_session_row_action`, line 1320) acts immediately on a nonced GET link — no interstitial. Revoke-all button (line 1383) links to `render_revoke_all_confirm_screen()` (line 1421), a `.wrap` interstitial with exact count + Confirm(POST)/Cancel, which posts to `handle_revoke_all_perform()` (line 1468) to actually revoke. |
| 3 | Revocation honors the cap, the per-revoker rate limit, and the `wp_sudo_session_revoked` audit hook on every UI path | ✓ VERIFIED (code) | `revoke_session_core()` (line 2145) enforces cap → self_target → target_expired → rate_limited → deactivate → `do_action('wp_sudo_session_revoked', ...)` for the per-user path. `process_revoke_all_perform()` (line 1500) independently enforces cap → operator-session → one rate slot → `Sudo_Session::revoke_all_active_sessions()` → `do_action('wp_sudo_session_revoked', 0, ...)` for the batch path — both paths gate cap+rate+audit. |
| 4 | The Access-tab capability-holder table no longer offers per-holder session revocation | ✓ VERIFIED (code) | Grep for `wp-sudo-revoke-session`, `AJAX_REVOKE_SESSION`, `handle_revoke_session`, `revokeSessionAction`, `sessionRevoked` returns zero matches in `includes/class-admin.php` and `admin/js/wp-sudo-admin.js`. Capability controls (`wp-sudo-revoke-cap`, "Grant Capability") remain present at lines 1883/1896/1936. |
| 5 | Every revocation path gives clear visible success/failure feedback, including distinct no-operator-session and missing-cap messages, never a silent no-op | ✓ VERIFIED (code) | `select_revoke_result_notice()` (line 1620) maps 6 distinct outcome codes (`no_cap`, `no-operator-session`, `self_target`, `target_expired`, `rate_limited`, `success`) to distinct messages; `default` case returns `null` (no fabricated notice for unknown codes) rather than a generic message. Rendered via `wp_admin_notice()` with `is-dismissible`, `role`/`aria-live` set per type (line 1590-1600). Hooked to `admin_notices` restricted to `users.php` (line 378, 1570). |

**Score:** 5/5 truths verified at the code level. All 5 truths additionally require live-browser confirmation (see Human Verification below) which is a known, recorded, and deliberately deferred item — not a code gap.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/class-sudo-session.php` | `is_session_live()`, `revoke_all_active_sessions()` | ✓ VERIFIED | Lines 1224-1279. Matches plan interface sketches exactly: live-set `meta_query` on `_wp_sudo_expires > time()` (NUMERIC), post-enumeration exclude filter, gate-free by design. |
| `includes/class-cli-command.php` | CLI delegates to shared method | ✓ VERIFIED | Line 83: `Sudo_Session::revoke_all_active_sessions()`. Private duplicate enumerator confirmed removed (no `private function revoke_all_active_sessions` remains). |
| `includes/class-admin.php` | `revoke_session_core()`, row action, admin_post handlers, revoke-all UI, notice map | ✓ VERIFIED | `revoke_session_core()` (2145), `user_row_actions()` (1270), `handle_revoke_session_row_action()`/`process_revoke_session_row_action()` (1320/1349), `render_revoke_all_button()` (1383), `render_revoke_all_confirm_screen()` (1421), `handle_revoke_all_perform()`/`process_revoke_all_perform()` (1468/1500), `select_revoke_result_notice()`/`render_revoke_result_notice()` (1620/1569). All present, non-stub, substantively implemented. |
| `admin/js/wp-sudo-admin.js` | Dead session-revoke JS handler removed | ✓ VERIFIED | Zero matches for `wp-sudo-revoke-session` in the file. |
| `tests/Unit/SudoSessionTest.php`, `CliCommandTest.php`, `AdminTest.php` | Test coverage for new/changed behavior | ✓ VERIFIED | 941/941 unit tests green; 39 row-action/revoke-all/core/notice-specific tests found by name-pattern grep; explicit no_cap/self_target/target_expired/rate_limited core tests present (lines 3600-3660); AJAX-hook-absence inversion test present (`test_register_does_not_add_revoke_session_ajax_hook`, line 3120); `admin_notices` hook-registration test present (line 4613). |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `class-cli-command.php` `revoke()` | `Sudo_Session::revoke_all_active_sessions` | static call | ✓ WIRED | Line 83, no exclusion arg (CLI has no operator browser session). |
| `revoke_session_core()` | `Sudo_Session::is_session_live` | target-liveness precondition | ✓ WIRED | Line 2157, returns `target_expired` before rate/deactivate when false. |
| `revoke_session_core()` | `wp_sudo_session_revoked` | `do_action` on success | ✓ WIRED | Line ~2182 (audit hook fires with target, revoker, reason, blog_id). |
| `user_row_actions` filter | `Sudo_Session::is_session_live` | visibility gate | ✓ WIRED | Line 1281, shared predicate — not a re-implemented `> time()` comparison. |
| `admin_post_` per-user handler | `revoke_session_core` | delegates with `users_list_row_action` reason tag | ✓ WIRED | Line 1360; every outcome mapped to a distinct redirect query arg (line 1349-1363). |
| `revoke-all perform` | `Sudo_Session::revoke_all_active_sessions` | operator-excluded, one rate slot | ✓ WIRED | Line 1527, called only after cap+operator-session+rate checks (lines 1501-1525); one rate slot consumed per batch, not per user. |
| `admin_notices` hook | `render_revoke_result_notice` | hook registration | ✓ WIRED | Line 378 registers; test at line 4613 asserts registration. |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|--------------|--------|----------|
| REVK-01 | 24-02 | Per-user row action, active-set-only, current-site, self-excluded | ✓ SATISFIED (code) / outstanding manual check | `user_row_actions()` at class-admin.php:1270 implements exactly this gate order. Live-browser rendering confirmation deferred (see human_verification). |
| REVK-02 | 24-01, 24-02 | UI revoke-all behind confirmation; single-user acts immediately | ✓ SATISFIED (code, with documented scope deviation) | Confirmation interstitial confirmed at line 1421; per-user action is immediate (line 1320, no confirm step). **Scope note:** REQUIREMENTS.md/ROADMAP wording says "network-wide" but 24-CONTEXT.md and both plan-01/02 frontmatter explicitly document a deliberate v4.5 scope decision to implement **current-site-scoped** revoke-all, deferring true network-wide enumeration to REVK-F3. Code matches the documented decision (`revoke_all_active_sessions()` has no blog-iteration logic; comment at class-admin.php:368 says "current-site only"). This is a **documentation wording gap in REQUIREMENTS.md/ROADMAP.md, not an implementation gap** — flagged, not treated as a failure per the verification brief. |
| REVK-03 | 24-01, 24-02 | Cap, rate limit, audit hook on every UI path | ✓ SATISFIED (code) | Confirmed on both the per-user core (`revoke_session_core`) and the revoke-all perform path (`process_revoke_all_perform`) independently — each enforces its own cap/session/rate checks before acting, consistent with design-review objection C (gate-free shared enumerator, UI-side gating). |
| REVK-04 | 24-03 | Access-tab session-revoke button removed; table governs capabilities only | ✓ SATISFIED (code) | Zero remaining references to the removed button/AJAX symbols; capability controls (Grant/Revoke Capability) confirmed intact and untouched. |
| REVK-05 | 24-01, 24-02 | Clear feedback on every path incl. no-session/no-cap, never silent | ✓ SATISFIED (code) | `select_revoke_result_notice()` covers 6 distinct codes with `null` fallback (no fabricated notice) — satisfies "never a silent no-op" at the code/mapping level. Visual/ARIA rendering confirmation deferred (see human_verification). |

No orphaned requirements found — REQUIREMENTS.md maps only REVK-01..05 to Phase 24, and all five appear in plan frontmatter `requirements:` fields (24-01: REVK-02/03/05; 24-02: REVK-01/02/03/05; 24-03: REVK-04).

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | None found | — | Grep for TODO/FIXME/XXX/HACK/PLACEHOLDER across `class-admin.php`, `class-sudo-session.php`, `class-cli-command.php`, `admin/js/wp-sudo-admin.js` returned zero matches. No stub returns (`return null`/empty array with no logic), no console.log-only handlers, no orphaned dead code from the removed AJAX path. |

### Human Verification Required

The following items are **known, recorded, and deliberately deferred** per explicit user decision documented in `24-03-SUMMARY.md` ("Outstanding: Manual UI Verification") and `STATE.md` Pending Todos. They are NOT treated as phase failures — the automated gate (composer test 941/2831 green, PHPStan L6 + Psalm clean, PHPCS clean, `composer verify:metrics` in sync) is green, and all code-level evidence for REVK-01..05 is present and substantively wired, as detailed above.

1. **Row action visibility and immediate-revoke behavior** — verify on a live Studio/wp-env multisite as an operator with cap + active session.
2. **No-operator-session distinct message** — verify the row action is visible but click fails with the distinct message when the operator lacks their own active session.
3. **Capless-operator absence** — verify both the row action and revoke-all button are entirely absent for a user without the capability.
4. **Revoke-all count-gated visibility, interstitial accuracy, and operator exclusion** — verify end-to-end on a multisite with multiple live sessions.
5. **Notice visual/ARIA correctness** — verify rendered DOM for dismissibility and role/aria-live attributes across all 6 notice codes.
6. **Access-tab visual confirmation** — verify no session-revoke button renders and capability controls are otherwise unchanged.

Per the verification brief: run these six checks from a browser-capable session (`claude-playwright` / `claude-browser-handoff`) before treating REVK-01..05 as fully verified end-to-end. This aligns with the project's CLAUDE.md browser/Playwright handoff policy, which this project (and its automated verifier) also cannot bypass.

### Gaps Summary

No code-level gaps found. All 5 observable truths, all required artifacts (three levels: exists, substantive, wired), all key links, and all 5 REVK requirement IDs are satisfied by the actual codebase — not merely claimed by the SUMMARYs. Independent verification (direct source reads, grep-based absence checks, live test run at 941/941, PHPStan L6 + Psalm clean, PHPCS clean, `composer verify:metrics` in sync, and git log confirmation of all 7 phase commits) corroborates the SUMMARY claims without relying on them.

One documentation-accuracy note (not a code gap): REQUIREMENTS.md's REVK-02 text still says "network-wide" while the implementation is deliberately current-site-scoped per 24-CONTEXT.md's documented v4.5 scope decision (network-wide deferred to REVK-F3). Recommend updating REVK-02's wording in REQUIREMENTS.md to say "current-site" to match the implementation, as plan 01 itself recommends.

The phase's only genuine outstanding item is the deferred manual browser-based UI verification of REVK-01..05, which is a recorded, deliberate scope decision (not a lapse) and is reflected here as `status: human_needed` rather than `gaps_found`.

---

*Verified: 2026-07-01T15:43:27Z*
*Verifier: Claude (gsd-verifier)*
