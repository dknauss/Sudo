# Connectors API Reference (WP 7.0)

*Drafted 2026-04-13 from `wordpress-develop` trunk source code.*
*Updated 2026-04-18 with local runtime verification in WordPress Studio (`7.0-RC2-62241`) for REST save behavior, key-source precedence, and Connectors admin UI state handling.*
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

Response includes connector setting fields. In normal cases, keys are masked in
the response (last 4 characters visible, rest replaced with bullets) by the
`rest_post_dispatch` filter (`_wp_connectors_rest_settings_dispatch()`).

**Correctness edge case:** `_wp_connectors_mask_api_key()` returns values of
length 4 or fewer unchanged. That is not a realistic concern for ordinary LLM
provider keys, but it means the implementation is not literally "always
masked."

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
- Invalid AI-provider keys are reverted to empty string server-side; the raw
  REST write still returns `200 OK`
- Response returns the masked version of the saved key

The stock Connectors UI compensates for this lossy REST contract: if the
returned value is empty or unchanged after save, it shows an inline error
instead of treating the connector as successfully configured.

**WP Sudo now gates this path.** On current `main`, REST writes to
`/wp/v2/settings` are challenged when the request body contains connector-style
credential keys matching `connectors_*_api_key`. This protects the Connectors
admin UI save path without broadly gating unrelated REST settings writes.

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
already in scope for `Gate::intercept_rest()`, and WP Sudo now ships a built-in
rule:

- **Rule ID:** `connectors.update_credentials`
- **Route:** `#^/wp/v2/settings$#`
- **Methods:** `POST`, `PUT`, `PATCH`
- **Matcher:** only fires when request params contain setting names matching
  `connectors_[a-z0-9_]+_api_key`

This is intentionally narrower than gating the entire settings endpoint. Normal
REST settings updates remain untouched unless the request is attempting to
replace a connector credential.

### Key exposure via REST

In ordinary cases, raw API keys are not returned by the REST API. The
`rest_post_dispatch` filter masks them before the response reaches the client.
An attacker with a stolen session cannot normally read existing keys via
`GET /wp/v2/settings` — they only see the masked version (e.g.,
`••••••••••••fj39`).

There is a correctness edge case for values of length 4 or fewer, which are
returned unchanged by `_wp_connectors_mask_api_key()`. That is source- and
runtime-verified, but not a realistic practical concern for typical AI provider
credentials.

However, without an additional guard an attacker could still **overwrite** keys
via `POST /wp/v2/settings`. That is the real attack vector: a **credential
integrity** failure rather than a credential disclosure failure. The attacker
does not need to know the original secret; they only need to replace it with
one they control.

Current WP Sudo `main` mitigates that path by challenging connector credential
writes before they reach the settings save handler.

### Hardened key storage bypass

Keys provided via environment variable or PHP constant cannot be effectively
replaced through the REST API because the database value is ignored when a
higher-precedence source exists. This is the strongest deployment-side
mitigation against the admin-UI replacement vector: sites that set
`ANTHROPIC_API_KEY` in `wp-config.php` or the environment largely neutralize
credential replacement via Settings → Connectors, even though in-process code
still shares the same broader trust boundary.

---

## Failure and Exploit Scenarios

This section enumerates the ways connector credential integrity can fail in
practice. Scenarios are grouped by attacker capability so readers don't
conflate "bug in the UI" with "attacker with shell access." The interesting
case for core is the first one: the most important consequences listed under
**admin session only** are reachable from a single `POST /wp/v2/settings`
write, with no filesystem access, no plugin install, and no code execution.

**Verification note (2026-04-18):** The corrections below reflect local runtime
testing in WordPress Studio on `7.0-RC2-62241` plus source inspection of the
bundled Connectors UI build. Where a claim is conditional rather than
end-to-end runtime-proven, that is stated explicitly.

### No attacker — ordinary failure modes

These are UX and operational defects independent of any malicious actor.

- **Lossy invalid-save semantics at the REST layer.** An admin mistypes or
  paste-truncates a new AI-provider key. Validation against the provider
  fails, the server clears the stored option to an empty string, and the raw
  REST write still returns `200 OK` instead of a structured `WP_Error`.
  The stock Connectors UI does surface an inline error, but generic REST
  callers and audit tooling have to infer "failed validation and nullified"
  from response shape rather than status code.
- **Database writes can be ignored by higher-precedence sources.** If a
  connector is actually backed by an environment variable or PHP constant,
  a direct REST or database write to the option can succeed while the
  runtime continues to use the env/constant value. That is real precedence
  behavior and easy to miss in custom tooling.
