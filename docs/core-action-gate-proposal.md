# Action Gate Primitive for WordPress Core — Proposal

**Status:** Draft proposal, not adopted by WordPress core.
**Drafted:** 2026-04-17
**Author context:** Derived from WP Sudo's production implementation and its comparative analysis in `sudo-architecture-comparison-matrix.md`.
**Intended audience:** WordPress core contributors, plugin authors evaluating adoption, and WP Sudo operators assessing the future migration path.

---

## Abstract

WordPress has no first-class primitive for declaring that an operation is *consequential* and requires fresh proof of human intent before proceeding. Every protection plugin — WP Sudo, Fortress, Wordfence, Solid Security — reinvents its own catalog of dangerous operations and its own enforcement mechanism, with overlapping coverage, inconsistent semantics, and no shared interoperability surface.

This proposal describes a narrow core primitive — an **Action Gate** — consisting of:

1. A declarative registry of gated actions, keyed by namespaced IDs.
2. A runtime enforcement call that core and plugins invoke inside privileged functions.
3. A short-lived, scope-bound, cryptographically-bound **sudo session** concept.
4. A pluggable challenge mechanism that defaults to password reauthentication.
5. Per-surface policy tiers for non-interactive contexts (CLI, cron, XML-RPC, REST via application passwords).

Adoption is designed to be incremental: core ships the primitive without modifying any existing function, plugins opt in on their own schedule, and core begins migrating its own high-consequence functions across subsequent releases.

The primitive does not replace `current_user_can()` and does not solve plugin authorization bugs. It closes the gap between "has the capability" and "proved intent to use the capability right now."

