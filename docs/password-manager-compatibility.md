# Password-Manager / Autofill Compatibility — Reauth Surfaces

Status of password-manager autofill on WP Sudo's reauthentication surfaces.

> **Verification boundary.** The *markup audit* below is factual and cited to
> source (verifiable from the code). The *per-manager autofill matrix* is
> **partially verified**: as of 2026-07-24 the **iCloud Keychain** row is filled
> from a real observed reproduction (see "Verified results" below); every other
> manager's cells remain `pending` or `not tested`. Per this repo's verification
> rules (`CLAUDE.md` → Verification Requirements; the password-manager 2FA
> interaction research todo under `.planning/todos/pending/`), a specific
> manager's autofill/TOTP behavior must not be recorded as fact without a concrete
> manager version + reproduction. Untested cells require manual verification on a
> machine with each manager installed and are left as `pending` / `not tested`.

## Reauth surfaces

1. **Full-page challenge** — `includes/class-challenge.php` renders
   `#wp-sudo-challenge-password-form` (password step) and
   `#wp-sudo-challenge-2fa-form` (2FA step).
2. **In-editor grant modal** — `admin/js/wp-sudo-editor-reauth.js` renders a
   `wp.components.Modal` password form via `createElement` (the phase-2 plan flags
   this as the most autofill-hostile surface: a modal field injected by JS, not a
   server-rendered login form).

## Markup audit (factual)

Password-autofill managers key off: a real `<form>`, `<input type="password">`,
the `autocomplete="current-password"` token, an associated `<label>`, and a submit
control. TOTP autofill keys off `autocomplete="one-time-code"`.

| Semantic managers expect | Full-page challenge | In-editor modal |
|---|---|---|
| Real `<form>` with a submit button | Yes — `<form method="post">` + `<button type="submit">` (`class-challenge.php:351,360`) | Yes — `<form onSubmit>` + `<Button type="submit">` (`wp-sudo-editor-reauth.js`, `ReauthModal`) |
| `<input type="password">` | Yes (`class-challenge.php:356`) | Yes — `TextControl type="password"` |
| `autocomplete="current-password"` | Yes (`class-challenge.php:357`) | Yes — `autoComplete: 'current-password'` |
| Associated `<label>` | Yes — `<label for="wp-sudo-challenge-password">` (`class-challenge.php:353`) | Yes — `TextControl` label "Password" (WP associates `htmlFor`/`id`) |
| `autocomplete="username"` hint field | **No** | **No** |
| TOTP `autocomplete="one-time-code"` | Provided by the **Two Factor plugin's own** field rendering at our challenge time (`Two_Factor_Core::…->authentication_page()`, `class-challenge.php:387`), not by us | Modal-capable providers (TOTP/email/backup codes) complete the second factor **in place** since v4.7.0 — the provider's server-rendered field is fetched via `handle_ajax_2fa_partial()` and validated through the existing challenge path; link-out remains the fallback for non-modal-capable providers. |

**Takeaway:** both surfaces already expose the standards-minimal set for *password*
autofill (real form + `type=password` + `current-password` + label + submit). The
one common gap versus `wp-login.php` is the absence of an
`autocomplete="username"` hint field, which helps a manager decide *which* stored
credential to offer.

## Candidate cheap fix (recommended, NOT yet applied)

Add a non-editable `autocomplete="username"` hint carrying the current user's login
so managers can associate the right credential:
- Full-page challenge: a hidden/readonly `<input autocomplete="username">`.
- Modal: the same, plus localizing `user_login` into `wpSudoEditorReauth` (the
  config does not currently carry it — see `class-plugin.php` `enqueue_editor_reauth()`).

This is a standards-endorsed (WHATWG) markup hint, but its **actual** benefit per
manager is exactly what the matrix below must verify. It is intentionally **not**
implemented here: it is a change to a security surface whose payoff is unverified,
and this file's own rule is not to encode unverified manager behavior. Decide it
during the manual pass, with a repro showing it changes autofill for a real
manager.

## Per-manager autofill matrix — PARTIAL (iCloud Passwords Chrome extension, offer-only, 2026-07-24)

