# #322 — Pre-Implementation Design Brief (post-panel, decided)

Plugin P1, shipping code. This brief incorporates the three-model review panel (Opus + Fable + Sonnet, 2026-07-26); the panel served as the CLAUDE.md Pre-Implementation Design Review. Decision: **"Both"** — ship the global fail-closed fix in v1; add origin-bound replay as a later UX enhancement. Line refs verified against `origin/main`.

## Problem & threat (confirmed by panel)
The request stash is keyed to `user_id` alone (`class-request-stash.php:201-202`); the only per-stash secret is the random key, which travels in the challenge URL. On success, replay executes the stashed request; if sudo is already active it auto-resumes with no fresh confirmation. A cloned session (stolen cookie, no password) plants a stash; the attacker sends the victim the challenge URL; the victim reauthenticates → the plugin executes the **attacker's** transaction. The clone shares the WP session verifier, so nothing in the current binding distinguishes it from the victim.

**Panel corrections to the original framing:**
- The worst routes are **GET** actions: replay of a GET is a *redirect to the stashed URL* (`build_replay_response_data()` GET branch → `class-challenge.php:984`). `network.site_delete`, `theme.switch`, `tools.export` are GET with no stash allowlist — plant → victim reauth → browser redirected to the destructive URL. A POST-body/`success_url`-only fix does nothing here.
- There are **three** auto-replay entry points, not one: `replay_stash` (`:661`), `render_resume_page` (`:944`), and `complete_active_session_request` (`:915`, from two AJAX paths `:643`/`:739`).
- A per-class `replay_mode:none` denylist **fails open**: third-party rules via `wp_sudo_gated_actions` and even the plugin's own `options.wp_sudo*` settings-gate default to replayable; and it misses `plugin.*`/`theme.*` (categories `plugins`/`themes`).
- Accuracy: `user.change_password`/`change_email` are *already* `stash_no_replay` (`:394`,`:445`); the only replayable credential rule is `auth.app_password` (`:484`). `return_url` is `wp_get_referer()` captured at stash time → attacker-controlled.

## Decided approach

### v1 — global fail-closed (ship now)
Enforce at the single chokepoint **`build_replay_response_data()`** so all three callers inherit it:
1. **Never auto-execute / auto-submit** a stashed action, and **never redirect to the stashed URL or `return_url`**, for any gated action. Redirect to a **neutral** admin URL (dashboard) carrying the existing **`BLOCKED_REPLAY_QUERY_ARG`** "review and submit again" notice (`class-challenge.php:154`).
2. **Drop `success_url`** (and any redirect/exfil field) from persisted stash data (`class-action-registry.php:484`). Legit external-app handoff is preserved via the user's *own* fresh resubmit, which still carries `success_url`.
3. This is **fail-closed and method-independent** (covers GET and POST) and needs **no per-class taxonomy** — the vulnerable primitive is auto-resume-without-confirm itself, so remove it universally. Benign GET filters (plugin-list `s`/`paged`) simply require a re-click; acceptable.

### Later enhancement — origin-bound replay (restores UX; not v1)
Bind each stash to a fresh **pre-challenge browser-instance secret** set in whichever browser created the stash (a dedicated cookie at stash time). A stash planted by the attacker's clone carries the secret in the *attacker's* browser; the victim's browser lacks it → the victim's reauth cannot release it. This lets benign auto-replay return without the confused-deputy risk.
- **This is NOT #279's key.** #279 keys the proof per login-session verifier — which the clone **shares** — so #279 does not distinguish attacker from victim and does not close #322. #322 needs a per-*browser* secret, distinct machinery. (Corrects an earlier assumption that the two overlap; they can proceed independently.)

### Rejected
The per-class `replay_mode:none` list (my original brief's primary approach) — fails open, misses GET, misses `plugin.*`/`theme.*`.

## Tests to update deliberately (Opus, verified)
- `tests/e2e/specs/challenge.spec.ts` **CHAL-01** asserts a GET `plugin.activate` reauth *replays the action* → must change to "action NOT performed; neutral redirect."
- **CHAL-04** exercises active-session resume (`render_resume_page`).
- No test asserts `success_url` is in the allowlist (grep: 0 hits) — dropping it breaks nothing.
- `ReauthFlowTest::test_stash_is_user_bound_across_flow` pins user_id-only ownership — relevant only when the later origin-binding lands.

## Must block / must not break
- **Block:** planted-stash replay of ALL gated actions (GET + POST) after a victim reauth; app-password mint-and-exfil.
- **Not break:** the user's own resubmit (existing blocked-replay notice); the editor modal (owner-scoped JS closure, no server stash — unaffected); the REST/AJAX 403 + `challenge_url` contract (do not `wp_die`); WP-CLI/cron/REST (not on the challenge/stash path — Opus confirmed).

## Workflow
Panel = the Pre-Implementation Design Review (done). Proceed to TDD (failing tests first; reflection for private state, no new getters), `composer test` + `analyse` + `lint` green, pre-commit reviewer agent approval before each commit. Own worktree/branch. Independent of #278/#279/#280.