This proposal is deliberately narrow. It does not resolve the deeper critique that WordPress's plugin contract — every plugin running with full process privileges in a shared PHP runtime — is itself the root vulnerability (see [Peralty's "Case for the Split"](https://peralty.com/2026/04/17/a-letter-to-matt-on-wp-next-part-1-the-case-for-the-split/), 2026-04-17, which argues this cannot be fixed within backwards-compatibility constraints). The action gate is the ceiling of what can be layered onto the existing runtime without fracturing the ecosystem. It is relevant in a "WP Classic" future as a hardening measure for the installed base, and it is a natural layer in a "WP Next" future that addresses runtime isolation separately. See [Relationship to Structural Modernization Proposals](#relationship-to-structural-modernization-proposals).

---

## Problem Statement

### The missing catalog

WordPress has a well-defined capability system (`current_user_can()`, roles, meta-caps) and a well-defined hook system (actions, filters). What it does not have is a catalog of which operations are *consequential enough* that a capability check alone is insufficient authorization.

Every security plugin that addresses this problem maintains its own list, and those lists diverge:

| Plugin | Enforcement model | Catalog size | Extensibility |
|---|---|---|---|
| WP Sudo | Action registry, hook interception | 34 actions (26 single-site + 8 multisite) | `wp_sudo_gated_actions` filter |
| Fortress | Protected capabilities + protected pages | Varies by tier | Capability/page-based |
| Wordfence | WAF-style request inspection | Rule-based, vendor-maintained | Custom rules |
| Solid Security | Feature-flag driven protections | Per-feature | Limited |

The absence of a core catalog has three consequences:

1. **Incompatible semantics.** Two security plugins installed together can block the same action twice with different challenge flows, or gate different subsets of operations, producing unpredictable UX.
2. **No stable interop surface.** Plugins that want to opt into being gated — or exempt specific flows from gating — have no standard API. Each protection plugin must be integrated with individually.
3. **No primitive for downstream work.** Plugin isolation, granular audit logging, capability-aware rate limiting, and AI-agent-aware access control all need a shared catalog of consequential operations to build on. Without one, each of those efforts builds its own.

### The missing session concept

Capability checks answer *"is this principal authorized in general to perform this action?"* They do not answer *"is this principal currently, actively, intentionally performing this action?"*

The gap matters whenever credentials have been stolen, phished, or inherited across contexts:

- A stolen session cookie grants full capability to the attacker.
- An XSS payload executes with full capability in the victim's origin.
- A device handed off mid-session (e.g., admin walked away from an unlocked workstation) carries full capability.
- An OAuth/SSO token with broad scopes delegates capability to downstream services.

The operating-system analogy — `sudo` — is near-universal outside web applications precisely because the capability model is necessary but not sufficient. Mac/Linux do not let the root bit be held indefinitely; Windows has UAC for a similar reason. Mobile platforms re-prompt on sensitive operations. Browser extensions require user consent for new permissions. WordPress does not have this layer in core.

### The non-interactive surface problem

Even when a "re-prove identity" primitive makes sense for browser-driven sessions, the same primitive must handle surfaces where there is no human at the keyboard to challenge:

- WP-CLI (long-lived SSH session, no browser)
- wp-cron (unattended background process)
- XML-RPC (legacy protocol, often disabled)
- REST API via Application Passwords (service-to-service)
- WPGraphQL (when installed)

A core primitive must define policy behavior for each of these surfaces explicitly. WP Sudo uses a three-tier model (Disabled / Limited / Unrestricted) that has proven viable in practice and is the direct basis for this proposal.

---

## Goals and Non-Goals

### Goals

- **Provide a shared declarative catalog** of consequential operations, extensible by core and plugins alike.
- **Provide an enforcement primitive** that runs the gate check with minimal boilerplate at the call site.
- **Provide a sudo session concept** — short-lived, cryptographically-bound, scope-aware — as a first-class core object.
- **Provide a pluggable challenge mechanism** so that password, 2FA, WebAuthn/passkey, and external identity providers can all serve as the proof-of-intent step.
- **Provide per-surface policy tiers** for non-interactive contexts.
- **Emit auditable events** for every gate outcome (passed, challenged, blocked, replayed).
- **Preserve backward compatibility.** Existing plugins and sites continue to function without modification; adoption is opt-in per action.

### Non-Goals

- **Authorization.** The gate sits above `current_user_can()`, not in place of it. A user must still have the capability to perform the action; the gate adds a proof-of-intent requirement on top.
- **Plugin sandboxing / privilege separation.** This primitive is a building block that a future plugin capability manifest system can consume, but the manifest system itself is a separate and much larger effort. More importantly, this proposal does not address the runtime-level problem that all plugins execute with the full privileges of the WordPress process — a problem that is structural to PHP's shared-process execution model and cannot be solved by a gate that runs inside the same process. A gate constrains *when* consequential operations may proceed; it does not constrain *what code running in the WordPress process may do*. The latter requires either out-of-process isolation (per-plugin subprocesses, WASM runtimes) or a different plugin contract entirely. Both are outside the scope of a backwards-compatible primitive.
- **WAF-style request inspection.** The gate does not attempt to classify requests as malicious or detect exploits; it requires proof of intent for *declared* operations.
- **Authentication replacement.** The gate uses existing WordPress authentication; it does not introduce a new login mechanism.
- **Fixing plugin authorization bugs.** If a plugin fails to call `current_user_can()` before invoking an action, the gate cannot detect this. The gate enforces registered operations; unregistered operations pass through unchanged.

---

## Proposed API

### Namespacing

Action IDs follow the Abilities API convention: `namespace/verb.object` or `namespace/verb` where no object is needed.

```
core/plugins.activate
core/plugins.install
core/plugins.delete
core/users.delete
core/users.promote_to_admin
core/options.sensitive_update
core/network.add_site
woocommerce/orders.refund
memberpress/members.manual_grant
```

Namespaces scope ownership: `core/*` is reserved for WordPress core; plugins use their slug or a vendor-prefix. This matches the Abilities API and allows WP Sudo to migrate its existing rule IDs into the namespace with a deterministic mapping.

### Registration

```php
wp_register_gated_action( 'core/plugins.activate', [
    'label'              => __( 'Activate a plugin' ),
    'description'        => __( 'Install or enable code that executes with full site privileges.' ),
    'capabilities'       => [ 'activate_plugins' ],
    'consequence_class'  => 'plugin-management',
    'scope'              => 'core/plugins',
    'grace_window'       => 300,
    'surfaces'           => [
        'admin'   => 'gate',
        'rest'    => 'gate',
        'ajax'    => 'gate',
        'cli'     => 'policy:limited',
        'cron'    => 'policy:disabled',
        'xmlrpc'  => 'policy:disabled',
    ],
    'consent_required'   => false,
    'audit_detail'       => 'high',
] );
```

Field semantics:

| Field | Purpose |
|---|---|
| `label`, `description` | Shown in the challenge UI and in admin displays of gated actions. Translatable. |
| `capabilities` | Required user capabilities. Gate fails closed if the user lacks them. Optional — some actions are role-independent. |
| `consequence_class` | Taxonomy for downstream consumers (plugin manifests, audit aggregation, risk reporting). Enum of core-defined classes plus extensibility. |
| `scope` | Defines which gated actions share a sudo session. A single successful challenge in the `core/plugins` scope satisfies all gated actions in that scope for the duration of the grace window. |
| `grace_window` | Seconds a scope-bound sudo session remains valid after a successful challenge. Default derived from site setting; overridable per action. |
| `surfaces` | Per-surface policy. Values: `gate` (full challenge), `policy:unrestricted` (no gate), `policy:limited` (require explicit site policy allow), `policy:disabled` (block entirely). |
| `consent_required` | If `true`, even a valid sudo session is insufficient; explicit user consent is required at the moment of action. Used for irreversible or cross-system operations (e.g., deleting the site, rotating an external credential). |
| `audit_detail` | Controls logging verbosity. |

Registration happens during `init`, priority 0, on a new action: `wp_register_gated_actions`. Core registers its own set first; plugins follow.

### Enforcement

The enforcement call is made *inside* the privileged function, after capability checks:

```php
function activate_plugin( $plugin, $redirect = '', $network_wide = false, $silent = false ) {
    // Existing capability check
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return new WP_Error( 'insufficient_permissions', ... );
    }

    // New: gate enforcement
    $gate = wp_enforce_gated_action( 'core/plugins.activate', [
        'context' => [
            'plugin'       => $plugin,
            'network_wide' => $network_wide,
        ],
        'on_challenge' => [
            'redirect_to' => $redirect,
        ],
    ] );

    if ( is_wp_error( $gate ) ) {
        // Gate definitively blocked (policy:disabled, lockout, expired consent)
        return $gate;
    }

    if ( $gate->needs_challenge() ) {
        // Surface-appropriate response
        if ( wp_doing_ajax() || wp_is_json_request() ) {
            wp_send_json_error( $gate->as_json_challenge(), 403 );
            exit;
        }
        wp_safe_redirect( $gate->challenge_url() );
        exit;
    }

    // Gate passed. Existing logic continues.
    // ...
}
```

The returned `$gate` is a `WP_Gate_Decision` object with a small, predictable API:

```php
$gate->passed();             // bool: session valid, action may proceed
$gate->needs_challenge();    // bool: user must reauthenticate before proceeding
$gate->blocked();            // bool: policy or lockout forbids the action entirely
$gate->reason();             // string: 'passed' | 'no_session' | 'expired' | 'scope_mismatch' | 'policy_blocked' | 'rate_limited' | 'consent_required'
$gate->challenge_url();      // string: URL to the challenge page (includes return URL and stash token)
$gate->as_json_challenge();  // array: { code, message, challenge_url, action_id }
$gate->as_rest_response();   // WP_REST_Response with 403 and challenge metadata
```

Callers that prefer a simpler API can use the boolean helper:

```php
if ( ! wp_gated_action_passes( 'core/plugins.activate' ) ) {
    wp_die_with_challenge( 'core/plugins.activate' );
}
```

### Querying

```php
wp_get_gated_action( 'core/plugins.activate' );      // Returns registration data or null
wp_gated_actions();                                  // Returns full registry (filtered by current user's visible scope)
wp_user_has_sudo( $user_id, 'core/plugins' );        // Returns true if the user has an active sudo session in that scope
wp_gated_action_requires_challenge( $action_id );    // Convenience wrapper: exists + needs challenge
```

UI surfaces use these to display lock icons, pre-warn users, or suppress controls when the backing action would require a challenge.

### Session lifecycle

The sudo session is a core object with the following properties:

| Property | Default | Purpose |
|---|---|---|
| User binding | User ID, session token | Session is valid only for the exact WordPress session that created it. |
| Scope | Single scope string | Session covers all gated actions in that scope. |
| Created at | Timestamp | Start of the grace window. |
| Grace window | Per-action `grace_window` | Expiry. |
| Challenge method used | String | Stored for audit (e.g., `password`, `webauthn`, `totp`). |
| Binding salt | Random per-session | Enables cryptographic verification that a replayed request matches the session that created it. |

Stored as user meta (`_wp_sudo_session_{scope}`), with the challenge-method salt bound to an httponly, SameSite=Strict cookie. This is the pattern WP Sudo uses today; see `includes/class-sudo-session.php`.

### Challenge mechanism

A default challenge implementation ships in core — password reauthentication via the existing `wp_authenticate()` flow, rendered on a dedicated challenge page that preserves the stashed original request for replay after success.

The challenge handler is replaceable via a filter:

```php
add_filter( 'wp_gated_action_challenge_handler', function ( $handler, $action_id, $user_id ) {
    // Custom handler implementing WP_Gate_Challenge_Handler interface
    return new My_Biometric_Challenge_Handler();
}, 10, 3 );
```

The `WP_Gate_Challenge_Handler` interface defines:

```php
interface WP_Gate_Challenge_Handler {
    public function render_challenge_ui( array $context ): void;
    public function verify_challenge_response( WP_User $user, array $submission ): bool;
    public function get_challenge_modality(): string; // 'password' | 'totp' | 'webauthn' | 'external'
}
```

This pluggability is essential because:

- 2FA plugins (Two Factor, Wordfence Login Security, etc.) need to participate in the challenge.
- Passkey/WebAuthn adoption should be possible without waiting for core support.
- Enterprise deployments may want to integrate external identity providers (Okta, Entra ID) for step-up authentication.
- Hardware token (YubiKey) workflows exist today and should remain viable.

### Policy for non-interactive surfaces

The per-surface policy field in registration is enforced by core at the surface entry points:

- **CLI surface:** `WP_CLI::before_invoke` hook checks whether the command maps to a gated action and applies the declared policy. `policy:disabled` short-circuits with an error; `policy:limited` checks a site-level allowlist; `policy:unrestricted` passes through.
- **Cron surface:** Scheduled events matched against gated actions. Same tier logic.
- **XML-RPC surface:** Method calls matched against gated actions.
- **REST surface via Application Passwords:** The gate distinguishes cookie-authenticated sessions from application-password-authenticated requests and applies the surface-specific policy.

The policy layer is necessary because many sites legitimately need to invoke gated operations from automation (deployment pipelines running `wp plugin activate`, backup plugins running in cron, etc.). Blanket gating would break these workflows; blanket exemption would leave the surface ungated. The three-tier model gives operators explicit control.

---

## Default Challenge Implementation

Core ships a minimal password-based challenge:

- A dedicated page at `wp-login.php?action=sudo-challenge` (or equivalent).
- Pre-filled with the current user's login (read-only).
- Accepts password only; 2FA plugins hook in via the challenge handler filter.
- On success, creates the sudo session in the requested scope, then replays the stashed original request.
- On failure, increments a rate limiter (per-user, per-IP, per-scope).
- Rate limit: five attempts in five minutes triggers a five-minute lockout (matches WP Sudo's current defaults).

The stash-and-replay mechanism preserves the original request method, headers, body, and target URL so the challenge feels like a pause rather than a restart. For REST/AJAX callers, the challenge is surfaced as a 403 response with enough metadata (`challenge_url`, `action_id`, `return_token`) for the client to initiate a modal challenge or redirect.

---

## Core Migration Plan

Core adoption proceeds in phases. No existing core function is modified in the primitive-introduction release; migration begins in the following release.

### Phase 1: Primitive shipped, no core adoption

**WordPress 7.x.** Ships:

- `wp_register_gated_action()`, `wp_enforce_gated_action()`, `wp_gated_action_passes()`
- `WP_Gate_Decision`, `WP_Gate_Challenge_Handler` classes/interfaces
- Default password challenge handler and UI
- Sudo session storage in user meta
- Audit hooks: `wp_gated_action_passed`, `wp_gated_action_challenged`, `wp_gated_action_blocked`, `wp_gated_action_replayed`
- Documentation in the developer handbook

Plugins begin opting in. WP Sudo migrates to wrap the core primitive instead of maintaining its own session layer; Fortress adds registration for its protected capabilities as gated actions; 2FA plugins implement the challenge handler interface.

### Phase 2: Core registers its own catalog

**WordPress 7.x + 1.** Core adds `wp_register_gated_action()` calls for the initial catalog, defaulting all policies to `policy:unrestricted` (i.e., registered but non-enforcing). The registration alone enables:

- UI lock icons on admin pages backing gated actions
- Audit hooks for plugins that want to observe without blocking
- Plugin manifest systems to reference the catalog

**Initial core catalog** (proposed):

| Action ID | Backing core function(s) |
|---|---|
| `core/plugins.activate` | `activate_plugin`, `activate_plugins` |
| `core/plugins.deactivate` | `deactivate_plugins` |
| `core/plugins.install` | `wp_ajax_install_plugin`, plugin upload handlers |
| `core/plugins.delete` | `delete_plugins` |
| `core/plugins.edit_source` | File editor for plugins |
| `core/themes.switch` | `switch_theme` |
| `core/themes.delete` | `delete_theme` |
| `core/themes.install` | Theme upload handlers |
| `core/themes.edit_source` | File editor for themes |
| `core/users.delete` | `wp_delete_user` |
| `core/users.promote_to_admin` | `wp_update_user` with role change to `administrator` |
| `core/users.change_password` | `wp_set_password` for other users |
| `core/options.update_siteurl` | `update_option( 'siteurl' )` |
| `core/options.update_home` | `update_option( 'home' )` |
| `core/options.update_admin_email` | `update_option( 'admin_email' )` |
| `core/options.toggle_registration` | `update_option( 'users_can_register' )` |
| `core/options.default_role` | `update_option( 'default_role' )` |
| `core/core.update` | `update_core` |
| `core/export.all_users` | User export routines |
| `core/export.all_content` | WXR export |
| `core/network.add_site` | `wpmu_create_blog` (multisite) |
| `core/network.delete_site` | `wpmu_delete_blog` (multisite) |
| `core/network.grant_super_admin` | `grant_super_admin` (multisite) |
| `core/network.revoke_super_admin` | `revoke_super_admin` (multisite) |
| `core/connectors.update_credentials` | `POST /wp/v2/settings` with `connectors_*_api_key` payload |
| `core/abilities.execute_destructive` | `wp_before_execute_ability` for destructive abilities (future) |

### Phase 3: Gradual enforcement

**WordPress 7.x + 2 and later.** Core begins flipping the default surface policy from `policy:unrestricted` to `gate` for specific action classes, one release at a time, in priority order:

1. Plugin management (activation, install, delete, edit_source)
2. User privilege changes (promote_to_admin, delete, change_password)
3. Sensitive option writes (siteurl, home, admin_email, connectors)
4. Theme management
5. Export operations
6. Multisite network actions

Each flip is opt-out: site operators can set `add_filter( 'wp_gated_action_default_policy', ... )` to preserve previous behavior during migration, with a documented deprecation timeline.

### Phase 4: Primitive becomes mandatory for new core APIs

**WordPress 8.x+.** New APIs introduced after this point are expected to register gated actions at design time rather than retrofit them. Code review for core contributions includes gate registration as a checklist item for any new privileged operation.

### Timeline summary

| Release | Milestone |
|---|---|
| 7.x | Primitive shipped, plugins opt in |
| 7.x+1 | Core catalog registered, non-enforcing |
| 7.x+2 | Plugin management gated by default |
| 7.x+3 | User privilege changes gated by default |
| 7.x+4 | Sensitive options, themes, exports gated by default |
| 8.x | Primitive expected for all new privileged APIs |

This timeline mirrors the Customizer → Site Editor deprecation pattern: a multi-release, telemetry-informed rollout with explicit opt-outs at each phase.

---

## Plugin Adoption Path

### For plugins with privileged operations

```php
// In plugin bootstrap
add_action( 'init', function () {
    wp_register_gated_action( 'my-plugin/orders.refund', [
        'label'             => __( 'Refund an order', 'my-plugin' ),
        'capabilities'      => [ 'manage_woocommerce' ],
        'consequence_class' => 'financial',
        'scope'             => 'my-plugin/financial',
        'grace_window'      => 300,
        'surfaces'          => [
            'admin'  => 'gate',
            'rest'   => 'gate',
            'cli'    => 'policy:limited',
        ],
    ] );
}, 5 );

// In the refund handler
function my_plugin_refund_order( $order_id ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return new WP_Error( 'insufficient_permissions', ... );
    }

    $gate = wp_enforce_gated_action( 'my-plugin/orders.refund', [
        'context' => [ 'order_id' => $order_id ],
    ] );

    if ( $gate->needs_challenge() ) {
        wp_safe_redirect( $gate->challenge_url() );
        exit;
    }

    // Process refund
}
```

### For security plugins (WP Sudo, Fortress, etc.)

Security plugins transition from maintaining their own session layer to consuming the core primitive:

1. **Deprecate internal session storage.** Use the core `WP_Gate_Decision` / sudo session mechanism.
2. **Register additional actions or override core registrations.** Use the `wp_gated_actions` filter to extend coverage or tighten policy beyond core defaults.
3. **Implement the challenge handler interface.** For plugins that add 2FA, WebAuthn, or policy-based challenge modalities.
4. **Preserve configuration UI.** Continue providing site-level controls for grace window, per-surface policy, and custom rules, but write through to the core primitive's storage.

This path lets existing protection plugins become thinner and more interoperable rather than being obsoleted. WP Sudo's value proposition shifts from "sudo, re-implemented" to "operational tooling and opinionated policy on top of core sudo."

### For general-purpose plugins

Plugins that don't gate their own actions but want to check whether a gated action is about to be invoked (for UI warnings, pre-action logging, etc.) use the query API:

```php
if ( wp_gated_action_requires_challenge( 'core/plugins.activate' ) ) {
    echo '<span class="requires-sudo-indicator" aria-label="Requires re-authentication"></span>';
}
```

---

## Interaction with Existing Systems

### Capability system

The gate is additive. Capability checks run first, the gate runs second. A user without the required capability is rejected by `current_user_can()` before the gate is ever consulted. A user with the capability but without an active sudo session is sent to the challenge.

This ordering is important: the challenge UI itself requires capability context to render correctly (e.g., showing "Activate Akismet" in the challenge prompt). Running the capability check first ensures the challenge only appears for users who could plausibly complete the action.

### Application Passwords

Application-password-authenticated requests are treated as a distinct surface for policy purposes. Core already distinguishes cookie-authenticated from application-password-authenticated requests via `$_SERVER['PHP_AUTH_USER']` and the authentication filter chain; the gate adds per-action policy tiers for this surface.

Default policy for application passwords on privileged actions: `policy:limited`. Site operators explicitly allowlist which actions may be invoked over application passwords, matching the WP Sudo approach.

### WP-CLI

CLI gating uses `WP_CLI::add_hook( 'before_invoke:<command>', ... )` to match commands against gated actions. The map is explicit (not inferred from command semantics) because CLI command names are not always aligned with the functions they call.

### wp-cron

Cron callbacks are matched by hook name and callback identity. The default for cron is `policy:disabled` for nearly all gated actions, since cron runs unattended and has no human to challenge. Site operators may override on a per-action basis.

### REST API

REST gating hooks `rest_pre_dispatch` with knowledge of the route pattern and method. The existing `permission_callback` in each route runs first (capability authorization); the gate runs after the callback passes but before the handler executes.

### XML-RPC

XML-RPC method calls are matched at the `xmlrpc_call` action. The default for XML-RPC on gated actions is `policy:disabled` given the protocol's legacy status and limited use of per-action authentication.

### WPGraphQL

When WPGraphQL is installed, its resolvers are matched against gated actions via the existing `do_graphql_request` hook. The gate registers as an inspection layer after WPGraphQL's own authentication.

### Two Factor plugins

The Two Factor plugin (plugin directory slug `two-factor`) and similar plugins implement the `WP_Gate_Challenge_Handler` interface. A 2FA-equipped site gets 2FA-enforced challenges automatically once the plugin registers its handler; core does not need to know the specifics of the 2FA modality.

### Abilities API (WordPress 7.0+)

The `wp_before_execute_ability` hook, fired before every ability execution (including the direct PHP path via `WP_Ability::execute()`), is a natural integration point. Destructive abilities can be registered as gated actions and automatically gated regardless of whether they are invoked via REST, CLI, or direct PHP.

```php
wp_register_gated_action( 'my-plugin/abilities.delete_all_drafts', [
    'backing_ability' => 'my-plugin/delete-all-drafts',
    // ...
] );
```

Core's gate enforcement hooks `wp_before_execute_ability`; when the ability about to execute has a registered gate, the gate fires before the ability's callback runs.

### Connectors API (WordPress 7.0+)

The `core/connectors.update_credentials` action in the initial catalog closes the external credential replacement vector documented in `connectors-api-reference.md`. The gate intercepts `POST /wp/v2/settings` when the payload contains `connectors_*_api_key` keys, regardless of whether the caller is the admin UI, a plugin, or a REST client.

---

## Design Decisions and Tradeoffs

### Scope-bound vs. site-wide sudo session

WP Sudo's current model: one sudo session covers all gated actions for its duration.
This proposal: sudo sessions are scoped; one challenge covers one scope.

**Why change.** A single session covering all actions maximizes convenience but gives stolen-session windows broader reach. Scoping to semantic clusters (plugin management, user management, financial, credentials) limits blast radius while keeping UX acceptable. Compare: `sudo` on Unix does not grant persistent root across terminals; it grants a short window in the invoking shell.

**Tradeoff.** More prompts per session for admins working across multiple areas (activating a plugin *and* changing a user role). Mitigated by per-scope grace window tuning and by the fact that most admin flows concentrate in one scope at a time.

### Grace window default

Default proposed: 300 seconds (5 minutes).

Compare:
- macOS `sudo`: 5 minutes by default
- Linux `sudo`: 15 minutes by default (varies by distribution)
- WP Sudo current: 5 minutes default, configurable 1-15 minutes
- AWS IAM session token: typically 1 hour

Five minutes balances prompt fatigue against exposure. Site operators can tune via filter or site setting.

### Challenge modality

Password-based is the default because every WordPress site has a password and no dependencies. 2FA plugins, passkey plugins, and external identity providers replace the handler via filter.

An argument exists for defaulting to "whatever the user used at login" — if they logged in with a passkey, challenge with a passkey. Core does not currently track the primary authentication modality in a way that makes this trivial; the challenge handler filter is the extensibility point that makes this achievable in userland until core login tracking catches up.

### Consent-required actions

Some actions should require explicit user consent even with a valid sudo session:

- Irreversible operations (delete all content, delete user with all content)
- Cross-system operations (rotate an external API key, notify an external service)
- Financial operations (refund above a threshold)
- Multisite destructive operations (delete site, delete network)

The `consent_required: true` flag triggers an additional confirmation UI at the moment of action, overlaying the sudo session. This is analogous to iOS permission prompts that require fresh consent even when the app has previously been granted the permission.

### Surface policy granularity

The three-tier model (disabled / limited / unrestricted) is deliberately narrow. Finer granularity (per-role, per-IP, time-of-day, etc.) is left to policy plugins that layer on top.

### Per-action vs. per-call policy

Policy is per-action, not per-call. A given action has one policy per surface; it cannot be `gate` for some invocations and `policy:unrestricted` for others based on arbitrary runtime state. This constraint keeps the semantics predictable and auditable. Runtime adjustments (e.g., trusted source IPs) are expressed as changes to which surface the request is classified into, not as per-call policy variation.

### Session storage

User meta + cookie-bound salt is the minimum viable design. Alternatives considered:

- **Dedicated custom table.** Cleaner but requires schema migration and more complex uninstall logic.
- **Transients.** Rejected: transients are global, not user-scoped, and rely on object cache behavior that varies across hosts.
- **JWT-style signed tokens.** Rejected: stateless tokens can't be revoked mid-window, which is a critical property for security response.

User meta is already user-scoped, reliable across hosts, and revocable.

### Performance

The enforcement call is designed to be cheap in the common case:

- Active session: one user meta read, one timestamp comparison. Sub-millisecond.
- No session: registry lookup, decision construction, return. Fractional millisecond.
- Challenge required: all of the above plus a redirect. Once per grace window per scope.

At a typical 50-100 gated actions per admin request (most of which hit the "already in sudo" fast path after the first challenge), overhead is negligible.

### Failure modes

- **Registry corruption / empty.** Gate fails open: unregistered actions pass through. This matches current WordPress behavior and preserves backward compatibility.
- **Session storage failure.** Gate fails closed for registered actions: user is sent to challenge. A user who cannot store a session cannot produce one, which correctly blocks progress rather than silently allowing it.
- **Challenge handler exception.** Gate fails closed: user sees an error page with a recovery path. Logged as `wp_gated_action_challenge_error`.
- **Core catalog divergence from code.** If core modifies `activate_plugin` without updating its gate registration, gating fails silently on that specific action. Caught by integration tests that verify the catalog against known privileged functions.

---

## Relationship to WP Sudo

WP Sudo is the most complete production implementation of this design pattern in the WordPress ecosystem. This proposal treats WP Sudo as prior art and as the reference implementation for the behaviors described.

### What WP Sudo already demonstrates works

- Hook-based interception across admin, AJAX, REST, CLI, cron, XML-RPC, WPGraphQL surfaces.
- Three-tier per-surface policy model (Disabled / Limited / Unrestricted).
- Short-lived, cryptographically-bound, browser-scoped sudo sessions.
- Rate limiting (five attempts, five-minute lockout).
- Two Factor plugin integration via documented hooks.
- Request stash-and-replay across the challenge boundary.
- Audit hook emission for all gate outcomes.
- Two-tier expiry with grace window (`is_active` vs. `is_within_grace`).

### What migrates from WP Sudo to core

- The `Action_Registry` becomes `wp_register_gated_action()`.
- The `Gate` class's interception logic becomes the core enforcement layer.
- The `Sudo_Session` class's session model becomes the core session.
- The `Challenge` class becomes the default challenge handler implementation.
- The `Request_Stash` logic becomes a core utility used by the challenge flow.

### What WP Sudo continues to own

- Opinionated policy defaults for operators (more aggressive than core's defaults).
- Admin-bar countdown timer and operational UX enhancements.
- Site Health integration surfacing gate configuration health.
- Policy presets (quick-configure "Lockdown", "Balanced", "Permissive" modes).
- Additional gated actions beyond core's initial catalog.
- Multisite-specific operational tooling.
- Integration adapters for security plugins that don't adopt the core primitive.

The migration path for existing WP Sudo deployments is to upgrade to a version that delegates to core when available and falls back to its own implementation when not.

### What this proposal does not claim

- That WP Sudo's exact API should be lifted verbatim. Core adoption requires API review, bikeshedding, and probably renaming. The API sketch above is illustrative of the shape, not a final specification.
- That WP Sudo is the only prior art. Fortress, Wordfence, Solid Security, and others have informed this design through their own implementations of overlapping concerns.

---

## Out of Scope

The following are related but explicitly not addressed by this proposal:

- **Plugin capability manifests and plugin isolation.** A separate, larger proposal. This primitive is one building block such a system would consume.
- **Capability system overhaul.** No changes to `current_user_can()`, role/capability semantics, or the meta-cap filter chain.
- **Authentication replacement or extension.** Login flow remains unchanged. Passkey/WebAuthn adoption happens through the challenge handler filter, not through changes to the login form.
- **Session lifecycle hardening.** Absolute timeout, idle timeout, and session rotation are orthogonal concerns handled by Fortress-style plugins or future core work.
- **Rate limiting as a general primitive.** The gate includes per-scope challenge rate limiting; general-purpose rate limiting for arbitrary actions is not in scope.
- **Audit log persistence.** The gate emits audit hooks; persistence, rotation, and querying of audit logs is left to operator tooling.
- **Cross-site / multisite session propagation.** A sudo session is site-bound. Whether super-admin operations should carry a sudo session across network sites is a question for a follow-up proposal.
- **UI for managing the gated action catalog.** Core surfaces a basic read-only view; rich policy management UX is left to WP Sudo and similar plugins.

---

## Relationship to Structural Modernization Proposals

Parallel to this proposal, a recurring argument in the WordPress community holds that the platform's core security problems — particularly the plugin execution model — cannot be meaningfully addressed within the current backwards-compatibility envelope. Several substantive contributions to that argument have surfaced in April 2026 and are worth naming because they map different positions on the same underlying question:

- **Malcolm Peralty, ["A Letter to Matt on WP Next, Part 1: The Case for the Split"](https://peralty.com/2026/04/17/a-letter-to-matt-on-wp-next-part-1-the-case-for-the-split/)** (2026-04-17). Proposes forking the project into two maintained lines: **WP Classic** (frozen architecture, long-horizon security patches, no structural changes) and **WP Next** (PHP 8.2+ baseline, object-oriented kernel with dependency injection, Composer-first distribution, modernized admin, and — relevant to this proposal — a "proper permissions model" rather than the current "trust everybody with the whole process" plugin contract). Peralty's core claim on the plugin contract: the runtime cannot be repaired within backwards-compatibility constraints; only a line-in-the-sand split can.

- **Joost de Valk, ["WordPress: Refactor, Don't Redecorate"](https://joost.blog/wordpress-refactor-not-redecorate/)**. Makes the same technical critique of the plugin permission model — *"a plugin runs with the same permissions as the PHP process"*; *"the keys to the entire database — and the entire filesystem"*; and an analogy comparing the current plugin contract to granting every mobile app root access to the phone — but lands on the opposite architectural conclusion: these problems can be retrofitted backwards-compatibly. Cites Yoast's Indexables table and WooCommerce's High-Performance Order Storage as precedents for schema-level refactor without breaking existing APIs. Explicitly acknowledges the hard version: *"retrofitting a permission model without breaking thousands of existing plugins is a genuinely hard problem. But hard isn't the same as impossible."* The refactor-not-split thesis is directly compatible with the posture of this proposal.

- **Brian Coords, ["EmDash: First Thoughts and Takeaways for WordPress"](https://www.briancoords.com/emdash-first-thoughts-and-takeaways-for-wordpress/)**. Ecosystem-level commentary on Cloudflare's EmDash CMS as a contrast case. The one architectural point relevant here is the same plugin-contract critique stated concisely: EmDash uses *"a much more locked-down approach to extensibility"* versus WordPress where *"a plugin can literally destroy or expose your entire database if it wanted to."* Coords does not propose a mechanism; the post's value in this context is as a third-voice confirmation that the plugin contract is being named as a problem beyond any single author's framing.

The Peralty and Joost positions are the ones that matter for this proposal. They agree on the diagnosis of the plugin contract and disagree on the prescription. This proposal takes no position on whether a full refactor or a split is the right path for WordPress generally, but it does take a position on the narrow question of proof-of-intent for consequential operations: that narrow question can and should be addressed backwards-compatibly, regardless of whether the broader runtime refactor or split proceeds.

### The honest limit of this proposal under the Peralty critique

Peralty's critique of the plugin contract is accurate as a description of the runtime: plugins run in the same PHP process as core, share the same database credentials, hold the same filesystem and network access, and are subject to no enforceable declaration of what they intend to do. A gate that runs *inside* that same process cannot repair the contract. It can only require fresh proof of human intent before declared consequential operations proceed. Plugins that do not call the gate, plugins with authorization bugs that never reach a gated core function, and plugins whose malicious behavior does not require privileged core APIs are all outside the gate's reach. The primitive is useful precisely to the extent that the harms it addresses — stolen-session exploitation of privileged core operations and external credential tampering — are a subset of the broader problem Peralty identifies, not the whole of it.

This matters because the gate primitive can appear, at first reading, to be a candidate replacement for runtime isolation. It is not. Shipping it should not be interpreted as evidence that runtime isolation is unnecessary.

### Under a WP Classic path

If WordPress pursues Peralty's Classic line — or, more likely in governance practice, a slower implicit version of the same posture where the existing codebase continues accreting without a structural break — the action gate is approximately the ceiling of in-process defense. It delivers:

- A stable, shared catalog of consequential operations across the ecosystem
- Reduced blast radius for stolen sessions performing privileged operations through core APIs
- A documented path for security plugins to converge on instead of each maintaining divergent implementations
- A hook point for 2FA, passkey, and external-IdP step-up challenges
- Audit-quality observability of privileged action attempts regardless of caller

It does not deliver isolation, and it does not close the vectors that run entirely within a compromised plugin's own code. These remain open under any Classic trajectory until the plugin contract itself changes.

Under this path, the primitive's value scales with adoption. Core migrating its own privileged functions to the gate produces the baseline benefit. Widespread plugin adoption — in particular, commerce, membership, and financial-operation plugins calling the gate from their own sensitive functions — produces the ecosystem-wide benefit. The primitive remains useful even if adoption is partial, but the ceiling of a Classic future is lower than its proponents sometimes claim.

### Under a WP Next path

If WordPress pursues Peralty's Next line — a parallel modernized codebase with a new plugin contract that enforces runtime isolation (WASM sandboxes, subprocess execution, capability-based host imports, or equivalent) — the action gate is still relevant, just in a different role.

In a properly isolated plugin runtime, the operations a plugin can invoke are already constrained by the host's capability grants. A plugin without the "modify-users" capability cannot invoke `wp_update_user()` regardless of whether a gate is present, because the host does not expose that function to the plugin's sandbox. This closes the broad class of unrestricted-invocation harms.

What isolation does not address on its own:

- A properly-granted capability still being invoked by a stolen session within a legitimate plugin. If the plugin legitimately has user-management capability, and an attacker has a stolen admin session, the isolation boundary is intact but the admin-level trust decision remains compromised.
- Credential tampering against external services, which is a correctly-authorized operation being abused.
- The admin's own operations being performed under coerced or stolen identity.

For those residual cases, "proof of human intent at the moment of the consequential operation" remains the appropriate defense, and it is architecturally the same concept regardless of whether the operation originates from in-process PHP or from a sandboxed plugin calling a host-exported function. In a Next runtime, the gate becomes a layer *above* the capability grants rather than the outermost defense, and its API shape may migrate from a procedural call (`wp_enforce_gated_action()`) to a declarative attribute on host-exported functions. The semantics — declarative catalog, scope-bound sudo session, pluggable challenge, surface policy tiers — transfer directly.

The primitive is therefore not "backwards-compatible hardening that becomes obsolete under WP Next." It is a reusable semantic model for proof-of-intent that is orthogonal to whether the runtime is isolated.

### Why ship it in the meantime

The governance-realistic observation is that a WordPress split has been discussed, in various forms, for at least a decade, and no such split has occurred. Multiple forks (ClassicPress, etc.) exist but have not achieved the ecosystem mass that would make them a credible migration target. Betting on a split to arrive on any specific timeline is a bet against observed governance behavior.

In the meantime, the installed base — the 43% of the web Peralty cites — continues to run the current contract. Session theft continues to occur. Privileged operations continue to be performed by stolen credentials. External credential surfaces continue to grow (Connectors API, AI provider keys, MCP server credentials in future releases). The harms this primitive addresses are happening now, regardless of what structural reforms may or may not arrive later.

Shipping the primitive does not preclude structural reform. It is cheap to implement, it is backwards-compatible, and it is a strict improvement over the current state for the defined threat model. The correct reading is neither "this instead of runtime isolation" nor "this before we get serious about runtime isolation" but "this regardless of whether runtime isolation ever arrives, because it addresses a distinct threat class that isolation alone does not close."

### What this proposal asks of the structural debate

If the structural debate proceeds — whether as an explicit WP Classic / WP Next split or as a gradual modernization inside the current line — the authors of that effort should:

1. Treat the proof-of-intent concern as a first-class requirement, not a nice-to-have. A runtime with strong isolation but no consent/reauthentication layer is still vulnerable to stolen-session abuse of legitimately-granted capabilities.
2. Preserve the semantic model (action catalog, scope-bound sessions, surface policies, pluggable challenges) even if the API shape changes.
3. Design the plugin capability manifest — if one is introduced — to consume the gated action catalog as its taxonomy of consequential operations, not to reinvent it.

Conversely, if the structural debate does not proceed, the action gate primitive is what can be delivered now to materially improve the security posture of the installed base, without waiting for structural consensus that has historically not arrived.

---

## Open Questions

1. **Should the default for `consent_required` actions be "explicit UI confirmation" or "separate challenge"?** Consent and proof-of-intent are distinct, but the UX cost of requiring both may be prohibitive.
2. **Should scope definitions be hierarchical?** e.g., a session in `core/plugins` could cover `core/plugins.activate` and `core/plugins.delete` but not `core/plugins.install` if install is a protected sub-scope.
3. **Should the gate emit a challenge for application-password-authenticated requests differently than cookie-authenticated requests?** Application password tokens already represent an explicit grant; some argue the gate should treat them as pre-sudo.
4. **Should `policy:limited` at the surface level include a per-action site policy, or should surface-level policy be uniform?** Finer control is valuable; implementation complexity is higher.
5. **What is the minimal viable set of challenge handler interface methods?** The interface sketched above is a starting point; the real interface needs broader input from 2FA and passkey plugin authors.
6. **How does the gate interact with `_doing_it_wrong` and similar developer notices?** Misuse of the primitive (registering gates without enforcement, etc.) should produce actionable developer feedback without production noise.
7. **Should core provide a telemetry hook for gate decisions?** Aggregated challenge rates, scope usage, and policy-block frequencies could inform both operators and core's migration timeline.

---

## References

- WP Sudo production implementation: `includes/class-gate.php`, `includes/class-sudo-session.php`, `includes/class-action-registry.php`, `includes/class-challenge.php`.
- WP Sudo comparative analysis: `docs/sudo-architecture-comparison-matrix.md`.
- WP Sudo security model: `docs/security-model.md`.
- WordPress 7.0 Abilities API: `docs/abilities-api-assessment.md`, [Introducing the Abilities API](https://developer.wordpress.org/reference/classes/wp_ability/).
- WordPress 7.0 Connectors API: `docs/connectors-api-reference.md`, [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/).
- Two Factor ecosystem analysis: `docs/two-factor-ecosystem.md`.
- Core authentication reference: `docs/wordpress-core-authentication.md`.
- Operating system sudo semantics: `man 8 sudo`, macOS/BSD implementations, Linux PAM configuration.
- Browser extension manifest evolution: Manifest V2 → V3 migration history.
- iOS entitlements and runtime permission prompts: Apple Platform Security documentation.
- Malcolm Peralty, ["A Letter to Matt on WP Next, Part 1: The Case for the Split"](https://peralty.com/2026/04/17/a-letter-to-matt-on-wp-next-part-1-the-case-for-the-split/) (2026-04-17) — structural critique of the current WordPress plugin contract, argues for a split between WP Classic and WP Next; informs the "Relationship to Structural Modernization Proposals" section.
- Joost de Valk, ["WordPress: Refactor, Don't Redecorate"](https://joost.blog/wordpress-refactor-not-redecorate/) — makes the same plugin-permission critique as Peralty but argues for backwards-compatible refactoring, citing Yoast Indexables and WooCommerce HPOS as precedents; directly compatible with the posture of this proposal.
- Brian Coords, ["EmDash: First Thoughts and Takeaways for WordPress"](https://www.briancoords.com/emdash-first-thoughts-and-takeaways-for-wordpress/) — ecosystem contrast between Cloudflare's EmDash and WordPress, reiterates the plugin-contract concern at the DX/ecosystem level.

