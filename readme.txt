=== Sudo – Admin Action Gating ===
Contributors:      dpknauss
Donate link:       https://dan.knauss.ca
Tags:              reauthentication, access control, admin protection, multisite, security
Requires at least: 6.4
Tested up to:      7.0
Requires PHP:      8.2
Stable tag:        4.5.1
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Sudo gates dangerous admin actions (plugins, users, roles, settings, network changes) behind reauthentication, regardless of role.

== Description ==

WordPress has rich access control — roles, capabilities, policies on who can do what. It has no native control over when those capabilities can be exercised within a session. Sudo fills that gap. By gating consequential actions behind reauthentication whenever no active sudo window is already in place, it lets site owners directly define the blast radius of many session-compromise paths. The attack surface becomes a policy decision.

This is not role-based escalation. Every logged-in user is treated the same: attempt a gated action without an active sudo session, get challenged. Sessions are time-bounded and non-extendable, enforcing the zero-trust principle that trust must be continuously earned, never assumed. Sudo verifies that the current user is still the account holder; WordPress still decides whether that user is allowed to perform the action.

Lightweight by design. Sudo is an event-gate, not a query-heavy plugin: it adds no database queries to normal front-end page loads (at most one cached read for the logged-in admin-bar timer), ships zero production dependencies with no build step, and stores only three small options plus self-expiring session data. Its one growing table — the activity log — self-prunes at a 14-day default, and everything is removed on uninstall. Database work happens only when a covered action is actually being confirmed.

= Playground demo =

* [Try the latest release in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Fmain%2Fblueprint.json)
* [Try current main in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FSudo%2Fmain%2Fblueprint-main.json)

Playground demo credentials are `admin` / `password`. When WP Sudo asks for reauthentication, enter the same password: `password`.

= Why Sudo? =

In 2026, Broken Access Control accounted for 57% of all exploitation attempts against WordPress sites — add Privilege Escalation (20%) and Broken Authentication (3%) and that’s 80% of real-world WordPress attacks targeting the access-control operations Sudo gates (Patchstack 2026 RapidMitigate data). Nearly half of high-impact vulnerabilities are exploited within 24 hours; the median time to first exploit is 5 hours. Traditional WAFs block only 12–26% of these attacks.

When the firewall misses it, the plugin hasn’t patched it, and the attacker already has an active session — Sudo can still be the final layer **on the covered paths it intercepts**. Plugin installs, user creation, role changes, and settings modifications are all built-in gated operations. A stolen session cookie alone is not enough when that browser session does not already have an active sudo window.

**Important scope note:** WP Sudo gates specific known operations on specific known surfaces. A plugin vulnerability that performs a privileged state change through its own code path — without routing through a standard admin, AJAX, or REST surface WP Sudo intercepts — is outside this layer. WP Sudo is not a general repair for broken authorization in plugin code.

= What gets gated? =

* **Plugins** — activate, deactivate, delete, install, update
* **Themes** — switch, delete, install, update
* **Users** — delete, change role, change password, create new user, create application password
* **File editors** — plugin editor, theme editor
* **Critical options** — `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register`
* **Connector credentials** — Settings > Connectors API key updates saved through the REST settings endpoint
* **WordPress core** — update, reinstall
* **Site data export** — WXR export
* **WP Sudo settings** — settings changes are self-protected
* **Multisite** — network theme enable/disable, site delete/deactivate/archive/spam, super admin grant/revoke, network settings

Developers can add custom rules via the `wp_sudo_gated_actions` filter.

= How it works =

**Browser requests (admin UI):** The user sees an interstitial challenge page. After entering their password (and 2FA code if configured), the original request is replayed automatically. **AJAX and REST requests** receive a `sudo_required` error; an admin notice on the next page load links to the challenge page.

**Non-interactive requests (WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL):** Configurable per-surface policies with three modes: **Disabled**, **Limited** (default), and **Unrestricted**.

= Security features =

* **Zero-trust-aligned reauthentication** — a valid login session is never sufficient on its own. Dangerous operations require explicit identity confirmation whenever no active sudo session is already in place. This is a focused proof-of-intent layer for known operations and surfaces, not a comprehensive zero-trust framework.
* **Role-agnostic** — any user attempting a gated action without an active sudo session is challenged, including administrators.
* **Full attack surface** — admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, Application Passwords, and WPGraphQL.
* **Session binding** — sudo sessions are cryptographically bound to the browser via a secure httponly cookie token.
* **2FA browser binding** — the two-factor challenge is bound to the originating browser with a one-time challenge cookie.
* **Rate limiting** — 5 failed password attempts trigger a 5-minute lockout.
* **Self-protection** — changes to WP Sudo settings require reauthentication.
* **Server-side enforcement** — gating decisions happen in PHP hooks before action handlers. JavaScript is for UX only.

= Recommended plugins =

