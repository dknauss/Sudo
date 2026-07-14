# Password-Manager / Autofill Compatibility — Reauth Surfaces

Status of password-manager autofill on WP Sudo's reauthentication surfaces.

> **Verification boundary.** The *markup audit* below is factual and cited to
> source (verifiable from the code). The *per-manager autofill matrix* is
> **NOT** filled in: per this repo's verification rules (`CLAUDE.md` →
> Verification Requirements; the research todo
> `.planning/todos/pending/2026-07-05-password-manager-2fa-interaction.md`), a
> specific manager's autofill/TOTP behavior must not be recorded as fact without a
> concrete manager version + reproduction. Those cells require manual verification
> on a machine with each manager installed — they are left as `pending`.

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
| TOTP `autocomplete="one-time-code"` | Provided by the **Two Factor plugin's own** field rendering at our challenge time (`Two_Factor_Core::…->authentication_page()`, `class-challenge.php:387`), not by us | N/A — the modal has no 2FA step yet (Milestone B; a `2fa_pending` password response links out to the full-page challenge) |

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

## Per-manager autofill matrix — PENDING MANUAL VERIFICATION

Fill each cell only with a concrete manager version + reproduction. Do not infer.

| Manager | Full-page: password | Full-page: TOTP | Modal: password |
|---|---|---|---|
| 1Password | pending | pending (see todo — reproduce the "harder with 2FA" report with a version) | pending |
| Bitwarden | pending | pending | pending |
| iCloud Keychain | pending | pending | pending |
| Chrome/Edge/Firefox built-in | pending | pending | pending |
| Dashlane | pending | pending | pending |

For each filled cell, state whether a miss is a **manager limitation** or a
**fixable-markup** issue on our side, and link the repro.

## References

- Research todo: `.planning/todos/pending/2026-07-05-password-manager-2fa-interaction.md`
- Two Factor rendering surfaces: `docs/two-factor-integration.md`
- Modal risk-item: `gutenberg-editor-reauth-phase2-plan.md` (Task 3 autofill verification)
