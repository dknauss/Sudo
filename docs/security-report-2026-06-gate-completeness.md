# Security Report — Action-Gating Completeness (June 2026)

Coordinated-disclosure investigation of two gate-completeness weaknesses in the
WP Sudo action-gating model. Both were confirmed true positives and fixed in
**4.1.0** (PR #102). This report records what they are, **when and how they
entered the code**, why earlier audits missed them, and **how to prevent
recurrence**.

- **Severity:** Medium. Neither is a remote or unauthenticated bypass; both
  presuppose an already-authenticated session (borrowed/stolen) or a captured
  re-auth cookie.
- **Affected versions:** ≤ 4.0.0 (see [Provenance](#provenance)).
- **Fixed in:** 4.1.0. No DB migration; binding is enforced only when present.

---

## The two findings

### F1 — Effect-level gating absent on the interactive surface

The gate protects different surfaces two different ways:

- **Non-interactive** (WP-CLI, Cron, XML-RPC): gated at the **effect level** —
  `Gate::register_function_hooks()` hooks the WordPress actions/filters that fire
  just before a dangerous effect (`delete_user`, `activate_plugin`,
  `pre_update_option_*`, etc.). These fire regardless of how the request arrived.
- **Interactive** (admin UI / AJAX / REST): gated by **request-pattern
  matching** — `Gate::intercept()` compares `$pagenow` + the `action` parameter
  against an enumerated rule list (`plugins.php`, `users.php`, `user-edit.php`,
  …). `admin-post.php` and third-party/custom REST routes are **not** enumerated.

**Consequence:** a well-behaved third-party plugin that performs a
gated-equivalent action from its own `admin-post.php` handler (with a correct
capability check *and* nonce) ran with **no sudo challenge**, because no rule's
`pagenow` matches `admin-post.php`. The identical action was blocked on WP-CLI
(`wp user delete`) and on core's enumerated REST route (`DELETE
/wp/v2/users/<id>`). Same effect, inconsistent outcome.

### F2 — Sudo proof not bound to the login session

`Sudo_Session::verify_token()` checked only three things: that the current user
matches, that a per-browser random cookie token (`wp_sudo_token`) hashes to the
stored value, and that the token is non-empty. It never consulted
`wp_get_session_token()`. Two consequences:

1. **Cross-session replay.** A captured `wp_sudo_token` cookie could be presented
   from a *different* login session for the same user and pass verification.
2. **Outlives logout.** No `wp_logout` teardown existed, and the proof was not
   tied to a session token, so the sudo window survived logout and
   `WP_Session_Tokens::destroy_all()`.

---

## Provenance

> **Caveat:** the repository's git history is collapsed/squashed (every
> `git log -S` probe for the relevant symbols resolves to a single commit,
> `de96849`, whose message is unrelated to the code). Commit-level attribution
> is therefore **not recoverable** from this clone. Provenance below is
> established at **release-tag granularity**, which is verifiable from real
> release snapshots.

### When

| Finding | First present in | Evidence |
|---|---|---|
| **F2** (no login-session binding) | **v1.2.0** (earliest fetchable tag) | `verify_token` / `wp_sudo_token` exist in `v1.2.0`; `wp_get_session_token` is **absent in every tag v1.2.0 → v4.0.0** |
| **F1** (interactive/non-interactive asymmetry) | **v2.2.0** | `register_function_hooks` is absent in `v2.0.0`/`v2.1.0`, first appears in `v2.2.0`; the interactive `intercept()`/`match_request()` pattern-matcher predates it (v2.0.0 gate) |

`includes/class-sudo-session.php` is **byte-identical (whitespace-ignored)
`v3.4.0` → `v4.0.0`**; `includes/class-gate.php` changed only cosmetically in
that span (a `"WP Sudo"` → `"Sudo"` string rebrand). So 4.0.0 introduced
neither finding — it inherited both unchanged.

### How (root cause)

- **F2 — an omission in the original session design.** The session was bound to
  the *browser* (a random cookie token) to stop a stolen cookie working from a
  different device. It was never bound to the *login session* that minted it, and
  logout teardown was never wired. The binding model answered "is this the same
  browser?" but not "is this still the same login?" — so the proof's lifetime was
  decoupled from the authentication that justified it.

- **F1 — a surface-coverage assumption baked into the architecture.** When the
  three-tier surface policy arrived in v2.2.0, non-interactive surfaces got
  effect-level hooks (the only thing that works without a UI), while the
  interactive surface kept request-pattern matching so it could offer the
  challenge + stash/replay UX. The implicit assumption was that dangerous actions
  on the admin surface only flow through **enumerated core pages**. That holds for
  core, but not for third-party `admin-post.php` handlers or custom REST routes —
  which is precisely the uncovered path. The gate could only block what it had
  been positioned to see.

---

## Why earlier audits did not catch these

The v3.4.0 build was scanned and recorded "clean." That result was correct *for
the question that scan asked*. The scan was **sink-oriented**: enumerate request
handlers and hunt for injection, XSS, CSRF/nonce gaps, and broken access control
*inside* the code. WP Sudo has none of those, so it passed.

Both findings are invisible to that framing:

- **F1 is a control-completeness gap**, not a sink bug. The vulnerable handler
  that runs is a *third party's*, and correctly written; the defect is that WP
  Sudo's control *never fires there*. A sink scan of WP Sudo's own code sees
  nothing wrong.
- **F2 is a session-management property** (credential-to-session binding and
  lifetime), not a sink. There is no tainted-data flow to find.

The findings surfaced only when the plugin was audited **as a security control**,
from the asset: *"can the reauth gate be bypassed, and is the reauth credential
bound to its session?"* That is a different methodology, not a different level of
diligence.

---

## The fix (4.1.0)

- **F1 — interactive effect-level backstop** (`includes/class-gate.php`):
  `register_interactive_backstop()` arms on `admin_init` and hard-blocks the
  unambiguous destructive effects (`delete_user`, `delete_plugin`,
  `delete_theme`, `activate_plugin`, `upgrader_pre_install`, `export_wp`) when no
  sudo window is active. Deliberately **scoped** to those effect hooks;
  `pre_update_option_*` filters and the `user.create`/`user.promote` hooks are
  **excluded** because core and legitimate plugins write them incidentally during
  ordinary admin loads (e.g. `validate_active_plugins()`), so guarding them would
  block non-gated workflows. Silent on allow (the enumerated flow owns
  `wp_sudo_action_passed`); on block fires `wp_sudo_action_blocked` on the
  `admin` surface with the real user ID.
- **F2 — login-session binding** (`includes/class-sudo-session.php`,
  `includes/class-plugin.php`): new `_wp_sudo_session_bind` user meta = SHA-256 of
  the login-session token, captured at activation (via `set_logged_in_cookie` so
  it works during the login-grant request). `verify_token()` rejects a non-empty
  bind that no longer matches the current login session; the session is
  deactivated on `wp_logout`; `uninstall.php` cleans the new key. Enforced only
  when present, so pre-4.1.0 sessions need no migration and cookie-less surfaces
  (CLI/cron/app-password/WPGraphQL) remain policy-governed.

The fix followed the project's pre-implementation design review (which produced
the scope decision above), TDD (`tests/Unit/ActionGatingCompletenessTest.php`),
and the pre-commit reviewer workflow.

### Residual / deferred gaps (tracked, not yet closed)

The design review deliberately deferred these to separate increments to keep the
fix minimal and avoid over-blocking:

1. **Custom REST routes** — the backstop arms on `admin_init` only. A custom
   REST route that calls a destructive effect is not yet backstopped, because a
   REST backstop must mirror `intercept_rest()`'s app-password policy/auth
   classification or it would regress Unrestricted headless users.
2. **`user.create` / `user.promote` via non-enumerated handlers** — excluded
   from the admin backstop because their hooks (`wp_pre_insert_user_data`,
   `*_capabilities` metadata writes) fire on benign, high-frequency paths; they
   need the same incidental-write analysis before they can be safely guarded.

---

## Prevention — how to stop more of the same

1. **Audit this plugin as a control, on every release.** Add a recurring
   **control-oriented** review alongside the sink-oriented scan, framed from the
   asset: *can the gate be bypassed, and is the reauth credential bound to its
   session?* Sink scans will keep coming back clean; they cannot see these
   classes.
2. **Enforce effect/surface parity.** Maintain a matrix of *every* dangerous
   effect × *every* surface (admin, ajax, rest cookie, rest app-password, cli,
   cron, xmlrpc, wpgraphql, and **non-enumerated admin/REST handlers**), with the
   expected outcome in each cell. A new gated rule is not "done" until every cell
   is accounted for. Treat any surface that relies on *enumeration* (rather than
   the effect boundary) as presumptively incomplete for third-party handlers.
3. **Codify session invariants as tests.** The sudo proof must be bound to (a)
   the browser and (b) the login session, and must not outlive logout, password
   change, or `destroy_all()`. Keep these as explicit unit/integration
   assertions so a future refactor cannot silently drop one (4.1.0 adds the
   binding tests; the logout/`destroy_all` integration assertions are the natural
   next addition).
4. **Close the deferred gaps deliberately.** Track the two residual items above
   to completion rather than letting "deferred" become "forgotten."
5. **Prefer the effect boundary over enumeration where UX allows.** Enumeration
   is necessary for the challenge + stash/replay experience, but it is a
   coverage liability. The effect-level backstop pattern (block at the WordPress
   effect hook when no sudo window is active) is the more complete primitive;
   extend it surface by surface as the policy/UX questions for each are resolved.

---

## References

- PR #102 — implementation, design review, and verification.
- `docs/security-model.md` — threat model, trust boundaries, meta-key table.
- `docs/developer-reference.md` — hook signatures (incl. the `admin`-surface
  `wp_sudo_action_blocked` from the backstop).
- `CHANGELOG.md` / `readme.txt` — 4.1.0 entry (affected versions: ≤ 4.0.0).