* **[Two Factor](https://wordpress.org/plugins/two-factor/)** — Strongly recommended. Makes the sudo challenge a two-step process: password + authentication code (TOTP, email, backup codes). Add **[WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)** for passkey and security key support.
* **[WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/)** or **[Stream](https://wordpress.org/plugins/stream/)** — Recommended for audit visibility. Sudo fires action hooks covering session lifecycle, gated actions, policy decisions, preset application, lockouts, and tamper detection.

= User experience =

* **Admin bar countdown** — a live M:SS timer shows remaining session time. Turns red in the final 60 seconds.
* **Keyboard shortcut** — press Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac) to proactively start a sudo session.
* **Accessible** — WCAG 2.1 AA throughout (screen-reader announcements, ARIA labels, focus management, keyboard support).
* **Contextual help** — built-in help tabs on the settings page.

= MU-plugin for early loading =

An optional mu-plugin ensures gate hooks are registered before any other plugin loads. Install it with one click from the settings page.

= Multisite =

Settings and sessions are network-wide. The action registry includes 8 additional network admin rules. Settings page appears under **Network Admin → Settings → Sudo**.

== Installation ==

1. Upload the `wp-sudo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Sudo** to configure session duration, quick policy presets, and entry-point policies.
4. (Optional) Install the mu-plugin from the settings page for early hook registration.
5. (Recommended) Install the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin for two-factor authentication.

== Frequently Asked Questions ==

= How does sudo gating work? =

For browser-admin requests, Sudo intercepts the request before WordPress processes the sensitive operation. In many common flows this happens on `admin_init`; other actions use surface-specific hooks. The original request is stashed, the user is redirected to a challenge page, and after successful reauthentication, the original request is replayed. For AJAX and REST requests, the browser receives a `sudo_required` error and an admin notice links to the challenge page.

= Does this replace WordPress roles and capabilities? =

No. Sudo verifies that the current user is still the account holder; WordPress still decides whether that user is allowed to perform the action.

= What about REST API and Application Passwords? =

Cookie-authenticated REST requests receive a `sudo_required` error. Application Password requests are governed by a separate policy (Disabled, Limited, or Unrestricted). Individual application passwords can override the global policy from the user profile page. Connector credential writes through the WordPress 7.0 Connectors API are also covered here: REST updates to `/wp/v2/settings` that include `connectors_*_api_key` fields are challenged as a gated action rather than being treated as ordinary settings writes.

= What about WP-CLI, Cron, and XML-RPC? =

Each has its own three-tier policy: Disabled, Limited (default), or Unrestricted. In Limited mode, gated actions are blocked while non-gated commands work normally.

= Does it support two-factor authentication? =

Yes. With the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin, the sudo challenge becomes a two-step process: password + authentication code. Third-party 2FA plugins can integrate via filter hooks.

= Does it work on multisite? =

Yes. Settings and sessions are network-wide. The action registry includes network-specific rules. See the Multisite section above.

= What problem does Sudo solve? =

Sudo can defeat or severely limit the damage attackers can do if they hijack an authenticated session or successfully exploit a vulnerability. Session theft via stolen cookies, unattended devices, broken access control exploits, and credential stuffing all produce a valid session. Sudo means a valid session alone is not enough on the covered paths it intercepts — unless that same browser session already has an active sudo window.

= How is Sudo different from WordPress security plugins? =

No existing security plugin gates actions that authenticated users can take. Conventional plugins focus on perimeter defense — rate-limiting, firewalling, malware scanning. Sudo operates at the final point of consequence: between an authenticated session and the destructive action it might take. A stolen cookie, a compromised account, an exploited plugin vulnerability — Sudo is the layer that can still require password confirmation before damage can be done on the covered paths it intercepts.

= What are Sudo's limitations? =

Sudo does not protect against an attacker who already knows your WordPress password and 2FA one-time password — someone who possesses all credentials can complete the sudo challenge just as the real user can. It also does not protect against direct database access, file system operations that bypass WordPress hooks, or custom plugin code that mutates privileged state through paths WP Sudo never sees. If a vulnerable plugin runs inside an already-active sudo window for that same browser session, WP Sudo usually will not prompt again until the window expires. For a full account of what Sudo does and does not defend against, see the Security Model documentation on GitHub.

= What if sudo is already active when a broken access control bug is exploited? =

Active sudo is per browser session, not site-wide. Another administrator's active sudo session does not help an attacker in a different browser or on a different machine.

If the exploit runs inside the same browser session that already has an active sudo window, WP Sudo usually will not prompt again for covered actions until that window expires. Correct capability checks can still block the action, but broken authorization in the vulnerable plugin may not. WP Sudo is strongest when an attacker has a stolen session but not the password or second factor, and no active sudo window is already in place.

= Is there brute-force protection? =

Yes. After 5 failed password attempts on the reauthentication form, the user is locked out for 5 minutes. Lockout events fire the wp_sudo_lockout action hook for audit logging.

= Does logging in automatically start a sudo session? =

Yes (since v2.6.0). A successful browser-based login activates a sudo session automatically — the user just proved their identity, so requiring a second challenge immediately is unnecessary friction. Application Password and XML-RPC logins are not affected.

= What happens when I change my password? =

Password changes on profile.php, user-edit.php, or via the REST API are a gated action (since v2.6.0) — they require an active sudo session to proceed. Since v2.8.0, saving a password change also automatically expires any active sudo session.

= What is the grace period? =

A 2-minute grace window (since v2.6.0) allows in-flight form submissions to complete even if the sudo session expired while the user was filling in the form. Session binding is enforced throughout — a stolen cookie on a different browser does not gain grace-period access.

= Can I change the 2FA authentication window? =

Yes. The default window is 5 minutes — how long a user has to enter their 2FA code after successfully providing their password. Use the wp_sudo_two_factor_window filter to adjust it (value in seconds). See the developer reference on GitHub for details.

== For Developers ==

WP Sudo is built for correctness and contributor legibility, not just functionality.

Architecture: a single SPL autoloader maps the WP_Sudo\* namespace to includes/class-*.php. The Gate class detects the entry surface (admin UI, AJAX, REST, WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL), matches the incoming request against the built-in rule registry, and challenges, soft-blocks, or hard-blocks based on surface and policy. All gating decisions happen server-side in PHP hooks — JavaScript is used only for UX.

Testing: the suite is split into two tiers. Unit tests use Brain\Monkey to mock WordPress functions and run in ~0.4s. Integration tests run against real WordPress + MySQL and cover full reauth flows, AJAX and REST gating, Two Factor interaction, multisite isolation, uninstall cleanup, and all audit hooks. Current suite counts are maintained in the GitHub repository.

CI: GitHub Actions runs PHPStan level 6 and PHPCS on every push and PR, unit tests across PHP 8.2-8.4, integration lanes across WordPress 6.4/6.7/7.0, and scheduled compatibility sweeps for supported WordPress minors.

Extensibility: the action registry is filterable via wp_sudo_gated_actions. Audit hooks cover session lifecycle, gated actions, policy decisions, preset application, lockouts, tamper detection, and the high-severity admin-escalation block. See the GitHub repository for hook reference, CONTRIBUTING.md, and the full developer documentation.

== Screenshots ==

1. Challenge page — reauthentication interstitial with password field.
2. Gated plugin activation — the Plugins page Activate link replaced with a reauthentication prompt.
3. Settings tab — policy presets, session duration, and entry-point policies.
4. Gated Actions tab — protected operations with rule IDs and covered surfaces.
5. Rule Tester tab — evaluate representative request shapes without executing them.
6. Access tab — manage dedicated Sudo governance capabilities.
7. Dashboard widget — active sessions, policy summary, and recent events including session revocations.
8. Admin bar timer — live countdown while a sudo session is active.
9. Users list — Sudo Active view with the "Revoke sudo sessions" bulk action and per-user row action.

== Changelog ==

= 4.5.1 =
* **Harmonized user identity (UX)** — the Session Activity dashboard widget and the Settings → Sudo Access tab now present users identically: full real name as the primary line, username secondary (linked to the user-edit screen when the operator can edit that user), with an avatar and translated role chip(s). A shared `WP_Sudo\User_Identity` helper keeps the two surfaces from drifting.
* **Avatar rendering fix** — the widget avatar passed `get_avatar()` a non-existent `force` argument, so it silently honored the site's "Show Avatars" (Discussion) setting instead of always rendering; corrected to `force_display`.

= 4.5.0 =
* **Security — escalation-guard authority** — the opt-in admin-escalation guard now requires the acting user to hold the promoting authority (`promote_users` for administrator grants, super-admin for `grant_super_admin`) in addition to an active sudo session, closing a broken-access-control bypass where a low-privilege account with a sudo session could pass the backstop.
* **Security — session-revocation binding** — revoking another user's sudo session now requires the operator's token-bound sudo session, so a stolen auth cookie or a session without its own sudo can no longer revoke others' sessions.
* **Session revocation UX** — revocation moved to a native "Revoke sudo sessions" Users-list bulk action (replacing the toolbar button and its unstyled interstitial), and the Session Activity dashboard widget now records and displays session revocations.
* **Access tab** — the capability-holder table is more readable and accessible (one row per capability, human-readable labels, per-control accessible names, translatable labels); the governance coverage panel now names the context-correct capability on multisite and no longer lists super admins as unable to access settings.
* **Two Factor lifecycle bridge** — the optional bridge now also gates classic `profile.php` / `user-edit.php` provider lifecycle changes behind an active sudo session.
* **Localization** — committed the translation template and added WP-CLI-backed Composer commands to regenerate and verify it.

= 4.2.2 =
* **Access tab polish** — the Grant Capability form now includes a searchable administrator picker while preserving the numeric `user_id` grant contract.
* **Submission readiness** — refreshed the Access tab screenshot, canonical metrics, and release-planning docs while keeping WordPress.org submission intentionally delayed/on hold.

= 4.2.1 =
* **WordPress.org package readiness** — cleaned Plugin Check input-handling findings and shortened the 4.0.0 upgrade notice to fit directory limits.
* **Submission warning triage** — Pressship now verifies with one documented slug warning for the intended `wp-sudo` slug.

= 4.2.0 =
* **Two Factor bridge hardening** — REST factor-management operations in the optional Two Factor bridge are now gated behind WP Sudo.
* **WSAL bridge expansion** — the optional WP Activity Log sensor bridge maps additional security/governance audit hooks into WSAL events for escalation blocks, session revocation, recovery-mode use, governance-capability changes, missing built-in rules, and regex-rule failures.
* **Gutenberg REST UX groundwork** — cookie-authenticated REST `sudo_required` responses now include a `challenge_url` for editor clients. Headless REST policy responses remain unchanged.
* **Test hardening** — added integration coverage for activation/deactivation lifecycle behavior, `destroy_all()` login-session-binding invariants, and live admin-escalation guard hooks.

= 4.1.0 =
**Security — gate completeness (coordinated disclosure). Affected versions: ≤ 4.0.0.**
* **Interactive effect-level backstop** — a session-aware guard on `admin_init` now hard-blocks the destructive core effects (`delete_user`, `delete_plugin`, `delete_theme`, `activate_plugin`, `upgrader_pre_install`, `export_wp`) when no sudo window is active, closing a gap where those actions could run through a non-enumerated handler such as a third-party `admin-post.php` route. Scoped to those effect hooks by design; option writes and role/user-create paths are excluded to avoid blocking legitimate workflows.
* **Login-session binding** — the sudo proof is now bound to the WordPress login session that created it (new `_wp_sudo_session_bind` user meta). A captured `wp_sudo_token` cookie can no longer be replayed from another session; the window ends on logout and a bound proof stops verifying once its login session is no longer valid (e.g. after `destroy_all()`). No migration required; cookie-less surfaces and pre-4.1.0 sessions are unaffected.
* **Admin-escalation guard (opt-in, default OFF)** — a new role-aware guard refuses to grant administrator (single-site) or super-admin (multisite) unless the acting user holds an active sudo session. Because the attacker in the common privilege-escalation exploits is unauthenticated or low-privilege and cannot hold a session, the grant is blocked even when a third-party plugin's own permission check fails. Effect-level and surface-agnostic (the capabilities meta write + `grant_super_admin`), fires only on a *newly granted* admin/super-admin, halts before the write, and emits a distinct high-severity `wp_sudo_escalation_blocked` event. Enable via the `wp_sudo_guard_escalation` filter; defers on CLI/Cron/XML-RPC and Unrestricted Application-Password surfaces, with a `wp_sudo_allow_escalation` allowlist and `WP_SUDO_ALLOW_ESCALATION` constant for trusted provisioners and recovery.

= 4.0.0 =
**Breaking release — review before upgrading.**
* **Removed `sudo_can()`** — use the prefixed `wp_sudo_can()` (identical signature). Search-replace any remaining calls.
* **Removed the `compatibility` governance mode** — governance is now always strict. The leftover `wp_sudo_governance_mode` option is deleted automatically on upgrade (one-time dismissible notice); `WP_SUDO_RECOVERY_MODE` remains the only break-glass recovery path.
* **Minimum requirements raised** to WordPress 6.4 and PHP 8.2.
* **Connectors credential gating on WordPress 7.0** — a registry-aware matcher gates connector API-key writes to `/wp/v2/settings`, closing a gap where names like Akismet's `wordpress_api_key` were ungated.
* **Fixed a WordPress 7.0 upgrade fatal** under WP-CLI/cron (the governance backfill dereferenced an uninitialized roles global).
* **WordPress.org readiness** — listing name "Sudo – Admin Action Gating", added SECURITY.md, refreshed listing screenshots, and a manual release environment matrix.

= 3.4.0 =
* **Break-glass recovery mode hardened** — `WP_SUDO_RECOVERY_MODE` is now role-gated (only `manage_options` / `manage_network_options` holders recover), shows a permanent notice while active, and fires the new `wp_sudo_recovery_mode_active` audit hook (sampled, at most one event per user per hour).
* **CI and quality** — repaired the Psalm type-coverage gate (it had been silently passing without analyzing), added least-privilege permissions to all workflows, and reconciled documentation drift.
* **Fix** — removed an obsolete Editor role-error notice in the admin UI; fixed an SSL-detection unit-test flake.

= 3.3.0 =
* **Fix strict-mode lockout** — the governance-capability backfill was keyed to a version (3.1.0) that never shipped, so sites upgrading from public 3.1.x releases skipped it and could be locked out of Settings → Sudo. It is re-keyed to 3.3.0, runs once for affected sites, and skips when any user already holds `manage_wp_sudo`.
* **Audit column clamping** — `Event_Store` clamps `event`, `rule_id`, `surface`, and `ip` to their schema widths before insert, so over-length third-party values truncate predictably instead of dropping the audit row.
* **New `wp_sudo_grant_session_on_login` filter** — suppress the automatic sudo session granted on browser login for shared-terminal/kiosk or SSO scenarios.

= 3.2.0 =
* **Governance capabilities** — new `wp_sudo_can()` helper and an Access tab for managing who can administer Sudo, mapped to standard WordPress capabilities so WP-CLI and audit plugins can evaluate them via `current_user_can()`.
* **Security hardening** — 2FA lockout integrity, WPGraphQL mutation-detection hardening (tokenizer, persisted-query fail-safe, multipart/batched coverage), REST gating for folder-based plugins, per-user IP lockout, admin-email gating, cookie Secure-flag fallback behind TLS proxies, and request-stash minimization.
* **App Password policy validation** — per-App-Password policy overrides require UUID format + existence checks, with automatic cleanup when the App Password is deleted.

= 3.1.3 =
* **Fix: release Playground link** — the stable release Blueprint installs the tag ZIP through `pluginData` instead of using Playground's currently brittle `git:directory` tag fetch path.
* **Playground link posture** — README Playground links now distinguish the immutable latest-release demo from the current `main` demo.
* **Blueprint password seeding** — the demo Blueprint now uses WordPress core's `wp_set_password()` API instead of writing the password hash directly through `$wpdb`.

= 3.1.2 =
* **Fix: Playground authentication** — the demo resets the `admin` user password before login so `admin` / `password` works for both WordPress login and WP Sudo reauthentication.
* **Fix: toolbar session cancellation** — the Sudo admin bar item now cancels active sessions reliably from wp-admin and front-end contexts.
* **Fix: dashboard widget freshness** — active-session transients are invalidated when sessions are cancelled, so the dashboard widget updates without needing a manual refresh.
* **Demo data** — Playground now seeds recent privilege-action samples and active demo sudo sessions with staggered 5-15 minute durations.
* **CI preview** — PR Playground preview links now use the checked-in Blueprint and a CORS-safe `git:directory` plugin install instead of GitHub archive ZIP URLs.

= 3.1.1 =
* **Security: role-change interception hardening** — role and capability metadata writes are now blocked before mutation when they require an active sudo session.
* **Security: sensitive request replay safety** — intercepted requests that include password/secret fields no longer replay partial POST data after those fields are omitted from the stash; users are returned with a warning instead.
* **Fix: MU-plugin loader resilience** — copied MU shims now preserve the actual plugin loader path, and the static shim can recover when the plugin directory is renamed.
* **Audit bridge parity** — Stream and WP Activity Log bridges now include `wp_sudo_action_passed` events.
* **Compatibility: PHP 8.0 test support** — reflection-based unit tests avoid PHP 8.1-only reflection behavior when running under PHP 8.0.
* **Dependency security** — updated vulnerable transitive development dependencies, including `fast-xml-parser`, and cleared the npm audit report.

= 3.0.0 =
* **Major milestone: operator tooling and visibility** — adds the Request / Rule Tester plus a new Session Activity Dashboard Widget with active sessions, recent events, and policy summary.
* **Major milestone: policy control** — adds one-click **Normal**, **Incident Lockdown**, and **Headless Friendly** presets for the non-interactive surfaces.
* **Security: Connectors API credential writes now require sudo** — REST updates to `/wp/v2/settings` are challenged when they include `connectors_*_api_key` fields, protecting database-backed connector credentials without over-gating unrelated settings writes.
* **Platform: lightweight event persistence** — adds an internal event store and recorder with daily pruning, enabling dashboard visibility and future reporting.
* **Fix: challenge and lockout recovery hardening** — improves retry behavior around exact-expiry lockouts and stale challenge / 2FA return flows.
* **Compatibility and testing breadth** — expands WordPress minor coverage, nginx + MariaDB and Playground SQLite browser lanes, and local integration fallback behavior for `wp-env`.
* **WordPress 7.0 readiness** — forward test and preview lanes remain pinned to `7.0-RC1` pending final release-day verification.

= 2.14.0 =
* **Feature: Playwright end-to-end coverage** — added browser-verified challenge, cookie, gate UI, admin bar timer, keyboard shortcut, MU-plugin AJAX, multisite network-admin, and visual-regression coverage to exercise the real user flows around reauthentication.
* **Fix: multisite symlink and network-admin flow hardening** — preserved network-admin return URLs and supported symlinked local multisite installs used in Local and Studio-style development.
* **Fix: bootstrap plugin URL handling** — plugin asset URLs now preserve normal `plugins_url` filtering and custom plugin roots instead of assuming a fixed `/wp-content/plugins/` path.
* **Testing workflow: Local socket support** — `bin/install-wp-tests.sh` can now auto-detect a single Local by Flywheel MySQL socket when TCP MySQL is unavailable, with updated contributor guidance for local integration setup.
* **Repo hygiene** — added GPL license and repository health files, and centralized live test/size counts in `docs/current-metrics.md`.
* **504 unit tests, 1311 assertions. 140 integration tests in CI.**

= 2.13.0 =
* **Feature: IP + user multidimensional rate limiting** — per-IP failed-attempt tracking alongside per-user, with combined lockout policy and the triggering IP address added as the third `wp_sudo_lockout` hook argument.
* **Docs alignment** — security model, developer reference, and manual testing guide updated for new rate-limiting dimensions.
* **496 unit tests, 1293 assertions. 132 integration tests in CI.**

= 2.12.0 =
* **Feature: WP-CLI operator commands** — added `wp sudo status`, `wp sudo revoke --user=<id>`, and `wp sudo revoke --all` for session inspection and revocation workflows.
* **Feature: Stream audit bridge** — added optional `bridges/wp-sudo-stream-bridge.php`, mapping all 9 WP Sudo audit hooks into Stream records with inert behavior when Stream APIs are unavailable.
* **Feature: public integration API (`wp_sudo_check()` / `wp_sudo_require()`)** — added first-party helpers for third-party plugins/themes to require active sudo sessions without full Gate-rule registration.
* **Docs and release hygiene** — updated developer reference/manual testing for Stream + public API, refreshed roadmap priorities, and regenerated `bom.json`.
* **494 unit tests, 1286 assertions. 135 integration tests in CI.**

= 2.11.1 =
* **Docs release + metadata alignment** — corrected post-v2.11.0 documentation drift: roadmap completion markers, RC re-test guidance, and release notes alignment across `CHANGELOG.md`, `readme.md`, and `readme.txt`.
* **Version annotation fixes** — corrected `@since` annotations introduced in the v2.11.0 development cycle so Phase 3/4 additions no longer reference the nonexistent `2.10.3` version.
* **Pre-release hygiene** — regenerated `bom.json` and updated ignore rules for `.planning/private-reference/`, `.composer_cache/`, and `vendor_test/`.
* **478 unit tests, 1228 assertions. 130 integration tests in CI.**

= 2.11.0 =
* **Action Registry hardening (Phase 3.01)** — filtered `wp_sudo_gated_actions` input is now normalized and validated before caching. Invalid or malformed third-party rule fragments are safely discarded instead of flowing into matchers.
* **MU-loader resilience (Phase 3.02)** — loader now resolves plugin basename/path with explicit fallback ordering and respects plugin activation state across single-site and multisite contexts.
* **WPGraphQL persisted-query strategy (Phase 4.01)** — mutation gating now supports persisted-query detection hooks and clearer policy behavior for headless GraphQL deployments.
* **WSAL sensor bridge (Phase 4.02)** — new optional bridge (`bridges/wp-sudo-wsal-sensor.php`) maps WP Sudo’s 9 audit hooks into WP Activity Log events.
* **Coverage expansion** — high-value unit and integration coverage added across phases 3/4, including malformed rule inputs, MU-loader edge paths, WPGraphQL policy enforcement, and bridge emission behavior.
* **Housekeeping** — Admin bar class cleanup (docblock trimming, explicit hook args); no behavioral changes.
* **478 unit tests, 1228 assertions. 130 integration tests in CI.**

= 2.10.2 =
* **Fix: multisite uninstall orphaned MU-plugin shim and user meta** — network-activated uninstall now unconditionally cleans all sites and network-wide data.
* **Fix: `wp_sudo_version` option not deleted on uninstall** — orphan option row left after plugin deletion.
* **Fix: `Admin::get()` TypeError on PHP 8.2+** — corrupted settings no longer crash; falls back to defaults.
* **Fix: `Gate::matches_rest()` crash on invalid third-party regex** — new `safe_preg_match()` wrapper fails closed.
* **Psalm 6.16 + Shepherd type coverage** — dual static analysis; type coverage badge via shepherd.dev.
* **Codecov integration** — unit test coverage uploaded on CI.
* **16 new unit tests** closing gaps in CLI cron-policy, network activation, settings save, admin bar, transient failures, cookie/token edges, 2FA provider.
* **428 unit tests, 1043 assertions.**

= 2.10.1 =
* **Fix: accessibility audit follow-up** — admin bar countdown polish, docs alignment.

= 2.10.0 =
* **Feature: WebAuthn gating bridge** — gates WebAuthn key registration/deletion when the Two Factor WebAuthn plugin is active.
* **Fix: MU-plugin shim respects deactivation** — loader checks `active_plugins` before loading; inert when deactivated.
* **Fix: WP 7.0 notice CSS, 2FA window clamping, app-password JS localization.**
* **REST `_wpnonce` fallback** — accepts query parameter when cookie nonce header absent.
* **Exit path integration tests** — REST 403, AJAX 403, admin redirect, challenge auth, grace window.
* **397 unit tests, 944 assertions.**

= 2.9.2 =
* **Fix: 2FA help text corrected** — Settings help tab said "default 2FA window is 10 minutes"; code default is 5 minutes. Fixed. (The sudo session countdown is a separate timer and remains at 15 minutes.)
* **Fix: version constant drift** — `phpstan-bootstrap.php` and `tests/bootstrap.php` had stale version constants; now synced to 2.9.2.
* **Docs: readme.txt expanded** — Patchstack 2026 attack statistics added to Description; 8 new FAQ entries covering problem scope, differences from security plugins, limitations, brute-force protection, login session grant, password change behaviour, grace period, and 2FA window.

= 2.9.1 =
* **Docs: threat model kill chain** — verified risk reduction data from Patchstack, Sucuri, Verizon DBIR, Wordfence, and OWASP added to security model and FAQ.
* **Docs: project size table** — readme.md gains a Project Size subsection; stale test counts corrected; missing v2.8.0/v2.9.0 changelog entries added.

= 2.9.0 =
* **`wp_sudo_action_allowed` audit hook** — fires when a gated action is permitted by an Unrestricted policy. Covers all five non-interactive surfaces: REST App Passwords, WP-CLI, Cron, XML-RPC, and WPGraphQL (mutations only). This is the ninth audit hook.
* **Docs: CLAUDE.md accuracy audit** — corrected six inaccuracies; logged one confabulation in `llm_lies_log.txt`.
* **397 unit tests, 944 assertions.**

= 2.8.0 =
* **Expire sudo session on password change** — hooks `after_password_reset` and `profile_update` to invalidate any active sudo session when a user's password changes. Closes the gap where a compromised session persisted after a password reset.
* **WPGraphQL conditional display** — the WPGraphQL policy dropdown, help tab paragraph, and Site Health review all adapt based on whether WPGraphQL is installed.
* **391 unit tests, 929 assertions.**

= 2.7.0 =
* **`wp_sudo_wpgraphql_bypass` filter** — new filter for WPGraphQL JWT authentication compatibility. Fires in Limited mode before mutation detection; return `true` to exempt specific requests (e.g. JWT login/refresh mutations). See developer reference for a bridge mu-plugin example.
* **Fix: WPGraphQL listed in non-interactive entry points** — the "How Sudo Works" help tab now includes WPGraphQL in the list of policy-governed surfaces.

= 2.6.1 =
* **Fix: WPGraphQL integration tests** — extract `Gate::check_wpgraphql()` to fix pre-existing CI test regression; no behavioral change in production.
* **Docs: v2.6.0 documentation update** — FAQ, ROADMAP, developer-reference.md, security-model.md, MANUAL-TESTING.md updated to reflect v2.6.0 features.

= 2.6.0 =
* **Login implicitly grants a sudo session** — a successful browser-based login now automatically activates a sudo session. No second challenge required immediately after logging in. Application Password and XML-RPC logins are unaffected.
* **user.change_password gated** — password changes on the profile and user-edit pages now require a sudo session. Closes the session-theft → silent password change → lockout attack chain. The REST API endpoint is also gated.
* **Grace period (120 s)** — a 2-minute grace window after session expiry lets in-flight form submissions complete without triggering a re-challenge. Session binding is verified throughout the grace window.
* **375 unit tests, 905 assertions. 73 integration tests in CI.**

= 2.5.0 =
* **WPGraphQL surface gating** — adds WPGraphQL as a fifth non-interactive surface. Three-tier policy (Disabled / Limited / Unrestricted); default is Limited. Mutations are blocked without a sudo session; queries pass through. Fires wp_sudo_action_blocked on block.
* **Site Health** — WPGraphQL policy included in Entry Point Policies health check.
* **364 unit tests, 887 assertions. 73 integration tests in CI.**

= 2.4.1 =
* **AJAX gating integration tests** — 11 new tests covering the AJAX surface: rule matching for all 7 declared AJAX actions, full intercept flow, session bypass, non-gated pass-through, blocked transient lifecycle, admin notice fallback, and wp.updates slug passthrough.
* **Action registry filter integration tests** — 3 new tests verifying custom rules added via wp_sudo_gated_actions are matched by the Gate in a real WordPress environment.
* **Audit hook coverage** — wp_sudo_action_blocked now integration-tested for CLI, Cron, and XML-RPC surfaces (in addition to REST app-password).
* **CI quality gate** — new GitHub Actions job runs PHPCS and PHPStan on every push and PR; Composer dependency cache added; nightly scheduled run against WP trunk.
* **MU-plugin manual install instructions** — fallback copy instructions added to the settings page UI and help tab.
* **CONTRIBUTING.md** — new contributor guide covering local setup, test strategy, TDD workflow, and code style requirements.
* **349 unit tests, 863 assertions. 73 integration tests in CI.**

= 2.4.0 =
* **Integration test suite** — 55 tests against real WordPress + MySQL (session lifecycle, request stash/replay, full reauth flow, REST gating, upgrader migrations, Two Factor interaction, multisite isolation).
* **CI pipeline** — GitHub Actions with unit tests across PHP 8.1–8.4 and integration tests against WordPress latest + trunk.
* **Fix: multisite site-management gate gap** — Archive, Spam, Delete, Deactivate site actions now correctly trigger the sudo challenge.
* **Fix: admin bar timer width** — expiring (red) state no longer stretches wider than active (green) state.
* **Fix: WP 7.0 admin notice background** — restored white background lost in WP 7.0's admin visual refresh.
* **Fix: 2FA countdown advisory-only** — window reduced to 5 minutes; expired codes accepted if provider validates.
* **WP 7.0 Beta 1 tested** — full manual testing guide completed, all 15 sections PASS.
* **349 unit tests, 863 assertions. 55 integration tests in CI.**

= 2.3.2 =
* **Fix: admin bar sr-only text leak** — screen-reader-only milestone text no longer renders in the dashboard canvas when the admin bar node lacks a containing block.
* **Documentation overhaul** — readmes slimmed; security model, developer reference, FAQ, and full changelog extracted to `docs/`. Manual testing guide rewritten for v2.3.1+.
* **Composer lock compatibility** — `config.platform.php` set to `8.1.99` so the lock file resolves for PHP 8.1+ regardless of local version.
* **Housekeeping** — removed stale project state file and outdated manual testing guide; added `@since` tags; updated CLAUDE.md and Copilot instructions with docs/ references.
* **343 unit tests, 853 assertions.**

= 2.3.1 =
* **Fix: Unicode escape rendering** — localized JS strings now use actual UTF-8 characters, fixing visible backslash-escape text during challenge replay.
* **Fix: screen-reader-only text flash** — the sr-only span no longer flashes visible fragments during replay.
* **CycloneDX SBOM** — `bom.json` shipped for supply chain transparency.
* **Help tabs** — per-application-password policy section added. Count corrected to 8.
* **Copilot coding agent** — GitHub Copilot configuration added.
* **Accessibility roadmap complete** — all items verified resolved.
* **343 unit tests, 853 assertions.**

= 2.3.0 =
* **Per-application-password sudo policies** — individual Application Password credentials can override the global REST API policy.
* **Challenge page iframe fix** — breaks out of `wp_iframe()` context.
* **Accessibility improvements** — admin bar cleanup on page unload; lockout countdown SR throttling; settings field defaults.
* **PHPStan level 6 static analysis** — zero errors.
* **Documentation** — AI and agentic tool guidance and UI/UX testing prompts.
* **343 unit tests, 853 assertions.**

See the plugin's `CHANGELOG.md` for all versions.

== Upgrade Notice ==

= 4.5.1 =
UX release: unifies how users are shown on the dashboard widget and the Access tab (full name primary, username secondary, avatar, role chips) and fixes a widget avatar that failed to render when "Show Avatars" was off. No migration required.

= 4.5.0 =
Recommended security update: hardens the admin-escalation guard (requires the actor's promoting authority) and session revocation (requires a token-bound sudo session). Adds bulk session revocation, dashboard revocation visibility, and Access-tab a11y/i18n. No migration required.

= 4.0.0 =
Breaking release: replace `sudo_can()` with `wp_sudo_can()`. Compatibility governance mode is removed; strict governance and `WP_SUDO_RECOVERY_MODE` remain. Requires WordPress 6.4+ and PHP 8.2+.

= 2.7.0 =
New `wp_sudo_wpgraphql_bypass` filter for JWT authentication compatibility. No settings migration required.

= 2.6.1 =
No behavioral changes. CI fix and documentation update only.

= 2.6.0 =
Login now automatically grants a sudo session. Password changes are now gated. A 2-minute grace period prevents form failures when the session expires mid-submission. No settings migration required.

= 2.4.0 =
Integration test suite, CI pipeline, multisite gate fix, admin bar CSS fix, WP 7.0 compatibility. No settings migration required.

= 2.3.2 =
Admin bar CSS fix, documentation overhaul, Composer lock compatibility. No settings changes required.

= 2.3.1 =
Bug fixes (Unicode escapes, sr-only text flash), CycloneDX SBOM, accessibility roadmap complete. No settings changes required.

= 2.3.0 =
Per-application-password sudo policies, challenge page iframe fix, accessibility improvements, PHPStan level 6 static analysis. No settings migration required.

= 2.2.0 =
Entry point policies now have three modes: Disabled, Limited, Unrestricted. Existing Block/Allow settings are migrated automatically. Review Settings > Sudo after upgrading.

= 2.0.0 =
Major rewrite. The custom Site Manager role is removed. Sudo now gates dangerous actions for all users via reauthentication. Review the new settings (entry point policies) after upgrading.
