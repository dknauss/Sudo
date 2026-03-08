# Feature Research

**Domain:** Playwright E2E browser testing for a WordPress admin security plugin
**Researched:** 2026-03-08
**Confidence:** HIGH (codebase, JS source files, manual testing guide, existing docs — all verified against live files)

> Note on sources: WebSearch and WebFetch were unavailable during this research session.
> All findings draw from the project's own codebase (HIGH confidence): JavaScript files in
> `admin/js/`, CSS in `admin/css/`, `docs/ui-ux-testing-prompts.md`, `tests/MANUAL-TESTING.md`,
> `.planning/codebase/`, and the existing `.planning/research/` files. Playwright-specific
> patterns are based on training data (MEDIUM confidence) and are flagged where verification
> against official docs is recommended before implementation.

---

## Feature Landscape

### Table Stakes (Users Expect These)

For a Playwright E2E suite on a security plugin, "users" are the maintainers who need to
verify browser-level behavior that PHPUnit cannot reach. These are the tests without which
the E2E milestone fails to close the gaps it was created to address.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **Playwright toolchain setup (Node.js + config)** | Every other E2E feature depends on this. Without it, no browser-level test can run. | HIGH | Requires `npm init`, `@playwright/test`, `playwright.config.ts`, Chromium browser install, CI job. No build step currently exists in this project. This is the prerequisite for everything else. |
| **WordPress login helper (session fixture)** | Every test needs to start authenticated as an admin. Without a reusable login fixture, each test file reimplements authentication — brittle and slow. | MEDIUM | Playwright supports `storageState` to persist browser auth across tests. Login once, save session to file, load in subsequent tests. Eliminates WordPress login form from every test. |
| **Cookie attribute verification (HttpOnly, SameSite, Secure)** | The `wp_sudo_token` cookie uses `HttpOnly: true`, `SameSite: Strict`, and `Secure` (when HTTPS). These attributes are unreachable by PHPUnit (no real browser). Verifying them is one of the 5 core motivators for this milestone. | MEDIUM | Playwright exposes cookies via `context.cookies()`. Assert `httpOnly: true`, `sameSite: 'Strict'`, and `secure: true` after session activation. This directly closes a known test gap documented in `PITFALLS.md` Pitfall 3. |
| **Admin bar countdown timer JS behavior** | `wp-sudo-admin-bar.js` implements a `setInterval` countdown, ARIA milestone announcements, `wp-sudo-expiring` CSS class toggle at 60s, and page reload at 0s. All of this is JavaScript-level behavior unreachable by PHPUnit. | MEDIUM | Playwright can manipulate `Date` and `setTimeout` via `page.clock.install()` / `page.clock.tick()`. Assert timer text updates, CSS class addition at 60s, and reload behavior at 0s. The JS file is 102 lines and well-structured — high test confidence. |
| **MU-plugin install/uninstall AJAX flow** | `wp-sudo-admin.js` handles the MU-plugin AJAX flow: spinner shows, button disables, fetch fires, on success the page reloads after 1s, on error the message area is populated and button re-enables. The PHP side is integration-tested; the JS interaction chain is not. | MEDIUM | Playwright intercepts and mocks `fetch` responses to test both success and error states without requiring actual filesystem writes. Or use a real test WP environment for the full flow including page reload. |
| **Challenge page stash-challenge-replay full flow** | The complete flow (trigger gated action → land on challenge page → enter password → AJAX auth → stash replay → land on destination) has never been exercised in a real browser. PHPUnit integration tests simulate it but cannot verify the JavaScript POST-replay form submission or the loading overlay behavior. | HIGH | This is the core user journey of the entire plugin. Playwright navigates to Plugins, clicks Activate, enters password in challenge form, and asserts the plugin is now active. The JS in `wp-sudo-challenge.js` drives this flow; it is 400+ lines of AJAX, ARIA, and form logic. |
| **Visual regression baseline screenshots** | WP 7.0 GA ships April 9, 2026 with an admin visual refresh. Without snapshot baselines captured now (before GA), there is no reference to diff against. Screenshots taken after the admin refresh contain the new styles and cannot serve as regression baselines for the refresh itself. | MEDIUM | Playwright's built-in `expect(page).toHaveScreenshot()` API captures PNG snapshots and pixel-diffs on subsequent runs with configurable tolerance thresholds. Baselines must be committed to the repo. This is time-sensitive: capture baselines against WP 7.0 before any CSS changes to the plugin. |
| **Gate UI: disabled buttons when no sudo session** | `wp-sudo-gate-ui.js` disables Install, Activate, Update, and Delete buttons via `aria-disabled`, `pointer-events: none`, and a capture-phase `blockClick` handler. A MutationObserver watches for dynamically added cards. These DOM mutations cannot be verified by PHPUnit. | MEDIUM | Playwright navigates to the Plugins page with no active session, asserts buttons have `aria-disabled="true"` and `class="wp-sudo-disabled"`, then attempts a click and asserts no navigation occurred. |

