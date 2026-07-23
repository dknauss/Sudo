# Changelog

## 4.8.0 - Unreleased

- **Readable governance capabilities on the user profile (UX):** WP Sudo grants its four governance capabilities (`manage_wp_sudo`, `view_wp_sudo_activity`, `export_wp_sudo_activity`, `revoke_wp_sudo_sessions`) directly to users, which WordPress core rendered as a run-on list of raw slugs under the profile's generic "Additional Capabilities" heading. That raw section is now suppressed **only when** those governance caps are the sole additional (non-role) capabilities the user holds — so another plugin's capabilities are never hidden, an already-hidden section is never force-shown, and an explicitly *denied* governance cap still surfaces as core's "Denied: …". In its place, a dedicated **"Sudo capabilities"** block lists each **directly granted** capability by its human-readable label (e.g. "Manage Sudo settings and policies"), matching the Access-tab wording. Role classification mirrors core (`wp_roles()->is_role()`), so a user's additional custom roles are not mistaken for stray capabilities, and the block reads the raw stored grants rather than an effective check — so a multisite super admin (who has effective access via the `wp_sudo_can()` short-circuit without a stored grant) is not misreported as holding caps they were never granted. The block links to Settings → Sudo → Access only for a viewer who can actually reach it (`manage_wp_sudo`); other viewers see plain informational text.
- **Security — REST `POST` to `/wp/v2/users` was ungated (fix):** the `user.change_password` and `user.promote` REST rules matched only `PUT`/`PATCH`, but WordPress registers the users update route under `WP_REST_Server::EDITABLE` (`POST, PUT, PATCH`). A cookie-authenticated `POST /wp/v2/users/{id\|me}` could therefore change a user's password or role with no reauthentication — and no effect-level backstop covers account mutations. Both rules now gate `POST`, including the `/me` route (`update_current_item`). Guarded by `tests/Unit/UserMutationRestMethodTest.php`.

## 4.7.0 - 2026-07-16

- **In-editor reauthentication modal — password path (new):** completing the in-editor capability whose server-side plumbing shipped in 4.6.0, a gated block-editor REST request soft-blocked with `sudo_required` now opens an in-place **"Confirm your identity"** password modal over the editor instead of only linking out to the challenge page. The modal grants the sudo session and the original request is transparently re-dispatched — no full-page redirect, and editor state (unsaved content, open panels) is preserved. The `apiFetch` middleware owns the intercept; re-dispatch is owner-scoped so concurrent editor requests replay against the granting user only, with stale-nonce recovery (re-minting via the 4.6.0 `wp_sudo_refresh_grant_nonce` endpoint) and graceful degradation back to the link-out snackbar when there is no safe in-editor path. The security invariant is preserved: a two-factor-enrolled account never receives a session from the password step alone.
- **In-editor two-factor — in-modal second factor (new):** accounts with a modal-capable Two Factor provider (TOTP, email, or backup codes) now complete the second factor **inside the same modal** used for the password step, rather than being sent to the full-page challenge. After the password step leaves the user in `2fa_pending` (`Sudo_Session::get_2fa_pending()`), the client fetches the provider's own server-rendered field from a logged-in, pending-gated AJAX endpoint (`wp_sudo_challenge_2fa_partial` → `handle_ajax_2fa_partial()`, which renders via a shared `render_two_factor_fields()` behind a private default-deny provider allowlist and honors the email provider's shared send-throttle), injects it into the modal, and validates it through the **unchanged** challenge validator before the owner re-dispatch — so the in-modal path enforces exactly the same second-factor check as the full-page flow. Providers without a modal-capable field still link out.
- **Full-page challenge nonce fix (bug):** the full-page 2FA submit path now strips the Two Factor provider's own `_ajax_nonce` before re-dispatch, so a stale provider nonce can no longer collide with the challenge submission (surfaced while building the in-modal path).
- **Demos & docs:** added the in-editor 2FA Playground blueprint (`blueprint-editor-2fa.json`, with rolling TOTP codes shown in an editor notice so the sandbox demo stays completable) and a multisite/network-admin scenario blueprint (`blueprint-multisite.json`); refreshed the readme with the in-editor 2FA modal screenshot and a fifth "Try multisite" Playground badge.

## 4.6.0 - 2026-07-06

- **In-editor reauthentication for the block editor (new):** when a block-editor REST request is soft-blocked with `sudo_required`, WP Sudo now surfaces an in-editor snackbar with a "Reauthenticate" action that opens the challenge page (instead of the editor dead-ending on an opaque 403). Editor state is preserved; the user grants a sudo session and retries. An `apiFetch` middleware detects the `sudo_required` code — including one nested in a `/batch/v1` envelope — and consumes the server-emitted `challenge_url` verbatim (never rebuilt in JS), degrading to a plain message if that URL is absent or not a same-origin http(s) URL. This is the **link-out increment**: the shipped client only opens the challenge page. The release also lands the server-side plumbing the forthcoming in-editor modal will use — grant-config localization (`wpSudoEditorReauth`) and a logged-in-only AJAX endpoint (`wp_sudo_refresh_grant_nonce`) that re-mints a fresh grant nonce for an editor left open past the ~24 h nonce lifetime (it grants nothing) — but **no shipped client code consumes it yet**. The in-editor password/2FA modal and automatic request re-dispatch are deferred to a later release.
- **Optional critical-event alert bridge (new):** `bridges/wp-sudo-critical-alert-bridge.php` is an optional mu-plugin that **pushes** a notification when a high-severity audit hook fires — capability tamper, blocked escalation, reauth lockout, and dropped built-in rules (plus opt-in, throttled recovery-mode) — where the packaged Stream/WSAL bridges only *log*. It emails the scope-appropriate admin out of the box, with a `wp_sudo_critical_alert_dispatch` filter to send Slack/Teams/webhook via `wp_remote_post` instead. Alerts are queued and dispatched on `shutdown` (so a slow send never delays the gate), deduped per identity, and capped per recipient (network-scope events to the network admin, site events to the site admin), with an overflow "N suppressed" digest. A demo companion (`bin/demo/wp-sudo-alert-inline-demo.php`) renders alerts inline for sandboxes without outbound network, e.g. WordPress Playground. The bridge carries its own `@version` and remains inert unless installed.
- **Harmonized user identity across admin surfaces (UX):** the dashboard Session Activity widget and the Settings → Sudo Access tab now present users identically — the user's **full real name** is the primary/prominent line, the **username** is secondary (linked to the user-edit screen when the operator can edit that user), shown with an avatar and translated role chip(s). Previously the Access tab showed only the display name plus a `<code>`-styled login (no avatar, no role), while the widget led with the username; the two have been unified. A shared `WP_Sudo\User_Identity` helper (`primary_name()` / `role_labels()`) is the single source of truth so the surfaces cannot drift, and role names are localized via `translate_user_role()`. Also fixes a latent no-op: the widget avatar passed `get_avatar()` a non-existent `force` argument, so it honored the site's "Show Avatars" (Discussion) setting instead of always rendering — corrected to `force_display`, so sudo-governance avatars render regardless of that setting.

## 4.5.0 - 2026-07-05

