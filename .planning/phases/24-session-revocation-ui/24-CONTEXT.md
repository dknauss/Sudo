# Phase 24: Session Revocation UI - Context

**Gathered:** 2026-06-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Make active sudo sessions revocable from the Users list — where they are already
visible via the "Sudo Active (N)" view (`_wp_sudo_expires > time()`) — and
separate session revocation from capability administration. Covers REVK-01..05:
a per-user "Revoke sudo session" Users-list row action, a UI-surfaced revoke-all
behind a confirmation step, removal of the Access-tab "Revoke Session" button,
preservation of the existing guardrails (cap + rate limit + audit hook), and
clear visible feedback on every path including the gated/no-session and
missing-cap cases.

Out of scope (deferred): an Access-tab "Active Sessions" panel (REVK-F1), session
metadata in the revoke UI such as countdown/bound IP (REVK-F2), and true
cross-site/network-wide enumeration (REVK-F3). Governance-coverage panel fixes are
Phase 25, not here.

A mandatory Pre-Implementation Design Review precedes TDD (per CLAUDE.md — new UI
surface, capability checks, multisite/Users-list execution contexts). A design
brief already exists at
`.planning/todos/pending/2026-06-30-session-revocation-surfaces.md`.

</domain>

<decisions>
## Implementation Decisions

### Per-user row action — interaction model
- The "Revoke sudo session" row action is a **classic nonced link → admin-post
  handler → redirect back to `users.php` with a dismissible admin notice**. No new
  JS is added to `users.php` (which has none today — admin JS is enqueued only on
  `settings_page_wp-sudo-settings`, see `class-admin.php:990`).
- Standard WP `user_row_actions` idiom; the post-action reload naturally drops the
  now-revoked row from the "Sudo Active" set.
- Single-user revocation acts **immediately** — no confirmation step (REVK-02).
- Row-action link text: **"Revoke sudo session"** (matches REVK-01 and the
  "Sudo Active" view vocabulary; unambiguously the session, not the capability).

### Row-action visibility / gating model
- **Hide** the row action entirely from operators who lack
  `revoke_wp_sudo_sessions` — never advertise an action they can never perform.
- **Render** it for cap-holders who currently lack an active sudo session, and let
  the click **fail with a clear, distinct message** ("Start a sudo session before
  revoking others"). That state is transient/recoverable; hiding it would confuse.
  Satisfies REVK-05's "never a silent no-op."
- The action is shown only for users in the active-session set
  (`_wp_sudo_expires > time()`, browser-independent — NOT `Sudo_Session::is_active()`,
  which requires a cookie-bound token) and **never on the operator's own row**.
- Multisite: acts on the current site's session meta; the `is_super_admin()`
  short-circuit in `wp_sudo_can()` is respected.

### Revoke-all — placement, scope, confirmation, empty-state
- **Placement:** a revoke-all button rendered on the **Users-list screen** (near
  the "Sudo Active" view / tablenav), contextually adjacent to the per-user
  actions — NOT on the Access tab (which is being separated from session concerns
  per REVK-04).
- **Shared code home:** promote the currently-private CLI
  `revoke_all_active_sessions()` into a **shared `Sudo_Session` method** so the UI
  and CLI run one code path.
- **Multisite scope:** **current-site scope** — revoke active sessions for the
  current site's users (the same enumeration the CLI method already uses, the same
  set the per-site "Sudo Active" view shows). Button renders on each site's Users
  list. True cross-site/network enumeration stays deferred (REVK-F3).
