# Developer Reference


## Naming, Slug, and Repository Identifiers

Sudo intentionally uses a few different names in different contexts:

- **Product and UI name:** Sudo. User-facing admin labels, notices, and diagnostics should use this name.
- **Plugin file header / WordPress.org listing name:** Sudo – Admin Action Gating. The plugin header and the readme `=== … ===` title use this longer, descriptive name so the public listing names the core function and satisfies WordPress.org's ≥5-latin-letter and trademark rules. The short brand **Sudo** is used in UI copy. Do not shorten the header to bare "Sudo" (only 4 latin letters — fails Plugin Check's `plugin_header_unsupported_plugin_name` rule).
- **Slug, text domain, option/meta prefixes, and hook prefixes:** `wp-sudo` / `wp_sudo`. These are stable identifiers and must not be renamed to match the shorter UI label because doing so would break translations, stored settings, integrations, and existing extension code.
- **GitHub repository:** `dknauss/Sudo`. Repository URLs should use the renamed GitHub repo, while package/folder identifiers may still intentionally use `wp-sudo`.

When adding or reviewing code, normalize display text toward **Sudo** but leave technical identifiers alone unless a migration explicitly owns that change.


## Localization and Translation Template

The plugin text domain is `wp-sudo`, matching the intended WordPress.org slug, even though the product/UI name is **Sudo**. Do not rename the text domain to match the product name.

Maintainer commands:

```bash
composer i18n:make-pot   # Regenerate languages/wp-sudo.pot
composer verify:i18n     # Check that languages/wp-sudo.pot is current
```

`composer verify:i18n` is a release-readiness gate: it generates a temporary POT file with WP-CLI's `wp i18n make-pot` and fails if it differs from the committed `languages/wp-sudo.pot`. When adding or changing translatable strings, run `composer i18n:make-pot` intentionally and review the POT diff before committing.

The repository commits only `languages/wp-sudo.pot` plus safety placeholder files. Do not commit generated `.po` or `.mo` files unless the project starts maintaining reviewed translations directly.

## Gated Action Rule Structure

Use the `wp_sudo_gated_actions` filter to add custom rules. Each rule defines matching criteria for admin UI (`pagenow`, actions, HTTP method), AJAX (action names), and REST (route patterns, HTTP methods). Custom rules appear in the Gated Actions table on the settings page.

Rule ingestion is normalized before caching. Invalid filtered entries are dropped
fail-closed per rule (required scalar metadata: `id`, `label`, `category`;
surface shapes must be array-or-null for `admin`, `ajax`, `rest`). If the
filter returns a non-array payload, WP Sudo falls back to built-in rules.

Custom rules protect only the surfaces they define: admin, AJAX, and/or REST. Application Password requests are covered when a custom rule defines REST criteria, because Application Passwords enter through the REST API. WP-CLI, Cron, and XML-RPC Limited mode use a built-in function-hook map for WP Sudo's core rules; they do not automatically discover arbitrary custom rules. If a third-party workflow needs non-interactive protection, either add an explicit integration with `wp_sudo_check()` / `wp_sudo_require()`, expose it through a REST rule that WP Sudo can match, or use the surface policy to disable the entry point. WPGraphQL is gated by its own surface-level policy rather than per-rule matching — in Limited mode, all mutations require a sudo session regardless of which action they perform. See [WPGraphQL Surface](#wpgraphql-surface) below.

For POST replay, custom admin rules should declare a `stash` policy. WP Sudo
stores only top-level POST fields named in `stash.post_fields`; if no allowlist
is present, the user can still reauthenticate but the POST body is not replayed
automatically. Use `post_mode => 'none'` for uploads, file-editor saves, or
other requests that cannot be safely reconstructed from POST fields alone.

```php
add_filter( 'wp_sudo_gated_actions', function ( array $rules ): array {
    $rules[] = array(
        'id'       => 'custom.my_action',
        'label'    => 'My dangerous action',
        'category' => 'custom',
        'admin'    => array(
            'pagenow'  => array( 'admin.php', 'edit.php' ), // string or string[]
            'actions'  => array( 'my_dangerous_action' ),
            'method'   => 'POST',
            'callback' => function (): bool {
                return some_extra_condition();
            },
        ),
        'ajax'     => array(
            'actions' => array( 'my_ajax_action' ),
        ),
        'rest'     => array(
            'route'    => '#^/my-namespace/v1/dangerous#',
            'methods'  => array( 'POST', 'DELETE' ),
            'callback' => function ( $request ): bool { // optional: inspect params
                return array_key_exists( 'confirm', $request->get_params() );
            },
        ),
        'stash'    => array(
            'post_mode'   => 'allowlist',
            'post_fields' => array( '_wpnonce', '_wp_http_referer', 'action', 'item_id' ),
        ),
    );
    return $rules;
} );
```

For non-replayable POST actions:

```php
'stash' => array(
    'post_mode' => 'none',
),
```

Secret-like field names are still omitted even when allowlisted. Matching is
case-insensitive and covers exact names plus high-signal suffixes such as
`_api_key`, `_secret_key`, `_password`, dashed equivalents, and camelCase
endings such as `apiKey` or `accessToken`.

### Gating Third-Party Plugin Actions

To gate AJAX or REST endpoints from a third-party plugin, create a bridge
file and drop it into `wp-content/mu-plugins/`. Use a class-existence guard
so the rules are only added when the target plugin is active.

**Example: WebAuthn security key registration** (full bridge at
`bridges/wp-sudo-webauthn-bridge.php`):

```php
<?php
// mu-plugins/wp-sudo-webauthn-bridge.php
defined( 'ABSPATH' ) || exit;

add_filter( 'wp_sudo_gated_actions', static function ( array $rules ): array {
    // Only add rules when the WebAuthn Provider plugin is active.
    if ( ! class_exists( 'WildWolf\WordPress\TwoFactorWebAuthn\Plugin' ) ) {
        return $rules;
    }

    // Gate security key registration (two-step AJAX ceremony).
    $rules[] = array(
        'id'       => 'auth.webauthn_register',
        'label'    => __( 'Register security key (WebAuthn)', 'wp-sudo' ),
        'category' => 'users',
        'admin'    => null,
        'ajax'     => array(
            'actions' => array( 'webauthn_preregister', 'webauthn_register' ),
        ),
        'rest'     => null,
    );

    // Gate security key deletion.
    $rules[] = array(
        'id'       => 'auth.webauthn_delete',
        'label'    => __( 'Delete security key (WebAuthn)', 'wp-sudo' ),
        'category' => 'users',
        'admin'    => null,
        'ajax'     => array(
            'actions' => array( 'webauthn_delete_key' ),
        ),
        'rest'     => null,
    );

    return $rules;
} );
```

