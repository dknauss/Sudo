# Sudo for WordPress

_Defense in depth needs a last layer._ 

Sudo is the only solution that provides control over actions just before they're performed.

**Firewalls guard the house. Security plugins guard the door. Sudo governs what can happen inside the house.**

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress: 6.2–7.0](https://img.shields.io/badge/WordPress-6.2–7.0-0073aa.svg)](https://wordpress.org/)
[![PHP: 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)
[![PHPUnit](https://github.com/dknauss/wp-sudo/actions/workflows/phpunit.yml/badge.svg)](https://github.com/dknauss/wp-sudo/actions/workflows/phpunit.yml)
[![Codecov](https://codecov.io/gh/dknauss/wp-sudo/graph/badge.svg?branch=main)](https://codecov.io/gh/dknauss/wp-sudo)
[![Type Coverage](https://shepherd.dev/github/dknauss/wp-sudo/coverage.svg)](https://shepherd.dev/github/dknauss/wp-sudo)
[![Try in Playground](https://img.shields.io/badge/Try%20it-Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/wp-sudo/main/blueprint.json)

> _**This exploratory plugin is NOT production-ready**. Please [**help test it**](https://github.com/dknauss/wp-sudo/blob/main/tests/MANUAL-TESTING.md) and share your feedback._

<br/><br/>

![Unbreakable Barrier Gate](assets/fuwa-no-seki-narrow.png)

> So full of cracks,  
the barrier gatehouse of Fuwa  
lets both rain and moonlight in —  
quietly exposed, yet enduring.  
  
— [Abatsu-ni](https://en.wikipedia.org/wiki/Abutsu-ni), *Diary of the Waning Moon*  

<br/><br/>

# In 2026, 57% of attacks on WordPress sites target one thing: Access Control.

In 2024, Broken Access Control was the third-largest source of discovered WordPress vulnerabilities ([Patchstack 2025](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2024/#headline-899-17052)). In 2025, it took the #1 position on the [OWASP Top 10](https://owasp.org/Top10/2025/). In 2026, Broken Access Control accounted for 57% of all actual exploitation attempts on WordPress sites ([Patchstack 2026](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/)). These trends indicate attackers are focusing their efforts on the vectors that are difficult to defend against with firewalls but have high rewards: the ability to create admin accounts, install plugins, and change settings. Add Privilege Escalation (20%) and Broken Authentication (3%) — that's 80% of real-world WordPress attacks targeting the operations Sudo gates. 

Approximately half of high-impact WordPress vulnerabilities are exploited within 24 hours — median time to first exploit is 5 hours. When the firewall misses the exploit, the vulnerable plugin hasn't been patched, and the attacker already has a session, Sudo is the last barrier between the break-in and the damage. Plugin installs, user creation, role changes, settings modifications: every potentially destructive action requires reauthentication, regardless of how the attacker got in. 

## Barrier Gate Architecture 門

WordPress has rich access control — roles, capabilities, policies on _who_ can do _what_. It has no native control over _when_ those capabilities and actions can be exercised within a session. Sudo fills that gap. By gating consequential actions behind a mandatory reauthentication step whenever those actions are taken, Sudo inserts a final check against potential intrusion and abuse. If a privileged account has been compromised, as is often the case, by an attacker who does not know its password or control its associated email, Sudo's challenge will prevent them from executing privileged actions. Coupled with two-factor-authentication (2FA), Sudo will limit the blast radius or potential harm of any session compromise — regardless of how that compromise occurred, and regardless of the user's role. **The attack surface becomes a policy decision.**

This is not role-based escalation. Every logged-in user is treated the same: attempt a gated action, get challenged. Sessions are time-bounded and non-extendable, enforcing the zero-trust principle that trust must be continuously earned, never assumed. WordPress capability checks still run after the gate, so Sudo adds a security layer without changing the permission model.

Inspired by the Linux command `sudo` (superuser do), Sudo for WordPress is represented by the gate 門, a [CJK grapheme](https://en.wikipedia.org/wiki/CJK_characters) ([Kangxi radical 169](https://en.wiktionary.org/wiki/%E9%96%80)) that originates in a 3,000-year-old pictograph. At once an entrance, barrier, and threshold, the sign of the gate has endured in East Asian writing systems that descend from the Shang dynasty [oracle bone script](https://en.wikipedia.org/wiki/Oracle_bone_script) — where the gate made its earliest known appearance. It appears in the Kanji character for the Japanese frontier pass or barrier gate — _seki_ ([関](https://en.wiktionary.org/wiki/%E9%96%A2)) or _sekisho_ (関所). These were crucial checkpoints that came into use in medieval times but are associated with the centralization of control they enabled in the early modern Edo period (1603–1868) as a way to control traffic along major highways, such as the [Tōkaidō](https://en.wikipedia.org/wiki/T%C5%8Dkaid%C5%8D_(road)).

### What Gets Gated?

| Category | Operations |
|---|---|
| **Plugins** | Activate, deactivate, delete, install, update |
| **Themes** | Switch, delete, install, update |
| **Users** | Delete, change role, change password, create new user, create application password |
| **File editors** | Plugin editor, theme editor |
| **Critical options** | `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register` |
| **WordPress core** | Update, reinstall |
| **Site data export** | WXR export |
| **WP Sudo settings** | Self-protected — settings changes require reauthentication |
| **Multisite** | Network theme enable/disable, site delete/deactivate/archive/spam, super admin grant/revoke, network settings |

Developers can add custom rules via the `wp_sudo_gated_actions` filter. See [Developer Reference](docs/developer-reference.md).

### How It Works

**Browser requests (admin UI):** The user sees an interstitial challenge page. After entering their password (and 2FA code if configured), the original request is replayed automatically. **AJAX and REST requests** receive a `sudo_required` error; an admin notice on the next page load links to the challenge page.

**Non-interactive requests (WP-CLI, Cron, XML-RPC, Application Passwords, and WPGraphQL if installed):** Configurable per-surface policies with three modes: **Disabled**, **Limited** (default), and **Unrestricted**.

### Security Features

- **Zero-trust architecture** — a valid login session is never sufficient on its own. Dangerous operations require explicit identity confirmation every time.
- **Role-agnostic** — any user attempting a gated action is challenged, including administrators.
- **Full attack surface** — admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, Application Passwords, and WPGraphQL.
- **Session binding** — sudo sessions are cryptographically bound to the browser via a secure httponly cookie token.
- **2FA browser binding** — the two-factor challenge is bound to the originating browser with a one-time challenge cookie.
- **Rate limiting** — 5 failed password attempts trigger a 5-minute lockout.
- **Self-protection** — changes to WP Sudo settings require reauthentication.
- **Server-side enforcement** — gating decisions happen in PHP hooks before action handlers. JavaScript is for UX only.

For the full threat model, boundaries, and environmental considerations, see [Security Model](docs/security-model.md). For a detailed comparison with other reauthentication and sudo approaches, see [Architecture Comparison Matrix](docs/sudo-architecture-comparison-matrix.md).

### Recommended Plugins

- **[Two Factor](https://wordpress.org/plugins/two-factor/)** — Strongly recommended. Makes the sudo challenge a two-step process: password + authentication code (TOTP, email, backup codes). Add **[WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)** for passkey and security key support.
- **[WP Activity Log](https://wordpress.org/plugins/wp-security-audit-log/)** or **[Stream](https://wordpress.org/plugins/stream/)** — Recommended for audit visibility. Sudo fires 9 action hooks covering session lifecycle, gated actions, policy decisions, and lockouts.

### User Experience

- **Admin bar countdown** — a live M:SS timer shows remaining session time. Turns red in the final 60 seconds.
- **Keyboard shortcut** — `Ctrl+Shift+S` / `Cmd+Shift+S` to proactively start a sudo session.
- **Accessible** — WCAG 2.1 AA throughout (screen-reader announcements, ARIA labels, focus management, keyboard support).
- **Contextual help** — 10 help tabs on the settings page.

### MU-Plugin for Early Loading

An optional mu-plugin ensures gate hooks are registered before any other plugin loads. Install it with one click from the settings page.

### Multisite

Settings and sessions are network-wide. The action registry includes 8 additional network admin rules. The Settings page appears under **Network Admin > Settings > Sudo**.

## Installation

1. Upload the `wp-sudo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings > Sudo** to configure session duration and entry point policies.
4. (Optional) Install the mu-plugin from the settings page for early hook registration.
5. (Recommended) Install the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin for two-factor authentication.

## Frequently Asked Questions

### Is this a security plugin or something else?

It's a unique approach to fundamental security architecture that doesn't exist in the WordPress plugin marketplace today.

Sudo provides a unique, skin-tight, first-and-last layer of defense that complements other security layers. 

### What does Sudo protect?

To understand what Sudo protects and defends against, it's important to understand the threat model.

[Patchstack's State of WordPress Security in 2026](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2026/) reports that plugin vulnerabilities are still surging year after year, and they remain the source of nearly all vulnerabilities in the ecosystem.

* Nearly half (46%) of all WordPress plugin vulnerabilities that emerged (11,334) remain unpatched.
* Broken Access Control was far and away the most exploited vulnerability, accounting for 57% of the total attacks on WordPress sites.
* Traditional WAFs are only blocking 12 to 26% of attacks.
* Patchstack has an excellent 93% success rate, but it is not 100%.
* Nearly half of high-impact vulnerabilities were exploited within 24 hours. 
* The average time to exploitation has fallen to just 5 hours.
* Post-breach, the attacker's playbook is predictable:
  * 55% of hacked WordPress sites have malicious admin accounts.
  * Up to 70% have backdoor plugins.
 
Another rising threat that firewalls can't detect or deter is stolen cookies. These are the persistent session cookies that are created when you log into Slack, Gmail, WordPress, and all your other sites and apps. Attackers acquire cookies from devices compromised by phishing with infostealers, keyloggers, and other malware. 

Once an attacker has control of an active user session, they need WordPress to do what they assume it will always do: obey without challenge.

Sudo breaks that assumption — a key link in the attackers' kill chain.

Your perimeter has failed. Your own user account has been compromised. Now what?

What if there was one last layer of defense to issue a challenge?

That's Sudo.

### How does sudo gating work?

When a user attempts a gated action, Sudo intercepts the request at `admin_init`. The original request is stashed, the user is redirected to a challenge page, and after successful reauthentication, the original request is replayed. For AJAX and REST requests, the browser receives a `sudo_required` error and an admin notice links to the challenge page.

### Does this replace WordPress roles and capabilities?

No. Sudo adds a reauthentication layer on top of the existing permission model. WordPress capability checks still run after the gate.

### What about REST API and Application Passwords?

Cookie-authenticated REST requests receive a `sudo_required` error. Application Password requests are governed by a separate policy (Disabled, Limited, or Unrestricted). Individual application passwords can override the global policy from the user profile page.

### What about WP-CLI, Cron, and XML-RPC?

Each has its own three-tier policy: Disabled, Limited (default), or Unrestricted. In Limited mode, gated actions are blocked while non-gated commands work normally.

### Does it support two-factor authentication?

Yes. With the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin, the sudo challenge becomes a two-step process: password + authentication code. Third-party 2FA plugins can integrate via [filter hooks](docs/developer-reference.md).

### Does it work on multisite?

Yes. Settings and sessions are network-wide. The action registry includes network-specific rules. See the Multisite section above.

### Can I extend the list of gated actions?

Yes. Use the `wp_sudo_gated_actions` filter. See [Developer Reference](docs/developer-reference.md) for the rule structure and code examples.

For more questions, see the [full FAQ](FAQ.md).

## Developer Reference

Hook signatures, filter reference, custom rule structure, and testing instructions: [docs/developer-reference.md](docs/developer-reference.md).

### Engineering Practices

WP Sudo is built for correctness and contributor legibility, not just functionality.

**Architecture.** A single SPL autoloader maps the `WP_Sudo\*` namespace to `includes/class-*.php`. The `Gate` class is the heart of the plugin: it detects the entry surface (admin UI, AJAX, REST, WP-CLI, Cron, XML-RPC, Application Passwords, WPGraphQL), matches the incoming request against a registry of 29+ rules, and either challenges, soft-blocks, or hard-blocks depending on surface and policy. All gating decisions happen server-side in PHP action hooks — JavaScript is used only for UX (countdown timer, keyboard shortcut).

**Test-driven development.** New code requires a failing test before production code is written. The suite is split into two deliberate tiers:

- **Unit tests** (460 tests, 1182 assertions) — use [Brain\Monkey](https://brain-wp.github.io/BrainMonkey/) to mock all WordPress functions. Run in ~0.5s with no database. Cover request matching, session state machine, policy enforcement, and hook registration.
- **Integration tests** (130 tests) — run against real WordPress + MySQL via `WP_UnitTestCase`. Cover full reauth flows, bcrypt verification, transient TTL, REST and AJAX gating, Two Factor interaction, multisite session isolation, upgrader migrations, and all 9 audit hooks.

**Static analysis and code style.** PHPStan, Psalm (with WordPress stubs/plugin), and PHPCS (WordPress-Extra + WordPress-Docs + WordPressVIPMinimum) run on every push and pull request via GitHub Actions, alongside the full test matrix (PHP 8.1–8.4, WordPress latest + trunk). A nightly scheduled run catches WordPress trunk regressions early. Type coverage is published to Shepherd on default-branch pushes (`main`/`master`).

**Extensibility.** The action registry is filterable via `wp_sudo_gated_actions`. The plugin fires 9 audit hooks covering session lifecycle, gated actions, policy decisions, and lockouts — designed for integration with activity log plugins. Third-party 2FA plugins integrate via four filter hooks. See [docs/developer-reference.md](docs/developer-reference.md) for the full hook reference.

**Contributing.** See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, the TDD workflow, and code style requirements.

### Project Size

| Component | Size |
|---|---|
| **Production PHP** (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`) | 288 KB · 7,823 lines |
| **Assets** (screenshots, banner images) | 5.0 MB |
| **Tests** (`tests/`) | 648 KB · 15,539 lines |
| **Docs** (`docs/` + root-level md/txt) | 348 KB |
| **Total PHP** (production + tests, excl. vendor) | 23,402 lines |
| **Test-to-production ratio** | 2.0:1 |

No production dependencies. Dev dependencies (PHPUnit, PHPStan, Psalm, PHPCS, Brain\Monkey, Mockery) live in `vendor/` and are not shipped.

*Last updated: 2026-03-05. See CLAUDE.md for the update command.*

## Screenshots

1. **Challenge page** — reauthentication interstitial with password field.

   ![Challenge page](assets/screenshot-1.png?v=2)

2. **Two-factor authentication** — after password confirmation, users with 2FA enabled enter their authentication code.

   ![Two-factor authentication](assets/screenshot-2.png?v=3)

3. **Settings page** — configure session duration and entry point policies.

   ![Settings page](assets/screenshot-3.png?v=2)

4. **Gate notice (plugins)** — when no sudo session is active, a persistent notice links to the challenge page.

   ![Gate notice on plugins page](assets/screenshot-4.png?v=2)

5. **Gate notice (themes)** — the same gating notice appears on the themes page.

   ![Gate notice on themes page](assets/screenshot-5.png?v=2)

6. **Gated actions** — the settings page lists all gated operations with their categories and surfaces.

   ![Gated actions table](assets/screenshot-6.png?v=2)

7. **Active sudo session** — the admin bar shows a green countdown timer.

   ![Active sudo session](assets/screenshot-7.png?v=2)

## Changelog

### 2.10.2

- **Fix: multisite uninstall orphaned MU-plugin shim and user meta** — network-activated uninstall now unconditionally cleans all sites and network-wide data.
- **Fix: `wp_sudo_version` option not deleted on uninstall** — orphan option row left after single-site and multisite deletion.
- **Fix: `Admin::get()` TypeError on PHP 8.2+** — corrupted settings no longer crash; falls back to defaults.
- **Fix: `Gate::matches_rest()` crash on invalid third-party regex** — new `safe_preg_match()` wrapper fails closed.
- **Psalm 6.15.1 + Shepherd type coverage** — dual static analysis alongside PHPStan; 96.7% type inference.
- **Codecov integration** — unit test coverage uploaded on CI.
- **16 new unit tests** — CLI cron-policy, network activation, network settings save, admin bar, transient failures, cookie/token edges, 2FA provider.
- **428 unit tests, 1043 assertions. 92 integration tests in CI.**

### 2.10.1

- **Fix: accessibility audit follow-up** — admin bar countdown polish, docs alignment.

### 2.10.0

- **Feature: WebAuthn gating bridge** — gates WebAuthn key registration/deletion when the Two Factor WebAuthn plugin is active.
- **Fix: MU-plugin shim respects deactivation** — loader checks `active_plugins` before loading; inert when deactivated.
- **Fix: WP 7.0 notice CSS, 2FA window clamping, app-password JS localization.**
- **REST `_wpnonce` fallback** — accepts query parameter when cookie nonce header absent.
- **Exit path integration tests** — REST 403, AJAX 403, admin redirect, challenge auth, grace window.
- **397 unit tests, 944 assertions. 92 integration tests in CI.**

### 2.9.2

- **Fix: 2FA help text corrected** — Settings help tab said "10 minutes"; code default is 5 minutes.
- **Fix: version constant drift** — bootstrap files synced to 2.9.2.
- **397 unit tests, 944 assertions.**

### 2.9.1

- **Docs: threat model kill chain** — verified risk reduction data from Patchstack, Sucuri, Verizon DBIR, Wordfence, and OWASP added to security model and FAQ.
- **Docs: project size table** — readme.md gains a Project Size subsection; stale test counts corrected; missing v2.8.0/v2.9.0 changelog entries added.

### 2.9.0

- **`wp_sudo_action_allowed` audit hook** — fires when a gated action is permitted by an Unrestricted policy. Covers all five non-interactive surfaces: REST App Passwords, WP-CLI, Cron, XML-RPC, and WPGraphQL (mutations only). This is the ninth audit hook.
- **Docs: CLAUDE.md accuracy audit** — corrected six inaccuracies; logged one confabulation in `llm_lies_log.txt`.
- **Docs: manual testing** — MANUAL-TESTING.md adds §19 (Unrestricted audit hook verification) with forward references from existing Unrestricted subsections.
- **397 unit tests, 944 assertions. 92 integration tests in CI.**

### 2.8.0

- **Expire sudo session on password change** — hooks `after_password_reset` and `profile_update` to invalidate any active sudo session when a user's password changes. Closes the gap where a compromised session persisted after a password reset.
- **WPGraphQL conditional display** — the WPGraphQL policy dropdown, help tab paragraph, and Site Health review all adapt based on whether WPGraphQL is installed.
- **391 unit tests, 929 assertions. 92 integration tests in CI.**

### 2.7.0

- **`wp_sudo_wpgraphql_bypass` filter** — new filter for WPGraphQL JWT authentication compatibility. Fires in Limited mode before mutation detection; return `true` to exempt specific requests (e.g., JWT login/refresh mutations). See [developer reference](docs/developer-reference.md#wp_sudo_wpgraphql_bypass-filter) for a bridge mu-plugin example.
- **Fix: WPGraphQL listed in non-interactive entry points** — the "How Sudo Works" help tab now includes WPGraphQL in the list of policy-governed surfaces.

### 2.6.1

- **Fix: WPGraphQL integration tests** — extract `Gate::check_wpgraphql()` to fix pre-existing CI test regression; no behavioral change in production.
- **Docs: v2.6.0 documentation update** — FAQ, ROADMAP, developer-reference.md, security-model.md, MANUAL-TESTING.md updated to reflect v2.6.0 features.

### 2.6.0

- **Login implicitly grants a sudo session** — a successful browser-based login now automatically activates a sudo session. No second challenge is required immediately after logging in. Application Password and XML-RPC logins are unaffected.
- **`user.change_password` gated** — password changes on the profile and user-edit pages now require a sudo session. Closes the session-theft → silent password change → lockout attack chain. The REST API endpoint is also gated.
- **Grace period (120 s)** — a 2-minute grace window after session expiry lets in-flight form submissions complete without triggering a re-challenge. Session binding is verified throughout the grace window.
- **375 unit tests, 905 assertions. 73 integration tests in CI.**

### 2.5.0

- **WPGraphQL surface gating** — adds WPGraphQL as a fifth non-interactive surface alongside WP-CLI, Cron, XML-RPC, and Application Passwords. Three-tier policy (Disabled / Limited / Unrestricted); default is Limited. GraphQL mutations are blocked without a sudo session; read-only queries pass through. Fires `wp_sudo_action_blocked` on block.
- **Site Health** — WPGraphQL policy included in Entry Point Policies health check.
- **364 unit tests, 887 assertions. 73 integration tests in CI.**

### 2.4.1

- **AJAX gating integration tests** — 11 new tests covering the AJAX surface: rule matching for all 7 declared AJAX actions, full intercept flow, session bypass, non-gated pass-through, blocked transient lifecycle, admin notice fallback (`render_blocked_notice`), and `wp.updates` slug passthrough.
- **Action registry filter integration tests** — 3 new tests verifying custom rules added via `wp_sudo_gated_actions` are matched by the Gate in a real WordPress environment, including custom admin rules, custom AJAX rules, and filter-based removal of built-in rules.
- **Audit hook coverage** — `wp_sudo_action_blocked` now integration-tested for CLI, Cron, and XML-RPC surfaces (in addition to REST app-password). Documents that `wp_sudo_action_allowed` is intentionally absent from the production code path.
- **CI quality gate** — new GitHub Actions job runs PHPCS and PHPStan on every push and PR; Composer dependency cache added to unit and integration jobs; nightly scheduled run at 3 AM UTC against WP trunk.
- **MU-plugin manual install instructions** — fallback copy instructions added to the settings page UI and help tab for environments where the one-click installer fails due to file permissions.
- **CONTRIBUTING.md** — new contributor guide covering local setup, unit vs integration test distinction, TDD workflow, and lint/analyse requirements.
- **349 unit tests, 863 assertions. 73 integration tests in CI.**

### 2.4.0

- **Integration test suite** — 55 tests against real WordPress + MySQL (session lifecycle, request stash/replay, full reauth flow, REST gating, upgrader migrations, Two Factor interaction, multisite isolation).
- **CI pipeline** — GitHub Actions with unit tests across PHP 8.1–8.4 and integration tests against WordPress latest + trunk.
- **Fix: multisite site-management gate gap** — Archive, Spam, Delete, Deactivate site actions now correctly trigger the sudo challenge.
- **Fix: admin bar timer width** — expiring (red) state no longer stretches wider than active (green) state.
- **Fix: WP 7.0 admin notice background** — restored white background lost in WP 7.0's admin visual refresh.
- **Fix: 2FA countdown advisory-only** — window reduced to 5 minutes; expired codes accepted if provider validates.
- **WP 7.0 Beta 1 tested** — full manual testing guide completed, all 15 sections PASS.
- **349 unit tests, 863 assertions. 55 integration tests in CI.**

### 2.3.2

- **Fix: admin bar sr-only text leak** — screen-reader-only milestone text no longer renders in the dashboard canvas when the admin bar node lacks a containing block.
- **Documentation overhaul** — readmes slimmed; [security model](docs/security-model.md), [developer reference](docs/developer-reference.md), [FAQ](FAQ.md), and [full changelog](CHANGELOG.md) moved to project root. [Manual testing guide](tests/MANUAL-TESTING.md) rewritten for v2.3.1+.
- **Composer lock compatibility** — `config.platform.php` set to `8.1.99` so the lock file resolves for PHP 8.1+ regardless of local version.
- **Housekeeping** — removed stale project state file; added `@since` tags; updated CLAUDE.md and Copilot instructions with docs/ references.
- **343 unit tests, 853 assertions.**

### 2.3.1

- **Fix: Unicode escape rendering** — localized JS strings now use actual UTF-8 characters, fixing visible backslash-escape text during challenge replay.
- **Fix: screen-reader-only text flash** — the sr-only span no longer flashes visible fragments during replay.
- **CycloneDX SBOM** — `bom.json` shipped for supply chain transparency.
- **Help tabs** — per-application-password policy section added. Count corrected to 8.
- **Copilot coding agent** — GitHub Copilot configuration added.
- **Accessibility roadmap complete** — all items verified resolved.
- **343 unit tests, 853 assertions.**

### 2.3.0

- **Per-application-password sudo policies** — individual Application Password credentials can override the global REST API policy.
- **Challenge page iframe fix** — breaks out of `wp_iframe()` context.
- **Accessibility improvements** — admin bar cleanup on page unload; lockout countdown SR throttling; settings field defaults.
- **PHPStan level 6 static analysis** — zero errors.
- **Documentation** — [AI and agentic tool guidance](docs/ai-agentic-guidance.md) and [UI/UX testing prompts](docs/ui-ux-testing-prompts.md).
- **343 unit tests, 853 assertions.**

See [full changelog](CHANGELOG.md) for all versions.
