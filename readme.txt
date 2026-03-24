_This exploratory plugin is not production-ready. Please test it and share your feedback on what works and what doesn't._

[Try it in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/wp-sudo/main/blueprint.json)

=== Sudo ===
Contributors:      dpknauss
Donate link:       https://dan.knauss.ca
Tags:              sudo, security, reauthentication, access control, admin protection
Requires at least: 6.2
Tested up to:      7.0
Requires PHP:      8.0
Stable tag:        2.14.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

WordPress security plugins guard the door. Sudo governs what can happen inside the house.

== Description ==

WordPress has rich access control — roles, capabilities, policies on who can do what. It has no native control over when those capabilities can be exercised within a session. Sudo fills that gap. By gating consequential actions behind reauthentication, it lets site owners directly define the blast radius of any session compromise — regardless of how that compromise occurred, and regardless of the user's role. The attack surface becomes a policy decision.

This is not role-based escalation. Every logged-in user is treated the same: attempt a gated action, get challenged. Sessions are time-bounded and non-extendable, enforcing the zero-trust principle that trust must be continuously earned, never assumed. WordPress capability checks still run after the gate, so Sudo adds a security layer without changing the permission model.

= Why Sudo? =

In 2026, Broken Access Control accounted for 57% of all exploitation attempts against WordPress sites — add Privilege Escalation (20%) and Broken Authentication (3%) and that's 80% of real-world WordPress attacks targeting the exact operations Sudo gates (Patchstack 2026 RapidMitigate data). Nearly half of high-impact vulnerabilities are exploited within 24 hours; the median time to first exploit is 5 hours. Traditional WAFs block only 12–26% of these attacks.

When the firewall misses it, the plugin hasn't patched it, and the attacker already has an active session — Sudo is the final layer. Every destructive action requires reauthentication, regardless of how the attacker got in. A stolen session cookie is not enough. An unattended browser is not enough.

= What gets gated? =

* **Plugins** — activate, deactivate, delete, install, update
* **Themes** — switch, delete, install, update
* **Users** — delete, change role, change password, create new user, create application password
* **File editors** — plugin editor, theme editor
* **Critical options** — `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register`
* **WordPress core** — update, reinstall
* **Site data export** — WXR export
* **WP Sudo settings** — settings changes are self-protected
* **Multisite** — network theme enable/disable, site delete/deactivate/archive/spam, super admin grant/revoke, network settings

Developers can add custom rules via the `wp_sudo_gated_actions` filter.

= How it works =

**Browser requests (admin UI):** The user sees an interstitial challenge page. After entering their password (and 2FA code if configured), the original request is replayed automatically. **AJAX and REST requests** receive a `sudo_required` error; an admin notice on the next page load links to the challenge page.

**Non-interactive requests (WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL):** Configurable per-surface policies with three modes: **Disabled**, **Limited** (default), and **Unrestricted**.

= Security features =

* **Zero-trust architecture** — a valid login session is never sufficient on its own. Dangerous operations require explicit identity confirmation every time.
* **Role-agnostic** — any user attempting a gated action is challenged, including administrators.
* **Full attack surface** — admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, Application Passwords, and WPGraphQL.
* **Session binding** — sudo sessions are cryptographically bound to the browser via a secure httponly cookie token.
* **2FA browser binding** — the two-factor challenge is bound to the originating browser with a one-time challenge cookie.
* **Rate limiting** — 5 failed password attempts trigger a 5-minute lockout.
* **Self-protection** — changes to WP Sudo settings require reauthentication.
* **Server-side enforcement** — gating decisions happen in PHP hooks before action handlers. JavaScript is for UX only.

= Recommended plugins =

