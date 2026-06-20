# Codex Comprehensive Review — 2026-06-20

Audience: Claude / GSD executor
Branch reviewed: `gsd/plan-13.1-and-14`
Base reviewed: recent `origin/main` progress through `6abd333`

## Executive recommendation

**Do Phase 13.1 Plan 02 next, before deeper WordPress.org readiness work.**

Reason: the current highest-risk gap is not production PHP behavior; it is QA coverage/tooling drift. `access-grant.spec.ts` exists but is not wired into E2E CI, and the scheduled WordPress compat sweep still runs PHP 8.1 even though v4.0.0 now requires PHP 8.2. Fixing this first gives trustworthy CI for the remaining v4.0.0/.org-readiness edits.

After Phase 13.1 Plan 02 is merged/green, run Phase 14-01 for `readme.txt` / plugin header compliance.

## Local validation performed

Passed locally:

- `composer test:unit` — 812 tests / 2315 assertions
- `composer test:integration` — 199 tests / 669 assertions / 11 skipped
- `composer lint`
- `composer analyse`
- `composer verify:metrics`
- `composer audit`

Not clean / needs tracking:

- `npm audit` reports dev-tooling vulnerabilities through `@wordpress/env` / `@wp-playground/*` transitive dependencies:
  - `ws` high severity
  - `js-yaml` moderate severity
- `npm audit --omit=dev` reports 0 vulnerabilities, so this is tooling-only, not production package exposure.

GitHub status observed:

- PR #90 checks were passing.
- Latest `main` checks on `6abd333` were green: PHPUnit, Psalm, E2E, Plugin Check, CodeQL, docs.

## Findings to act on

### P1 — Compat sweep still uses PHP 8.1

File: `.github/workflows/compat-wordpress-minors.yml`
Lines observed: job name around line 14, `setup-php` around line 61

Problem: v4.0.0 requires PHP `>=8.2`, but the weekly compat sweep still says and installs PHP 8.1. Composer install will fail in that scheduled workflow.

Recommended fix:

- Change workflow label to PHP 8.2.
- Change `php-version: '8.1'` to `php-version: '8.2'`.
- Update `docs/current-metrics.md` CI Matrix Snapshot accordingly.

### P1 — `access-grant.spec.ts` is not assigned to E2E CI

File: `.github/workflows/e2e.yml`
Area: 4 E2E group commands

Problem: `tests/e2e/specs/access-grant.spec.ts` exists and contains ACCESS-01/ACCESS-02, but no E2E workflow group invokes it. This means the security-sensitive Access tab grant flow is not protected by CI.

Recommended fix:

- Add `tests/e2e/specs/access-grant.spec.ts` to exactly one E2E group, preferably group 3 per existing Phase 13.1 Plan 02.
- Re-run/derive E2E count with `npx playwright test --config tests/e2e/playwright.config.ts --project=chromium --list` after wiring.
- Update `docs/current-metrics.md`.

### P2 — `readme.txt` short description exceeds WordPress.org limit

File: `readme.txt`
Line observed: line 12

Problem: short description is 204 chars; WordPress.org limit is 150.

Recommended fix:

Use the Phase 14-01 planned shorter description or similar, then verify with:

```bash
awk 'NR==12{print length($0)}' readme.txt
```

### P2 — Plugin header / readme name mismatch risk

Files:

- `wp-sudo.php` — `Plugin Name: WP Sudo`
- `readme.txt` — `=== Sudo ===`

Problem: Plugin Check can report `plugin_name_mismatch`. Product decision says product name is `Sudo`; slug/text-domain/package identifiers remain `wp-sudo` intentionally.

Recommended fix:

- Change only the plugin header display name to `Plugin Name: Sudo` during Phase 14-01.
- Do not change slug, text domain, Composer package name, or plugin folder name.

### P2 — Connector integration test cleanup should use `try/finally`

File: `tests/Integration/ConnectorsMatcherTest.php`
Area: `test_conn02_custom_api_key_connector_is_auto_gated()`

Problem: the test registers a connector on the live `WP_Connector_Registry` singleton and unregisters at the end. If an assertion fails before cleanup, the custom connector and `Action_Registry` cache can leak into later tests.

Recommended fix:

- Wrap the post-register test body in `try/finally`.
- In `finally`, call `$registry->unregister( $connector_id );` and `Action_Registry::reset_cache();`.

### P3 — PHP 8.5 deprecation in integration test

File: `tests/Integration/GovernanceTest.php`
Line observed: `ReflectionProperty::setAccessible( true )`

Problem: tests pass, but PHP 8.5 emits a deprecation because `setAccessible()` has had no effect since PHP 8.1.

