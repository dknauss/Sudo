# WP Sudo

## What This Is

WP Sudo is a WordPress plugin that provides action-gated reauthentication. Dangerous admin operations (plugin activation, user deletion, critical settings changes, etc.) require password confirmation before they proceed — regardless of user role. It intercepts requests across 7 surfaces (admin UI, AJAX, REST API, WP-CLI, Cron, XML-RPC, Application Passwords) and gates 28+ operations across 7 categories.

The plugin is at v2.3.2 with 13,495 lines of PHP (6,220 production, 7,275 test — 54% test code). It has zero production dependencies and a comprehensive unit test suite using Brain\Monkey mocks.

## Core Value

Every destructive WordPress admin action requires the user to prove they are still present at the keyboard, regardless of their role or how they got there.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. Inferred from existing codebase at v2.3.2. -->

- ✓ Multi-surface request interception (admin, AJAX, REST, CLI, Cron, XML-RPC, App Passwords) — existing
- ✓ Action Registry with 28 rules across 7 categories (plugins, themes, users, editors, options, multisite, core) — existing
- ✓ Challenge interstitial with password verification and request replay — existing
- ✓ Cryptographic session binding (user meta + httponly cookie) — existing
- ✓ Progressive rate limiting (5 attempts → 5-min lockout) — existing
- ✓ Request stash and replay for GET and POST requests — existing
- ✓ Per-surface policy configuration (Disabled/Limited/Unrestricted for REST/CLI/Cron/XML-RPC) — existing
- ✓ Per-application-password policy overrides — existing (v2.3.0)
- ✓ Two Factor plugin integration via bridge pattern — existing
- ✓ Admin bar countdown timer during active sessions — existing
- ✓ Settings page (single-site and multisite) — existing
- ✓ Site Health integration (status tests + debug info) — existing
- ✓ Version-aware upgrader/migration system — existing
- ✓ Capability restriction (unfiltered_html enforcement on editors) — existing
- ✓ 9 audit action hooks for external logging — existing
- ✓ MU-plugin for early hook registration on non-interactive surfaces — existing
- ✓ Keyboard shortcut (Ctrl+Shift+S) for quick reauthentication — existing
- ✓ WCAG 2.1 AA accessibility throughout — existing
- ✓ CSP-compatible (no inline scripts) — existing
- ✓ Comprehensive unit test suite (~220 methods, Brain\Monkey + Mockery + Patchwork) — existing

### Active

<!-- Current milestone scope: v2.4 — Integration Tests & WP 7.0 Readiness -->

- [ ] Integration test harness using WordPress test scaffolding (WP_UnitTestCase)
- [ ] Integration test: full reauth flow (Gate → Challenge → Session → Stash → Replay)
- [ ] Integration test: REST API gating with real cookie auth and application passwords
- [ ] Integration test: session token binding (real cookies + real user meta)
- [ ] Integration test: upgrader migration chain against real database
- [ ] Integration test: Two Factor plugin interaction with real plugin installed
- [ ] Integration test: multisite session isolation
- [ ] Integration test: transient TTL enforcement (stash expiry, 2FA pending expiry)
- [ ] Integration test: bcrypt password verification (WP 6.8+ default)
- [ ] WP 7.0 visual verification (settings page, challenge page, admin bar against refreshed admin chrome)
- [ ] WP 7.0 functional verification (manual test guide execution on 7.0 beta/RC)
- [ ] "Tested up to" version bump for WP 7.0 GA
- [ ] Abilities API assessment document (surface type analysis, gating strategy for destructive abilities)

### Out of Scope

<!-- Explicit boundaries for this milestone. -->

- Abilities API implementation (gate surface code) — only 3 read-only abilities exist; defer until destructive abilities appear
- Block editor integration (gating content saves) — design decision: gate admin operations, not content operations
- Session extension/renewal — intentionally declined; zero-trust requires re-earning trust
- E2E browser tests (Playwright/Cypress) — integration tests are the priority; E2E is a separate future milestone
- Admin UI visual regression testing — manual visual check is sufficient for 7.0; automated visual testing is a separate concern
- Real-time collaboration conflict handling — sudo is per-user, not per-resource; no conflict exists with current architecture
- Environment diversity testing (Apache, multiple PHP versions, MariaDB, backward compat to WP 6.2) — future milestone, see `docs/roadmap-2026-02.md` section 5

## Context

- WordPress 7.0 Beta 1 scheduled Feb 19, 2026; GA April 9, 2026
- Both dev sites (Local + Studio) are on 7.0-alpha-61682 with wp-sudo 2.3.2 active
- Key WP 7.0 changes: always-iframed post editor, admin visual refresh (DataViews/design tokens), Fragment Notes with @ mentions, Abilities API expansion, WP AI Client merge proposal
- WP 6.8+ defaults to bcrypt for password hashing — unit tests mock `wp_check_password()` so this has never been exercised
- LLM confabulation history documented in `llm_lies_log.txt` — 5 fabricated claims fixed, verification requirements added to CLAUDE.md
- Codebase mapped in `.planning/codebase/` (7 documents, 1,575 lines)
- Roadmap assessment in `docs/roadmap-2026-02.md` identifies 9 integration test gaps

## Constraints

- **PHP version**: 8.0+ minimum, 8.3+ recommended — matches existing plugin requirement
- **WordPress version**: 6.2+ minimum — must maintain backward compat while testing on 7.0
- **No production dependencies**: Plugin is self-contained; integration test dependencies go in require-dev only
- **TDD**: All new code requires tests first (CLAUDE.md requirement)
- **Verification**: External code references must be verified against live sources before committing (CLAUDE.md requirement)
- **Timeline**: WP 7.0 GA is April 9, 2026 — "Tested up to" bump must happen by then

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Use WP_UnitTestCase over custom harness | Official WordPress test scaffolding gives real DB, real functions, maintained compatibility | — Pending |
| Integration tests in separate directory from unit tests | Keep Brain\Monkey unit tests fast; integration tests are slower and need WP loaded | — Pending |
| Don't gate Abilities API yet | Only 3 read-only abilities in WP 7.0; no destructive abilities to gate | — Pending |
| bcrypt verification via integration tests | Can't unit-test real password hashing; integration tests exercise `wp_check_password()` naturally | — Pending |

---
*Last updated: 2026-02-19 after initialization*
