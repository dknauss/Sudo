# Changelog

## 3.0.0

### Headline changes

- **Major milestone: operator tooling and visibility** — WP Sudo now includes a **Request / Rule Tester** for representative admin, AJAX, and REST request shapes plus a **Session Activity Dashboard Widget** for active sessions, recent events, and current policy posture.
- **Major milestone: policy control** — Settings → Sudo now includes one-click **Normal**, **Incident Lockdown**, and **Headless Friendly** presets for the non-interactive surfaces, with confirmation, audit logging, and summary notices.
- **Major milestone: ecosystem hardening** — Connectors API credential writes saved through `/wp/v2/settings` now require sudo when they include `connectors_*_api_key` fields, protecting database-backed connector credentials without over-gating unrelated settings writes.

### New platform capabilities

- **Event persistence layer** — audit events are now recorded through `Event_Store` and `Event_Recorder`, enabling the dashboard widget and future reporting. The shared `wpsudo_events` table includes 14-day retention, daily cron pruning, graceful degradation when the table is unavailable, and SQLite compatibility for Playground-style environments.

### Security and recovery hardening

- **Challenge lockout expiry recovery** — corrected an edge case where the visible countdown could reach zero while the server still treated the lockout as active for that exact second, blocking an immediate retry. Password and IP lockouts now expire in sync with the countdown.
- **Stale challenge and 2FA recovery flows** — hardened recovery when a sudo session is already active or a user is returning from 2FA throttle/lockout flows, with expanded browser coverage for replay, resend, cancel, and recovery behavior.

### Dashboard widget UX

- **Active sessions: identity context** — sessions panel now shows gravatars, username, role badge, display name, and time remaining for each active session. Responsive layout hides gravatars and names on small screens.
- **Recent events: client-side filtering** — dropdown filters for Time (1h / 24h / 7d), Event type, and Surface, applied client-side against 50 stored events. Filters laid out horizontally in a single row.
- **Passed-event audit visibility defaults** — `wp_sudo_action_passed` events (admin, REST, WPGraphQL) are now recorded by default so active-session actions stay visible in the audit timeline. Disabling passed-event logging now requires an explicit code override (constant/filter), and WP Sudo shows a warning notice when that override is active.
- **Widget placement and layout** — widget renders in the side column at high priority, active session cards use CSS Grid (`repeat(auto-fit, minmax(180px, 1fr))`) with scrollable container, usernames link to user-edit.php, and the empty-state panel now uses a clearer Site Health–style status layout.
- **Users list "Sudo Active" filter** — the Users → All Users screen gains a "Sudo Active (N)" view link that filters the list to users with an active sudo session via `_wp_sudo_expires` meta query.

### Accessibility

- **Dashboard widget table semantics** — added `scope="col"` to table headers and screen-reader-only `<caption>` elements for the Recent Events and Policy Summary tables.

### Compatibility and testing

- **WordPress 7.0 readiness** — forward test and preview lanes are now pinned to `7.0-RC1`, with RC1 visual signoff recorded and the remaining RC/GA checklist documented for final release-day verification.
- **Testing and compatibility breadth** — added scheduled WordPress `6.3`–`6.6` compatibility coverage, explicit nginx + php-fpm + MariaDB and Playground SQLite browser smoke workflows, and a dedicated nginx + MariaDB multisite smoke lane.
- **Testing workflow: local integration fallback** — `composer test:integration` now falls back to the running `wp-env` `tests-cli` container when a local rebuild leaves the generated host-side MySQL endpoint stale, while CI continues to use the normal direct PHPUnit path.
- **Testing posture:** expanded CI and browser coverage shipped with this release; live suite counts are tracked in `docs/current-metrics.md`.

## 2.14.0

- **Feature: Playwright end-to-end coverage** — added browser-verified challenge, cookie, gate UI, admin bar timer, keyboard shortcut, MU-plugin AJAX, multisite network-admin, and visual-regression coverage to exercise the real user flows around reauthentication.
- **Fix: multisite symlink and network-admin flow hardening** — preserved network-admin return URLs and supported symlinked local multisite installs used in Local and Studio-style development.
- **Fix: bootstrap plugin URL handling** — plugin asset URLs now preserve normal `plugins_url` filtering and custom plugin roots instead of assuming a fixed `/wp-content/plugins/` path.
- **Testing workflow: Local socket support** — `bin/install-wp-tests.sh` can now auto-detect a single Local by Flywheel MySQL socket when TCP MySQL is unavailable, with updated contributor guidance for local integration setup.
- **Repo hygiene** — added GPL license and repository health files, and centralized live test/size counts in `docs/current-metrics.md`.
- **504 unit tests, 1311 assertions. 140 integration tests in CI.**

