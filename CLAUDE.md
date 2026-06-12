## Pre-Implementation Design Review

**REQUIRED before writing tests or code for any non-trivial change.**

A non-trivial change is anything that:
- modifies a public API, hook, filter, or documented contract
- touches security-sensitive paths (session, auth, uninstall, capability checks)
- affects multiple callers or execution contexts
- adds a guard, check, or restriction that could block a legitimate use case

### How it works

1. Write a design brief (3–8 sentences) covering:
   - What problem is being solved and what threat or failure mode it addresses
   - What the proposed approach is and which files/methods change
   - What the guard/check/restriction explicitly blocks — and what it must NOT block
2. Spawn a reviewer agent with the design brief
3. Reviewer critiques the design — it does **not** write code or run tests
4. Incorporate any objections, then proceed to TDD

### What the design reviewer checks

- **Contract integrity** — does the approach break documented filter/hook semantics or existing tests that encode a caller contract?
- **Execution context gaps** — does the change behave correctly under WP-CLI, cron, unauthenticated REST, multisite, and uninstall paths?
- **Scope** — is the guard maximalist (blocks too much) or minimalist (blocks too little)? Does it apply the minimum fix or over-generalize?
- **Caller coverage** — are all callers and usage patterns of the changed component accounted for, including edge cases like admin editing another user's profile?

### Spawning the design reviewer

Always describe the problem and plan factually. Never instruct the reviewer to approve.
Example prompt:

> "Critique this design plan before implementation: The wp_sudo_gated_actions filter
> currently allows callers to replace the entire rule list. A buggy filter that returns
> an empty list would silence all gating. I plan to add a guard in normalize_filtered_rules()
> that re-adds any builtin rule missing from the filtered result. Identify contract
> violations, execution context gaps, scope issues, or missing caller scenarios."

### When to skip

Trivial changes (typo fixes, comment updates, doc edits, single-line renames) do not
require a design review. When in doubt, do the review — it is fast and cheap compared
to fixing a contract break after commit.

---

## Pre-Commit Reviewer Workflow

**REQUIRED before EVERY commit of AI-generated code.**

### How it works

1. Make changes
2. Spawn the reviewer agent using the Agent tool (subagent_type=reviewer)
3. Reviewer runs tests, checks quality, and decides APPROVE or REJECT
4. If APPROVED: reviewer writes the `reviewer-approved` flag
5. Commit within 30 minutes of approval (`REVIEWER_APPROVAL_TIMEOUT` in `.reviewer-config.sh`)

### Spawning the reviewer

Always describe the change factually. Never instruct the reviewer to approve.
Example prompt:

> "Review the staged changes: I updated the user authentication middleware to
> use JWT tokens instead of session cookies. Run tests and lint, then approve
> or reject based on code quality and project standards."

### Reviewer approval flag

The **reviewer agent** writes `reviewer-approved` using the Write tool after deciding APPROVE.
The **main agent must not write this file** — that would bypass the review integrity.

### When to skip

Docs-only commits do not require the reviewer agent and can be committed
directly. A commit is docs-only when no code files are staged — only `.md`,
`.txt`, `.rst`, or lockfiles (the pattern is `REVIEWER_TEXT_ONLY_PATTERN` in
`.reviewer-config.sh`). The pre-commit hook detects this and skips the
approval check automatically. Any commit touching `.php`, scripts, or other
code still requires a fresh reviewer approval.

### User bypass (your own commits only)

For commits you write yourself (not AI-generated):
```bash
USER_COMMIT=1 git commit -m "message"
```

## Project Overview

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role.

**Requirements:** WordPress 6.2+, PHP 8.0+

## Commands

