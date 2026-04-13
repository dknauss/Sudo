# Connectors API Reference (WP 7.0)

*Drafted 2026-04-13 from `wordpress-develop` trunk source code.*
*There is now an official Core dev note for the Connectors API, but this reference remains source-derived because it goes deeper into the REST masking/write path than the high-level announcement. Verify against the GA release before relying on implementation details.*

**Official dev note:** [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)

**Source files:**

- `src/wp-includes/connectors.php` — public API functions and internal helpers
- `src/wp-includes/class-wp-connector-registry.php` — singleton registry class
- `src/wp-admin/options-connectors.php` — admin settings page
- `src/wp-admin/menu.php` — menu registration

---

## Admin Page

| Property | Value |
|---|---|
| Menu location | Settings > Connectors |
| File | `wp-admin/options-connectors.php` |
| Parent | `options-general.php` |
| Capability | `manage_options` |
| Rendering | Client-rendered via `wp_options_connectors_wp_admin_render_page()` |

The page is a script-module-driven UI, not a traditional PHP form. There is no
`options.php` POST submission. Credential saves go through the REST API.

---

## Credential Storage

API keys are stored as **plaintext WordPress options** via `register_setting()`.
No encryption at rest.

### Setting name pattern

```
connectors_{type}_{id}_api_key
```

Hyphens in `{type}` and `{id}` are normalized to underscores.

### Built-in connectors (WP 7.0 defaults)

| Connector ID | Setting name | PHP constant | Env var | Credentials URL |
|---|---|---|---|---|
| `anthropic` | `connectors_ai_anthropic_api_key` | `ANTHROPIC_API_KEY` | `ANTHROPIC_API_KEY` | `https://platform.claude.com/settings/keys` |
| `google` | `connectors_ai_google_api_key` | `GOOGLE_API_KEY` | `GOOGLE_API_KEY` | `https://aistudio.google.com/api-keys` |
| `openai` | `connectors_ai_openai_api_key` | `OPENAI_API_KEY` | `OPENAI_API_KEY` | `https://platform.openai.com/api-keys` |

### Key resolution order

`_wp_connectors_get_api_key_source()` checks in this order:

1. **Environment variable** (`getenv()`) — highest precedence
2. **PHP constant** (`defined()` / `constant()`) — e.g., in `wp-config.php`
3. **Database** (`get_option()`) — the Settings > Connectors UI writes here

If an env var or constant provides the key, the database value is not used.

### Key validation

On save (POST/PUT to `/wp/v2/settings`), AI provider keys are validated against
the provider's API via `_wp_connectors_is_ai_api_key_valid()`. Invalid keys are
reverted to an empty string. Non-AI connectors accept keys without validation.

---

## REST API Surface

Connector settings are registered with `show_in_rest => true` and setting group
`'connectors'`. They are read and written through the standard WordPress settings
endpoint.

### Read credentials

```
GET /wp/v2/settings
```

Response includes connector setting fields. Keys are **always masked** in the
response (last 4 characters visible, rest replaced with bullets). Raw keys are
never exposed via REST. Masking is applied by a `rest_post_dispatch` filter
(`_wp_connectors_rest_settings_dispatch()`).

### Write credentials

```
POST /wp/v2/settings
Content-Type: application/json

{
    "connectors_ai_anthropic_api_key": "sk-ant-..."
}
```

- Requires `manage_options` capability (standard settings endpoint auth)
- AI provider keys are validated against the provider before storage
- Invalid keys are silently reverted to empty string
- Response returns the masked version of the saved key

**This is the primary gating target for WP Sudo.** The `POST /wp/v2/settings`
route is the only write path for connector credentials from the admin UI.

---

## Hooks

### Actions

| Hook | Fires when | Parameters |
|---|---|---|
| `wp_connectors_init` | Registry is ready for plugin registration (during `init`) | `WP_Connector_Registry $registry` |

### Filters

| Hook | Purpose | Parameters |
|---|---|---|
| `rest_post_dispatch` | Masks API keys in REST responses; validates on save | `WP_REST_Response, WP_REST_Server, WP_REST_Request` |
| `script_module_data_options-connectors-wp-admin` | Exposes connector data to the admin JS module | `array $data` |