Recommended fix:

- Remove `setAccessible( true )` or guard it.

### P3 — Assistant/tooling docs have stale floors

File: `.github/copilot-instructions.md`
Line observed: runtime requirements still say WordPress 6.2+, PHP 8.0+

Problem: v4.0.0 raises floors to WordPress 6.4 and PHP 8.2. Stale assistant instructions can cause future agents to reintroduce old assumptions.

Recommended fix:

- Update during Phase 14 docs cleanup or as part of Phase 13.1 metrics/tooling cleanup.

### P3 — NPM audit visibility missing

Problem: Composer audit is covered by `.github/workflows/composer-security.yml`; npm dev-tool audit is not. Current dev-only dependency tree reports known upstream issues.

Recommended fix:

- Add a scheduled npm audit workflow or Dependabot/Renovate tracking.
- Make it informational/non-blocking initially if upstream `@wordpress/env`/Playground prevents immediate clean resolution.
- Document exceptions explicitly so this does not surprise release reviewers.

## Suggested execution order

1. **Execute Phase 13.1 Plan 02 now**
   - Update `compat-wordpress-minors.yml` PHP 8.1 → 8.2.
   - Wire `access-grant.spec.ts` into E2E.
   - Trim/cache/rebalance CI as already planned.
   - Update `docs/current-metrics.md` first/alongside.

2. **Then execute Phase 14-01**
   - `readme.txt` short description <=150 chars.
   - Remove stale version-specific prose.
   - Change `Plugin Name: Sudo` in `wp-sudo.php`.
   - Assert version/license consistency.

3. **Then do small hardening follow-ups**
   - `try/finally` cleanup in `ConnectorsMatcherTest`.
   - Remove PHP 8.5 `ReflectionProperty::setAccessible()` deprecation.
   - Update `.github/copilot-instructions.md` stale floors.

4. **Then add dependency-maintenance visibility**
   - Dependabot/Renovate for npm/GitHub Actions.
   - Informational npm audit workflow or documented exception list.

## Release-readiness stance

Do not treat v4.0.0 as release-ready until these are true:

- Access grant E2E spec runs in CI.
- All active CI workflows align with PHP 8.2 / WP 6.4 floors.
- `readme.txt` passes WordPress.org validation.
- Plugin Check passes against a clean dist with no name/stable-tag blockers.
- Current metrics/docs no longer claim old PHP/WP floors.

---

## Codex follow-up status — 2026-06-20

After this review note was created, Codex applied the safe local follow-ups in the working tree:

- Patched `.github/workflows/compat-wordpress-minors.yml` from PHP 8.1 to PHP 8.2.
- Trimmed `.github/workflows/phpunit.yml` integration matrix to 7 lanes and added WordPress test-library caching.
- Wired `tests/e2e/specs/access-grant.spec.ts` into `.github/workflows/e2e.yml` and rebalanced CHAL-09/10/11 out of the previous 2FA-heavy group.
- Added informational `.github/workflows/npm-audit.yml` and npm Dependabot tracking.
- Updated `.github/copilot-instructions.md` floors to WordPress 6.4+ / PHP 8.2+.
- Hardened `tests/Integration/ConnectorsMatcherTest.php` registry cleanup with `try/finally`.
- Removed the PHP 8.5-deprecated `ReflectionProperty::setAccessible()` call from `tests/Integration/GovernanceTest.php`.
- Updated `readme.txt` short description and removed inline raw-GitHub screenshots from the WordPress.org screenshot section.
- Changed plugin header display name to `Sudo` in `wp-sudo.php`.
- Updated `docs/current-metrics.md` from live local verification.

Validation after these changes:

- `composer test:unit` — 812 tests / 2315 assertions.
- `composer test:integration` — 199 tests / 669 assertions / 11 skipped.
- `WP_MULTISITE=1 composer test:integration` — 199 tests / 681 assertions / 4 skipped.
- `composer lint` — passed.
- `composer analyse` — passed.
- `composer verify:metrics` — passed.
- `composer audit` — passed.
- `npm audit --omit=dev` — 0 vulnerabilities.
- Full `npm audit` still reports dev-tooling vulnerabilities (1 high, 15 moderate); the new workflow reports these as informational warnings rather than a blocking release gate.

Remaining for Claude / GSD:

1. Review this working tree and decide whether to keep all changes together or split into two commits/PRs:
   - CI/tooling/test hardening.
   - WordPress.org readme/header readiness.
2. Let GitHub CI prove the workflow changes, especially WP test-library cache restore on a second run and ACCESS-01/02 visibility in E2E logs.
3. Continue Phase 14 brand/security/submission checklist items not covered here.
