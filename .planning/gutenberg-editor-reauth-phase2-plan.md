# Phase Plan — Block-Editor Reauth, Phase 2 (Transparent Modal)

**Status:** Execution plan, not yet started. Derived from
[`gutenberg-editor-reauth-design-brief.md`](gutenberg-editor-reauth-design-brief.md)
after: surface inventory, design review, security review (SAFE WITH STATED CONDITIONS,
C1–C4), and the 2026-07-05 scope decision (**Tier 2 — transparent in-editor modal**).

**Not scheduled.** Sits behind v4.5 (Session Governance) in the roadmap order. This
plan exists so the feature is TDD-ready whenever prioritized. Each task below follows
the repo's TDD discipline (RED in the working tree → GREEN commit) and the
Pre-Implementation Design Review is already satisfied by the brief + two reviews.

---

## ⛔ Required gates — READ BEFORE WRITING ANY CODE

This feature is **security-sensitive** (new REST + editor surface, sudo-session grant,
CSRF-nonce exposure, capability-gated actions). Per `CLAUDE.md` and the global
"size review to risk" rule it gets a **mandatory deep review**, not a light touch.
These gates are non-negotiable; do not merge past their findings.

**Deterministic gates that WILL fire automatically (do not bypass):**
- **Pre-commit reviewer-agent approval** on every code commit. The text-only skip is
  `\.(md|txt|rst)$` — the editor `.js` and any `.php` here do **not** qualify, so each
  code commit needs a fresh `reviewer-approved` flag written by the reviewer agent
  (not the main agent). Do **not** use `USER_COMMIT=1` on AI-generated code.
- **`composer test` + `composer analyse` (PHPStan L6) + Psalm + PHPCS** via the hook.
- **CODEOWNERS** `* @dknauss` → GitHub review + "require conversation resolution".
- **`docs/current-metrics.md`** must be updated in the same commit that changes any
  counted quantity (new E2E specs, unit tests) — `composer verify:metrics` gates it.

**Judgment gates the deterministic gates CANNOT enforce — you must actively do these:**
1. **Reconfirm the design before TDD.** The Pre-Implementation Design Review is
   satisfied by the brief + two reviews, but before writing tests, verify the surface
   inventory and the security boundary still hold against the *current* code (routes,
   `intercept_rest`, `block_rest` cookie-auth branch, the AJAX grant endpoints). If any
   drifted, re-review before proceeding.
2. **Hand the reviewer the C1–C4 checklist explicitly.** The pre-commit reviewer is
   generic and will **not** know about these unless the review brief names them. Every
   review request for this feature MUST ask the reviewer to verify:
   - **C1** — grant nonce stays the single `wp_sudo_challenge` CSRF action; not broadened to authz use.
   - **C2** — grant nonce localized **only** on block/site-editor screens, skipped when `Sudo_Session::is_active()`; never sprayed onto non-editor pages.
   - **C3** — re-dispatch carries the user's own first-party `wp_rest` nonce; the request is never rebuilt server-side.
   - **C4** — `challenge_url` stays absent on the headless/app-password branch (regression test present and passing).
   - Plus: REST path never touches `Request_Stash`; no content/design save is gated; no `@wordpress/scripts` build step introduced.
3. **Security-focused review pass on the finished implementation.** The design-time
   security review (brief Part 3.6) cleared the *approach*; the real bugs live in the
   JS/enqueue. Run a security-scoped review of the actual diff (nonce localization
   surface, modal password field handling, re-dispatch) before merge.

---

## Goal

When a gated action is triggered from the block/site editor (in practice: Block
Directory plugin install/activate via `/wp/v2/plugins`), the user reauthenticates
**without leaving the editor** and the original action then completes — instead of
hitting an opaque `sudo_required` 403. No gating decision changes; no content save is
gated; the REST path never touches `Request_Stash`.

## Resolved residual decisions (were Part 5 Q2–Q4)

| # | Question | Decision for this plan | Why |
|---|---|---|---|
| Q2 | Unwrap `/batch/v1` envelopes, or out-of-scope? | **Out-of-scope for Phase 2, but detect and fail loud.** The gated `plugins` controller is not `allow_batch` in core today, so no gated route is currently batchable. The middleware must still *recognize* a batched `sudo_required` (inspect `responses[]`) and surface the snackbar rather than silently no-op, even if it only links out in that case. | Avoids building batch re-dispatch for zero current routes while preventing the SEV-2 silent-failure regression if a future/third-party gated route becomes batchable. |
| Q3 | Re-dispatch one request or all in-flight? | **Re-dispatch only the user-actioned request.** Queue concurrent `sudo_required` rejections; the modal resolves one; on grant, re-fire that one and let any others re-trigger naturally (they will now pass under the active session or re-prompt). Debounce so only one modal and one snackbar exist at a time. | Simplest correct behavior; avoids replaying requests the user may not have intended to retry; the active session covers the rest. |
| Q4 | SEV-3 label mislabel (`plugin.deactivate` → "Activate plugin") | **Suppress the label in the UI** (do not echo `rule_id`/`label`); show a generic "This action requires reauthentication." **Defer** the registry `status`-callback disambiguation to an optional follow-up. | Cheap, removes the user-visible lie now; the registry fix is a separate change with its own tests and isn't needed for correct UX. |