Fill each cell only with a concrete manager version + reproduction. Do not infer.

| Manager | Full-page: password | Full-page: TOTP | Modal: password |
|---|---|---|---|
| 1Password | not tested (2026-07-24) — see note | pending (see todo — reproduce the "harder with 2FA" report with a version) | not tested (2026-07-24) — see note |
| Bitwarden | pending | pending | pending |
| iCloud Keychain (iCloud Passwords Chrome ext. only)¹ | ✅ **offers**, fill/submit pending² (2026-07-24) | pending — no 2FA-enabled account tested | ✅ **offers**, fill/submit pending² (2026-07-24) |
| Chrome/Edge/Firefox built-in | not isolated (2026-07-24) — see note | pending | not isolated (2026-07-24) — see note |
| Dashlane | pending | pending | pending |

For each filled cell, state whether a miss is a **manager limitation** or a
**fixable-markup** issue on our side, and link the repro.

¹ **Scope:** only the **iCloud Passwords Chrome extension** (Chrome 150) was
exercised. Safari's native iCloud Keychain integration and other Apple browser
contexts use different autofill surfaces and were **not** tested — pending. The
extension version is not exposed to page scripts, so this file's "concrete version"
boundary cannot be met for this manager; treat the result as extension-build-agnostic
and re-verify after major macOS/extension updates.

² **Offer-only:** the autofill *offer* appearing is verified; the full **offer →
accept-fill → submit → sudo grant** chain is **not** — a controlled React
`TextControl` may show an offer yet not update the field's state until the tester
accepts the fill (Touch ID) at the machine.

### Verified results — iCloud Keychain (2026-07-24)

- **Manager / environment:** iCloud Passwords Chrome extension (ships with the OS;
  exact extension version not exposed to page scripts — not captured) on **macOS 26**,
  **Google Chrome 150** (both read from the live browser: `navigator.userAgent` +
  `userAgentData` high-entropy `platformVersion`). **Site:** local WordPress Studio
  at `http://localhost:8881`, **WP Sudo 4.8.0**, WordPress 7.0.2 / PHP 8.5.8, admin
  account with **no** Two Factor enabled. Observed by the human tester on the real
  screen (the native autofill popup renders in a compositor layer that automation
  screenshots capture as blank).
