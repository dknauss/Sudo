# Design Phase Scaffold — Block-Editor (Gutenberg) Reauthentication UX

**Status:** Design-phase scaffold, **reviewed twice on 2026-07-05**:
(1) design review → "revise before TDD", findings folded in (Part 3.5);
(2) focused adversarial **security review** of the editor AJAX-grant reuse →
**SAFE WITH STATED CONDITIONS**, verdict + must-dos folded in (Part 3.6).
**Scope decided 2026-07-05: Tier 2 — transparent in-editor modal** (Part 4 / Part 5 Q1).
No production code proposed here. The gating security question is resolved (Part 3.6),
and the scope is set; the design is **TDD-ready** pending a plan-phase breakdown of the
Part 5 residual questions (batch depth, concurrency policy, SEV-3 label handling).

**Verification stamp:** Surface inventory and security-boundary claims below were
re-grounded against the live codebase on **2026-07-05** (current `main`, plugin
`4.2.2`). Line references are to `includes/class-gate.php` and
`includes/class-action-registry.php` as of that date. This supersedes the
`2026-06-25` route inventory buried in
[`post-4.1.0-dev-scopes.md`](post-4.1.0-dev-scopes.md) §"Scope 3" — that analysis
is still correct; this consolidates it into a review-ready artifact and re-verifies
it against current code.

**Prior art already shipped (v4.2.0):** the server-side `challenge_url` field on the
cookie-auth REST `sudo_required` error (`Phase 1` of the original plan). See
[`class-gate.php:2628`](../includes/class-gate.php). Everything below is the *UX layer*
that consumes it — not yet started.

---

## Part 1 — Surface Inventory (the "which editor flows trip the gate" question)

### Method

The block/site editor does not call literal REST route strings; it writes through
`@wordpress/core-data` entities (`saveEntityRecord` / `deleteEntityRecord`), whose
`baseURL`s resolve to `/wp/v2/*` routes. So the authoritative question is: **which
of WP Sudo's gated REST rules sit on a route the editor's `apiFetch` can reach?**

The gated REST surface is not a matter of opinion — it is exactly the set of rules
in the Action Registry with a non-null `rest` block. Re-derive it:

```bash
# Every gated REST route + methods, with its rule id
grep -n "'id'\s*=>\|'route'\s*=>\|'methods'\s*=>" includes/class-action-registry.php \
  | grep -B1 -A1 "'route'"
```

### Gated REST surface (authoritative, from current registry)

| Rule id | REST route (regex) | Methods | Editor-reachable? | Realistic in-editor trigger |
|---|---|---|---|---|
| `plugin.activate` | `/wp/v2/plugins/<slug>` | PUT, PATCH | **Yes** | **Block Directory:** activate a block plugin from the inserter |
| `plugin.deactivate` | `/wp/v2/plugins/<slug>` | PUT, PATCH | Yes (theoretical) | No first-class editor UI; reachable via apiFetch |
| `plugin.delete` | `/wp/v2/plugins/<slug>` | DELETE | Yes (theoretical) | No first-class editor UI |
| `plugin.install` | `/wp/v2/plugins` | POST | **Yes** | **Block Directory:** install a block plugin from the inserter |
| `user.delete` | `/wp/v2/users/<id>` | DELETE | No (theoretical) | Editor has no user-management UI |
| `user.promote` | `/wp/v2/users/<id>` | PUT, PATCH | No (theoretical) | — |
| `user.change_password` | `/wp/v2/users/<id\|me>` | PUT, PATCH | No (theoretical) | — |
| `user.create` | `/wp/v2/users` | POST | No (theoretical) | — |
| `auth.app_password` | `/wp/v2/users/<id\|me>/application-passwords` | POST | No | Profile screen, not editor |
| `options.critical` | `/wp/v2/settings` | PUT, PATCH, POST | Low | Critical opts¹ not written from the editor canvas |
| `connectors.update_credentials` | `/wp/v2/settings` | PUT, PATCH, POST | No | Connector API keys not editor-written |

¹ Critical options = `siteurl`, `home`, `admin_email`, `new_admin_email`,
`default_role`, `users_can_register` (filterable via `wp_sudo_critical_options`).
Re-derive: `sed -n '/function critical_option_names/,/^\t\t}/p' includes/class-action-registry.php`.

