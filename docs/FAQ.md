# Frequently Asked Questions

## What problem does Sudo solve?

**The primary threat WP Sudo is built for:** an attacker has a valid authenticated admin session — stolen cookie, unattended browser, hijacked session via XSS — but does not know the account password or second factor, and no active sudo window is already open. On that threat, WP Sudo is highly effective: the attacker cannot perform any covered high-risk operation without completing the reauthentication challenge.

Beyond that core scenario, Sudo can limit or block damage from a broader class of threats: Broken Access Control (BAC), Broken Authentication, Privilege Escalation, and CSRF vulnerabilities — **when those exploits must trigger a covered operation on a surface WP Sudo intercepts.** That condition matters. If an exploit runs through a plugin's own ungated code path, or calls privileged WordPress functions directly without routing through a surface WP Sudo can see, the gate does not fire. Sudo is not a generic repair for broken authorization in plugin code.

Sudo also defends against scenarios where a device is stolen or left unattended, and against automated non-interactive access (WP-CLI, REST, XML-RPC, WPGraphQL) being used to perform destructive operations without operator intent. These types of threats are becoming increasingly common and a leading source of breaches across all platforms.

Broken Access Control is the #1 web application vulnerability today. OWASP ranked it #1 in its 2025 Top 10 list after finding this type of vulnerability in 100% of its application test samples ([OWASP 2025](https://owasp.org/Top10/2025/A01_2025-Broken_Access_Control/)). In the WordPress ecosystem, Broken Access Control was the second-largest category of new vulnerabilities in 2024, after XSS ([Patchstack 2024](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2025/)). In 2025, Broken Access Control vulnerabilities became the target of 57% of all attacks on WordPress sites ([Patchstack 2025](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/#-broken-access-control-was-the-most-exploited-vulnerability)). This surge is most likely the result of threat actors shifting away from XSS and concentrating their efforts on the types of attacks that firewalls are least likely to identify or predict, even with machine learning. As Patchstack notes:

> [Broken Access Control] vulnerabilities are very difficult to defend against using traditional WAFs because the exploits look like normal authenticated traffic with no obvious injection patterns.
>
> Add CSRF, Privilege Escalation, and Broken Authentication — all access control failures — and you're looking at 28% of all WordPress vulnerabilities catalogued in 2024. (Patchstack, [State of WordPress Security in 2026](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/#-broken-access-control-was-the-most-exploited-vulnerability))

Verizon's 2025 Data Breach Investigations Report finds that 77–88% of basic web application attacks begin with stolen credentials. Sucuri's forensics show that 55% of hacked WordPress databases contain malicious admin accounts created after the breach. The attacker doesn't need to find a new exploit — they need an active session, and they need WordPress to do what attackers assume it will always do: obey without challenge.

Sudo breaks that assumption — a key link in the attackers' kill chain. On covered consequential actions, a stolen session cookie by itself is not enough when the exploited browser session does not already have an active sudo window. An unattended browser with no active sudo window is not enough either. But Sudo is not a universal repair for broken authorization: if an exploit runs inside an already-active sudo session, or bypasses the covered WordPress paths entirely, the underlying bug still matters.

## Will Sudo protect my site from attacks on plugin vulnerabilities?

Sudo can neutralize attacks or severely limit their blast radius (the scope of the harm they can do) when the exploit must cross a covered consequential action and the attacker does not already control an active sudo session in that same browser/session.

Sudo is *not* intended as a replacement for diligent plugin selection, timely updates, and effective firewalling; instead, it complements and backstops those layers of defense. 

When the firewall misses an exploit and a vulnerable plugin still needs WordPress to perform a covered high-impact action, *Sudo can still be the gate between access and damage*. But it only protects the paths it actually intercepts. If the plugin omits capability checks, exposes its own ungated mutation endpoint, directly mutates privileged state, or the exploit runs inside an already-active sudo session, WP Sudo may not stop it.

## Can WP Sudo stop a privilege escalation attack?

It depends entirely on where the exploit path goes.

