# Requirements: WP Sudo v2.4

**Defined:** 2026-02-19
**Core Value:** Every destructive WordPress admin action requires reauthentication, verified by real infrastructure — not mocks.

## v2.4 Requirements

### Harness (HARN)

- [ ] **HARN-01**: Integration test harness exists with `WP_UnitTestCase` base class, separate from unit tests
- [ ] **HARN-02**: `phpunit-integration.xml.dist` runs integration tests against real WordPress + MySQL
- [ ] **HARN-03**: `tests/integration/bootstrap.php` loads plugin via `muplugins_loaded` hook
- [ ] **HARN-04**: `bin/install-wp-tests.sh` sets up WP test library and test database
- [ ] **HARN-05**: `composer test:unit` and `composer test:integration` run independently; `composer test` = unit only
- [ ] **HARN-06**: GitHub Actions CI runs both suites in separate jobs with MySQL 8.0 service
- [ ] **HARN-07**: Brain\Monkey unit tests continue to pass unchanged after harness is added

### Integration Tests — Core Security (INTG)

- [ ] **INTG-01**: Full reauth flow tested end-to-end — Gate intercepts gated action → stashes request → Challenge verifies password → Session activates → Stash replays
- [ ] **INTG-02**: `wp_check_password()` exercises real bcrypt hashing (WP 6.8+ default) — correct and incorrect password paths verified
- [ ] **INTG-03**: Session token binding verified — user meta hash matches `$_COOKIE` superglobal; expired session returns `is_active() === false`
- [ ] **INTG-04**: Transient write/read verified via real `set_transient()`/`get_transient()` — happy path; explicit `delete_transient()` cleanup path

### Integration Tests — Surface Coverage (SURF)

- [ ] **SURF-01**: Upgrader migration chain tested against real database — insert old-version options, run `Upgrader::maybe_upgrade()`, assert final DB state
- [ ] **SURF-02**: REST API gating tested with real cookie authentication — gated REST route returns `sudo_required` error without active session
- [ ] **SURF-03**: REST API gating tested with real application password — per-password policy override verified
- [ ] **SURF-04**: Audit hooks fire with correct arguments during reauth flow — `did_action()` assertions for `wp_sudo_action_gated`, `wp_sudo_action_allowed`, `wp_sudo_action_replayed`
- [ ] **SURF-05**: Rate limiting persists across calls with real user meta — 5 failed attempts trigger lockout via real `update_user_meta()`

### Integration Tests — Advanced (ADVN)

- [ ] **ADVN-01**: Two Factor plugin interaction tested with real plugin installed — `is_user_using_two_factor()` detection and 2FA pending state machine
- [ ] **ADVN-02**: Multisite session isolation verified — session activated on site A, `is_active()` returns false on site B for same user
- [ ] **ADVN-03**: Multisite integration tests run in CI via `WP_TESTS_MULTISITE=1` matrix variant

### WP 7.0 Compatibility (WP70)

- [ ] **WP70-01**: Manual testing guide (`tests/MANUAL-TESTING.md`) executed against WP 7.0 beta/RC with no regressions
- [ ] **WP70-02**: Settings page, challenge page, and admin bar render correctly under WP 7.0 admin visual refresh
- [ ] **WP70-03**: "Tested up to" version bumped to 7.0 in `readme.txt` and plugin header
- [ ] **WP70-04**: Abilities API assessment document written — evaluates whether any WP 7.0 abilities trigger existing gating rules; documents gating strategy for future destructive abilities

## v2.5 Requirements (Deferred)

### Extended Coverage

- **EXTD-01**: Capability tamper detection integration test — `unfiltered_html` granted to Editor, verify removal + `wp_sudo_capability_tampered` hook
- **EXTD-02**: Hook timing/priority integration test — Gate fires at `admin_init` priority 1 before competing hooks at priority 10
- **EXTD-03**: E2E browser tests (Playwright) — full challenge page interaction including JavaScript countdown timer
- **EXTD-04**: Abilities API gate surface implementation — new surface type `ability` in Gate class

## Out of Scope

| Feature | Reason |
|---------|--------|
| Admin UI rendering tests (HTML output assertions) | Brittle; manual testing more effective for visual changes |
| JavaScript/countdown timer tests | Requires separate Jest/Playwright toolchain; not in scope |
| CSS/asset enqueuing integration tests | Already covered by unit tests; server config concern |
| E2E browser tests | Separate milestone after integration tests are stable |
| Performance/load testing | Specialized tooling; not a plugin testing concern at this scale |
| Backward compat failure tests | Handled by declared minimum version; no test value |
| Direct SQL assertions | Plugin uses WP API; coupling to SQL internals is fragile |
| Session extension/renewal | Intentionally declined by design (zero-trust) |
| Block editor integration | Design decision: gate admin ops, not content ops |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| HARN-01 | Phase 1 | Pending |
| HARN-02 | Phase 1 | Pending |
| HARN-03 | Phase 1 | Pending |
| HARN-04 | Phase 1 | Pending |
| HARN-05 | Phase 1 | Pending |
| HARN-06 | Phase 1 | Pending |
| HARN-07 | Phase 1 | Pending |
| INTG-01 | Phase 2 | Pending |
| INTG-02 | Phase 2 | Pending |
| INTG-03 | Phase 2 | Pending |
| INTG-04 | Phase 2 | Pending |
| SURF-01 | Phase 3 | Pending |
| SURF-02 | Phase 3 | Pending |
| SURF-03 | Phase 3 | Pending |
| SURF-04 | Phase 3 | Pending |
| SURF-05 | Phase 3 | Pending |
| ADVN-01 | Phase 4 | Pending |
| ADVN-02 | Phase 4 | Pending |
| ADVN-03 | Phase 4 | Pending |
| WP70-01 | Phase 5 | Pending |
| WP70-02 | Phase 5 | Pending |
| WP70-03 | Phase 5 | Pending |
| WP70-04 | Phase 5 | Pending |

**Coverage:**
- v2.4 requirements: 23 total
- Mapped to phases: 23
- Unmapped: 0 ✓

---
*Requirements defined: 2026-02-19*
*Last updated: 2026-02-19 after initial definition*
