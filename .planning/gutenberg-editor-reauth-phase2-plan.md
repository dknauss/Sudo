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
CSRF-nonce exposure, capability-gated actions), so it warrants a **mandatory deep
review**, not a light touch — high-risk diffs (auth, capabilities, sessions, new
endpoints) always do. These gates are non-negotiable; do not merge past their findings.

**Deterministic gates that WILL fire automatically (do not bypass):**
- **Pre-commit reviewer-agent approval** on every code commit. The text-only skip is
  `\.(md|txt|rst)$` — the editor `.js` and any `.php` here do **not** qualify, so each
  code commit needs a fresh `reviewer-approved` flag written by the reviewer agent
  (not the main agent). Do **not** use `USER_COMMIT=1` on AI-generated code.
- The pre-commit hook runs **`composer test:unit`**, **`composer lint`** (PHPCS), and
  **`composer analyse:phpstan`** (`.reviewer-config.sh`) — it does **not** run Psalm or
  the full `composer analyse`/integration suite locally. Run `composer analyse` (PHPStan
  + Psalm) and the integration suite yourself before pushing; CI is the backstop.
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
   - **C2** — grant nonce localized **only** on block/site-editor screens (never sprayed onto non-editor pages), but **loaded even when a session is active** so the recovery path survives session expiry in a long-open editor (revised per Codex PR #157 review).
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
- **C2 (revised)** localize the grant nonce **only** on block/site-editor screens — but **do NOT** skip when `Sudo_Session::is_active()`; the editor is a long-lived SPA and the short sudo session expires while it stays open, so the recovery handler must already be loaded. Acceptable because the nonce is a CSRF token, not authz (see brief Part 3.6). (Task 2)
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

### Task 2 — Editor enqueue + grant-nonce localization (C1, C2 revised)
- **RED:** PHP unit: the editor handler + grant nonce are enqueued on block/site-editor
  screens **regardless of whether a sudo session is active at page load**; the nonce is
  **not** emitted on non-editor screens; `NONCE_ACTION` is reused (C1 — no new action).
- **GREEN:** hook `enqueue_block_editor_assets` (or equivalent), mirroring the tight
  *screen* gating of `class-challenge.php:179` — but **NOT** the active-session skip at
  `class-plugin.php:200-202`. That skip is a proactive shortcut for a single page load;
  the editor is a long-lived SPA where the short session expires while it stays open, so
  a later gated action would return `sudo_required` with no handler/modal/nonce loaded,
  reopening the opaque 403 this feature fixes (Codex PR #157 review). The nonce is a CSRF
  token (brief Part 3.6), so always-loading it in the editor adds negligible risk.

### Task 3 — In-editor modal: password + 2FA (with a per-factor 2FA rendering split)
- **RED:** test that the modal calls `handle_ajax_auth` with the password and **no**
  `stash_key` (session-only grant), handles `{code:'authenticated'}`,
  `{code:'2fa_pending'}`, `locked_out`, and `invalid_password`; closing cancels cleanly.
- **GREEN:** `wp.element.createElement( wp.components.Modal, … )` — build-free. Snackbar
  (`core/notices`) is the trigger surface that opens the modal; it degrades to a plain
  message when `challenge_url` is absent (headless).
- **⚠ 2FA rendering caveat (Codex PR #157 review — corrects an overstated SEV-1).**
  `handle_ajax_2fa()` only *validates*; the provider *fields* are emitted server-side by
  `Challenge::render_page()` via the `wp_sudo_render_two_factor_fields` render hook, which
  the modal never runs. So AJAX 2FA is **not** universally reusable:
  - **TOTP-style factors** (a plain code input) — the modal renders its own field with
    `autocomplete="one-time-code"` + `inputmode="numeric"` (2FA bridge-guide rules, Part 7)
    and posts to `handle_ajax_2fa`. Works in-modal.
  - **Provider-ceremony factors** (WebAuthn/passkey, or any custom
    `wp_sudo_render_two_factor_fields` output) — the modal **cannot** render these. Either
    add a small server-rendered 2FA-partial endpoint the modal fetches and injects, **or**
    route these users to the link-out fallback (full-page challenge). Decide per-factor at
    task-design time; **default to link-out** if the partial endpoint is not built. Do not
    ship the modal claiming universal 2FA support.

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

### Task 6 — E2E + integration on BOTH single-site and multisite (required)
**Both topologies must pass — this is a hard requirement, not a nicety.** The
challenge-URL construction is multisite-specific (`build_session_challenge_url()`
infers network-admin routing from the referrer, `class-gate.php:2646-2664`) and the
capability model differs (single-site admin vs. network super-admin), so single-site
green does **not** imply multisite green.

- **Single-site:** Block Directory install/activate happy path (gated → modal → grant →
  auto re-dispatch → plugin active, editor state intact); AJAX 2FA grant path;
  grace-window no-duplicate-snackbar; grant nonce present only on editor screens and
  absent when session active; batched `sudo_required` surfaces the snackbar (no silent
  no-op); headless branch stays `challenge_url`-free (C4).
- **Multisite (network admin + a subsite editor):** the same grant→re-dispatch flow
  succeeds; the `challenge_url` routes to the correct (network vs. site) admin context;
  a network-only gated action (e.g. a super-admin-scoped operation) gates and grants
  correctly; per-site session isolation holds (a grant on one site does not silently
  satisfy a gated action on another beyond documented behavior). Run the integration
  suite with the multisite bootstrap (`WP_TESTS_MULTISITE`/`WP_MULTISITE`) as well as
  single-site.
- Reuse the harness admin auth pattern per the repo's E2E convention; unit tests that
  branch on `is_multisite()` must cover **both** return values.

### Task 7 — Security-config alignment check (see Part 7)
Before the final security-scoped review, confirm the implementation matches the
config best-practices in Part 7 (nonce/cookie/REST/capability/enqueue/multisite). This
is a checklist pass, not new code; failures here feed back into Tasks 1–6.

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

---

## Part 7 — Security-config alignment (from the security-docs survey)

Grounded against the user's security-doc repos (`wp-security-benchmark`,
`wp-security-hardening-guide`, `wp-security-style-guide`, `wordpress-2fa-ecosystem`;
`Security-White-Paper` had no citable config content). Items below are the ones that
bear on this feature. **Gaps are stated explicitly — do not treat this as full coverage.**

**Reauth concept is endorsed by the corpus.** `wp-security-benchmark` §5.5 "Ensure
reauthentication is required for privileged actions" (Level 2) names **wp-sudo** and lists
exactly the gated-action categories this plugin covers; `wp-security-hardening-guide` §8.2
"Privileged Action Gating" frames it as "sudo mode" and cites VIP step-up auth's bounded
unlock window as precedent for a time-boxed grant. The feature is aligned with documented
best practice, not inventing a pattern.

**Nonce / CSRF (supports C1, C3).** Style-guide glossary "Nonce": WP nonces are **not**
single-use — valid up to ~24h (two 12h ticks) — so a nonce is request-intent/origin proof,
**not** authorization; keep `current_user_can()` gating the destructive action itself
(A01 passage, hardening-guide §4 pairs nonce + capability as *both-required* layers). This
directly backs C1 (nonce is CSRF, not authz) and the design's insistence that the sudo
session never becomes a substitute for capability checks.

**Sessions / cookies (supports C4 + session-binding).** Benchmark §2.5 / hardening §6.3
require `HttpOnly + Secure + SameSite` on session cookies (framed as defense-in-depth for
`session_start()` plugins — cite carefully, it is not stated for WP's own auth cookie).
Style-guide "Session hijacking": **2FA does not protect an already-authenticated session**
→ bind the sudo grant to the login session and keep it short (benchmark §5.3 / hardening
§8.4: 8–24h max, purge on permission change). Reinforces the short-lived, login-bound token.

**REST (supports C4).** Hardening §7.5 / benchmark §5.6: every custom REST endpoint **must**
have a `permission_callback` (if a 2FA-partial endpoint is added in Task 3, this is
mandatory). Application passwords **bypass 2FA by design** and are a separate trust tier
(style-guide "Application password") — this is the doctrinal basis for **C4** (the
headless/app-password branch must not receive `challenge_url` or a reauth affordance).
Keep soft-block/challenge responses **generic** (no user-enumeration / capability-name
leakage) — benchmark §5.4, style-guide "Information disclosure".

**Capabilities / multisite (supports the Task 6 multisite requirement).** Hardening §7.4:
"apply reauthentication requirements at the **network level** for Super Admin actions
(adding sites, network-wide plugins, network settings)" — the single most on-point citation
for why Task 6's multisite lane is mandatory. Super Admin is all-or-nothing/unscopable
(benchmark §13.1); single-site **Administrator** ↔ **Super Admin** is the terminology to use
per context (style-guide "Admin (role)").

**2FA rendering (backs the Task 3 caveat).** `wordpress-2fa-ecosystem/docs/bridge-guide.md`
"Three-Hook Pattern" — Detection / **Rendering** / Validation are *separate*; the host
renders fields, and **WebAuthn/passkey ceremonies require enqueued scripts + a hidden
result field, not a pure PHP/AJAX bridge** ("Constraints and Unsupported Patterns"). This
independently confirms Codex's provider-2FA finding. Field-markup rules for the modal's own
TOTP input: **no `<form>`/submit/`_wpnonce`**, unique `name`, `autocomplete="one-time-code"`,
`inputmode="numeric"`; **debounce email-OTP sends** (a retry can invalidate the code);
render **backup-code** fallback; **never decrypt secrets yourself** — always call the
provider's own validation. Push/cloud-validated methods (miniOrange) and no-stable-API
plugins (Shield) are unsuitable for synchronous in-modal 2FA → link-out.

**Explicit gaps in the corpus (source elsewhere — do NOT cite these repos for them):**
script-enqueue/`wp_localize_script` secret-leakage & screen-scoping guidance (use core
handbook / `wp-secure-code`); `map_meta_cap` cautions (absent everywhere); cookie-flag
guidance for a *plugin-issued* cookie specifically; session-fixation mitigation for a
custom token; REST soft-block info-disclosure specifics.