### Differentiators (Competitive Advantage)

Features beyond the 5 PHPUnit-uncoverable scenarios. These raise the test suite quality
from "closes the gap" to "sets the standard" for WordPress security plugin E2E testing.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **Keyboard navigation and focus management** | `docs/ui-ux-testing-prompts.md` section 4c documents 8 keyboard-only scenarios: Tab order, `Enter` to submit, `Escape` to leave challenge, focus after failed attempt, focus after lockout expiry, 2FA step transition focus. Playwright can drive these natively via `page.keyboard`. | MEDIUM | Verifies WCAG focus management that cannot be tested in PHPUnit. Playwright's `page.getByRole()` + `locator.focus()` + `page.keyboard.press()` drive the flow. Assert `document.activeElement` via `page.evaluate()` after each transition. |
| **Keyboard shortcut (Ctrl+Shift+S) behavior** | `wp-sudo-admin-bar.js` and `wp-sudo-shortcut.js` handle `Ctrl+Shift+S` / `Cmd+Shift+S`. When no session is active: navigate to challenge. When session is active: flash the admin bar node (green, 300ms). Tests verify both states and that `prefers-reduced-motion` suppresses the flash animation. | MEDIUM | Playwright's `page.keyboard.press('Control+Shift+S')` fires the shortcut. Assert redirect (no-session state) or CSS property check (active-session state). `prefers-reduced-motion` is set via `page.emulateMedia({ reducedMotion: 'reduce' })`. |
| **ARIA live region announcements** | `wp-sudo-challenge.js` calls `wp.a11y.speak()` throughout the flow. The admin bar timer fires milestone announcements at 60s, 30s, 10s, and 0s via an `aria-live="assertive"` span. Screen reader announcement correctness is not testable by PHPUnit. Playwright can assert on live region DOM mutations. | HIGH | Assert the `aria-live` span text content changes at correct timer milestones. Assert `wp.a11y.speak()` call side effects by checking the WordPress `div#wp-a11y-speak-assertive` element content. High value for WCAG compliance signal. High complexity because timer manipulation is needed. |
| **Rate limiting UI feedback: throttle and lockout** | `wp-sudo-challenge.js` shows a throttle countdown (2s, 5s) and hard lockout countdown with live timer. PHPUnit integration tests verify the PHP-side locking; Playwright verifies the JS disables the button, shows the correct countdown text, re-enables after expiry, and announces lockout expiry via `wp.a11y.speak()`. | HIGH | Requires 5 incorrect password submissions to trigger lockout. Playwright must wait for the 5-minute lockout or clock-fast-forward it. `page.clock.install()` + `page.clock.tick(300_000)` is the clean approach. Verifies the complete lockout UX documented in `ui-ux-testing-prompts.md` H1 and 2g. |
| **Visual regression: WP 7.0 admin refresh** | WordPress 7.0 GA (April 9, 2026) ships a new admin color scheme and UI. WP Sudo's challenge page, settings page, and admin bar must not regress under the new styles. Playwright snapshot tests capture baselines and diff on re-run. | MEDIUM | Three surfaces, three viewports (desktop, tablet, mobile) = 9 baseline screenshots minimum. Threshold tuning is needed: WordPress admin UI has minor cross-browser pixel rendering differences. Start with `threshold: 0.01` (1% pixel diff tolerance) and adjust. Use `fullPage: false` to exclude dynamic header content. |
| **Responsive layout verification** | `docs/ui-ux-testing-prompts.md` section 3 documents 6 viewport sizes (1920x1080, 1366x768, 768x1024, 1024x768, 375x667, 390x844). Touch targets (44x44 px minimum), no horizontal overflow, stacked/collapsed layouts. | MEDIUM | `page.setViewportSize()` + visual snapshot per surface per viewport. Or assert `getBoundingClientRect()` for critical touch targets. 18 screenshots (3 surfaces × 6 viewports) covers the full responsive spec. |
| **Color contrast verification (expiring state)** | `docs/ui-ux-testing-prompts.md` section 4d calls out specific color contrast requirements: admin bar in `wp-sudo-expiring` state (red on white), lockout notice (yellow background), error notice (red border). PHPUnit cannot measure rendered color contrast. | HIGH | Playwright can extract computed styles via `page.evaluate()` and `getComputedStyle()`, but automated WCAG AA ratio checking requires axe-core integration or manual color extraction. Consider `@axe-core/playwright` as a focused add-on for this test specifically. Complexity is high because the expiring state requires timer manipulation to reach. |
| **Session-only mode via admin notice link** | When a gated AJAX request fires (e.g., plugin install button), the plugin blocks the AJAX and sets a short-lived transient. On the next page load, an admin notice appears with a link to the challenge page. Playwright can click this link and verify the session-only mode flow (no stash key, action label "Activate sudo session"). | MEDIUM | Two-page interaction: (1) trigger AJAX block, (2) observe admin notice on page reload, (3) click notice link, (4) complete challenge in session-only mode. Tests the full AJAX-gating user journey documented in `MANUAL-TESTING.md` section 3. |
| **Admin bar deactivation: click and keyboard** | `tests/MANUAL-TESTING.md` section `2h` and `ui-ux-testing-prompts.md` section 2h test admin bar timer click-to-deactivate and assert no page redirect. Playwright can click the admin bar node, assert session is deactivated (timer disappears), and assert the URL has not changed. | LOW | Simple DOM assertion after click. Deactivation URL includes nonce; Playwright handles this transparently via the logged-in session. Lower complexity because no timer manipulation is needed — only click-and-assert. |

