# WP Sudo Architecture & Security Assessment

## 1. Architectural Fragility: "Surface-Level" Interception
The most significant architectural flaw is WP Sudo's reliance on **Routing and Parameter Pattern Matching** for interactive requests, rather than hooking into the underlying execution functions.

* **The Problem:** In `Action_Registry.php`, the gating rules for Admin UI and REST requests rely on hardcoded HTTP methods, `pagenow` variables, and regex boundary matches (e.g., matching `update.php` and `action=install-plugin` or `/wp/v2/users/\d+$`). 
* **The Fragility:** WordPress is heavily migrating its traditional admin screens to React-based UI elements and distinct REST endpoints. If a core update changes a parameter name, shifts a capability to a different script, or alters a REST route, WP Sudo will silently fail to gate the action.
* **The Blindspot:** If a third-party plugin provides an alternative UI or custom REST endpoint for a sensitive action (e.g., an "Advanced User Manager" plugin with a custom AJAX action to delete users), WP Sudo will completely ignore it because its registry only recognizes native Core routes. 
* **The Contrast:** For CLI and Cron, the plugin hooks perfectly into deep underlying functions (e.g., `delete_plugin`, `wp_insert_user`). The lack of parity between interactive and non-interactive interception creates a massive attack surface for bypass via core evolution or third-party extensions.

## 2. Statefulness Flaws: Request Stashing and Replay
WP Sudo must capture, delay, and replay a request if a challenge is triggered. The implementation of `Request_Stash` has several critical limitations:

* **File Uploads Are Dropped:** The `Request_Stash` only serializes `$_GET` and `$_POST`. It completely ignores `$_FILES`. If a user's sudo session expires right as they submit a plugin `.zip` upload through the admin panel, the system will prompt them for a password, stash the POST data, and replay it—**without the file attached**. The operation will fail abruptly, creating a poor developer experience.
* **Transient Bloat (DoS Vector):** Every intercepted request creates an entry in `wp_options` (as a transient) with a 5-minute TTL. An unauthenticated or low-privilege attacker could programmatically spam a gated Admin-Ajax action or REST endpoint. Because `Request_Stash::save()` executes *before* rate-limiting takes effect, the attacker could easily flood the database with thousands of stale transients, degrading database performance and potentially causing a Denial of Service (DoS).
* **Verbatim Payload Storage:** The `Request_Stash->sanitize_params` explicitly bypasses sanitization (`return $params;`). This means WP Sudo is writing completely raw, actively malicious payloads (like XSS or SQLi strings from an attacker testing the gate) directly into the database. While standard transient reads are safe, storing active exploits in the database increases the risk radius if a secondary, unrelated plugin has an options table read vulnerability.

## 3. Concurrency and Rate Limiting Weaknesses
The system uses rate limiting to prevent brute-forcing the sudo password and 2FA prompts, but the implementation is susceptible to race conditions.

* **TOCTOU Race Condition:** In `Sudo_Session::record_failed_attempt()`, the system reads the current failed attempts from user meta, increments the integer in PHP, and saves it. Under high concurrency (e.g., an attacker firing 50 simultaneous asynchronous burst requests), the read/write operations will overlap. The attacker could successfully attempt potentially dozens of passwords before the counter synchronizes and triggers the 5-minute hard lockout.
* **Header Sending Edge Cases:** The session token heavily relies on setting `WP_Sudo_token` via standard PHP `setcookie()`. If any other active plugin is misconfigured—outputting whitespace or throwing a PHP Notice before WP Sudo executes—`headers_sent()` will equate to true. WP Sudo will correctly fail gracefully, but the user will be completely unable to acquire a session token on their browser, trapping them in an inescapable loop of valid passwords but immediately rejected sessions.

## 4. Code Quality and Performance Overheads
While generally performant, there are a few bottlenecks introduced by the security architecture:

* **Global Tamper Processing (`init` Canary):** In `Plugin::enforce_editor_unfiltered_html`, the codebase validates that the Editor role has not been granted `unfiltered_html`. This function fires on **every single page load** at `init` priority 1 (front-end visitor pages, RSS feeds, API calls). Polling `get_role()` and auditing capabilities globally for a tamper-detection canary is unnecessary bloat for 99% of requests. This should run only when users are updated or roles are modified.
* **Regex Engine Bound to the REST API:** The `Gate::intercept_rest()` method evaluates every single REST API request (`rest_request_before_callbacks`) against a `preg_match` loop spanning the entire action registry looking for route matches. As the site scales in traffic or the registry grows, this forces the server to spend CPU cycles running Regex on completely harmless, ungated read-only API requests (e.g., fetching posts for a headless frontend).