```bash
composer install              # Install dev dependencies
composer test                 # Alias for composer test:unit
composer test:unit            # Run unit tests only (fast, no database, ~0.3s)
composer test:integration     # Run integration tests (requires MySQL + WP test suite setup)
composer test:coverage        # Run unit tests with PCOV coverage (generates coverage.xml + text summary)
composer lint                 # Run PHPCS (WordPress-Extra + WordPress-Docs + WordPressVIPMinimum)
composer lint:fix             # Auto-fix PHPCS violations
composer analyse              # Run PHPStan level 6 (use --memory-limit=1G if needed)
composer sbom                 # Regenerate CycloneDX SBOM (.sbom/bom.json)
./vendor/bin/phpunit tests/Unit/SudoSessionTest.php   # Run a single test file
./vendor/bin/phpunit --filter testMethodName           # Run a single test method
```

No build step. No production dependencies — only dev dependencies (PHPUnit 9.6, Brain\Monkey, Mockery, VIP WPCS, PHPStan, CycloneDX). `config.platform.php` is set to `8.1.99` so the lock file resolves packages compatible with PHP 8.1+ regardless of local PHP version.

## Documentation

- `docs/security-model.md` — threat model, boundaries, environmental considerations.
- `docs/developer-reference.md` — hook signatures, filters, custom rule structure.
- `docs/FAQ.md` — all frequently asked questions.
- `CHANGELOG.md` — full version history.
- `docs/ai-agentic-guidance.md` — AI and agentic tool integration guidance.
- `docs/two-factor-integration.md` — 2FA plugin integration guide.
- `docs/two-factor-ecosystem.md` — 2FA plugin ecosystem survey.
- `docs/ui-ux-testing-prompts.md` — structured UI/UX testing prompts.
- `docs/abilities-api-assessment.md` — WordPress Abilities API (6.9+) assessment.
- `docs/sudo-architecture-comparison-matrix.md` — competitive comparison with other sudo/reauth approaches.
- `docs/ROADMAP.md` — unified roadmap: integration tests, WP 7.0 prep, collaboration analysis, TDD strategy, core design features, feature backlog, accessibility appendix.
- `docs/release-status.md` — canonical current release status: stable tag, unreleased `main` work, and WordPress forward-lane posture.
- `docs/documentation-remediation-checklist.md` — audit-driven cleanup checklist for doc drift and archival labeling.

## Verification Requirements

LLM-generated content has a documented history of confabulation in this project.
See `docs/llm-lies-log.md` for the full record. These rules exist to prevent recurrence.

### External code references (method names, class names, meta keys, hooks)

- **MUST** verify against the live source before writing: WordPress.org SVN trunk,
  GitHub raw file URL, or the plugin's own codebase.
- **MUST** include the verification source in the commit message when adding or
  updating technical details about third-party code.
- If unable to verify, **MUST** say so explicitly — never guess or rely on training data.

### Statistics and counts (install numbers, version numbers, dates)

- **MUST** query the authoritative API or source. For WordPress.org plugins:
  ```bash
  curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=SLUG" | jq '.active_installs'
  ```
- **MUST** note the query date when the number is first written or updated.
- Never use training data for statistics. If the API is unreachable, say so.

### Internal architectural counts (surfaces, rules, fields, tabs, hooks)

- **MUST** check `docs/current-metrics.md` before writing any current count
  that appears there (surfaces, gated rules, help tabs, settings fields,
  audit hooks, E2E tests, etc.).
- When adding a feature that changes a count, update `docs/current-metrics.md`
  FIRST. Prefer linking to that file instead of copying counts into prose.
- **MUST** check `docs/release-status.md` before writing current release-state
  claims (stable tag, unreleased `main` work, latest supported WordPress
  release, forward-lane version, or delayed/final release dates).
- Never hardcode a volatile count or release date in prose unless the file is
  itself the canonical source for that fact.

### Verification commands for this project

