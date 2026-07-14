# In-Editor Reauth — Execution Checklist (Milestone A → B)

**For the browser-capable session.** Ordered RED→GREEN TDD steps that turn the milestone
plan into executable work. Do them top to bottom; each code commit needs a fresh
pre-commit **reviewer-approved** flag (docs-only `.md` commits skip it). Hand the reviewer
the **C1–C4** checklist from the phase-2 plan on every review request for this feature.

**Branch:** `feat/gutenberg-reauth-increment-3` (PR #178). **Read first:** the "▶ START
HERE" PR comment, `gutenberg-editor-reauth-milestone-plan.md`, and (for B)
`gutenberg-editor-reauth-milestone-b-2fa-partial-brief.md`.

**Gate commands** (superuser sandbox → prefix PHP/composer with `COMPOSER_ALLOW_SUPERUSER=1`):
- `composer test:unit` · `composer lint` · `composer analyse` (PHPStan **+** Psalm) · `composer verify:metrics`
- `composer verify:i18n` needs the WP-CLI shim on PATH (scratchpad `wpcli-bin`)
- Integration: `composer test:integration` **and** `WP_MULTISITE=1 composer test:integration` (the flag `bin/run-integration-tests.sh` forwards — `WP_TESTS_MULTISITE` alone silently runs single-site)
- E2E single-site: `npm run test:e2e:local` · multisite: `npm run test:e2e:local:multisite`
- After adding specs: update `docs/current-metrics.md` in the **same** commit (`verify:metrics` gates it)

---

## Step 0 — Rebase (no new tests; reconcile only) ✅ DONE (2026-07-13)
- [x] `git fetch origin main && git rebase origin/main` on `feat/gutenberg-reauth-increment-3` (was 9 behind). **Clean replay — no reconciliation needed:** `admin/js/wp-sudo-editor-reauth.js` + `includes/class-plugin.php` are byte-identical to the pre-rebase reviewed floor; the branch only absorbed main's already-merged Increment-1/2 commits.
- [x] `composer test:unit` (1026) + `lint` + `analyse` (PHPStan+Psalm) + `verify:metrics` green; force-pushed (`--force-with-lease`, PR #178 head → `964d730`, no longer BEHIND). codex reviewed. _Note: first `test:unit` run errored `Class WP_Sudo\User_Identity not found` — stale Composer classmap (class added Jul 6, `dump-autoload` never re-run); fixed with `composer dump-autoload`. Untracked/local, affects `main` identically — run it after checkout if seen._

## Step 1 — Modal grant E2E floor (EDITOR-06) ✅ DONE (2026-07-14, commit `ce1c67e`, codex-approved)
**Outcome:** 7 active tests in `editor-reauth.spec.ts` (no `test.fixme`), green against a live WP Sudo env (WordPress Studio, WP 7.0.1, `localhost:8881`). Codex's C1–C4 review caught that the initial floor (`25c2e72`) hadn't yet met the reviewed C4/Q2 decisions and my first reconciliation *encoded* that gap; the approved commit fixes them: **C4** (modal opens only with a validated same-origin `challenge_url`; null/unsafe → plain notice, no modal), **Q2** (`/batch/v1` is detect-and-surface only — no modal, no envelope re-dispatch), plus a submit-chain `.catch` so a rejected grant can't lock the modal (EDITOR-07). Tests: EDITOR-06 grant, EDITOR-01 cancel→link-out, EDITOR-02 batch, EDITOR-03/05 no-safe-URL degradation, EDITOR-07 rejection recovery, EDITOR-04 unchanged. POT refreshed; metrics E2E count → 71. **Deferred (still gate Step 8 un-draft):** Q3 concurrent single-flight (#4 → Step 4); `b86de6b` commit message needs the two-factor source URL (verified: `github.com/WordPress/two-factor/blob/master/providers/class-two-factor-email.php`) — amend before un-draft (history rewrite + force-push).
- [x] **⚠ ENV GUARD (do this FIRST — a bare `wp-env start` is not enough):** confirm the E2E target is **WP Sudo's own** wp-env, not some other WordPress that merely answers on the same port. On one machine `localhost:8889` was an unrelated **Pixel** env reached via an SSH tunnel; WP Sudo was absent, so EDITOR-04 **passed vacuously** (with nothing gating, "normal error ≠ modal" is trivially true) and EDITOR-06 failed for the wrong reason. Before trusting any result: (a) `curl -s http://localhost:8889/wp-json/wp/v2/plugins` (authenticated) and verify a WP Sudo entry with `status: active`; (b) sanity-check that a gated `apiFetch` (e.g. `PUT /wp/v2/plugins/hello {status:'active'}`) is **rejected** with a `sudo_required`-class code, not resolved. If either fails, the environment is wrong — fix it before writing/running specs. NB: this session's `wp-env start` brought up MySQL but never created the WordPress containers (deprecated `.wp-env.json` options + custom WP 7.0 core zip); verify WordPress is actually up, don't assume exit-0 means provisioned.
- [x] **RED/GREEN — EDITOR-06** active; drives a real gated action via `apiFetch` `PUT /wp/v2/plugins/hello` (cleaner + more deterministic than the Block Directory inserter UI), asserts modal opens, password grant, original request re-dispatches and resolves.
- [x] Reconciled **EDITOR-01/02/03/05** to the middleware's actual (corrected) decision keyed on the validated `challenge_url`: 01 modal→cancel→link-out; 02 batch detect-and-surface (no modal); 03/05 no-safe-URL → plain notice, no modal. Kept **EDITOR-04**. (Superseded the "modal-cancel for all" first attempt that codex rejected.)
- [x] **GREEN:** targeted run `WP_BASE_URL=http://localhost:8881 WP_SUDO_SKIP_WP_ENV_CAP_SETUP=1 …editor-reauth.spec.ts` → **7 passed**. Metrics E2E count updated to 71.

## Step 2 — 2FA-bypass invariant (THE security gate)
- [ ] **RED (unit/integration):** a 2FA-enabled user's password-only path returns `2fa_pending` and **never** a granted session — assert `handle_ajax_auth` yields `2fa_pending` (not `authenticated`) and no `Sudo_Session` token is minted for a 2FA user on the password step.
- [ ] **RED (E2E):** 2FA account → editor gated action → modal password → **no grant** → link-out to the full challenge page.
- [ ] **GREEN:** both pass. This turns "password-only is safe" from a claim into an enforced guarantee; guards against a future bypass regression.

## Step 3 — Stale-nonce recovery
- [ ] **RED:** an aged/expired `wp_sudo_challenge` grant nonce (overnight-tab scenario, not a nonce-valid tab) → `refreshNonceAction` re-mints and the grant then succeeds (or one retry-on-`check_ajax_referer`-failure path). Assert the recovery, not just the happy path.
- [ ] **GREEN:** pass.

## Step 4 — REST re-dispatch boundaries
- **Q2 (batch detect-and-surface) already landed in Step 1** (`ce1c67e`, EDITOR-02): batched `/batch/v1` `sudo_required` surfaces the snackbar, never opens the modal, never re-dispatches the envelope. Remaining here:
- [ ] **RED (Q3 — codex #4, THE open item):** single-flight (one modal/snackbar) + concurrent `sudo_required` rejections must **re-dispatch only the user-actioned request**, not every shared caller. Today all callers share `pendingGrant` then each re-fire their own `wp.apiFetch(options)` — replaying background requests the user never actioned. Fix + test the queue/debounce.
- [ ] **GREEN:** pass.

## Step 5 — Degradation + headless regression (C4, Q4)
- **C4 client behavior already landed in Step 1** (`ce1c67e`, EDITOR-03/05): rejection without a safe `challenge_url` (absent or unsafe) → plain message, no "Reauthenticate" action, no modal. Remaining here:
- [ ] **RED:** the UI never echoes the rule label (Q4). App-password/bearer `sudo_required` stays `challenge_url`-free (assert against the headless branch in `class-gate.php`).
- [ ] **GREEN:** pass.

## Step 6 — Password-manager / autofill matrix
- [ ] Manually verify the `createElement` modal password field against 1Password / Bitwarden / iCloud Keychain / browser built-ins; apply cheap markup fixes (field-markup rules, phase-2 Part 7). Record the matrix in the password-manager todo (`.planning/todos/pending/2026-07-05-password-manager-2fa-interaction.md`).

## Step 7 — Full gate sweep (single-site AND multisite)
- [ ] `npm run test:e2e:local` **and** `npm run test:e2e:local:multisite` green (the challenge-URL routing differs on multisite).
- [ ] `composer test:integration` **and** `WP_MULTISITE=1 composer test:integration` green.
- [ ] `composer analyse` (PHPStan+Psalm), `composer lint`, `composer verify:metrics`, `composer verify:i18n` green.
- [ ] **Security-scoped review of the real diff** with the C1–C4 checklist named explicitly.
- [ ] Release notes state the 2FA-users-link-out fallback plainly (branch readme already does).

## Step 8 — Un-draft Milestone A
- [ ] **Pre-un-draft carryover from Step 1's codex review** (both flagged as PR-level, keep PR draft until resolved):
  - **Q3 (#4)** single-flight/concurrent — must be done in Step 4.
  - **`b86de6b` (#5/#6)** commit message lacks the required third-party source URL for its WordPress/two-factor claims. Amend to cite `github.com/WordPress/two-factor/blob/master/providers/class-two-factor-email.php` (claim verified: `authentication_page()` → `generate_and_email_token()` → `wp_mail()`). Needs a history rewrite + `--force-with-lease` push.
- [ ] All Step 1–7 boxes checked → mark PR #178 ready for review; maintainer merges.

---

## Milestone B (after A merges) — from the reviewed brief
Follow `gutenberg-editor-reauth-milestone-b-2fa-partial-brief.md`. TDD order:
1. **RED:** extract `render_two_factor_fields($user)`; assert `render_page()` output **byte-identical** (shared-renderer regression).
2. **RED:** `handle_ajax_2fa_partial()` — 403 without/with expired `2fa_pending`; `link_out` for WebAuthn-primary, **no-provider (hook-only)**, and unknown providers; markup for TOTP-primary.
3. **RED (the HIGH finding):** email-provider partial fetch is **single-flight + shares the `wp_sudo_resend_*` throttle** — no unbounded mail-send on re-fetch. (`Two_Factor_Email::authentication_page()` sends on render.)
4. **RED:** `wp_sudo_render_two_factor_fields` behaves in the new admin-ajax fire context (script/screen-dependent callback → link-out or documented plain-field requirement).
5. **RED (client):** serialize **all** injected-partial fields generically (no hardcoded `authcode`); handle the pending-expired 403 → link-out/restart (not "invalid code").
6. **RED (E2E, single-site + `WP_MULTISITE=1`):** TOTP in-modal grant → re-dispatch; WebAuthn → link-out (no dead form); email resend + shared cap; multisite asserts the partial resolves the **same per-site `2fa_pending` transient** the password step wrote.
7. Resolve **Q-B1** (default-deny `wp_sudo_2fa_modal_capable` filter vs. private curated map) in implementation review; security-scoped review of the new endpoint + email render-send behavior.