---

## 5. SWOT Analysis

### Strengths
- **Zero-Trust Approach:** Treats authenticated sessions as inherently vulnerable, mitigating session hijacking, device compromise, and XSS-to-RCE vectors.
- **TDD & High Quality Engineering:** Over 500 tests (unit/integration) and strict static analysis (PHPStan Level 6) ensures reliability, guards against LLM confabulation, and lowers regression risk.
- **Broad Attack Surface Coverage:** Protects not just the UI but headless entry points (REST, CLI, Application Passwords, XML-RPC, WPGraphQL).

### Weaknesses
- **Stash/Replay Limitations:** Dropping `$_FILES` and verbatim payload serialization into DB transients creates blindspots and potential secondary vulnerabilities.
- **Interactive Routing Intercepts:** Matching interactive requests based on HTTP routing (`pagenow` and `$_GET`) vs actual capability execution creates bypass vulnerabilities for heavily-customized setups or 3rd-party admin UI plugins.
- **Concurrency in Rate Limiting:** A classic TOCTOU race condition exists in tracking failed attempts.

### Opportunities
- **Client-Side Modal Reauth:** Adopting an inline UX flow (similar to GitHub or Silverstripe) bypasses the need for the flawed `Request_Stash` entirely and eliminates the dropped-file upload problem.
- **Enterprise Integrations:** Developing the planned WSAL sensor and IP+User rate limiting expands viability for enterprise/managed hosting markets.
- **Core Function Hooks for Interactive:** Migrating interactive UI interception deeper to intercept core operational functions (like CLI/Cron do) instead of request routing could completely eliminate bypass risks in custom interfaces.

### Threats
- **WordPress Core Architecture Shifts:** Accelerated moves to deep React interfaces (block editor, DataViews) can silently bypass URL-based action matching.
- **Abilities API Evolution:** While current read-only scopes in the WP 6.9+ Abilities API are covered by REST policies, future evolutions of headless capabilities may introduce new execution vectors that the current Gate struggles to comprehensively monitor.
- **DoS Vectors:** Programmatic spamming of gated targets could saturate the transient table (due to stash generation on failed attempts) prior to the IP/User rate-limiting effectively blocking the actor.

---

## 6. Strategic Alignment & Roadmap Review

### Is Anything Missing?
The current `ROADMAP.md` discusses the **Client-side modal challenge** as a "Feature to consider" due to its UX improvements, but misses the fact that it is fundamentally a **security and reliability fix** for the `Request_Stash` (which drops file uploads and creates DoS/payload-storage risks). 
Additionally, the roadmap does not address the architectural dissonance between how non-interactive requests are brilliantly blocked via fail-safe core hooks (e.g. `delete_plugin`) while interactive requests rely on brittle surface routing checks.

### Should Anything Change?
1. The roadmap should elevate the **Client-Side Modal Challenge** from a "Later (v2.9+) - Deferred" feature to a critical, high-priority milestone because it deprecates the `Request_Stash` risk entirely.
2. The roadmap needs an immediate initiative to **Redesign Interactive Interception**. Relying on `$_REQUEST['action']` and `$pagenow` is an unsustainable pattern in the Gutenberg era.

### What Should Be Prioritized?
1. **Fixing the TOCTOU Race Condition:** The brute-force protection must be fortified to prevent rapid, concurrent request bursts from circumventing the 5-attempt limit. 
2. **Mitigating Transient Bloat / DoS:** Implement a strict limit on stash writes per user, or move transient generation to trigger *only* after a valid authentication flow is initiated, not purely on interception.
3. **Optimizing the init Canary:** Shift the `unfiltered_html` check to execute only during user-save or role-update hooks instead of running universally on every page load.
4. **Client-Side Modal Challenge & Deep Intercepting:** Prioritize architecture rewrites over secondary backlog items (like Dashboard widgets and arbitrary CLI management commands).

### Most Important Next Steps
1. **Immediate Code Fixes:** 
   - Refactor `Sudo_Session::record_failed_attempt` to handle race conditions (e.g., non-blocking timestamp arrays or leveraging more atomic database constraints).
   - Add a cap on `Request_Stash`-spawned transients to deter table flooding.
   - Restrict `Plugin::enforce_editor_unfiltered_html()` processing overhead.
2. **Roadmap Reprioritization:**
   - Classify "Client-side modal challenge" as a high-priority architectural replacement.
   - Introduce an explicitly planned phase: "Migrate Admin UI interception to function-level hooks."
