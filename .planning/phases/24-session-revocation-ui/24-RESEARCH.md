# Phase 24: Session Revocation UI — Research

**Status:** Lean research (planning invoked with `--skip-research`)
**Created:** 2026-06-30

> Research was skipped per `--skip-research`. The domain is already fully decided
> in `24-CONTEXT.md` (locked interaction model, gating, scope, guardrails, and
> feedback). This file exists only to carry the **Validation Architecture** that
> the VALIDATION.md derives from, plus the few facts the planner must not
> re-derive. It is intentionally thin — the authoritative design source is
> `24-CONTEXT.md`.

---

## Decided Facts the Planner Must Honor

These come straight from `24-CONTEXT.md` (locked) and the live codebase; restated
here so the planner and plan-checker share one reference.

- **No-JS classic pattern.** `users.php` has no WP Sudo JS (admin JS enqueues only
  on `settings_page_wp-sudo-settings`, `class-admin.php:986-990`). The row action is
  a nonced link → `admin_post_` handler → redirect to `users.php` with a dismissible
  notice. The revoke-all confirmation is an interstitial native `.wrap` page.
- **Reusable core to factor.** `Admin::handle_revoke_session()`
  (`class-admin.php:1672`) already does cap check → `REVOKE_RATE_PREFIX` 10/hour rate
  limit → `Sudo_Session::deactivate()` → `wp_sudo_session_revoked` hook. Factor its
  core so the new `admin_post_` handler and the shared revoke-all method call **one
  path**.
- **Promote the CLI enumerator.** `revoke_all_active_sessions()`
  (`class-cli-command.php:123`) is current-site-scoped (`get_users` with the session
  meta key, no `blog_id`). Promote to a shared `Sudo_Session` method so UI + CLI run
  one code path.
- **Active-session enumeration source of truth.** Row-action visibility and the
  revoke-all count use the `_wp_sudo_expires > time()` meta query already behind the
  "Sudo Active (N)" view / `filter_users_by_sudo_active()` (`class-admin.php:1063`,
  `:1134`, meta query `:1176`) — **not** `Sudo_Session::is_active()` (cookie-bound).
- **Removals.** Access-tab "Revoke Session" button (`class-admin.php:1421-1424`) and,
  after consolidation, the orphaned `wp_ajax_handle_revoke_session` AJAX action
  (`class-admin.php:309`).
- **Exclusions.** Both the row action and revoke-all exclude the operator's own
  session/row.
- **Rate-limit accounting.** Revoke-all = **one** slot against the 10/hour budget;
  per-user row action = one slot each.
- **Audit reason tags.** Distinct machine-readable reasons per surface
  (`users_list_row_action`, `revoke_all_ui`; CLI keeps its own).
- **Notices via `wp_admin_notice()`** (WP 6.4+) with correct type, `is-dismissible`,
  aria-live/role.

## ⚠ Requirement-Wording Reconciliation (planner + checker MUST note)

`REQUIREMENTS.md` **REVK-02** reads "revoke all active sudo sessions **network-wide**".
`24-CONTEXT.md` deliberately narrows this to **current-site scope** for v4.5, with
true cross-site/network-wide enumeration explicitly deferred as **REVK-F3**. The
CONTEXT decision is authoritative (it is the user's discussed, locked scope). Plans
must implement **current-site-scoped** revoke-all and **state this deviation
explicitly** so the plan-checker does not flag REVK-02 as unmet. Recommend updating
the REVK-02 requirement text (or annotating it) to read "current-site" with the
network-wide portion tracked under REVK-F3.

---

## Validation Architecture

Phase 24 is a UI/security phase. Validation must prove the **decision logic and
guardrails** (capability gate, rate-limit accounting, audit-hook firing with the
correct reason tag, active-session enumeration, message selection, operator-self
exclusion) under unit test with Brain\Monkey/Mockery — the same harness that already
covers `handle_revoke_session()` in `AdminTest.php` and the CLI revoke-all in
`CliCommandTest.php`. Pure presentation (notice rendering, interstitial layout) that
cannot be meaningfully asserted in unit tests is captured as manual verification.

**Automated validation must cover:**

1. **Shared revoke-all method** (new `Sudo_Session` method): enumerates the
   current-site active-session set via the `_wp_sudo_expires > time()` meta query,
   deactivates each, **excludes the operator's own session**, returns the count.
   Asserted in `SudoSessionTest.php`. CLI command continues to pass through it
   (`CliCommandTest.php` still green).
2. **Factored revocation core** is reached by BOTH the per-user `admin_post_` handler
   and the shared revoke-all method: each path enforces
   `wp_sudo_can('revoke_wp_sudo_sessions')`, applies the per-revoker rate limit
   (revoke-all = one slot; row action = one slot), and fires `wp_sudo_session_revoked`
   with the **surface-specific reason tag**. Asserted in `AdminTest.php`.
3. **Row-action visibility gate** (`user_row_actions` filter): action is **absent**
   for operators lacking the cap; **absent** on the operator's own row; **present**
   only for users in the active-session set; **present** (not hidden) for a
   cap-holding operator with no active sudo session of their own. Asserted in
   `AdminTest.php`.
4. **Three distinct blocked-path messages + success** are selected correctly:
   no-cap → "permission" message; operator-has-no-session → "start a session"
   message; target-already-expired → "no longer active" message; success → success
   notice. Asserted on the handler's outcome/redirect query arg (not the rendered
   HTML) in `AdminTest.php`. Satisfies REVK-05 "never a silent no-op."
5. **Nonce + cap + sudo-gating** on every entry point: the `admin_post_` handler and
   the revoke-all confirm/perform path verify a nonce, require the cap, and remain
   themselves sudo-gated (consistent with the old gated AJAX action). Asserted in
   `AdminTest.php`.
6. **Dead-code removal is clean:** the Access-tab "Revoke Session" button markup and
   the `wp_ajax_handle_revoke_session` registration are gone; no remaining caller
   references them. Asserted by a presence/absence test or grep-backed assertion in
   `AdminTest.php`, plus full suite green.
7. **Full gate:** `composer test`, `composer analyse` (PHPStan L6), `composer lint`
   all green before code commit.

**Manual-only (UI presentation):**

- Visual confirmation that the interstitial confirm screen states the exact count and
  offers Confirm/Cancel, and that dismissible notices render with assistive-tech
  announcement on `users.php` after redirect. Why manual: layout/notice rendering in
  a live wp-admin Users screen is not meaningfully unit-assertable; the message
  *selection* and count *computation* are covered automatically above.

**Pre-implementation design review (project policy):** REQUIRED before tests/code —
new UI surface, capability checks, multisite/Users-list execution contexts (per
CLAUDE.md). Tracked as a manual gate; no non-doc commit before reviewer approval.

---

*Phase: 24-session-revocation-ui*
*Lean research authored 2026-06-30 (planning ran with `--skip-research`)*