## Security conditions carried from the review (C1–C4) → tasks

- **C1** grant-nonce blast radius → keep the single `wp_sudo_challenge` nonce action (it is a CSRF token, not authz); do **not** broaden it. (Task 2)
- **C2** localize the grant nonce **only** on block/site-editor screens, skipped when `Sudo_Session::is_active()` — mirror `class-plugin.php:200-202`. (Task 2)
- **C3** re-dispatch carries the user's own `wp_rest` nonce (first-party; never server-rebuilt). (Task 4)
- **C4** regression: `challenge_url` stays absent on the headless/app-password branch. (Task 5)

---

## Task breakdown (TDD)

### Task 1 — `apiFetch` middleware: detect `sudo_required` (incl. batched), emit a signal
- **RED:** JS unit (or Playwright-driven) test: a mocked `apiFetch` rejection with
  `code === 'sudo_required'` and a `data.challenge_url` triggers the handler; a batched
  envelope carrying a `sudo_required` inner response also triggers it; a normal error
  does not.
- **GREEN:** `wp.apiFetch.use()` middleware that inspects both a top-level rejection and
  a `/batch/v1` `responses[]` array; extracts `challenge_url` (may be absent); dispatches
  an internal event/state the snackbar layer consumes. No password UI yet.
- **Notes:** build-free vanilla JS in `admin/js/`, deps declared on `wp-api-fetch`.

### Task 2 — Editor enqueue + grant-nonce localization (C1, C2)
- **RED:** PHP unit: the editor enqueue registers only on editor screens; the localized
  data carries the grant nonce and challenge-AJAX action; nothing is enqueued when
  `Sudo_Session::is_active()`; the nonce is **not** emitted on non-editor screens.
- **GREEN:** hook `enqueue_block_editor_assets` (or equivalent), mirroring the tight
  gating of `class-challenge.php:179` and the active-session skip at
  `class-plugin.php:200-202`. Reuse `NONCE_ACTION` (C1 — no new action).

### Task 3 — In-editor modal: password + AJAX 2FA via existing grant endpoints
- **RED:** test that the modal calls `wp_ajax_` `handle_ajax_auth` with the password and
  **no** `stash_key` (session-only grant), handles `{code:'authenticated'}`,
  `{code:'2fa_pending'}` → 2FA step via `handle_ajax_2fa`, `locked_out`, and
  `invalid_password`; closing the modal cancels cleanly.
- **GREEN:** `wp.element.createElement( wp.components.Modal, … )` — build-free. Collects
  the password, posts to the grant endpoint with the localized nonce, drives the 2FA
  sub-step. Snackbar (`core/notices`) is the trigger surface that opens the modal;
  it also degrades to a plain message when `challenge_url` is absent (headless).

### Task 4 — Re-dispatch the original request on grant (C3)
- **RED:** test that on `authenticated`, the exact original request is re-fired once,
  carrying a first-party `wp_rest` nonce, and its result resolves the promise the editor
  was awaiting; concurrent `sudo_required` requests are queued and not double-fired (Q3).
- **GREEN:** capture the original request options in the middleware, re-invoke `apiFetch`
  after grant; debounce modal/snackbar to a single instance.

### Task 5 — Headless-branch regression + graceful degradation (C4, Q4)
- **RED:** PHP: app-password/bearer `sudo_required` response has **no** `challenge_url`
  (assert against `class-gate.php:1869-1883`). JS: a rejection without `challenge_url`
  shows the plain snackbar message and **no** "Reauthenticate" action, and the UI never
  echoes the rule label (Q4).
- **GREEN:** already-true server invariant + client guards; this task locks them with tests.

### Task 6 — Playwright E2E (first challenge-transport E2E)
- Specs: Block Directory install/activate happy path (gated → modal → grant → auto
  re-dispatch → plugin active, editor state intact); AJAX 2FA grant path; grace-window
  no-duplicate-snackbar; grant nonce present only on editor screens and absent when
  session active; batched `sudo_required` surfaces the snackbar (does not silently
  no-op). Reuse the harness admin auth pattern per the repo's E2E convention.

---

## Out of scope (explicit)

- No new gating rules (surface inventory: zero required).
- No gating of content/design saves (posts, templates, global-styles, navigation, fonts, media).
- No `@wordpress/scripts` build step; no npm production dependency.
- No `Request_Stash` involvement on the REST path.
- No batch **re-dispatch** logic (detect-and-surface only, per Q2).
- No registry `status`-callback disambiguation (Q4 deferred follow-up).

## Risks / watch-items

- **Modal 2FA UX for provider-specific flows.** `handle_ajax_2fa` exists, but provider
  rendering (WebAuthn/passkey prompts) inside a modal may need the link-out fallback for
  some providers. Keep the new-tab degradation path wired.
- **Password managers vs. the in-editor modal.** A modal password field injected via
  `createElement` may not be recognized by autofill/1Password the way the full-page
  login form is — see the pending todo
  `2026-07-05-password-manager-2fa-interaction.md`. Verify autofill during Task 3.
- **Metrics.** Adding E2E specs changes counts in `docs/current-metrics.md` — update it
  in the same commit per the repo rule.
