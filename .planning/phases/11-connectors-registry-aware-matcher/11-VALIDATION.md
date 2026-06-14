---
phase: 11
slug: connectors-registry-aware-matcher
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-14
---

# Phase 11 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Sourced from `11-CONTEXT.md` (decisions + Design Review section) — `--skip-research` was used; domain research lives in `.planning/research/v4.0/RESEARCH.md`.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 9.6 — Brain\Monkey (unit, mocks WP fns) + `WP_UnitTestCase` (integration, real WP 7.0) |
| **Config file** | `phpunit.xml.dist` (unit), `phpunit-integration.xml.dist` (integration) |
| **Quick run command** | `composer test:unit` |
| **Full suite command** | `composer test:unit && composer analyse` (unit + PHPStan L6); `composer test:integration` when MySQL/WP test suite available |
| **Estimated runtime** | unit ~0.4s; PHPStan ~seconds; integration ~minutes (DB) |

---

## Sampling Rate

- **After every task commit:** Run `composer test:unit`
- **After every plan wave:** Run `composer test:unit && composer analyse` (+ `composer test:integration` when DB available)
- **Before `/gsd:verify-work`:** Full unit + integration green, PHPStan L6 green
- **Max feedback latency:** ~1s (unit tier)

---

## Per-Task Verification Map

Task IDs are assigned by the planner; rows below map each requirement + design-review test to its verification. Registry-path unit tests stub `wp_get_connectors()` via Brain\Monkey; integration tests run on the WP 7.0 CI lane with the real Connectors registry.

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| TBD | TBD | 1 | CONN-01 | unit (mock `wp_get_connectors` → Akismet) + integration (WP 7.0) | `composer test:unit` / `composer test:integration` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | CONN-02 | integration (custom connector via `$registry->register()` w/ arbitrary `setting_name`) | `composer test:integration` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | CONN-03 | unit + integration (`connectors_ai_openai_api_key` still gated — regression) | `composer test:unit` | ⚠️ extends `tests/Unit/GateTest.php` | ⬜ pending |
| TBD | TBD | 1 | CONN-04 | unit (`wp_get_connectors` absent → regex still gates `connectors_*_api_key`) | `composer test:unit` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | CONN-05 | unit (benign `blogname`/`siteurl`/`timezone_string` NOT gated under union) | `composer test:unit` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | CONN-06 | manual — see Manual-Only (docs updated + commit cites verified core source) | n/a | n/a | ⬜ pending |
| TBD | TBD | 1 | DR-1 (design review) | unit (`wp_get_connectors` absent → `connectors_ai_openai_api_key` still gated; preserves pre-7.0 contract) | `composer test:unit` | ❌ W0 | ⬜ pending |
| TBD | TBD | 1 | DR-2 (design review) | unit (after `reset_cache()`, second eval re-reads `wp_get_connectors()`; pins cache-invalidation contract) | `composer test:unit` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] Unit tests for the two-tier matcher — extend `tests/Unit/GateTest.php` (existing connector coverage: `test_match_request_matches_rest_connector_credentials_settings`) or a focused new test class. Cover CONN-03, CONN-04, CONN-05, DR-1, DR-2. Stub `wp_get_connectors()` per test (Brain\Monkey); the static cache MUST be cleared by `reset_cache()` in tearDown (design review Finding 1).
- [ ] Integration tests in `tests/Integration/` — CONN-01 (Akismet `wordpress_api_key` gated), CONN-02 (custom-registered connector with arbitrary `setting_name` gated). Require the WP 7.0 Connectors registry.
- [ ] No framework install — PHPUnit 9.6 + Brain\Monkey + Mockery already present.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Docs updated + commit cites verified WP core source | CONN-06 | "commit message cites the verified `wordpress-develop` source" is a confabulation-prevention process check, not an automatable assertion | Reviewer confirms `docs/connectors-api-reference.md` + `docs/developer-reference.md` document the two-tier matcher + custom-connector auto-gating, the `api_key`-only re-scoping note is present, and the implementation commit cites the verified core source (per CLAUDE.md verification rule) |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies (CONN-06 is the only manual-only item — process check)
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (new unit + integration test files)
- [ ] No watch-mode flags
- [ ] Feedback latency < ~1s (unit)
- [ ] `nyquist_compliant: true` set in frontmatter (planner/checker flips once task IDs are mapped)

**Approval:** pending
