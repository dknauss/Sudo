# Security Model

WP Sudo is a **hook-based interception layer**. It operates within WordPress's plugin API — `admin_init`, `pre_option_*`, `activate_plugin`, REST `permission_callback`, etc. — and is subject to the same boundaries as any WordPress plugin.

### Terminology Note

WP Sudo uses the term **reauthentication** to describe its core pattern, following [NIST SP 800-63B §7.2](https://pages.nist.gov/800-63-4/sp800-63b.html#sessionreauthn): *"Periodic reauthentication of subscriber sessions SHALL be performed to confirm the subscriber's continued presence and intent to be authenticated."* Reauthentication describes the security pattern of requiring a user to prove their identity again during an existing session. The underlying mechanisms — password hash comparison (`wp_check_password`), TOTP code validation, etc. — are verification at the cryptographic level, but the overall pattern is reauthentication, not verification. When 2FA is enabled for WP Sudo, it is also enabled for the initial WordPress login, so the challenge operates at the same assurance level — this is pure reauthentication, not step-up authentication (which would imply a higher assurance level than the initial login).

## What It Protects Against

- **Compromised admin sessions** — a stolen session cookie cannot perform gated actions without reauthenticating. The sudo session is cryptographically bound to the browser.
- **Session theft → password change → lockout** — password changes on the profile/user-edit pages and via the REST API are a gated action (`user.change_password`). An attacker who steals a session cookie cannot silently change the victim's password without triggering the challenge.
- **Insider threats** — even legitimate administrators must prove their identity before destructive operations.
- **Automated abuse** — headless entry points (WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL) can be disabled entirely or restricted to non-gated operations.
- **2FA replay** — the two-factor challenge is bound to the originating browser via a one-time cookie, preventing cross-browser replay.
- **Capability tampering** — direct database modifications to restore `unfiltered_html` on the Editor role are detected and reversed at `init`.

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

## What It Does Not Protect Against

- **Direct database access** — an attacker with SQL access can modify data without triggering any WordPress hooks. WP Sudo cannot gate operations that bypass the WordPress API entirely.
- **File system access** — PHP scripts that load `wp-load.php` and call WordPress functions directly may bypass the gate if they don't trigger the standard hook sequence.
- **Other plugins that bypass hooks** — if a plugin calls `activate_plugin()` in a way that suppresses `do_action('activate_plugin')`, the gate won't fire. The mu-plugin mitigates this by loading the gate before other plugins.
- **Server-level operations** — database migrations, WP-CLI commands run as root with direct PHP execution, or deployment scripts that modify files are outside WordPress's hook system.

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

**Mutation detection heuristic.** In Limited mode, WP Sudo checks whether the POST body contains the word `mutation` unless a classifier override is provided. This remains a deliberately blunt default heuristic — it cannot false-negative on a standard inline GraphQL mutation, but it may false-positive on a query that mentions `mutation` in a string argument. The tradeoff is intentional: safe to over-block (for inline queries), and independent of WPGraphQL's schema.

**Persisted queries.** When using WPGraphQL Persisted Queries (or APQ), the request body often contains only a query hash/ID. Use the `wp_sudo_wpgraphql_classification` filter to classify those requests as `mutation` or `query`. Without classifier coverage, persisted mutations can appear as non-mutations under the default heuristic. If strict mutation blocking is required and classifier coverage is not feasible, use the **Disabled** policy.

**Scope.** WPGraphQL core exposes `deleteUser`, `updateUser`, `createUser`, and related mutations that map directly to gated operations. Third-party WPGraphQL extensions may add further mutations. The surface-level policy gates all mutations uniformly without requiring a schema-coupled rule set.

### WPGraphQL: Headless Authentication Boundary

The **Limited** policy has a constraint that does not apply to the other surfaces:
a sudo session can only be created from the WordPress admin interface, and it is
bound to the specific browser that completed the challenge.

For a mutation to pass through in Limited mode, two conditions must be met simultaneously:

1. **WordPress must identify the requesting user** — `get_current_user_id()` must return a non-zero value. This requires the request to carry valid WordPress authentication: a session cookie (browser-based admin access), an Application Password (`Authorization` header), or a JWT token if a JWT plugin is active.

2. **The sudo session cookie must be present** — the `_wp_sudo_token` cookie must accompany the request and match the token hash in user meta. This cookie is only set when the user completes a sudo challenge in the WordPress admin UI.

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

### Transients (Request Stash)

**What WP Sudo stores via transients:**

`Request_Stash` uses `set_transient()` to save the original HTTP request data
(method, URL, POST body) when redirecting to the challenge page. After successful
reauthentication, the stash is retrieved with `get_transient()` and the original
request is replayed.

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
- The stash stores only the request metadata needed for replay — it is small
  (typically under 1 KB) and unlikely to be evicted by LRU policies.

### Summary: Failure Modes by Cache Layer

| Cache layer | Failure mode | Direction | Security impact |
|-------------|-------------|-----------|-----------------|
| Object cache (stale write) | Revoked session appears active | Fail-open | **Medium** — gated action allowed without valid session |
| Object cache (eviction/flush) | Session data re-fetched from DB | Fail-closed | None |
| Object cache (stale rate limit) | Throttle/Lockout window not enforced | Fail-open | **Low** — defense-in-depth measure, not primary control |
| Page cache (cached admin/REST) | Stale responses served | Fail-open | **Medium** — depends on what is cached |
| Transient eviction | Request stash lost | Fail-closed | None — user must repeat action |

All fail-open conditions require a misconfigured cache. Standard WordPress hosting
with a properly configured persistent object cache and standard page cache
exclusions for `/wp-admin/` and `/wp-json/` does not trigger any of these risks.

## Session Binding

When sudo is activated, a cryptographic token is stored in a secure httponly cookie and its hash is saved in user meta. On every gated request, both must match. A stolen session cookie on a different browser will not have a valid sudo session.

## Grace Period

Since v2.6.0, sudo sessions have a 120-second grace window (`Sudo_Session::GRACE_SECONDS`) after they expire. If a user was filling in a form when the session expired, the gate calls `Sudo_Session::is_within_grace()` before redirecting to the challenge page.

**Security properties of the grace window:**

- **Token binding is enforced** — `is_within_grace()` calls `verify_token()` before returning `true`. The session cookie must still be present and match the stored hash. A browser without the original sudo cookie cannot gain grace access.
- **Grace applies to interactive surfaces only** — the admin UI, REST API, and WPGraphQL gating points check grace. The admin bar timer does not — it reflects the true session state so the user sees accurately when their session has expired.
- **Meta cleanup is deferred** — `is_active()` does not delete the session meta while the grace window is open. This allows `is_within_grace()` to read the expiry timestamp and token. Cleanup runs when `time() > $expires + GRACE_SECONDS`.
- **Wind-down, not extension** — gated actions initiated during the grace period pass if the session token is still valid. The gate does not distinguish between "in-progress" and "new" actions — the window is deliberately short (120 s) to limit exposure. `is_active()` returns false during grace, the admin bar shows the session as expired, and no new session meta is written.

## 2FA Browser Binding

When the password step succeeds and 2FA is required, a one-time challenge cookie is set in the browser. The 2FA pending state is keyed by the hash of this cookie, not by user ID. An attacker who stole the WordPress session cookie but is on a different machine does not have the challenge cookie and cannot complete the 2FA step.