## 2.13.0

- **Feature: IP + user multidimensional rate limiting** — failed authentication attempts are now tracked per-IP alongside per-user. When the same IP address triggers failures across multiple user accounts, the IP itself is locked out, mitigating credential-stuffing attacks that rotate usernames. The `wp_sudo_lockout` hook now includes the triggering IP address as a third positional argument (`$user_id, $attempts, $ip`) for audit visibility.
- **Docs alignment** — updated `security-model.md` with the new rate-limiting dimensions and `developer-reference.md` with the enriched lockout hook payload schema. Manual testing guide expanded with IP-based lockout verification steps.
- **496 unit tests, 1293 assertions. 132 integration tests in CI.**

## 2.12.0

- **Feature: WP-CLI operator commands** — added `wp sudo status`, `wp sudo revoke --user=<id>`, and `wp sudo revoke --all` for session inspection and revocation workflows.
- **Feature: Stream audit bridge** — added optional `bridges/wp-sudo-stream-bridge.php`, mapping all 9 WP Sudo audit hooks into Stream records. Bridge remains inert when Stream APIs are unavailable and supports late plugin load order.
- **Feature: public integration API (`wp_sudo_check()` / `wp_sudo_require()`)** — added first-party helpers for third-party plugins/themes to require an active sudo session without registering full action rules. `wp_sudo_require()` can redirect to the challenge page in session-only mode (or return `false` when redirecting is disabled/unavailable) and emits `wp_sudo_action_gated` with surface `public_api`.
- **Docs: release alignment** — updated developer reference and manual testing docs for Stream bridge and public API helpers; refreshed roadmap and contributing guidance for current development priorities and repo-local integration test paths.
- **Pre-release hygiene** — regenerated `bom.json`.
- **494 unit tests, 1286 assertions. 135 integration tests in CI.**

## 2.11.1

- **Docs release + metadata alignment** — corrected post-v2.11.0 documentation drift: roadmap completion markers, RC re-test guidance, and release notes alignment across `CHANGELOG.md`, `readme.md`, and `readme.txt`.
- **Version annotation fixes** — corrected `@since` annotations introduced in the v2.11.0 development cycle so Phase 3/4 additions no longer reference the nonexistent `2.10.3` version.
- **Pre-release hygiene** — regenerated `bom.json` and updated ignore rules to keep `.planning/private-reference/`, `.composer_cache/`, and `vendor_test/` out of commits.
- **478 unit tests, 1228 assertions. 130 integration tests in CI.**

## 2.11.0

- **Phase 3 complete: Action Registry schema validation hardening** — filtered `wp_sudo_gated_actions` rules are now normalized and validated before caching, preventing malformed third-party payloads from reaching gate matchers.
- **Phase 3 complete: MU-loader resilience** — loader basename/path resolution now follows an explicit fallback chain and correctly respects active plugin state in single-site and multisite environments.
- **Phase 4 complete: WPGraphQL persisted-query strategy** — GraphQL policy behavior was tightened and documented for persisted-query/headless setups, with expanded integration coverage of mutation classification and bypass behavior.
- **Phase 4 complete: WSAL sensor bridge** — added `bridges/wp-sudo-wsal-sensor.php`, mapping all 9 WP Sudo audit hooks to WP Activity Log events for security telemetry integration.
- **Housekeeping: Admin bar class cleanup** — docblock trimming, explicit `$accepted_args` on hook registrations, no behavioral changes.
- **Docs and planning closure** — phase summaries and roadmap/planning artifacts updated to reflect completion across Phases 1–4 of the security hardening sprint.
- **478 unit tests, 1228 assertions. 130 integration tests in CI.**

## 2.10.2