Key patterns:

- **Class-existence guard** — `class_exists()` check ensures rules are
  only added when the target plugin is active. Use the plugin's main class.
- **AJAX-only rules** — set `'admin' => null` and `'rest' => null` when
  the action is only accessible via AJAX. The gate's `matches_ajax()`
  checks `$_REQUEST['action']` against the `actions` array.
- **Gate registration and deletion, not rename** — gate security-sensitive
  operations (adding/removing authentication factors), not cosmetic ones.
- **Category `'users'`** — groups the rules with other user-management
  actions in the Gated Actions table on the settings page.

To find the AJAX action names for a plugin, search its source for
`wp_ajax_` hooks:

```bash
grep "wp_ajax_" /path/to/plugin/*.php
```

### Built-in Example: Narrow REST Gating on a Shared Endpoint

WP Sudo's built-in `connectors.update_credentials` rule is a good example of
how to gate one sensitive action on a broad shared endpoint without blocking the
entire endpoint.

- **Route:** `/wp/v2/settings`
- **Methods:** `POST`, `PUT`, `PATCH`
- **Problem:** the endpoint is used for many unrelated settings writes
- **Narrowing strategy:** only match when request params include connector
  credential setting names — using a two-tier registry-first union matcher

**Matcher details (`is_connector_api_key_setting_name`):**
On WP 7.0+, Tier 1 reads all connectors where `authentication.method === 'api_key'`
from `wp_get_connectors()` and gates their `setting_name` values. This catches
non-regex names like Akismet's `wordpress_api_key`. Tier 2 always runs as a
union fallback: `^connectors_[a-z0-9_]+_api_key$` covers pre-WP-7.0 installs
and connectors that auto-generate their setting name. Any connector with
`method=api_key` is gated automatically — no WP Sudo configuration needed.

For full details see
[`docs/connectors-api-reference.md` — Two-tier matcher](connectors-api-reference.md).

This pattern is useful when a third-party plugin reuses a generic REST or admin
save path for multiple settings classes, but only one subset is security
critical. In those cases:

- match the shared route/action normally
- add a callback that checks for the exact field names or payload shape that
  identify the dangerous write
- prefer **over-matching inside the sensitive class** to under-matching and
  missing a destructive credential or policy change

## Public API Helpers

Use these helpers when you want to gate a custom operation without adding a
full `wp_sudo_gated_actions` rule.

### `wp_sudo_check( ?int $user_id = null ): bool`

Returns `true` when the user has an active sudo session (or is within the grace
window), otherwise `false`.

Only the current request's authenticated user can be checked — sudo sessions
are bound to a per-browser cookie, so passing a user ID other than the current
user always returns `false`. Omit the argument (or pass `null`) to check the
current user.

### `wp_sudo_require( array $args = array() ): bool`

Returns `true` when sudo is active. If not active:

- Fires `wp_sudo_action_gated` with surface `public_api`.
- Redirects to the challenge page in session-only mode by default.
- Returns `false` when redirecting is disabled or unavailable.

Accepted args:

- `user_id` (`int`) — target user; defaults to current user.
- `rule_id` (`string`) — audit identifier; defaults to `public_api.require`.
- `redirect` (`bool`) — default `true`; set `false` to receive `false` instead of redirect.
- `return_url` (`string`) — optional URL for challenge cancel/return flow.

Example:

```php
if ( ! wp_sudo_require( array( 'rule_id' => 'my-plugin.run_sensitive_task' ) ) ) {
    return;
}

// Sensitive action runs only with active sudo.
my_plugin_run_sensitive_task();
```

## Soft-Block Response Payload (`sudo_required`)

When the Gate blocks a cookie-authenticated AJAX or REST request (rather than
hard-blocking a non-interactive surface), it returns a recoverable `sudo_required`
response so the client can prompt the user to start a session and retry.

- **REST** — a `WP_Error` with code `sudo_required`, a translated message naming
  the action and the keyboard shortcut, and error data
  `array( 'status' => 403, 'rule_id' => <rule id> )`.
- **AJAX** (`wp_send_json_error`) — `code` and `errorCode` both `sudo_required`,
  `message`/`errorMessage` (plain text), `rule_id`, and pass-through `slug`/`plugin`
  when present (so `wp.updates` can locate the originating DOM element).

On the next admin page load a `set_blocked_transient` notice provides a clickable
link to the challenge page. Treat `rule_id` as the stable field; message text is
localized and may change.

## Governance Capabilities

WP Sudo separates *who may administer Sudo* from general site-admin authority
using four dedicated capabilities, checked through a single helper. Route every
Sudo admin-surface capability check through it rather than calling
`current_user_can( 'manage_options' )` directly.

### `wp_sudo_can( string $cap, ?int $user_id = null ): bool`

Returns `true` when the user holds the given governance capability. `$cap` is one
of:

- `manage_wp_sudo` — administer Sudo settings and the Access tab.
- `view_wp_sudo_activity` — view the Session Activity dashboard widget.
- `export_wp_sudo_activity` — reserved for activity export (no surface gates on it yet).
- `revoke_wp_sudo_sessions` — force-revoke other users' active sudo sessions.

Decision order: multisite super admins always pass; break-glass recovery mode
(`WP_SUDO_RECOVERY_MODE`) grants `manage_wp_sudo` to the current user only; then
the strict cap check runs — `user_can( $user_id, $cap )`.