- **Confirmation:** an **interstitial confirm screen** — nonced link → a minimal
  native wp-admin `.wrap` page stating the exact count ("You are about to revoke N
  active sudo sessions on this site") with a primary **Confirm** button and a
  **Cancel** link → nonced POST to the admin-post handler. Consistent with the
  no-JS classic pattern and with how WP core confirms bulk user deletion.
- **Empty-state:** **absent** — render no revoke-all button when the active-session
  count is zero (gated by the same `_wp_sudo_expires > time()` count that drives
  "Sudo Active (N)"). Satisfies REVK-02's "absent or disabled" with the simpler
  branch.
- Revoke-all **excludes the operator's own session** (consistent with the row
  action excluding the operator's own row) so the operator stays in their sudo
  session to observe results and act further.

### Guardrails (REVK-03) — preserved on every path
- Every path enforces the `revoke_wp_sudo_sessions` capability, applies the
  per-revoker rate limit, and fires the `wp_sudo_session_revoked` audit hook.
- **Rate-limit accounting:** a single revoke-all counts as **one slot** against the
  10/hour per-revoker limit (regardless of how many sessions it kills); per-user
  row actions keep counting one each. Keeps the abuse guard meaningful without
  letting one legitimate incident-response action exhaust the budget.
- **Audit reason tags:** pass **distinct machine-readable reasons** to
  `wp_sudo_session_revoked` per surface — e.g. `users_list_row_action` for the
  per-user action and `revoke_all_ui` for the bulk action (CLI keeps its own tag) —
  so audit-log consumers / SIEMs can distinguish which surface performed the
  revocation.

### Feedback (REVK-05)
- **Three distinct, actionable messages**, one per blocked path:
  - operator lacks cap → "You don't have permission to revoke sudo sessions."
  - operator has no active sudo session → "Start a sudo session before revoking others."
  - target already expired → "That user no longer has an active sudo session."
- Plus a distinct **success** notice. Exact wording is Claude's discretion to
  finalize at implementation.
- **Accessibility:** render result notices via core **`wp_admin_notice()`**
  (WP 6.4+, already used in this plugin), with the correct success/error type,
  `is-dismissible`, and assistive-tech announcement (aria-live/role).

### AJAX-handler consolidation (cleanup)
- Because the row action uses the **admin-post** path and REVK-04 removes the only
  UI caller of the existing `wp_ajax_handle_revoke_session` AJAX action, that AJAX
  action is expected to become **dead code**. The clean shape is a **single
  `admin_post_` handler** (plus the shared `Sudo_Session` revoke-all method) as the
  one revocation entry point, with the orphaned AJAX action removed. The reusable
  core of the current `handle_revoke_session()` (cap check → rate limit → deactivate
  → audit hook) should be factored so the admin-post handler and the shared method
  call one path.

### Claude's Discretion
- Exact nonce/action names and `admin_post_` handler naming.
- Final wording of success/failure strings (within the distinct-per-path intent).
- Confirm-screen layout details within the "minimal native wp-admin page" choice.
- Whether the per-user and revoke-all admin-post handlers are one handler with a
  mode arg or two — implementation detail, provided guardrails are shared.

</decisions>

<specifics>
## Specific Ideas

- Mirror how WP core confirms bulk user deletion for the revoke-all interstitial.
- Keep capability administration (Access-tab holder table + grant form) and session
  revocation (Users list) visually and functionally separate — the core intent of
  the phase.
- The design brief
  (`.planning/todos/pending/2026-06-30-session-revocation-surfaces.md`) is the
  seed; its "Bundle in: governance coverage panel" section belongs to Phase 25, not
  this phase.

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Admin::handle_revoke_session()` (`includes/class-admin.php:1672`) — already
  enforces `wp_sudo_can('revoke_wp_sudo_sessions')`, the `REVOKE_RATE_PREFIX`
  10/hour rate limit, `Sudo_Session::deactivate()`, and the `wp_sudo_session_revoked`
  hook; takes `user_id`/`reason` from POST. Factor its core for reuse by the new
  admin-post handler.
- CLI `revoke_all_active_sessions()` (`includes/class-cli-command.php:123`) —
  `get_users(['fields'=>'ids','meta_key'=>Sudo_Session::META_KEY,'number'=>-1])`
  then `Sudo_Session::deactivate()` each. No `blog_id` → current-site scope on
  multisite. Promote to a shared `Sudo_Session` method.
- "Sudo Active" view + `filter_users_by_sudo_active()`
  (`class-admin.php:1063`, `:1134`, meta query at `:1176`) — the
  `_wp_sudo_expires > time()` enumeration to reuse for row-action visibility and the
  revoke-all count.
- `Sudo_Session::deactivate()` (`class-sudo-session.php:322`) — the actual session
  teardown.

### Established Patterns
- Admin JS is enqueued **only** on `settings_page_wp-sudo-settings`
  (`enqueue_assets`, `class-admin.php:986-990`) — `users.php` has no WP Sudo JS,
  which is why the row action uses the no-JS classic link pattern.
- `wp_admin_notice()` (WP 6.4+) is already used in the plugin for notices.
- AJAX access actions use the `wp_sudo_access` nonce and are themselves gated
  (revocation is a gated AJAX action today); the admin-post path must remain
  cap-gated + nonce-protected + itself sudo-gated.
- `user_row_actions` is **not** currently hooked anywhere
  (`includes/`, `admin/`) — net-new wiring in this phase.

### Integration Points
- New: `user_row_actions` filter (per-user link), `admin_post_{action}` handler(s)
  (perform + redirect), a Users-list tablenav hook for the revoke-all button +
  interstitial confirm screen.
- Removal: the Access-tab "Revoke Session" button (`class-admin.php:1421-1424`) and,
  after consolidation, the orphaned `wp_ajax_handle_revoke_session` action
  (registered at `class-admin.php:309`).
- Shared: a new `Sudo_Session` revoke-all method consumed by both the CLI command
  and the UI admin-post handler.

</code_context>

<deferred>
## Deferred Ideas

- **REVK-F1** — Access-tab "Active Sessions" panel (in-settings list of all
  session-holders with per-row revoke). v4.5 uses the Users-list row action instead.
- **REVK-F2** — Session metadata in the revoke UI (expiry countdown, bound context).
  Not needed to perform a revocation; potential info exposure.
- **REVK-F3** — True cross-site/network-wide selective revocation (per-site
  revocation from network admin, enumeration across all sites). v4.5 revoke-all is
  current-site-scoped.
- Governance-coverage panel multisite fixes — **Phase 25** (GCOV-01, GCOV-02), not
  this phase.

</deferred>

---

*Phase: 24-session-revocation-ui*
*Context gathered: 2026-06-30*