- **Access-tab capability table readability (UX):** the capability-holder table on the Access tab now renders each of a user's capabilities on its own line with its human-readable label (e.g. "Manage Sudo settings and policies") and the Revoke control paired directly to it, instead of a single run-on line of raw capability slugs with inline Revoke links that wrapped ambiguously at narrow widths. Capability slugs are no longer shown as prominent text anywhere on the tab — they move to a hover tooltip and a `screen-reader-text` span (still announced to assistive tech and available in the button's data attributes), and the Grant Capability dropdown shows the friendly label without the parenthetical slug. The revoke JavaScript now removes the whole capability item on success rather than a position-dependent sibling element, so a revoked capability's label can no longer linger as a stale entry until reload. Each Revoke button gains a capability-specific accessible name ("Revoke <label> capability") so screen-reader users tabbing through the otherwise-identical controls hear which capability each one affects, and the capability labels are now returned through the text domain so localized installs get translated names instead of English-only text.
- **Governance coverage panel fixes (multisite):** the Access-tab "Sudo governance coverage" panel now names the capability it actually scanned for — `manage_network_options` on multisite instead of a hardcoded `manage_options` — and no longer lists multisite super admins as unable to access Sudo settings (they always have effective access via the `wp_sudo_can()` short-circuit; the false positive required a stored `manage_network_options` grant, since capability queries cannot see the `site_admins` network option). Non-super-admin network operators with a stored grant but no `manage_wp_sudo` are still listed, and the raw-capability check keeps its recovery-mode-remap immunity.
- **Users-list bulk revocation replaces the revoke-all button (UX):** session revocation is now a native "Revoke sudo sessions" entry in the Users-list Bulk actions dropdown — filter to the Sudo Active view, select users, apply. The separate "Revoke all active sessions" toolbar button and its unstyled admin-post confirmation interstitial are removed; like core's password-reset bulk action, the explicit selection is the confirmation. The handler keeps the token-bound operator-sudo requirement, consumes exactly one rate-limit slot per batch, skips the operator's own row (with a visible "your own session was skipped" notice fragment), reports a selection with no live sessions distinctly, fires `wp_sudo_session_revoked` once per revoked user with the `users_list_bulk_action` reason tag, and preserves the operator's filter/pagination context on redirect. Site-wide revoke-everything remains available via `wp sudo revoke --all`. Hardening from external review (Codex): handling runs from a nonce-verified `load-users.php` interceptor because WordPress core does **not** nonce-check custom bulk actions on users.php (a crafted GET could otherwise reach the handler), and both the bulk path and the row action now enforce a current-site membership guard before consulting session state, so forged user IDs can neither revoke nor probe the network-global sudo sessions of other sites' users on multisite. The Users-list "Sudo Active (N)" badge count is now invalidated on session grant and teardown from every execution context (previously a 30-second-stale cache with no invalidation), and the stale `wp_sudo_revoke_session` AJAX reference was scrubbed from the `options.wp_sudo_access` registry rule.
- **Dashboard widget revocation visibility:** the Session Activity dashboard widget now records and displays `session_revoked` events — the bundled Event_Recorder subscribes to `wp_sudo_session_revoked`, storing the revoked user with the reason tag (`users-list` / `bulk-action`) visible in the surface column and the operator in context. `escalation_blocked` events also gain a human-readable "Escalation" label (previously rendered as the raw event type) and both event types get distinct pill styling. WP-CLI revocations do not fire the hook and remain outside widget visibility (documented in the developer reference).
- **Escalation-guard authority hardening (security):** the opt-in admin-escalation guard now requires the acting user to hold the promoting authority — `promote_users` for administrator grants (checked on the blog whose capabilities row is being written, so a cross-site handler cannot let an admin of one blog grant administrator on another), and existing super-admin status for `grant_super_admin` — *in addition to* an active/in-grace sudo session, before allowing the grant. Sudo is reauthentication, not authorization: a low-privilege account (subscriber/customer) can hold a sudo session, so requiring the session alone let the backstop wave through an escalation that reached a broken-access-control route. Blocked grants still fire `wp_sudo_escalation_blocked`.
- **Session revocation hardening (security):** both the Users-list "Revoke sudo session" row-action and the batch revocation path (now the "Revoke sudo sessions" bulk action) now require the operator's *token-bound* sudo session (`Sudo_Session::is_active()`) before revoking, instead of the browser-independent `is_session_live()` expiry check. A live `_wp_sudo_expires` timestamp with no valid request token — e.g. a stolen auth cookie or a second session without its own sudo — can no longer revoke other users' sessions. The row-action visibility still uses `is_session_live()` so the control shows with a distinct "start a sudo session" message. Deactivating another user's session no longer expires the operator's own browser `wp_sudo_token` cookie, so an operator can revoke several sessions in a row without being forced to re-challenge between them.
- **Two Factor profile-provider lifecycle bridge:** the optional Two Factor lifecycle bridge now also gates meaningful classic `profile.php` / `user-edit.php` provider lifecycle changes — enabling or disabling a provider, changing the primary provider, and TOTP-backed enrollment or removal — behind an active WP Sudo session. Unrelated profile saves and normalized no-op provider resubmissions are not gated. Profile replay for mixed profile plus Two Factor saves preserves source-verified core profile fields.
- **Localization packaging readiness:** added WP-CLI-backed Composer commands to regenerate and verify `languages/wp-sudo.pot`, committed the release-grade POT template, documented the workflow, and normalized a duplicate translator comment.

## 4.2.2 - 2026-06-28

- **Access tab polish:** the Grant Capability form now includes an explicit search field for administrator users, filtering the existing native select by display name/login while preserving exact numeric user ID option values and the unchanged AJAX grant payload.
- **Verification-gap closure:** refreshed canonical metrics, re-verified Phase 13.1, and updated the Access tab listing screenshot so public docs show the searchable picker.
- **Release planning hygiene:** refreshed planning/release docs to show that WordPress.org submission is intentionally delayed/on hold, while the repository remains submission-ready at any time.

## 4.2.1 - 2026-06-28

- **WordPress.org package readiness:** cleaned Pressship/Plugin Check input-handling findings by unslashing request values at the flagged sites and shortening the 4.0.0 upgrade notice to fit directory limits.
- **Submission warning triage:** reduced package validation to one documented slug warning (`wp-sudo` contains `wp`), added targeted notes/suppressions for intentional bridge, core-hook, and prepared-SQL false positives, and documented the slug decision in the WordPress.org submission checklist.
- **Release hygiene:** refreshed package metrics and Psalm baseline entries after the cleanup.

## 4.2.0 - 2026-06-27

- **Two Factor bridge hardening:** the optional Two Factor bridge now gates REST
  factor-management operations behind WP Sudo, extending sudo coverage to a
  sensitive 2FA account-control surface.
- **Observability (WSAL bridge expansion):** the optional WP Activity Log sensor
  bridge maps the additional security/governance audit hooks added in the 4.1.x
  line into WSAL events for escalation blocks, session revocation, recovery-mode
  use, governance-capability changes, missing built-in rules, and regex-rule
  failures.
- **Gutenberg REST UX groundwork:** cookie-authenticated REST `sudo_required`
  responses now include a `challenge_url` so block-editor clients can direct the
  user to reauthenticate without using server-side Request_Stash replay.
  Headless REST policy responses (`sudo_disabled` / `sudo_blocked`) are
  unchanged.
- **Test hardening:** added integration coverage for activation/deactivation
  lifecycle behavior, `WP_Session_Tokens::destroy_all()` login-session-binding
  invariants, and live admin-escalation guard hooks.
- **Planning and reference docs:** documented the Gutenberg route inventory, the
  build-free vanilla-JS decision for Phase 2 editor UX work, API-only
  configuration surfaces, and the accepted blog-invariant Connectors matcher
  cache behavior.

## 4.1.0 - 2026-06-24