- **Key-source provenance is only partially explicit.** The stock UI does
  surface whether a key is coming from an environment variable or a PHP
  constant, and externally configured connectors are rendered read-only.
  However, database-backed keys are not called out with equally explicit
  provenance beyond normal editable-state behavior.

### Attacker with `manage_options` session only

These scenarios require nothing beyond a valid admin REST session — session
hijack, stolen cookie, compromised admin credentials, XSS-escalated
privileges, or a rogue admin. No filesystem write, no plugin install, no
code execution. Reachable with one REST call.

- **Prompt exfiltration via same-provider key swap.** For a database-backed
  connector that is actually in use, an attacker can replace the stored key
  with one they control via `POST /wp/v2/settings`. Future requests for that
  provider then authenticate as the replacement credential. For same-provider
  swaps (for example, OpenAI-to-OpenAI), outbound traffic still goes to the
  same provider hostname; the security consequence is changed account
  ownership, billing, and prompt visibility rather than a visible endpoint
  change. This consequence follows from the connector write path and runtime
  precedence rules; I did not independently verify a third-party provider
  dashboard showing the prompts.
- **Ping-pong swap weakens simple forensic detection.** An attacker can swap
  in their key, allow a window of AI traffic, then restore the original key.
  That does not make logging useless — durable logs would still show two
  writes — but it does defeat simple before/after state inspection and any
  detection strategy that only looks for the final stored value.
- **Persistence past session cleanup.** The swapped key lives in
  `wp_options`. Resetting the admin password, invalidating sessions, and
  enrolling 2FA do not touch `wp_options`. An incident-response playbook
  that doesn't explicitly rotate connector credentials leaves the
  exfiltration channel open after the original intrusion is "remediated."
- **Weaponized nullification / breakage.** Attacker deliberately writes a
  malformed key. The server clears the stored option and returns `200 OK`.
  The stock UI will show an error if it initiated the save, but the lossy
  write semantics still let an attacker break the connector through the same
  settings path and create noise that can be mistaken for operator error.
- **Validation oracle (secondary concern).** Because
  `_wp_connectors_is_ai_api_key_valid()` probes the provider during a
  write, an attacker holding a stolen-but-unverified key can confirm it's
  live from the victim site's own environment. This is a lower-stakes side
  effect than the swap vector — an attacker who can already write
  `wp_options` has better options — but it is still a probe capability that
  comes "for free" with the save path.

### Attacker with filesystem access

These scenarios require code-execution-adjacent capability (editing
`wp-config.php`, adding a mu-plugin, or plugin/theme edit). They compose
with the admin-session scenarios above but are not the core-ticket concern.
Listed here for completeness because operators investigating a suspected
key-swap incident need to check these too.

- **Constant or env override defeats database rotation attempts outside the
  stock UI.** Attacker drops
  `define( 'OPENAI_API_KEY', 'sk-attacker...' );` into `wp-config.php`, or
  sets the equivalent environment variable. The stock Connectors UI does
  correctly show that the connector is externally configured and renders it
  read-only, so "UI says saved but constant still wins" is not accurate for
  the default screen. The real risk is broader: any direct option write,
  custom admin tool, or incident-response script that rotates only the
  database value can report success while the higher-precedence source keeps
  winning at runtime.
- **Response tampering via filter hook.** Attacker plants a
  `pre_http_request` filter (mu-plugin or theme edit) that rewrites the
  outbound request URL to an attacker-controlled proxy, or returns
  fabricated responses directly. Combined with a key swap, this makes the
  attacker a full man-in-the-middle for AI traffic — they read prompts and
  control responses. If AI responses are rendered into site content
  (summaries, moderation verdicts, generated copy), tampered responses
  become a content-integrity and potentially XSS vector.
- **Custom provider registration.** Attacker registers a malicious
  provider in the AI client registry pointing at their own endpoint, with
  a legitimate-looking display name. Admin may not notice an extra entry
  in the provider list.

### Summary table

| Consequence | Admin session only | Filesystem access required |
|---|---|---|
| Prompt exfiltration | Yes | — |
| Ping-pong weakens simple final-state detection | Yes | — |
| Persistence past password reset / 2FA enrollment | Yes | — |
| Weaponized nullification / breakage | Yes | — |
| Validation oracle for stolen keys | Yes | — |
| Constant/env override defeating database-only rotation | — | Yes |
| Response tampering / MITM | — | Yes |
| Rogue provider registration | — | Yes |

The distinction matters for remediation design. Everything in the left
column is reachable from a single option write and needs option-layer
mitigations (fingerprint diffing, change notifications, narrower
capability, dedicated action hook, UI indicator for active key source).
The right column is a code-execution problem and falls outside the
Connectors API's own surface.