**Yes, if the attack crosses a covered surface.** An attacker who exploits a plugin vulnerability to gain a session and then tries to perform a covered high-risk operation — activate a plugin, create an admin user, change a role — through the standard admin UI, AJAX, or REST path that WP Sudo intercepts will hit the gate. The attacker has no sudo session, so the operation is blocked regardless of how they obtained their session. This is the core kill-chain interception: even with a valid session, covered destructive actions require credential proof.

**Yes, even through the plugin's own path — if you enable the admin-escalation guard (4.1.0, opt-in, default OFF).** Turning on the `wp_sudo_guard_escalation` filter makes WP Sudo hook the *effect* of an administrator grant (the `{prefix}capabilities` user-meta write and `grant_super_admin`) rather than a surface, so it blocks a **newly granted** `administrator` (single-site) or super-admin (multisite) on *any* path — a plugin's own AJAX/REST handler, or even an unauthenticated request — unless the actor **both** holds the promoting authority (`promote_users` on the target blog, or existing super-admin status) **and** has an active sudo session. Sudo is reauthentication, not authorization — a low-privilege account can hold a sudo session — so the authority check is what neutralizes the most common privilege-escalation shape (an under-privileged or unauthenticated actor lacks it), while the session requirement blocks a stolen-cookie replay, even when the vulnerable plugin's own permission check is broken. It is off by default and intentionally narrow: it fires only for *new* administrator/super-admin grants (never low-privilege roles, demotions, or idempotent re-grants), it defers on CLI/Cron/XML-RPC and on Unrestricted REST Application-Password surfaces, and it **cannot** see administrator capabilities conferred at runtime via the `user_has_cap`/`map_meta_cap` filters or written directly with `$wpdb`. Enable it with `add_filter( 'wp_sudo_guard_escalation', '__return_true' )`; allowlist trusted provisioners with `wp_sudo_allow_escalation` or the `WP_SUDO_ALLOW_ESCALATION` constant.

**Otherwise, no, if the exploit runs through the plugin's own path.** Apart from that opt-in administrator/super-admin guard, if the vulnerable plugin calls `wp_set_role()` (to a non-admin role), `wp_insert_user()`, or similar WordPress functions from inside its own AJAX handler or REST endpoint — without routing through a standard surface WP Sudo has been positioned to intercept — the gate never fires. WP Sudo cannot invent interception points for code it does not see.

**No, if the attack runs inside an already-active sudo session.** If the exploited browser session already has an active sudo window, WP Sudo will generally not re-challenge until that window expires. The outcome at that point depends entirely on the underlying authorization logic in the exploited code. See the next question.

The practical implication: WP Sudo raises the cost of exploiting a compromised session, but it is not a substitute for fixing broken access controls in plugin code.

## What about plugins that define their own capabilities, roles, or mutation endpoints?

WP Sudo only gates the specific operations it knows about — the 35 built-in rules covering standard WordPress admin, AJAX, and REST surfaces. If a plugin defines its own `my_plugin_delete_user` AJAX action that directly performs user deletion, registers a custom REST endpoint that sets user roles, or operates through a capability like `custom_manage_users` rather than WordPress core's `edit_users`, WP Sudo knows nothing about those paths.

There is no automatic coverage for plugin-invented surfaces. The `wp_sudo_gated_actions` filter lets operators and developers add custom rules for known plugin paths, but that requires explicit integration per plugin. WP Sudo is hook-based, not schema-based — it gates known WordPress API surfaces; it cannot discover ungated surfaces it has not been explicitly told about.

## What if Sudo is already active when a broken access control bug is exploited?

Active sudo is per browser session, not site-wide. Another administrator's active sudo session does not help an attacker in a different browser or on a different machine.

If the exploit runs inside the **same** browser session that already has an active sudo window, WP Sudo usually will not prompt again for covered actions until that window expires. At that point the outcome depends on the underlying authorization logic: correct capability checks can still block the action, but broken authorization in the vulnerable plugin may not. WP Sudo is strongest when an attacker has a stolen session but not the password or second factor, and no active sudo window is already in place.

## How is Sudo different from WordPress security plugins?

No security plugin gates actions that authenticated users can take. Most WordPress security plugins entirely neglect internal user-level security and do not make it a matter of governable policy. 

Why this matters: Any authenticated user session is an attack surface. Attackers can acquire an authenticated user session in many ways:

* A broken access control vulnerability that can be exploited to create a rogue user account.
* A stolen session cookie lets an attacker take over your session from another browser without knowing your password.
* An unattended machine with an active admin session leaves gated operations open to anyone with physical access.
* Open APIs allow authenticated and unauthenticated remote users and automated systems to probe them, connect, and potentially take damaging actions.

Conventional security plugins attempt to compensate for the limitations of mass-market hosting and plugins. Often, a security plugin will add layers of protection at the application level — rate-limiting and firewalling aimed at deterring malicious requests across some (typically under-defined) portion of the exposed application surface. This can be resource-intensive work that is better handled at the server, network, or infrastructure layer. Rapid mitigation through virtual patching based on the latest threat intelligence about vulnerable code is extremely valuable. If that layer is missing or fails, Sudo is the final layer of protection. Post-breach malware scanning — the signature and purely performative feature of the worst security plugins — is not a security layer. It is detection after the fact — not defense. Years of mounting evidence show how malware targets and defeats these scanners after a breach. 

Sudo doesn't operate at the perimeter but at the final point of consequence. It applies policy across every supported entry surface — admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, and WPGraphQL — and within those surfaces gates the covered destructive actions that matter: plugin installation, user creation, role changes, settings modifications, theme switching, connector credential replacement, and core updates. The shape and extent of your site's attack surface becomes a deliberate policy decision. Close a surface entirely, limit it to non-destructive operations, or leave it open — per surface, per application password, per action.

This is your site's innermost armor — the skin-tight layer that interposes reauthentication at the moment of consequence, after every other defense has had its turn. There is no comparable WordPress plugin. This is not access control — it is action control. For a detailed feature-by-feature comparison with other reauthentication approaches, see the [Architecture Comparison Matrix](sudo-architecture-comparison-matrix.md).