- **Security (gate completeness):** Two coordinated-disclosure findings in the
  action-gating model are closed. Affected versions: ≤ 4.0.0 (both predate 4.0.0
  — F2 since the plugin's inception, F1 since the multi-surface gate).
  - **Interactive effect-level backstop.** The admin surface previously gated
    only by request-pattern matching against enumerated core pages, so a
    gated-equivalent destructive action invoked through a non-enumerated handler
    (e.g. a third-party `admin-post.php` route or custom dispatcher) could run
    without a sudo challenge — even though the identical action was blocked on
    WP-CLI and on core's enumerated REST routes. A session-aware backstop now
    arms on `admin_init` and hard-blocks the unambiguous destructive effect
    actions (`delete_user`, `delete_plugin`, `delete_theme`, `activate_plugin`,
    `upgrader_pre_install`, `export_wp`) when no sudo window is active. It is
    deliberately scoped to those effect hooks; the `pre_update_option_*` filters
    are excluded because WordPress core rewrites those options incidentally
    during ordinary admin loads. When blocked it fires `wp_sudo_action_blocked`
    on the `admin` surface with the real user ID. (Custom REST routes and the
    `user.create`/`user.promote` paths are tracked as follow-up increments.)
  - **Login-session binding.** The sudo proof is now bound to the WordPress
    login session that created it via the new `_wp_sudo_session_bind` user-meta
    key (SHA-256 of the login-session token). A captured `wp_sudo_token` cookie
    can no longer be replayed from another session; the session is ended on
    `wp_logout`, and a bound proof stops verifying once its login session is no
    longer valid (e.g. after `WP_Session_Tokens::destroy_all()` the user is no
    longer authenticated, so the window is unreachable). Binding is
    enforced only when a bind value is present, so existing sessions need no
    migration; cookie-less surfaces (CLI/cron/Application Passwords/WPGraphQL)
    carry no bind and remain governed by policy. The session is now also
    deactivated on `wp_logout`, and the login-session token is captured from
    `set_logged_in_cookie` so sessions granted during the login request bind
    correctly.
- **Security (admin-escalation guard, opt-in — default OFF):** A new role-aware
  guard refuses to grant **administrator** (single-site) or **super-admin**
  (multisite) unless the acting user holds an active (or in-grace) sudo session.
  It mitigates privilege-escalation through broken access control — including in
  third-party code — because in the common exploit shapes the attacker is
  unauthenticated or low-privilege and structurally cannot hold a sudo session,
  so the grant is blocked even when the vulnerable plugin's own permission check
  fails. Enable with `add_filter( 'wp_sudo_guard_escalation', '__return_true' )`.
  - **Effect-level and surface-agnostic.** Hooks the `{prefix}capabilities`
    user-meta write (`add_user_metadata`/`update_user_metadata`) and
    `grant_super_admin`, so it covers admin, REST, AJAX, and unauthenticated
    front-end writes with one mechanism. It fires **only** when a write *newly
    grants* administrator/super-admin to a user who does not already hold it —
    low-privilege role assignments, demotions, lateral changes, and idempotent
    self-edits pass untouched (evaluated against pre-mutation capabilities, so a
    sole admin re-asserting their own role is never blocked). On block it halts
    the request before the write persists (HTTP 403 / cron `exit`), never a
    short-circuit return.
  - **Respects operator policy.** Defers on CLI/Cron/XML-RPC (already governed by
    the non-interactive policy layer) and on a genuine **Unrestricted REST
    Application-Password** surface (audit-only), so it never contradicts an
    explicit entry-point setting.
  - **Escape hatches.** An allowlist filter (`wp_sudo_allow_escalation`) exempts
    a trusted provisioner (SSO/SAML/OIDC, directory sync), and the
    `WP_SUDO_ALLOW_ESCALATION` constant (checked first) covers deployment,
    migration, and sole-admin recovery.
  - **High-severity alarm.** A blocked escalation fires the distinct
    `wp_sudo_escalation_blocked` action and is recorded high-severity by the
    activity log, separate from routine policy denials, so external alerting can
    subscribe to just this case.
  - **Known limits (documented).** Runtime `user_has_cap`/`map_meta_cap` grants
    and raw `$wpdb` writes to the usermeta table bypass the meta hooks and are
    out of scope; the residual window is an escalation firing during a
    legitimate admin's own active sudo session.
## 4.0.0 - 2026-06-21

- **Breaking changes (4.0.0):**
  - **`sudo_can()` removed.** The deprecated unprefixed alias (deprecated in
    3.3.0) no longer exists; calling it is a fatal undefined-function error. Use
    `wp_sudo_can( string $cap, ?int $user_id = null ): bool` — identical
    signature. Search-replace any remaining `sudo_can(` calls with `wp_sudo_can(`.
  - **`compatibility` governance mode removed.** Governance is now always
    *strict* — capability checks delegate to `user_can( $user_id, $cap )` against
    the dedicated `manage_wp_sudo` family. Compatibility mode was added in 3.2.0
    as a transitional bridge when the dedicated-capability model replaced bare
    `manage_options` checks — it let sites keep the old `manage_options` authority
    while administrators were migrated onto the new caps, avoiding lockout before
    the backfill ran. It is removed now that the model is the established default,
    the 3.3.0 backfill grants the caps automatically, and the 3.4.0-hardened
    `WP_SUDO_RECOVERY_MODE` covers the lockout-recovery case — collapsing
    governance to one auditable path. A site that set
    `wp_sudo_governance_mode` is now treated as strict, and the inert option is
    **removed automatically** — no manual cleanup needed: `upgrade_4_0_0()` deletes
    it (both option stores on multisite) on the 3.x → 4.0.0 boundary, and an
    `admin_init` self-heal (`cleanup_inert_governance_mode_option()`) clears it if
    it reappears. After cleanup, an admin with `manage_wp_sudo` sees a one-time
    **dismissible** success notice (no persistent warning, no `_doing_it_wrong()`);
    the developer/audit signal is the `wp_sudo_inert_governance_mode_detected`
    action. `WP_SUDO_RECOVERY_MODE` remains the only break-glass path.
  - **Minimum WordPress raised to 6.4** (from 6.2).
  - **Minimum PHP raised to 8.2** (from 8.0). `composer.json` now requires
    `php >=8.2`, and the CI matrix drops the 8.0/8.1 lanes.
- **Connector credential writes gated on WordPress 7.0 (registry-aware
  matcher):** the `connectors.update_credentials` rule now matches connector
  API-key writes to `POST /wp/v2/settings` with a two-tier matcher. Tier 1
  reads the WordPress 7.0 Connectors registry (`wp_get_connectors()`, guarded
  by `function_exists()`) and gates every setting name belonging to an
  `api_key`-method connector; Tier 2 retains the existing
  `^connectors_[a-z0-9_]+_api_key$` regex as a union fallback. This closes a
  false-negative where connectors whose setting name does not match the regex —
  notably Akismet's `wordpress_api_key` — were ungated on every WP 7.0 install.
  The registry read is cached per request and re-read after
  `Action_Registry::reset_cache()`. Verified against WordPress 7.0 GA.
  (CONN-01 through CONN-06.)
- **WordPress 7.0 upgrade-path fatal fixed:** `Upgrader::maybe_upgrade()` now
  primes `wp_roles()` before running migration routines. On WordPress 7.0,
  `WP_User_Query` dereferences the global `$wp_roles` for capability queries,
  which is not yet initialized at `plugins_loaded` under WP-CLI/cron — so the
  `upgrade_3_3_0()` governance backfill (a
  `get_users( array( 'capability' => 'manage_wp_sudo' ) )` call) fataled with
  `Call to a member function for_site() on null` when a site upgraded across the
  3.3.0 boundary in a non-interactive context. Priming the global once makes the
  upgrade path safe regardless of how early it fires.
- **E2E CI balancing:** the default Chromium Playwright workflow now uses four
  explicit test groups instead of Playwright's opaque `--shard` assignment. The
  heavy challenge-flow tests are split across basic/admin, 2FA UI,
  lockout/surface, and replay/multisite groups while preserving the aggregate
  `E2E Tests` required status check.
- **WordPress.org readiness:** the listing name is set to **"Sudo – Admin Action
  Gating"** (plugin header + readme title; the in-product UI brand stays "Sudo"
  and the slug/text-domain stay `wp-sudo`). Adds `SECURITY.md` and a
  WordPress.org submission checklist, trims the readme short description to the
  150-character limit, and reconciles the request-stash redaction status (the
  shipped matcher is exact-match **plus** suffix-based via
  `SENSITIVE_KEY_SUFFIXES`, not exact-key only). Plugin Check (PCP) passes the
  plugin-name, trademark, and stable-tag rules. (ORG-01 through ORG-07.)
- **Screenshots (9):** a deterministic, env-gated Playwright capture spec
  (`npm run screenshots`) regenerates the nine `.wordpress-org` listing
  screenshots — challenge page, gated plugin activation, the four settings tabs
  (Settings, Gated Actions, Rule Tester, Access), the Session Activity dashboard
  widget, the admin-bar session timer, and the break-glass recovery notice —
  matching the readme `== Screenshots ==` captions one-to-one.
- **Manual release environment matrix:** `tests/MANUAL-TESTING.md` gains a
  release environment matrix (an Apache stack, a managed WordPress host, and the
  minimum supported WordPress version) plus a Connectors-credential manual
  verification — cookie-auth and Application Password writes to
  `POST /wp/v2/settings` with connector credential fields, including
  `wordpress_api_key`. (ENV-01 through ENV-03.)

## 3.4.0 - 2026-06-13

- **Break-glass recovery mode hardened (role-gated + visible):** `WP_SUDO_RECOVERY_MODE` previously granted the master `manage_wp_sudo` capability to any logged-in user. The grant is now role-gated — it applies only to users who also hold `manage_options` (single-site) or `manage_network_options` (multisite), so a locked-out administrator recovers while subscribers, editors, and other non-admins gain nothing. A permanent, non-dismissible notice now renders on the Sudo settings screen while recovery mode is active, and a new `wp_sudo_recovery_mode_active` audit hook fires (stored as a sampled `recovery_mode` event, at most one per user per hour), so break-glass usage is explicit, bounded, and auditable.
- **New audit hook `wp_sudo_recovery_mode_active`:** fires on each Sudo admin-page load while recovery mode is active. See the developer reference for the signature.
- **Psalm gate repaired:** the type-coverage gate had been silently passing without analyzing anything — a top-level `exit` in `uninstall.php` aborted the Psalm run (exit 0, zero output). `uninstall.php` is now excluded from analysis, a guard fails the gate loudly if no type-coverage figure is emitted, and the shepherd.dev type-coverage badge reports again (~96%).
- **CI hardening:** least-privilege `permissions` blocks added to all workflows; documentation-only pull requests now skip the heavy unit/integration/Psalm/CodeQL/E2E jobs without deadlocking the required status checks.
- **Documentation audit:** a feature-implementation audit reconciled the docs with the code — corrected confabulated AJAX handler names and the OTP-resend rate-limit description in the changelog, and replaced drift-prone hardcoded counts (audit hooks, help tabs) with links to `docs/current-metrics.md`.
- **Playground demos:** added recovery-mode and user-switching scenario blueprints for manual review.
- **Fix:** removed an obsolete Editor role-error notice workaround in the admin UI; fixed a random-order unit-test flake in the SSL-detection path.

## 3.3.0 - 2026-06-12

- **Governance backfill re-keyed to 3.3.0 (fixes strict-mode lockout):** the migration that grants `manage_wp_sudo` and the other governance capabilities to existing single-site administrators was keyed at `3.1.0` — a version that never had a public release (tags went v3.1.1 → v3.1.3 → v3.2.0). Sites upgrading from any public 3.1.x release skipped the backfill, leaving no `manage_wp_sudo` holders and locking administrators out of Settings → Sudo in the default strict governance mode (recovery only via `WP_SUDO_RECOVERY_MODE`). The routine is now keyed at `3.3.0` so it also runs once for sites already stamped `3.2.0`, and it skips when any user already holds `manage_wp_sudo`, preserving deliberate Access-tab grant/revoke configurations.
- **Audit column clamping:** `Event_Store` now clamps `event`, `rule_id`, `surface`, and `ip` values to their schema column widths before insert, so over-length values from third-party rules truncate predictably in PHP instead of erroring (strict MySQL, dropping the audit row) or truncating silently in the database.
- **`wp_sudo_grant_session_on_login` filter:** the automatic sudo session granted on browser login can now be suppressed (return `false`) for shared-terminal/kiosk hardening or SSO integrations. Default behavior is unchanged. Note for SSO integrators: suppressing the grant for users without a usable WordPress password makes gated actions unreachable for them — see the developer reference. Closes audit register item F17.

