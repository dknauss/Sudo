# AI and Agentic Tool Guidance

> **Research boundary:** the configurations below describe how the demonstrator
> behaves in a disposable evaluation environment. They are not deployment
> recommendations for a real site, and "recommended" below always means
> "recommended *for the scenario being demonstrated*". Do not install WP Sudo on
> production, public staging, production-derived, or real-data sites, and do not
> hand it real deployment credentials. See the canonical
> [Project Status](../PROJECT-STATUS.md).

WP Sudo provides action-gated reauthentication for WordPress. This document explains how AI assistants, automated agents, and agentic toolchains interact with WP Sudo's policies -- and how to configure the plugin for common AI-driven workflows.

The key insight: **AI tools do not introduce new WordPress entry points.** They use existing ones -- REST API, WPGraphQL, WP-CLI, browser-based cookie authentication -- all of which WP Sudo already governs through its configurable three-tier policies.

---

## Table of Contents

- [How AI Tools Interact with WP Sudo](#how-ai-tools-interact-with-wp-sudo)
  - [Browser-Based AI](#browser-based-ai)
  - [Headless AI Agents](#headless-ai-agents)
  - [WP-CLI Agents](#wp-cli-agents)
  - [WPGraphQL Agents](#wpgraphql-agents)
- [Recommended Policy Configurations](#recommended-policy-configurations)
  - [Conservative (Default)](#conservative-default)
  - [AI Content Workflow](#ai-content-workflow)
  - [Automated Deployment Pipeline](#automated-deployment-pipeline)
  - [Lockdown](#lockdown)
- [Per-Application-Password Policies (v2.3+)](#per-application-password-policies-v23)
- [Why Limited Is the Correct Default](#why-limited-is-the-correct-default)
- [Error Responses AI Agents Receive](#error-responses-ai-agents-receive)
- [Audit Trail](#audit-trail)

---

## How AI Tools Interact with WP Sudo

### Browser-Based AI

Gutenberg sidebar assistants, Jetpack AI, and other browser-embedded AI tools operate through cookie-authenticated REST API requests. They are indistinguishable from any other browser-initiated REST call.

These requests hit the same `rest_request_before_callbacks` filter as every other REST request. WP Sudo checks for the `X-WP-Nonce` header to confirm cookie authentication, then matches the request against the action registry.

- **Non-gated operations** (creating posts, uploading media, reading data) pass through without interruption.
- **Gated operations** (activating a plugin, deleting a user) return a `sudo_required` `WP_Error` with HTTP 403. An admin notice on the next page load links to the challenge page.
- The user activates a sudo session via the challenge page or the keyboard shortcut (`Cmd+Shift+S` / `Ctrl+Shift+S`) and retries the action.

This is identical to the experience for any browser-based REST request. No special handling is needed for AI tools that operate within the browser session.

### Headless AI Agents

Deployment bots, MCP-based tools, CI/CD pipelines, and other headless agents authenticate via Application Passwords, Bearer tokens, or OAuth. WP Sudo identifies these as non-cookie REST requests (no valid `X-WP-Nonce` header) and governs them with the **REST API (App Passwords)** policy setting.

The three policy modes:

| Mode | Non-Gated Operations | Gated Operations | Error Code |
|---|---|---|---|
| **Disabled** | Blocked | Blocked | `sudo_disabled` (403) |
| **Limited** (default) | Pass through | Blocked | `sudo_blocked` (403) |
| **Unrestricted** | Pass through | Pass through | -- |

In **Limited** mode, an AI agent can create posts, upload media, read site data, and perform any operation that is not in the gated action registry. Gated operations -- plugin activation, user deletion, critical settings changes -- return a `sudo_blocked` error with a clear message identifying the blocked action.

In **Disabled** mode, all non-cookie REST API requests are rejected regardless of whether the operation is gated. This effectively shuts off headless API access entirely.

In **Unrestricted** mode, all operations pass through as if WP Sudo is not installed. No gating checks, no audit logging.

#### WordPress Abilities API (6.9+)

The WordPress Abilities API (`/wp-abilities/v1/`) uses standard WordPress REST authentication — Application Passwords, cookie nonces, and other supported methods. Requests authenticated via Application Password are governed by the **REST API (App Passwords)** policy exactly like any other non-cookie REST request. No special configuration is needed.

In **Disabled** mode, all Abilities API requests via Application Passwords are blocked. In **Limited** mode, ability reads (`GET /run`) and standard executions (`POST /run`) pass through as non-gated operations; to require sudo for a specific destructive ability execution (`DELETE /run`), add a custom REST rule via the `wp_sudo_gated_actions` filter targeting the relevant route. In **Unrestricted** mode, all ability executions pass through.

### WP-CLI Agents

AI-driven deployment scripts, site management tools, and automation frameworks that operate through WP-CLI are governed by the **WP-CLI** policy setting.

| Mode | Non-Gated Commands | Gated Commands |
|---|---|---|
| **Disabled** | Blocked | Blocked |
| **Limited** (default) | Pass through | Blocked |
| **Unrestricted** | Pass through | Pass through |

In **Limited** mode:

- `wp post create`, `wp media import`, `wp option get` -- work normally.
- `wp plugin activate`, `wp user delete`, `wp theme switch` -- blocked with a 403 error identifying the operation.
- `wp cron event run` -- respects the **Cron** policy independently. If Cron is set to Disabled, `wp cron` subcommands are blocked even when CLI is Limited or Unrestricted.

In **Disabled** mode, all WP-CLI commands are blocked immediately at `init`.

### WPGraphQL Agents

Headless frontends, decoupled CMS setups, and AI tools that query WordPress through WPGraphQL are governed by the **WPGraphQL** policy setting. WPGraphQL gating operates at the surface level rather than the per-action rule level: when the policy is Limited, **all mutations** require an active sudo session regardless of which mutation is being performed. Read-only queries are never blocked.

| Mode | Queries | Mutations |
|---|---|---|
| **Disabled** | Blocked | Blocked |
| **Limited** (default) | Pass through | Blocked |
| **Unrestricted** | Pass through | Pass through |

WP Sudo detects mutations using a heuristic: if the request body contains a `mutation` operation type it is treated as a mutation. WPGraphQL handles its own URL routing, so gating works regardless of how the endpoint is named.

In **Limited** mode, mutation requests without an active sudo session receive an HTTP 403 response with a `sudo_blocked` error code. In **Disabled** mode, all requests to the endpoint are rejected with HTTP 403 and a `sudo_disabled` error code.

---

## Recommended Policy Configurations

### Conservative (Default)

All five policies set to **Limited**:

| Surface | Policy |
|---|---|
| REST API (App Passwords) | Limited |
| WP-CLI | Limited |
| Cron | Limited |
| XML-RPC | Limited |
| WPGraphQL | Limited |

AI agents can perform content operations (create posts, upload media, read data) but cannot make structural changes (install plugins, delete users, modify critical settings). This is the configuration the plugin ships with, and the one most evaluation scenarios should start from.

### AI Content Workflow

Same as Conservative. Content creation via REST API is not a gated operation, so AI writing assistants -- whether browser-based or headless -- work without any restrictions under the default Limited policy. No configuration changes are needed.

### Automated Deployment Pipeline

Modelling a CI/CD pipeline or deployment bot that needs to activate plugins, switch themes, or perform other gated operations. Use synthetic credentials in a disposable environment — do not point a real pipeline's Application Password or WP-CLI access at a site running this demonstrator:

**Option A:** Set REST API (App Passwords) to **Unrestricted**. This grants all Application Password credentials full access, which may be acceptable when only trusted automation uses Application Passwords.

**Option B (recommended):** Use per-application-password policies (v2.3+) to grant specific deployment credentials Unrestricted access while keeping the global REST API policy at Limited. This allows a deployment bot to perform gated operations while keeping other Application Password credentials (content-writing AI, monitoring tools) restricted.

**Option C:** For WP-CLI-based deployment, set WP-CLI to **Unrestricted**. Since WP-CLI requires server-level access, the trust boundary is already at the infrastructure level.

### Lockdown

All five policies set to **Disabled**:

| Surface | Policy |
|---|---|
| REST API (App Passwords) | Disabled |
| WP-CLI | Disabled |
| Cron | Disabled |
| XML-RPC | Disabled |
| WPGraphQL | Disabled |

Only browser-based operations with interactive reauthentication are permitted. All non-interactive entry points are shut off entirely. This is the configuration to exercise when demonstrating the narrowest policy surface — no headless automation at all.

Note that Disabled mode for REST API only affects non-cookie authentication. Browser-based REST requests (block editor, admin AJAX) continue to work normally with the standard challenge-and-retry flow.

---

## Per-Application-Password Policies (v2.3+)

When different AI tools need different access levels, per-application-password policies let you assign a policy level to individual Application Password credentials.

Example scenario:

| Credential | Purpose | Policy |
|---|---|---|
| `content-writer` | AI writing assistant | Limited (default) |
| `deploy-bot` | CI/CD pipeline | Unrestricted |
| `monitoring` | Uptime and health checks | Limited (default) |

The content-writing AI operates normally under the default Limited policy -- it creates posts and uploads media without restriction. The deployment bot has Unrestricted access and can activate plugins or switch themes. The monitoring tool reads data under Limited and cannot make structural changes.

Per-application-password policies override the global REST API (App Passwords) policy for that specific credential. Credentials without an explicit override fall back to the global setting.

---

## Why Limited Is the Correct Default

The Limited policy follows the principle of least privilege:

- **No impact on normal AI workflows.** Content creation, media uploads, data reading, and other non-gated operations are completely unaffected. AI writing assistants, content generators, and data-reading tools work without any restriction.

- **Structural operations are blocked with clear feedback.** When an AI agent attempts a gated operation (plugin activation, user deletion, critical settings change), it receives a specific error code (`sudo_blocked`) and a message identifying the blocked action. The agent or its operator can decide how to proceed.

- **Compromised credentials have limited blast radius.** If an AI agent's Application Password is leaked or an agent is manipulated into attempting destructive operations, the gated action registry prevents the most dangerous actions from executing.

- **Non-gated operations are not logged.** Limited mode only fires audit hooks for blocked gated operations, keeping the audit trail focused on security-relevant events rather than routine content operations.

---

## Error Responses AI Agents Receive

AI agents and their integration code should handle these WP Sudo error responses:

### REST API Errors (JSON)

**`sudo_required`** (HTTP 403) -- Cookie-authenticated browser request attempted a gated operation without an active sudo session. The user must reauthenticate interactively.

```json
{
  "code": "sudo_required",
  "message": "This action (Activate plugin) requires reauthentication. Press Cmd+Shift+S to start a sudo session, then try again.",
  "data": { "status": 403, "rule_id": "plugin.activate" }
}
```

**`sudo_blocked`** (HTTP 403) -- Non-cookie-authenticated request (Application Password, Bearer token) attempted a gated operation under the Limited policy. The operation cannot be performed through this authentication method.

```json
{
  "code": "sudo_blocked",
  "message": "This operation requires sudo and cannot be performed via Application Passwords.",
  "data": { "status": 403 }
}
```

**`sudo_disabled`** (HTTP 403) -- Non-cookie REST API access is entirely disabled by policy. No operations (gated or non-gated) are available through this authentication method.

```json
{
  "code": "sudo_disabled",
  "message": "This REST API operation is disabled by WP Sudo policy.",
  "data": { "status": 403 }
}
```

### WP-CLI Errors

Gated commands in Limited mode terminate with a 403 status and a message identifying the blocked operation:

```
Error: This operation (Activate plugin) requires sudo and cannot be performed via WP-CLI.
```

In Disabled mode, all CLI commands are terminated immediately:

```
Error: WP-CLI is disabled by WP Sudo policy.
```

---

## Audit Trail

All blocked operations on non-interactive surfaces fire the `wp_sudo_action_blocked` action hook:

```php
do_action( 'wp_sudo_action_blocked', int $user_id, string $rule_id, string $surface );
```

The `$surface` parameter identifies the entry point: `rest_app_password`, `cli`, `cron`, `xmlrpc`, or `wpgraphql`. The `$rule_id` identifies the specific gated action (e.g., `plugin.activate`, `user.delete`, `options.critical`). For WPGraphQL, the `$rule_id` is `wpgraphql` and the hook fires once per blocked mutation request.

Logging plugins such as [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/) or [Stream](https://wordpress.org/plugins/stream/) can subscribe to this hook to create a searchable audit record of what AI tools and automated agents attempted and what was blocked.

For Unrestricted surfaces, the `wp_sudo_action_allowed` hook fires when a gated action passes through:

```php
do_action( 'wp_sudo_action_allowed', int $user_id, string $rule_id, string $surface );
```

Together, `wp_sudo_action_blocked` and `wp_sudo_action_allowed` provide a complete record of policy decisions for every gated operation attempted through non-interactive entry points — whether the operation was permitted or denied. For WPGraphQL, the allowed hook fires only for mutations (queries are not gated and pass through silently).