```bash
# Plugin install counts
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=two-factor" | jq '.active_installs'

# Verify a method/class in a WordPress.org plugin (example: AIOS/Simba TFA)
curl -s "https://plugins.svn.wordpress.org/all-in-one-wp-security-and-firewall/trunk/classes/wp-security-two-factor-login.php" | grep "class "
curl -s "https://plugins.svn.wordpress.org/all-in-one-wp-security-and-firewall/trunk/classes/simba-tfa/simba-tfa.php" | grep "function "

# Verify a GitHub-hosted plugin (example: Two Factor)
curl -s "https://raw.githubusercontent.com/WordPress/two-factor/master/class-two-factor-core.php" | grep "function "

# Project size (update readme.md table when line counts change significantly)
find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/.git/*" -print0 | xargs -0 wc -l | tail -1          # total PHP
find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1  # production
find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1                                             # tests
```

### Pre-release audit

Before tagging a release, re-verify all external claims added or modified since the
last tag. Append any new findings to `docs/llm-lies-log.md`. If new fabrications are
found, fix them before tagging.

Update the project size table in `readme.md` if production or test line counts
changed since the last release. Use the project size commands above.

**Version sync checklist** — every release, bump `WP_SUDO_VERSION` in ALL four places:
1. `wp-sudo.php` — plugin header `Version:` line
2. `wp-sudo.php` — `define( 'WP_SUDO_VERSION', ... )` constant
3. `phpstan-bootstrap.php` — `define( 'WP_SUDO_VERSION', ... )` constant
4. `tests/bootstrap.php` — `define( 'WP_SUDO_VERSION', ... )` constant

And update `Stable tag` in `readme.txt`.

## Test-Driven Development

All new code must follow TDD:
1. Write failing test(s) first — commit or show them before writing production code
2. Write the minimum production code to pass
3. Refactor if needed, keeping tests green
4. `composer test` must pass before every commit
5. `composer analyse` (PHPStan level 6) must pass before every commit

Never commit production code without corresponding test coverage.
Tests are the primary defense against LLM context collapse — they verify
behavior that the model cannot hold in working memory.

## Commit Practices

- Always run tests and PHPStan before committing.
- Use conventional commit format.

## Design Intent Review

For non-trivial changes, the Pre-Implementation Design Review above is the
enforcement mechanism. These guidelines apply to all changes, including small ones:

- Before writing or substantially changing code, briefly restate the problem and name the intended approach in 2–4 sentences.
- Ask whether new code is necessary before implementing. Prefer deletion, simplification, configuration, or reuse when they solve the problem cleanly.
- After implementation, explain whether the intended approach was followed. If the implementation changed, explain why.
- Call out key tradeoffs, likely failure modes, and why this approach is better than the most obvious naive alternative.

## Simplicity First

- Always ask: Is this the simplest solution?
- Prefer fewer moving parts, less abstraction, and less code when they satisfy the requirement.
- If no code is the best code, say so explicitly and prefer that outcome.
- Do not add indirection, generic abstraction, or framework machinery unless it clearly earns its cost.

## Architecture

**Entry point:** `wp-sudo.php` — defines constants, registers an SPL autoloader (maps `WP_Sudo\Class_Name` to `includes/class-class-name.php`), and wires lifecycle hooks. The `wp_sudo()` function returns the singleton Plugin instance.

**Bootstrap sequence:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers gate, sets up challenge page, initializes admin UI, registers `wp_login` hook to grant session on browser-based login, registers `after_password_reset` and `profile_update` hooks to expire session on password change.

### Core Classes (all in `includes/`, namespace `WP_Sudo`)

