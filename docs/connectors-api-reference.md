# Connectors API Reference (WP 7.0)

*Drafted 2026-04-13 from `wordpress-develop` trunk source code.*
*Updated 2026-04-18 with local runtime verification in WordPress Studio (`7.0-RC2-62241`) for REST save behavior, key-source precedence, and Connectors admin UI state handling.*
*There is now an official Core dev note for the Connectors API, but this reference remains source-derived because it goes deeper into the REST masking/write path than the high-level announcement. Verify against the GA release before relying on implementation details.*

> [!WARNING]
> This reference is source-derived from WordPress 7.0 RC2 / trunk-era code and
> should be re-verified against the final GA release before relying on
> implementation details.

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

For **database-backed** connectors, API keys are stored as plaintext
WordPress options via `register_setting()`. There is no built-in encryption for
option-stored keys.

### Setting name pattern

```
connectors_{type}_{id}_api_key
```

Hyphens in `{type}` and `{id}` are normalized to underscores.

### Built-in connectors (WP 7.0 defaults)

These are the **default connector definitions** core registers when AI support
is enabled. Their metadata exists in core even on a bare install, but the
corresponding REST settings are only registered when the provider is present in
the WP AI Client registry. In other words, the table below describes the
default connector IDs and naming conventions, not a guarantee that every
`connectors_ai_*_api_key` field is exposed on every 7.0 install.

> [!IMPORTANT]
> The built-in connector table describes default connector definitions, not a
> guarantee that every `connectors_ai_*_api_key` setting is registered on every
> install. REST settings registration depends on the provider being present in
> the WP AI Client registry.

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

On save via **POST** or **PUT** to `/wp/v2/settings`, AI provider keys are
validated against the provider's API via
`_wp_connectors_is_ai_api_key_valid()`. Invalid keys are reverted to an empty
string. Non-AI connectors accept keys without validation.

**PATCH caveat:** the settings endpoint itself accepts `PATCH`, but
`_wp_connectors_rest_settings_dispatch()` currently only treats `POST` and
`PUT` as update methods for the validation branch. Masking still applies on
`PATCH`, but the AI-provider validation path does not.

> [!WARNING]
> The `/wp/v2/settings` endpoint accepts `PATCH`, but core's Connectors
> validation logic currently only treats `POST` and `PUT` as update methods for
> AI-key validation.

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

> [!CAUTION]
> `_wp_connectors_mask_api_key()` returns values of length 4 or fewer unchanged.
> This is not a realistic LLM-key risk, but it means masking is not literally
> universal.

### Write credentials

```
POST /wp/v2/settings
Content-Type: application/json

{
    "connectors_ai_anthropic_api_key": "sk-ant-..."
}
```

- Requires `manage_options` capability (standard settings endpoint auth)
- AI provider keys are validated against the provider before storage on
  **POST/PUT** requests
- Invalid AI-provider keys are reverted to empty string server-side; the raw
  REST write still returns `200 OK`
- Response returns the masked version of the saved key
- `PATCH` is accepted by the settings endpoint, but core's connector validation
  logic currently only runs on `POST` and `PUT`

The stock Connectors UI compensates for this lossy REST contract: if the
returned value is empty or unchanged after save, it shows an inline error
instead of treating the connector as successfully configured.

> [!NOTE]
> **WP Sudo now gates this path.** On current `main`, REST writes to
> `/wp/v2/settings` are challenged when the request body contains
> connector-style credential keys matching `connectors_*_api_key`. This
> protects the Connectors admin UI save path without broadly gating unrelated
> REST settings writes.

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

> [!IMPORTANT]
> This section is specific to the **WP Sudo** plugin in this repository. It is
> not describing stock WordPress core behavior; it explains how WP Sudo treats
> the Connectors credential-write path.

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

