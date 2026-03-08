# Roadmap: Security Hardening Sprint

**Milestone:** Security Hardening Sprint
**Status:** Complete (all 4 phases delivered)
**Created:** 2026-03-03
**Depth:** Standard (4 phases)
**Source:** ROADMAP.md section 12, .planning/review/03-03-2026/PROPOSED-NEXT-STEPS-Claude.md

---

### Phase 1: Request Stash Redaction and Upload Action Coverage

**Goal:** Eliminate real data exposure in request stash (passwords stored verbatim in transients) and close the upload-plugin/upload-theme gating gap. Implement per-user stash cap to bound growth.
**Plans:** 4 plans

Plans:
- [x] 01-01-PLAN.md — Redaction TDD: sanitize_params() omits sensitive fields, wp_sudo_sensitive_stash_keys filter (Wave 1)
- [x] 01-02-PLAN.md — Upload rules TDD: plugin.upload and theme.upload added to Action_Registry (Wave 1, parallel)
- [x] 01-03-PLAN.md — Stash cap TDD: MAX_STASH_PER_USER=5, user meta index, enforce_stash_cap(), Challenge + uninstall updates (Wave 2)
- [x] 01-04-PLAN.md — Integration tests: redaction + cap + index verified against real WordPress/MySQL (Wave 3)

### Phase 2: Non-Blocking Rate Limiting

**Goal:** Replace blocking sleep() in auth failure path with non-blocking time-based throttling. Eliminate PHP-FPM worker exhaustion under concurrent failed auth attempts. Address TOCTOU race in failure counter.
**Plans:** 4 plans

Plans:
- [x] 02-01-PLAN.md — Core TDD: replace blocking delay with non-blocking throttle and add_user_meta append-row failure tracking in `Sudo_Session` (Wave 1)
- [x] 02-02-PLAN.md — Integration TDD: migrate scalar-counter-coupled tests and validate throttle/lockout/hook behavior in real WP flows (Wave 2)
- [x] 02-04-PLAN.md — UX TDD: wire delay through challenge AJAX handler and add client-side throttle disable/countdown behavior (Wave 3)
- [x] 02-03-PLAN.md — Cleanup TDD: uninstall/meta cleanup + security-model docs alignment + full gate verification (Wave 4)

### Phase 3: Rule Schema Validation and MU Loader Resilience

**Goal:** Add strict schema validation for wp_sudo_gated_actions filter output. Harden MU loader path detection for non-standard plugin directory layouts.
**Plans:** 3 plans

Plans:
- [x] 03-01-PLAN.md — Core TDD: normalize/validate filtered rules in `Action_Registry::get_rules()` and drop invalid rules fail-closed (Wave 1)
- [x] 03-02-PLAN.md — MU-loader TDD: remove hardcoded basename/path assumptions and add resilient fallback resolution (Wave 2)
- [x] 03-03-PLAN.md — Integration + docs + full-gate verification for Phase 3 contracts (Wave 3)

### Phase 4: WPGraphQL Persisted Query Strategy and WSAL Sensor

**Goal:** Document and handle persisted-query mutation detection in Limited mode. Ship WSAL sensor extension for enterprise audit visibility.
**Plans:** 3 plans

Plans:
- [x] 04-01-PLAN.md — WPGraphQL TDD: add persisted-query classification strategy with preserved secure fallback behavior (Wave 1; depends on Phase 3)
- [x] 04-02-PLAN.md — WSAL TDD: implement optional WSAL sensor bridge mapped from existing WP Sudo audit hooks (Wave 2)
- [x] 04-03-PLAN.md — Integration + docs + manual verification + full-gate closure for Phase 4 deliverables (Wave 3)
