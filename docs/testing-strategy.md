---
status: current
applies_to: 4.8.x
last_verified: 2026-07-25
---

# Testing Strategy

> Relocated from `ROADMAP.md` §§1, 4–8 on 2026-07-25. Integration-test scope,
> the TDD/context-collapse rationale, environment-diversity, coverage, mutation,
> and exit-path testing.

## Integration Tests — Scope and Value

> **Status: Complete.** The integration test suite shipped in v2.4.0 (55 tests) and
> expanded in v2.4.1 (73 tests). CI now runs targeted compatibility lanes across
> PHP 8.2/8.3 and WordPress 6.4, 6.7, and 7.0 GA with single-site +
> multisite coverage. The analysis below is preserved for context on what drove
> the test design.

### What unit tests cover well (no integration gap)
- Request matching across all 7 surfaces (98 GateTest methods)
- Session state machine, token crypto, rate limiting
- Hook registration and filter application
- Policy enforcement (DISABLED/LIMITED/UNRESTRICTED)
- Upgrader migration logic
- Settings sanitization and defaults

### What unit tests cannot cover (real integration gaps)

These gaps have been closed by the integration suite:

| Gap | Integration coverage (v2.9.2) |
|-----|-------------------------------|
| **Cross-class workflows** (Gate → Challenge → Session → Stash) | `ReauthFlowTest` — 4 end-to-end tests |
| **Request stash replay** | `RequestStashTest` — 7 tests including transient TTL |
| **Real `wp_check_password()`** | `SudoSessionTest` — 10 tests with real bcrypt |
| **Transient TTL enforcement** | `RequestStashTest`, `SudoSessionTest` |
| **Two Factor plugin interaction** | `TwoFactorTest` — 7 tests with real provider |
| **Database state after migrations** | `UpgraderTest` — 4 tests against real options/meta |
| **REST API with real auth** | `RestGatingTest` — 7 tests with cookie and app password auth |
| **AJAX gating** | `AjaxGatingTest` — 12 tests covering all 7 declared AJAX actions |
| **Audit hooks** | `AuditHooksTest` — 11 tests across CLI, Cron, XML-RPC, REST |
| **Rate limiting** | `RateLimitingTest` — 6 tests with real user meta |
| **Multisite isolation** | `MultisiteTest` — 5 tests |
| **WPGraphQL surface gating** | `WpGraphQLGatingTest` — 16 tests (policy modes, mutation detection, bypass filter) |
| **Exit paths and grace window** | `ExitPathTest` — 9 tests (REST, AJAX, WPGraphQL, admin redirect, challenge auth, grace window) |
| **Uninstall cleanup** | `UninstallTest` — 2 tests (single-site + multisite) |
| **Login grants sudo** | `LoginSudoGrantTest` — 3 tests |
| **Password change expiry** | `PasswordChangeExpiryTest` — 8 tests |

### Remaining integration gaps

**Not addressable with PHPUnit:**
- **Cookie/header behavior** — `setcookie()` still guarded by `headers_sent()` check.
  Real httponly/SameSite attributes require browser-level testing (Playwright/Cypress).
- **Hook timing and priority** — no automated test verifies `admin_init` priority 1
  ordering relative to other plugins. Covered by manual testing guide.
- **Admin UI rendering** — visual correctness tested manually, not automated.

**Addressable — potential PHPUnit improvements:**

