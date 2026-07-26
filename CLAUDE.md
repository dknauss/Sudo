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

**Requirements:** WordPress 6.4+, PHP 8.2+

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

No build step. No production dependencies — only dev dependencies (PHPUnit 9.6, Brain\Monkey, Mockery, VIP WPCS, PHPStan, CycloneDX). `config.platform.php` is set to `8.2.99` so the lock file resolves packages compatible with PHP 8.2+ regardless of local PHP version.

## WP-CLI and remote-site safety

Operative rules (canonical full version in this repo's `AGENTS.md` → "WP-CLI and
remote-site safety", and user-level `~/AGENTS.md`):

- **Local testing:** `wp @studio <cmd>` or `studio wp <cmd>` — read/write on the
  local Studio sandbox. Automated integration tests still use `composer
  test:integration` (MySQL + WP test suite), not an alias.
- **Remote / production aliases (any alias with an `ssh:` key): READ-ONLY by
  default.** Reads (`get`/`list`/`SELECT`/`plugin list`) are fine; any write
  needs explicit per-command approval with `--dry-run` shown first. Never print,
  log, or store SSH keys or DB credentials.
- **Never use WP-CLI to bypass this plugin's own gates** on a site you do not own
  or lack explicit approval to modify.

## Documentation

- `docs/README.md` — **documentation index: which doc answers which question. Start here.**
- `docs/security-model.md` — threat model, boundaries, environmental considerations.
- `docs/security-audit-methodology.md` — **mandatory** security-audit process: "reason about the target first" before any sink-oriented scan. Required whenever auditing the gate, session, or any component that enforces, gates, binds, or issues a security decision.
- `docs/developer-reference.md` — hook signatures, filters, custom rule structure.
- `docs/FAQ.md` — all frequently asked questions.
- `CHANGELOG.md` — full version history.
- `docs/ai-agentic-guidance.md` — AI and agentic tool integration guidance.
- `docs/two-factor-integration.md` — 2FA plugin integration guide.
- `docs/two-factor-ecosystem.md` — 2FA plugin ecosystem survey.
- `docs/ui-ux-testing-prompts.md` — structured UI/UX testing prompts.
- `docs/security-manual-test-checklist.md` — live/manual end-to-end security test checklist (reauth REST gates + lockdown audit); the layer automated unit/CI tests do not cover.
- `docs/abilities-api-assessment.md` — WordPress Abilities API (6.9+) assessment.
- `docs/sudo-architecture-comparison-matrix.md` — competitive comparison with other sudo/reauth approaches.
- `docs/ROADMAP.md` — forward-only roadmap (Now/Next/Later/Non-goals). The design analyses that used to live here are now standalone docs: `docs/testing-strategy.md` (integration/TDD/coverage/mutation/exit-path), `docs/collaboration-analysis.md`, `docs/sudo-design-notes.md` (core design), plus archives `docs/archive/wp-7.0-prep.md` and `docs/archive/accessibility-audit.md`. The feature backlog now lives in GitHub issues (see the roadmap's Backlog section).
- `docs/release-status.md` — canonical current release status: stable tag, unreleased `main` work, and WordPress forward-lane posture.
- `docs/wporg-submission-checklist.md` — §1 pre-tag/core release gates (live: version-sync, blueprint, test/lint/analysis, external-claim audit). Its §2 WordPress.org submission process (validator, Plugin Check, SVN layout, listing assets) is **dormant reference** — wp.org submission is not planned, and Plugin Check is not a gate.

## Verification Requirements

Full rules live in this repo's `AGENTS.md` → "Verification Requirements". Single
source of truth: keeping a second copy here is what let the two files drift — for
months `CLAUDE.md` carried the `blueprint.json` release step and `AGENTS.md` did
not, so which rules you got depended on which agent you were (see #333).

**Summary (authoritative version in `AGENTS.md`):** LLM-generated content has a
documented history of confabulation in this project — `docs/llm-lies-log.md` is the
record, and these rules exist to prevent recurrence.

- **External code references** (method/class names, meta keys, hooks) MUST be
  verified against live source before writing, with the source named in the commit
  message. If you cannot verify, say so — never guess.
- **Statistics and counts** MUST come from the authoritative API, never training
  data, with the query date noted.
- **Internal architectural counts** MUST be checked against `docs/current-metrics.md`,
  and **release-state claims** against `docs/release-status.md`. Update those files
  FIRST when a change moves them.
- **Every release** bumps `WP_SUDO_VERSION` in four places, `Stable tag` in
  `readme.txt`, and the `blueprint.json` Playground install target — the badges point
  at `main/blueprint.json`, so skipping it silently serves a stale demo.
- **Before tagging**, re-verify every external claim added *or modified* since the
  last tag and
  append new findings to `docs/llm-lies-log.md`.


## Prose Discipline

Full rules live in this repo's `AGENTS.md` → "Prose Discipline". That file is the
single canonical copy on purpose: a rule about not duplicating claims should not
itself exist in two divergent copies.

**Summary (authoritative version in `AGENTS.md`):** prose is the least-tested artifact
here and the fastest thing an LLM produces, so treat a sentence about behaviour as
seriously as code asserting it.

- **Cite the enclosing symbol**, never a bare line number — if you cannot name the
  function or component containing the line, you have not read it, you have grepped it.
- **Register third-party claims once** in `docs/upstream-sources.md` and run
  `composer verify:sources`. Prose carries a summary and the registry ID, not a
  seventh copy of the URL.
- **Scope words are claims.** *only, sole, never, always, every* need a command that
  would falsify them, or a narrower sentence.
- **Write decisions, not state inventories.** "Chosen because X" survives; "the only
  painted state" rots at the next commit and no test notices.
- **Sweep every file type after a behaviour change**, especially languages the change
  did not touch, and open any regenerated screenshot before writing prose about it.
- **Run the mechanical checks after comment-only edits too** — `composer
  verify:metrics` (line counts are tracked), `bash bin/make-pot.sh` (`.pot` carries
  line refs; regenerate rather than hand-patch), `composer verify:sources`.

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

## Concurrent Sessions — Check Before You Branch

**REQUIRED before creating a branch, worktree, or PR.**

Several sessions work this repo at once. They share one filesystem and one git
repo, so each can already see the others — but only if it looks. Three sessions
once independently built the same version rollback (#389, #390, #391) and a
fourth nearly joined them. Nothing collided, because each picked a different
name for identical work.

1. **Search for the work, not the name:**
   ```bash
   gh pr list --state open --search "<issue#|keyword>"
   git branch -a --sort=-committerdate | head -20
   ```
   A `SessionStart` hook prints both at session start — read it rather than
   re-running it. If work for that issue already exists, join it, hand your
   findings to it, or say so. Do **not** start a parallel branch.

2. **Name branches after the issue, so collisions actually collide:**
   `<type>/<issue#>-<slug>` — e.g. `fix/322-stash-fail-closed`,
   `chore/388-version-4.9.0`. Git then refuses the second session's
   `worktree add -b`, which is the only mechanical stop that works. A name
   without the issue number silently coexists with three siblings.

3. **Another session's branch is not yours to force.** A locked worktree or an
   unmerged branch means someone is on it. Before touching one, confirm it has
   no uncommitted work and no unpushed commits, work from a **detached**
   worktree of your own, and never check out a branch another worktree holds.
   If it holds unmerged commits, capture what is worth keeping (a finding, a
   test) before deleting anything.

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

## No Test-Environment Shims in Production

Production code must not carry guards or accommodations whose only purpose is the
unit-test environment. Tests adapt to production, never the reverse — stub with
Brain\Monkey, not with production-side fallbacks.

Specifically, do **not** write:

- `function_exists()` / `defined()` / `class_exists()` / `method_exists()` guards on
  symbols guaranteed by WordPress core at the relevant load point, or by the plugin's
  own bootstrap (`WP_SUDO_*` constants are defined unconditionally before any class
  loads). A unit test missing a stub is a test bug — fix the test.
- `try { ... } catch ( \Throwable ) { ... }` wrappers around core APIs that exist to
  swallow exceptions the test environment throws. These can mask real production
  failures (e.g. a swallowed transient read made IP-lockout checks fail open).
- Public methods, getters, or constructor DI parameters that exist only so a test can
  reach internal state. Read private state through reflection in the test instead
  (`@$ref->setAccessible( true )` per the suite's PHP 8.0/8.5 pattern).

**Legitimate guards that are NOT shims (keep these):**

- Runtime-variable integration checks: `function_exists( 'graphql' )`,
  `class_exists( 'Two_Factor_Core' )`, `function_exists( 'dbDelta' )`,
  `function_exists( 'wp_get_admin_notice' )` (WP 6.4+ vs the 6.2 minimum), the
  SQLite drop-in `method_exists( $wpdb, 'dbh' )` detection, and `WP_CLI` checks.
  These guard symbols that genuinely may or may not exist at runtime.
- Tiny `reset_*()` static methods used purely for static-cache hygiene between tests.
- `Plugin::gate()` — the one component getter kept deliberately, so integration tests
  can exercise the live wired interceptor. Other component getters were removed.

## Architecture

**Entry point:** `wp-sudo.php` — defines constants, registers an SPL autoloader (maps `WP_Sudo\Class_Name` to `includes/class-class-name.php`), and wires lifecycle hooks. The `wp_sudo()` function returns the singleton Plugin instance.

**Bootstrap sequence:** `plugins_loaded` → `Plugin::init()` → loads translations, runs upgrader, registers gate, sets up challenge page, initializes admin UI, registers `wp_login` hook to grant session on browser-based login, registers `after_password_reset` and `profile_update` hooks to expire session on password change.

### Core Classes (all in `includes/`, namespace `WP_Sudo`)

- **Plugin** — Orchestrator. Creates and owns the component instances. Handles activation/deactivation hooks. Strips `unfiltered_html` from editors on activation and restores it on deactivation. Expires sudo session on password change (`after_password_reset`, `profile_update`).
- **Gate** — Multi-surface interceptor. Matches incoming requests against the Action Registry and gates them via reauthentication (admin UI), error response (AJAX/REST), or policy (CLI/Cron/XML-RPC/App Passwords). Beyond request-pattern matching it also arms **effect-level backstops** — interactive (`admin_init`) and REST (`register_rest_backstop`) — that hard-block unambiguous destructive effects reached through non-enumerated handlers (4.1.0), plus an opt-in, **default-OFF admin-escalation guard** (`arm_escalation_guard`, filter `wp_sudo_guard_escalation`) that blocks a newly-granted administrator/super-admin without an active sudo session by hooking the `{prefix}capabilities` meta write and `grant_super_admin`.
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

<!-- browser-and-playwright -->
## Browser and Playwright work

Browser automation, page interaction, screenshots, and browser-only inspection use the **`playwright` MCP server** (`mcp__playwright__*` tools) configured for the session — there is **no** `claude-playwright` / `claude-browser-handoff` launcher script (those wrappers were removed; the old `/Users/danknauss/bin/...` paths are dead and the username was wrong — the home dir is `/Users/dan-knauss`). Do not tell the user to run a launcher.

- **Ad-hoc browser driving/inspection:** use the `playwright` MCP tools when the server is connected. If they are not available in the current session, the fix is to connect/enable the `playwright` MCP (via the user's MCP config or `/mcp` in an interactive session), not to restart into a special session.
- **The repo's E2E / visual-regression suite** (`tests/e2e/specs/**`, `@wordpress/e2e-test-utils-playwright` + `wp-env`) runs via normal shell commands (`npm run test:e2e`, boot `wp-env`; use `WP_ENV_TESTS_PORT=8891` if 8890 is held) — any session with a working `wp-env` can run it; it does not depend on the MCP.

Use browser tooling only when it is actually needed, not when merely convenient.
