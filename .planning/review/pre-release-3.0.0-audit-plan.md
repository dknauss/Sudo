# Pre-Release 3.0.0 Audit — Delegated Exploration Plan

**Created:** 2026-04-19
**Target tag:** 3.0.0 (imminent — "this week" cadence)
**Severity rule:** MAJORs count as BLOCKERs for this release.
**Orchestrator:** Opus 4.7 (main session) with extended thinking.

## Why this document exists

This plan was drafted in-session so that if the conversation is interrupted,
quota-throttled, or handed to another operator, work can resume without
reconstructing context. Each lane below is self-contained enough to spawn
directly.

## Verification discipline (applies to every lane)

Every sub-agent MUST follow `CLAUDE.md` "Verification Requirements" verbatim:

- **External code** → WordPress.org SVN trunk (`https://plugins.svn.wordpress.org/<slug>/trunk/...`),
  GitHub raw (`raw.githubusercontent.com/...`), or the live plugin tree. Cite the URL.
- **Statistics** → `curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=SLUG" | jq '.active_installs'`.
  Note the query date (2026-04-19).
- **Internal counts** → check `docs/current-metrics.md` first; if drift is
  suspected, run the verification command listed in that file.
- **Release state** → `docs/release-status.md` is canonical; don't paraphrase
  from memory.
- **If unable to verify → say so explicitly.** Training data is not a source.
  Findings with unverifiable claims are rejected by the orchestrator.

Every finding returned must be in this format:

```
[SEVERITY] <one-line title>
Claim:    <what the doc/code asserts>
Source:   <live URL or file:line>
Verified: <command run / URL fetched / date>
Impact:   <why this matters for 3.0.0>
Action:   <concrete fix or "no action — verified correct">
```

Severities:
- `BLOCKER` — must fix before tag (all MAJORs count here for 3.0.0)
- `MAJOR` — must fix before tag (for this release, same as BLOCKER)
- `MINOR` — nice-to-have, can ship
- `OK` — verified correct, no action

## Landed work in scope (as of 2026-04-19)

Recent commits auditors should assume are in scope:

- `d70c712` chore: bump version to 3.0.0
- `af02084` docs: correct connectors reference revision history
- `55785f0` fix: Phase 9 stabilization pass (PHPCS, a11y, metrics, docs)
- `6022c3a` docs: fix stale counts in metrics and lies log
- `6a808a6` test(events): add Event_Store integration tests and demo script
- `c06a1f1` fix(timer): harden same-url reload tests and upgrade cron
- `c2b01d2` feat(events): add cron prune scheduling and graceful degradation
- `c987d56` / `c8fb980` SQLite table creation + detection in Event_Store
- `ff74fd4` feat: add Session Activity Dashboard Widget (2.15.0)
- `9d47038` docs(connectors): clarify multisite scope and tester limits
- `c3c4b4f` feat(admin): add REST Params textarea to Rule Tester
- `d3a1357` feat(event-store): add Event_Store class and 2.15.0 migration
- `5034de8` feat(admin): bidirectional preset ↔ surface policy sync
- `7271d42` / `9714eac` Phase 10 settings UI revision (tabbed nav, dropdowns, notices)
- `a372451` / `7def6ba` / `2de8764` / `2e13af9` UninstallTest fixes (superseded by `5636408`)
- `313b2d5` / `389dea6` fix(e2e): preset dropdown + checkbox removal
- `5636408` fix: use DESCRIBE for MySQL table detection in Event_Store
- `4cd121c` docs: update current-metrics.md line counts

Tree state: clean, pushed to `origin/main`.

## Critical discovery: MySQL 8 temp table detection

During pre-release testing, we discovered that `SHOW TABLES LIKE` does not see
temporary tables created by the WordPress test suite's transaction layer on
MySQL 8. This caused integration tests to incorrectly conclude the events table
was missing. The fix (`5636408`) changes `Event_Store::table_exists()` to use
`DESCRIBE <table>` instead, which correctly detects both real and temporary
tables.

This was NOT a production schema bug — the DDL is correct and works on MySQL 8.
It was a MySQL-8-specific interaction between WP core's integration-test
transaction layer and our use of `SHOW TABLES LIKE`.

The conditional assertions in `a372451` that masked this have been replaced with
proper assertions that now pass.

## Lane 1 — Security boundary audit (Sonnet)

**Scope:** Every gate on every surface, adversarial lens.