- **Fix: multisite uninstall orphaned MU-plugin shim and user meta** — when a network-activated plugin was uninstalled, the early-return path skipped `wp_sudo_cleanup_mu_shim()` and `wp_sudo_cleanup_user_meta()`, leaving the shim file and session metadata in the database after plugin deletion. Multisite uninstall now unconditionally cleans all sites and all network-wide data.
- **Fix: `wp_sudo_version` option not deleted on uninstall** — `wp_sudo_cleanup_site()` deleted four options but missed `wp_sudo_version`, leaving an orphan row. Also added the missing `delete_site_option( 'wp_sudo_role_version' )` to the multisite network cleanup path.
- **Fix: `Admin::get()` TypeError on PHP 8.2+ with corrupted settings** — `get_option()` returning `false` (from corrupted serialized data) was assigned to a `?array` typed property, causing a TypeError. Now validates the return with `is_array()` and falls back to defaults.
- **Fix: `Gate::matches_rest()` crash on invalid third-party regex** — third-party filters on `wp_sudo_gated_actions` could inject rules with malformed regex patterns, causing `preg_match()` warnings. New `safe_preg_match()` wrapper catches the warning and fails closed (rule does not match).
- **Psalm 6.15.1 static analysis** — added alongside PHPStan for dual static analysis. Psalm surfaced the `absint()` → `(int)` cast fix in Admin and the `wp_safe_redirect()` fallback in Gate (both shipped in v2.10.0 but caught by Psalm). Type coverage published to Shepherd.dev on default-branch pushes.
- **Codecov integration** — unit test coverage uploaded to Codecov on CI runs.
- **16 new unit tests** closing gaps in CLI cron-policy enforcement, network activation lifecycle, network admin settings save, admin bar deactivation handler, transient storage failures, cookie/token edge cases, and 2FA provider availability.
- **Dependency bumps** — PHPStan 2.1.40, Yoast PHPUnit Polyfills 4.0.0, actions/checkout v6, actions/cache v5, actions/upload-artifact v7, actions/github-script v8.
- **CodeQL and Dependabot** — CodeQL JavaScript security scanning enabled; Dependabot version updates for Composer and GitHub Actions.
- **428 unit tests, 1043 assertions. 92 integration tests in CI.**

## 2.10.1

- **Fix: accessibility audit follow-up** — admin bar countdown polish, docs alignment.
- **397 unit tests, 944 assertions. 92 integration tests in CI.**

## 2.10.0

- **Feature: WebAuthn gating bridge** — gates WebAuthn key registration and deletion via `wp_sudo_gated_actions` filter when the Two Factor WebAuthn plugin is active.
- **Fix: WP 7.0 notice CSS** — corrected admin notice styling for WordPress 7.0 compatibility.
- **Fix: MU-plugin shim respects deactivation** — the loader now checks `active_plugins` / `active_sitewide_plugins` before loading the plugin; inert when deactivated.
- **Fix: localize app-password JS** — moved inline script to localized data; paginate stale sessions in Site Health; fix return_url handling.
- **Fix: clamp 2FA window filter** — `wp_sudo_two_factor_window` filter output clamped to documented 1–15 minute bounds.
- **REST `_wpnonce` fallback** — Gate accepts `_wpnonce` query parameter for REST authentication when cookie nonce header is absent.
- **Exit path integration tests** — new test suite for security-critical exit paths (REST 403, AJAX 403, admin redirect, challenge auth, grace window).
- **PCOV coverage CI job** — unit test coverage generation added to CI pipeline.
- **Docs: NIST SP 800-63B terminology alignment** — reauthentication language updated throughout.
- **397 unit tests, 944 assertions. 92 integration tests in CI.**

## 2.9.2

- **Fix: 2FA help text corrected** — `includes/class-admin.php` displayed "The default 2FA window is 10 minutes" but the code default (set in v2.4.0) is `5 * MINUTE_IN_SECONDS`. Help text now reads "5 minutes". The sudo session countdown (admin bar) is a separate, unrelated timer that remains at 15 minutes.
- **Fix: version constant drift in dev bootstrap files** — `phpstan-bootstrap.php` and `tests/bootstrap.php` both defined `WP_SUDO_VERSION = '2.8.0'` while the runtime plugin was at v2.9.1. Both bumped to `'2.9.2'`.
- **Docs: readme.txt expanded** — Patchstack 2026 attack statistics (57% BAC, 80% sudo-mitigated, 5 h median exploit time) added to the Description section. Eight new FAQ entries added: what problem Sudo solves, how it differs from security plugins, limitations, brute-force protection, login session grant, password change behaviour, grace period, and the 2FA verification window. Integration and unit test counts corrected.
- **397 unit tests, 944 assertions.**

## 2.9.1

