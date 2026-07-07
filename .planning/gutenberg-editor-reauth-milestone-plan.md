# Gutenberg In-Editor Reauth — Milestone Plan (A → B split)

**Status:** Active plan. Supersedes the "not yet started" framing in
[`gutenberg-editor-reauth-phase2-plan.md`](gutenberg-editor-reauth-phase2-plan.md),
which remains the canonical **technical/task reference** (surface inventory, C1–C4,
Part 7 security-config). This doc records the **scope decision and sequencing** after a
working password-grant modal was found already built on a branch.

**Decision (2026-07-07):** split the "transparent modal" work into two shippable
milestones — **A: password path** (finish + harden the existing floor), then
**B: 2FA in the modal**. Greenfield context: **no existing install base**; 2FA is
**not** mandatory today but is an aspirational future default.

---

## What already exists (the floor)

Branch **`feat/gutenberg-reauth-increment-3`** (commit `25c2e72`, *"in-editor
password-grant modal — Increment 2/3, Task 3 floor"*), 2 commits ahead of its
merge-base, currently **9 commits behind `main`** (merges clean, no conflicts). It is
**not merged and has no PR** — a WIP floor set aside so 4.6.0 could ship only the safe
Increment 1 (link-out) + Increment 2 (server grant plumbing + nonce-refresh endpoint).

Built and working (manually demoed in Playground; `blueprint-editor-reauth.json` +
`assets/editor-reauth-modal.png`):

- `apiFetch` middleware detects `sudo_required`, opens a single-flight
  `wp.components.Modal` (build-free `createElement`) instead of only linking out.
- Password step mirrors the proven `wp-sudo-challenge.js` fetch flow (FormData
  `authAction` + `wp_sudo_challenge` nonce, session-only, `credentials: same-origin`).
- On `{authenticated}` it closes and **transparently re-dispatches** the original
  request via `wp.apiFetch` (carries the user's own `wp_rest` nonce — C3).
- Stale grant nonce refreshed via `refreshNonceAction` before the grant call.
- `2fa_pending` / cancel / no-config **fall back to the Increment-1 link-out snackbar**.
- Password never stored/sanitized/logged client-side. No `stash_key`, no content-save
  gating, no build step. `Plugin::enqueue_editor_reauth()` adds `wp-element` +
  `wp-components` deps.

**Not built / not verified:**
- **2FA never renders in the modal** — any 2FA account links out to the full challenge.
- **No automated proof.** `editor-reauth.spec.ts`: only EDITOR-04 is active; the modal
  test **EDITOR-06** and the superseded snackbar tests (EDITOR-01/02/03/05) are
  `test.fixme`. Only code-level review passed.
- Password-manager/autofill behavior against the injected modal field is unverified.
- No security-scoped review of the actual diff; C1–C4 not re-verified against current
  `main`; no `WP_MULTISITE=1` run.

---

## Why the split (and why password-only is not a security downgrade)

- **No 2FA bypass.** The client cannot self-grant — the server mints the session
  (`handle_ajax_auth` → `check_ajax_referer` + password + `Sudo_Session`). For a
  2FA-enabled account the server returns `2fa_pending`, and the modal bounces that case
  to the full-page challenge, which enforces 2FA. A 2FA user's experience under
  Milestone A is **identical to today's 4.6.0** (link-out → challenge → 2FA). Password
  is never a substitute for a factor; the sudo session never substitutes for a
  capability check (C1; Part 7 nonce/CSRF).
- **Uniformity is unreachable anyway.** WebAuthn/passkey ceremonies **cannot** live in
  the modal (they need enqueued scripts + a hidden result field), so passkey users
  link out in **both** A and B. "Everyone gets the modal" is not an achievable end
  state — which removes the main reason to hold the password path for the 2FA work.
- **A is ~80% built, independently valuable, and security-neutral.** Bolting it to the
  hardest, least-certain part (provider 2FA rendering) delays a done feature for zero
  security gain.

The instinct that "no 2FA feels odd" is respected by a **hard gate, not a delay**: the
bypass-invariant test below turns the property into an enforced guarantee.

---

## Milestone A — In-editor reauth: password path

**Goal:** finish and harden the existing floor to a mergeable, verified state.
Non-2FA users reauthenticate in the modal; 2FA/passkey users link out (no regression).

Tracking home: a **draft PR reviving `feat/gutenberg-reauth-increment-3`**, scoped to A.

### Tasks
1. **Rebase the branch onto current `main`** (9 behind; reconcile `editor-reauth.js` and
   `class-plugin.php` against the shipped Increment-1/2 code). First task on the PR.
2. **De-`fixme` and finish the modal E2E** (`EDITOR-06`) against live wp-env; reconcile
   the superseded EDITOR-01/02/03/05 (delete or repoint — they asserted the link-out the
   modal now supersedes for non-2FA users). Keep EDITOR-04 (normal error ≠ modal).
3. **Bypass-invariant test (the security gate).** A 2FA-enabled user's password-only
   modal call must **never** return a granted session — assert it hard, at both the
   AJAX layer (unit/integration) and E2E (2FA account → modal password → *no* grant →
   link-out to challenge). This guards against a future change sneaking a bypass in.
4. **Stale-nonce recovery test.** Exercise `refreshNonceAction` under a genuinely
   **stale** grant nonce (aged/overnight-tab scenario), not only a nonce-valid tab
   (phase-2 plan Task 2 ⚠, Codex #158).
5. **Password-manager / autofill matrix.** 1Password / Bitwarden / iCloud Keychain /
   browser built-ins against the `createElement` modal field; apply cheap markup fixes
   (field-markup rules, phase-2 Part 7). Feeds
   `.planning/todos/pending/2026-07-05-password-manager-2fa-interaction.md`.
6. **REST re-dispatch boundaries.** Single-flight + concurrent-rejection queue (Q3)
   under test; batched-envelope **detect-and-surface** (Q2 — recognize a `sudo_required`
   inside `/batch/v1 responses[]`, never silently no-op).
7. **Degradation + headless regression.** Assert every fallback path (2fa_pending /
   cancel / no-config / unsafe or absent `challenge_url`) and that the headless /
   app-password branch stays `challenge_url`-free (C4).
8. **Single-site + `WP_MULTISITE=1` E2E green** (both required; multisite challenge-URL
   routing differs — phase-2 Task 6). Update `docs/current-metrics.md` for new specs.
9. **Mandatory security-scoped review of the real diff** + explicit C1–C4 checklist to
   the reviewer (phase-2 "Required gates"). Pre-commit reviewer flag on every code
   commit; `composer analyse` (PHPStan + Psalm) + integration suite before push.

### Exit criteria (all required)
Rebased onto `main`; EDITOR-06 + bypass-invariant + stale-nonce + degradation E2E green
on single-site **and** multisite; password-manager matrix recorded; C1–C4 verified;
security review passed; metrics synced; release notes state the 2FA-links-out fallback
plainly (the branch readme already does).

---

## Milestone B — 2FA in the modal

**Goal:** TOTP / email-OTP / backup-code accounts complete 2FA **inside** the modal.
WebAuthn/passkey stays link-out by nature (not a shortcut — inherent).

**The hard problem** (phase-2 Task 3 ⚠, Part 7 2FA): `handle_ajax_2fa()` only
*validates*, delegating to `$provider->validate_authentication()` + the
`wp_sudo_validate_two_factor` filter, both of which read **provider-specific `$_POST`
field names** emitted by the provider's own render step — which the modal never runs. A
generic modal field is **not** read. So B needs either:
- (a) a new `permission_callback`'d endpoint returning the **server-rendered provider
  partial** (exact provider markup), injected into the modal; or
- (b) provider-exact field names per factor.

Default to **(a) or link-out**; do not claim any in-modal 2FA works until the partial
endpoint exists. Per-provider suitability matrix from the 2FA-ecosystem survey:
TOTP/email-OTP/backup-codes = in-modal candidates (debounce email-OTP sends; never
decrypt secrets — always call the provider's validation); push/cloud-validated
(miniOrange) and no-stable-API (Shield) = link-out; WebAuthn = link-out.

Ships incrementally on top of A; same gate discipline (security review, C1–C4, the new
endpoint's `permission_callback`, single-site + multisite E2E, password-manager check on
the OTP field).

---

## References
- **Technical/task detail & C1–C4 & Part 7 security-config:**
  [`gutenberg-editor-reauth-phase2-plan.md`](gutenberg-editor-reauth-phase2-plan.md)
- **Design brief (approach, surface inventory, security review):**
  [`gutenberg-editor-reauth-design-brief.md`](gutenberg-editor-reauth-design-brief.md)
- **Password-manager/2FA autofill backlog:**
  `todos/pending/2026-07-05-password-manager-2fa-interaction.md`

## Sandbox note
E2E / wp-env / browser / password-manager verification cannot run in the headless build
sandbox (no `node_modules`/Docker/MySQL, no real browsers/credential managers). Those
tasks are executed in a browser-capable session; the sandbox handles code, unit/PHP
gates, and orchestration.