| Opportunity | Current state | Value |
|-------------|--------------|-------|
| ~~**Admin settings CRUD integration tests**~~ | ~~`Admin` class (1,244 lines) has 55 unit tests but no integration tests.~~ ✅ Done v2.9.2 — 8 integration tests in `tests/Integration/AdminTest.php` covering defaults, persistence, sanitization clamping, per-app-password overrides, and multisite `site_option` storage. | ~~Medium~~ |
| ~~**REST cookie-auth `_wpnonce` fallback**~~ | ~~Gate checks `X-WP-Nonce` header only.~~ ✅ Fixed v2.9.2 — fallback checks `$_REQUEST['_wpnonce']` (mirrors WP core `rest_cookie_check_errors()`). 2 unit tests in `GateTest.php`. | ~~Medium~~ |
| ~~**Admin integration test for MU-plugin install/remove**~~ | ~~MU-plugin install button and status detection tested manually only.~~ ✅ Covered by E2E (not PHPUnit, the correct home for this UI flow) — `tests/e2e/specs/mu-plugin.spec.ts` exercises the real AJAX install/uninstall via the Settings page: MUPG-01 (install → "Installed"), MUPG-02 (success icon), MUPG-03 (uninstall → "Not installed"), plus a 403-without-session check. | ~~Low~~ |
| ~~**Plugin lifecycle integration tests**~~ | ~~Activation, deactivation, and uninstall are tested (2 uninstall tests), but activation/deactivation hooks lack dedicated integration coverage.~~ ✅ Done — `tests/Integration/PluginLifecycleTest.php` (PR #121): single-site activation (cap strip, governance-cap grant, flag, cron), deactivation (restore, clear, preserve settings/meta), round-trip, idempotency, and multisite network activation. | ~~Low~~ |
| ~~**`@runInSeparateProcess` exit paths / `Set-Cookie` output**~~ | ~~9 tests use WPDieException + output capture. Cannot verify real HTTP headers or `Set-Cookie` output.~~ ✅ Covered by E2E, which observes the browser's parsed cookie state (the net effect of `Set-Cookie`): `cookie.spec.ts` COOK-01/02/03 assert `httpOnly` / `SameSite=Strict` / `path=/` on activation; `admin-bar-deactivate.spec.ts` ABAR-01 asserts the token cookie is cleared on the deactivation exit path; `admin-bar-timer.spec.ts` TIMR-04 covers time-based expiry. Only `Secure` over HTTPS remains untestable on the http wp-env stack. | ~~Low~~ |

**Deferred to browser-level testing (Playwright/Cypress):**

| Scenario | Why browser-level |
|----------|-------------------|
| Challenge page cookie attributes (httponly, SameSite, Secure) | `setcookie()` output not capturable in PHPUnit |
| Admin bar countdown timer JS accuracy | Requires real DOM + `setInterval` |
| MU-plugin install button AJAX flow | Button click → AJAX → file copy → status update |
| Block editor snackbar integration (future) | Requires `@wordpress/notices` API in browser |
| Challenge page keyboard navigation | Real focus management needs browser DOM |

---

## Context Collapse and TDD

### The problem as the codebase grows

WP Sudo is a substantial, growing PHP codebase (current size in `current-metrics.md`). In an LLM-assisted workflow, context
collapse means: the model can't hold the full codebase in context, starts making
changes that conflict with code it hasn't read, invents function signatures instead
of looking them up, and introduces subtle regressions.

This is exactly how the confabulation errors happened — training-data guesses
substituted for verifiable facts. The same failure mode applies to the plugin's
own code as it grows.

### What helps (in order of effectiveness)

**1. TDD — the single most effective mitigation**

A "tests first, always" rule works because:
- It forces reading the existing code before writing new code (you need to know
  what to assert against)
- It catches regressions immediately, even when the LLM can't hold the full
  codebase in context
- It creates a machine-verifiable contract — the tests don't care whether the
  code was written by a human or an LLM
- It prevents the "lazy shortcut" failure where generated code looks right but
  doesn't actually work

**2. CLAUDE.md as the architectural single source of truth**

Already started. The Architecture section in CLAUDE.md is the most important
defense against context collapse — it tells the LLM what exists without requiring
it to read every file. Keep expanding it as new classes/surfaces are added.

**3. Small, focused commits**

Already practiced. Each commit should touch one concern. This limits the amount
of context needed per change and makes `git diff` reviewable.

**4. PHPStan level 6 as a guardrail**

Already in place. Static analysis catches type mismatches, undefined methods, and
wrong argument counts — exactly the errors an LLM makes when it invents function
signatures.

**5. Pre-commit test gate**

Already in Commit Practices ("Always run tests before committing"). Could be
enforced with a git pre-commit hook, but the CLAUDE.md instruction is sufficient
for LLM-assisted work since Claude follows it.

### What about Amp, Beads, etc.?

These are workflow tools for managing LLM context across sessions:
- **Amp** — context management for Claude Code sessions
- **Beads** — structured context passing between LLM calls

They address a real problem (session continuity), but they're additive tooling,
not fundamental mitigations. TDD + CLAUDE.md + PHPStan address the root cause:
the LLM writes code it can't verify. Tests verify it mechanically. Static analysis
catches type-level errors. CLAUDE.md provides architectural context without needing
to read every file.

If the project grows to 30k+ lines, context management tools become more valuable.
At the plugin's current size, the bottleneck is verification (tests, linting, static analysis),
not context retrieval.

---

## Environment Diversity Testing (Future Milestone)

The integration test suite and manual testing guide run against multiple local
environment stacks: nginx + SQLite (Studio), nginx + MySQL (Local), and Apache + MySQL
(Local) on macOS with a single PHP version. Apache coverage was added in v2.8.0 via
Local by Flywheel sites. Gaps remain in CI and broader hosting diversity.

### Dimensions to cover

| Dimension | Current coverage | Gap |
|-----------|-----------------|-----|
| **Web server** | Apache + MariaDB (`wp-env` Playwright CI), nginx + php-fpm + MariaDB (stack-smoke CI), nginx + SQLite (Studio local), nginx/Apache + MySQL (Local manual) | full browser suite still runs only on the default Apache stack |
| **PHP version** | 8.2–8.4 (unit CI), 8.2/8.3 (integration CI), 8.2 (Studio/wp-env local) | 8.4 is not in integration CI |
| **Database** | MySQL 8.0 (integration CI), MariaDB LTS (`wp-env` CI + one integration lane + WP 6.4 / 6.5 compat-sweep lanes), SQLite (Playground stack-smoke CI + Studio local) | broader MariaDB/version overlap, MySQL 5.7 legacy hosts |
| **WordPress version** | 6.4 support-floor lane, 6.4–6.6 scheduled compat sweep, 6.7 stable lane, 7.0 forward lane | 6.5–6.6 are not part of required push/PR CI yet |
| **OS** | macOS (dev), Ubuntu 24.04 (CI) | Windows (if any WP-CLI or path handling is OS-sensitive) |
| **Hosting stack** | Bare local dev | Shared hosting (cPanel), managed WP (Pressable, WP Engine, Cloudways), containerized (Docker, Kubernetes) |

### Why this matters for WP Sudo specifically

- **Apache `mod_rewrite` vs nginx `try_files`:** The challenge page redirect and
  request replay depend on WordPress rewrite rules. Apache's `.htaccess` and nginx
  configs handle these differently. The REST API `Authorization` header handling
  also differs (Apache may strip it unless `CGIPassAuth` or `.htaccess` rules are
  in place).
- **PHP version differences:** `password_verify()` behavior, `setcookie()` signature
  changes (PHP 8.0 named params), `session_*` function availability,
  `json_validate()` (8.3+), readonly properties (8.2+).
- **Database engine:** MariaDB and MySQL have subtle JSON and collation differences.
  The upgrader migration chain and option serialization could behave differently.
- **Backward compat:** The plugin declares WordPress 6.4+ minimum. CI now includes
  a dedicated 6.4 floor lane, a scheduled 6.4–6.6 compatibility sweep, plus 6.7
  and 7.0 lanes.

### Recommended approach

**Phase A: Expand CI matrix** ✅ Done v2.9.2, extended in v2.14.x

CI matrix now covers PHP 8.2–8.4 for unit tests, integration lanes on PHP 8.2 and 8.3 for WordPress 6.4/6.7/7.0, one dedicated MariaDB lane, and a scheduled WordPress 6.4–6.6 compatibility sweep on PHP 8.1 with additional WordPress 6.4 and 6.5 MariaDB overlap lanes.

**Phase B: Apache + MariaDB CI job** ✅ Covered by Playwright `wp-env`

The Playwright workflow already runs against the default `wp-env` Docker stack,
which is Apache + MariaDB. That lane is now named explicitly in CI so it is
visible as an intentional compatibility signal rather than an accidental default.

**Phase C: Manual testing matrix (low effort, recurring)** — ✅ checklist section added by external PR [#98](https://github.com/dknauss/Sudo/pull/98) (merged 2026-06-21); the recurring per-release runs remain.

`tests/MANUAL-TESTING.md` now has a "Release Environment Matrix Checklist". Before each
release, run the manual guide on at least:
- One Apache environment (DDEV, MAMP, or a staging host)
- One managed WordPress host (Pressable, WP Engine, or Cloudways free trial)
- The minimum supported WordPress version (the floor in `docs/release-status.md`; 6.4 as of v4.0.0)

**Phase D: Docker-based local testing (medium effort)** ➜ partially completed

The repo now has a dedicated nginx + php-fpm + MariaDB Compose stack under
`.github/docker/` for CI/browser smoke coverage. A broader contributor-friendly
multi-profile local stack is still future work:
- `apache-mysql` (the classic LAMP stack)
- `nginx-mariadb` (alternative)
- `apache-sqlite` (WP 6.4+ SQLite support)

This lets any contributor reproduce the full matrix locally.

### Priority

Phase A (CI matrix expansion) is complete as of v2.9.2. Remaining phases (B–D)
are lower priority and should be scoped as a future milestone when Apache/MariaDB
testing or managed-host validation becomes a concern.

### Current state

As of 2026-03-23, the practical testing picture is:

- behavioral coverage is strong in the core challenge flow
- environment coverage is intentionally selective rather than exhaustive

Specifically, the repo now has:
- unit CI on PHP `8.0`–`8.4`
- integration CI on PHP `8.0`/`8.1`/`8.3`
- automated WordPress lanes for `6.4`, `6.7`, and `7.0`
- a scheduled WordPress `6.3`–`6.6` compatibility sweep
- one MariaDB integration lane in addition to the main MySQL `8.0` matrix, plus WordPress `6.4` and `6.5` MariaDB overlap lanes in the scheduled sweep
- Playwright E2E on Apache + MariaDB via `wp-env`
- Playwright stack-smoke coverage on explicit nginx + php-fpm + MariaDB
- Playwright stack-smoke coverage on explicit nginx + php-fpm + MariaDB multisite
- Playwright stack-smoke coverage on Playground SQLite
- local/manual SQLite verification documented for Studio in [`docs/studio-sqlite-release-runbook.md`](studio-sqlite-release-runbook.md)

Still missing from automation:
- broader MariaDB and legacy-MySQL breadth
- full browser-suite parity on alternate stacks

Recommended next-step test expansion remains intentionally narrow:
- add stack-sensitive smoke cases first, not full-suite parity on alternate stacks
- keep SQLite as release-only assurance plus smoke CI, not a merge gate
- add MariaDB/version overlap gradually instead of turning the compat sweep into a full cartesian matrix
- keep the separate nginx + MariaDB multisite smoke lane narrow: network-admin cancel/return plus one gated replay path before considering any promotion or deeper expansion

That is a good balance for current plugin risk: the dangerous-action challenge and replay flows are well covered, while future matrix expansion should focus on compatibility breadth rather than adding more low-signal tests to already-covered flows.

---

## Coverage Tooling (Baseline Established)

**Status:** A single PCOV coverage CI job runs against the unit test suite (PHP 8.3).
This establishes a baseline without adding overhead to the full integration matrix.

**What's in place:**
- `composer test:coverage` — runs unit tests with PCOV, generates `coverage.xml` + text summary
- CI job `unit-tests-coverage` — runs on every push/PR, uploads `coverage.xml` as artifact
- No failure threshold yet — the first run establishes the baseline

**What's deferred:**
- Coverage across the full integration matrix (8 jobs across PHP 8.1/8.3 ×
  WP latest/trunk × single/multisite). The marginal CI cost is not justified
  until the matrix is stable.
- Coverage badge. Unit tests mock WordPress functions via Brain\Monkey, so line
  coverage looks high while entire real code paths (bcrypt, transients, cookies)
  are untested. A badge communicates accuracy only once the integration suite
  is comprehensive and the environment matrix is broad.

**When to expand:** After the environment diversity milestone (Phase A CI matrix
expansion). At that point per-matrix-entry coverage adds signal: you can see
which combinations of PHP/WP versions hit paths the others miss.

---

## Mutation Testing (Deferred to Post-Environment-Diversity)

**Decision: add mutation testing (Infection PHP) after the environment diversity milestone.**

Mutation testing validates that tests actually detect failures by introducing small
code changes (mutations) and verifying the test suite catches them. This is the
right tool for a security plugin — it directly answers "would our tests catch a
regression in the session token comparison or rate limiting logic?"

**Why not now:**
- Infection re-runs the full test suite for every mutant. With the current suite
  (see `docs/current-metrics.md` for the current suite size), a full Infection run would take 10–30 minutes
  locally. That's acceptable for a pre-release check, not for CI on every push.
- The more valuable immediate gap is environment diversity: knowing the tests pass
  on Apache/MariaDB and WP 6.4–6.9 is higher confidence signal than mutation score
  on a single stack.
- Mutation testing against mocked unit tests (Brain\Monkey) produces limited signal —
  mutations in production code are hidden by the mock boundary. Infection is most
  useful against the integration suite where real code runs.

**Recommended approach when the time comes:**

1. Run Infection against the integration suite only (`--test-framework-options="--config=phpunit-integration.xml.dist"`).
2. Configure a minimum mutation score indicator (MSI) of 80% as a pre-release gate,
   not a per-push CI gate.
3. Focus mutation scope on security-critical classes: `Sudo_Session`, `Gate`,
   `Challenge` — not `Admin`, `Admin_Bar`, or `Site_Health`.
4. Add a `composer mutation` script for local runs; keep it out of the standard CI
   matrix until the integration suite runs fast enough to justify the overhead.

---

## Exit Path Testing

**Status:** Partially addressed. 9 integration tests in `ExitPathTest.php` cover the 5 most critical exit paths plus 3 grace window scenarios, using REST dispatch and WPDieException + output capture instead of `@runInSeparateProcess`. The subprocess approach remains deferred.

The 76 `exit`/`die` paths in the codebase (mostly `wp_send_json()` + `exit` in the Gate) were the biggest remaining testing blind spot. The 9 tests added in v2.9.2 cover the security-critical shapes:

| Test | Pattern | Verifies |
|------|---------|----------|
| REST blocked mutation | `rest_get_server()->dispatch()` | 403, `sudo_required` error shape |
| AJAX blocked action | WPDieException + `ob_get_clean()` | JSON error body, `sudo_required` code |
| WPGraphQL blocked mutation | `check_wpgraphql()` + reconstructed JSON | 403, `sudo_blocked` error shape |
| Admin gating redirect | `wp_redirect` filter capture | 302 to challenge page with `stash_key` |
| Challenge wrong password | WPDieException + `ob_get_clean()` | JSON error, non-empty message |
| Challenge correct password | WPDieException + `ob_get_clean()` | JSON success, `authenticated` code, session active |
| Grace window admin pass | `wp_redirect` filter (null) | No redirect during 120s grace |
| Grace window admin block | `wp_redirect` filter capture | Redirect after grace closes |
| Grace window REST pass | `rest_get_server()->dispatch()` | No `sudo_required` during grace |

**Remaining:** The `@runInSeparateProcess` approach (real `exit()` + output capture + header assertions) is still deferred. The WPDieException pattern covers response body shape but cannot verify actual HTTP headers or `Set-Cookie` output. This matters most for the challenge success path (cookie-setting). Browser-level testing (Playwright) would cover this more naturally than subprocess PHPUnit.

---