Files to audit end-to-end (not just grep):

- `includes/class-gate.php` — dispatch, surface detection, `parse_diagnostic_url()` (Rule Tester only; not a security boundary but still must not leak), bypass conditions.
- `includes/class-sudo-session.php` — RNG source for tokens, timing-safe comparison, rate limit state machine (5 attempts → 5-min lockout), 120 s grace window, session binding, cookie flags.
- `includes/class-challenge.php` — password verification, 2FA hand-off, request stash/replay (transient TTL, CSRF token lifecycle).
- `includes/class-request-stash.php` — stash key entropy, expiry, replay idempotency.
- `includes/class-action-registry.php` — every rule's matcher; Connectors regex for vendor credential keys; REST method coverage (POST/PATCH/PUT/DELETE); multisite-only rules.
- `includes/class-plugin.php::enforce_editor_unfiltered_html()` — canary timing vs `init:1`.
- Capability strip/restore on activation/deactivation (single-site vs multisite).
- Policy preset dropdowns — every enum value maps to a concrete policy; no surface silently defaults to Unrestricted.
- `includes/class-event-store.php` (Phase 9 new) — SQL injection surface in `dbDelta` + inserts; capability check on any reads; SQLite vs MySQL branch parity.
- Cron prune handler — who can trigger; admin-context assumption; rate limit.
- SQLite detection path — any branch that fails open under unexpected DB.

**Deliverables:** BLOCKER/MAJOR findings + an OK checklist confirming each gate was read.

## Lane 2 — Architecture + cross-file invariants (Sonnet)

**Scope:** Invariants that span files and would be missed by single-file review.

- Bootstrap order: `plugins_loaded` → `Plugin::init()` → … vs any hook fired earlier that assumes plugin state.
- `Upgrader` migration chain: every stored `wp_sudo_version` has a forward path to 3.0.0; downgrade safety; multisite per-site vs network options.
- 2.15.0 migration (`d3a1357`): schema + `dbDelta` idempotency on re-run; handles pre-existing table; multisite per-site vs network.
- `uninstall.php` parity with Event_Store (calls `Event_Store::drop_table()`; handles SQLite + MySQL + missing table).
- Cron registration: scheduled on activation, cleared on deactivation, re-scheduled on upgrade — verify against `Upgrader`.
- Preset ↔ surface bidirectional sync (`5034de8`): JS state model matches PHP `get_preset_policies()` canonical map. No policy key drift between `Admin`, `Action_Registry`, `Gate`.
- Autoloader convention (`WP_Sudo\Class_Name` → `includes/class-class-name.php`) — any new class that violates.
- Audit hook signatures match `docs/developer-reference.md`; count matches `docs/current-metrics.md`.
- `config.platform.php = 8.1.99` vs PHP 8.0+ minimum — any 8.1-only syntax reaching production PHP files.
- Settings UI tabs: all fields persist correctly; no orphaned option keys; no duplicated setting registered twice.

**Deliverables:** Invariant-violation findings with file:line citations for BOTH sides of each invariant.

## Lane 3 — External claim verification (Haiku ×3 fan-out)

**Scope:** Every third-party reference in docs and code comments.

Split across three parallel Haiku agents:

**Haiku 3a — Integration docs:**
- `docs/two-factor-integration.md`
- `docs/two-factor-ecosystem.md`
- `docs/abilities-api-assessment.md`

**Haiku 3b — Comparison + AI/agentic:**
- `docs/sudo-architecture-comparison-matrix.md`
- `docs/ai-agentic-guidance.md`
- Any inline comment in `includes/` referencing a third-party class, hook, or function.

**Haiku 3c — Readmes + stats:**
- `readme.md`
- `readme.txt`
- Plugin active-install counts for every plugin mentioned (Two Factor, WP 2FA, AIOS, Wordfence, iThemes/Solid Security, WP 2FA by Melapress, etc.) via `api.wordpress.org/plugins/info/1.2/`. Note query date 2026-04-19.

For each claim, each Haiku must:
1. Locate in source doc (file:line).
2. Fetch live source (SVN trunk or GitHub raw).
3. Grep for the asserted symbol.
4. Record: `verified` / `drifted` / `fabricated`.

**Deliverables:** Table per agent (claim, doc location, live source URL, status). Orchestrator merges into draft entry for `docs/llm-lies-log.md` if fabrications found.

## Lane 4 — Test coverage + metrics freshness (Sonnet)

