# Requirements: Playwright E2E Test Infrastructure

**Defined:** 2026-03-08
**Core Value:** Every destructive admin action requires proof the person at the keyboard is still the authenticated user.
**Milestone:** v2.14

## v1 Requirements

Requirements for this milestone. Each maps to roadmap phases.

### Toolchain

- [x] **TOOL-01**: Playwright test suite runs locally with `npm test` and `npx playwright test`
- [x] **TOOL-02**: wp-env starts a clean WordPress instance with WP Sudo activated via `npx wp-env start`
- [x] **TOOL-03**: CI runs E2E tests in a separate workflow job that does not modify existing PHPUnit jobs
- [x] **TOOL-04**: First smoke test navigates to wp-admin and asserts WP Sudo settings page loads
- [x] **TOOL-05**: Global setup logs in once and persists WordPress auth cookies via `storageState`
- [x] **TOOL-06**: Sudo session cookies (`wp_sudo_token`) are never included in persisted `storageState`

### Cookie Verification

- [x] **COOK-01**: Test verifies `wp_sudo_token` cookie has `httpOnly: true` after session activation
- [x] **COOK-02**: Test verifies `wp_sudo_token` cookie has `sameSite: 'Strict'` after session activation
- [x] **COOK-03**: Test verifies `wp_sudo_token` cookie is set with correct path scope

### Admin Bar Timer

- [x] **TIMR-01**: Test verifies admin bar countdown timer is visible during active sudo session
- [x] **TIMR-02**: Test verifies timer text updates (is not static `--:--`)
- [x] **TIMR-03**: Test verifies `wp-sudo-expiring` CSS class is added when timer reaches 60s threshold
- [x] **TIMR-04**: Test verifies page reloads when timer reaches 0s (session expiry)

### MU-Plugin AJAX

- [x] **MUPG-01**: Test verifies MU-plugin install button triggers AJAX and shows spinner
- [x] **MUPG-02**: Test verifies success message appears after MU-plugin install completes
- [x] **MUPG-03**: Test verifies MU-plugin uninstall flow works via Settings page

### Gate UI

- [x] **GATE-01**: Test verifies plugin action buttons have `aria-disabled="true"` when no sudo session is active
- [x] **GATE-02**: Test verifies `wp-sudo-disabled` CSS class is applied to gated buttons
- [x] **GATE-03**: Test verifies clicking a disabled button does not navigate away from the page

### Challenge Flow

- [x] **CHAL-01**: Test exercises full stash-challenge-replay flow: trigger gated action → challenge page → enter password → AJAX auth → stash replay → destination
- [x] **CHAL-02**: Test verifies challenge page loads with correct form elements (password input, submit button, cancel link)
- [x] **CHAL-03**: Test verifies failed password attempt shows error message without page reload

### Visual Regression

- [x] **VISN-01**: Snapshot baseline captured for challenge page card element
- [x] **VISN-02**: Snapshot baseline captured for settings page form element
- [x] **VISN-03**: Snapshot baseline captured for admin bar timer node (active session)
- [x] **VISN-04**: Snapshot baselines committed to repository with configurable diff thresholds

### Keyboard Navigation

- [x] **KEYB-01**: Test verifies Tab key traverses challenge page form fields in correct order
- [x] **KEYB-02**: Test verifies Enter key submits challenge form
- [x] **KEYB-03**: Test verifies Ctrl+Shift+S shortcut navigates to challenge page when no session is active
- [x] **KEYB-04**: Test verifies Ctrl+Shift+S shortcut flashes admin bar node when session is active

### Admin Bar Interaction

- [x] **ABAR-01**: Test verifies clicking admin bar timer node deactivates the session
- [x] **ABAR-02**: Test verifies URL does not change after admin bar deactivation click

## v2 Requirements

Deferred to future milestone. Tracked but not in current roadmap.

### Accessibility Depth

- **A11Y-01**: ARIA live region announcements verified at timer milestones (60s, 30s, 10s, 0s)
- **A11Y-02**: `wp.a11y.speak()` call side effects verified via `#wp-a11y-speak-assertive` element
- **A11Y-03**: Color contrast verified for `wp-sudo-expiring` state via computed styles or axe-core
- **A11Y-04**: `prefers-reduced-motion` suppresses admin bar flash animation

### Rate Limiting UI

- **RATE-01**: Lockout countdown timer shown after 5 failed attempts
- **RATE-02**: Submit button disabled during lockout period
- **RATE-03**: Form re-enables after lockout expiry (via clock manipulation)

### Extended Visual Coverage

- **RESP-01**: Responsive layout snapshots at 6 viewports (1920x1080, 1366x768, 768x1024, 1024x768, 375x667, 390x844)
- **RESP-02**: Touch targets verified at minimum 44x44 px on mobile viewports
- **SJOM-01**: Session-only mode flow via admin notice link after AJAX block

## Out of Scope

| Feature | Reason |
|---------|--------|
| REST API endpoint testing via Playwright | Already covered by PHPUnit integration tests (RestGatingTest.php) |
| WP-CLI behavior via Playwright | CLI does not run in browser; already covered by manual testing + integration mocks |
| Full-page screenshots of every admin page | >20 baselines become maintenance burden; snapshot only WP Sudo surfaces |
| Two Factor TOTP real flow | TOTP seed management is high complexity; mock AJAX response instead |
| Performance benchmarking via Playwright | Local benchmarks are not reproducible; use Lighthouse CI separately if needed |
| Multi-browser matrix (Firefox, WebKit) | Adds ~500MB download + 3min CI time for no material coverage gain |
| Cypress as alternative framework | Playwright is WordPress ecosystem standard; Cypress has known issues with multi-page flows |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| TOOL-01 | Phase 6 | Complete |
| TOOL-02 | Phase 6 | Complete |
| TOOL-03 | Phase 6 | Complete |
| TOOL-04 | Phase 6 | Complete |
| TOOL-05 | Phase 6 | Complete |
| TOOL-06 | Phase 6 | Complete |
| COOK-01 | Phase 7 | Complete |
| COOK-02 | Phase 7 | Complete |
| COOK-03 | Phase 7 | Complete |
| TIMR-01 | Phase 7 | Complete |
| TIMR-02 | Phase 7 | Complete |
| TIMR-03 | Phase 7 | Complete |
| TIMR-04 | Phase 7 | Complete |
| MUPG-01 | Phase 7 | Complete |
| MUPG-02 | Phase 7 | Complete |
| MUPG-03 | Phase 7 | Complete |
| GATE-01 | Phase 7 | Complete |
| GATE-02 | Phase 7 | Complete |
| GATE-03 | Phase 7 | Complete |
| CHAL-01 | Phase 7 | Complete |
| CHAL-02 | Phase 7 | Complete |
| CHAL-03 | Phase 7 | Complete |
| VISN-01 | Phase 7 | Complete |
| VISN-02 | Phase 7 | Complete |
| VISN-03 | Phase 7 | Complete |
| VISN-04 | Phase 7 | Complete |
| KEYB-01 | Phase 8 | Complete |
| KEYB-02 | Phase 8 | Complete |
| KEYB-03 | Phase 8 | Complete |
| KEYB-04 | Phase 8 | Complete |
| ABAR-01 | Phase 8 | Complete |
| ABAR-02 | Phase 8 | Complete |

**Coverage:**
- v1 requirements: 32 total
- Mapped to phases: 32
- Complete: 32
- Unmapped: 0

---
*Requirements defined: 2026-03-08*
*Last updated: 2026-03-09 — all 32 v1 requirements complete (Phase 6-8 done)*