### Anti-Features (Commonly Requested, Often Problematic)

Features that seem like reasonable E2E additions but should be explicitly rejected for this
milestone.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **Full visual regression across all WP versions** | "Catch CSS regressions on WP 6.9 and 7.0 simultaneously" seems thorough. | Snapshot baselines are tied to a specific WP admin theme. A snapshot taken on WP 6.9 will pixel-diff fail against WP 7.0 by design. Maintaining two baseline sets doubles the snapshot count and the CI time, for a scenario already covered by the integration test matrix (PHP × WP version). | Take baselines against WP 7.0 only (the target). The WP 6.x visual appearance is already known from manual testing history. Snapshot regressions catch changes _within_ a WP version, not _between_ versions. |
| **Testing REST API endpoints via Playwright** | "Let's verify the REST API returns `sudo_required` in the browser" sounds comprehensive. | REST API behavior is PHP-layer logic already covered by PHPUnit integration tests. Playwright tests it through the browser fetch layer with no additional insight over `curl` tests in the manual guide. Adds authentication setup complexity (nonce extraction from DOM) for zero new coverage signal. | Keep REST API behavioral tests in PHPUnit integration tests (`RestGatingTest.php` already exists; see `../../docs/current-metrics.md` for current suite counts). Use `curl` tests in `MANUAL-TESTING.md` for REST verification. |
| **WP-CLI behavior via Playwright** | CLI policy behavior visible from a browser is appealing. | WP-CLI does not run in a browser. Playwright cannot exec shell commands without Playwright's `spawn` workaround that is fragile and platform-specific. CLI behavior is already covered by manual testing section 7 and the policy architecture is integration-tested. | Leave WP-CLI tests in `MANUAL-TESTING.md` sections 7–8. CLI testing is a better fit for a shell-based CI step (phpunit integration tests simulate CLI context via `defined('WP_CLI')` mocking). |
| **Screenshot comparison for every admin page** | "Let's capture the full WordPress admin at 10 viewports × 5 pages × 2 WP versions = 100 snapshots" sounds complete. | Visual snapshot suites that grow beyond ~20 baselines become a maintenance burden. False positive diffs from WordPress's dynamic content (menu highlight, time-sensitive notifications, user avatar rendering) require constant baseline updates that erode developer trust in the suite. | Limit snapshots to the 3 WP Sudo surfaces (challenge page, settings page, admin bar) at 2–3 viewports. Keep snapshot count under 15. Never snapshot dynamic page content (plugin list, user list). |
| **Two-Factor Authentication full flow E2E** | "Test the TOTP entry step in a real browser" is appealing. | TOTP codes are time-based and require either a real 2FA-configured account or TOTP seed manipulation in the test environment. Setting up a real TOTP seed in a local WordPress + Playwright test is high complexity with fragile time synchronization. The PHP-level 2FA pending state machine is already covered by `TwoFactorTest.php` integration tests (132 total). | Test the 2FA UI elements (2FA step visibility, timer display, focus management) by mocking the AJAX response to return `requires_two_factor: true` via Playwright's `page.route()` interception, without requiring a real TOTP calculation. |
| **Performance testing via Playwright** | "Let's measure page load time in the browser" seems like a useful E2E add-on. | Performance benchmarks from local Playwright runs are not reproducible or meaningful — they depend on local machine resources, WordPress caching state, and database query time. They produce false confidence that fluctuates across developer machines. | If performance is a concern, use Lighthouse CI via `lhci autorun` as a separate pipeline step. Do not combine with E2E correctness tests. |
| **Headless-only test suite** | "We don't need headed mode, ship headless-only for speed." | WP 7.0's admin refresh includes visual changes that require headed browser rendering to validate. Headless Chromium and headed Chrome can render CSS properties differently. Visual regression tests must be run in a consistent headed context for reliable pixel diffs. | Run visual regression tests in headed Chromium (still headless in CI via xvfb or Linux runners). Use `--headed` locally for debugging. Keep functional tests headless for speed. |
| **Cypress as an alternative to Playwright** | "Cypress is more mature for WordPress" is sometimes asserted. | Cypress has no native support for multi-page flows in some contexts, uses iframes for its test runner UI (WordPress admin uses `wp_iframe()` which the challenge JS breaks out of — see `wp-sudo-challenge.js` line 22), and its network interception API is less flexible than Playwright's `page.route()`. Playwright's clock manipulation (`page.clock.install()`) is essential for the rate limiting tests. | Use Playwright. It is the better choice for this specific plugin's test requirements. |

