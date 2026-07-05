# Design Phase Scaffold — Block-Editor (Gutenberg) Reauthentication UX

**Status:** Design-phase scaffold, **design-reviewed 2026-07-05 → verdict: revise
before TDD**. The review findings are folded in below (Part 3 recast; Part 4 phasing
corrected; Part 5 split into resolved decisions + remaining questions; new Part 3.5
records the findings with code evidence). No production code proposed here. This
document is the input to the mandatory Pre-Implementation Design Review (per
`CLAUDE.md`) — the first review pass is done; a second pass should confirm the
revisions before TDD.

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

## Part 4 — Phased plan & effort (corrected)

| Phase | Scope | Build step? | Status |
|---|---|---|---|
| 1 | Server `challenge_url` on cookie-auth REST error + tests | No | ✅ **Shipped v4.2.0** |
| 2 | `apiFetch` middleware (batch-aware) + snackbar + **reuse existing AJAX grant** + re-dispatch; Playwright E2E | **No (build-free)** | Not started |
| 3 | Snackbar → modal challenge (try `wp.components.Modal` via `createElement`) | Reconsider build only if unmaintainable | Not started |
| ~~4~~ | ~~2FA-in-editor (deferred)~~ — **folded into Phase 2**: AJAX 2FA already exists (`handle_ajax_2fa`) | — | Rationale withdrawn (SEV-1) |
| 4 | Broaden E2E matrix once challenge transport is no longer page-based | — | Not started |

**Even-smaller floor (MVP fallback, per scope discipline):** if the middleware +
grant-nonce plumbing proves disproportionate for essentially one flow (Block Directory
install/activate), the honest minimum is: improve the `sudo_required` snackbar to carry
an actionable "Reauthenticate" link to the full-page challenge and stop — no AJAX grant,
no re-dispatch. State this as the floor the design must beat to justify the extra
machinery.

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
1. **Scope discipline — beat the floor.** Given essentially one real flow (Block
   Directory install/activate), does the middleware + grant-nonce + re-dispatch design
   earn its keep over the Part 4 "even-smaller floor" (actionable link only)? This is
   the single decision most likely to move the ETA.
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
   `challenge_url`-free regression; grace-window no-duplicate-snackbar.

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