**Why this matters by the numbers.** Of the 7,966 WordPress vulnerabilities catalogued in 2024 ([Patchstack](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2025/)), ~28% fall into classes Sudo directly mitigates (Broken Access Control, CSRF, Privilege Escalation, Broken Authentication). When XSS exploitation chains are included, the figure rises to 55–65%. (XSS currently accounts for 47.7% of all WordPress vulnerabilities and is primarily dangerous because it enables session hijacking → admin actions.) Post-compromise, [Sucuri found](https://sucuri.net/reports/2023-hacked-website-report/) that 55% of hacked WordPress databases contained malicious admin users and 49–70% had backdoor plugins — both actions that Sudo gates. In 2025, the total rose 42% to 11,334 ([Patchstack](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/)), with highly exploitable vulnerabilities up 113% and traditional WAFs blocking only 12–26% of WordPress-specific attacks. When measured by actual exploitation attempts rather than discovery counts, Sudo-mitigated vulnerability classes account for 80% of real-world WordPress attacks (Patchstack 2025 RapidMitigate data) — Broken Access Control alone represents 57%. See the [Security Model](security-model.md#threat-model-the-kill-chain) for the full threat model and risk reduction estimates.

## Does Sudo complement or compete with WordPress security plugins?

Sudo is complementary to any other security layers you put in place. It doesn't compete with your WAF, for instance — it's the defense that matters when the WAF fails. Plugins that help you authenticate and manage users or software updates, or identify vulnerabilities in code and user accounts, are completely complementary. Two-factor authentication is critically important, and Sudo is specifically designed to work with the WordPress community standard solution for 2FA.

## What are Sudo's limitations?

WP Sudo does not protect against an attacker who already knows your WordPress password and second factor — someone with your full credentials can complete the challenge just as you can. Using 2FA is strongly recommended.

Beyond that, WP Sudo's protection is bounded to the surfaces and operations it explicitly intercepts:

- **Plugin vulnerabilities that don't cross a covered surface** — if a broken plugin performs a privileged state change through its own code path (custom AJAX handler, custom REST endpoint, direct `wp_update_user()` call), WP Sudo does not see it. See *Can WP Sudo stop a privilege escalation attack?* above.
- **Custom plugin capabilities and roles** — operations protected by plugin-defined capabilities that parallel WordPress core ones are not automatically gated. Explicit custom rules via `wp_sudo_gated_actions` are required.
- **Direct database access** — SQL writes bypass all WordPress hooks.
- **File system operations** — PHP scripts that bypass the standard hook sequence.
- **Attacks inside an already-active sudo session** — WP Sudo will not re-challenge a same-browser session until the window expires; a vulnerable plugin running inside that window is governed by its own authorization logic.

See the [Security Model](security-model.md) for the full account of boundaries, failure modes, and caching considerations.

Also, there is no substitute for a first-class, security-hardened server and application environment. Learn what this means so you can deploy secure sites yourself or simply become a savvier hosting consumer:

* [WordPress Security Hardening Guide](https://github.com/dknauss/wp-security-hardening-guide) (Accessible to relatively non-technical readers.)
* [WordPress Security Benchmark](https://github.com/dknauss/wp-security-benchmark) (Patterned after CIS Benchmarks — a pragmatic technical reference for key security decisions and tradeoffs when you stand up a WordPress server.)

## How does sudo gating work?

When a user attempts a gated action — for example, activating a plugin — Sudo intercepts the request before WordPress processes the sensitive operation. In many common browser-admin flows this happens on `admin_init`; other actions use surface-specific hooks. The original request is stashed in a transient, the user is redirected to a challenge page, and after successful reauthentication, the original request is replayed. Passwords, tokens, API keys, and other configured secret fields are omitted from the stash; if one was present, Sudo activates the session and redirects the user back to re-enter the secret instead of replaying a partial POST. For AJAX and REST requests, the browser receives a `sudo_required` error, and an admin notice appears on the next page load linking to the challenge page. The user authenticates, activates a sudo session, and retries the action.

## How can I tell why a request was gated or blocked?

Use the **Request / Rule Tester** on **Settings → Sudo**. It lets you enter a
representative admin, AJAX, or REST request shape and see:

- which rule would match
- whether the request would be allowed, gated, soft-blocked, or hard-blocked
- whether stash/replay would apply
- notes about missing authentication or REST surface policy

The tester is diagnostic only. It does not execute the request, stash it, or
change live gate state.

## Who can access Sudo settings?

Users with the `manage_wp_sudo` capability. On a new install, the activating admin receives all four governance capabilities automatically. Other administrators can be granted access from **Settings → Sudo → Access**.

Governance is always strict since 4.0.0: access requires the `manage_wp_sudo` capability explicitly, with no `manage_options` fallback. (The `compatibility` governance mode and the `wp_sudo_governance_mode` option were removed in 4.0.0.) On multisite, super admins are always treated as holding the governance capabilities via the `is_super_admin()` short-circuit in `wp_sudo_can()`.

## What are the four governance capabilities?

- `manage_wp_sudo` — access Sudo settings, configure policy, manage capability grants.
- `view_wp_sudo_activity` — view the dashboard widget and activity screens.
- `export_wp_sudo_activity` — download activity exports (CSV/JSON).
- `revoke_wp_sudo_sessions` — force-revoke another user's active sudo session.

Super admins on multisite are always treated as holding all four capabilities regardless of explicit grants.

## What is the last-manager guard?

When revoking `manage_wp_sudo` from a user, WP Sudo checks whether that user is the **sole remaining holder** of the capability. If they are, the revoke is blocked with a 409 error to prevent lockout.

If you need to proceed despite the guard — for example, after a key person leaves — use break-glass recovery mode: add `define('WP_SUDO_RECOVERY_MODE', true);` to `wp-config.php`. See the next FAQ entry.

## What is break-glass recovery mode, and when should I use it?

Break-glass recovery mode is an emergency escape hatch for the "last manager locked out" scenario. It is activated by adding the following line to `wp-config.php`:

This is WP Sudo's break-glass governance recovery path, not WordPress core's `WP_Recovery_Mode`.

```php
define( 'WP_SUDO_RECOVERY_MODE', true );
```

When active:
- The current user gains effective `manage_wp_sudo` access **only if they also hold site/network admin authority** — `manage_options` on single-site, `manage_network_options` on multisite. The break-glass recovery check is role-gated: a locked-out manager who kept their administrator role recovers, but subscribers, editors, and other non-admins gain nothing. (Multisite super admins always pass regardless.)
- A **permanent, non-dismissible warning notice** is shown on the Sudo settings screen while break-glass recovery mode is active, and the `wp_sudo_recovery_mode_active` audit hook fires (recorded as a sampled `recovery_mode` event) so the usage is visible to logging tools.

Break-glass recovery mode **does not** bypass the reauthentication challenge itself. A user using break-glass recovery mode must still complete the sudo challenge on gated actions — they just regain access to the Sudo settings and Access tab.

Defining the constant requires `wp-config.php` write access, so the practical risk is operator error rather than remote escalation. The role gate contains the blast radius, but while the constant is set **every administrator** (every `manage_options` holder) regains full Sudo governance — and can self-grant the other capabilities and change gating policy from the Access tab. **Remove the constant the moment normal access is restored.**

One limitation: because the gate requires an admin capability, break-glass recovery mode does **not** rescue a Sudo manager who was deliberately granted `manage_wp_sudo` *without* a WordPress admin role. Recover such a user another way — for example `wp user add-cap <user> manage_wp_sudo` via WP-CLI, or temporarily grant them `manage_options`.

## Does this replace WordPress roles and capabilities?

No. Sudo verifies that the current user is still the account holder; WordPress still decides whether that user is allowed to perform the action. A user who does not have the `activate_plugins` capability will still be denied — Sudo does not grant any new permissions.

## Which operations are gated?

| Category | Operations |
|---|---|
| **Plugins** | Activate, deactivate, delete, install, update |
| **Themes** | Switch, delete, install, update |
| **Users** | Delete, change role, change password, create new user, create application password |
| **File editors** | Plugin editor, theme editor |
| **Critical options** | `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register` |
| **Connector credentials** | Settings > Connectors API key updates saved through the REST settings endpoint |
| **WordPress core** | Update, reinstall |
| **Site data export** | WXR export |
| **WP Sudo settings** | Self-protected — settings changes require reauthentication |
| **Multisite** | Network theme enable/disable, site delete/deactivate/archive/spam, super admin grant/revoke, network settings |

Sudo's settings page includes a read-only Gated Actions table showing registered rules and their covered surfaces: Admin, AJAX, REST, and GraphQL if it's installed and active, plus the built-in non-interactive hook coverage for core WP Sudo rules.

Note: the surfaces shown reflect WordPress's actual API coverage — not all operations have REST endpoints. Custom rules added via the `wp_sudo_gated_actions` filter protect the admin, AJAX, and/or REST surfaces they define. Application Password requests are covered when the custom rule defines REST criteria. WP-CLI, Cron, and XML-RPC Limited mode use WP Sudo's built-in function-hook coverage for core rules; custom non-interactive workflows need an explicit integration or a stricter surface policy.

## What about REST API and Application Passwords?

Cookie-authenticated REST requests (from the block editor, admin AJAX) receive a `sudo_required` error. An admin notice on the next page load links to the challenge page where the user can authenticate and activate a sudo session, then retry the action. Application Password and bearer-token REST requests are governed by a separate policy setting with three modes: **Disabled** (returns `sudo_disabled`), **Limited** (default — returns `sudo_blocked`), and **Unrestricted** (passes through with no checks). Individual application passwords can override the global policy from the user profile page — for example, a deployment pipeline password can be **Unrestricted** while an AI assistant password stays **Limited**. For guidance on configuring WP Sudo for AI agents and automated tools, see [AI & Agentic Tool Integration](ai-agentic-guidance.md).

## What about WP-CLI, Cron, and XML-RPC?

Each has its own three-tier policy setting: **Disabled**, **Limited** (default), or **Unrestricted**. In Limited mode, gated actions are blocked and logged via audit hooks while non-gated commands work normally. When CLI is Limited or Unrestricted, `wp cron` subcommands still respect the Cron policy — if Cron is Disabled, those commands are blocked even when CLI allows other operations.

## What about WPGraphQL?

When the [WPGraphQL](https://wordpress.org/plugins/wp-graphql/) plugin is active, WP Sudo adds its own **WPGraphQL** policy setting with the same three modes: Disabled, Limited (default), and Unrestricted. WPGraphQL gating works at the surface level rather than per-action: in Limited mode, all mutations require an active sudo session while read-only queries always pass through. In Disabled mode, all requests to the endpoint are rejected. WP Sudo detects mutations by inspecting the request body for a `mutation` operation type. WPGraphQL handles its own URL routing, so gating works regardless of how the endpoint is configured.

## Why does WPGraphQL's gating block all mutations rather than specific ones?

WP Sudo's action registry rules are tied to **WordPress action hooks** — `activate_plugin`, `delete_user`, `wp_update_options`, and so on. These hooks fire regardless of entry surface, which is how the same rules cover the admin UI, AJAX, and REST simultaneously.

WPGraphQL mutations do not reliably fire those same hooks. WPGraphQL dispatches through its own resolver chain, and whether a mutation eventually triggers a WordPress hook depends entirely on how each resolver is implemented. There is no guaranteed 1:1 mapping from "mutation name" to "WordPress action hook" across the full WPGraphQL ecosystem (core resolvers, WooCommerce, custom extensions).

Per-action gating would require either parsing GraphQL request bodies to extract operation names and maintaining a mutation→hook mapping, or a new WPGraphQL-specific rule type separate from the hook-based registry. Both carry significant ongoing maintenance costs for the plugins and custom mutations that WPGraphQL-based sites rely on.

The surface-level approach — blocking any request whose body resolves to a GraphQL `mutation` operation in Limited mode — is reliable and appropriate for the primary use case: headless deployments where mutations come from automated API clients rather than interactive admin users. For mutations that should not require a sudo session (content mutations, authentication handshakes, etc.), the `wp_sudo_wpgraphql_bypass` filter provides precise per-mutation control without modifying the global policy.
FYI: In GraphQL, a "mutation" is a type of operation used to modify server-side data, causing side effects on the back end. While queries are used for fetching data, mutations are specifically designed for creating, updating, or deleting data. (This is similar to `POST`, `PUT`, `PATCH`, or `DELETE` in `REST`.) 

## Does WP Sudo work with WPGraphQL JWT Authentication?

The [wp-graphql-jwt-authentication](https://github.com/wp-graphql/wp-graphql-jwt-authentication) plugin is the standard way to authenticate WPGraphQL requests using JSON Web Tokens. With WP Sudo's default **Limited** policy, two issues arise: (1) the JWT `login` mutation is sent by unauthenticated users who cannot have a sudo session, so it is blocked; (2) JWT-authenticated mutations fail because JWT requests do not carry the browser-bound sudo session cookie. The result is that Limited mode breaks the JWT authentication flow entirely.

**Solution:** Use the `wp_sudo_wpgraphql_bypass` filter (added in v2.7.0) to exempt authentication mutations. Add this to a mu-plugin:

```php
add_filter( 'wp_sudo_wpgraphql_bypass', function ( bool $bypass, string $body ): bool {
    if ( $bypass ) {
        return $bypass;
    }
    if ( str_contains( $body, 'login' ) || str_contains( $body, 'refreshJwtAuthToken' ) ) {
        return true;
    }
    return false;
}, 10, 2 );
```

This exempts only the `login` and `refreshJwtAuthToken` mutations — all other mutations remain gated. Alternatively, set the policy to **Unrestricted** if you do not need mutation-level gating. See the [developer reference](developer-reference.md#wp_sudo_wpgraphql_bypass-filter) for full details.

## What about the WordPress Abilities API?

The [Abilities API](https://developer.wordpress.org/apis/abilities-api/) (introduced in WordPress 6.9) registers its own REST namespace at `/wp-abilities/v1/`. It uses standard WordPress REST authentication, so Application Password–authenticated requests are governed by WP Sudo's **REST API (App Passwords)** policy — no special configuration is needed. In Disabled mode, all Abilities API requests via Application Passwords are blocked. In Limited mode, ability reads, and standard executions pass through as non-gated operations; site owners who want to require sudo for specific destructive ability executions can add custom rules via the `wp_sudo_gated_actions` filter.

## How does session binding work?

When sudo is activated, a cryptographic token is stored in a secure httponly cookie, and its hash is saved in user meta. On every gated request, both must match. A stolen session cookie on a different browser will not have a valid sudo session. See [Security Model](security-model.md) for full details.

## How does 2FA browser binding work?

When the password step succeeds, and 2FA is required, a one-time challenge cookie is set in the browser. The 2FA pending state is keyed by the hash of this cookie, not by user ID. An attacker who stole the WordPress session cookie but is on a different machine does not have the challenge cookie and cannot complete the 2FA step. See [Security Model](security-model.md) for full details.

## Is there brute-force protection?

Yes. After 5 failed password attempts on the reauthentication form, the user is locked out for 5 minutes. Lockout events fire the `wp_sudo_lockout` action hook for audit logging.

## How do I log sudo activity?

Install [WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/) or [Stream](https://wordpress.org/plugins/stream/). Sudo fires action hooks covering session lifecycle, gated actions, policy decisions, preset application, lockouts, and tamper detection. See [Developer Reference](developer-reference.md) for hook signatures and [current-metrics.md](current-metrics.md) for the live count.

## How long are dashboard events retained, and when are they purged?

WP Sudo keeps dashboard event rows for **14 days** by default. A daily WP-Cron task (`wp_sudo_prune_events`) removes older rows. Pruning runs in bounded batches (`Event_Store::PRUNE_BATCH_SIZE`, currently 1000 rows per batch) to avoid long table locks on busy sites.

## Why does the "Sudo Active" count on the Users screen sometimes lag?

The active-session count badge on the Users list and the active-sessions panel in the dashboard widget are backed by a 30-second per-site transient cache. After a user's sudo session activates or expires, the displayed count can be up to 30 seconds stale before the next rebuild. This is deliberate — the underlying `WP_User_Query` with a `_wp_sudo_expires` meta query would otherwise run on every admin page load, which becomes expensive on sites with many users. Gate enforcement itself is never cached: every gated action re-reads the live session state.

## Does it support two-factor authentication?

Yes. If the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin is installed and the user has 2FA enabled, the sudo challenge becomes a two-step process: password first, then the configured 2FA method (TOTP, email code, backup codes, etc.). For passkey and security key support, add the [WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/) plugin. A visible countdown timer shows how long the user has to enter their code. Third-party 2FA plugins can integrate via filter hooks — see [Developer Reference](developer-reference.md).

## Does it work on multisite?

Yes. Settings are network-wide (one configuration for all sites). Sudo sessions use user meta (shared across the network), so authenticating on one site covers all sites. The action registry includes network-specific rules for theme enable/disable, site management, super admin grant/revoke, and network settings. The settings page appears under **Network Admin > Settings > Sudo**. On uninstall, per-site data is cleaned per-site, and user meta is only removed when no remaining site has the plugin active.

## What is gated on multisite subsites?

On multisite, WordPress core already removes the most dangerous General Settings fields (site URL, home URL, membership, default role) from subsite admin pages — only Super Admins can change those at the network level. The remaining subsite settings (site title, tagline, admin email, timezone, date/time formats) are low-risk and not gated. Changing the admin email also requires email confirmation by WordPress core. Network-level operations — network settings, theme management, site creation/deletion, and Super Admin grants — are all gated.

## What is the mu-plugin, and do I need it?

The "must-use" mu-plugin is optional but ***highly recommended***. It ensures Sudo's gate hooks are registered before any other regular plugin loads, preventing another plugin from deregistering the hooks or processing dangerous actions before the gate fires. You can install it with one click from the settings page or follow the instructions for copying it to the `/mu-plugins` folder. (You may need to do this manually in many hosting environments.) The mu-plugin is a thin shim in `wp-content/mu-plugins/` that loads the gate code from the main plugin directory — it updates automatically with regular plugin updates.

## What happens if I deactivate the plugin?

Any active sudo sessions expire naturally. All gated actions return to their normal, ungated behavior. No data is lost. The MU-plugin shim (if installed) checks the `active_plugins` option on each request and remains inert when the main plugin is deactivated — no gate hooks are registered, and no plugin code is loaded. On uninstall, the shim file is automatically deleted from `wp-content/mu-plugins/`.

## Can I extend the list of gated actions?

Yes. Use the `wp_sudo_gated_actions` filter to add custom rules. See [Developer Reference](developer-reference.md) for the rule structure and code examples.

## Can I change the 2FA authentication window?

Yes. The default window is 5 minutes. Use the `wp_sudo_two_factor_window` filter to adjust it (value in seconds). You cannot make it lower than 1 minute or higher than 15 minutes. A tiny window maximizes user inconvenience, and a large window minimizes the security benefits. 10-15 minutes is the industry norm, with 10m the usual default in *nix systems. See [Developer Reference](developer-reference.md).

## Does logging in automatically start a sudo session?

Yes (since v2.6.0). A successful browser-based WordPress login activates a sudo session automatically. The user just proved knowledge of the password via the login form, and WP Sudo's challenge is password-based — an immediate second challenge would add friction without adding a barrier.

Two caveats worth knowing. First, the grant is password-strength only: 2FA plugins interrupt on the same `wp_login` hook at later priority, so for 2FA-enrolled users the grant happens before the second factor is verified. Second, on shared terminals the grant means a logged-in-and-walked-away user leaves a sudo window open for whoever sits down next. Sites that care about either can suppress the automatic grant with the `wp_sudo_grant_session_on_login` filter (since 3.3.0) — return `false` and users must pass an explicit challenge at their first gated action instead. See the [security model](security-model.md#login-auto-grant) for the full analysis.

Application Password and XML-RPC logins are **not** affected — the `wp_login` hook only fires for browser form logins, and these non-interactive paths don't produce a session cookie anyway.

## How does WP Sudo work with SSO (SAML / OIDC) logins?

It depends on whether your SSO plugin fires the `wp_login` action when it signs a user in (most do, for ecosystem compatibility — verify with your provider's documentation or source).

If it does, each fresh identity-provider login grants a sudo session automatically, exactly like a form login. For SSO users **without a usable WordPress password**, this is the only practical path to gated actions: WP Sudo's challenge asks for the WordPress password, which they cannot supply. Their workflow for an expired sudo session is to log out and re-authenticate through the identity provider — a fresh IdP login is effectively their reauthentication.

Practical guidance:

- **Leave the auto-grant enabled** (the default) if your SSO users don't have WordPress passwords. Suppressing it via `wp_sudo_grant_session_on_login` makes gated actions permanently unreachable for them.
- **Suppress the grant selectively** if some users retain real WordPress passwords and you want them explicitly challenged — the filter receives the `WP_User` object, so you can return `false` only for specific roles or users.
- **If your SSO plugin does not fire `wp_login`**, no session is granted at login and passwordless users cannot pass the challenge; gated actions will be blocked for them. Options: ensure those users have known WordPress passwords, or have the SSO integration call `WP_Sudo\Sudo_Session::activate( $user_id )` after a verified fresh IdP authentication.

A first-class SSO/SAML/OIDC challenge-provider framework (reauthenticating against the IdP from the challenge page itself) is on the [roadmap](ROADMAP.md) as design backlog.

## What happens when I change my password — does it affect my sudo session?

Password changes on `profile.php`, `user-edit.php`, or via the REST API (`PUT`/`PATCH /wp/v2/users/{id}`) are themselves a **gated action** (since v2.6.0), so they already require an active sudo session to proceed. Since v2.8.0, WP Sudo automatically expires the sudo session when a password change is saved.

## What is the grace period?

A 2-minute wind-down window (since v2.6.0) allows gated actions to pass for 120 seconds after session expiry, provided the session token is still valid. This prevents form submissions and multi-step workflows from being interrupted by session expiry — without it, a user who spent three minutes on a form would have their work rejected and need to reauthenticate, losing any unsaved input.

**How it works:** When the gate checks the session, it first calls `Sudo_Session::is_active()`. If the session has expired, it also calls `is_within_grace()`. If the expiry happened within the last 120 seconds *and* the session token still matches (session binding is enforced throughout), the request passes. The gate does not distinguish between actions that were "in progress" before expiry and new ones — any gated action within the window is permitted if the token is valid.

**What it does not relax:** session binding. A stolen cookie on a different browser does not gain grace-period access. The session token must still match — `is_within_grace()` calls `verify_token()` before returning true. The admin bar timer always reflects the true session state (`is_active()`), not the grace state — the user sees accurately when their session has expired.