**Scope:** Fresh green/red signal + coverage gap hunt.

Commands to run (capture actual output):
- `composer test:unit`
- `composer test:integration` (wp-env must be up; if not, document and skip with explicit note)
- `composer analyse:phpstan`
- `composer analyse:psalm`
- `composer lint`
- `npx playwright test --config tests/e2e/playwright.config.ts --list` (count only, don't execute)

Diff actual counts vs `docs/current-metrics.md`. Propose updates.

Recompute line counts using the exact commands in `docs/current-metrics.md`. Propose updates.

Coverage gap hunt: every public method in `includes/class-*.php` → is there at least one test referencing it? Focus on Phase 9 (Event_Store, Event_Recorder, Dashboard_Widget) + Phase 10 (Admin revisions). SQLite code paths — are they exercised, or only MySQL?

Audit for `@markTestSkipped` / `markAsIncomplete` in recent tests. The 4 UninstallTest fixes (`a372451`, `7def6ba`, `2de8764`, `2e13af9`) use conditional-assertions patterns; verify they're not masking drift.

**Deliverables:**
- Pass/fail matrix with actual numbers for every command.
- Metrics drift table (fact, current doc value, fresh value, delta).
- Untested public methods list with file:line.

## Lane 5 — Release readiness (orchestrator, not delegated)

Runs after Lanes 1–4 return and fixes land.

- Version sync across 4 places (CLAUDE.md "Version sync checklist"):
  1. `wp-sudo.php` plugin header `Version:` line
  2. `wp-sudo.php` `define( 'WP_SUDO_VERSION', ... )`
  3. `phpstan-bootstrap.php` `define( 'WP_SUDO_VERSION', ... )`
  4. `tests/bootstrap.php` `define( 'WP_SUDO_VERSION', ... )`
  Plus `Stable tag` in `readme.txt`.
- `docs/release-status.md` reflects 3.0.0 intent.
- `CHANGELOG.md` 3.0.0 entry covers every feature landed: Connectors gating, Policy Presets, Rule Tester, Settings UI Revision (Phase 10), Dashboard Widget + Event_Store (Phase 9), SQLite support, cron prune, preset bidirectional sync.
- SBOM regen: `composer sbom` if `composer.lock` changed since last SBOM.
- `docs/llm-lies-log.md` has a pre-release audit line for 2026-04-19.
- WP 7.0 posture per `.claude/projects/.../memory/project_wp70_delay.md` — don't bump "Tested up to" prematurely.

## Execution protocol

1. **Plan documented** (this file). User confirms scope.
2. **Wave A — parallel fan-out:** spawn Lanes 1, 2, 3a, 3b, 3c, 4 in a single message (6 agents). Each runs independently.
3. **Wait for all returns.** Each returns findings list in required format.
4. **Synthesize:** merge into single pre-release report with sections:
   - BLOCKERS (must fix)
   - MINORs (can ship)
   - Verified-OK summary
   - Metrics drift table
   - External-claim verification table
5. **Present to user** for go/no-go on each BLOCKER.
6. **Fix pass:** TDD where applicable; each fix is its own commit through the reviewer workflow.
7. **Lane 5** (release readiness) runs last, once fixes land.

## Handoff notes

If this session is interrupted:

- This plan lives at `.planning/review/pre-release-3.0.0-audit-plan.md`.
- Phase plans for 9 and 10 live at `.planning/phases/09-dashboard-widget/` and `.planning/phases/10-settings-ui-revision/`.
- The reviewer workflow is documented in the project `CLAUDE.md` — every AI-generated commit requires a fresh reviewer approval (flag file `reviewer-approved`, 5-minute TTL).
- Max 10 files per AI commit per the pre-commit hook; split larger changes.
- `USER_COMMIT=1` bypasses the reviewer gate for user-written commits only.
- Verification discipline is non-negotiable: see `docs/llm-lies-log.md` for the project's documented history of confabulation.

## Context and cost notes

- Sub-agent spawns are cheapest when prompts are self-contained and narrow. Each lane prompt will be 400–800 words with explicit file lists, verification commands, and the finding format.
- Haiku fan-out for Lane 3 is the biggest win — external claim verification is mechanical, high-volume, and perfect for parallel Haiku.
- Expected orchestrator context cost: ~30–50k tokens synthesizing findings. Each sub-agent: 15–40k tokens of own context, returning ~2–5k tokens to orchestrator.