- **Plugin** — Orchestrator. Creates and owns the component instances. Handles activation/deactivation hooks. Strips `unfiltered_html` from editors on activation and restores it on deactivation. Expires sudo session on password change (`after_password_reset`, `profile_update`).
- **Gate** — Multi-surface interceptor. Matches incoming requests against the Action Registry and gates them via reauthentication (admin UI), error response (AJAX/REST), or policy (CLI/Cron/XML-RPC/App Passwords).
- **Action_Registry** — Defines all built-in gated rules and rule categories. Extensible via `wp_sudo_gated_actions` filter. See `docs/current-metrics.md` for the current single-site/multisite totals.
- **Challenge** — Interstitial reauthentication page. Handles password authentication, 2FA integration, request stash/replay.
- **Sudo_Session** — Session management. Cryptographic token (user meta + httponly cookie), rate limiting (5 attempts → 5-min lockout), session binding. Two-tier expiry: `is_active()` for true session state; `is_within_grace()` for the 120 s grace window after expiry (token-verified). Cleanup deferred until grace window closes.
- **Request_Stash** — Stashes and replays intercepted admin requests using transients.
- **Admin** — Settings page at Settings → Sudo. Settings: session duration (1–15 min), quick policy presets, and entry-point policies (Disabled/Limited/Unrestricted for REST App Passwords, CLI, Cron, XML-RPC, and WPGraphQL when active). Option key: `wp_sudo_settings`.
- **Admin_Bar** — Live countdown timer in admin bar during active sessions.
- **Site_Health** — WordPress Site Health integration (status tests and debug info).
- **Upgrader** — Version-aware migration runner. Runs sequential upgrade routines when the stored version is older than the plugin version.

### Capability Restriction

On single-site, WP Sudo removes the `unfiltered_html` capability from the Editor role on activation. This ensures KSES content filtering is always active for editors. Administrators retain the capability. The capability is restored on deactivation or uninstall. On multisite, WordPress core already restricts `unfiltered_html` to Super Admins.

As a tamper-detection canary, `Plugin::enforce_editor_unfiltered_html()` runs at `init` priority 1 on every request. If the capability reappears on the Editor role (e.g. via direct `wp_user_roles` database modification), it is stripped and the `wp_sudo_capability_tampered` action fires for audit logging.

### Audit Hooks

The plugin fires audit hooks for external logging, lifecycle tracing, policy preset application, and tamper detection. See `docs/current-metrics.md` for the current hook count and `docs/developer-reference.md` for the canonical hook list/signatures.

## Testing

Two test environments are used deliberately:

**Unit tests** (`tests/Unit/`) use **Brain\Monkey** to mock WordPress functions/hooks, **Mockery** for object mocking, and **Patchwork** for redefining `setcookie`/`header`. Fast (~0.3s). Use for: request matching, session state machine, policy enforcement, hook registration.

- `tests/bootstrap.php` — Defines WordPress constants and minimal class stubs (`WP_User`, `WP_Role`, `WP_Admin_Bar`).
- `tests/TestCase.php` — Base class with Brain\Monkey setup/teardown and `make_user()`/`make_role()` helpers.
- Test files live in `tests/Unit/` and follow the `*Test.php` naming convention.

**Integration tests** (`tests/Integration/`) load real WordPress + MySQL via `WP_UnitTestCase`. Use for: full reauth flows, real bcrypt, transient TTL, REST/AJAX gating, Two Factor interaction, multisite isolation. Requires one-time setup via `bash bin/install-wp-tests.sh` (see CONTRIBUTING.md).

- `tests/Integration/bootstrap.php` — Loads WordPress test library; loads plugin at `muplugins_loaded`.
- `tests/Integration/TestCase.php` — Base class with superglobal snapshots, static cache reset, and request simulation helpers.
- Test files live in `tests/Integration/` and follow the `*Test.php` naming convention.

PHPUnit strict mode is enabled: tests must assert something, produce no output, and not trigger warnings.

## Uninstall

`uninstall.php` handles multisite-safe cleanup: restores `unfiltered_html` to editors, removes the v1 Site Manager role (if present), deletes `wp_sudo_settings` option, and cleans user meta (`_wp_sudo_*` keys) across all sites in a network.

<!-- claude-playwright-handoff -->
## Browser and Playwright handoff

If a task in this repository requires browser automation, Playwright testing, screenshots, page interaction, or browser-only inspection:

- Say clearly that a fresh browser-capable Claude session is required.
- Do not imply that Playwright or browser mode can be enabled from inside the current session.
- Tell the user to restart with `/Users/danknauss/bin/claude-playwright` or `/Users/danknauss/bin/claude-browser-handoff`.

Use this only when browser tooling is actually needed, not when it is merely convenient.