The ≤4-character masking edge case is documented under [REST API Surface](#rest-api-surface) above; it is not a realistic practical concern for typical AI provider credentials.

However, without an additional guard an attacker could still **overwrite** keys
via `POST /wp/v2/settings`. That is the real attack vector: a **credential
integrity** failure rather than a credential disclosure failure. The attacker
does not need to know the original secret; they only need to replace it with
one they control.

> [!NOTE]
> Current WP Sudo `main` mitigates that path by challenging connector
> credential writes before they reach the settings save handler.

### Higher-precedence key sources

Keys provided via environment variable or PHP constant cannot be effectively
replaced through the REST API because the database value is ignored when a
higher-precedence source exists. This is a strong deployment-side mitigation
against database-backed credential replacement: sites that set
`ANTHROPIC_API_KEY` in `wp-config.php` or the environment largely neutralize
replacement through the Connectors settings flow, direct REST writes, or other
tooling that only updates the option value, even though in-process code still
shares the same broader trust boundary.

---

## Failure and Exploit Scenarios

This section enumerates the ways connector credential integrity can fail in
practice. Scenarios are grouped by attacker capability so readers don't
conflate "bug in the UI" with "attacker with shell access." The most
relevant case for Connectors design is the **admin session only** one: the
most important consequences in that category are reachable from a single
`POST /wp/v2/settings` write, with no filesystem access, no plugin install,
and no code execution.

### Threat context

Two industry framings are worth naming up front, because they locate the
Connectors write-side risk in vocabulary that enterprise security reviewers
already use.

**LLMjacking** ([Sysdig Threat Research,
2024](https://sysdig.com/blog/llmjacking-stolen-cloud-credentials-used-in-new-ai-attack/))
is the term of art for attacks that compromise credentials in order to abuse
hosted LLM APIs. The Connectors scenario is an *inverse variant*: the
attacker does not need to steal the victim's API key — reads are masked and
the raw value is not usefully recoverable over REST. They instead **plant**
their own key into the victim site, so the victim's subsequent AI traffic
authenticates as the attacker. The victim's infrastructure and billing carry
the load; the attacker reads the prompts. Economic target (access to paid
LLM inference) and detection gaps (no per-credential audit surface, no
change fingerprint, no notification) are the same as classical LLMjacking;
the direction of key flow is reversed.

**Non-human identity** (NHI) is the umbrella term for long-lived bearer
credentials held by systems rather than people — API keys, service account
tokens, machine OAuth credentials. Connector API keys are textbook NHIs:
long-lived, no individual owner, no rotation SLA, no scope minimization, no
lifecycle tooling, no change attestation. The NHI security vocabulary
(rotation hygiene, attribution, change notification, diff-able state for
tamper detection) names the gaps enumerated in this section one-to-one. The
Connectors API currently treats AI credentials as "just another setting,"
which is precisely the failure mode the NHI category was named to address.

Neither framing is essential to understanding the scenarios, but both are
useful as search anchors for security practitioners triaging similar
findings elsewhere in their stack.

**Verification note (2026-04-18):** The corrections below reflect local runtime
testing in WordPress Studio on `7.0-RC2-62241` plus source inspection of the
bundled Connectors UI build. Where a claim is conditional rather than
end-to-end runtime-proven, that is stated explicitly.

Each scenario below carries a compact provenance tag:

- *(runtime)* — behavior reproduced end-to-end on `7.0-RC2-62241`
- *(source)* — derived from inspection of `src/wp-includes/connectors.php` trunk
- *(structural)* — follows from WordPress framework rules (option precedence, hook lifecycle, `wp_options` persistence) without needing a per-scenario runtime reproduction
- Combined tags (e.g. *(runtime + inferred)*) flag claims where the on-WordPress mechanism is runtime-verified but an off-platform consequence (such as what appears in a third-party provider's usage dashboard) is not independently verified here.

### Non-malicious operational failure modes

These are UX and operational defects independent of any malicious actor.

- **Lossy invalid-save semantics at the REST layer.** *(runtime)* An admin mistypes or
  paste-truncates a new AI-provider key. Validation against the provider
  fails, the server clears the stored option to an empty string, and the raw
  REST write still returns `200 OK` instead of a structured `WP_Error`.
  The stock Connectors UI does surface an inline error, but generic REST
  callers and audit tooling have to infer "failed validation and nullified"
  from response shape rather than status code. The harm here is ambiguity:
  logs, scripts, and recovery tooling can record the write as a nominal
  success even though the connector was effectively broken or cleared,
  making outages and malicious disruption harder to distinguish from routine
  operator error.
- **Rotation writes can be silently shadowed by a higher-precedence source.**
  *(runtime)* If a connector is backed by an environment variable or PHP
  constant, option-layer writes still succeed — the stored value changes —
  but the runtime continues to use the env/constant value per the resolution
  order. The stock Connectors UI does surface this: externally configured
  connectors are rendered read-only, so an admin rotating through the default
  screen sees that they are editing a shadowed source. The operational risk
  lives in **non-UI rotation paths**: direct REST writes, WP-CLI helpers,
  custom admin tools, and incident-response scripts that call
  `update_option()` without checking for overrides. Those paths get no
  warning and can report success while the active credential never changes,
  creating false confidence during troubleshooting or recovery.

  An attacker with filesystem access can engineer this condition deliberately
  by planting `define( 'OPENAI_API_KEY', 'sk-attacker...' );` in
  `wp-config.php` or setting the equivalent environment variable. In that
  variant the stock UI will correctly show "externally configured," but it
  does not surface the constant's *value* or *when it was added* — the
  read-only indicator is honest about the mechanism but is not a diff against
  what the admin remembers setting. An attacker-planted override looks
  indistinguishable from a legitimate one until the admin audits
  `wp-config.php` contents directly.
- **Key-source provenance is only partially explicit.** *(runtime)* The stock UI does
  surface whether a key is coming from an environment variable or a PHP
  constant, and externally configured connectors are rendered read-only.
  However, database-backed keys are not called out with equally explicit
  provenance beyond normal editable-state behavior.

### Attacker with `manage_options` session only

These scenarios require nothing beyond a valid admin REST session — session
hijack, stolen cookie, compromised admin credentials, XSS-escalated
privileges, or a rogue admin. No filesystem write, no plugin install, no
code execution. Reachable with one REST call.

> [!NOTE]
> The WordPress-side credential-routing change is runtime-verified.
> Provider-side prompt visibility depends on vendor logging, retention, and
> account features and is not uniform across providers.

**Attacker model assumption:** These scenarios assume the attacker writes
directly to `POST /wp/v2/settings`, not through the stock Connectors UI. The
UI's inline error handling on lossy saves and its read-only treatment of
env/constant-backed connectors do not apply to raw REST callers. An attacker
with a stolen or rogue `manage_options` session has no reason to route
through the UI, which is why "but the UI shows an error" is not a mitigation
for this threat model.

- **Prompt diversion via same-provider key swap.** *(runtime + inferred)* For a database-backed
  connector that is actually in use, an attacker can replace the stored key
  with one they control via `POST /wp/v2/settings`. Future requests made
  through that connector then authenticate as the replacement credential. For
  same-provider swaps (for example, OpenAI-to-OpenAI), outbound traffic still
  goes to the same provider hostname; the security consequence is changed
  account ownership, billing, and prompt visibility rather than a visible
  endpoint change. The on-WordPress half of this was runtime-verified in
  local Studio using a temporary AI-client harness: after replacing the
  stored key through `/wp/v2/settings`, the outbound `Authorization` header
  used by subsequent AI-client requests changed from the baseline key to the
  replacement key. I did not independently verify a third-party provider
  dashboard showing the prompts; that remains the inferred off-platform
  consequence of provider-side authentication and logging.

  Provider-side prompt visibility is not uniform across vendors. Some
  platforms offer request/response logging or admin/audit visibility for API
  usage, while others make retention optional or more limited. The
  WordPress-specific claim proven here is narrower: after a connector key is
  replaced, subsequent outbound requests authenticate as the replacement
  credential, so any provider-side logging or usage visibility attached to
  that credential will also follow the replacement account.

  Official examples of provider-side visibility controls and retention:
  - [Google Gemini API / AI Studio logs and datasets](https://ai.google.dev/gemini-api/docs/logs-datasets)
  - [Google Vertex AI request-response logging](https://cloud.google.com/vertex-ai/generative-ai/docs/multimodal/request-response-logging)
  - [OpenAI data controls](https://platform.openai.com/docs/models/default-usage-policies-by-endpoint)
  - [OpenAI admin/audit logs](https://help.openai.com/en/articles/9687866-admin-and-audit-logs-api-for-the-api-platform%23.apk)
  - [Anthropic API/data-retention docs](https://platform.claude.com/docs/en/build-with-claude/api-and-data-retention)
- **Ping-pong swap weakens simple forensic detection.** *(structural)* An attacker can
  swap in their key, allow a window of AI traffic, and then restore the
  original value. If the site only inspects the current stored key after the
  fact, it will appear normal. The risk here is not that all logging fails,
  but that final-state checks and diff-only investigations can miss a real
  credential-diversion window unless the site retains a durable history of
  key changes.
- **Persistence past session cleanup.** *(structural)* The swapped key lives in
  `wp_options`. Resetting the admin password, invalidating sessions, and
  enrolling 2FA do not touch `wp_options`. An incident-response playbook
  that doesn't explicitly rotate connector credentials leaves the
  diverted-traffic channel open after the original intrusion is "remediated."
- **Weaponized nullification / breakage.** *(runtime)* Attacker deliberately writes a
  malformed key. The server clears the stored option and returns `200 OK`.
  The stock UI will show an error if it initiated the save, but the lossy
  write semantics still let an attacker break the connector through the same
  settings path and create noise that can be mistaken for operator error.
- **Validation oracle (secondary concern).** *(source)* Because
  `_wp_connectors_is_ai_api_key_valid()` probes the provider during a
  write, an attacker holding a stolen-but-unverified key can confirm it's
  live from the victim site's own environment. This is a lower-stakes side
  effect than the swap vector — an attacker who can already write
  `wp_options` has better options — but it is still a probe capability that
  comes "for free" with the save path.

### Attacker with filesystem access

These scenarios require code-execution-adjacent capability (editing
`wp-config.php`, adding a mu-plugin, or plugin/theme edit). They compose
with the admin-session scenarios above but are not specific to the
Connectors API itself. Listed here for completeness because operators
investigating a suspected key-swap incident still need to check these too.

- **Response tampering via filter hook.** *(structural)* Attacker plants a
  `pre_http_request` filter (mu-plugin or theme edit) that rewrites the
  outbound request URL to an attacker-controlled proxy, or returns
  fabricated responses directly. Combined with a key swap, this makes the
  attacker a full man-in-the-middle for AI traffic — they read prompts and
  control responses. If AI responses are rendered into site content
  (summaries, moderation verdicts, generated copy), tampered responses
  become a content-integrity and potentially XSS vector.
- **Custom provider registration or wiring changes.** *(structural)* Attacker registers a
  malicious provider in the AI client registry, or alters provider wiring in
  code to point at their own endpoint, with a legitimate-looking display name
  or otherwise subtle presentation. Admin may not notice the change in the
  provider list or request flow.

### Summary table

| Consequence | Admin session only | Filesystem access required |
|---|---|---|
| Prompt diversion to replacement account | Yes | — |
| Ping-pong weakens simple final-state detection | Yes | — |
| Persistence past password reset / 2FA enrollment | Yes | — |
| Weaponized nullification / breakage | Yes | — |
| Validation oracle for stolen keys | Yes | — |
| Response tampering / MITM | — | Yes |
| Rogue provider registration | — | Yes |

The difference between admin-session attacks and filesystem/code-execution
attacks matters because they need different fixes. The admin-session
scenarios can happen through an ordinary connector-key write, so they need
safeguards around key changes themselves: clearer source/fingerprint
display, better change signals, narrower permissions, and better visibility
when a credential is replaced. The filesystem scenarios are broader
post-compromise problems and are not specific to Connectors, but they are
still relevant when investigating a suspected key-swap incident.

The two groups can also reinforce each other. An attacker with code
execution can do more than edit `wp-config.php` or plant a filter; they can
also use the same REST/settings write path to replace a stored connector
key. That write path is attractive because it is simple, blends in with
normal settings activity, and can survive session cleanup if the stored key
is left in place. In other words, filesystem access does not make the
settings-write risk irrelevant — it makes it easier to use.
