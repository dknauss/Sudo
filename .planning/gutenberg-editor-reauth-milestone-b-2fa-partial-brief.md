# Milestone B Design Brief — Server-Rendered 2FA Partial for the In-Editor Modal

**Status:** Design brief, **design-reviewed 2026-07-07** (critique incorporated below —
see the ⚠ notes and §3a). Not code. Scopes the one genuinely uncertain piece of
[Milestone B](gutenberg-editor-reauth-milestone-plan.md): making TOTP / email-OTP /
backup-code reauthentication work **inside** the in-editor modal. Grounded against the
live `includes/class-challenge.php` + `class-sudo-session.php` paths and the upstream
`WordPress/two-factor` provider source (verified 2026-07-07). WebAuthn/passkey is out of
modal scope by nature (§3).

---

## 1. Problem and failure mode

Milestone A's modal grants a sudo session for **password-only** accounts and **links out
to the full-page challenge for any 2FA account** (`2fa_pending` → snackbar link-out).
Milestone B closes that gap for the OTP-family providers so a 2FA user reauthenticates in
place too.

**Why it isn't trivial — the render/validate coupling.** The server never validates a
generic code. `Challenge::handle_ajax_2fa()` (`class-challenge.php:626-658`) delegates to
`$provider->validate_authentication( $user )` (`:646`) plus the
`wp_sudo_validate_two_factor` filter (`:658`), and **both read provider-specific `$_POST`
field names** (e.g. Two Factor TOTP's `authcode`). Those names are emitted only by the
provider's **own render step** — `$provider->authentication_page( $user )` inside
`render_page()` (`:382-398`), which the modal never runs. So:

- A generic modal input (`one-time-code`) is **not read** by the validator — even for
  TOTP. Rendering a plain field and POSTing to `handle_ajax_2fa` silently fails.
- The correct markup is provider- and user-specific and must come from the server.

**Failure mode if built naively:** the modal shows a field that looks right, the user
enters a valid code, and the grant fails on every attempt — an opaque dead-end worse than
the honest link-out we ship in A.

## 2. Proposed approach

**Extract the existing render block into a shared renderer, expose it behind a gated
endpoint, and have the modal inject its output verbatim — then POST to the unchanged
`handle_ajax_2fa`.** Reuse, not a fork, so the modal and full page can never drift.

**Server (`includes/class-challenge.php`):**
- Extract `render_page()` lines ~382-398 (the `Two_Factor_Core::get_primary_provider_for_user`
  → `$provider->authentication_page( $user )` + `do_action( 'wp_sudo_render_two_factor_fields', $user )`
  block) into `render_two_factor_fields( \WP_User $user ): void`. `render_page()` calls it
  unchanged — byte-identical full-page output (pure echo-context move; regression-guarded).
- New AJAX handler `handle_ajax_2fa_partial()` that:
  1. `check_ajax_referer( self::NONCE_ACTION )` + logged-in.
  2. **Gates on `Sudo_Session::get_2fa_pending( $user_id )`** — never render 2FA fields for
     a user who has not passed the password step. The pending state is set **only** by
     `attempt_activation()` after a correct password (`class-sudo-session.php:505-528`, via
     the browser-bound `CHALLENGE_COOKIE`) and expires (`get_2fa_pending` returns null once
     `expires_at < time()`). No/expired pending → 403. This is uncircumventable: there is no
     path to a valid pending state without the password step.
  3. Computes provider **modal-capability** (§3/§3a). Not modal-capable, or **no
     classifiable `Two_Factor_Core` primary provider at all** → `{ code: 'link_out' }` (no
     markup). Capable → capture `render_two_factor_fields()` via `ob_start()` and return the
     HTML string, injected into a **contained node** (not raw page `innerHTML`).
- Register `wp_ajax_wp_sudo_2fa_partial` in `class-plugin.php` (editor enqueue already
  screen-scoped from Milestone A; the partial is fetched, not sprayed into the page).

**⚠ The partial is NOT a pure render surface for the email provider (§3a) — this is the
one place the endpoint is state-changing and needs its own guard.**

**Transport choice — admin-ajax, not REST.** The whole challenge flow (`handle_ajax_auth`,
`handle_ajax_2fa`) is admin-ajax with the `wp_sudo_challenge` nonce; the editor being
REST-driven concerns the gated *action* (`/wp/v2/plugins`), not the reauth transport
(Milestone A already grants via admin-ajax). The partial stays consistent, reuses the exact
nonce/lockout/pending machinery, and needs no new `permission_callback` contract.

**Client (`admin/js/wp-sudo-editor-reauth.js`), on `2fa_pending`:**
1. Fetch the partial (same-origin, credentials included — carries the login +
   `CHALLENGE_COOKIE` that bind `2fa_pending`), **single-flight** (one in-flight fetch;
   re-render/reopen must not re-fetch — see §3a).
2. `{ code: 'link_out' }` → Milestone A snackbar link-out (WebAuthn/push/unknown/hook-only).
3. HTML → inject into a contained node, focus the code field, and **serialize *all* inputs
   within the injected partial generically** (never hardcode `authcode` etc. — that would
   re-introduce the coupling this removes) and POST them to `handle_ajax_2fa`.
4. Handle the full response set: `{2fa_resent}` (email resend), `locked_out`, `throttled`,
   `resend_throttled`, `invalid`, **and the pending-expired 403** (`get_2fa_pending` null
   mid-flow → `handle_ajax_2fa` returns 403 "session has expired", `:565-570`) → **link
   out / restart, not "invalid code."**
5. On `{authenticated}` → close + transparently re-dispatch the original request (the
   Milestone A path, unchanged).

## 3. What the capability check blocks — and must NOT block

**Blocks (→ link-out, never in-modal):**
- **WebAuthn / U2F / passkey.** `authentication_page()` emits `<script>` + a hidden field
  populated by `navigator.credentials.get()`. Markup injected via `innerHTML` **does not
  execute scripts** (inert per spec), and the provider's enqueued assets aren't guaranteed
  in the editor context — an injected ceremony is dead on arrival. Detect and link out.
- **Push / cloud-validated (miniOrange), no-stable-API (Shield)** — unsuitable for
  synchronous in-modal validation. Link out.
- **No classifiable `Two_Factor_Core` primary provider** — including the **plugin's own
  hook-based 2FA extension path** (`wp_sudo_requires_two_factor` +
  `wp_sudo_render_two_factor_fields` + `wp_sudo_validate_two_factor`, no provider object).
  `needs_two_factor()` can be true via that filter with `get_primary_provider_for_user()`
  returning nothing to classify. **Default: link_out** (do not blindly echo hook output —
  it may be script/screen-dependent, §3a). Extensible via the §3a filter if an integrator
  opts a plain-field hook into the modal.

**Must NOT block / must keep working:**
- **TOTP** (plain `authcode` input); **email-OTP** (plain code input, but see §3a);
  **backup codes _only when they are the user's primary provider_** — `render_page()` uses
  `get_primary_provider_for_user()` / `$provider->authentication_page()` directly (`:385-387`),
  bypassing `Two_Factor_Core::login_html()`, so **neither the full page nor the modal has a
  "use a different method" switcher today.** A TOTP-primary user who needs a backup code
  (lost authenticator) must **link out**; the switcher is the deferred link-out affordance
  (§5). Do not claim backup-code-as-fallback works in-modal.
- **The full-page challenge 2FA path** — untouched (shared renderer; regression test on
  byte-identical `render_page()` output).
- **Milestone A password-only + non-2FA flows**, and the **headless / app-password branch**
  (no 2FA affordance, no `challenge_url`, C4) — unaffected.
- **The `2fa_pending` handshake and all lockout / throttle / IP-lockout guards** — the
  partial adds a render surface; **code validation stays entirely in `handle_ajax_2fa`,
  unchanged.** (Rate-limiting: unchanged for *validation*; the email *render-send* needs its
  own guard — §3a.)

### 3a. ⚠ Email-OTP render sends mail — the partial is state-changing here (HIGH)

Verified against `WordPress/two-factor` `providers/class-two-factor-email.php`:
`authentication_page()` calls `generate_and_email_token()` whenever
`! user_has_token() || user_token_has_expired()`. **So fetching the partial for an
email-OTP user sends an OTP email.** The `wp_sudo_resend_*` counter cited elsewhere
(`:630-644`) guards a *different* path — `pre_process_authentication()` inside
`handle_ajax_2fa` — and does **not** fire on partial render. Therefore the flow has **two
independent mail-send triggers with two different guards**, and a partial re-fetch (React
re-render, modal reopen, retry after token TTL) re-sends mail with **no server-side limit**.
Client debounce is not a rate limit. Required in the design:
- Make partial fetch **strictly single-flight server-side** for the email provider, and
  **share/extend the `wp_sudo_resend_*` counter across both the partial render and the
  validate path** (one throttle covering every send), **or** explicitly document that
  render-time sends are bounded only by the provider's token TTL and accept that residual.
- Prefer the shared-throttle option. Do not repeat the "render surface only / rate-limiting
  unchanged" framing — it is false for email.

## 4. Security notes (carry the phase-2 C1–C4 + Part 7)
- Partial endpoint is an **information + (for email) state-changing** surface: gate on login
  + nonce + `2fa_pending`; return only the **current user's primary provider** markup;
  generic errors (no provider enumeration / capability-name leakage — benchmark §5.4).
- **C1** unchanged: `wp_sudo_challenge` stays a CSRF nonce, not authz.
- Validation authority stays server-side in `handle_ajax_2fa` (never trust client-parsed
  codes; never decrypt secrets — always the provider's `validate_authentication`).
- Injected markup is server-rendered, self, admin, login+pending-gated (self-XSS at worst);
  still inject into a contained node, not page-level `innerHTML`.
- New endpoint + the email render-send behavior = mandatory security-scoped review of the
  real diff before merge.

## 5. Out of scope
- WebAuthn/passkey in-modal (inherent link-out).
- Any change to `handle_ajax_2fa` validation logic or the `2fa_pending` handshake.
- A **"use a different method" switcher** in the modal — render the **primary** provider
  only; method-switch (incl. backup-code fallback for a non-backup primary) is the deferred
  link-out affordance. Note the full-page challenge shares this primary-only limit today.
- New gating rules; content-save gating; a build step.

## 6. Test strategy (feeds the TDD tasks)
- **Unit/integration:** partial 403s without / with expired `2fa_pending`; returns
  `link_out` for WebAuthn-primary, **hook-only (no provider)**, and unknown-provider users,
  and markup for TOTP-primary; `render_page()` full-page output byte-unchanged
  (shared-renderer regression); `handle_ajax_2fa` validation path untouched;
  **`wp_sudo_render_two_factor_fields` behaves in the new admin-ajax fire context** (a
  script/screen-dependent callback must not break the endpoint — assert link-out or a
  documented plain-field requirement); **email-provider partial fetch is single-flight and
  shares the resend throttle** (no unbounded mail-send on re-fetch).
- **E2E (single-site AND `WP_MULTISITE=1`):** TOTP account → editor gated action → modal →
  fetch partial → enter code → grant → transparent re-dispatch; WebAuthn account → modal →
  `link_out` → full-page challenge (no dead form); email-OTP resend surfaces `2fa_resent`
  and respects the shared cap; **pending-expiry mid-2FA → link-out/restart** (not "invalid").
  **Multisite: assert the partial fetch resolves the same per-site `2fa_pending` transient
  the password step wrote** (`set_transient`/`get_transient` are per-site; the flow is sound
  because password-set / partial-read / validate-read all hit the same site's
  `admin-ajax.php`). There is **no network-admin block editor**, so that context is moot.
- Password-manager/autofill check on the OTP field (`autocomplete="one-time-code"`,
  `inputmode="numeric"`), per Milestone A's matrix.

## 7. Open question for implementation review (Q-B1)
Classify the primary provider's modal-capability without a brittle class-name allowlist.
Recommended: a **default-deny** filter `wp_sudo_2fa_modal_capable( bool $capable, $provider, \WP_User $user )`
returning **false (link-out) for anything not explicitly allowlisted** — built-in Two
Factor OTP classes (TOTP, email, backup-codes-as-primary) allowlisted; **no provider ⇒
false**; script-ceremony providers ⇒ false. Safe default, explicit allowlist, extensible.
Confirm the default-deny stance and whether the public filter earns its API surface, or
whether a private curated map (no public hook) is preferable for the first version.

## 9. Implementation-review resolutions (2026-07-15, verified against live source)

A pre-implementation design review of the concrete plan resolved the open items and
caught two hazards. Decisions now locked:

- **Q-B1 → private default-deny allowlist, NO public filter in v1.** Modal-capable iff
  the primary provider `instanceof \Two_Factor_Totp | \Two_Factor_Email |
  \Two_Factor_Backup_Codes` (three full `instanceof` clauses). Null/no-provider
  (hook-only path), WebAuthn/U2F, `Two_Factor_Dummy`, and any third-party provider →
  `link_out`. Simplicity First: a public `wp_sudo_2fa_modal_capable` filter does not
  earn its permanent API surface yet; it stays an additive future option.
- **Email throttle → gate the SEND, not the field (fixes an over-count lockout).**
  `Two_Factor_Email::authentication_page()` sends only when
  `! user_has_token($id) || user_token_has_expired($id)` (both **public**), and the token
  TTL is **15 min** vs the `wp_sudo_resend_*` window of 5 min — so re-fetches inside the
  window never re-send (no unbounded hole). The partial handler pre-computes
  `$will_send`; increments the shared `wp_sudo_resend_<id>` counter **only on a real
  send**, and only hard-blocks (`resend_throttled` 429, no render) when
  `$will_send && count >= 3`. A valid-token reopen renders the field WITHOUT sending or
  counting, so a user who already holds a code is never locked out of entering it.
- **Injected partial must render OUTSIDE the modal `<form>` + neutralize native submits.**
  The provider markup carries native `<input type="submit">` (email: "Verify" + "Resend
  Code"). Inside Milestone A's `<form onSubmit>` a click/Enter could trigger a full-page
  navigation that destroys editor state. Inject into a non-`<form>` contained node, drive
  submission only via the React Confirm button, and disable/neutralize the injected
  submits defensively. (`dangerouslySetInnerHTML` scripts stay inert — no XSS delta.)
- **`twoFactorModalCapable` localized at page load** (same allowlist) replaces the
  Milestone A `hasTwoFactor`-only skip with `hasTwoFactor && !twoFactorModalCapable`, so
  non-capable 2FA users still link out pre-password (no double-prompt regression) while
  capable users open the modal. Page-load classification is a benign UX hint only — the
  server stays authoritative via the `2fa_pending` gate + the partial's own classification
  (which returns `link_out` on any mismatch). **The client's `2fa_pending` handler always
  fetches the partial regardless of the stale flag**, so a user who enrolls 2FA after page
  load still resolves correctly.
- **In-modal email resend is NOT wired in v1.** Generic serialization excludes the resend
  submit, so `handle_ajax_2fa`'s resend path never fires from the modal; the first partial
  fetch sends one code, and a user who needs a resend links out. The `2fa_resent` client
  branch is kept only as a harmless defensive no-op, not advertised.
- **TOTP login field is `authcode`** (verified `class-two-factor-totp.php:776-800` +
  `validate_authentication` `:521`) — NOT the setup page's `two-factor-totp-authcode`.
  Tests assert `authcode`; the client never hardcodes it (generic serialization).

## 8. References
- Milestone plan: [`gutenberg-editor-reauth-milestone-plan.md`](gutenberg-editor-reauth-milestone-plan.md)
- Phase-2 technical/C1–C4/Part 7: [`gutenberg-editor-reauth-phase2-plan.md`](gutenberg-editor-reauth-phase2-plan.md)
- Live code: `class-challenge.php` `render_page()` `:264-421`, `handle_ajax_2fa()` `:545-700`;
  `class-sudo-session.php` `get_2fa_pending()` `:587-649`, pending-cookie set `:505-534`.
- Upstream: `WordPress/two-factor` `providers/class-two-factor-email.php`
  (`authentication_page()` → `generate_and_email_token()` — render-time send).