---

## Feature Dependencies

```
[Playwright toolchain setup]
    └──required by──> [WordPress login helper]
    └──required by──> [Cookie attribute verification]
    └──required by──> [Admin bar countdown timer tests]
    └──required by──> [MU-plugin AJAX flow tests]
    └──required by──> [Challenge page stash-challenge-replay]
    └──required by──> [Visual regression baselines]
    └──required by──> [Gate UI: disabled buttons]
    └──required by──> [All differentiators]

[WordPress login helper]
    └──required by──> [Cookie attribute verification]
        (need to be logged in to activate a session and inspect cookies)
    └──required by──> [Admin bar countdown timer tests]
        (admin bar only renders for authenticated users)
    └──required by──> [Challenge page stash-challenge-replay]
        (must be logged in to trigger a gated action)
    └──required by──> [Gate UI: disabled buttons]
        (gate UI only appears in authenticated WP admin)
    └──required by──> [Visual regression baselines]
        (WP admin requires authentication)

[Challenge page stash-challenge-replay]
    └──enables──> [Rate limiting UI feedback]
        (need the challenge page working before testing failure states)
    └──enables──> [ARIA live region announcements]
        (announce flow is driven by the challenge JS)

[Admin bar countdown timer tests]
    └──enables──> [Visual regression: WP 7.0 admin bar state]
        (need active session to capture timer snapshot)
    └──enables──> [Color contrast: expiring state]
        (expiring class only added by timer JS at <=60s)
    └──enables──> [Keyboard shortcut: active session flash]
        (shortcut behavior differs based on session state)

[Playwright clock manipulation — page.clock.install()]
    └──required by──> [Admin bar timer: 60s expiring state test]
    └──required by──> [Admin bar timer: reload at 0s test]
    └──required by──> [Rate limiting UI: lockout countdown test]
    └──required by──> [ARIA milestone announcements at 60s/30s/10s]

[Visual regression baselines]
    └──gate for──> [Future WP version CSS regression detection]
        (cannot detect a regression without a baseline)
    └──time-sensitive──> [Capture before WP 7.0 CSS changes to plugin]
        (baselines captured after a CSS change contain the change)
```

