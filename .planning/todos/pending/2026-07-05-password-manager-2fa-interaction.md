---
created: 2026-07-05
title: Investigate password-manager / 2FA interaction with WP Sudo reauth
area: research
files:
  - docs/two-factor-integration.md
  - docs/two-factor-ecosystem.md
  - includes/class-challenge.php
  - docs/ROADMAP.md
---

## Problem

WP Sudo's reauthentication surfaces (the full-page `wp-sudo-challenge` screen and,
if the Tier 2 block-editor feature ships, an in-editor modal) present a password
field and, on 2FA sites, a second-factor step. How password managers interact with
these surfaces is unverified, and at least one manager is known to be getting harder:

- **1Password is reportedly/anecdotally harder with two-factor lately — UNVERIFIED.**
  This is a hypothesis to reproduce, not a settled fact. Recent 1Password behavior
  around TOTP autofill / passkeys / the in-app 2FA flow *may* make it awkward on
  second-factor prompts. Pin down what actually breaks (autofill not offered, TOTP not
  surfaced, passkey prompt intercepted, etc.) with a **concrete 1Password version +
  repro** before recording any of it as fact (per the repo verification rules).

The reauth flow is deliberately *not* the WordPress login form — it is a custom
challenge handler (`includes/class-challenge.php`). Password managers key heavily off
login-form heuristics (`autocomplete` attributes, field `name`/`type`, form action),
so our custom form and especially a `wp.components.Modal` field injected via
`createElement` may not trigger autofill the way `wp-login.php` does.

## Scope of investigation

1. **Which managers, which surfaces.** Matrix of {1Password, Bitwarden, iCloud
   Keychain, Chrome/Edge/Firefox built-in, Dashlane} × {full-page challenge, in-editor
   modal (if built)} × {password autofill, TOTP autofill, passkey/WebAuthn}.
2. **What our markup gives them.** Audit the challenge form and (future) modal for the
   `autocomplete` tokens and field semantics managers expect (`current-password`,
   `one-time-code`, proper `name`/`type`, a real `<form>` with a submit). Confirm the
   AJAX-submitted form still exposes autofill-friendly semantics.
3. **Two Factor (WordPress/two-factor) itself.** How the plugin renders each provider
   (TOTP, WebAuthn/passkey, email, backup codes) at *our* challenge time via provider
   delegation, and whether that rendering is autofill-friendly. Cross-reference
   `docs/two-factor-integration.md` (three integration surfaces) and
   `docs/two-factor-ecosystem.md`.
4. **1Password specifically.** Reproduce the "tough with two-factor now" behavior;
   identify whether it is a 1Password change, a passkey-vs-TOTP issue, or something in
   how the second factor is presented. Capture concrete repro + version.

## Deliverable

A short findings note (add to `docs/two-factor-integration.md` or a new
`docs/password-manager-compatibility.md`): per-manager autofill status on each reauth
surface, any cheap markup fixes (`autocomplete="current-password"` /
`autocomplete="one-time-code"`, form semantics), and a clear statement of what is a
manager limitation vs. something we can fix. Feeds the Tier 2 modal design (Task 3
autofill verification in the Phase 2 plan) and the general challenge-form UX.

## Notes

- This is research, not a committed feature. Do not make runtime support claims about a
  specific manager without a real repro (per the repo verification rules).
- Related: block-editor Tier 2 modal risk-item in
  `gutenberg-editor-reauth-phase2-plan.md` (a `createElement` modal field is the most
  autofill-hostile surface — verify it early if that feature is built).
