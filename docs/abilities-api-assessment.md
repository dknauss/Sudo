# Abilities API Assessment

**Date:** 2026-02-19 (updated 2026-06-14)
**WP version evaluated:** 7.0 Beta 1 through RC2
**Status:** Pre-GA assessment retained. No Abilities-specific gating change is planned from this review; Connectors credential REST writes are gated on `main`, and the PHP execution path remains monitor-only.
**Covers:** Abilities API, WordPress MCP Adapter, AI Client, Connectors API

---

## Overview

The WordPress Abilities API, introduced in WP 6.9, exposes registered "abilities" via
REST endpoints and (optionally) WP-CLI. The WordPress MCP Adapter translates these
abilities into MCP tools for AI agents (Claude, Cursor, etc.) — it calls the same
REST endpoints, so both are covered by the same gating analysis.

This document evaluates the pre-GA WP 7.0 surface, explains why WP Sudo does not
currently need to gate Abilities API calls, and documents the strategy for when
gating becomes necessary. Treat implementation details here as source-derived
research; use [release-status.md](release-status.md) and [ROADMAP.md](ROADMAP.md)
for current release posture and open follow-up tasks.

**Verification sources for ability names and REST routes:**

- [Abilities API in WordPress 6.9 — Make WordPress Core](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/) — 3 core abilities, `permission_callback` pattern
- [Abilities API REST Endpoints — developer.wordpress.org](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/) — REST route structure
- [From Abilities to AI Agents: Introducing the WordPress MCP Adapter — developer.wordpress.org](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/) — confirms 3 read-only abilities as of 7.0 Beta 1

Ability names were verified against official sources listed above and in
`.planning/phases/05-wp-7-0-readiness/05-RESEARCH.md`, not inferred from training data.

---

## Abilities Surface Evaluated for WP 7.0

As of the WP 7.0 Beta/RC review, WordPress core registered three abilities. All
three were read-only: they exposed information but did not modify or destroy site
state.

| Ability ID | Label | Permission Callback | Destructive? |
|------------|-------|---------------------|--------------|
| `core/get-site-info` | Get Site Information | `current_user_can('read')` | No |
| `core/get-user-info` | Get User Information | `current_user_can('read')` | No |
| `core/get-environment-info` | Get Environment Info | `current_user_can('read')` | No |

Abilities are registered inside the `wp_abilities_api_init` action using
`wp_register_ability()`. Each registration specifies a `permission_callback`
(capability check) and an `execute_callback` (returns data).

---

## REST Endpoints (WP Abilities API v1)