**Rules with NO REST surface** (gated only on the classic admin/AJAX surface, so
*never* reachable from the editor's REST client): `plugin.upload`, `plugin.update`,
all `theme.*`, `editor.plugin`, `editor.theme`, `core.update`, `tools.export`, all
`network.*`, `options.wp_sudo`, `options.wp_sudo_access`, `user.promote_profile`.
(These have `'rest' => null` in the registry.)

### Deliberately NOT gated (content/design — gating them would be a scope violation)

The editor writes these entities constantly; gating them would break the documented
"don't gate content saves" principle and is explicitly **out of scope**:
`/wp/v2/posts`, `/pages`, `/templates`, `/template-parts`, `/global-styles`,
`/navigation`, `/menus`, `/menu-items`, `/widgets`, `/sidebars`, `/blocks` (reusable),
`/font-families`, `/font-faces`, `/media`, `/comments`.

### Conclusion — the surface is thin, and that is the point

**The only realistically editor-reachable gated flow is Block Directory plugin
install/activate (`/wp/v2/plugins`).** Everything else on the gated REST surface
either has no editor UI (users, app passwords) or is content/design that is
deliberately ungated. This **confirms** the earlier "likely thin" intuition and
**refutes** any framing that this feature needs new gating rules for
templates/global-styles/blocks/menus. **Phase 2 requires zero new gating rules** —
it is a pure UX layer over the one-to-few already-gated routes the editor can hit.

Framing consequence for the reviewer: this is a **UX-polish feature, not a
security-coverage feature.** Its value is "don't destroy unsaved editor state and
don't dead-end on a 403," not "close a gap." That should calibrate how much
machinery it earns.

---

## Part 2 — Load-bearing security boundary (re-verified in current code)

**Client re-dispatch ≠ `Request_Stash` replay.** The editor flow is safe *only*
because the REST path is a **soft block that creates no server stash**. The client
re-fires its own in-memory request after the session is granted. Editor flows must
**never** route through `Request_Stash` (which exists to strip secrets and enforce
one-time, ownership-bound replay of *admin form* posts).

**`challenge_url` is emitted on the cookie-auth branch only.** Verified in
[`class-gate.php:intercept_rest()`](../includes/class-gate.php) (as of 2026-07-05):

- App-password / bearer requests → `sudo_disabled` or `sudo_blocked`, **no
  `challenge_url`** (lines ~1847–1884). Emitting a challenge URL to a headless
  client would be information disclosure and is correctly avoided.
- Cookie-auth browser requests → `block_rest()`, which is the *only* caller that
  attaches `challenge_url` (line ~2628).

Any implementation must preserve this split. A regression test must assert the
headless branches stay `challenge_url`-free.

---

## Part 3 — Design Brief (Pre-Implementation Design Review format)

*This is the brief to hand to the design reviewer agent. Describe factually; do not
instruct the reviewer to approve.*

**What problem is being solved / what failure mode.** A gated action triggered from
the block or site editor (in practice: installing/activating a block plugin from the
inserter) returns a `sudo_required` 403 that the editor SPA surfaces as an opaque
failure, and there is no in-editor path to reauthenticate. A full-page redirect to
the challenge page would destroy unsaved editor state. This is a UX dead-end, not a
security gap — the gate already fires correctly.

**Proposed approach / files.** A **build-free, vanilla-JS** client layer (same
pattern as the existing hand-written `admin/js/*.js` files — e.g.
`admin/js/wp-sudo-shortcut.js` already consumes a localized `challengeUrl` — declaring
script deps on `wp-api-fetch`, `wp-data`, `wp-notices`):
1. An `apiFetch` middleware (`wp.apiFetch.use(...)`) that catches the `sudo_required`
   error, reads its `challenge_url`, and instead of failing shows an in-editor
   **snackbar** (`wp.data.dispatch('core/notices').createNotice(...)`) with a
   "Reauthenticate" action. The middleware must **unwrap `/batch/v1` response
   envelopes** (see Part 3.5 SEV-2) or explicitly document batched gated writes as
   out of scope; must **degrade gracefully when `challenge_url` is absent** (headless
   / app-password sessions get `sudo_blocked` with no URL — show the plain message,
   no action); and must **not echo the rule label** verbatim (see SEV-3 — the label
   can be wrong for REST deactivate).
2. Snackbar action grants the session via **same-document AJAX against the existing
   `wp_ajax_` challenge endpoints** — `handle_ajax_auth()` already supports a
   **session-only grant** (no `stash_key` → `attempt_activation` →
   `{code:'authenticated'}`, `class-challenge.php:448-450`) and `handle_ajax_2fa()`
   already handles **2FA over AJAX** (`:506`, registered `:96`). This is why 2FA does
   **not** force a full-page redirect (correcting the earlier deferral). This requires
   localizing the `wp_sudo_challenge` grant nonce (`NONCE_ACTION`) into the editor
   context (see SEV-4). **Not** an iframe — but for the correct reason (SEV-5): the
   sudo cookie is `HttpOnly`+`SameSite=Strict` and JS never reads it, so an iframe
   buys nothing and a cross-site frame is blocked by `Strict`; same-origin,
   same-document AJAX satisfies `Strict` and the cookie rides along automatically.
3. On successful session grant, the client **re-dispatches its own original request**.
   Consume the **server-emitted `challenge_url` verbatim** — do NOT rebuild it in JS
   (multisite/network-admin URL construction is server-side and referrer-fragile;
   SEV-missing-5). No new PHP gating rules. A small refactor may localize the grant
   nonce/endpoints for the editor; challenge *rendering* need not be extracted since
   the AJAX grant path is reused, not the full-page form.

**What it explicitly blocks / must NOT block.** It changes **no** gating decision:
the same actions gate, the same actions pass. It must NOT gate any content/design
save; must NOT route REST through `Request_Stash`; must NOT emit `challenge_url` to
headless clients; must NOT depend on `is_network_admin()` context under REST (consume
the server-emitted `challenge_url`); must NOT introduce an `@wordpress/scripts` build
step for the MVP; must NOT throw when `challenge_url` is absent; must NOT fire
duplicate snackbars for concurrent in-flight requests or when a grant just landed
inside the grace window.

---

## Part 3.5 — Design Review Findings (2026-07-05, folded in above)

First-pass review verdict: **needs revision before TDD**. The core model
(soft-block + client re-dispatch, no stash) is sound and the security boundary holds;
the issues are scope/framing and execution-context gaps. SEV-1 and SEV-3 were
independently re-verified against code before folding in.

| # | Finding | Code evidence | Resolution folded into brief |
|---|---|---|---|
| SEV-1 | Transport was treated as an open question and 2FA deferred — but the AJAX **session-only grant** and **AJAX 2FA** already exist and work outside the full-page challenge | `class-challenge.php:448-450` (`{code:'authenticated'}` with no stash_key), `:506` + `:96` (2FA over AJAX registered) | Part 3 recast to reuse these; 2FA no longer forces full-page; Phase 4 deferral rationale dropped. **Verified.** |
| SEV-2 | A naive apiFetch middleware won't see `sudo_required` when it arrives inside a `/batch/v1` envelope (site editor uses batch saves) → silent no-op | `intercept_rest` on `rest_request_before_callbacks` fires per inner request | Part 3 step 1: middleware must unwrap batch envelopes, or document batched gated writes out of scope. |
| SEV-3 | `plugin.activate`/`plugin.deactivate` share an identical route+method with no discriminating callback → a REST deactivate is gated but labeled "Activate plugin"; the new snackbar would promote this latent mislabel to a user-visible string | `class-action-registry.php:101-133` (identical `#^/wp/v2/plugins/[^/]+(?:/[^/]+)?$#` PUT/PATCH); `matches_rest` returns first match | Part 3 step 1: don't echo the rule label. (Optional follow-up: add a body `status` callback to disambiguate.) **Verified.** |
| SEV-4 | Same-document AJAX grant needs the `wp_sudo_challenge` nonce, which is localized only on the challenge admin page | `handle_ajax_auth`/`_2fa` require `check_ajax_referer(NONCE_ACTION)` (`:414,:507`); nonce localized only when `page===wp-sudo-challenge` (`:177-181`) | Part 3 step 2: editor enqueue must mint+localize the grant nonce; noted as a security-relevant surface widening. |
| SEV-5 | The iframe rejection was justified by "SameSite/partitioned cookie readback," but the sudo cookie is `HttpOnly` — JS never reads it, so there is no readback | `class-sudo-session.php:876,891,905` (`HttpOnly`+`SameSite=Strict`) | Part 3 step 2: keep the no-iframe decision, corrected rationale, so no test asserts a non-existent readback mechanism. |

**Missing scenarios added to the design:** batched writes (SEV-2); grace-window
debounce (no snackbar when a grant just landed — `is_within_grace`,
`class-sudo-session.php:252`); concurrent N in-flight gated requests (re-dispatch which?
debounce snackbars); nonce-staleness claim was overstated (apiFetch does not auto-refresh
a stale REST nonce mid-flight — don't assert it as guaranteed); multisite → consume the
server-emitted `challenge_url` verbatim; app-password editor sessions degrade gracefully
(no `challenge_url` → plain message).

## Part 3.6 — Focused Security Review (2026-07-05): editor AJAX-grant reuse

A second, narrow adversarial review answered the one question worth double-checking:
does reusing `handle_ajax_auth()` / `handle_ajax_2fa()` as the editor's grant
transport open any CSRF, nonce-reuse, session-fixation, replay, token-binding,
rate-limit, or info-disclosure surface the full-page flow does **not** already have?

**Verdict: SAFE WITH STATED CONDITIONS.** No new attack was found. The grant is
transport-agnostic — `handle_ajax_auth`/`_2fa` authenticate the current cookie-auth
user by password (+2FA), mint a login-session-bound token, and the session is then
"on" for all subsequent gated requests, identical to the full-page flow. Nothing in
grant, binding, replay, or rate-limit behavior is keyed on the calling page.

Key refutations (all code-grounded):
- **CSRF / confused-deputy:** cross-site grant is blocked by `SameSite=Strict` on both
  cookies (`class-sudo-session.php:891,905,522,645`) + `check_ajax_referer` + the
  password requirement. The grant does **not** bind to the re-dispatched request — but
  neither does the full-page flow; the session is action-agnostic in both. Re-dispatch
  fires the user's own `wp_rest`-nonce-bearing request, so it stays first-party.
- **Nonce exposure (the one real delta):** localizing `NONCE_ACTION` into the editor is
  a new place the grant nonce lives, but it is a **CSRF token, not an authz token** —
  possessing it does not authenticate (password still required, `class-sudo-session.php:442`;
  2FA still browser-bound via the HttpOnly `wp_sudo_challenge` cookie). Any same-origin
  script can already mint WP nonces, so this does not lower the bar. It does **not**
  expose the sudo token (HttpOnly, JS-unreadable).
- **Fixation / binding:** `set_token()` binds to the login session unconditionally
  (`:851-866`); no branch is conditional on the calling page or `DOING_AJAX`. In
  admin-ajax `headers_sent()` is false, so the Set-Cookie fires normally.
- **Replay:** session-only grant requires an empty `stash_key` and REST never stashes;
  the editor path cannot enter the stash/replay branch.
- **Rate-limit:** shared `(user_id)` / `(ip,user_id)` accounting — no parallel bucket,
  no bypass or amplification.
- **Info disclosure:** grant responses carry only generic messages/codes; no extra leak.

**Conditions (must-dos / defense-in-depth), folded into the plan below:**
- **C1 — grant-nonce blast radius.** Keeping the single `wp_sudo_challenge` nonce action
  is acceptable (it is a CSRF token); a distinct editor-grant nonce action is optional
  defense-in-depth. Decide, don't drift.
- **C2 — tight localization.** Enqueue/localize the grant nonce **only on actual
  block/site-editor screens** (mirror `class-challenge.php:179`), and skip it when
  `Sudo_Session::is_active()` (as the existing shortcut enqueue does,
  `class-plugin.php:200-202`). Do not spray the nonce onto non-editor pages.
- **C3 — first-party re-dispatch.** The re-fired request must carry the user's own
  `wp_rest` nonce so `is_rest_cookie_auth()` (`class-gate.php:451-466`) classifies it as
  cookie-auth and re-evaluates the now-active session at `:1829`. Never rebuild it
  server-side.
- **C4 — headless-branch regression test.** Assert `challenge_url` stays absent on the
  app-password/bearer branch (`class-gate.php:1869-1883`); the editor feature must not
  cause it to leak to headless sessions.

## Part 4 — Phased plan & effort (scope decided 2026-07-05: **Tier 2 — transparent modal**)

**Structural correction that sets the phasing:** a `core/notices` **snackbar cannot
host a password field** — it only shows a message with action buttons. The AJAX grant
requires the user's password (+2FA). Therefore the transparent, stay-in-editor path
**requires a modal** (`wp.components.Modal`) to collect the password; the snackbar can
only *notify* and *trigger* the modal (or, in fallback, link out). The earlier
"snackbar MVP now, modal later" sequencing was incoherent — the modal is **in the MVP**.

**Decision:** build the transparent in-editor flow (never leave the editor). The
security review cleared the AJAX-grant reuse (Part 3.6) and the modal is build-free
(`wp.element.createElement( wp.components.Modal, … )`), so Tier 2 is cheaper than the
original "largest lift" framing — but it does carry the C1–C4 conditions, batch
handling, and concurrency edge cases.

| Phase | Scope | Build step? | Status |
|---|---|---|---|
| 1 | Server `challenge_url` on cookie-auth REST error + tests | No | ✅ **Shipped v4.2.0** |
| 2 | `apiFetch` middleware (batch-aware) → snackbar trigger → **modal** (password + AJAX 2FA) via existing grant endpoints → **auto re-dispatch**; conditions C1–C4; Playwright E2E | **No (build-free)** | Not started |
| 3 | Polish: concurrency/debounce refinement, error-state UX, accessibility pass on the modal | No | Not started |

**Graceful-degradation fallback (kept, not the primary path):** the *link-out snackbar*
(open the challenge in a **new tab**, editor state preserved, manual retry) is the
degradation path when the modal cannot serve a case — e.g. a session/2FA edge the
in-editor modal cannot complete, or `challenge_url` absent on a headless session (show
the plain message, no action; **C4**). The modal is the default; the link-out is the
safety net, not a separate phase.

**Build-step decision (settled):** declined for the MVP. The plugin ships zero
production npm deps and no build step today; the snackbar/middleware reach core
globals via a hand-written file, the same pattern as existing `admin/js/`. Adding
`@wordpress/scripts` would introduce an npm prod-dep tree, SBOM/`npm audit` surface,
a `build/` artifact, and version-pinning maintenance — cost a snackbar does not earn.

---

## Part 5 — Decisions (post-review) and remaining questions

**Resolved by the first review pass:**
- **Transport (was Q1):** reuse the existing AJAX grant (`handle_ajax_auth` session-only
  + `handle_ajax_2fa`); 2FA does not force full-page. No mixed strategy needed.
- **`class-challenge.php` extraction (was Q2):** not required — reuse the AJAX grant
  endpoints, not the full-page form; no shared-verifier refactor for the MVP.
- **Gray-area opt-in (was Q4):** `global-styles` / `font-families/faces` stay ungated;
  if ever gated, do it as a default-OFF filter opt-in (mirror `wp_sudo_guard_escalation`),
  not a Settings checkbox. Out of Phase 2 scope. Confirmed.

**Remaining questions for the second review pass / discussion phase:**
1. **Scope discipline — beat the floor.** ✅ **RESOLVED 2026-07-05: Tier 2 (transparent
   modal).** Build the stay-in-editor flow (modal collects password/2FA via the
   security-cleared AJAX grant, then auto re-dispatch). The link-out snackbar is kept
   only as the degradation fallback. Rationale: the flow is infrequent but high-intent
   (user is actively blocked mid-compose), the AJAX endpoints already exist, and the
   modal is build-free. Accepts the C1–C4 conditions + batch + concurrency cost.
2. **Batch handling depth.** Unwrap `/batch/v1` envelopes now, or document out-of-scope?
   The gated `plugins` controller is not `allow_batch` in core today, so out-of-scope is
   defensible for the MVP — but a third-party gated route or a core change could
   silently regress. Decide and document.
3. **Concurrency policy.** On grant, re-dispatch only the user-actioned request, or all
   in-flight `sudo_required` requests? Snackbar debounce/idempotency for N requests and
   for the grace-window race.
4. **SEV-3 disambiguation.** Ship the snackbar with the label suppressed (cheap), or
   also add a body-`status` callback to the registry so `plugin.deactivate` labels
   correctly (a small registry change with its own tests)?
5. **Test strategy.** Phase 2 is the first Playwright E2E of a challenge-transport flow.
   Specs: block-plugin install/activate happy path; re-dispatch after grant; AJAX 2FA
   grant path; **batched gated write** (per the Q2 decision); headless-branch-stays-
   `challenge_url`-free regression (**security C4**); grace-window no-duplicate-snackbar;
   grant-nonce localized only on editor screens and skipped when session active
   (**security C2**); re-dispatch carries a first-party `wp_rest` nonce (**security C3**).

---

## Part 6 — Re-derivation commands (anti-confabulation)

```bash
# Gated REST surface (rule id + route + methods)
grep -n "'id'\s*=>\|'route'\s*=>\|'methods'\s*=>" includes/class-action-registry.php | grep -B1 -A1 "'route'"

# Confirm challenge_url is emitted only by block_rest() (cookie-auth branch)
grep -n "challenge_url\|block_rest\|sudo_disabled\|sudo_blocked" includes/class-gate.php

# Critical option names
sed -n '/function critical_option_names/,/^\t\t}/p' includes/class-action-registry.php

# Confirm no build step / zero prod npm deps
test -f package.json && jq '.dependencies // {}' package.json   # expect {} or dev-only
```