### Dependency Notes

- **Playwright toolchain is the absolute prerequisite:** Nothing else can run without it. The first phase of this milestone is entirely toolchain: `npm init`, install, `playwright.config.ts`, first passing smoke test. This matches the integration test harness pattern from the previous milestone.
- **Login helper unlocks everything:** Every test that touches WP admin needs authentication. Build it first with `storageState` persistence. Tests that reuse the saved state skip the login flow entirely, keeping the suite fast.
- **Clock manipulation is a cross-cutting concern:** The admin bar timer and rate-limiting tests both depend on `page.clock.install()`. This is a Playwright-native feature (not a hack). It intercepts `Date`, `setTimeout`, `setInterval`, and `requestAnimationFrame`. Verify this API is available in the installed Playwright version before building dependent tests.
- **Visual regression baseline timing is critical:** WP 7.0 GA is April 9, 2026. Baselines must be captured on the current WP 7.0 build before any CSS changes are made to the plugin for WP 7.0 compatibility. If plugin CSS changes come first, the baseline contains the change and cannot detect future regressions to it. Capture baselines as the first deliverable of the visual regression phase.
- **Challenge page stash-challenge-replay is the highest-risk test:** It involves 3 pages (triggering page, challenge page, destination), 2 AJAX requests (password auth, optional 2FA), and a JavaScript POST-replay form submission. Build this incrementally: first test that challenge page loads, then that password form submits, then that stash replay works.

---

## MVP Definition

This is a subsequent milestone (v2.14), not a greenfield MVP. "MVP" here means: what is the
minimum E2E suite that closes the 5 PHPUnit-uncoverable scenarios and enables WP 7.0 regression
detection?

### Launch With (Phase 1 — Core E2E Infrastructure)

The toolchain plus the tests that directly close the 5 documented PHPUnit gaps:

- [ ] **Playwright toolchain setup** — `npm init`, `@playwright/test`, `playwright.config.ts`, Chromium install, `composer test:e2e` / `npm test` script, CI job skeleton — *everything else requires this*
- [ ] **WordPress login helper** — `storageState` session fixture, reusable across all test files — *required by every other test*
- [ ] **Cookie attribute verification** — activate sudo session, call `context.cookies()`, assert `httpOnly: true`, `sameSite: 'Strict'`, `secure: true` on `wp_sudo_token` — *closes PHPUnit gap 1*
- [ ] **Admin bar countdown timer tests** — verify timer text updates, `wp-sudo-expiring` class at 60s, page reload at 0s, using `page.clock.install()` — *closes PHPUnit gap 2*
- [ ] **MU-plugin AJAX flow** — install via Settings page, assert spinner appears, success message, page reload; uninstall equivalent — *closes PHPUnit gap 3*
- [ ] **Gate UI: disabled buttons** — Plugins page with no session, assert `aria-disabled` and `wp-sudo-disabled` class on action links — *directly testable, low risk*
- [ ] **Visual regression baselines** — challenge page, settings page, admin bar (active session) at desktop + mobile; captured against WP 7.0 before any plugin CSS changes — *time-sensitive: must happen before April 9 changes*

### Add After Core Is Stable (Phase 2 — Full Flow + Keyboard)

- [ ] **Challenge page stash-challenge-replay** — full Plugins → challenge → auth → replay flow — *highest-complexity test; add once toolchain and simpler tests are stable*
- [ ] **Keyboard navigation: challenge page Tab order and Escape key** — Tab through form, Escape announces and navigates — *closes PHPUnit gap 5 (keyboard navigation)*
- [ ] **Keyboard shortcut (Ctrl+Shift+S)** — no-session state redirects to challenge; active-session state flashes admin bar — *medium complexity, high UX value*
- [ ] **Admin bar click-to-deactivate** — click timer node, assert timer disappears, assert URL unchanged — *low complexity, good regression coverage*

### Future Consideration (Phase 3 — Accessibility + Advanced UX)