- **Coverage status (partial):** the cells record that iCloud Keychain **offers**
  autofill on both password surfaces (the primary markup-compatibility question).
  NOT yet verified: the full **offer → accept-fill → submit → sudo grant** chain
  end-to-end (requires the human tester's Touch ID acceptance at the machine), and
  the **Full-page TOTP** cell (needs a Two Factor-enabled account with the secret
  stored in iCloud). Both are pending a live tester session at the test machine.
- **Full-page challenge — password → OFFERS.** On focusing
  `#wp-sudo-challenge-password` (real `<form method=post>`, `type=password`,
  `autocomplete="current-password"`, associated "Password" label), iCloud
  Passwords surfaced its autofill suggestion. Trigger: the Plugins-screen
  "Confirm your identity" link → `admin.php?page=wp-sudo-challenge`.
- **In-editor modal — password → OFFERS.** The `wp.components.Modal`
  (`.wp-sudo-reauth-modal`) password field (`type=password`,
  `autocomplete="current-password"`, real `<form>`, "Password" label,
  `id="inspector-text-control-N"`) also drew an iCloud Passwords offer. Trigger:
  a gated `apiFetch({ path:'/wp/v2/plugins/hello', method:'PUT', data:{status:'active'} })`
  fired from the block editor — the `plugin.activate` rule intercepts with
  `sudo_required` *before* the controller runs (nothing is actually activated),
  opening the modal (the same mechanism as E2E `editor-reauth.spec.ts` EDITOR-01).
- **Live-markup observations (both surfaces):** the password input carries an
  **empty `name`** attribute and there is **no `autocomplete="username"` hint
  field** — yet iCloud Keychain offered on both surfaces regardless. This is
  direct evidence that the standards-minimal set (real form + `type=password` +
  `current-password` + label + submit) is **sufficient for iCloud Keychain**, and
  that the "Candidate cheap fix" `autocomplete="username"` hint is **not required
  for iCloud Keychain** (it may still help managers that key on the username
  field to pick *which* credential — verify per-manager before concluding).
- **Classification:** the autofill **offer** is verified on both surfaces (no
  markup change is needed for the offer to appear); the full **offer → accept-fill
  → submit → sudo grant** chain is **not yet verified**, and the result is scoped to
  the **iCloud Passwords Chrome extension only** — Safari's native iCloud Keychain
  and other Apple contexts remain pending.
- **Not tested:** the **Full-page TOTP** cell (the test account has no Two Factor
  provider enabled, so the Two Factor plugin renders no `one-time-code` field); and
  the **in-editor modal 2FA** surface — the modal now hosts an in-place second factor
  for modal-capable providers (TOTP / email / backup codes, Milestone B) and links
  out only for non-modal-capable providers, so its autofill/TOTP behavior with any
  manager is untested here.

### Notes on the untested rows (2026-07-24)

- **1Password — not tested this pass.** The 1Password extension was **absent from
  the available Chrome profile** ("Home Mac Mini" identity). No DOM injection
  (no injected iframe, no `1password`/`op-` field attributes or inline-menu
  container) was detected on either surface, consistent with the extension not
  running in that profile. The priority "1Password + Two Factor" repro target
  (below) remains **pending** a Chrome profile with 1Password installed and
  unlocked.
- **Chrome/Edge/Firefox built-in — not isolated this pass.** In the test profile
  **iCloud Passwords was the active autofill provider**; Chrome's own dropdown
  did not surface a saved credential (`:autofill` pseudo-class was false, no
  Chrome suggestion observed). A clean test of Chrome's built-in manager needs a
  profile **without** iCloud Passwords intercepting, with a credential for
  `localhost:8881` saved in Chrome.

## Manual verification protocol

Use a real browser profile with the password manager installed and unlocked by
the human tester. Do not export, inspect, or paste vault contents into logs.

For each manager/cell:

1. Record manager name/version, browser/version, WordPress URL, WP Sudo commit,
   and whether the WordPress account has Two Factor enabled.
2. Start from a logged-in admin editor session with no active sudo session.
3. Trigger a gated editor action that opens the in-editor modal.
4. Observe whether the manager offers the correct WordPress credential in the
   modal password field.
5. Submit through the manager-assisted path and record whether the modal grants
   and the original request re-dispatches.
6. For the full-page challenge, open the challenge page from the fallback
   snackbar and repeat the password observation there.
7. For Two Factor, use an account with Two Factor enabled, enter the password
   step, and observe whether the manager offers or fills the TOTP field rendered
   by the Two Factor plugin on the full-page challenge. The in-editor modal is
   expected to link out on `2fa_pending`; it does not render a TOTP field.
8. Classify any miss as either `manager limitation`, `fixable markup`, or
   `unclear`, with a short reproduction note.

### 1Password with Two Factor repro target

This is the priority manual pass because the original report was that the flow
is harder with 1Password plus Two Factor:

1. In Chrome, unlock 1Password and confirm it has the local WordPress admin
   credential plus the account's TOTP.
2. Log in to the Studio/local WordPress site as the Two Factor-enabled admin.
3. From the editor, trigger a gated action. The modal should accept the password
   step only long enough to receive `2fa_pending`, then close to the link-out
   snackbar.
4. Click `Reauthenticate` to open the full challenge page.
5. On the full-page challenge, record whether 1Password offers the password
   credential, then whether it offers the TOTP on the Two Factor field.
6. Record whether the user can finish the challenge without manually copying
   from 1Password. If not, note which field failed and whether an
   `autocomplete="username"` hint would plausibly help the password step.

## References

- Research todo: password-manager 2FA interaction item under `.planning/todos/pending/`
- Two Factor rendering surfaces: `docs/two-factor-integration.md`
- Modal risk-item: `gutenberg-editor-reauth-phase2-plan.md` (Task 3 autofill verification)
