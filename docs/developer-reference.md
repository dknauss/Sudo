# Developer Reference

## Gated Action Rule Structure

Use the `wp_sudo_gated_actions` filter to add custom rules. Each rule defines matching criteria for admin UI (`pagenow`, actions, HTTP method), AJAX (action names), and REST (route patterns, HTTP methods). Custom rules appear in the Gated Actions table on the settings page.

Rule ingestion is normalized before caching. Invalid filtered entries are dropped
fail-closed per rule (required scalar metadata: `id`, `label`, `category`;
surface shapes must be array-or-null for `admin`, `ajax`, `rest`). If the
filter returns a non-array payload, WP Sudo falls back to built-in rules.

All rules — including custom rules — are automatically protected on non-interactive surfaces (WP-CLI, Cron, XML-RPC, Application Passwords) via the configurable policy settings, even if they don't define AJAX or REST criteria. WPGraphQL is gated by its own surface-level policy rather than per-rule matching — in Limited mode, all mutations require a sudo session regardless of which action they perform. See [WPGraphQL Surface](#wpgraphql-surface) below.

```php
add_filter( 'wp_sudo_gated_actions', function ( array $rules ): array {
    $rules[] = array(
        'id'       => 'custom.my_action',
        'label'    => 'My dangerous action',
        'category' => 'custom',
        'admin'    => array(
            'pagenow'  => 'admin.php',
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
            'route'   => '#^/my-namespace/v1/dangerous#',
            'methods' => array( 'POST', 'DELETE' ),
        ),
    );
    return $rules;
} );
```

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

## Audit Hook Signatures

Sudo fires 9 action hooks for external logging integration with [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/), [Stream](https://wordpress.org/plugins/stream/), and similar plugins.

```php
// Session lifecycle.
do_action( 'wp_sudo_activated', int $user_id, int $expires, int $duration );
do_action( 'wp_sudo_deactivated', int $user_id ); // Also fires on password change (v2.8.0).

// Authentication failures.
do_action( 'wp_sudo_reauth_failed', int $user_id, int $attempts );
do_action( 'wp_sudo_lockout', int $user_id, int $attempts );

// Action gating.
// $surface values: 'admin', 'ajax', 'rest_app_password', 'cli', 'cron', 'xmlrpc', 'wpgraphql'
do_action( 'wp_sudo_action_gated', int $user_id, string $rule_id, string $surface );
do_action( 'wp_sudo_action_blocked', int $user_id, string $rule_id, string $surface );
do_action( 'wp_sudo_action_allowed', int $user_id, string $rule_id, string $surface ); // Unrestricted policy (v2.9.0).
do_action( 'wp_sudo_action_replayed', int $user_id, string $rule_id );

// Tamper detection.
do_action( 'wp_sudo_capability_tampered', string $role, string $capability );
```

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
| `wp_sudo_action_replayed` | `1900008` |
| `wp_sudo_capability_tampered` | `1900009` |

The bridge is inert when WSAL APIs are unavailable.

Stream parity note: WSAL bridge is first-party in this phase. Stream mapping is
planned next (same WP Sudo hook source, Stream record writer target).

## Filters

| Filter | Description |
|---|---|
| `wp_sudo_gated_actions` | Add or modify gated action rules. |
| `wp_sudo_two_factor_window` | 2FA authentication window in seconds (default: 300). Clamped to 60–900 seconds (1–15 minutes). |
| `wp_sudo_requires_two_factor` | Whether a user needs 2FA for sudo (for third-party 2FA plugins). |
| `wp_sudo_validate_two_factor` | Validate a 2FA code (for third-party 2FA plugins). |
| `wp_sudo_render_two_factor_fields` | Render 2FA input fields (for third-party 2FA plugins). |
| `wp_sudo_wpgraphql_classification` | Classify WPGraphQL body as `mutation` or `query` (persisted-query support). |
| `wp_sudo_wpgraphql_bypass` | Bypass WPGraphQL Limited-mode gating for specific requests. |

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

### `Sudo_Session::activate( int $user_id ): void`

Creates a new sudo session: generates a token, writes user meta, sets the httponly cookie, and fires `wp_sudo_activated`. Also called automatically by `Plugin::grant_session_on_login()` on successful browser-based login (`wp_login` hook).

### `Sudo_Session::GRACE_SECONDS`

Class constant (`int 120`). The length of the grace window in seconds. Can be referenced in custom code that inspects session state.

## WPGraphQL Surface

WP Sudo adds WPGraphQL as a fifth non-interactive surface alongside WP-CLI, Cron, XML-RPC, and Application Passwords. The policy setting key is `wpgraphql_policy` (stored in `wp_sudo_settings`). The three-tier model applies: Disabled, Limited (default), Unrestricted.

**How gating works.** WPGraphQL does not use the WordPress REST API pipeline — it dispatches requests via rewrite rules at `parse_request`. WP Sudo hooks into WPGraphQL's own `graphql_process_http_request` action, which fires after authentication but before body reading, regardless of how the endpoint is named or configured. In Limited mode, requests whose POST body contains the string `mutation` are blocked unless the requesting user has an active sudo session.

**Why surface-level rather than per-action.** The action registry rules are keyed to WordPress action hooks — `activate_plugin`, `delete_user`, `wp_update_options`, etc. — that fire regardless of entry surface. WPGraphQL mutations do not reliably fire those same hooks; they dispatch through WPGraphQL's own resolver chain, and the mapping from mutation name to WordPress hook depends entirely on how each resolver is implemented. Per-action gating would therefore require either (a) parsing the GraphQL request body to extract operation names and maintaining a mutation→hook mapping across the full WPGraphQL ecosystem, or (b) a new WPGraphQL-specific rule type separate from the hook-based registry. Both approaches carry significant ongoing maintenance cost. The surface-level heuristic — block any body containing `mutation` — is reliable for the primary use case (headless deployments where mutations come from automated clients, not interactive users) and the `wp_sudo_wpgraphql_bypass` filter provides the escape hatch for mutations that should not require a sudo session (see below).

**Headless deployments.** The Limited policy requires both a recognized WordPress user and an active sudo session cookie. For frontends running at a different origin, this means mutations will be blocked in most configurations — the sudo session cookie is browser-bound and can only be created via the WordPress admin UI. See [WPGraphQL: Headless Authentication Boundary](security-model.md#wpgraphql-headless-authentication-boundary) in the security model for full details and per-deployment policy recommendations.

**Persisted queries.** The default `str_contains($body, 'mutation')` heuristic does not detect mutations sent via WPGraphQL Persisted Queries (body contains only hash/ID). Use `wp_sudo_wpgraphql_classification` to classify persisted requests as mutation/query. If you do not provide classifier coverage and strict blocking is required, use Disabled policy.

### `wp_sudo_wpgraphql_classification` filter

Classifies a GraphQL request body as mutation or query before the legacy
heuristic is used.

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
- Return any other value to fall back to default body-string heuristic.

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
    // Exempt JWT authentication mutations only.
    if ( str_contains( $body, 'login' ) || str_contains( $body, 'refreshJwtAuthToken' ) ) {
        return true;
    }
    return false;
}, 10, 2 );
```

This uses the same `str_contains()` heuristic as the mutation detection. For more precise matching, use `preg_match()` to extract the mutation operation name from the body.
