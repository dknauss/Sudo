---
phase: 24
slug: session-revocation-ui
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-30
---

# Phase 24 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from the Validation Architecture in `24-RESEARCH.md`.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 9.6 with Brain\Monkey, Mockery, Patchwork |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/AdminTest.php` |
| **Full suite command** | `composer test` |
| **Static analysis** | `composer analyse` |
| **Lint** | `composer lint` |
| **Estimated runtime** | Targeted Admin/Session test ~1s; full unit suite ~1s; PHPCS/PHPStan longer but required before code commit |

---

## Sampling Rate

- **After every task that edits tests or revocation code:** Run the targeted file
  test (`AdminTest.php`, `SudoSessionTest.php`, or `CliCommandTest.php` per the task).
- **After every plan wave:** Run `composer test`.
- **Before code commit:** Run `composer test`, `composer analyse`, and `composer lint`
  as separate commands.
- **Before `/gsd:verify-work`:** Full suite green; `composer verify:metrics` if
  implementation changes canonical counts (surfaces/hooks/fields).
- **Max feedback latency:** keep targeted feedback under 10 seconds.

---

## Per-Task Verification Map

Plan/task IDs are provisional until the planner finalizes wave layout; each REVK
requirement maps to an automated unit assertion in the existing harness.

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 24-01-01 | 01 | 1 | REVK-03 (factored core: cap + rate limit + audit hook + reason tag) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/AdminTest.php` | ✅ | ⬜ pending |
| 24-01-02 | 01 | 1 | REVK-02 (shared current-site revoke-all method, excl. operator) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/SudoSessionTest.php` | ✅ | ⬜ pending |
| 24-01-03 | 01 | 1 | REVK-02/03 (CLI still routes through shared method) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/CliCommandTest.php` | ✅ | ⬜ pending |
| 24-02-01 | 02 | 2 | REVK-01 (row-action visibility gate + self/active-set/no-cap cases) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/AdminTest.php` | ✅ | ⬜ pending |
| 24-02-02 | 02 | 2 | REVK-01 (admin_post_ handler: nonce + cap + sudo-gate → deactivate → redirect) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/AdminTest.php` | ✅ | ⬜ pending |
| 24-02-03 | 02 | 2 | REVK-02 (revoke-all interstitial confirm + perform, count + one rate slot) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/AdminTest.php` | ✅ | ⬜ pending |
| 24-02-04 | 02 | 2 | REVK-05 (three distinct blocked-path messages + success, no silent no-op) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/AdminTest.php` | ✅ | ⬜ pending |
| 24-03-01 | 03 | 3 | REVK-04 (Access-tab Revoke button removed; orphaned AJAX action removed) | unit | `./vendor/bin/phpunit --configuration phpunit.xml.dist tests/Unit/AdminTest.php` | ✅ | ⬜ pending |
| 24-03-02 | 03 | 3 | REVK-01..05 (full gate) | validation | `composer test`, `composer analyse`, `composer lint` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Existing infrastructure covers all phase requirements:

- [x] `tests/Unit/AdminTest.php` exists and already covers `handle_revoke_session()`
      (cap check, rate limit, audit hook).
- [x] `tests/Unit/SudoSessionTest.php` exists and covers `Sudo_Session` lifecycle.
- [x] `tests/Unit/CliCommandTest.php` exists and covers the CLI revoke-all path.
- [x] `phpunit.xml.dist`, Brain\Monkey, Mockery, and Patchwork are configured.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Pre-implementation design review | REVK-01..05 | Project policy: new UI surface + capability checks + multisite/Users-list contexts require design review before code. | Run the design-reviewer agent on the brief before writing tests/code; no non-doc commit before reviewer approval. |
| Interstitial confirm + dismissible notice rendering | REVK-02, REVK-05 | Live wp-admin Users-screen layout / notice rendering is not meaningfully unit-assertable; message *selection* and *count* are covered automatically. | On a Studio/wp-env multisite, trigger revoke-all → confirm exact count, Confirm/Cancel; trigger each blocked path → confirm distinct dismissible notice with aria announcement. |

All functional decision logic and guardrails have automated unit coverage.

---

## Validation Sign-Off

- [x] All tasks have automated verify or Wave 0 dependencies.
- [x] Sampling continuity: no 3 consecutive tasks without automated verify.
- [x] Wave 0 covers all missing references (none missing — existing harness suffices).
- [x] No watch-mode flags.
- [x] Feedback latency < 10s for targeted checks.
- [x] `nyquist_compliant: true` set in frontmatter.

**Approval:** approved 2026-06-30 for planning; execution still requires
security-sensitive design + pre-commit reviewer approval before non-doc commit.