* **[Two Factor](https://wordpress.org/plugins/two-factor/)** — Strongly recommended. Makes the sudo challenge a two-step process: password + authentication code (TOTP, email, backup codes). Add **[WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)** for passkey and security key support.
* **[WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/)** or **[Stream](https://wordpress.org/plugins/stream/)** — Recommended for audit visibility. Sudo fires 9 action hooks covering session lifecycle, gated actions, policy decisions, and lockouts.

= User experience =

* **Admin bar countdown** — a live M:SS timer shows remaining session time. Turns red in the final 60 seconds.
* **Keyboard shortcut** — press Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac) to proactively start a sudo session.
* **Accessible** — WCAG 2.1 AA throughout (screen-reader announcements, ARIA labels, focus management, keyboard support).
* **Contextual help** — 10 help tabs on the settings page.

= MU-plugin for early loading =

An optional mu-plugin ensures gate hooks are registered before any other plugin loads. Install it with one click from the settings page.

= Multisite =

Settings and sessions are network-wide. The action registry includes 8 additional network admin rules. Settings page appears under **Network Admin → Settings → Sudo**.

== Installation ==

1. Upload the `wp-sudo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → Sudo** to configure session duration and entry point policies.
4. (Optional) Install the mu-plugin from the settings page for early hook registration.
5. (Recommended) Install the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin for two-factor authentication.

== Frequently Asked Questions ==

= How does sudo gating work? =

When a user attempts a gated action, Sudo intercepts the request at `admin_init`. The original request is stashed, the user is redirected to a challenge page, and after successful reauthentication, the original request is replayed. For AJAX and REST requests, the browser receives a `sudo_required` error and an admin notice links to the challenge page.

= Does this replace WordPress roles and capabilities? =

No. Sudo adds a reauthentication layer on top of the existing permission model. WordPress capability checks still run after the gate.

= What about REST API and Application Passwords? =

Cookie-authenticated REST requests receive a `sudo_required` error. Application Password requests are governed by a separate policy (Disabled, Limited, or Unrestricted). Individual application passwords can override the global policy from the user profile page.

= What about WP-CLI, Cron, and XML-RPC? =

Each has its own three-tier policy: Disabled, Limited (default), or Unrestricted. In Limited mode, gated actions are blocked while non-gated commands work normally.

= Does it support two-factor authentication? =

Yes. With the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin, the sudo challenge becomes a two-step process: password + authentication code. Third-party 2FA plugins can integrate via filter hooks.

= Does it work on multisite? =

Yes. Settings and sessions are network-wide. The action registry includes network-specific rules. See the Multisite section above.

= What problem does Sudo solve? =

Sudo defeats or severely limits the damage attackers can do if they hijack an authenticated session or successfully exploit a vulnerability. Session theft via stolen cookies, unattended devices, broken access control exploits, and credential stuffing all produce a valid session. Sudo means a valid session alone is not enough — every destructive action still requires identity confirmation at the moment of execution.

= How is Sudo different from WordPress security plugins? =

No existing security plugin gates actions that authenticated users can take. Conventional plugins focus on perimeter defense — rate-limiting, firewalling, malware scanning. Sudo operates at the final point of consequence: between an authenticated session and the destructive action it might take. A stolen cookie, a compromised account, an exploited plugin vulnerability — Sudo is the layer that still requires password confirmation before damage can be done.

= What are Sudo's limitations? =

Sudo does not protect against an attacker who already knows your WordPress password and 2FA one-time password — someone who possesses all credentials can complete the sudo challenge just as the real user can. It also does not protect against direct database access or file system operations that bypass WordPress hooks. For a full account of what Sudo does and does not defend against, see the Security Model documentation on GitHub.

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

Architecture: a single SPL autoloader maps the WP_Sudo\* namespace to includes/class-*.php. The Gate class detects the entry surface (admin UI, AJAX, REST, WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL), matches the incoming request against a registry of 29+ rules, and challenges, soft-blocks, or hard-blocks based on surface and policy. All gating decisions happen server-side in PHP hooks — JavaScript is used only for UX.

Testing: the suite is split into two tiers. Unit tests (494 tests, 1286 assertions) use Brain\Monkey to mock WordPress functions and run in ~0.4s. Integration tests (135 tests) run against real WordPress + MySQL and cover full reauth flows, AJAX and REST gating, Two Factor interaction, multisite isolation, uninstall cleanup, and all 9 audit hooks.

CI: GitHub Actions runs PHPStan level 6 and PHPCS on every push and PR, the full test matrix across PHP 8.1-8.4 and WordPress latest + trunk, and a nightly scheduled run against WordPress trunk.

Extensibility: the action registry is filterable via wp_sudo_gated_actions. Nine audit hooks cover session lifecycle, gated actions, policy decisions, and lockouts. See the GitHub repository for hook reference, CONTRIBUTING.md, and the full developer documentation.

== Screenshots ==

1. Challenge page — reauthentication interstitial with password field.
2. Two-factor authentication — after password confirmation, users with 2FA enabled enter their authentication code.
3. Settings page — configure session duration and entry point policies.
4. Gate notice (plugins) — when no sudo session is active, a persistent notice links to the challenge page.
5. Gate notice (themes) — the same gating notice appears on the themes page.
6. Gated actions — the settings page lists all gated operations with their categories and surfaces.
7. Active sudo session — the admin bar shows a green countdown timer.

== Changelog ==

= Unreleased =
* **WordPress 7.0 release prep** — repinned forward CI and local preview lanes to `7.0-RC1`, recorded RC1 visual signoff in `tests/MANUAL-TESTING.md`, and documented the remaining RC2/RC3/GA checklist so final 7.0 readiness can be closed out cleanly.
* **Fix: lockout-expiry recovery on the challenge page** — corrected an edge case where the countdown could reach zero but the server still treated the lockout as active for that exact second, blocking the immediate retry. Password and IP lockouts now expire in sync with the visible countdown, and browser coverage verifies recovery after the countdown ends.
* **Tests: expanded 2FA recovery replay coverage** — browser coverage now verifies stash replay after 2FA lockout expiry for GET and POST actions, and covers the provider resend branch before a later successful replay.
* **Testing workflow: compatibility breadth expansion** — added a scheduled WordPress `6.3`–`6.6` integration sweep on PHP `8.1`, plus explicit browser smoke workflows for nginx + php-fpm + MariaDB and Playground SQLite.
* **Tests: alternate-stack smoke coverage** — added a reusable Playwright smoke pack that proves admin load, session-only challenge success, and stashed settings POST replay across the default Apache stack and the new nginx/SQLite lanes.
* **Tests: dedicated multisite alternate-stack lane** — added a separate nginx + MariaDB multisite smoke workflow for network-admin challenge cancel and stashed POST replay, and scoped those browser cases to that dedicated lane so the default single-site Playwright suite stays stable.
* **Testing workflow: local integration fallback** — `composer test:integration` now falls back to the running `wp-env` `tests-cli` container when a local `wp-env` rebuild leaves the generated host-side MySQL endpoint stale, while CI keeps using the normal direct PHPUnit path.

= 2.14.0 =
* **Feature: Playwright end-to-end coverage** — added browser-verified challenge, cookie, gate UI, admin bar timer, keyboard shortcut, MU-plugin AJAX, multisite network-admin, and visual-regression coverage to exercise the real user flows around reauthentication.
* **Fix: multisite symlink and network-admin flow hardening** — preserved network-admin return URLs and supported symlinked local multisite installs used in Local and Studio-style development.
* **Fix: bootstrap plugin URL handling** — plugin asset URLs now preserve normal `plugins_url` filtering and custom plugin roots instead of assuming a fixed `/wp-content/plugins/` path.
* **Testing workflow: Local socket support** — `bin/install-wp-tests.sh` can now auto-detect a single Local by Flywheel MySQL socket when TCP MySQL is unavailable, with updated contributor guidance for local integration setup.
* **Repo hygiene** — added GPL license and repository health files, and centralized live test/size counts in `docs/current-metrics.md`.
* **504 unit tests, 1311 assertions. 140 integration tests in CI.**

= 2.13.0 =
* **Feature: IP + user multidimensional rate limiting** — per-IP failed-attempt tracking alongside per-user, with combined lockout policy and enriched `wp_sudo_lockout` hook payload (`type`, IP address).
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
* **Psalm 6.15.1 + Shepherd type coverage** — dual static analysis; 96.7% type inference.
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