## 3.2.0 - 2026-06-08

### Governance and capabilities

- **`sudo_can()` helper:** Replaces direct `manage_options` checks across the plugin with a fine-grained capability helper that maps to the WordPress capabilities system. `wp_sudo_is_recovery_mode()` allows capability checks to short-circuit during emergency recovery flows.
- **Access tab:** Settings → Sudo gains an Access tab for managing which roles and users can administer sudo settings. `wp_sudo_grant_cap` and `wp_sudo_revoke_cap` AJAX handlers are registered and gated by the new `options.wp_sudo_access` rule; audit hooks fire on both grant and revoke.
- **WordPress capability integration:** Sudo capability assertions are now mapped to standard WordPress capability checks so external tools (WP-CLI, audit plugins) can evaluate them through `current_user_can()`.
- **Capability-gated grant/revoke actions:** `grant_cap` and `revoke_cap` AJAX actions are now registered in the action registry and gated by the challenge flow.

### Security hardening

- **2FA lockout integrity:** A correct password no longer clears failed-attempt counters while the 2FA challenge is still pending, so repeated password + bad-2FA cycles accumulate toward the intended lockout. Pending 2FA transients are cleared at the start of each new password submission to prevent orphaned transient accumulation. OTP resend requests are now rate-limited by a per-user resend count-cap over a 5-minute window to prevent email flooding via the Two Factor Email provider.
- **WPGraphQL mutation detection:** Limited-mode fallback classification now decodes JSON bodies, GET/form `query` params, and multipart `operations` payloads; catches JSON-escaped and batched mutations; fails safe on unknown persisted operations; and avoids blocking queries that only mention `mutation` in string arguments. CR and CRLF line terminators in GraphQL comments are now handled per spec, closing a tokenizer bypass. Escaped triple-quotes inside block strings are now parsed correctly. UTF-8 BOM prefixes are stripped before JSON decoding. Persisted-query / APQ requests with no inline document body are treated as mutations by default; a filter allows read-only persisted ops to opt out.
- **REST plugin gate covers folder-based plugins:** The `plugin.activate`, `plugin.deactivate`, and `plugin.delete` REST matchers now use `[^/]+(?:/[^/]+)?` to match folder-style plugin slugs (e.g. `akismet/akismet`). Previously, the majority of real-world plugins using a folder layout were silently ungated on the REST surface.
- **PCRE fail-closed for built-in rules:** A PCRE error on a built-in rule now gates the request (fail-closed) instead of silently passing it. `wp_sudo_rule_regex_error` fires for observability.
- **Non-interactive surface coverage for plugin settings:** The `wp_sudo_settings` option write is now blocked on CLI, Cron, and XML-RPC surfaces with an audit hook on interception, preventing unprompted policy downgrades from non-interactive contexts.
- **Admin email gating:** `new_admin_email` and `admin_email` field writes are now challenge-gated on interactive and REST surfaces. Admin email retargeting is a recognized account-takeover precursor.
- **Per-user IP lockout:** Failed-attempt IP lockout is now keyed on `ip + user_id` instead of IP alone. A single account on a shared egress IP (NAT, VPN, CGNAT) can no longer sustain a DoS against all admins sharing that IP.
- **2FA challenge path checks IP lockout:** `handle_ajax_2fa()` now checks the per-IP lockout at entry, matching the behavior of the password submission path. Previously, an active IP lockout was honored at the password step but ignored at 2FA entry.
- **Cookie Secure flag hardening:** Session and 2FA cookies now respect `FORCE_SSL_ADMIN` as a Secure-flag fallback when `is_ssl()` returns false (e.g. behind a TLS-terminating reverse proxy without `X-Forwarded-Proto`). A `wp_sudo_cookie_secure` filter allows operator override. Previously the Secure flag was absent in these configurations.
- **REST auth surface hardening:** The REST interceptor now requires the request to be unauthenticated via App Password before trusting the cookie-auth branch, preventing a pivot where a request carries both cookie credentials and an App Password.
- **Request stash minimization:** Challenge replay now stores only rule-allowlisted POST fields, never stores `$_GET` separately, redacts compound secret names by suffix, and blocks automatic replay for unsafe or incomplete POST bodies.
- **App Password policy validation:** Per-App-Password policy overrides now require UUID v4 format validation and confirmed existence in `WP_Application_Passwords` before persisting. Policy entries are automatically removed when the corresponding App Password is deleted via the `wp_delete_application_password` hook.
- **Dashboard widget capability check:** The active-sessions user edit link is now only emitted when the current user holds `edit_user` for the target, preventing link exposure on multisite where site admins cannot edit network users.
- **Public API cross-user isolation:** `Public_API::check()` now explicitly returns false when the target user ID differs from `get_current_user_id()`, making the cross-user isolation guarantee explicit at the API boundary rather than relying on internal session-token rejection alone.
- **Built-in rule filter visibility:** The `wp_sudo_gated_actions` filter now emits a diagnostic action and Site Health warning when built-in rule IDs are missing after filtering. Intentional removal remains supported, but accidental wholesale erasure is visible to operators.
- **Uninstall defense-in-depth:** `uninstall.php` now asserts `delete_plugins` for browser/admin execution while preserving WP-CLI uninstall behavior alongside the WordPress-provided `WP_UNINSTALL_PLUGIN` sentinel.

### CLI and diagnostics

- **`wp sudo status` output:** The status command now clarifies that the "active" state reflects the stored expiry timestamp only — token binding cannot be verified from CLI without cookie access.

### Developer experience

- **Inline script encoding:** Dashboard widget inline JS i18n now uses `JSON_HEX_TAG | JSON_HEX_AMP` encoding flags so `<script>` injection safety is explicit rather than relying on PHP's incidental `\/` escaping of forward slashes.
- **WPGraphQL block-string escaping documented:** `developer-reference.md` now documents that the only GraphQL block-string escape sequence is `\"""` (escaped triple-quote); `\\` has no special meaning inside block strings.
- **Admin help copy clarifies auth boundaries:** Contextual help and public docs now distinguish reauthentication from authorization: Sudo verifies the current user is still the account holder; WordPress and target handlers still decide whether that user is allowed to perform the action.
- **E2E CI acceleration:** The default Chromium Playwright suite now runs in four GitHub Actions shards with a preserved aggregate `E2E Tests` status check, and the roadmap captures follow-up cache/image/smoke-split options.

## 3.1.3 - 2026-05-11

- **Release Playground link:** the stable release Blueprint installs the tag ZIP through `pluginData` instead of using Playground's currently brittle `git:directory` tag fetch path.
- **Playground link posture:** README Playground links now distinguish the immutable latest-release demo from the current `main` demo.
- **Blueprint password seeding:** the demo Blueprint now uses WordPress core's `wp_set_password()` API instead of writing the password hash directly through `$wpdb`.

## 3.1.2 - 2026-05-11

### Playground and preview fixes

- **Playground authentication:** the demo Blueprint now resets the `admin` user password before login so `admin` / `password` works for both WordPress login and WP Sudo reauthentication.
- **Front-end toolbar cancellation:** clicking the Sudo toolbar item now cancels an active sudo session from front-end admin-bar contexts without navigating away unexpectedly.
- **Dashboard widget freshness:** active-session transients are invalidated when sessions are cancelled, so the dashboard widget updates without needing a manual refresh.
- **Demo activity:** Playground now seeds recent privilege-action samples and active demo sudo sessions with staggered 5-15 minute durations.
- **PR preview links:** the Playground Preview workflow now uses the checked-in Blueprint and pins the plugin install to the PR commit through Playground's CORS-safe `git:directory` resource.

## 3.1.1 - 2026-05-11

### Security hardening

- **Role-change interception:** role and capability metadata writes are now blocked before mutation when they require an active sudo session, closing the gap where non-interactive role changes could be detected only after the write path.
- **Sensitive request replay safety:** intercepted requests that include password/secret fields no longer replay partial POST data after those fields are omitted from the stash; users are returned with a warning instead.
- **MU-plugin loader resilience:** copied MU shims now preserve the actual plugin loader path, and the static shim can recover when the plugin directory is renamed.
- **Audit bridge parity:** Stream and WP Activity Log bridges now include `wp_sudo_action_passed` events, keeping active-session approvals visible in downstream audit logs.

### Compatibility and tooling

- **PHP 8.0 test compatibility:** reflection-based unit tests now avoid PHP 8.1-only reflection behavior when running under PHP 8.0.
- **NPM security audit cleanup:** updated vulnerable transitive development dependencies, including `fast-xml-parser` via the repo override, and cleared the npm audit report.

