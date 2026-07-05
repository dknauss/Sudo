# Security Model

WP Sudo is a **hook-based interception layer**. It operates within WordPress's plugin API — `admin_init`, `pre_option_*`, `activate_plugin`, REST `permission_callback`, etc. — and is subject to the same boundaries as any WordPress plugin.

### Terminology Note

WP Sudo uses the term **reauthentication** to describe its core pattern, following [NIST SP 800-63B §7.2](https://pages.nist.gov/800-63-4/sp800-63b.html#sessionreauthn): *"Periodic reauthentication of subscriber sessions SHALL be performed to confirm the subscriber's continued presence and intent to be authenticated."* Reauthentication describes the security pattern of requiring a user to prove their identity again during an existing session. The underlying mechanisms — password hash comparison (`wp_check_password`), TOTP code validation, etc. — are verification at the cryptographic level, but the overall pattern is reauthentication, not verification. When 2FA is enabled for WP Sudo, it is also enabled for the initial WordPress login, so the challenge operates at the same assurance level — this is pure reauthentication, not step-up authentication (which would imply a higher assurance level than the initial login).

## What It Protects Against

- **Compromised admin sessions** — a stolen session cookie cannot perform covered gated actions without reauthenticating unless that same browser session already has an active sudo window. The sudo session is cryptographically bound to the browser.
- **Connector credential replacement** — a stolen `manage_options` browser session cannot silently replace database-backed Connectors API keys over `POST`/`PUT`/`PATCH /wp/v2/settings` without reauthenticating first. The rule matches only that REST route (the WP 7.0 Connectors panel saves through it); options writes that reach the database through other admin-side paths are not covered by this rule. REST readback already masks the stored secret — WordPress 7.0 core registers `_wp_connectors_rest_settings_dispatch()` on `rest_post_dispatch`, which replaces each registered connector API-key setting in the `/wp/v2/settings` response with a masked value via `_wp_connectors_mask_api_key()` (`wp-includes/connectors.php`).
- **Session theft → password change → lockout** — password changes on the profile/user-edit pages and via the REST API are a gated action (`user.change_password`). An attacker who steals a session cookie cannot silently change the victim's password without triggering the challenge.
- **Insider threats** — even legitimate administrators must prove their identity before destructive operations.
- **Automated abuse** — headless entry points (WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL) can be disabled entirely or restricted to non-gated operations.
- **2FA replay** — the two-factor challenge is bound to the originating browser via a one-time cookie, preventing cross-browser replay.
- **Capability tampering** — direct database modifications to restore `unfiltered_html` on the Editor role are detected and reversed at `init`.
- **Privilege escalation to administrator (opt-in, default OFF)** — when enabled via the `wp_sudo_guard_escalation` filter, WP Sudo refuses to *newly* grant the `administrator` role (single-site) or super-admin (multisite) unless the acting user **both** holds the promoting authority — `promote_users` for administrator grants (checked on the blog whose capabilities are being written, on multisite), or existing super-admin status for `grant_super_admin` — **and** has an active or in-grace sudo session. Sudo is reauthentication, not authorization: a low-privilege account *can* hold a sudo session, so the authority check is what stops it, while the session requirement stops a stolen-cookie replay. It hooks the **effect** — the `{prefix}capabilities` user-meta write and `grant_super_admin` — not a surface, so it applies on admin, REST, AJAX, and unauthenticated front-end requests alike: an attacker who lacks the promoting authority, or has no sudo session, is blocked even when the vulnerable code's own capability check fails. It is **off by default**, and even when enabled it does not fire for low-privilege role assignments, demotions, or idempotent re-grants. Its boundaries (default-OFF, the `user_has_cap`/`$wpdb` blind spots, and the in-session residual) are listed under [What It Does Not Protect Against](#what-it-does-not-protect-against); full rationale is in [admin-escalation-guard-analysis.md](admin-escalation-guard-analysis.md).

## Internal Admin Users and Governance Boundary

WP Sudo ships with a four-capability governance model (v3.2.0). Access to Sudo
settings, activity views, exports, and session revocations is controlled by
dedicated capabilities rather than broad `manage_options` inheritance:

| Capability | Grants access to |
|---|---|
| `manage_wp_sudo` | Sudo settings, policy configuration, capability management |
| `view_wp_sudo_activity` | Dashboard widget and activity screens |
| `export_wp_sudo_activity` | Activity data exports (CSV/JSON) |
| `revoke_wp_sudo_sessions` | Force-revoking other users' active sessions |

**Default on new installs (single-site):** the activating admin receives all four
capabilities. Other admins receive none until explicitly granted from
**Settings → Sudo → Access**.

**Multisite:** super admins are always treated as holding all four capabilities
(via `is_super_admin()` short-circuit in `wp_sudo_can()`). Per-site admins receive
no Sudo-management authority until explicitly delegated.

**4.0.0 note:** the `compatibility` governance mode and the `sudo_can()` alias
were removed in 4.0.0. Governance is now always strict — `wp_sudo_can()` delegates
directly to `user_can( $user_id, $cap )` with no `manage_options` fallback. See
[Migrating to 4.0](developer-reference.md#migrating-to-40).

**Break-glass recovery — the sole remaining escape hatch:** if every holder of `manage_wp_sudo` is removed,
`define('WP_SUDO_RECOVERY_MODE', true)` in `wp-config.php` grants temporary
access. This refers to WP Sudo's break-glass governance recovery path, not WordPress core's `WP_Recovery_Mode`.
The break-glass recovery check is **role-gated**: while the constant is defined, the
current user receives effective `manage_wp_sudo` only if they *also* hold site
or network admin authority (`manage_options` on single-site,
`manage_network_options` on multisite). The governance model deliberately
separates `manage_wp_sudo` from `manage_options`, so a locked-out manager who
kept their admin role still recovers, while subscribers, editors, and other
non-admins gain nothing. Both `wp_sudo_can()` and the `map_meta_cap` mapping
apply the gate (the meta cap maps to the admin primitive cap, so WordPress
core's own admin-page gate and multisite super-admin bypass do the enforcing),
and the multisite super-admin short-circuit is unchanged.

This narrows but does not eliminate the residual risk: while the constant is set,
*every* user who holds `manage_options` (`manage_network_options` on multisite)
regains full Sudo governance **regardless of role** — so a custom non-administrator
role granted `manage_options` qualifies too. On a multi-admin site that is still a
meaningful elevation, since any such user can self-grant the other three caps and
change gating policy from the Access tab.
Two safeguards make the window visible:

- A **permanent, non-dismissible warning notice** appears on the Sudo settings
  screen while break-glass recovery mode is active.
- The `wp_sudo_recovery_mode_active` **audit hook** fires on every Sudo
  admin-page load under break-glass recovery mode (unthrottled, for external loggers); the
  bundled recorder stores a sampled `recovery_mode` event (one per user per hour).

Defining the constant requires `wp-config.php` write access, so the practical
risk is operator error — enabling it for break-glass and then leaving it on.
**Remove the constant the moment normal access is restored.**

> **Note (multisite and non-admin managers):** because the gate requires
> `manage_network_options` on multisite, break-glass recovery effectively only matters
> alongside super-admin access there; and because it requires `manage_options`
> everywhere, break-glass recovery does **not** rescue a Sudo manager who was deliberately
> granted `manage_wp_sudo` *without* a WordPress admin role. Recover such a user
> another way — e.g. `wp user add-cap <user> manage_wp_sudo` via WP-CLI, or
> temporarily granting them `manage_options`. A scoped recovery form
> (`define('WP_SUDO_RECOVERY_MODE', <user_id_or_login>)`) that grants a single
> named user is tracked as follow-up work.

See [FAQ](FAQ.md#what-is-break-glass-recovery-mode-and-when-should-i-use-it).

Operationally, this implies:

- WP Sudo should be paired with external immutable logging where possible.
- Organizations with many admins should treat Sudo settings access as a distinct
  governance concern and limit `manage_wp_sudo` to the subset of administrators
  who should own security policy.
- Capability grant/revoke and session-revoke AJAX operations are covered by the
  `options.wp_sudo_access` gated rule, requiring an active sudo session before
  proceeding.

See [`docs/archive/internal-admin-governance-spec.md`](archive/internal-admin-governance-spec.md)
for the archived design spec (implemented in 3.2.0).

### Multisite role terminology and Sudo's boundary

Several long-running WordPress Core tickets separate three related ideas that are
easy to collapse in security documentation:

- [#37593](https://core.trac.wordpress.org/ticket/37593) treats **network
  administrator** as the clearer user-facing name for the ordinary Multisite
  network-level administrator.
- [#39174](https://core.trac.wordpress.org/ticket/39174) explores network roles,
  global roles, and whether **super admin** should mean a permanent role, a
  global administrator, or a special emergency authority.
- [#20140](https://core.trac.wordpress.org/ticket/20140) is about asking for the
  current password before account changes, but the discussion expands naturally
  into a general recent-authentication gate for high-impact actions.

Sudo should align with those distinctions. It is **not** a Multisite role system
and does not try to define site, network, or global administrator roles for Core.
It is the reauthentication layer around actions that are already authorized
by WordPress. In user-facing prose, prefer **network administrator** for ordinary
Multisite authority. Use **super admin** only when referring to WordPress Core's
technical super-admin concept or APIs such as `is_super_admin()`. Use
**break-glass recovery mode** for Sudo's emergency governance recovery path, and
use **sudo session** or **sudo window** for the short-lived recent-authentication
state that allows a gated action to proceed.

This distinction matters for future Core alignment: network roles decide **who is
authorized** to act; Sudo decides whether an already-authorized actor has proved
their current presence and intent recently enough to perform a covered high-risk
action.

## Threat Model: The Kill Chain

Model a WordPress compromise as a kill chain:

1. **Initial Access** — brute force, exploit, credential theft, XSS
2. **Establish Session** — session cookie, app password, direct auth
3. **Escalate/Persist** — add admin user, install backdoor plugin, modify files, change credentials
4. **Impact** — defacement, data exfiltration, spam, crypto mining

Traditional security plugins focus on **step 1** (blocking initial access). Sudo focuses on the **step 2→3 transition** — even with a valid session, destructive actions require credential proof. See the [Architecture Comparison Matrix](sudo-architecture-comparison-matrix.md) for a detailed comparison of WP Sudo's approach with other reauthentication patterns.

### Public data supporting risk reduction

**Vulnerability landscape** ([Patchstack 2025 whitepaper](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2025/), covering 7,966 vulnerabilities discovered in 2024):

- XSS: 47.7%, Broken Access Control: 14.2%, CSRF: 11.4%, Privilege Escalation: 1.6%, Broken Authentication: 1.0%
- Directly mitigated classes (BAC + CSRF + PrivEsc + BrokenAuth) = ~28% of all WP vulnerabilities

**2025 update** ([Patchstack 2026 whitepaper](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/), covering 11,334 vulnerabilities discovered in 2025 — a 42% increase):

- Highly exploitable vulnerabilities increased 113% YoY
- Traditional WAFs blocked only 12% of attacks targeting known exploited WordPress vulnerabilities (26% with an expanded rule set)
- 46% of vulnerabilities had no developer fix at the time of public disclosure
- Approximately half of high-impact vulnerabilities were exploited within 24 hours; the weighted median time to first exploit was 5 hours

**Exploitation targeting** ([Patchstack 2026 whitepaper](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/) — RapidMitigate blocked attack data, 2025):

- Broken Access Control: 57% of all exploitation attempts
- Privilege Escalation: 20%, Broken Authentication: 3%
- Sudo-mitigated classes account for 80% of actual WordPress exploitation attempts — far exceeding the ~28% share of discovered vulnerabilities
- XSS (47.7% of discovered vulnerabilities) accounts for only 1% of exploitation attempts — attackers overwhelmingly target access control flaws

**Post-compromise forensics** ([Sucuri 2023 Hacked Website Report](https://sucuri.net/reports/2023-hacked-website-report/)):

- 55.2% of compromised WordPress databases contained malicious admin users
- 49–70% of compromised sites had backdoors (many as fake plugins)
- The three most common post-compromise actions — admin user creation, plugin installation, settings modification — are all gated by Sudo

**Credential attacks** ([Verizon DBIR 2024–2025](https://www.verizon.com/business/resources/reports/dbir/)):

- 77–88% of basic web application attacks involved stolen credentials
- [Wordfence blocked over 55 billion password attacks in 2024](https://www.wordfence.com/blog/2025/04/2024-annual-wordpress-security-report-by-wordfence/)

**Access control** ([OWASP Top 10:2025](https://owasp.org/Top10/2025/A01_2025-Broken_Access_Control/)):

- Broken Access Control remains #1, found in 100% of tested applications

**Kill chain analysis:** XSS (47.7% of WP vulnerabilities) is primarily dangerous because it enables session hijacking → authenticated admin actions. Sudo blocks the downstream exploitation even when XSS succeeds.

### Risk reduction estimates

| Scenario | Estimate | Basis |
|---|---|---|
| Vulnerability classes with reduced exploitability | ~28% of discovered vulns; 80% of actual exploitation attempts | Patchstack 2024 discovery breakdown + 2025 RapidMitigate exploitation data |
| Post-compromise persistence blocked | 49–70% of compromises | Sucuri: backdoor plugins + admin user creation, all Sudo-gated |
| Session hijacking damage containment | Near-complete for gated actions | Attacker has session cookie but not password |
| Perimeter defense gap | WAF blocks 12–26% of WP-specific attacks | Patchstack 2025 WAF testing |

*Statistics verified 2026-02-27 against primary sources (Patchstack 2025 and 2026 whitepapers, Sucuri, Verizon DBIR, Wordfence, OWASP).*

**Scope note on the exploitation statistics.** The figures above describe vulnerability *categories* (Broken Access Control, Privilege Escalation, Broken Authentication, CSRF) and what share of exploitation attempts target them. WP Sudo is effective against the subset of those attacks that require triggering a covered operation on a surface WP Sudo intercepts — specifically the kill-chain step where an attacker with a valid session attempts a covered high-risk action. Exploits within those same categories that perform privileged state changes through plugin-specific ungated code paths, or that do not require triggering any covered operation, are outside WP Sudo's interception path and are not included in these estimates.

## What It Does Not Protect Against

- **Privilege escalation via uncovered plugin paths** — if a plugin performs a privileged state change (setting user roles, creating admin accounts, modifying critical options) through its own AJAX handler, custom REST endpoint, or direct WordPress function call without routing through a surface WP Sudo intercepts, the gate never fires. WP Sudo only blocks operations it has been positioned to see. A subscriber who exploits a broken plugin AJAX handler that directly calls `wp_set_role()` is entirely outside WP Sudo's interception path. **Partial mitigation (4.1.0):** an interactive effect-level backstop on `admin_init` hard-blocks the unambiguous *destructive* core effects — `delete_user`, `delete_plugin`, `delete_theme`, `activate_plugin`, `upgrader_pre_install`, `export_wp` — even when they are reached through a non-enumerated admin handler (e.g. a third-party `admin-post.php` route), so a no-sudo session cannot complete those specific actions through an uncovered path. The backstop is deliberately scoped to those effect hooks: option writes (`pre_update_option_*`) and role/user-creation hooks are excluded because WordPress core and legitimate plugins trigger them incidentally during ordinary admin loads, so blocking there would break non-gated workflows. **Escalation to administrator (4.1.0, opt-in, default OFF):** the separate role-aware admin-escalation guard (`wp_sudo_guard_escalation` filter) hooks the capabilities-meta write and `grant_super_admin` directly, so when enabled it blocks a *newly granted* administrator/super-admin on **any** surface — including custom REST routes, AJAX, and unauthenticated requests that are otherwise uncovered. It is intentionally narrow: it does **not** gate low-privilege role assignments or non-admin user creation, and it does **not** see administrator capabilities conferred at runtime through the `user_has_cap`/`map_meta_cap` filters or written directly with `$wpdb` (both bypass the meta hooks it watches). Low-privilege role changes and custom-capability mutation endpoints therefore remain uncovered by design.
- **Custom plugin capabilities, roles, and mutation endpoints** — operations gated by plugin-defined capabilities that mirror core WordPress capabilities are not automatically covered. WP Sudo gates the 35 built-in rules on known surfaces; it cannot auto-discover ungated surfaces in arbitrary plugin code. The `wp_sudo_gated_actions` filter can cover known plugin paths, but requires explicit integration per plugin.
- **Broken authorization in already-active sudo sessions** — active sudo is per browser session, not site-wide. Another user's active sudo session does not help an attacker somewhere else, but if a vulnerable plugin runs inside the *same* browser session after sudo has already been satisfied, WP Sudo usually will not prompt again for covered actions until the window expires. Correct capability checks can still block the action; missing or wrong capability checks remain the plugin's bug.
- **Direct database access** — an attacker with SQL access can modify data without triggering any WordPress hooks. WP Sudo cannot gate operations that bypass the WordPress API entirely.
- **File system access** — PHP scripts that load `wp-load.php` and call WordPress functions directly may bypass the gate if they don't trigger the standard hook sequence.
- **Other plugins that bypass hooks or covered paths** — if a plugin calls `activate_plugin()` in a way that suppresses `do_action('activate_plugin')`, exposes a custom AJAX/REST endpoint, or directly mutates roles, capabilities, or options through code paths WP Sudo does not intercept, the gate won't fire. The mu-plugin mitigates some early-loading races, but it cannot invent interception points for code it never sees. On multisite, the shared capability-key matcher recognizes blog-prefixed keys (e.g. `wp_5_capabilities`) via regex since 4.1.0, so cross-blog capability writes that flow through the user-meta hooks *are* seen by the non-interactive (CLI/Cron/XML-RPC) role guard and, when enabled, by the opt-in escalation guard; what still bypasses interception is a write that never triggers those hooks at all — most notably a direct `$wpdb` update to the usermeta table.
- **Server-level operations** — database migrations, WP-CLI commands run as root with direct PHP execution, or deployment scripts that modify files are outside WordPress's hook system.
- **Credential theft at login** — an attacker who knows the password and logs in through wp-login.php receives an automatic sudo session (see [Login Auto-Grant](#login-auto-grant)) and could in any case pass the password challenge at will. WP Sudo's reauthentication barrier is built against *session* theft, not *credential* theft; the residual walk-away and programmatic-login exposures of the auto-grant, and the `wp_sudo_grant_session_on_login` opt-out, are documented in that section.

### Why this boundary matters

WP Sudo is strongest against the attack pattern it was built for: an attacker has
an authenticated session but does **not** know the user's password or second
factor, and no active sudo window is already in place for that same browser
session. It is not a general repair for broken authorization in arbitrary plugin
code. If a vulnerable plugin performs a privileged state change through its own
ungated path, or does so inside an already-active sudo session, the underlying
authorization defect still determines the outcome.

## WPGraphQL Surface

WPGraphQL registers its endpoint via WordPress rewrite rules and dispatches requests at the `parse_request` hook — it does not use the WordPress REST API pipeline. WordPress's standard authentication still applies — cookies, nonces, and Application Passwords are valid. WP Sudo hooks into WPGraphQL's own `graphql_process_http_request` action, which fires after authentication but before body reading, regardless of how the endpoint is named or configured.

```
HTTP POST /graphql
        │
        ▼  parse_request (WPGraphQL Router)
        │
        ▼  graphql_process_http_request  ◄── WP Sudo intercepts here
        │  (after auth validation, before body read)
        │  Policy check:
        │    Disabled     → wp_send_json(sudo_disabled, 403) + exit
        │    Limited+mutation, no session → wp_send_json(sudo_blocked, 403) + exit
        │    otherwise    → pass through
        │
        ▼  new Request() — php://input read
        │
        ▼  execute_http() — GraphQL schema execution
        │
        ▼  graphql_process_http_request_response
        │
        ▼  HTTP Response
```

WP Sudo adds WPGraphQL as a fifth non-interactive surface with the same three-tier policy model (Disabled / Limited / Unrestricted) as WP-CLI, Cron, XML-RPC, and Application Passwords. The default is **Limited**.

**Mutation detection heuristic.** In Limited mode, WP Sudo first applies the `wp_sudo_wpgraphql_classification` filter, then decodes common JSON bodies, GET/form `query` params, and multipart `operations` GraphQL payloads and scans GraphQL document text for top-level `mutation` operation tokens. This covers standard inline mutations, JSON-escaped operation text, GET mutation attempts, file-upload multipart operations, and batched bodies where any item is a mutation. The fallback is still intentionally schema-independent: it does not parse the WPGraphQL schema or resolve operation hashes on its own, but it avoids the older raw-body substring behavior that could both miss encoded mutations and over-block queries that merely mention `mutation` in string arguments.

**Persisted queries.** When using WPGraphQL Persisted Queries (or APQ), the request often contains only a query hash/ID in the JSON body, GET `extensions` param, or multipart `operations` field. In Limited mode, unresolved persisted operations are treated as mutations by default so they fail safe. Use the `wp_sudo_wpgraphql_classification` filter to classify known persisted read operations as `query` when they should pass through without sudo. If all persisted requests should pass through, use the **Unrestricted** policy; if all GraphQL traffic should be blocked, use the **Disabled** policy.

**Scope.** WPGraphQL core exposes `deleteUser`, `updateUser`, `createUser`, and related mutations that map directly to gated operations. Third-party WPGraphQL extensions may add further mutations. The surface-level policy gates all mutations uniformly without requiring a schema-coupled rule set.

### WPGraphQL: Headless Authentication Boundary

The **Limited** policy has a constraint that does not apply to the other surfaces:
a sudo session can only be created from the WordPress admin interface, and it is
bound to the specific browser that completed the challenge.

For a mutation to pass through in Limited mode, two conditions must be met simultaneously:

1. **WordPress must identify the requesting user** — `get_current_user_id()` must return a non-zero value. This requires the request to carry valid WordPress authentication: a session cookie (browser-based admin access), an Application Password (`Authorization` header), or a JWT token if a JWT plugin is active.

2. **The sudo session cookie must be present** — the `_wp_sudo_token` cookie must accompany the request and match the token hash in user meta. This cookie is only set when the user completes a sudo challenge in the WordPress admin UI. Since 4.1.0 the proof is additionally bound to the WordPress login session that created it (`_wp_sudo_session_bind`): a captured cookie replayed from a different login session is rejected, the window ends on logout, and a bound proof stops verifying once its login session is no longer valid (e.g. after `WP_Session_Tokens::destroy_all()` the user is no longer authenticated, so the window is unreachable). Binding is enforced only when a bind value is present, so cookie-less surfaces and pre-4.1.0 sessions are unaffected and need no migration.

**Why this matters for headless deployments.** A frontend running at a different origin from the WordPress backend (e.g. a SvelteKit app at `localhost:5173` calling WordPress at `site.wp.local`) cannot automatically share the sudo session cookie. Cross-origin requests do not carry cookies unless CORS is configured with `Access-Control-Allow-Credentials: true` and a matching origin, and the frontend fetch uses `credentials: 'include'`. Without this, `get_current_user_id()` returns `0` and the sudo session cookie is absent — mutations are blocked by the Limited policy regardless of whether the frontend user is "logged in" from the application's perspective.

In practice, for most headless deployments, **Limited behaves identically to Disabled**: all mutations are blocked. The difference only becomes relevant when a user is simultaneously accessing the WordPress admin in the same browser with an active sudo session, and the frontend is configured to share credentials cross-origin.

**JWT authentication (wp-graphql-jwt-authentication).** The standard WPGraphQL JWT plugin hooks `determine_current_user` at priority 99, so `get_current_user_id()` returns the correct user ID for JWT-authenticated requests. However, JWT requests do not carry WordPress cookies, so the sudo session check always fails — authenticated JWT mutations are blocked in Limited mode. Worse, the JWT `login` mutation is sent by *unauthenticated* users (they are trying to obtain a token), so it is also blocked. **The default Limited policy breaks the JWT authentication flow entirely.** Use the `wp_sudo_wpgraphql_bypass` filter to exempt authentication mutations, or set the policy to Unrestricted. See the [developer reference](developer-reference.md#wp_sudo_wpgraphql_bypass-filter) for a bridge mu-plugin example.

**Recommended policy by deployment type:**

| Deployment | Recommended policy |
|---|---|
| Public-facing headless app (ratings, comments, contact forms) | Unrestricted |
| JWT-authenticated headless app (with bypass filter for auth mutations) | Limited + `wp_sudo_wpgraphql_bypass` filter |
| Internal admin tool with concurrent wp-admin access, same browser | Limited |
| Block all GraphQL mutations unconditionally | Disabled |

For headless deployments that need to gate mutations by authentication — require a WordPress user but not a full sudo session — the recommended approach is to use Application Password authentication on the GraphQL endpoint and set the global REST API (App Passwords) policy to Limited. Unauthenticated requests will still be blocked by the WPGraphQL Limited policy (since `get_current_user_id()` = 0), while authenticated app-password requests are governed by the REST API policy.

**Per-App-Password policy overrides.** The global REST (App Passwords) policy can be overridden per credential: each Application Password (identified by UUID, validated as UUID v4 and confirmed to exist before persisting) can carry its own Disabled / Limited / Unrestricted tier, and the override takes precedence over the global policy for requests authenticated with that credential. An Unrestricted override on one App Password therefore punches through a global Limited posture. Overrides are stored in the `wp_sudo_settings` option, are editable by users who can administer Sudo settings, and are removed automatically when the corresponding App Password is deleted.

## Environmental Considerations

- **Cookies** — sudo session tokens require secure httponly cookies. Reverse proxies that strip or rewrite `Set-Cookie` headers may break session binding. Ensure the proxy passes cookies through to PHP.
- **Object cache** — user meta reads go through `get_user_meta()`, which may be served from an object cache (Redis, Memcached). Standard WordPress cache invalidation handles this correctly, but custom or misconfigured cache setups can cause issues. See [Caching Considerations](#caching-considerations) for a full risk analysis.
- **Surface detection** — the gate relies on WordPress constants (`REST_REQUEST`, `DOING_CRON`, `WP_CLI`, `XMLRPC_REQUEST`) set by WordPress core before plugin code runs. These constants are stable across all standard WordPress hosting environments.
- **MU loader path resolution** — the loader resolves multiple basename/path candidates (configured basename, loader-derived basename, canonical fallback). If none resolve, it fails safely and emits `wp_sudo_mu_loader_unresolved_plugin_path` for diagnostics.

## Caching Considerations

WP Sudo stores state in three WordPress data layers — user meta, transients, and
cookies — all of which can be affected by caching systems. This section documents
the risks and mitigations for each caching layer.

### Object Cache (Redis, Memcached)

**What WP Sudo stores via user meta:**

| Meta key | Purpose | Written by | Read by |
|----------|---------|------------|---------|
| `_wp_sudo_token` | Hashed session token | `Sudo_Session::activate()` | `Sudo_Session::verify_token()` |
| `_wp_sudo_session_bind` | Hashed WordPress login-session token the sudo proof is bound to (4.1.0) | `Sudo_Session::activate()` | `Sudo_Session::verify_token()` |
| `_wp_sudo_expires` | Session expiry timestamp | `Sudo_Session::activate()` | `Sudo_Session::is_active()`, `is_within_grace()` |
| `_wp_sudo_failure_event` | Append-row failed auth event timestamps | `Sudo_Session::record_failed_attempt()` | `Sudo_Session::get_failed_attempts()`, `Sudo_Session::is_locked_out()` |
| `_wp_sudo_throttle_until` | Throttle expiry timestamp for non-blocking retry delay | `Sudo_Session::record_failed_attempt()` | `Sudo_Session::throttle_remaining()`, `Sudo_Session::attempt_activation()` |
| `_wp_sudo_lockout_until` | Lockout expiry timestamp | `Sudo_Session::record_failed_attempt()` | `Sudo_Session::is_locked_out()` |

All reads go through `get_user_meta()`, which checks the object cache before
querying the database. Writes go through `add_user_meta()` /
`update_user_meta()` / `delete_user_meta()`, which call `wp_cache_delete()` to
invalidate the cached value.

**Risk: Stale session state after revocation.** If a persistent object cache
returns a stale `_wp_sudo_token` or `_wp_sudo_expires` value after it has been
updated or deleted, a revoked sudo session could briefly appear active. This is
a **fail-open** condition — the gate would allow a gated action that should have
been blocked.

**Mitigations:**

- WordPress core's metadata API invalidates the object cache on every write. A
  properly configured persistent object cache (Redis, Memcached) is safe.
- The risk only materializes with misconfigured or custom cache setups that do not
  honor `wp_cache_delete()` calls — for example, a read-replica cache that has
  eventual consistency, or a cache plugin that batches invalidations.
- External cache flushes (Redis restart, Memcached eviction under memory pressure)
  remove the cached value entirely, causing a database read on the next request.
  This is a **fail-closed** condition (session data is re-fetched from the source
  of truth) and is not a security risk.

**Risk: Stale rate-limit state.** If append-row failure events
(`_wp_sudo_failure_event`) or lockout/throttle timestamps
(`_wp_sudo_lockout_until`, `_wp_sudo_throttle_until`) are served from stale
cache data, lockout and retry-delay behavior can be incorrect.

**Mitigations:**

- Same as session state — WordPress core invalidates the cache on write.
- Rate limiting is a defense-in-depth measure, not the primary security boundary.
  The password hash comparison is the critical check, and it is not cache-dependent.

### Page Cache (Varnish, nginx fastcgi_cache, Cloudflare, CDN)

**Risk: Cached admin pages or REST responses.** If a full-page cache caches
WordPress admin pages, the challenge interstitial, or REST/AJAX error responses,
users could:

- See a stale challenge page that no longer corresponds to their session state
- Receive a cached "sudo_required" error response after they have already
  reauthenticated
- Bypass gating entirely if the cache serves a previously-allowed response to a
  different user or session

**Mitigations:**

- WordPress core sets `Cache-Control: no-cache, must-revalidate, max-age=0` on
  all admin pages. Well-configured page caches respect this header.
- WordPress REST API responses include `Cache-Control: no-store` for authenticated
  requests. CDNs and reverse proxies should not cache these.
- WP Sudo does not add any custom cache headers — it relies on WordPress core's
  cache control, which is designed to prevent caching of authenticated responses.

**Known failure modes:**

- A Varnish or nginx configuration that ignores `Cache-Control` headers for
  logged-in users. This is a server misconfiguration, not a WP Sudo issue, but
  it can break sudo gating.
- CDNs configured to cache all responses from `/wp-json/` without checking auth
  headers. This would break all authenticated REST API functionality, not just
  WP Sudo.
- Aggressive "edge caching" plugins that cache full HTML responses for logged-in
  users. These are rare but exist (e.g., some configurations of WP Rocket,
  LiteSpeed Cache, or Cloudflare APO). WP Sudo cannot detect or prevent this.

**Recommendation:** If using a reverse proxy or CDN, verify that admin pages
(`/wp-admin/`), REST API responses (`/wp-json/`), and AJAX endpoints
(`/wp-admin/admin-ajax.php`) are excluded from full-page caching for
authenticated requests.

### Transients (Request Stash + IP Rate Limiting)

**What WP Sudo stores via transients:**

- `Request_Stash` saves the replay target (method and URL) plus only the
  matched rule's allowlisted POST fields. It does not store `$_GET`
  separately; GET replay uses the original URL. Passwords, tokens, API keys,
  and other configured or suffix-matched secret fields are omitted from the
  stash; when those fields were present, WP Sudo redirects the user back after
  reauthentication and asks them to re-enter the secret while the sudo session
  is active.
- `Sudo_Session` stores per-IP failed-attempt event buckets
  (`wp_sudo_ip_failure_event_{hash}`) and per-IP lockout timestamps
  (`wp_sudo_ip_lockout_until_{hash}`) for multidimensional rate limiting.
- `Gate` stores a short-lived blocked-action notice
  (`_wp_sudo_blocked_{user_id}`, 60 s TTL) holding only the matched rule ID
  and its static translated label — no request data — so the next admin page
  load can render an explanatory notice after an AJAX/REST block.

**Risk: Stash eviction before reauthentication completes.** With a persistent
object cache, transients are stored in the object cache rather than the database.
If the object cache evicts the stash entry (due to memory pressure, TTL
expiration, or cache flush) before the user completes the challenge, the original
request data is lost.

**Impact:** The user reauthenticates successfully but is redirected to the admin
dashboard instead of replaying their original action. They must repeat the
action manually. This is **annoying but not a security issue** — it fails safe
(no action is taken without authentication).

**Mitigations:**

- Transient TTL is set to 5 minutes, which is generous for a password challenge.
- Without a persistent object cache, transients fall back to the `wp_options`
  database table, which is not subject to memory-pressure eviction.
- The stash stores only the request metadata and rule-allowlisted POST fields
  needed for replay. Unsafe or unallowlisted POST bodies are not replayed
  automatically. Stashes are small (typically under 1 KB) and unlikely to be
  evicted by LRU policies.

**Risk: IP-rate-limit transient eviction or stale reads.** If per-IP failure
event/lockout transients are evicted early, the combined lockout policy can
under-enforce temporarily for that source IP.

**Impact:** This is a **low-severity fail-open** condition in a defense-in-depth
control. Password verification and user-bound lockouts still apply.

**Mitigations:**

- Per-user lockout state in user meta remains active even if IP transients are lost.
- IP lockout transients are time-boxed and rewritten on each lockout trigger.
- Deployments requiring stronger consistency should pair WP Sudo with upstream
  controls (WAF/rate limiting at edge or load balancer).

### Summary: Failure Modes by Cache Layer

| Cache layer | Failure mode | Direction | Security impact |
|-------------|-------------|-----------|-----------------|
| Object cache (stale write) | Revoked session appears active | Fail-open | **Medium** — gated action allowed without valid session |
| Object cache (eviction/flush) | Session data re-fetched from DB | Fail-closed | None |
| Object cache (stale rate limit) | Throttle/Lockout window not enforced | Fail-open | **Low** — defense-in-depth measure, not primary control |
| Page cache (cached admin/REST) | Stale responses served | Fail-open | **Medium** — depends on what is cached |
| Transient eviction | Request stash lost | Fail-closed | None — user must repeat action |
| Transient eviction/stale read (IP lockout) | Source-IP lockout may clear early | Fail-open | **Low** — user lockout + password checks still apply |

All fail-open conditions require a misconfigured cache. Standard WordPress hosting
with a properly configured persistent object cache and standard page cache
exclusions for `/wp-admin/` and `/wp-json/` does not trigger any of these risks.

## Session Binding

When sudo is activated, a cryptographic token is stored in a secure httponly cookie and its hash is saved in user meta. On every gated request, both must match. A stolen session cookie on a different browser will not have a valid sudo session.

## Login Auto-Grant

Every successful browser form login (the `wp_login` hook) automatically activates a full sudo session (since v2.6.0). The rationale: WP Sudo's challenge is password-based, and a user who just proved knowledge of the password would pass it trivially — so an immediate challenge adds friction without a barrier. The same logic bounds what the grant costs: an attacker who logs in with stolen credentials gains an immediate sudo window, but withholding the grant would not have stopped them, because they can pass the password challenge at will.

**Security properties and limits of the auto-grant:**

- **Second factor enforced by session binding.** WP Sudo's priority-10 grant runs on `wp_login` *before* the second factor is verified — the Two Factor plugin hooks the same hook at `PHP_INT_MAX` (verified against live source, `class-two-factor-core.php` line 123, 2026-06-09) — but that grant does not survive to become a password-only window. The grant binds to the login-session token (see [Session Binding](#session-binding)), and for an enrolled user the Two Factor plugin unconditionally destroys that session to challenge the factor: `Two_Factor_Core::wp_login()` calls `destroy_current_session_for_user()` then `wp_clear_auth_cookie()`, and re-mints with `wp_set_auth_cookie()` only after the factor validates (verified against live source). The re-minted session token no longer matches the grant's binding, so `verify_token()` rejects it and the user meets WP Sudo's own challenge, which enforces password + second factor. Net: an enrolled user is never left holding a password-strength sudo window from the auto-grant. (This assumes the second factor is actually exercised on the login; a 2FA plugin that authenticates the factor inline via the `authenticate` filter mints the grant *after* the factor instead, which is likewise fine.)
- **Walk-away exposure (password-only logins).** For a login whose session persists — a password login *not* completed with a session-rotating second factor — a user who logs in and steps away leaves up to a full session window (1–15 minutes, per the session-duration setting) of gated-action capability for whoever is at the keyboard, someone who could *not* have passed the challenge. (A Two Factor-enrolled login does not leave this window; the grant is invalidated per the previous point.) Sites where this matters can suppress the grant with the `wp_sudo_grant_session_on_login` filter (return `false`), requiring an explicit challenge at the first gated action.
- **Programmatic logins.** Any code that fires `do_action( 'wp_login', ... )` — SSO/SAML/OIDC plugins, custom login flows — triggers the grant. For passwordless SSO users this is what keeps gated actions reachable at all: a fresh identity-provider login is effectively their reauthentication, since they cannot pass a WordPress-password challenge. Conversely, sites that do not want programmatic logins to mint sudo sessions should suppress the grant via the filter — but only for users who retain a usable WordPress password, otherwise gated actions become unreachable for them. For those passwordless accounts the guarantee is correspondingly weaker: the sudo window opens at *login freshness*, not at the moment of the action. (This login-freshness downgrade is specific to passwordless accounts. A 2FA-enrolled user with a real password is instead re-challenged, because Session Binding invalidates the pre-2FA grant — see *Second factor enforced by session binding* above.) A site that needs a genuine at-the-moment step-up for administrators should not treat WP Sudo under passwordless SSO as providing one — and note that provisioning WordPress passwords for those admins is *not* sufficient by itself, because the login auto-grant still mints a window on every login. The remedy is to give those admins passwords **and** suppress the auto-grant for them via the `wp_sudo_grant_session_on_login` filter (so they meet the challenge at the first gated action), or to adopt the roadmapped IdP challenge-provider (see the [FAQ](FAQ.md)) — not to remove WP Sudo, which still gates the action, bounds the window, and fires audit hooks.
- **Non-interactive surfaces unaffected.** `wp_login` does not fire for Application Password or XML-RPC authentication, and those paths carry no session cookie, so the grant is scoped to browser logins.

See the [developer reference](developer-reference.md#filters) for the filter signature and the [FAQ](FAQ.md) for the SSO integration guidance.

## Grace Period

Since v2.6.0, sudo sessions have a 120-second grace window (`Sudo_Session::GRACE_SECONDS`) after they expire. If a user was filling in a form when the session expired, the gate calls `Sudo_Session::is_within_grace()` before redirecting to the challenge page.

**Security properties of the grace window:**

- **Token binding is enforced** — `is_within_grace()` calls `verify_token()` before returning `true`. The session cookie must still be present and match the stored hash. A browser without the original sudo cookie cannot gain grace access.
- **Grace applies to interactive surfaces only** — the admin UI, cookie-authenticated REST, and WPGraphQL gating points check grace. Because `is_within_grace()` requires the sudo token cookie, grace is structurally unreachable for cookie-less requests — App Password and bearer-token REST clients never receive a grace window. The admin bar timer does not check grace either — it reflects the true session state so the user sees accurately when their session has expired.
- **Meta cleanup is deferred** — `is_active()` does not delete the session meta while the grace window is open. This allows `is_within_grace()` to read the expiry timestamp and token. Cleanup runs when `time() > $expires + GRACE_SECONDS`.
- **Wind-down, not extension** — gated actions initiated during the grace period pass if the session token is still valid. The gate does not distinguish between "in-progress" and "new" actions — the window is deliberately short (120 s) to limit exposure. `is_active()` returns false during grace, the admin bar shows the session as expired, and no new session meta is written.

## 2FA Browser Binding

When the password step succeeds and 2FA is required, a one-time challenge cookie is set in the browser. The 2FA pending state is keyed by the hash of this cookie, not by user ID. An attacker who stole the WordPress session cookie but is on a different machine does not have the challenge cookie and cannot complete the 2FA step.

## Emerging Boundaries: Abilities API, AI Client, and Connectors (WP 7.0+)

*Added 2026-04-13. Full pre-GA analysis in [abilities-api-assessment.md](abilities-api-assessment.md);
use [release-status.md](release-status.md) and [ROADMAP.md](ROADMAP.md) for
current release posture and GA parity follow-up tasks.*

WordPress 7.0 introduces three new subsystems that interact with WP Sudo's trust
model in different ways. None require Gate changes today, but they establish new
boundaries that will become relevant as the Abilities API matures.

### PHP-path ability execution

The Abilities API provides `WP_Ability::execute()` — a direct PHP execution path
that bypasses REST, CLI, and all other surfaces the Gate currently intercepts.
Any plugin can call:

```php
wp_get_ability( 'namespace/ability-name' )->execute( $input );
```

This path runs `check_permissions()` (the ability's `permission_callback`), which
is a capability check — authorization, not reauthentication. The Gate does not
intercept it.

**Current risk: none from the evaluated core ability set.** The core abilities
reviewed for WP 7.0 were read-only. The PHP path is not a concern until a
destructive ability is registered.

**Future risk: medium.** The Abilities API is designed as a uniform execution
interface — plugins are expected to call it programmatically. When destructive
abilities appear, this path becomes a bypass route for any gated operation that
is also registered as an ability. Unlike `activate_plugin()` (an internal function
that plugins happen to call), abilities are an intentional public API for
cross-plugin invocation, making widespread use of the PHP path likely.

**Interception point:** `wp_before_execute_ability` fires before every ability
execution, including the PHP path. When destructive abilities are registered,
WP Sudo can hook this action to enforce reauthentication — regardless of which
surface initiated the call.

### External credential trust boundary

The **Connectors API** manages API keys for external AI providers (and potentially
other services) through a settings page at Settings > Connectors. This introduces
an external credential class whose consequences are outside WordPress itself, but
the write path is now explicitly in WP Sudo's threat model.

Today, WP Sudo protects WordPress-internal credentials and state: passwords,
session tokens, user roles, plugin activations. Connectors credentials are
*external* — compromising them has consequences that WordPress cannot contain:

| Attack | Impact | Containable by WordPress? |
|---|---|---|
| Redirect AI traffic to attacker endpoint | Prompt exfiltration (site content, user data, admin context) | No — data leaves the site |
| Replace API key with attacker's own | Billing fraud against the attacker's provider account | No — financial impact is off-site |
| Delete provider credentials | Denial of service for AI-dependent features | Yes — but damage is already done |

The Connectors settings page is covered by a built-in REST rule:
`connectors.update_credentials`. It challenges `POST` / `PUT` / `PATCH`
writes to `/wp/v2/settings` when the request body contains connector credential
setting names matching `connectors_*_api_key`. This mitigates the credential
replacement vector for database-backed connector keys, while leaving unrelated
REST settings writes untouched. The remaining follow-up is GA source/runtime
verification that core's released Connectors implementation still matches the
documented route and setting-name pattern. See
[release-status.md](release-status.md),
[abilities-api-assessment.md](abilities-api-assessment.md), and
[connectors-api-reference.md](connectors-api-reference.md).

### AI agent entry points

The WordPress MCP Adapter translates abilities into MCP tools for AI agents
(Claude, Cursor, etc.). MCP calls flow through existing surfaces:

- **HTTP transport** → REST API → `intercept_rest()` (covered)
- **STDIO transport** → WP-CLI → CLI policy (covered)

Authentication is per-request (Application Passwords or WP-CLI `--user`). There
is no persistent AI agent session concept in WP 7.0. Each tool call is an
independent authenticated request subject to the existing surface policies.

**If a persistent agent session concept is introduced in a future release** — a
long-lived token that can perform multiple operations without per-request
authentication — it would constitute a new trust boundary requiring its own
policy tier in WP Sudo, comparable to the existing CLI and Cron policies. The
pre-GA WP 7.0 review found no such core proposal; re-check this before future
agent-specific policy work.