- **Docs: threat model kill chain** — `docs/security-model.md` gains a new "Threat Model: The Kill Chain" section with verified statistics from Patchstack (2024 vulnerability breakdown), Sucuri (post-compromise forensics), Verizon DBIR (credential attacks), Wordfence (55B password attacks blocked), and OWASP Top 10:2025 (Broken Access Control #1). Risk reduction estimates table included. `FAQ.md` adds a condensed "Why this matters by the numbers" paragraph. All statistics verified 2026-02-27 against primary sources.
- **Docs: project size table** — `readme.md` gains a "Project Size" subsection (6,688 production lines, 11,555 test lines, 1.7:1 ratio). `CLAUDE.md` gains verification commands and a pre-release checklist note to keep the table current. Stale test counts in `readme.md` corrected (375/905 → 397/944, 73 → 92 integration). Missing v2.8.0 and v2.9.0 changelog entries added to `readme.md`.
- **397 unit tests, 944 assertions.**

## 2.9.0

- **Feature: `wp_sudo_action_allowed` audit hook** — fires when a gated action is permitted by an Unrestricted policy. Covers all five non-interactive surfaces: REST App Passwords (`$user_id, $rule_id, 'rest_app_password'`), WP-CLI (`0, $rule_id, 'cli'`), Cron (`0, $rule_id, 'cron'`), XML-RPC (`0, $rule_id, 'xmlrpc'`), and WPGraphQL (`$user_id, 'wpgraphql', 'wpgraphql'` — mutations only). WPGraphQL queries do not fire the hook. Implemented by adding an `'audit'` mode to `register_function_hooks()` for CLI/Cron/XML-RPC, and inline `do_action()` calls for REST and WPGraphQL. This is the ninth audit hook.
- **Docs: CLAUDE.md accuracy audit** — corrected six inaccuracies: policy names, missing doc reference, missing password-change hooks in bootstrap sequence, rule count ambiguity, and hook count. Logged one confabulation (fabricated `wp_sudo_action_allowed` documentation in `ai-agentic-guidance.md`) in `llm_lies_log.txt`.
- **Docs: manual testing** — MANUAL-TESTING.md adds §19 (Unrestricted audit hook verification for all five surfaces) with forward references from existing Unrestricted subsections.
- **397 unit tests, 944 assertions.**

## 2.8.0

- **Feature: expire sudo session on password change** — hooks `after_password_reset` (lost-password flow) and `profile_update` (admin profile, user-edit, REST API) to invalidate any active sudo session when a user's password changes. Handlers guard with a meta-existence check before calling `deactivate()` to avoid phantom `wp_sudo_deactivated` audit events for users without sessions. Closes the gap where a compromised session persisted after a password reset.
- **Feature: WPGraphQL conditional display** — the WPGraphQL policy dropdown on Settings > Sudo, the WPGraphQL paragraph in the "Session & Policies" help tab, and the Site Health policy review all adapt based on whether WPGraphQL is installed. When inactive: the dropdown is hidden, the help tab shows an install note instead of the full explanation, and Site Health does not flag the WPGraphQL policy.
- **Docs: WPGraphQL surface-level gating rationale** — `docs/developer-reference.md` Rule Structure section now explains why WPGraphQL is gated at the surface level rather than per-action, with a forward reference to the WPGraphQL Surface section.
- **Docs: manual testing additions** — MANUAL-TESTING.md adds §16.0 (WPGraphQL conditional behavior when plugin inactive) and §18 (password change expires sudo session — three scenarios).
- **391 unit tests, 929 assertions.**

## 2.7.0

- **Feature: `wp_sudo_wpgraphql_bypass` filter** — fires in Limited mode before mutation detection. Return `true` to allow a request through without sudo session checks. Solves compatibility with [wp-graphql-jwt-authentication](https://github.com/wp-graphql/wp-graphql-jwt-authentication): the JWT `login` mutation is sent by unauthenticated users and was blocked by the default Limited policy, breaking the entire JWT authentication flow. A documented bridge mu-plugin exempts `login` and `refreshJwtAuthToken` mutations while keeping all other mutations gated. The filter does not fire in Disabled or Unrestricted mode.
- **Fix: WPGraphQL now listed in non-interactive entry points** — the "How Sudo Works" help tab text omitted WPGraphQL from the list of non-interactive surfaces.
- **379 unit tests, 915 assertions.**

## 2.6.1

- **Fix: WPGraphQL integration tests now call `check_wpgraphql()` directly** — `Gate::check_wpgraphql( string $body ): ?WP_Error` extracted from `gate_wpgraphql()` so integration tests can exercise the policy logic without WPGraphQL installed or `wp_send_json()`/`exit` side effects. No behavioral change in production. Fixes a pre-existing CI regression introduced in v2.5.1 when WPGraphQL gating moved from `rest_request_before_callbacks` to `graphql_process_http_request`.
- **Docs: full documentation update for v2.6.0** — FAQ, ROADMAP, readme.txt, readme.md, developer-reference.md, security-model.md, MANUAL-TESTING.md, CLAUDE.md updated to reflect all v2.6.0 features (login grant, password-change gating, grace period).
- **375 unit tests, 905 assertions. 73 integration tests in CI.**

## 2.6.0

- **Feature: login implicitly grants a sudo session** — a successful WordPress browser-based login (via `wp_login`) now automatically activates a sudo session. The user just proved their identity via the login form; requiring a second challenge immediately is unnecessary friction. This mirrors the behaviour of Unix `sudo` and GitHub's sudo mode. Application Password and XML-RPC logins are unaffected (`wp_login` does not fire for those). Implemented in `Plugin::grant_session_on_login()`.
- **Feature: `user.change_password` gated action** — changing a user's password on `profile.php` or `user-edit.php` now requires an active sudo session. The rule fires only when `pass1` or `pass2` is present in the POST body, narrowing it to actual password changes (not bio, email, or role updates, which also use `action=update`). The REST counterpart gates any `PUT`/`PATCH` to `/wp/v2/users/{id}` or `/wp/v2/users/me` that includes a `password` parameter. Closes the "session theft → silent password change → lockout" attack chain.
- **Feature: grace period (two-tier expiry)** — sudo sessions now have a 120-second grace window (`GRACE_SECONDS = 120`) after they expire. If a user was filling in a form while the session expired, the form submission still passes the gate without requiring re-authentication. The grace window is session-token-verified — a stolen cookie in a different browser does not gain grace access. Session meta cleanup is deferred until the grace window closes so the token is available for verification throughout. The four admin-bar UI call sites are intentionally excluded (the timer always reflects the true session state).
- **375 unit tests, 905 assertions. 73 integration tests in CI.**

## 2.5.2

- **Fix: WPGraphQL Limited policy now blocks unauthenticated mutations** — cross-origin requests (e.g. from a SvelteKit frontend) do not carry WordPress session cookies, so `get_current_user_id()` returns 0. Previously the Limited policy silently passed these through via an `if (!$user_id) return` guard before the session check was reached. Now, unauthenticated mutations are blocked with the same `sudo_blocked` 403 response as authenticated-without-session mutations. The `$user_id &&` short-circuit prevents `Sudo_Session::is_active()` from ever being called with user 0.
- **Fix: per-application-password policy dropdown now renders on profile pages** — two bugs prevented the dropdown column from appearing: (1) the JS looked for `.application-passwords-list-table` which does not exist; the table lives inside `.application-passwords-list-table-wrapper`. (2) UUID extraction tried to read `data-slug` from the revoke button, which carries no data attributes; WordPress already sets `data-uuid` directly on each `<tr>`. Both are now corrected.
- **Fix: `user.promote` rule now fires on bulk role changes** — the Action Registry `user.promote` rule declared `'method' => 'GET'`, but WordPress's bulk role change action on `users.php` POSTs the request. Changing the method to `ANY` ensures both the single-user edit and bulk promote paths are gated.
- **Security: sudo session required for MU-plugin install/uninstall** — `handle_mu_install()` and `handle_mu_uninstall()` only checked capability; now they also require an active sudo session (returns `sudo_required` 403 if absent).
- **Security: sudo session required for per-app-password policy save** — `handle_app_password_policy_save()` only checked capability; now also requires an active sudo session.
- **UX: per-app-password dropdown surfaces `sudo_required` response** — when the policy save AJAX returns `sudo_required`, the dropdown restores its previous value, shows an amber outline, and displays an alert explaining that a sudo session is needed.
- **Docs: WPGraphQL persisted queries caveat** — corrected the absolute claim in `security-model.md` that the mutation heuristic "cannot false-negative on an actual GraphQL mutation"; it cannot detect mutations sent via the Persisted Queries extension. Added explicit guidance to use the Disabled policy in persisted-query environments.
- **Docs: WPGraphQL headless authentication boundary** — added a new subsection to `security-model.md` explaining why Limited behaves identically to Disabled for most cross-origin headless deployments, with per-deployment policy recommendations. Cross-referenced from a new `## WPGraphQL Surface` section in `docs/developer-reference.md`.
- **361 unit tests, 882 assertions. 73 integration tests in CI.**

## 2.5.1

- **Fix: WPGraphQL gating now functional** — v2.5.0 hooked into `rest_request_before_callbacks`, but WPGraphQL dispatches requests via WordPress rewrite rules at `parse_request`, not through the REST API pipeline. The REST filter never fired for GraphQL requests. The fix hooks into WPGraphQL's own `graphql_process_http_request` action, which fires after authentication but before body reading, regardless of how the endpoint is named or configured. No endpoint URL matching is needed.
- **Remove `wp_sudo_wpgraphql_route` filter** — the filter was designed for the now-dead URL-matching approach and has no effect. Removed from the codebase and all documentation.
- **361 unit tests, 881 assertions. 73 integration tests in CI.**

## 2.5.0

- **WPGraphQL surface gating** — adds WPGraphQL as a fifth non-interactive surface alongside WP-CLI, Cron, XML-RPC, and Application Passwords. Three-tier policy (Disabled / Limited / Unrestricted); default is Limited. In Limited mode, GraphQL mutations are blocked without an active sudo session while read-only queries pass through. Fires the `wp_sudo_action_blocked` audit hook on block. The policy setting (`wpgraphql_policy`) is stored regardless of whether WPGraphQL is installed; the settings field is only shown when WPGraphQL is active.
- **Mutation detection heuristic** — Limited mode checks whether the POST body contains the word `mutation`. Intentionally blunt: cannot false-negative on actual mutations, may false-positive on queries mentioning "mutation" in a string argument. Documented in `docs/security-model.md`.
- **`wp_sudo_wpgraphql_route` filter** — allows the gated route to be overridden to match custom WPGraphQL endpoint configurations.
- **Site Health integration** — WPGraphQL policy included in the Entry Point Policies health check (flagged if set to Unrestricted).
- **364 unit tests, 887 assertions. 73 integration tests in CI.**

## 2.4.2

- **Documentation: roadmap consolidation** — Merged three separate roadmaps (`ROADMAP.md`, `ACCESSIBILITY-ROADMAP.md`, `docs/roadmap-2026-02.md`) into one unified `ROADMAP.md` at project root. Moved `CHANGELOG.md` and `FAQ.md` to root for prominence.
- **Planned Development Timeline** — Added comprehensive timeline at the top of ROADMAP.md showing immediate, short-term, medium-term, and deferred work phases. Provides quick reference for what will actually be implemented.
- **Table of Contents** — Added scannable TOC to ROADMAP.md linking to all 10 sections plus appendix.

## 2.4.1

- **AJAX gating integration tests** — 11 new tests covering the AJAX surface: rule matching for all 7 declared AJAX actions, full intercept flow via `wp_doing_ajax` filter, session bypass, non-gated pass-through, blocked transient lifecycle, admin notice fallback (`render_blocked_notice`), and `wp.updates` slug passthrough.
- **Action registry filter integration tests** — 3 new tests verifying custom rules added via `wp_sudo_gated_actions` are matched by the Gate in a real WordPress environment; including custom admin rules, custom AJAX rules, and filter-based removal of built-in rules.
- **Audit hook coverage** — `wp_sudo_action_blocked` now integration-tested for CLI, Cron, and XML-RPC surfaces (in addition to REST app-password). Documents that `wp_sudo_action_allowed` is intentionally absent from the production code path.
- **CI quality gate** — new GitHub Actions job runs PHPCS and PHPStan on every push and PR; Composer dependency cache added to unit and integration jobs; nightly scheduled run at 3 AM UTC catches WordPress trunk regressions.
- **MU-plugin manual install instructions** — fallback copy instructions added to the settings page UI (`<details>` disclosure) and help tab for environments where the one-click installer fails due to file permissions.
- **CONTRIBUTING.md** — new contributor guide covering prerequisites, local setup, unit vs integration test distinction, TDD workflow, and lint/analyse requirements.
- **349 unit tests, 863 assertions. 73 integration tests in CI.**

## 2.4.0

- **Integration test suite** — 55 integration tests running against a real WordPress + MySQL environment via `WP_UnitTestCase`. Covers sudo session lifecycle (bcrypt verification, token binding, rate limiting, expiry), request stash/replay with transient TTL, full reauth flow (5-class end-to-end), REST API gating with cookie auth and application passwords, upgrader migration chain, audit hook arguments, Two Factor plugin interaction, and multisite session isolation.
- **CI pipeline** — GitHub Actions workflow with unit tests across PHP 8.1–8.4 and integration tests against WordPress `latest` and `trunk` (including multisite variant). MySQL 8.0 service container with health checks.
- **Fix: multisite site-management gate gap** — Archive, Spam, Delete, and Deactivate site actions on Network Admin → Sites now correctly trigger the sudo challenge. WordPress core's `sites.php` sends `action=confirm` with the real action in `action2`; the Gate now checks both parameters.
- **Fix: admin bar timer width** — the countdown timer's red (expiring) state no longer stretches wider than the green (active) state. Defensive CSS constrains the background to content width regardless of WP core layout context.
- **Fix: WP 7.0 admin notice background** — restored white background on WP Sudo admin notices, which lost their background color in WP 7.0's admin visual refresh.
- **Fix: 2FA countdown advisory-only** — the two-factor verification window is now advisory (5 minutes, reduced from 10). Expired 2FA codes are still accepted if the underlying provider validates them, preventing false rejections for slow email delivery.
- **Fix: `setcookie()` headers-already-sent guard** — `Sudo_Session::activate()` now checks `headers_sent()` before calling `setcookie()`, preventing warnings in CLI and integration test contexts.
- **Verification requirements** — CLAUDE.md now mandates live source verification for all external code references, with documented verification commands. LLM lies log tracks 5 prior fabrications that were corrected.
- **WP 7.0 Beta 1 tested** — manual testing guide completed against WP 7.0 Beta 1 (15 sections, all PASS). Visual compatibility, help tabs, challenge page, and admin bar verified against the refreshed admin chrome.
- **349 unit tests, 863 assertions. 55 integration tests in CI.**

## 2.3.2

- **Fix: admin bar sr-only text leak** — screen-reader-only milestone text no longer renders in the dashboard canvas. The admin bar `<li>` node now establishes a containing block (`position: relative`) and sr-only elements use `clip-path: inset(50%)` alongside the legacy `clip` property.
- **Documentation overhaul** — readmes slimmed to storefront length. Full content extracted to `docs/`: [security model](docs/security-model.md), [developer reference](docs/developer-reference.md), [FAQ](docs/FAQ.md), and this changelog. [Manual testing guide](tests/MANUAL-TESTING.md) rewritten for v2.3.1+ with per-app-password testing, MU-plugin toggle, and iframe edge case coverage.
- **Composer lock compatibility** — `config.platform.php` set to `8.1.99` so the lock file resolves packages compatible with PHP 8.1+ regardless of the local PHP version. Fixes Copilot coding agent CI failure (`doctrine/instantiator` 2.1.0 requiring PHP 8.4+).
- **Housekeeping** — removed stale `WP-SUDO-PROJECT-STATE.md`; added `@since 2.0.0` to Upgrader class; updated CLAUDE.md and `.github/copilot-instructions.md` with docs/ file listings.
- **343 unit tests, 853 assertions.**

## 2.3.1

- **Fix: Unicode escape rendering** — localized JS strings using bare `\uXXXX` escapes (not valid PHP Unicode syntax) now use actual UTF-8 characters, fixing visible backslash-escape text during challenge replay.
- **Fix: screen-reader-only text flash** — the sr-only "Verifying..." span no longer flashes visible fragments inside the flex container during challenge replay.
- **CycloneDX SBOM** — `bom.json` shipped in the repo for supply chain transparency. Regenerate with `composer sbom`.
- **Help tabs** — per-application-password policy section added to the Settings help tab. Help tab count corrected from 4 to 8 across readmes.
- **Copilot coding agent** — `.github/copilot-instructions.md` and `copilot-setup-steps.yml` added for GitHub Copilot integration.
- **Accessibility roadmap complete** — all items (critical through low priority) verified resolved and documented.
- **343 unit tests, 853 assertions.**

## 2.3.0

- **Per-application-password sudo policies** — individual Application Password credentials can now have their own Disabled, Limited, or Unrestricted policy override, independent of the global REST API (App Passwords) policy. Configure per-password policies from the Application Passwords section on the user profile page.
- **Challenge page iframe fix** — the reauthentication challenge page now breaks out of WordPress's `wp_iframe()` context, fixing a nested-frame display issue during plugin and theme updates.
- **Accessibility improvements** — admin bar countdown timer cleans up on page unload; lockout countdown screen reader announcements throttled to 30-second intervals; settings fields display default values.
- **PHPStan level 6 static analysis** — full codebase passes PHPStan level 6 with zero errors.
- **Documentation** — new [AI and agentic tool guidance](docs/ai-agentic-guidance.md) and [UI/UX testing prompts](docs/ui-ux-testing-prompts.md).
- **343 unit tests, 853 assertions.**

## 2.2.1

- **Security hardening** — stashed redirect URLs are now validated with `wp_validate_redirect()` before replay.
- **Accessibility** — ARIA `role="alert"` and `role="status"` added to gate notices; disabled-action text color improved to 4.6:1 contrast ratio (WCAG AA).
- **2FA ecosystem documentation** — new [integration guide](docs/two-factor-integration.md) and [ecosystem survey](docs/two-factor-ecosystem.md) covering 7 major 2FA plugins with bridge patterns.
- **WP 2FA bridge** — drop-in bridge for WP 2FA by Melapress supporting TOTP, email OTP, and backup codes ([`bridges/wp-sudo-wp2fa-bridge.php`](bridges/wp-sudo-wp2fa-bridge.php)).
- **Help tabs** — Settings tab moved to 2nd position; all four 2FA hooks documented; Security Model heading added.
- **334 unit tests, 792 assertions.**

## 2.2.0

- **Three-tier entry point policies** — replaces the binary Block/Allow toggle with three modes per surface: Disabled (shuts off the protocol entirely), Limited (default — gated actions blocked, non-gated work proceeds normally), and Unrestricted (everything passes through).
- **Function-level gating for non-interactive surfaces** — WP-CLI, Cron, and XML-RPC now hook into WordPress function-level actions (`activate_plugin`, `delete_plugin`, `set_user_role`, etc.) instead of matching request parameters. This makes gating reliable regardless of how the operation is triggered.
- **CLI enforces Cron policy** — `wp cron` subcommands respect the Cron policy even when CLI is Limited or Unrestricted. If Cron is Disabled, `wp cron event run` is blocked.
- **REST API policy split** — Disabled returns `sudo_disabled` (surface is off), Limited returns `sudo_blocked` (gated action denied), clearly distinguishing the two rejection reasons.
- **Automatic upgrade migration** — existing `block` settings migrate to `limited`, `allow` to `unrestricted`. Multisite-aware.
- **Site Health updated** — Disabled is treated as valid hardening (Good status). Unrestricted triggers a Recommended notice.
- **Manual testing guide** — comprehensive step-by-step verification procedures in `tests/MANUAL-TESTING.md`.
- **327 unit tests, 752 assertions.**

## 2.1.0

- Removes the `unfiltered_html` capability from the Editor role. Editors can no longer embed scripts, iframes, or other non-whitelisted HTML — KSES sanitization is always active for editors. Administrators retain `unfiltered_html`. The capability is restored if the plugin is deactivated or uninstalled.
- Adds tamper detection: if `unfiltered_html` reappears on the Editor role (e.g. via database modification), it is stripped automatically and the `wp_sudo_capability_tampered` action fires for audit logging.
- Fixes admin bar deactivation redirect: clicking the countdown timer to end a session now keeps you on the current page instead of redirecting to the dashboard.
- Replaces WordPress core's confusing "user editing capabilities" error with a clearer message when a bulk role change skips the current user.

## 2.0.0

Complete rewrite. Action-gated reauthentication replaces role-based privilege escalation.

- **New model** — gates dangerous operations behind reauthentication for any user, regardless of role. No custom role, no capability escalation.
- **Full attack surface coverage** — admin UI (stash-challenge-replay), AJAX (error + admin notice + session activation), REST API (cookie-auth challenge, app-password policy), WP-CLI, Cron, XML-RPC.
- **Action Registry** — 20 gated rules across 7 categories (plugins, themes, users, editors, options, updates, tools), plus 8 multisite-specific rules. Extensible via `wp_sudo_gated_actions` filter.
- **Entry point policies** — three-tier Disabled/Limited/Unrestricted policies for REST Application Passwords, WP-CLI, Cron, and XML-RPC.
- **2FA browser binding** — challenge cookie prevents cross-browser 2FA replay.
- **2FA countdown timer** — visible countdown during the verification step; configurable window via `wp_sudo_two_factor_window` filter.
- **Self-protection** — WP Sudo settings changes are gated.
- **MU-plugin toggle** — one-click install/uninstall from the settings page. Stable shim + loader pattern keeps the mu-plugin up to date with regular plugin updates.
- **Multisite** — network-wide settings, network-wide sessions, 8 network admin rules, `get_site_option`/`set_site_transient` storage.
- **8 audit hooks** — full lifecycle and policy logging for integration with WP Activity Log, Stream, and similar plugins.
- **Contextual help** — 8 help tabs on the settings page.
- **Accessibility** — WCAG 2.1 AA throughout (ARIA labels, focus management, status announcements, keyboard support).
- **281 unit tests, 686 assertions.**

## 1.2.1

- In-place modal reauthentication; no full-page redirect.
- AJAX activation, accessibility improvements, expanded test suite.

## 1.2.0

- M:SS countdown timer, red bar at 60 seconds, accessibility improvements.
- Multisite-safe uninstall, contextual Help tab.

## 1.1.0

- 15-minute session cap, two-factor authentication support, `unfiltered_html` restriction.

## 1.0.0

- Initial release.