### Settings registration

Connector settings are registered at `init` priority 20 via
`_wp_register_default_connector_settings()`. Each `api_key` connector gets a
`register_setting()` call with:

```php
register_setting( 'connectors', $setting_name, array(
    'type'              => 'string',
    'show_in_rest'      => true,
    'sanitize_callback' => 'sanitize_text_field',
) );
```

---

## Public API Functions

| Function | Purpose | Returns |
|---|---|---|
| `wp_is_connector_registered( $id )` | Check if a connector exists | `bool` |
| `wp_get_connector( $id )` | Retrieve a single connector's data | `array\|null` |
| `wp_get_connectors()` | Retrieve all registered connectors | `array` |

### Connector data structure

```php
array(
    'name'           => 'Anthropic',
    'description'    => 'Text generation with Claude.',
    'logo_url'       => 'https://example.com/logo.svg',  // optional
    'type'           => 'ai_provider',
    'authentication' => array(
        'method'          => 'api_key',           // 'api_key' or 'none'
        'credentials_url' => 'https://...',       // optional
        'setting_name'    => 'connectors_ai_anthropic_api_key',
        'constant_name'   => 'ANTHROPIC_API_KEY', // optional
        'env_var_name'    => 'ANTHROPIC_API_KEY',  // optional
    ),
    'plugin' => array(                            // optional
        'file' => 'ai-provider-for-anthropic/plugin.php',
    ),
)
```

---

## Registering a Custom Connector

```php
add_action( 'wp_connectors_init', function ( WP_Connector_Registry $registry ) {
    $registry->register( 'my-service', array(
        'name'           => 'My Service',
        'description'    => 'Integration with My Service API.',
        'type'           => 'my_type',
        'authentication' => array(
            'method'          => 'api_key',
            'credentials_url' => 'https://my-service.com/api-keys',
        ),
    ) );
} );
```

The `setting_name` is auto-generated as `connectors_my_type_my_service_api_key`
when omitted. Connector IDs must match `/^[a-z0-9_-]+$/`.

---

## WP Sudo Gating Analysis

### Credential save path

The Connectors UI saves credentials via `POST /wp/v2/settings`. This route is
already in scope for `Gate::intercept_rest()`. A rule matching this route with
the connector setting names would gate credential changes:

```php
// Example rule for Action_Registry
[
    'id'    => 'connectors.update_credentials',
    'label' => __( 'Update connector credentials', 'wp-sudo' ),
    'rest'  => [
        'route'   => '#^/wp/v2/settings$#',
        'methods' => [ 'POST', 'PUT', 'PATCH' ],
        'callback' => function () {
            // Only gate when connector settings are in the request body.
            $input = file_get_contents( 'php://input' );
            return false !== strpos( $input, 'connectors_' );
        },
    ],
],
```

**Caveat:** `POST /wp/v2/settings` is a general-purpose endpoint. A rule
matching it broadly would gate all settings changes via REST, not just
connectors. The `callback` filter above narrows it to connector-related changes.
This heuristic is similar to the WPGraphQL mutation detection approach — blunt
but safe to over-match.

### Key exposure via REST

Raw API keys are never returned by the REST API. The `rest_post_dispatch` filter
masks them before the response reaches the client. An attacker with a stolen
session cannot read existing keys via `GET /wp/v2/settings` — they only see the
masked version (e.g., `••••••••••••fj39`).

However, the attacker can **overwrite** keys via `POST /wp/v2/settings`. This
is the real attack vector: a **credential integrity** failure rather than a
credential disclosure failure. The attacker does not need to know the original
secret; they only need to replace it with one they control.

### Hardened key storage bypass

Keys provided via environment variable or PHP constant cannot be effectively
replaced through the REST API because the database value is ignored when a
higher-precedence source exists. This is the strongest deployment-side
mitigation against the admin-UI replacement vector: sites that set
`ANTHROPIC_API_KEY` in `wp-config.php` or the environment largely neutralize
credential replacement via Settings → Connectors, even though in-process code
still shares the same broader trust boundary.