- [ ] **ARIA live region announcements** — timer milestone announcements, challenge flow announcements — *high value but requires clock manipulation + DOM observation; defer until Phase 1/2 are stable*
- [ ] **Rate limiting UI: lockout countdown and re-enable** — 5 failed attempts, clock-fast-forward 5 min, assert form re-enables — *high complexity; needs `page.clock` for the 5-minute wait*
- [ ] **Responsive layout verification** — 6 viewports × 3 surfaces snapshots — *add after baseline snapshot tooling is proven*
- [ ] **Color contrast via axe-core** — `@axe-core/playwright` for expiring-state contrast — *add as a focused accessibility gate, not part of core E2E*
- [ ] **Session-only mode via admin notice** — AJAX block → notice on reload → session-only challenge — *medium complexity, tests a distinct user journey worth adding eventually*

---

## Feature Prioritization Matrix

| Feature | PHPUnit Gap Closed | Implementation Cost | Priority |
|---------|--------------------|---------------------|----------|
| Playwright toolchain setup | Enabler | HIGH | P1 |
| WordPress login helper | Enabler | MEDIUM | P1 |
| Cookie attribute verification | Gap 1 (httponly/SameSite/Secure) | MEDIUM | P1 |
| Admin bar countdown timer JS | Gap 2 (admin bar JS) | MEDIUM | P1 |
| MU-plugin AJAX flow | Gap 3 (MU-plugin AJAX) | MEDIUM | P1 |
| Visual regression baselines | Gap 5 (WP 7.0 regression) | MEDIUM | P1 (time-sensitive) |
| Gate UI: disabled buttons | Adjacent gap | LOW | P1 |
| Challenge page stash-challenge-replay | Full flow never browser-tested | HIGH | P2 |
| Keyboard navigation: Tab + Escape | Gap 5 (keyboard/focus) | MEDIUM | P2 |
| Keyboard shortcut Ctrl+Shift+S | Gap 5 (keyboard) | MEDIUM | P2 |
| Admin bar click-to-deactivate | Gap 2 (admin bar) | LOW | P2 |
| ARIA live region announcements | WCAG verification | HIGH | P3 |
| Rate limiting UI: lockout countdown | UX verification | HIGH | P3 |
| Responsive layout snapshots | Visual coverage | MEDIUM | P3 |
| Color contrast via axe-core | WCAG verification | HIGH | P3 |
| Session-only mode via AJAX notice | Flow coverage | MEDIUM | P3 |

**Priority key:**
- P1: Must have for v2.14.0 launch — closes the documented 5 PHPUnit-uncoverable gaps
- P2: Should have, add in v2.14.x — completes the user journey and keyboard coverage
- P3: Nice to have, future consideration — accessibility depth, edge-case UX coverage

---

## PHPUnit Infrastructure Context

This milestone builds on an existing, stable test infrastructure:

| Layer | Status | Notes |
|-------|--------|-------|
| PHPUnit unit tests | 496 tests, all passing | Brain\Monkey, Mockery, Patchwork — no changes needed |
| PHPUnit integration tests | 132 tests, CI matrix (PHP 8.1×8.3, WP latest×trunk, single+multisite) | WP_UnitTestCase, real MySQL — no changes needed |
| PHPStan level 6 | Passing | No changes needed |
| PHPCS (VIP WPCS) | Passing | No changes needed |

**Playwright adds a third, fully separate layer.** It does not touch `composer.json`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, or any PHP test infrastructure. It is a parallel Node.js toolchain. The two ecosystems are completely independent.

**Practical implication:** Playwright requires a running WordPress instance. Unlike integration tests (which spin up WP via `install-wp-tests.sh` in CI), Playwright needs a full HTTP server with a real browser. This means:
- Local: Use one of the existing dev sites (single-site-studio, single-site-local) documented in MEMORY.md
- CI: Requires either `@wordpress/env` (Docker + Node) or a pre-built WordPress Docker image with the plugin installed
- This is the highest CI complexity decision of this milestone and should be decided in Phase 1

**`@wordpress/env` vs pre-built image:** `@wordpress/env` (wp-env) is the official WordPress tool for E2E testing and is used by WordPress core, Gutenberg, and the Two Factor plugin. It requires Docker and Node.js. It starts a fresh WordPress with predictable state on each CI run. For this plugin with no block editor code, wp-env is heavier than needed — but it is the ecosystem standard and provides the cleanest CI setup. The alternative (running tests against a manually configured dev site) is faster locally but fragile in CI. **Use `@wordpress/env` for CI; use local dev sites for local development.**