> Since 4.0.0, governance is always strict. The `compatibility` mode and the
> `sudo_can()` alias were removed — see [Migrating to 4.0](#migrating-to-40).

### `wp_sudo_is_recovery_mode(): bool`

Returns `true` when `WP_SUDO_RECOVERY_MODE` is defined and truthy in
`wp-config.php`. Break-glass escape hatch for the "last manager locked out"
scenario; leaving it enabled permanently effectively bypasses the governance
model.

### Capability-model audit (4.0.0)

Every Sudo admin surface was audited at 4.0.0 and confirmed to gate exclusively
on the dedicated Sudo capability family — no surface falls back to bare
`manage_options`. The surface-to-cap mapping is:

| Surface | Capability |
|---|---|
| Settings page (`add_options_page` / `add_network_options_page`) | `manage_wp_sudo` |
| Settings page callback (`render_settings_page`) | `manage_wp_sudo` |
| AJAX handlers (grant/revoke cap, revoke session, mu-plugin install/uninstall) | `manage_wp_sudo` |
| Dashboard widget | `view_wp_sudo_activity` |

**Intentional non-gate uses of `manage_options`** (these are correct behavior, not
gaps):

- The break-glass gate inside `wp_sudo_can()` — when `WP_SUDO_RECOVERY_MODE` is
  active, `manage_options` is the *authority check* that limits recovery to
  WordPress administrators. This is not an access gate replacing `manage_wp_sudo`;
  it is an additional restriction applied during the emergency escape hatch.
- `wp_sudo_map_governance_meta_cap()` — maps `manage_wp_sudo` to `manage_options`
  during recovery mode so WordPress core's own admin-page gate enforces the same
  check. Same recovery scope; same intent.
- The Access tab informational panel — reads the list of users who hold
  `manage_options` for display purposes only. This is a read-only UI element, not
  an authorization gate.

## Migrating to 4.0

Version 4.0.0 is a breaking release. Three changes affect integrators:

### `sudo_can()` removed → use `wp_sudo_can()`

The unprefixed `sudo_can()` alias (shipped in 3.2.0, deprecated in 3.3.0 with a
documented removal target of 4.0.0) is gone. The promise is now delivered:
calling `sudo_can()` is a fatal undefined-function error. Replace it with
`wp_sudo_can()`, which has the identical signature and behavior:

```php
wp_sudo_can( string $cap, ?int $user_id = null ): bool
```

Search-replace any remaining `sudo_can(` call sites with `wp_sudo_can(`. No other
change is needed — the parameters and return value are unchanged.

### `compatibility` governance mode removed → governance is always strict

The `compatibility` value of the `wp_sudo_governance_mode` option used to make
`wp_sudo_can()` and `wp_sudo_map_governance_meta_cap()` delegate to
`manage_options` / `manage_network_options` instead of the dedicated
`manage_wp_sudo` capability family. That mode is removed. Governance is now always
*strict*: `wp_sudo_can()` delegates to `user_can( $user_id, $cap )`.

**Why it existed, and why it's gone.** WP Sudo 3.2.0 introduced a dedicated
capability model — the `manage_wp_sudo` family — to separate "who may administer
Sudo" from the generic site-admin `manage_options` capability. `compatibility`
mode was the **transitional bridge** for that change: it let a site keep
authorizing Sudo administration via the old `manage_options` check while its
administrators were being migrated onto the dedicated capabilities, so adopting
the stricter model could not lock an existing admin out before the capability
backfill had run. By 4.0.0 the bridge has done its job — the dedicated-capability
model is the established default, the 3.3.0 backfill grants the caps to existing
administrators automatically, and the 3.4.0-hardened `WP_SUDO_RECOVERY_MODE`
covers the "last manager locked out" recovery case the bridge was guarding
against. Removing the mode collapses governance to a single, auditable strict
path and eliminates a second capability-check code branch — less complexity and
less attack surface, in keeping with the 4.0.0 pre-public hardening baseline.

A site that still has `wp_sudo_governance_mode` stored is not broken — any stored
value is **inert** (treated as strict) — and **4.0.0 removes it automatically**; no
manual database cleanup is required:

- **On upgrade.** `Upgrader::upgrade_4_0_0()` deletes `wp_sudo_governance_mode` on
  the 3.x → 4.0.0 boundary, from both the per-site option store and (on multisite)
  network sitemeta. Deleting an absent option is a no-op, so it is safe on fresh
  installs upgrading through 4.0.0.
- **On detection (self-heal).** `Admin::cleanup_inert_governance_mode_option()`
  runs on `admin_init` (priority 1) and deletes the option from both stores if it
  ever reappears after the version stamp is already 4.0.0 — for an authorized
  (`manage_wp_sudo`) admin loading any admin page.
- **The signal.** After the option is cleared, an admin with `manage_wp_sudo` sees
  a single **dismissible** success notice confirming the leftover setting was
  removed (no action needed). There is **no** persistent warning and **no**
  `_doing_it_wrong()`; the developer/audit signal is the
  `wp_sudo_inert_governance_mode_detected` action, fired at most once per request
  when the cleanup deletes the option.

`WP_SUDO_RECOVERY_MODE` (see `wp_sudo_is_recovery_mode()`) remains the **sole**
break-glass path for a locked-out administrator.

### Recovery from a misconfigured `manage_wp_sudo` grant

If every holder of `manage_wp_sudo` is removed — for example, the capability was
accidentally revoked from the only administrator who had it — no one can reach the
Sudo settings page to re-grant it. `WP_SUDO_RECOVERY_MODE` is the way out:

1. Add `define( 'WP_SUDO_RECOVERY_MODE', true );` to `wp-config.php`.
2. Log in (or reload) as an administrator who holds WordPress's `manage_options`
   capability (any standard single-site admin). On multisite, the account must hold
   `manage_network_options` (super admin).
3. Navigate to **Settings → Sudo → Access** and grant `manage_wp_sudo` to the
   intended user(s).
4. Remove `define( 'WP_SUDO_RECOVERY_MODE', true );` from `wp-config.php`
   immediately after access is restored.

**Why it works.** While the constant is defined, `wp_sudo_can( 'manage_wp_sudo' )`
returns `true` for the current user — but only if they also hold `manage_options`
(single-site) or `manage_network_options` (multisite). Subscribers, editors, and
non-admin users gain nothing. Once the constant is removed, the dedicated-capability
check resumes and only explicitly granted users can access Sudo settings.

**What it does not cover.** A user who holds `manage_wp_sudo` *without* a WordPress
admin role (i.e. `manage_options`) cannot recover this way — recovery mode requires
the admin primitive cap. Use WP-CLI to re-grant the capability in that case:

```bash
wp user add-cap <user_login> manage_wp_sudo
```

See [security-model.md §Break-glass recovery](security-model.md#internal-admin-users-and-governance-boundary)
for the full risk analysis of the recovery window and the audit hooks that fire
while `WP_SUDO_RECOVERY_MODE` is active.

**First-run lockout safety.** On a fresh install, the activating administrator
automatically receives all four Sudo governance capabilities during plugin
activation — the `upgrade_3_3_0()` backfill routine runs and grants
`manage_wp_sudo` to any existing administrator. A first-run lockout (no one holds
`manage_wp_sudo` after activation) can only occur if no WordPress administrator
existed at activation time, which is atypical. If it does occur, `WP_SUDO_RECOVERY_MODE`
is the recovery path.

### Minimum requirements raised

- **WordPress 6.4** (from 6.2). Among other things, 6.4 guarantees
  `wp_get_admin_notice()`, so no compatibility shim is needed for it.
- **PHP 8.2** (from 8.0). `composer.json` requires `php >=8.2`; the CI test
  matrix drops the 8.0 and 8.1 lanes.

Confirm the host meets both minimums before upgrading.

## Request / Rule Tester

Settings → Sudo now includes an internal **Request / Rule Tester** panel for
diagnosing how WP Sudo would classify a representative request **without
executing it**.

The first MVP supports three request shapes only:

- `admin`
- `ajax`
- `rest`

Supported inputs:

- surface
- HTTP method
- full URL
- authenticated / unauthenticated toggle
- active sudo toggle
- network-admin context toggle
- REST auth mode (`cookie`, `application_password`, `bearer`, or `none`)

Current output includes:

- matched rule label and ID
- evaluated surface
- decision (`allow`, `gate`, `soft-block`, `hard-block`)
- whether stash/replay would be used
- explanatory notes

### What the decisions mean

- `allow` — no matched rule, unauthenticated request, active sudo already
  present, or a surface policy that explicitly permits the request
- `gate` — an interactive admin request would be sent through the challenge
  page and use stash/replay
- `soft-block` — an AJAX or cookie-authenticated REST request would be blocked
  in place and retried after sudo activation
- `hard-block` — a non-browser REST request would be rejected by current
  surface policy (for example, Application Password policy set to Limited or
  Disabled)

### Diagnostic-only behavior

The tester uses `WP_Sudo\Gate::evaluate_diagnostic_request()` internally. That
method is intentionally side-effect-free:

- no redirects
- no request stashing
- no transients
- no audit hooks
- no mutation of live gate state

It is meant for operator troubleshooting and rule design, not for executing or
replaying requests.

For Connectors specifically, this means the tester can verify whether a
representative `/wp/v2/settings` write would match
`connectors.update_credentials` and whether WP Sudo would allow, gate, or block
it under the current REST policy assumptions. It does **not** execute the
underlying core Connectors save flow, so it cannot by itself prove runtime
effects like key validation, masking, key-source precedence, or downstream
provider impact.

## Audit Hook Signatures

Sudo fires audit action hooks for external logging integration with [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/), [Stream](https://wordpress.org/plugins/stream/), and similar plugins. The live count is tracked in [current-metrics.md](current-metrics.md).

```php
// Session lifecycle.
do_action( 'wp_sudo_activated', int $user_id, int $expires, int $duration );
do_action( 'wp_sudo_deactivated', int $user_id ); // Also fires on password change (v2.8.0).

// Authentication failures.
do_action( 'wp_sudo_reauth_failed', int $user_id, int $attempts );
do_action( 'wp_sudo_lockout', int $user_id, int $attempts, string $ip );

// Action gating.
// $surface values: 'admin', 'ajax', 'rest' (cookie-auth REST), 'rest_app_password',
//                  'cli', 'cron', 'xmlrpc', 'wpgraphql', 'public_api'.
// Note: 'rest' is browser/cookie-authenticated REST; 'rest_app_password' is an
// Application Password request. On 'cli'/'cron'/'xmlrpc' the $user_id is 0 (no
// authenticated user in those contexts), and only action_allowed/action_blocked
// fire there — never action_gated/action_passed.
// Since 4.1.0 the interactive effect-level backstop also fires action_blocked on
// the 'admin' surface (with the real $user_id) when a destructive effect
// (delete_user/delete_plugin/delete_theme/activate_plugin/upgrader_pre_install/
// export_wp) is reached through a non-enumerated handler while no sudo window is
// active. The backstop is silent on the allow path; the enumerated request flow
// owns the action_passed signal.
do_action( 'wp_sudo_action_gated', int $user_id, string $rule_id, string $surface );
do_action( 'wp_sudo_action_blocked', int $user_id, string $rule_id, string $surface );
do_action( 'wp_sudo_action_allowed', int $user_id, string $rule_id, string $surface ); // Unrestricted policy (v2.9.0).
do_action( 'wp_sudo_action_passed', int $user_id, string $rule_id, string $surface ); // Active session (v3.0.0).
do_action( 'wp_sudo_action_replayed', int $user_id, string $rule_id );

// Admin-escalation guard (v4.1.0, opt-in via the wp_sudo_guard_escalation filter,
// default OFF). High-severity, distinct from wp_sudo_action_blocked so external
// alerting can subscribe to escalation specifically. Fires when a NEWLY granted
// administrator/super-admin (or the deletion of an administrator target) is
// blocked because the actor lacks the promoting authority (promote_users on the
// target blog, or existing super-admin) or holds no active/grace sudo session.
// $target_id is the user being granted/deleted (not the actor). $rule_id is one of 'user.promote',
// 'user.super_admin', or 'user.delete'. The bundled Event_Recorder stores this as
// an `escalation_blocked` event with context severity=high.
do_action( 'wp_sudo_escalation_blocked', int $target_id, string $rule_id, string $surface );

// Rule diagnostics.
// Fires when a custom rule's REST-route regex fails to compile. $fail_closed is
// true when the malformed pattern caused the request to be gated anyway (v3.1.4).
do_action( 'wp_sudo_rule_regex_error', string $pattern, string $subject, bool $fail_closed );
do_action( 'wp_sudo_policy_preset_applied', int $user_id, string $preset_key, array $previous, array $current, bool $is_network );

// Tamper detection.
do_action( 'wp_sudo_capability_tampered', string $role, string $capability );

// Governance access-model transitions (v3.2.0).
do_action( 'wp_sudo_capability_granted', int $target_user_id, string $cap, int $granter_user_id, int $site_id );
do_action( 'wp_sudo_capability_revoked', int $target_user_id, string $cap, int $revoker_user_id, int $site_id );
// The bundled Event_Recorder stores this as a `session_revoked` event row:
// target user in user_id, operator in context as `revoked_by`, reason tag in
// the surface column. The UI fires `users_list_row_action` for the per-user
// row action and `users_list_bulk_action` once per user revoked by the
// Users-list "Revoke sudo sessions" bulk action; a zero target remains a
// documented batch convention for third-party callers. WP-CLI revocations
// (`wp sudo revoke`, including `--all` — the only remaining site-wide
// revoke-everything path) do not fire this hook, so they never appear in the
// Session Activity dashboard widget — during incident response, do not read
// an empty widget as "no revocations" if a CLI runbook was in play.
do_action( 'wp_sudo_session_revoked', int $target_user_id, int $revoker_user_id, string $reason, int $site_id );

// Break-glass recovery usage (v3.4.0). Fires on every Sudo admin-page load
// while WP_SUDO_RECOVERY_MODE is active (unthrottled, for external loggers).
// The bundled Event_Recorder samples this to one stored `recovery_mode` event
// row per user per hour to avoid flooding the events table.
do_action( 'wp_sudo_recovery_mode_active', int $user_id );

// Rule diagnostics.
do_action( 'wp_sudo_gated_actions_missing_builtin_rules', array $missing_builtin_ids );
```

`wp_sudo_lockout` adds source IP as a third argument as of v2.13.0. Existing
callbacks that register for two arguments continue to work unchanged.

### Optional WSAL Sensor Bridge

WP Sudo ships an optional WSAL bridge at
`bridges/wp-sudo-wsal-sensor.php`. Install it as an mu-plugin to map
WP Sudo hooks into WSAL events.

Event mapping:

| WP Sudo hook | WSAL event ID |
|---|---|
| `wp_sudo_activated` | `1900001` |
| `wp_sudo_deactivated` | `1900002` |
| `wp_sudo_reauth_failed` | `1900003` |
| `wp_sudo_lockout` | `1900004` |
| `wp_sudo_action_gated` | `1900005` |
| `wp_sudo_action_blocked` | `1900006` |
| `wp_sudo_action_allowed` | `1900007` |
| `wp_sudo_action_passed` | `1900008` |
| `wp_sudo_action_replayed` | `1900009` |
| `wp_sudo_capability_tampered` | `1900010` |
| `wp_sudo_policy_preset_applied` | `1900011` |
| `wp_sudo_escalation_blocked` | `1900012` |
| `wp_sudo_session_revoked` | `1900013` |
| `wp_sudo_recovery_mode_active` | `1900014` |
| `wp_sudo_capability_granted` | `1900015` |
| `wp_sudo_capability_revoked` | `1900016` |
| `wp_sudo_gated_actions_missing_builtin_rules` | `1900017` |
| `wp_sudo_rule_regex_error` | `1900018` |

`wp_sudo_recovery_mode_active` fires on every recovery-mode page load by
design; the bridge throttles it to one event per user per hour (mirroring
the bundled Event_Recorder's sampling) to avoid flooding a table-backed
activity log. The diagnostic-only `wp_sudo_inert_governance_mode_detected`
hook is intentionally not mapped.

The bridge is inert when WSAL APIs are unavailable.

### Optional Stream Bridge

WP Sudo ships an optional Stream bridge at
`bridges/wp-sudo-stream-bridge.php`. Install it as an mu-plugin to map
WP Sudo hooks into Stream records.

Record mapping:

- **Connector:** `wp_sudo`
- **Context:** `wp_sudo`
- **Action:** derived from hook (`activated`, `deactivated`,
  `reauth_failed`, `lockout`, `gated`, `blocked`, `allowed`,
  `passed`, `replayed`, `policy_preset_applied`, `capability_tampered`)
- **Args/meta:** always includes `source=wp-sudo` and `hook`, plus hook
  fields such as `user_id`, `rule_id`, `surface`, `attempts`, `ip`, `expires`,
  `duration`, `preset_key`, `previous`, `current`, and `is_network` where
  applicable.

The bridge supports late Stream availability (mu-plugin loads before
regular plugins) by deferring registration to `plugins_loaded` when
needed. It remains inert when Stream APIs are unavailable.

### Optional Critical-Event Alert Bridge

WP Sudo ships an optional alert bridge at
`bridges/wp-sudo-critical-alert-bridge.php`. Install it as an mu-plugin to be
**notified** (not just logged) when a high-severity audit hook fires. It packages
the hook-to-notification wiring so you don't have to write it by hand; unlike the
Stream/WSAL bridges, which log every event, it pushes a message for the events
that usually warrant a human look.

Mapped events (default set): `wp_sudo_capability_tampered`,
`wp_sudo_escalation_blocked`, `wp_sudo_lockout`, and
`wp_sudo_gated_actions_missing_builtin_rules`. `wp_sudo_recovery_mode_active` is
supported but **opt-in** (it fires on every Sudo admin-page load during a
legitimate break-glass episode and would drown the urgent alerts).

Safety properties:

- **Deferred, never blocking.** Alerts are queued and dispatched on `shutdown`
  (which fires even after the gate's `wp_die()`), so a slow send never delays the
  security-blocking response — important because `wp_sudo_escalation_blocked`
  fires immediately before the gate dies.
- **Throttled against floods.** Each event is deduped per identity for a window,
  and a bridge-wide hourly cap collapses an incident into one "N more suppressed"
  summary — several of these hooks are attacker-driven at volume (lockout
  enumeration, per-request tamper), so an unthrottled bridge would amplify a
  mail/outbound flood.
- **Correct attribution.** `wp_sudo_escalation_blocked`'s first argument is the
  **target** being granted/deleted, not the actor; the alert states the target
  precisely and enriches with the current user as "Actor" separately.
- **Multisite-aware recipient.** Super-admin-scope events default to the network
  admin email; site events to the site admin email.

Filters/actions: `wp_sudo_critical_alert_events` (enabled keys),
`wp_sudo_critical_alert_recipient` (`string $email, array $event`),
`wp_sudo_critical_alert_throttle` (int seconds), `wp_sudo_critical_alert_hourly_cap`
(int), `wp_sudo_critical_alert_dispatch` (return non-null to **replace** the
default email — send Slack/Teams/webhook via `wp_remote_post`, or capture the
composed alert for inline display where outbound network is unavailable, e.g.
WordPress Playground), and the additive `wp_sudo_critical_alert_dispatched` action.

**Inline demo companion.** `bin/demo/wp-sudo-alert-inline-demo.php` is a
demo-only realization of that Playground use case (it is not shipped for
production — the `bin/` tree is demo/tooling, not part of the plugin runtime).
Dropped into `mu-plugins/` alongside the bridge, it listens on the additive
`wp_sudo_critical_alert_dispatched` action (never the replace-filter, so it
cannot suppress real alert email), buffers each composed alert in a short-lived
transient, and renders it as an admin notice on the next wp-admin load. Because
it is a demo it also relaxes the bridge's dedupe/hourly-cap (both → 0) so a live
walkthrough can re-trigger the same event and keep seeing it. The Playground
`blueprint-main.json` copies both the bridge and this companion into
`mu-plugins/` so "trigger a tamper/escalation/lockout → watch the alert fire" is
visible without any outbound network.

### Future: External Audit Mode (v3.2 candidate)

For operators who treat Stream or WSAL as their canonical audit destination,
a planned **External Audit Mode** setting will allow WP Sudo to stop writing
to its own `wpsudo_events` table while keeping all audit hooks (and
therefore both bridges) firing normally. The dashboard widget's Active
Sessions and Policy Summary panels are unaffected; the Recent Events panel
switches to a bridge-status tile linking to the destination plugin.
Activation is gated by a bridge-presence preflight to prevent silent audit
loss. See [`docs/external-audit-mode-spec.md`](external-audit-mode-spec.md)
for the full design. Not scheduled; optional Phase 5 of the v3.1–v3.3 plan.

## Filters

> **API-only, by design.** None of the filters below has a Settings → Sudo
> control. The Settings page deliberately exposes only the everyday knobs
> (session duration, quick policy presets, and the entry-point policies for
> REST App Passwords / CLI / Cron / XML-RPC / WPGraphQL). Advanced and
> security-sensitive behaviour is configured in code — a filter or a constant
> (see [Constants (no Settings UI)](#constants-no-settings-ui) below) — so the
> UI stays uncluttered and the dangerous switches are not a click away. This is
> the project's standing pattern: when a knob is rarely needed or carries a
> footgun, it lives in the API, not the UI.

| Filter | Description |
|---|---|
| `wp_sudo_gated_actions` | Add, modify, or intentionally remove gated action rules. Site Health warns when built-in rule IDs are missing after filtering. |
| `wp_sudo_grant_session_on_login` | Whether to grant a sudo session automatically on browser login (`apply_filters( 'wp_sudo_grant_session_on_login', true, $user )`). Default `true`. Return `false` for shared-terminal/kiosk hardening or SSO control. Caution: suppressing the grant for users without a usable WordPress password (common with SSO) makes gated actions unreachable for them — only suppress for users who can pass the password challenge. Since 3.3.0. |
| `wp_sudo_two_factor_window` | 2FA authentication window in seconds (default: 300). Clamped to 60–900 seconds (1–15 minutes). |
| `wp_sudo_requires_two_factor` | Whether a user needs 2FA for sudo (for third-party 2FA plugins). |
| `wp_sudo_validate_two_factor` | Validate a 2FA code (for third-party 2FA plugins). |
| `wp_sudo_log_passed_events_enabled` | Toggle recording of `action_passed` dashboard events. Default `true`; intended for explicit code-level overrides only. |
| `wp_sudo_critical_options` | The option names gated by the built-in `options.critical` rule (default: `siteurl`, `home`, `admin_email`, `new_admin_email`, `default_role`, `users_can_register`). Removing an entry silently un-gates that option — narrow the built-in protection set with care. |
| `wp_sudo_sensitive_stash_keys` | Lowercase field-name keys omitted from a stashed request before replay (default includes `password`, `user_pass`, `pass1`/`pass2`, `token`, `secret`, …). Matched case-insensitively, including nested keys. Over-matching is safe (the field is dropped and the user resubmits); under-matching risks replaying a secret. |
| `wp_sudo_cookie_secure` | Whether session/2FA cookies set the `Secure` flag (default `is_ssl() \|\| force_ssl_admin()`). Returning `false` on production HTTPS exposes the cookie over plain HTTP — change only for known reverse-proxy/TLS-termination setups. |
| `wp_sudo_wpgraphql_classification` | Classify WPGraphQL body as `mutation` or `query` (persisted-query support). |
| `wp_sudo_wpgraphql_bypass` | Bypass WPGraphQL Limited-mode gating for specific requests. |
| `wp_sudo_guard_escalation` | Master on/off switch for the admin-escalation guard (`apply_filters( 'wp_sudo_guard_escalation', false )`). **Default `false` (guard OFF).** Return `true` to block a *newly granted* `administrator` (single-site) / super-admin (multisite) — and to alarm on administrator deletion — unless the actor **both** holds the promoting authority (`promote_users` on the target blog, or existing super-admin for `grant_super_admin`) **and** has an active or grace sudo session. (Sudo is reauthentication, not authorization: a low-privilege account can hold a session, so the authority check is what stops an under-privileged actor.) Effect-level (capabilities-meta write + `grant_super_admin`), so it applies on every surface; defers on CLI/Cron/XML-RPC and on Unrestricted REST Application-Password requests. Since 4.1.0. |
| `wp_sudo_allow_escalation` | Allowlist a specific administrator/super-admin grant past the escalation guard (`apply_filters( 'wp_sudo_allow_escalation', false, int $target_id, mixed $context )`). Default `false`. Return `true` to let a trusted provisioner (SSO/SAML/OIDC, directory sync) through. `$context` varies by path: the incoming capabilities array on the single-site promote path, the string `'super-admin'` on the multisite path, or `'delete'` on the admin-deletion path. Since 4.1.0. |

### Constants (no Settings UI)

Defined in `wp-config.php` (or earlier than plugin load). Like the filters
above, none has a Settings → Sudo control — these are deliberately code-only
break-glass / environment switches.

| Constant | Effect | Since |
|---|---|---|
| `WP_SUDO_ALLOW_ESCALATION` | Define `true` to bypass the admin-escalation guard entirely. Checked **first**, before any session or capability read, so deployment, migration, and sole-admin recovery flows are never hard-blocked by the guard. Constant form of the `wp_sudo_allow_escalation` filter. | 4.1.0 |
| `WP_SUDO_RECOVERY_MODE` | Break-glass recovery. While defined, any user holding `manage_options` (`manage_network_options` on multisite) regains full Sudo governance access regardless of role — the escape hatch for a misconfigured/last-manager lockout. Weakens the governance model while set; remove as soon as normal access is restored. See [`wp_sudo_is_recovery_mode()`](#wp_sudo_is_recovery_mode-bool). | 3.4.0 |
| `WP_SUDO_DISABLE_PASSED_EVENT_LOGGING` | Define `true` to stop recording `action_passed` dashboard events (reduced audit visibility for actions performed during an active sudo session). Checked **before** the `wp_sudo_log_passed_events_enabled` filter, which can still override per-request. | 3.0.0 |

Internal/structural constants (`WP_SUDO_VERSION`, `WP_SUDO_PLUGIN_DIR`,
`WP_SUDO_PLUGIN_URL`, `WP_SUDO_PLUGIN_BASENAME`, `WP_SUDO_MU_LOADED`) are set by
the bootstrap/MU loader, not operator knobs — do not define them by hand.

> **Planned (not yet implemented):** if gating of editor *site-design* writes
> (`/wp/v2/global-styles`, `/wp/v2/font-families`) is added, it will follow this
> same pattern — a **default-OFF filter opt-in**, not a Settings field — so the
> gray-area routes stay out of the UI unless an operator explicitly enables
> them. See `.planning/post-4.1.0-dev-scopes.md` (route inventory).

**Action (not a filter):** `wp_sudo_render_two_factor_fields` — `do_action( 'wp_sudo_render_two_factor_fields', WP_User $user )` echoes 2FA input fields on the challenge form for third-party 2FA plugins. See [two-factor-integration.md](two-factor-integration.md).

## MU Loader Diagnostics Hook

When the optional MU loader cannot resolve the main plugin file path, it emits:

```php
do_action( 'wp_sudo_mu_loader_unresolved_plugin_path', array $file_candidates );
```

Use this for operational visibility on non-canonical plugin layouts or broken
deploy states where the shim is present but the main plugin path is unresolved.

## Testing

Two test environments are used deliberately — choose based on what you are testing:

**Unit tests** (`tests/Unit/`) use Brain\Monkey to mock all WordPress functions. Fast (~0.3s total). Run with `composer test:unit`. Use for: request matching logic, session state machine, policy enforcement, hook registration, settings sanitization.

**Integration tests** (`tests/Integration/`) load real WordPress against a MySQL database via `WP_UnitTestCase`. Run with `composer test:integration` (requires one-time setup — see [CONTRIBUTING.md](../CONTRIBUTING.md)). Use for: full reauth flows, real bcrypt verification, transient TTL and cookie behavior, REST and AJAX gating, Two Factor interaction, multisite session isolation, upgrader migrations.

When in doubt: if the test needs a real database, real crypto, or calls that cross class boundaries in production, write an integration test.

Static analysis:

- `composer analyse:phpstan` runs PHPStan.
- `composer analyse:psalm` runs Psalm with the WordPress Psalm plugin/stubs.
- `composer analyse` runs both analyzers.

Code style: `composer lint` (PHPCS, WordPress-Extra + WordPress-Docs + WordPressVIPMinimum rulesets). Auto-fix with `composer lint:fix`.

Manual testing: see [`tests/MANUAL-TESTING.md`](../tests/MANUAL-TESTING.md) for step-by-step verification procedures against a real WordPress environment.

## Session API

### `Sudo_Session::is_active( int $user_id ): bool`

Returns `true` if the user has an unexpired sudo session with a valid token. This is the primary check used throughout the plugin. Returns `false` and defers meta cleanup if the session has expired within the grace window (see `is_within_grace()`).

### `Sudo_Session::is_within_grace( int $user_id ): bool`

Returns `true` when the session has expired **within the last `GRACE_SECONDS` (120 s)** and the session token still matches the cookie. Used by the Gate at interactive decision points (admin UI, REST, WPGraphQL) to allow in-flight form submissions to complete after the session timer expires.

Session binding is enforced during the grace window — `verify_token()` is called before returning `true`. A stolen cookie on a different browser does not gain grace access.

The admin bar UI uses `is_active()` only; it always reflects the true session state.

### `Sudo_Session::activate( int $user_id ): bool`

Creates a new sudo session: generates a token, writes user meta, sets the httponly cookie, and fires `wp_sudo_activated`. Returns `true` on success. Also called automatically by `Plugin::grant_session_on_login()` on successful browser-based login (`wp_login` hook); that automatic grant can be suppressed via the `wp_sudo_grant_session_on_login` filter.

### `Sudo_Session::deactivate( int $user_id ): void`

Ends the user's sudo session: clears the session user meta, expires the httponly cookie, and fires `wp_sudo_deactivated`. Called on explicit toolbar deactivation and automatically on password change.

### `Sudo_Session::GRACE_SECONDS`

Class constant (`int 120`). The length of the grace window in seconds. Can be referenced in custom code that inspects session state.

## WPGraphQL Surface

WP Sudo adds WPGraphQL as a fifth non-interactive surface alongside WP-CLI, Cron, XML-RPC, and Application Passwords. The policy setting key is `wpgraphql_policy` (stored in `wp_sudo_settings`). The three-tier model applies: Disabled, Limited (default), Unrestricted.

**How gating works.** WPGraphQL does not use the WordPress REST API pipeline — it dispatches requests via rewrite rules at `parse_request`. WP Sudo hooks into WPGraphQL's own `graphql_process_http_request` action, which fires after authentication but before body reading, regardless of how the endpoint is named or configured. In Limited mode, WP Sudo decodes JSON bodies, GET/form `query` params, and multipart `operations` payloads, scans inline GraphQL documents for a top-level `mutation` operation token, and blocks mutations unless the requesting user has an active sudo session.

**Why surface-level rather than per-action.** The action registry rules are keyed to WordPress action hooks — `activate_plugin`, `delete_user`, `wp_update_options`, etc. — that fire regardless of entry surface. WPGraphQL mutations do not reliably fire those same hooks; they dispatch through WPGraphQL's own resolver chain, and the mapping from mutation name to WordPress hook depends entirely on how each resolver is implemented. Per-action gating would therefore require either (a) parsing the GraphQL request body to extract operation names and maintaining a mutation→hook mapping across the full WPGraphQL ecosystem, or (b) a new WPGraphQL-specific rule type separate from the hook-based registry. Both approaches carry significant ongoing maintenance cost. The surface-level heuristic — tokenize the request body to detect a top-level `mutation` operation — is reliable for the primary use case (headless deployments where mutations come from automated clients, not interactive users) and the `wp_sudo_wpgraphql_bypass` filter provides the escape hatch for mutations that should not require a sudo session (see below).

**Headless deployments.** The Limited policy requires both a recognized WordPress user and an active sudo session cookie. For frontends running at a different origin, this means mutations will be blocked in most configurations — the sudo session cookie is browser-bound and can only be created via the WordPress admin UI. See [WPGraphQL: Headless Authentication Boundary](security-model.md#wpgraphql-headless-authentication-boundary) in the security model for full details and per-deployment policy recommendations.

**Persisted queries.** The default heuristic extracts inline GraphQL documents from the request body and scans each for a top-level `mutation` operation token (see `extract_wpgraphql_documents()` and `wpgraphql_document_contains_mutation()` in `includes/class-gate.php`). A WPGraphQL Persisted Queries (or APQ) request whose body carries only an operation id or hash (`id`, `queryId`, `documentId`, or an `extensions.persistedQuery` object) contains no inline document — the tokenizer cannot determine the operation type, so the request is **blocked by default** (fail-safe). Use `wp_sudo_wpgraphql_classification` to classify persisted requests as `query` so read-only persisted operations can pass through. If all persisted requests should pass through, use Unrestricted policy.

**Block-string tokenizer.** `wpgraphql_document_contains_mutation()` mirrors graphql-php's lexer so the gate sees the same operation structure the server will execute. GraphQL block strings (`"""…"""`) recognize exactly one escape sequence — `\"""` (an escaped triple-quote) — and have **no** `\\` escaped-backslash rule, unlike ordinary `"…"` strings; only an *unescaped* `"""` terminates the block. So a sequence like `\\"""` is a literal backslash followed by an escaped `\"""`, which leaves the block string unterminated — graphql-php then raises an `Unterminated string` syntax error and executes nothing, so there is no mutation to gate. Verified against `webonyx/graphql-php` `src/Language/Lexer.php` (`readBlockString()`), where the escape branch fires only when a backslash is immediately followed by three quote characters. When editing the tokenizer, preserve this equivalence — do not switch to an "even number of backslashes terminates" model, which would diverge from the real lexer.

### `wp_sudo_wpgraphql_classification` filter

Classifies a GraphQL request body as mutation or query before the default
decoded-payload classifier is used.

```php
/**
 * @param string $classification '' by default; return 'mutation' or 'query'.
 * @param string $body           The raw GraphQL request body.
 * @return string
 */
apply_filters( 'wp_sudo_wpgraphql_classification', '', $body );
```

- Return `'mutation'` to force mutation handling.
- Return `'query'` to force non-mutation handling.
- Return any other value to fall back to default decoded-payload classification.

### `wp_sudo_wpgraphql_bypass` filter

Fires in Limited mode before mutation detection. Return `true` to allow the request through without sudo session checks. Does **not** fire in Disabled or Unrestricted mode — those policies return before this point.

```php
/**
 * @param bool   $bypass Whether to bypass gating. Default false.
 * @param string $body   The raw GraphQL request body.
 * @return bool
 */
apply_filters( 'wp_sudo_wpgraphql_bypass', false, $body );
```

**JWT authentication example.** The [wp-graphql-jwt-authentication](https://github.com/wp-graphql/wp-graphql-jwt-authentication) plugin adds `login` and `refreshJwtAuthToken` mutations. These must bypass WP Sudo because they *are* the authentication mechanism — the `login` mutation is sent by unauthenticated users who cannot have a sudo session. Add this to an mu-plugin or theme:

```php
add_filter( 'wp_sudo_wpgraphql_bypass', function ( bool $bypass, string $body ): bool {
    if ( $bypass ) {
        return $bypass;
    }

    $payload = json_decode( $body, true );
    $query   = is_array( $payload ) && is_string( $payload['query'] ?? null )
        ? $payload['query']
        : '';

    // Exempt only documents whose first top-level mutation field is a JWT
    // authentication operation. Anchoring to the document shape matters: a
    // `login` alias, a field name like `loginName`, or the substring inside
    // a string argument elsewhere in the body must NOT trigger the bypass.
    return (bool) preg_match(
        '/^\s*mutation\b[^{]*\{\s*(login|refreshJwtAuthToken)\s*[({]/',
        $query
    );
}, 10, 2 );
```

A bypass filter is security-sensitive in the **over-match** direction: every request it passes skips Limited-mode gating entirely. A naive substring check such as `str_contains( $body, 'login' )` would let *any* mutation through ungated as long as the string `login` appears anywhere in the body — in an alias, a field name like `loginName`, or a string argument. Prefer under-matching (a legitimate auth mutation gets challenged — recoverable friction) over over-matching (a destructive mutation passes silently). Note one residual limit of the anchored example above: a document that pairs an auth operation with a second top-level mutation field (`mutation { login(...) {...} deleteUser(...) {...} }`) would still match on the first field. If that matters in your deployment, validate the complete document or use a persisted-query allowlist instead of pattern matching.