The Abilities API registers the following REST routes under the `wp-abilities/v1`
namespace:

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/wp-json/wp-abilities/v1/abilities` | List all registered abilities |
| `GET` | `/wp-json/wp-abilities/v1/categories` | List ability categories |
| `GET` | `/wp-json/wp-abilities/v1/{ns}/{name}` | Get a single ability by namespace and name |
| `GET\|POST\|DELETE` | `/wp-json/wp-abilities/v1/{ns}/{name}/run` | Execute an ability |

The HTTP method for the `/run` endpoint is determined by the ability type:

- Read-only operations use `GET`
- Operations requiring input parameters use `POST`
- Destructive operations use `DELETE`

In the evaluated Beta/RC surface, no registered core abilities used `DELETE` on
`/run`. All three core abilities used `GET`.

---

## Analysis: Does WP Sudo Need to Gate Abilities?

### Current state: No gating required

WP Sudo's gating model intercepts operations that **modify or destroy site state**:
activating plugins, deleting users, changing critical settings, installing themes,
and so on. Read-only operations are explicitly outside WP Sudo's scope.

The evaluated core abilities were read-only. They expose information about the
site, user, and environment — but they do not change anything. No reauthentication
is warranted for information retrieval.

### `permission_callback` pattern vs. WP Sudo gating

The Abilities API uses `permission_callback` (a standard WordPress capability check
such as `current_user_can('read')`) to control access. This is authorization — it
answers "is this user allowed to call this ability at all?"

WP Sudo provides reauthentication — it answers "has this user recently confirmed
their identity, regardless of their role?" These are complementary controls, not
substitutes. The `permission_callback` check runs inside WordPress before the
`execute_callback` fires. WP Sudo would intercept at the REST layer (via
`rest_request_before_callbacks`) before the `permission_callback` even runs.

For read-only abilities, the `permission_callback` check is sufficient. WP Sudo
would add no additional security value by intercepting them.

### Current Gate surfaces: no `ability` surface type

The Gate class (`includes/class-gate.php`) currently recognizes seven surfaces:

| Surface | Interception point |
|---------|--------------------|
| `admin` | `admin_init` at priority 1 |
| `ajax` | `admin_init` at priority 1 (also fires for `admin-ajax.php`) |
| `rest` | `rest_request_before_callbacks` filter |
| `cli` | `init` at priority 0 via function-level hooks |
| `cron` | `init` at priority 0 via function-level hooks |
| `xmlrpc` | `init` at priority 0 via `xmlrpc_enabled` filter and function hooks |
| `wpgraphql` | `graphql_process_http_request` action (v2.5.0+, conditional on WPGraphQL being active) |

There is no `ability` surface type. The Abilities API REST routes are served through
the standard WordPress REST API and are therefore already covered by the existing
`rest` surface interception — no special handling is required.

---

## Gating Strategy for Future Destructive Abilities

When a destructive ability appears in WordPress core or a plugin (indicated by a
`DELETE` method on a `/wp-abilities/v1/{ns}/{name}/run` route), WP Sudo can gate it
without adding a new surface type.

### REST-exposed abilities (browser and App Password callers)

The existing `intercept_rest()` method in `Gate` already intercepts all REST requests
via `rest_request_before_callbacks` and routes them through `match_request('rest')`.
A new rule in `Action_Registry` matching the destructive ability's route is all that
is needed:

```php
// Example: hypothetical destructive ability
[
    'id'    => 'abilities.delete_plugin',
    'label' => __( 'Delete plugin via Abilities API', 'wp-sudo' ),
    'rest'  => [
        'route'   => '#^/wp-abilities/v1/core/delete-plugin/run$#',
        'methods' => [ 'DELETE' ],
    ],
],
```

The existing `matches_rest()` method in `Gate` checks route pattern and HTTP method,
so a regex matching `/wp-abilities/v1/.*/run` with `DELETE` would catch all destructive
ability runs in a single rule:

```php
[
    'id'    => 'abilities.run_destructive',
    'label' => __( 'Run destructive ability', 'wp-sudo' ),
    'rest'  => [
        'route'   => '#^/wp-abilities/v1/[^/]+/[^/]+/run$#',
        'methods' => [ 'DELETE' ],
    ],
],
```

No new surface type is required for REST-exposed abilities.

### WordPress MCP Adapter (AI agent callers)

The WordPress MCP Adapter translates registered abilities into MCP tools. When an AI
agent calls an MCP tool, the adapter executes the corresponding ability via the same
`/wp-abilities/v1/{ns}/{name}/run` REST endpoint. From WP Sudo's perspective, an
MCP-originated ability call is indistinguishable from any other REST request — it
flows through `rest_request_before_callbacks` and is subject to the same Gate
interception.

No special handling is required for MCP Adapter calls. The same REST rules that gate
direct ability calls also gate MCP-mediated calls.

### WP-CLI `wp ability run` (CLI callers)

For abilities executed via WP-CLI's `wp ability run` command, the existing CLI
surface gating via function-level hooks in `register_function_hooks()` applies. A
hook on the appropriate WordPress action that fires before the ability's
`execute_callback` would be added to the function hook registration block.

### Direct PHP execution path: `WP_Ability::execute()`

**Correction (2026-04-13):** The original assessment stated that no PHP-level
execution path exists. This was incorrect. WordPress 7.0 includes
`WP_Ability::execute()` and `wp_get_ability()`, which allow any PHP code to
execute an ability directly — bypassing the REST API entirely.

Abilities registered with `show_in_rest => false` can *only* be executed this way.
They are hidden from REST listings and return `rest_ability_not_found` on REST
access, but remain callable via:

```php
$ability = wp_get_ability( 'namespace/ability-name' );
$result  = $ability->execute( $input );
```

**Authorization on the PHP path:** `WP_Ability::execute()` runs input validation
against the ability's input schema, calls `check_permissions()` (the ability's
`permission_callback`), executes the callback via `do_execute()`, and validates
output. This is a capability check (authorization), not reauthentication.

**Hooks:** Two action hooks fire around execution:

- `wp_before_execute_ability` — fires before the ability runs
- `wp_after_execute_ability` — fires after the ability completes

The `wp_before_execute_ability` hook is the interception point for WP Sudo. If
a destructive ability is registered that plugins call via the PHP path (bypassing
REST), WP Sudo can hook into `wp_before_execute_ability` to block execution when
no sudo session is active — similar to the function-level hooks used for CLI,
Cron, and XML-RPC surfaces in `Gate::register_function_hooks()`.

### When to add an `ability` surface type to Gate

The PHP execution path already exists (condition 1 from the original trigger
list is met). However, the remaining conditions are not yet met:

**Revised trigger conditions:**

1. ~~A non-REST, non-CLI ability execution path is introduced~~ — **exists now**
   (`WP_Ability::execute()`)
2. A destructive ability is registered that plugins are likely to call via the
   PHP path (not just REST)
3. The `wp_before_execute_ability` hook proves to be a reliable interception point
   (i.e., it fires consistently, cannot be bypassed, and provides enough context
   to identify the ability being executed)

Condition 2 is the practical trigger. Until destructive abilities exist, the PHP
execution path is not a security concern — the three current core abilities are
all read-only.

**Implementation plan when conditions 2 and 3 are met:**

- Hook `wp_before_execute_ability` in `Gate::register()` (all surfaces, not just
  non-interactive) to catch PHP-path execution regardless of the calling context
- Check the ability ID against a list of gated ability IDs in `Action_Registry`
- If the ability is gated and no sudo session is active, block with `wp_die()` or
  return a `WP_Error` depending on context

This does not require a new surface constant — the existing `admin` surface
(or a new `ability` label for audit hooks) is sufficient.

---

## AI Client and Connectors API (WP 7.0)

### AI Client: `wp_ai_client_prompt()`

WordPress 7.0 introduces a built-in AI Client — a provider-agnostic PHP API for
sending prompts to external AI models (text generation, image generation, TTS,
etc.). The entry point is `wp_ai_client_prompt()`, which returns a fluent builder.

**WP Sudo does not need to gate AI Client prompt execution.** Sending a prompt
to an external AI provider does not modify WordPress state. It is analogous to
`wp_remote_post()` — an outbound HTTP call, not a destructive site operation.
WordPress core provides the `wp_ai_client_prevent_prompt` filter for prompt-level
access control, which is the correct layer for that concern.

### Connectors API: credential management surface

AI provider credentials (API keys) are managed through the **Connectors API**,
which provides a settings page at **Settings > Connectors**. AI provider plugins
that register with the AI Client's provider registry get automatic Connectors
integration.

**This is now an active gating target in WP Sudo.** An attacker with a stolen
admin session could use the Connectors settings page to:

- **Exfiltrate data** — redirect AI traffic to an attacker-controlled endpoint,
  capturing prompts that may contain site content, user data, or admin context
- **Commit billing fraud** — replace a legitimate API key with the attacker's own
- **Denial of service** — delete provider credentials, breaking AI-dependent features

Connectors credential changes are a settings modification comparable to other
settings that WP Sudo already gates. Current `main` now ships a built-in REST
rule, `connectors.update_credentials`, that matches:

- `POST` / `PUT` / `PATCH` to `/wp/v2/settings`
- only when request params include connector-style credential setting names
  matching `connectors_[a-z0-9_]+_api_key`

This is intentionally narrower than gating all REST settings writes. It closes
the write-only key replacement path for database-backed connector credentials
without interfering with unrelated settings updates.

**Current source-grounded understanding:** the official dev note exists, and core
routes connector credential writes through the standard REST settings endpoint
rather than a bespoke admin form action. The remaining task is GA parity
verification, not first implementation: confirm the released route, setting-name
pattern, and masking/validation behavior still match the current analysis in
`connectors-api-reference.md`.

### MCP Adapter: no persistent agent sessions

The WordPress MCP Adapter uses **per-request authentication**:

- **STDIO transport:** Authenticates via WP-CLI with `--user` at server startup
- **HTTP transport:** Uses Application Passwords or custom OAuth per-request

There is no persistent AI agent session concept, no long-lived agent tokens, and
no session state maintained across requests. Each MCP tool call is an independent
authenticated request that flows through the existing REST or CLI surface.

The pre-GA review did not find a core proposal for persistent agent sessions or
long-lived agent tokens. The real-time collaboration work uses short-lived
WebSocket tokens for human editors, not AI agents. Re-check this assumption
before designing future agent-specific policy.

**If a persistent agent session concept is introduced in a future release**, it
would warrant a new policy tier in WP Sudo (comparable to CLI or Cron policy) —
a long-lived token that can perform multiple operations without per-request
authentication is a new trust boundary.

---

## Recommendation

> **Scope note:** This document assesses how the *shipping* WP Sudo plugin treats
> the Abilities API at runtime. For the distinct question of how a proposed core
> Actions API / Action Gate would relate to the Abilities API — aligning with its
> namespacing and execution-hook pattern while treating it as adjacent prior art
> rather than the vehicle — see [`core-action-gate-proposal.md`](core-action-gate-proposal.md) §6.

**No Abilities-specific Gate changes are planned from this assessment alone.**

The evaluated core abilities were read-only. The existing REST surface interception in
`Gate::intercept_rest()` already covers the `/wp-abilities/v1/` namespace routes if
a matching rule is ever added to `Action_Registry`. The PHP execution path exists
but is not a concern until destructive abilities are registered.

**Monitoring action items:**

1. Watch the [abilities-api](https://github.com/WordPress/abilities-api) GitHub
   repository for new ability registrations, especially any using `DELETE` on `/run`
   or registered with `show_in_rest => false`.
2. When destructive abilities appear, add a REST rule to `Action_Registry` matching
   `/wp-abilities/v1/.*/run` with `DELETE` method. No `Gate` class changes required.
   This also covers MCP Adapter calls (same REST endpoints).
3. For abilities with `show_in_rest => false` that are destructive, hook
   `wp_before_execute_ability` to gate the PHP execution path.
4. For WP-CLI `wp ability run` with destructive abilities, add a function-level hook
   in `Gate::register_function_hooks()` targeting the appropriate WordPress action.
5. Before the next release that changes Connectors coverage, verify that the
   released Connectors settings page still writes credential changes through
   `/wp/v2/settings`, that connector setting names still follow the documented
   `connectors_*_api_key` pattern, and that the built-in
   `connectors.update_credentials` rule remains accurate.
6. Monitor Make Core and Trac for any proposal to introduce persistent AI agent
   sessions or long-lived agent tokens — this would require a new WP Sudo policy tier.
7. Monitor the WordPress MCP Adapter for any direct-execution path that bypasses REST.