### Documentation

- **Release posture:** refreshed WordPress 7.0 schedule references and kept public metadata aligned with WordPress 6.9 as the latest stable line until 7.0 final ships.

## 3.0.0

### Headline changes

- **Major milestone: operator tooling and visibility** — WP Sudo now includes a **Request / Rule Tester** for representative admin, AJAX, and REST request shapes plus a **Session Activity Dashboard Widget** for active sessions, recent events, and current policy posture.
- **Major milestone: policy control** — Settings → Sudo now includes one-click **Normal**, **Incident Lockdown**, and **Headless Friendly** presets for the non-interactive surfaces, with confirmation, audit logging, and summary notices.
- **Major milestone: ecosystem hardening** — Connectors API credential writes saved through `/wp/v2/settings` now require sudo when they include `connectors_*_api_key` fields, protecting database-backed connector credentials without over-gating unrelated settings writes.

### New platform capabilities

- **Event persistence layer** — audit events are now recorded through `Event_Store` and `Event_Recorder`, enabling the dashboard widget and future reporting. The shared `wpsudo_events` table includes 14-day retention, daily cron pruning, graceful degradation when the table is unavailable, and SQLite compatibility for Playground-style environments.

### Security and recovery hardening

- **Challenge lockout expiry recovery** — corrected an edge case where the visible countdown could reach zero while the server still treated the lockout as active for that exact second, blocking an immediate retry. Password and IP lockouts now expire in sync with the countdown.
- **Stale challenge and 2FA recovery flows** — hardened recovery when a sudo session is already active or a user is returning from 2FA throttle/lockout flows, with expanded browser coverage for replay, resend, cancel, and recovery behavior.

### Dashboard widget UX

- **Active sessions: identity context** — sessions panel now shows gravatars, username, role badge, display name, and time remaining for each active session. Responsive layout hides gravatars and names on small screens.
- **Recent events: client-side filtering** — dropdown filters for Time (1h / 24h / 7d), Event type, and Surface, applied client-side against 50 stored events. Filters laid out horizontally in a single row.
- **Passed-event audit visibility defaults** — `wp_sudo_action_passed` events (admin, REST, WPGraphQL) are now recorded by default so active-session actions stay visible in the audit timeline. Disabling passed-event logging now requires an explicit code override (constant/filter), and WP Sudo shows a warning notice when that override is active.
- **Widget placement and layout** — widget renders in the side column at high priority, active session cards use CSS Grid (`repeat(auto-fit, minmax(180px, 1fr))`) with scrollable container, usernames link to user-edit.php, and the empty-state panel now uses a clearer Site Health–style status layout.
- **Users list "Sudo Active" filter** — the Users → All Users screen gains a "Sudo Active (N)" view link that filters the list to users with an active sudo session via `_wp_sudo_expires` meta query.

### Accessibility

- **Dashboard widget table semantics** — added `scope="col"` to table headers and screen-reader-only `<caption>` elements for the Recent Events and Policy Summary tables.

### Compatibility and testing

- **WordPress 7.0 readiness** — forward test and preview lanes are now pinned to `7.0-RC1`, with RC1 visual signoff recorded and the remaining RC/GA checklist documented for final release-day verification.
- **Testing and compatibility breadth** — added scheduled WordPress `6.3`–`6.6` compatibility coverage, explicit nginx + php-fpm + MariaDB and Playground SQLite browser smoke workflows, and a dedicated nginx + MariaDB multisite smoke lane.
- **Testing workflow: local integration fallback** — `composer test:integration` now falls back to the running `wp-env` `tests-cli` container when a local rebuild leaves the generated host-side MySQL endpoint stale, while CI continues to use the normal direct PHPUnit path.
- **Testing posture:** expanded CI and browser coverage shipped with this release; live suite counts are tracked in `docs/current-metrics.md`.

## 2.14.0

- **Feature: Playwright end-to-end coverage** — added browser-verified challenge, cookie, gate UI, admin bar timer, keyboard shortcut, MU-plugin AJAX, multisite network-admin, and visual-regression coverage to exercise the real user flows around reauthentication.
- **Fix: multisite symlink and network-admin flow hardening** — preserved network-admin return URLs and supported symlinked local multisite installs used in Local and Studio-style development.
- **Fix: bootstrap plugin URL handling** — plugin asset URLs now preserve normal `plugins_url` filtering and custom plugin roots instead of assuming a fixed `/wp-content/plugins/` path.
- **Testing workflow: Local socket support** — `bin/install-wp-tests.sh` can now auto-detect a single Local by Flywheel MySQL socket when TCP MySQL is unavailable, with updated contributor guidance for local integration setup.
- **Repo hygiene** — added GPL license and repository health files, and centralized live test/size counts in `docs/current-metrics.md`.
- **504 unit tests, 1311 assertions. 140 integration tests in CI.**

## 2.13.0

- **Feature: IP + user multidimensional rate limiting** — failed authentication attempts are now tracked per-IP alongside per-user. When the same IP address triggers failures across multiple user accounts, the IP itself is locked out, mitigating credential-stuffing attacks that rotate usernames. The `wp_sudo_lockout` hook now includes the triggering IP address as a third positional argument (`$user_id, $attempts, $ip`) for audit visibility.
- **Docs alignment** — updated `security-model.md` with the new rate-limiting dimensions and `developer-reference.md` with the enriched lockout hook payload schema. Manual testing guide expanded with IP-based lockout verification steps.
- **496 unit tests, 1293 assertions. 132 integration tests in CI.**

## 2.12.0

- **Feature: WP-CLI operator commands** — added `wp sudo status`, `wp sudo revoke --user=<id>`, and `wp sudo revoke --all` for session inspection and revocation workflows.
- **Feature: Stream audit bridge** — added optional `bridges/wp-sudo-stream-bridge.php`, mapping all 9 WP Sudo audit hooks into Stream records. Bridge remains inert when Stream APIs are unavailable and supports late plugin load order.
- **Feature: public integration API (`wp_sudo_check()` / `wp_sudo_require()`)** — added first-party helpers for third-party plugins/themes to require an active sudo session without registering full action rules. `wp_sudo_require()` can redirect to the challenge page in session-only mode (or return `false` when redirecting is disabled/unavailable) and emits `wp_sudo_action_gated` with surface `public_api`.
- **Docs: release alignment** — updated developer reference and manual testing docs for Stream bridge and public API helpers; refreshed roadmap and contributing guidance for current development priorities and repo-local integration test paths.
- **Pre-release hygiene** — regenerated `bom.json`.
- **494 unit tests, 1286 assertions. 135 integration tests in CI.**

## 2.11.1

- **Docs release + metadata alignment** — corrected post-v2.11.0 documentation drift: roadmap completion markers, RC re-test guidance, and release notes alignment across `CHANGELOG.md`, `readme.md`, and `readme.txt`.
- **Version annotation fixes** — corrected `@since` annotations introduced in the v2.11.0 development cycle so Phase 3/4 additions no longer reference the nonexistent `2.10.3` version.
- **Pre-release hygiene** — regenerated `bom.json` and updated ignore rules to keep `.planning/private-reference/`, `.composer_cache/`, and `vendor_test/` out of commits.
- **478 unit tests, 1228 assertions. 130 integration tests in CI.**

## 2.11.0

- **Phase 3 complete: Action Registry schema validation hardening** — filtered `wp_sudo_gated_actions` rules are now normalized and validated before caching, preventing malformed third-party payloads from reaching gate matchers.
- **Phase 3 complete: MU-loader resilience** — loader basename/path resolution now follows an explicit fallback chain and correctly respects active plugin state in single-site and multisite environments.
- **Phase 4 complete: WPGraphQL persisted-query strategy** — GraphQL policy behavior was tightened and documented for persisted-query/headless setups, with expanded integration coverage of mutation classification and bypass behavior.
- **Phase 4 complete: WSAL sensor bridge** — added `bridges/wp-sudo-wsal-sensor.php`, mapping all 9 WP Sudo audit hooks to WP Activity Log events for security telemetry integration.
- **Housekeeping: Admin bar class cleanup** — docblock trimming, explicit `$accepted_args` on hook registrations, no behavioral changes.
- **Docs and planning closure** — phase summaries and roadmap/planning artifacts updated to reflect completion across Phases 1–4 of the security hardening sprint.
- **478 unit tests, 1228 assertions. 130 integration tests in CI.**

## 2.10.2