---

## Visual Regression Testing: How It Works In Practice

Based on Playwright's built-in `toHaveScreenshot` API (MEDIUM confidence — training data,
verify against official Playwright docs before implementing):

### Baseline Management

1. First run: `npx playwright test --update-snapshots` — Playwright captures PNG baselines and saves them to `e2e/__snapshots__/` alongside the test file.
2. Subsequent runs: `npx playwright test` — Playwright diffs current screenshot against baseline pixel-by-pixel. Test fails if diff exceeds threshold.
3. When baselines need updating (intentional UI change): `npx playwright test --update-snapshots` — replaces baselines. Review the diff in CI before merging.
4. Baselines are committed to the repository. This is the standard pattern.

### Threshold Tuning

WordPress admin renders with minor pixel differences across:
- Font anti-aliasing (macOS vs Linux)
- Scrollbar rendering (macOS overlaid vs Linux fixed-width)
- Image rendering (DPI differences between headed and headless Chromium)

**Recommended approach:**
- Start with `threshold: 0.01` (1% pixel diff tolerance per pixel) for functional areas
- Use `maxDiffPixels: 100` (absolute cap on differing pixels) as a secondary gate
- Use `clip` option to isolate the specific UI element (e.g., just the admin bar node, not the full page with dynamic WordPress header content)
- Never snapshot the plugin list, user list, or any page with dynamic row content — those change between runs

### What to Snapshot (and What Not To)

**Snapshot these:**
- `#wp-sudo-challenge-card` — the challenge page card element only
- `#wp-sudo-settings-form` — the settings page form only
- `#wp-admin-bar-wp-sudo-active` — the admin bar timer node only
- Same elements in `wp-sudo-expiring` state (requires clock manipulation to reach)

**Do not snapshot these:**
- Full-page screenshots (dynamic WP admin header, sidebar, footer)
- Plugin/theme list tables (row counts and activation states change)
- Any element containing a nonce, timestamp, or user-specific string

---

## Sources

- `admin/js/wp-sudo-admin-bar.js` — 102-line countdown timer with milestone announcements, `prefers-reduced-motion` support, keyboard shortcut flash (HIGH confidence — live file)
- `admin/js/wp-sudo-challenge.js` — challenge page controller: AJAX password auth, 2FA step, throttle countdown, lockout countdown, replay logic, `wp.a11y.speak()` calls (HIGH confidence — live file)
- `admin/js/wp-sudo-admin.js` — MU-plugin AJAX install/uninstall, spinner, message area, page reload on success (HIGH confidence — live file)
- `admin/js/wp-sudo-gate-ui.js` — button disabling, `MutationObserver` for dynamic cards, `aria-disabled`, capture-phase click blocking (HIGH confidence — live file)
- `docs/ui-ux-testing-prompts.md` — structured manual UI/UX test scenarios for all 3 surfaces across heuristics, navigation flows, responsive viewports, and accessibility (HIGH confidence — live file)
- `tests/MANUAL-TESTING.md` — 19-section manual testing guide documenting every testable scenario with expected outcomes (HIGH confidence — live file)
- `.planning/codebase/TESTING.md` (2026-03-04) — existing test infrastructure details; E2E section confirms "Not automated" and "No Playwright/Cypress/WebDriver tests planned" — this milestone changes that (HIGH confidence)
- `.planning/codebase/CONCERNS.md` (2026-02-19) — documents cookie attribute testing gap (Pitfall 3) and JS behavior not reachable by PHPUnit (HIGH confidence)
- `.planning/research/PITFALLS.md` (2026-02-19) — Pitfall 3: "WP_UnitTestCase Cannot Test Real HTTP Headers or Cookies" — explicitly calls out Playwright as the resolution layer (HIGH confidence)
- `ROADMAP.md` — "Playwright E2E test infrastructure" listed as short-term milestone with the 5 PHPUnit-uncoverable scenarios explicitly named (HIGH confidence)
- WordPress MEMORY.md dev environment — local dev sites available for E2E test target (HIGH confidence)
- Playwright clock API, visual regression API, `storageState` — MEDIUM confidence (training data, August 2025 cutoff; verify current Playwright version docs before implementing)

---
*Feature research for: Playwright E2E browser testing for WP Sudo*
*Researched: 2026-03-08*