- **Fix: multisite uninstall orphaned MU-plugin shim and user meta** — when a network-activated plugin was uninstalled, the early-return path skipped `wp_sudo_cleanup_mu_shim()` and `wp_sudo_cleanup_user_meta()`, leaving the shim file and session metadata in the database after plugin deletion. Multisite uninstall now unconditionally cleans all sites and all network-wide data.
- **Fix: `wp_sudo_version` option not deleted on uninstall** — `wp_sudo_cleanup_site()` deleted four options but missed `wp_sudo_version`, leaving an orphan row. Also added the missing `delete_site_option( 'wp_sudo_role_version' )` to the multisite network cleanup path.
- **Fix: `Admin::get()` TypeError on PHP 8.2+ with corrupted settings** — `get_option()` returning `false` (from corrupted serialized data) was assigned to a `?array` typed property, causing a TypeError. Now validates the return with `is_array()` and falls back to defaults.
- **Fix: `Gate::matches_rest()` crash on invalid third-party regex** — third-party filters on `wp_sudo_gated_actions` could inject rules with malformed regex patterns, causing `preg_match()` warnings. New `safe_preg_match()` wrapper catches the warning and fails closed (rule does not match).
- **Psalm 6.15.1 static analysis** — added alongside PHPStan for dual static analysis. Psalm surfaced the `absint()` → `(int)` cast fix in Admin and the `wp_safe_redirect()` fallback in Gate (both shipped in v2.10.0 but caught by Psalm). Type coverage published to Shepherd.dev on default-branch pushes.
- **Codecov integration** — unit test coverage uploaded to Codecov on CI runs.
- **16 new unit tests** closing gaps in CLI cron-policy enforcement, network activation lifecycle, network admin settings save, admin bar deactivation handler, transient storage failures, cookie/token edge cases, and 2FA provider availability.
- **Dependency bumps** — PHPStan 2.1.40, Yoast PHPUnit Polyfills 4.0.0, actions/checkout v6, actions/cache v5, actions/upload-artifact v7, actions/github-script v8.
- **CodeQL and Dependabot** — CodeQL JavaScript security scanning enabled; Dependabot version updates for Composer and GitHub Actions.
- **428 unit tests, 1043 assertions. 92 integration tests in CI.**

## 2.10.1

- **Fix: accessibility audit follow-up** — admin bar countdown polish, docs alignment.
- **397 unit tests, 944 assertions. 92 integration tests in CI.**

## 2.10.0

- **Feature: WebAuthn gating bridge** — gates WebAuthn key registration and deletion via `wp_sudo_gated_actions` filter when the Two Factor WebAuthn plugin is active.
- **Fix: WP 7.0 notice CSS** — corrected admin notice styling for WordPress 7.0 compatibility.
- **Fix: MU-plugin shim respects deactivation** — the loader now checks `active_plugins` / `active_sitewide_plugins` before loading the plugin; inert when deactivated.
- **Fix: localize app-password JS** — moved inline script to localized data; paginate stale sessions in Site Health; fix return_url handling.
- **Fix: clamp 2FA window filter** — `wp_sudo_two_factor_window` filter output clamped to documented 1–15 minute bounds.
- **REST `_wpnonce` fallback** — Gate accepts `_wpnonce` query parameter for REST authentication when cookie nonce header is absent.
- **Exit path integration tests** — new test suite for security-critical exit paths (REST 403, AJAX 403, admin redirect, challenge auth, grace window).
- **PCOV coverage CI job** — unit test coverage generation added to CI pipeline.
- **Docs: NIST SP 800-63B terminology alignment** — reauthentication language updated throughout.
- **397 unit tests, 944 assertions. 92 integration tests in CI.**

## 2.9.2

- **Fix: 2FA help text corrected** — `includes/class-admin.php` displayed "The default 2FA window is 10 minutes" but the code default (set in v2.4.0) is `5 * MINUTE_IN_SECONDS`. Help text now reads "5 minutes". The sudo session countdown (admin bar) is a separate, unrelated timer that remains at 15 minutes.
- **Fix: version constant drift in dev bootstrap files** — `phpstan-bootstrap.php` and `tests/bootstrap.php` both defined `WP_SUDO_VERSION = '2.8.0'` while the runtime plugin was at v2.9.1. Both bumped to `'2.9.2'`.
- **Docs: readme.txt expanded** — Patchstack 2026 attack statistics (57% BAC, 80% sudo-mitigated, 5 h median exploit time) added to the Description section. Eight new FAQ entries added: what problem Sudo solves, how it differs from security plugins, limitations, brute-force protection, login session grant, password change behaviour, grace period, and the 2FA verification window. Integration and unit test counts corrected.
- **397 unit tests, 944 assertions.**

## 2.9.1

- **Docs: threat model kill chain** — `docs/security-model.md` gains a new "Threat Model: The Kill Chain" section with verified statistics from Patchstack (2024 vulnerability breakdown), Sucuri (post-compromise forensics), Verizon DBIR (credential attacks), Wordfence (55B password attacks blocked), and OWASP Top 10:2025 (Broken Access Control #1). Risk reduction estimates table included. `FAQ.md` adds a condensed "Why this matters by the numbers" paragraph. All statistics verified 2026-02-27 against primary sources.
- **Docs: project size table** — `readme.md` gains a "Project Size" subsection (6,688 production lines, 11,555 test lines, 1.7:1 ratio). `CLAUDE.md` gains verification commands and a pre-release checklist note to keep the table current. Stale test counts in `readme.md` corrected (375/905 → 397/944, 73 → 92 integration). Missing v2.8.0 and v2.9.0 changelog entries added to `readme.md`.
- **397 unit tests, 944 assertions.**

## 2.9.0

- **Feature: `wp_sudo_action_allowed` audit hook** — fires when a gated action is permitted by an Unrestricted policy. Covers all five non-interactive surfaces: REST App Passwords (`$user_id, $rule_id, 'rest_app_password'`), WP-CLI (`0, $rule_id, 'cli'`), Cron (`0, $rule_id, 'cron'`), XML-RPC (`0, $rule_id, 'xmlrpc'`), and WPGraphQL (`$user_id, 'wpgraphql', 'wpgraphql'` — mutations only). WPGraphQL queries do not fire the hook. Implemented by adding an `'audit'` mode to `register_function_hooks()` for CLI/Cron/XML-RPC, and inline `do_action()` calls for REST and WPGraphQL. This is the ninth audit hook.
- **Docs: CLAUDE.md accuracy audit** — corrected six inaccuracies: policy names, missing doc reference, missing password-change hooks in bootstrap sequence, rule count ambiguity, and hook count. Logged one confabulation (fabricated `wp_sudo_action_allowed` documentation in `ai-agentic-guidance.md`) in `llm_lies_log.txt`.
- **Docs: manual testing** — MANUAL-TESTING.md adds §19 (Unrestricted audit hook verification for all five surfaces) with forward references from existing Unrestricted subsections.
- **397 unit tests, 944 assertions.**

## 2.8.0

- **Feature: expire sudo session on password change** — hooks `after_password_reset` (lost-password flow) and `profile_update` (admin profile, user-edit, REST API) to invalidate any active sudo session when a user's password changes. Handlers guard with a meta-existence check before calling `deactivate()` to avoid phantom `wp_sudo_deactivated` audit events for users without sessions. Closes the gap where a compromised session persisted after a password reset.
- **Feature: WPGraphQL conditional display** — the WPGraphQL policy dropdown on Settings > Sudo, the WPGraphQL paragraph in the "Session & Policies" help tab, and the Site Health policy review all adapt based on whether WPGraphQL is installed. When inactive: the dropdown is hidden, the help tab shows an install note instead of the full explanation, and Site Health does not flag the WPGraphQL policy.
- **Docs: WPGraphQL surface-level gating rationale** — `docs/developer-reference.md` Rule Structure section now explains why WPGraphQL is gated at the surface level rather than per-action, with a forward reference to the WPGraphQL Surface section.
- **Docs: manual testing additions** — MANUAL-TESTING.md adds §16.0 (WPGraphQL conditional behavior when plugin inactive) and §18 (password change expires sudo session — three scenarios).
- **391 unit tests, 929 assertions.**

## 2.7.0

- **Feature: `wp_sudo_wpgraphql_bypass` filter** — fires in Limited mode before mutation detection. Return `true` to allow a request through without sudo session checks. Solves compatibility with [wp-graphql-jwt-authentication](https://github.com/wp-graphql/wp-graphql-jwt-authentication): the JWT `login` mutation is sent by unauthenticated users and was blocked by the default Limited policy, breaking the entire JWT authentication flow. A documented bridge mu-plugin exempts `login` and `refreshJwtAuthToken` mutations while keeping all other mutations gated. The filter does not fire in Disabled or Unrestricted mode.
- **Fix: WPGraphQL now listed in non-interactive entry points** — the "How Sudo Works" help tab text omitted WPGraphQL from the list of non-interactive surfaces.
- **379 unit tests, 915 assertions.**

## 2.6.1

- **Fix: WPGraphQL integration tests now call `check_wpgraphql()` directly** — `Gate::check_wpgraphql( string $body ): ?WP_Error` extracted from `gate_wpgraphql()` so integration tests can exercise the policy logic without WPGraphQL installed or `wp_send_json()`/`exit` side effects. No behavioral change in production. Fixes a pre-existing CI regression introduced in v2.5.1 when WPGraphQL gating moved from `rest_request_before_callbacks` to `graphql_process_http_request`.
- **Docs: full documentation update for v2.6.0** — FAQ, ROADMAP, readme.txt, readme.md, developer-reference.md, security-model.md, MANUAL-TESTING.md, CLAUDE.md updated to reflect all v2.6.0 features (login grant, password-change gating, grace period).
- **375 unit tests, 905 assertions. 73 integration tests in CI.**

## 2.6.0

- **Feature: login implicitly grants a sudo session** — a successful WordPress browser-based login (via `wp_login`) now automatically activates a sudo session. The user just proved their identity via the login form; requiring a second challenge immediately is unnecessary friction. This mirrors the behaviour of Unix `sudo` and GitHub's sudo mode. Application Password and XML-RPC logins are unaffected (`wp_login` does not fire for those). Implemented in `Plugin::grant_session_on_login()`.
- **Feature: `user.change_password` gated action** — changing a user's password on `profile.php` or `user-edit.php` now requires an active sudo session. The rule fires only when `pass1` or `pass2` is present in the POST body, narrowing it to actual password changes (not bio, email, or role updates, which also use `action=update`). The REST counterpart gates any `PUT`/`PATCH` to `/wp/v2/users/{id}` or `/wp/v2/users/me` that includes a `password` parameter. Closes the "session theft → silent password change → lockout" attack chain.
- **Feature: grace period (two-tier expiry)** — sudo sessions now have a 120-second grace window (`GRACE_SECONDS = 120`) after they expire. If a user was filling in a form while the session expired, the form submission still passes the gate without requiring re-authentication. The grace window is session-token-verified — a stolen cookie in a different browser does not gain grace access. Session meta cleanup is deferred until the grace window closes so the token is available for verification throughout. The four admin-bar UI call sites are intentionally excluded (the timer always reflects the true session state).
- **375 unit tests, 905 assertions. 73 integration tests in CI.**

## 2.5.2

- **Fix: WPGraphQL Limited policy now blocks unauthenticated mutations** — cross-origin requests (e.g. from a SvelteKit frontend) do not carry WordPress session cookies, so `get_current_user_id()` returns 0. Previously the Limited policy silently passed these through via an `if (!$user_id) return` guard before the session check was reached. Now, unauthenticated mutations are blocked with the same `sudo_blocked` 403 response as authenticated-without-session mutations. The `$user_id &&` short-circuit prevents `Sudo_Session::is_active()` from ever being called with user 0.
- **Fix: per-application-password policy dropdown now renders on profile pages** — two bugs prevented the dropdown column from appearing: (1) the JS looked for `.application-passwords-list-table` which does not exist; the table lives inside `.application-passwords-list-table-wrapper`. (2) UUID extraction tried to read `data-slug` from the revoke button, which carries no data attributes; WordPress already sets `data-uuid` directly on each `<tr>`. Both are now corrected.
- **Fix: `user.promote` rule now fires on bulk role changes** — the Action Registry `user.promote` rule declared `'method' => 'GET'`, but WordPress's bulk role change action on `users.php` POSTs the request. Changing the method to `ANY` ensures both the single-user edit and bulk promote paths are gated.
- **Security: sudo session required for MU-plugin install/uninstall** — `handle_mu_install()` and `handle_mu_uninstall()` only checked capability; now they also require an active sudo session (returns `sudo_required` 403 if absent).
- **Security: sudo session required for per-app-password policy save** — `handle_app_password_policy_save()` only checked capability; now also requires an active sudo session.
- **UX: per-app-password dropdown surfaces `sudo_required` response** — when the policy save AJAX returns `sudo_required`, the dropdown restores its previous value, shows an amber outline, and displays an alert explaining that a sudo session is needed.
- **Docs: WPGraphQL persisted queries caveat** — corrected the absolute claim in `security-model.md` that the mutation heuristic "cannot false-negative on an actual GraphQL mutation"; it cannot detect mutations sent via the Persisted Queries extension. Added explicit guidance to use the Disabled policy in persisted-query environments.
- **Docs: WPGraphQL headless authentication boundary** — added a new subsection to `security-model.md` explaining why Limited behaves identically to Disabled for most cross-origin headless deployments, with per-deployment policy recommendations. Cross-referenced from a new `## WPGraphQL Surface` section in `docs/developer-reference.md`.
- **361 unit tests, 882 assertions. 73 integration tests in CI.**

## 2.5.1

- **Fix: WPGraphQL gating now functional** — v2.5.0 hooked into `rest_request_before_callbacks`, but WPGraphQL dispatches requests via WordPress rewrite rules at `parse_request`, not through the REST API pipeline. The REST filter never fired for GraphQL requests. The fix hooks into WPGraphQL's own `graphql_process_http_request` action, which fires after authentication but before body reading, regardless of how the endpoint is named or configured. No endpoint URL matching is needed.
- **Remove `wp_sudo_wpgraphql_route` filter** — the filter was designed for the now-dead URL-matching approach and has no effect. Removed from the codebase and all documentation.
- **361 unit tests, 881 assertions. 73 integration tests in CI.**

## 2.5.0

- **WPGraphQL surface gating** — adds WPGraphQL as a fifth non-interactive surface alongside WP-CLI, Cron, XML-RPC, and Application Passwords. Three-tier policy (Disabled / Limited / Unrestricted); default is Limited. In Limited mode, GraphQL mutations are blocked without an active sudo session while read-only queries pass through. Fires the `wp_sudo_action_blocked` audit hook on block. The policy setting (`wpgraphql_policy`) is stored regardless of whether WPGraphQL is installed; the settings field is only shown when WPGraphQL is active.
- **Mutation detection heuristic** — Limited mode checks whether the POST body contains the word `mutation`. Intentionally blunt: cannot false-negative on actual mutations, may false-positive on queries mentioning "mutation" in a string argument. Documented in `docs/security-model.md`.
- **`wp_sudo_wpgraphql_route` filter** — allows the gated route to be overridden to match custom WPGraphQL endpoint configurations.
- **Site Health integration** — WPGraphQL policy included in the Entry Point Policies health check (flagged if set to Unrestricted).
- **364 unit tests, 887 assertions. 73 integration tests in CI.**

## 2.4.2

- **Documentation: roadmap consolidation** — Merged three separate roadmaps (`ROADMAP.md`, `ACCESSIBILITY-ROADMAP.md`, `docs/roadmap-2026-02.md`) into one unified `ROADMAP.md` at project root. Moved `CHANGELOG.md` and `FAQ.md` to root for prominence.
- **Planned Development Timeline** — Added comprehensive timeline at the top of ROADMAP.md showing immediate, short-term, medium-term, and deferred work phases. Provides quick reference for what will actually be implemented.
- **Table of Contents** — Added scannable TOC to ROADMAP.md linking to all 10 sections plus appendix.

## 2.4.1

- **AJAX gating integration tests** — 11 new tests covering the AJAX surface: rule matching for all 7 declared AJAX actions, full intercept flow via `wp_doing_ajax` filter, session bypass, non-gated pass-through, blocked transient lifecycle, admin notice fallback (`render_blocked_notice`), and `wp.updates` slug passthrough.
- **Action registry filter integration tests** — 3 new tests verifying custom rules added via `wp_sudo_gated_actions` are matched by the Gate in a real WordPress environment; including custom admin rules, custom AJAX rules, and filter-based removal of built-in rules.
- **Audit hook coverage** — `wp_sudo_action_blocked` now integration-tested for CLI, Cron, and XML-RPC surfaces (in addition to REST app-password). Documents that `wp_sudo_action_allowed` is intentionally absent from the production code path.
- **CI quality gate** — new GitHub Actions job runs PHPCS and PHPStan on every push and PR; Composer dependency cache added to unit and integration jobs; nightly scheduled run at 3 AM UTC catches WordPress trunk regressions.
- **MU-plugin manual install instructions** — fallback copy instructions added to the settings page UI (`<details>` disclosure) and help tab for environments where the one-click installer fails due to file permissions.
- **CONTRIBUTING.md** — new contributor guide covering prerequisites, local setup, unit vs integration test distinction, TDD workflow, and lint/analyse requirements.
- **349 unit tests, 863 assertions. 73 integration tests in CI.**

## 2.4.0

- **Integration test suite** — 55 integration tests running against a real WordPress + MySQL environment via `WP_UnitTestCase`. Covers sudo session lifecycle (bcrypt verification, token binding, rate limiting, expiry), request stash/replay with transient TTL, full reauth flow (5-class end-to-end), REST API gating with cookie auth and application passwords, upgrader migration chain, audit hook arguments, Two Factor plugin interaction, and multisite session isolation.
- **CI pipeline** — GitHub Actions workflow with unit tests across PHP 8.1–8.4 and integration tests against WordPress `latest` and `trunk` (including multisite variant). MySQL 8.0 service container with health checks.
- **Fix: multisite site-management gate gap** — Archive, Spam, Delete, and Deactivate site actions on Network Admin → Sites now correctly trigger the sudo challenge. WordPress core's `sites.php` sends `action=confirm` with the real action in `action2`; the Gate now checks both parameters.
- **Fix: admin bar timer width** — the countdown timer's red (expiring) state no longer stretches wider than the green (active) state. Defensive CSS constrains the background to content width regardless of WP core layout context.
- **Fix: WP 7.0 admin notice background** — restored white background on WP Sudo admin notices, which lost their background color in WP 7.0's admin visual refresh.
- **Fix: 2FA countdown advisory-only** — the two-factor verification window is now advisory (5 minutes, reduced from 10). Expired 2FA codes are still accepted if the underlying provider validates them, preventing false rejections for slow email delivery.
- **Fix: `setcookie()` headers-already-sent guard** — `Sudo_Session::activate()` now checks `headers_sent()` before calling `setcookie()`, preventing warnings in CLI and integration test contexts.
- **Verification requirements** — CLAUDE.md now mandates live source verification for all external code references, with documented verification commands. LLM lies log tracks 5 prior fabrications that were corrected.
- **WP 7.0 Beta 1 tested** — manual testing guide completed against WP 7.0 Beta 1 (15 sections, all PASS). Visual compatibility, help tabs, challenge page, and admin bar verified against the refreshed admin chrome.
- **349 unit tests, 863 assertions. 55 integration tests in CI.**

## 2.3.2

- **Fix: admin bar sr-only text leak** — screen-reader-only milestone text no longer renders in the dashboard canvas. The admin bar `<li>` node now establishes a containing block (`position: relative`) and sr-only elements use `clip-path: inset(50%)` alongside the legacy `clip` property.
- **Documentation overhaul** — readmes slimmed to storefront length. Full content extracted to `docs/`: [security model](docs/security-model.md), [developer reference](docs/developer-reference.md), [FAQ](docs/FAQ.md), and this changelog. [Manual testing guide](tests/MANUAL-TESTING.md) rewritten for v2.3.1+ with per-app-password testing, MU-plugin toggle, and iframe edge case coverage.
- **Composer lock compatibility** — `config.platform.php` set to `8.1.99` so the lock file resolves packages compatible with PHP 8.1+ regardless of the local PHP version. Fixes Copilot coding agent CI failure (`doctrine/instantiator` 2.1.0 requiring PHP 8.4+).
- **Housekeeping** — removed stale `WP-SUDO-PROJECT-STATE.md`; added `@since 2.0.0` to Upgrader class; updated CLAUDE.md and `.github/copilot-instructions.md` with docs/ file listings.
- **343 unit tests, 853 assertions.**

## 2.3.1

- **Fix: Unicode escape rendering** — localized JS strings using bare `\uXXXX` escapes (not valid PHP Unicode syntax) now use actual UTF-8 characters, fixing visible backslash-escape text during challenge replay.
- **Fix: screen-reader-only text flash** — the sr-only "Verifying..." span no longer flashes visible fragments inside the flex container during challenge replay.
- **CycloneDX SBOM** — `bom.json` shipped in the repo for supply chain transparency. Regenerate with `composer sbom`.
- **Help tabs** — per-application-password policy section added to the Settings help tab. Help tab count corrected from 4 to 8 across readmes.
- **Copilot coding agent** — `.github/copilot-instructions.md` and `copilot-setup-steps.yml` added for GitHub Copilot integration.
- **Accessibility roadmap complete** — all items (critical through low priority) verified resolved and documented.
- **343 unit tests, 853 assertions.**

## 2.3.0

- **Per-application-password sudo policies** — individual Application Password credentials can now have their own Disabled, Limited, or Unrestricted policy override, independent of the global REST API (App Passwords) policy. Configure per-password policies from the Application Passwords section on the user profile page.
- **Challenge page iframe fix** — the reauthentication challenge page now breaks out of WordPress's `wp_iframe()` context, fixing a nested-frame display issue during plugin and theme updates.
- **Accessibility improvements** — admin bar countdown timer cleans up on page unload; lockout countdown screen reader announcements throttled to 30-second intervals; settings fields display default values.
- **PHPStan level 6 static analysis** — full codebase passes PHPStan level 6 with zero errors.
- **Documentation** — new [AI and agentic tool guidance](docs/ai-agentic-guidance.md) and [UI/UX testing prompts](docs/ui-ux-testing-prompts.md).
- **343 unit tests, 853 assertions.**

## 2.2.1

- **Security hardening** — stashed redirect URLs are now validated with `wp_validate_redirect()` before replay.
- **Accessibility** — ARIA `role="alert"` and `role="status"` added to gate notices; disabled-action text color improved to 4.6:1 contrast ratio (WCAG AA).
- **2FA ecosystem documentation** — new [integration guide](docs/two-factor-integration.md) and [ecosystem survey](docs/two-factor-ecosystem.md) covering 7 major 2FA plugins with bridge patterns.
- **WP 2FA bridge** — drop-in bridge for WP 2FA by Melapress supporting TOTP, email OTP, and backup codes ([`bridges/wp-sudo-wp2fa-bridge.php`](bridges/wp-sudo-wp2fa-bridge.php)).
- **Help tabs** — Settings tab moved to 2nd position; all four 2FA hooks documented; Security Model heading added.
- **334 unit tests, 792 assertions.**

## 2.2.0

- **Three-tier entry point policies** — replaces the binary Block/Allow toggle with three modes per surface: Disabled (shuts off the protocol entirely), Limited (default — gated actions blocked, non-gated work proceeds normally), and Unrestricted (everything passes through).
- **Function-level gating for non-interactive surfaces** — WP-CLI, Cron, and XML-RPC now hook into WordPress function-level actions (`activate_plugin`, `delete_plugin`, `set_user_role`, etc.) instead of matching request parameters. This makes gating reliable regardless of how the operation is triggered.
- **CLI enforces Cron policy** — `wp cron` subcommands respect the Cron policy even when CLI is Limited or Unrestricted. If Cron is Disabled, `wp cron event run` is blocked.
- **REST API policy split** — Disabled returns `sudo_disabled` (surface is off), Limited returns `sudo_blocked` (gated action denied), clearly distinguishing the two rejection reasons.
- **Automatic upgrade migration** — existing `block` settings migrate to `limited`, `allow` to `unrestricted`. Multisite-aware.
- **Site Health updated** — Disabled is treated as valid hardening (Good status). Unrestricted triggers a Recommended notice.
- **Manual testing guide** — comprehensive step-by-step verification procedures in `tests/MANUAL-TESTING.md`.
- **327 unit tests, 752 assertions.**

## 2.1.0

- Removes the `unfiltered_html` capability from the Editor role. Editors can no longer embed scripts, iframes, or other non-whitelisted HTML — KSES sanitization is always active for editors. Administrators retain `unfiltered_html`. The capability is restored if the plugin is deactivated or uninstalled.
- Adds tamper detection: if `unfiltered_html` reappears on the Editor role (e.g. via database modification), it is stripped automatically and the `wp_sudo_capability_tampered` action fires for audit logging.
- Fixes admin bar deactivation redirect: clicking the countdown timer to end a session now keeps you on the current page instead of redirecting to the dashboard.
- Replaces WordPress core's confusing "user editing capabilities" error with a clearer message when a bulk role change skips the current user.

## 2.0.0

Complete rewrite. Action-gated reauthentication replaces role-based privilege escalation.

- **New model** — gates dangerous operations behind reauthentication for any user, regardless of role. No custom role, no capability escalation.
- **Full attack surface coverage** — admin UI (stash-challenge-replay), AJAX (error + admin notice + session activation), REST API (cookie-auth challenge, app-password policy), WP-CLI, Cron, XML-RPC.
- **Action Registry** — 20 gated rules across 7 categories (plugins, themes, users, editors, options, updates, tools), plus 8 multisite-specific rules. Extensible via `wp_sudo_gated_actions` filter.
- **Entry point policies** — three-tier Disabled/Limited/Unrestricted policies for REST Application Passwords, WP-CLI, Cron, and XML-RPC.
- **2FA browser binding** — challenge cookie prevents cross-browser 2FA replay.
- **2FA countdown timer** — visible countdown during the verification step; configurable window via `wp_sudo_two_factor_window` filter.
- **Self-protection** — WP Sudo settings changes are gated.
- **MU-plugin toggle** — one-click install/uninstall from the settings page. Stable shim + loader pattern keeps the mu-plugin up to date with regular plugin updates.
- **Multisite** — network-wide settings, network-wide sessions, 8 network admin rules, `get_site_option`/`set_site_transient` storage.
- **8 audit hooks** — full lifecycle and policy logging for integration with WP Activity Log, Stream, and similar plugins.
- **Contextual help** — 8 help tabs on the settings page.
- **Accessibility** — WCAG 2.1 AA throughout (ARIA labels, focus management, status announcements, keyboard support).
- **281 unit tests, 686 assertions.**

## 1.2.1

- In-place modal reauthentication; no full-page redirect.
- AJAX activation, accessibility improvements, expanded test suite.

## 1.2.0

- M:SS countdown timer, red bar at 60 seconds, accessibility improvements.
- Multisite-safe uninstall, contextual Help tab.

## 1.1.0

- 15-minute session cap, two-factor authentication support, `unfiltered_html` restriction.

## 1.0.0

- Initial release.
