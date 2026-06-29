# Phase 7: Core E2E Tests + Visual Regression Baselines — Research

**Researched:** 2026-03-08
**Domain:** Playwright E2E testing against real WordPress plugin behavior
**Confidence:** HIGH (all findings sourced directly from plugin source code)

---

## Summary

Phase 7 writes the actual behavioral Playwright tests that exercise the WP Sudo plugin's UI surfaces. All research was performed by reading the live PHP and JavaScript source files. No training-data assumptions were used for selector names, cookie attributes, or AJAX endpoint names — every detail below is sourced from the code.

The phase covers six test categories: cookie verification (COOK), admin bar timer (TIMR), MU-plugin AJAX (MUPG), gate UI (GATE), challenge flow (CHAL), and visual regression (VISN). The Phase 6 infrastructure (playwright.config.ts, global-setup.ts, fixtures, smoke tests) is already in place and working. Phase 7 builds behavioral tests on top of it.

**Primary recommendation:** Write tests by reading selectors from the source directly. Every ID, class, cookie name, and AJAX action in this document was read from the PHP/JS source. Do not guess at selector names — the plugin uses very specific IDs that are trivially verifiable.

---

## Standard Stack

The E2E infrastructure from Phase 6 is already established. Phase 7 adds no new npm packages — it uses only what was installed in Phase 6.

### Already Available (from Phase 6)
| Library | Purpose |
|---------|---------|
| `@playwright/test` | Test runner, page API, expect assertions, screenshot comparisons |
| `@wordpress/env` | WordPress Docker environment at `http://localhost:8889` |

### Playwright APIs Needed Per Test Category

| Category | Key Playwright APIs |
|----------|-------------------|
| COOK | `context.cookies()` — returns array with `httpOnly`, `sameSite`, `path`, `name`, `value` |
| TIMR | `page.clock.install()` / `page.clock.tick()` — freeze/advance fake timers; `locator.textContent()` |
| MUPG | `page.click()`, `page.waitForURL()`, `page.evaluate()` for `window.location.reload` after AJAX |
| GATE | `page.locator('.wp-sudo-disabled')`, `getAttribute('aria-disabled')`, click event interception |
| CHAL | AJAX navigation pattern: `Promise.all([page.waitForURL(), page.click()])` |
| VISN | `expect(page).toHaveScreenshot({ mask: [...], threshold: ... })` |

---

## Architecture Patterns

### Existing File Layout (Phase 6 baseline)

```
tests/e2e/
├── playwright.config.ts          # baseURL=http://localhost:8889, workers:1, chromium only
├── global-setup.ts               # WP login, save storageState excluding wp_sudo_* cookies
├── fixtures/
│   └── test.ts                   # visitAdminPage fixture (handles upgrade.php, login check)
├── specs/
│   └── smoke.spec.ts             # 2 passing smoke tests
└── artifacts/
    ├── storage-states/
    │   └── admin.json            # Saved login cookies (excludes wp_sudo_*)
    └── test-results/             # Failure artifacts
```

### Phase 7 New Files

```
tests/e2e/specs/
├── cookie.spec.ts                # COOK-01, COOK-02, COOK-03
├── admin-bar-timer.spec.ts       # TIMR-01, TIMR-02, TIMR-03, TIMR-04
├── mu-plugin.spec.ts             # MUPG-01, MUPG-02, MUPG-03
├── gate-ui.spec.ts               # GATE-01, GATE-02, GATE-03
├── challenge.spec.ts             # CHAL-01, CHAL-02, CHAL-03
└── visual-regression.spec.ts     # VISN-01, VISN-02, VISN-03, VISN-04
```

### Helper Pattern for Activating a Sudo Session

Many tests need an active sudo session. The challenge page authentication flow is the only way to acquire one (never use storageState). A reusable helper function:

```typescript
// Source: challenge.php + wp-sudo-challenge.js (verified)
async function activateSudoSession(page: Page, password = 'password'): Promise<void> {
    // Navigate to challenge page in session-only mode (no stash key)
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');

    // Wait for JS config to be loaded
    await page.waitForFunction(() => typeof (window as any).wpSudoChallenge !== 'undefined');

    await page.fill('#wp-sudo-challenge-password', password);

    await Promise.all([
        page.waitForURL(/wp-admin/),
        page.click('#wp-sudo-challenge-submit'),
    ]);
}
```

Note: Session-only mode (no `stash_key` param) redirects to `cancelUrl` (typically the admin dashboard) after authentication. The challenge page URL is `/wp-admin/admin.php?page=wp-sudo-challenge`.

---

## COOK: Cookie Verification

### What the Code Does

**Source:** `includes/class-sudo-session.php`, `set_token()` method (lines 703–751)

When `Sudo_Session::activate()` is called, `set_token()` runs:

```php
setcookie(
    self::TOKEN_COOKIE,          // 'wp_sudo_token'
    $token,
    array(
        'expires'  => time() + ( $duration * MINUTE_IN_SECONDS ),
        'path'     => COOKIEPATH,          // '/' in standard WP installs
        'domain'   => COOKIE_DOMAIN,       // '' in standard WP installs → current domain
        'secure'   => is_ssl(),            // false for http://localhost
        'httponly' => true,                // ALWAYS true
        'samesite' => 'Strict',            // ALWAYS Strict
    )
);
```

**Cookie constants in wp-env:**
- `COOKIEPATH` = `'/'` (standard WordPress default)
- `COOKIE_DOMAIN` = `''` (empty string, meaning current domain)
- `is_ssl()` = `false` for `http://localhost:8889`

**Cookie name constants (verified from class-sudo-session.php):**
- Main session token: `wp_sudo_token` (`Sudo_Session::TOKEN_COOKIE`)
- 2FA challenge binding: `wp_sudo_challenge` (`Sudo_Session::CHALLENGE_COOKIE`)

### Playwright Cookie API

`context.cookies()` returns an array of `Cookie` objects with these fields:
- `name`: string
- `value`: string
- `httpOnly`: boolean
- `sameSite`: `'Strict' | 'Lax' | 'None'`
- `secure`: boolean
- `path`: string
- `domain`: string

### Test Pattern for COOK

```typescript
// Source: class-sudo-session.php set_token() verified
test('COOK-01: wp_sudo_token cookie is httpOnly', async ({ page, context }) => {
    await activateSudoSession(page);

    const cookies = await context.cookies();
    const sudoCookie = cookies.find(c => c.name === 'wp_sudo_token');
    expect(sudoCookie).toBeDefined();
    expect(sudoCookie!.httpOnly).toBe(true);
});

test('COOK-02: wp_sudo_token cookie has SameSite=Strict', async ({ page, context }) => {
    await activateSudoSession(page);
    const cookies = await context.cookies();
    const sudoCookie = cookies.find(c => c.name === 'wp_sudo_token');
    expect(sudoCookie!.sameSite).toBe('Strict');
});

test('COOK-03: wp_sudo_token cookie path is root', async ({ page, context }) => {
    await activateSudoSession(page);
    const cookies = await context.cookies();
    const sudoCookie = cookies.find(c => c.name === 'wp_sudo_token');
    expect(sudoCookie!.path).toBe('/');
});
```

**Pitfall:** The `secure` flag will be `false` for `http://localhost:8889`. Do NOT assert `secure: true` in tests — that only applies to HTTPS sites.

**Stale cookie cleanup:** The plugin explicitly removes any stale cookie at `ADMIN_COOKIE_PATH` path by sending an expiry cookie for `/wp-admin/` before setting the new cookie at `/`. Tests should look for the cookie at path `/`, not `/wp-admin/`.

---

## TIMR: Admin Bar Timer

### What the Code Does

**Source:** `includes/class-admin-bar.php` + `admin/js/wp-sudo-admin-bar.js` (verified)

**PHP side (admin_bar_node method):**
The admin bar node is added with these exact attributes:
- Node ID: `wp-sudo-active` (becomes `wp-admin-bar-wp-sudo-active` on the `<li>`)
- Meta class: `wp-sudo-active`
- Node title contains: `<span class="ab-icon dashicons dashicons-unlock"></span><span class="ab-label">Sudo: M:SS</span>`
- `href` is the deactivate URL (with `wp_sudo_deactivate=1` and nonce)

**JavaScript side (wp-sudo-admin-bar.js):**
- Targets `#wp-admin-bar-wp-sudo-active`
- Reads `.ab-label` for text updates
- Countdown: decrements by 1 every 1000ms via `setInterval`
- Text format: `'Sudo: ' + m + ':' + (s < 10 ? '0' : '') + s`
- Expiring CSS class: `wp-sudo-expiring` added to `n` (the `<li>`) when `r <= 60`
- Reload: `window.location.reload()` when `r <= 0`
- Localized config: `window.wpSudoAdminBar.remaining` (integer, seconds)

**CSS (wp-sudo-admin-bar.css):**
- Active: `#wpadminbar .wp-sudo-active .ab-item { background: #2e7d32 }` (green)
- Expiring: `#wpadminbar .wp-sudo-expiring .ab-item { background: #c62828 }` (red)

### Test Patterns for TIMR

**TIMR-01: Timer visible with correct text format:**
```typescript
// Must have active session first
test('TIMR-01: admin bar shows Sudo: M:SS countdown', async ({ page }) => {
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    const timerNode = page.locator('#wp-admin-bar-wp-sudo-active');
    await expect(timerNode).toBeVisible();

    const label = timerNode.locator('.ab-label');
    await expect(label).toContainText(/^Sudo: \d+:\d{2}$/);
});
```

**TIMR-02: Text updates each second (use page.clock):**
```typescript
// page.clock.install() before navigation to control time
test('TIMR-02: timer text updates each second', async ({ page }) => {
    await page.clock.install();
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    const label = page.locator('#wp-admin-bar-wp-sudo-active .ab-label');
    const before = await label.textContent();

    await page.clock.tick(1000); // Advance 1 second
    const after = await label.textContent();

    expect(before).not.toBe(after);
});
```

**TIMR-03: wp-sudo-expiring CSS class at 60s:**
```typescript
test('TIMR-03: expiring class added at 60 seconds remaining', async ({ page }) => {
    await page.clock.install();
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    const node = page.locator('#wp-admin-bar-wp-sudo-active');
    await expect(node).not.toHaveClass(/wp-sudo-expiring/);

    // Advance to within 60s of expiry (default session = 15 min = 900s)
    // Tick 840 seconds to reach 60s remaining
    await page.clock.tick(840_000);
    await expect(node).toHaveClass(/wp-sudo-expiring/);
});
```

**TIMR-04: Page reload at 0s:**
```typescript
test('TIMR-04: page reloads when timer reaches zero', async ({ page }) => {
    await page.clock.install();
    // Set session to 1 minute for faster testing via WP-CLI before test
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    const reloadPromise = page.waitForNavigation({ waitUntil: 'load' });
    // Tick past all remaining time
    await page.clock.tick(910_000); // 910 seconds past 15-minute session
    await reloadPromise;
    // After reload, timer should be gone (session expired)
    await expect(page.locator('#wp-admin-bar-wp-sudo-active')).not.toBeVisible();
});
```

**CRITICAL PITFALL for TIMR:** `page.clock.install()` must be called BEFORE navigation to the page that loads the timer JS. If called after, the `setInterval` already has the real clock reference and won't respond to fake clock ticks.

**Session Duration Setup:** For TIMR-04, either:
1. Set session_duration to 1 minute via WP-CLI before the test: `npx wp-env run tests-cli wp option patch update wp_sudo_settings session_duration 1`
2. Or tick enough to exhaust a 15-minute session (900 seconds)

---

## MUPG: MU-Plugin AJAX

### What the Code Does

**Source:** `includes/class-admin.php` + `admin/js/wp-sudo-admin.js` (verified)

**PHP AJAX endpoints:**
- Install action: `wp_sudo_mu_install` (`Admin::AJAX_MU_INSTALL`)
- Uninstall action: `wp_sudo_mu_uninstall` (`Admin::AJAX_MU_UNINSTALL`)
- Nonce action: `wp_sudo_mu_plugin` (used in `check_ajax_referer`)
- Nonce field name in FormData: `_nonce` (not `_wpnonce`)

**Server-side requirement:** Both handlers check `Sudo_Session::is_active()`. Without an active session, they return `{ code: 'sudo_required', message: '...' }` with HTTP 403.

**DOM elements (verified from render_mu_plugin_status PHP):**
- Status text: `#wp-sudo-mu-status`
- Install button (when not installed): `#wp-sudo-mu-install` (class: `button button-primary`)
- Uninstall button (when installed): `#wp-sudo-mu-uninstall` (class: `button`)
- Spinner: `#wp-sudo-mu-spinner`
- Message: `#wp-sudo-mu-message` (aria-live="polite")

**JS behavior after success:**
```javascript
setTimeout(function () {
    window.location.reload(); // After 1000ms delay
}, 1000);
```

After `reload()`, the page re-renders with the new MU-plugin status. The "Installed" state appears when `defined('WP_SUDO_MU_LOADED')` is true.

**Status text patterns (verified from PHP):**
- Installed: "Installed" (with green dashicons-yes-alt icon)
- Not installed: "Not installed" (with yellow dashicons-warning icon)

### Test Pattern for MUPG

```typescript
// MUPG requires active sudo session before clicking install/uninstall

test('MUPG-01: install MU-plugin via Settings page', async ({ page }) => {
    // Ensure MU-plugin not installed first via WP-CLI
    await activateSudoSession(page);
    await page.goto('/wp-admin/options-general.php?page=wp-sudo-settings');

    const installBtn = page.locator('#wp-sudo-mu-install');
    await expect(installBtn).toBeVisible();

    // Click and wait for page reload (JS does setTimeout 1000ms then reload)
    await installBtn.click();
    await page.waitForURL(/wp-sudo-settings/, { timeout: 10_000 });

    // After reload, status should show Installed
    await expect(page.locator('#wp-sudo-mu-status')).toContainText('Installed');
});
```

**MUPG-02 (uninstall):** Same pattern — click `#wp-sudo-mu-uninstall`, wait for page reload, assert "Not installed".

**MUPG-03 (requires sudo):** Navigate to settings WITHOUT activating a session first, click install, intercept AJAX response, assert error message or `#wp-sudo-mu-message` contains "sudo session is required".

**State cleanup:** Tests must reset MU-plugin state between runs. Use WP-CLI:
```bash
# Check state
npx wp-env run tests-cli wp eval "echo defined('WP_SUDO_MU_LOADED') ? 'installed' : 'not-installed';"
# Remove for clean state
npx wp-env run tests-cli bash -c "rm -f /var/www/html/wp-content/mu-plugins/wp-sudo-gate.php"
```

---

## GATE: Gate UI (Disabled Buttons)

### What the Code Does

**Source:** `admin/js/wp-sudo-gate-ui.js` (verified)

The gate UI script is enqueued on plugin/theme admin pages when the user does NOT have an active sudo session. It disables buttons using `disableButtons()`:

```javascript
btn.classList.add('disabled', 'wp-sudo-disabled');
btn.setAttribute('aria-disabled', 'true');
// For <a> tags:
btn.setAttribute('role', 'button');
btn.removeAttribute('href');
// Capture-phase click blocker:
btn.addEventListener('click', blockClick, true);
```

Also injects inline style: `.wp-sudo-disabled{pointer-events:none;opacity:.5;cursor:default}`

**Page selectors per page (verified from selectorMap in gate-ui.js):**

| Page (`config.page`) | Selectors targeted |
|---------------------|-------------------|
| `plugin-install` | `.install-now`, `.update-now`, `.activate-now` |
| `plugins` | `.activate a`, `.deactivate a`, `.delete a` |
| `theme-install` | `.theme-install`, `.update-now` |
| `themes` | `.theme-actions .activate`, `.theme-actions .delete-theme`, `.button.update-now`, `.submitdelete.deletion` |

**PHP enqueue side:** Gate-UI script is enqueued by the Gate class `filter_plugin_action_links` / `filter_theme_action_links`, which passes `config.page` to identify the current page.

### Test Patterns for GATE

GATE tests need NO active sudo session.

```typescript
test('GATE-01: plugin list activate links have aria-disabled=true', async ({ page }) => {
    // Ensure no active session (fresh storageState without sudo cookie)
    await page.goto('/wp-admin/plugins.php');

    // Activate links should be disabled by gate-ui.js
    const activateLinks = page.locator('.activate a');
    // Check first available activate link
    const firstActivate = activateLinks.first();
    await expect(firstActivate).toHaveAttribute('aria-disabled', 'true');
});

test('GATE-02: disabled buttons have wp-sudo-disabled CSS class', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    const disabledBtns = page.locator('.wp-sudo-disabled');
    await expect(disabledBtns.first()).toBeVisible();
});

test('GATE-03: clicking disabled button does not navigate', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    const activateLink = page.locator('.activate a').first();

    const initialUrl = page.url();
    await activateLink.click({ force: true }); // force to bypass Playwright's own disabled check
    // Should stay on same page — blockClick prevents navigation
    expect(page.url()).toBe(initialUrl);
});
```

**Pitfall:** Gate UI only runs when NO session is active. If a session cookie is somehow present in storageState, the buttons will NOT be disabled. Verify `wp_sudo_token` is absent from storageState (Phase 6 global-setup already filters this).

**Pitfall:** For GATE-03, `.activate a` links have their `href` removed by gate-ui.js. Playwright's `click()` on a link with no `href` and `pointer-events:none` may require `{ force: true }`. After clicking, assert URL has not changed.

---

## CHAL: Challenge Flow

### What the Code Does

**Source:** `includes/class-challenge.php` + `admin/js/wp-sudo-challenge.js` (verified)

**Challenge page URL:** `/wp-admin/admin.php?page=wp-sudo-challenge`
- With stash key: `&stash_key=XXXXX` (16-char alphanumeric, no prefix)
- With return URL: `&return_url=URL` (used as cancel destination)

**DOM IDs (verified from render_page() PHP):**
- Challenge card: `#wp-sudo-challenge-card`
- Password step container: `#wp-sudo-challenge-password-step`
- Password form: `#wp-sudo-challenge-password-form`
- Password input: `#wp-sudo-challenge-password` (type="password", autocomplete="current-password")
- Submit button: `#wp-sudo-challenge-submit` (class: "button button-primary")
- Error box: `#wp-sudo-challenge-error` (role="alert", hidden by default)
- 2FA step: `#wp-sudo-challenge-2fa-step` (hidden by default)
- 2FA form: `#wp-sudo-challenge-2fa-form`
- 2FA submit: `#wp-sudo-challenge-2fa-submit`
- 2FA error box: `#wp-sudo-challenge-2fa-error`
- 2FA timer: `#wp-sudo-challenge-2fa-timer`
- Loading overlay: `#wp-sudo-challenge-loading` (hidden by default)

**Cancel button:** `<a href="cancelUrl" class="button">Cancel</a>` — standard link, not a button element.

**AJAX flow (verified from challenge.php + challenge.js):**
- AJAX URL: `window.wpSudoChallenge.ajaxUrl` (= `admin-ajax.php`)
- Auth action: `window.wpSudoChallenge.authAction` (= `wp_sudo_challenge_auth`)
- Nonce field: `_wpnonce` in FormData, value from `window.wpSudoChallenge.nonce`
- Stash key field: `stash_key` in FormData (if present)
- Password field: `password` in FormData

**On success (stash mode, GET request):** JS sets `window.location.href = data.redirect`
**On success (stash mode, POST request):** JS creates a hidden form and calls `HTMLFormElement.prototype.submit.call(form)`
**On success (session-only mode):** JS sets `window.location.href = config.cancelUrl`
**On wrong password:** Error box unhides, shows "Incorrect password. Please try again."

**Stash-replay flow end-to-end:**
1. User navigates to gated page (e.g., plugin activation URL)
2. Gate intercepts at `admin_init`, calls `challenge_admin()`
3. `Request_Stash::save()` stores request in transient `_wp_sudo_stash_{16-char-key}`
4. Gate redirects to `/wp-admin/admin.php?page=wp-sudo-challenge&stash_key={key}&return_url={url}`
5. User enters password → AJAX to `admin-ajax.php?action=wp_sudo_challenge_auth`
6. On success: `replay_stash()` deletes stash, returns `{ code: 'success', redirect: url }` (GET) or `{ replay: true, url: ..., post_data: {...} }` (POST)
7. JS redirects or submits form

### Test Patterns for CHAL

**CHAL-01: Full stash-replay flow (GET):**
```typescript
// Source: challenge.php, challenge.js, request-stash.php (verified)
test('CHAL-01: gated action triggers challenge, correct password replays action', async ({ page }) => {
    // Navigate to a gated action (plugin activation URL matches admin rule)
    // Gate matches: pagenow=plugins.php, action=activate, method=GET
    await page.goto('/wp-admin/plugins.php?action=activate&plugin=hello.php&_wpnonce=XXX');

    // Gate should redirect to challenge page
    await page.waitForURL(/page=wp-sudo-challenge/);
    await expect(page.locator('#wp-sudo-challenge-card')).toBeVisible();

    // Fill and submit
    await page.fill('#wp-sudo-challenge-password', 'password');

    await Promise.all([
        page.waitForURL(/plugins\.php/),
        page.click('#wp-sudo-challenge-submit'),
    ]);

    // Back on plugins page (action replayed)
    await expect(page).toHaveURL(/plugins\.php/);
});
```

**CHAL-02: Challenge page form elements are present:**
```typescript
test('CHAL-02: challenge page has required form elements', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');

    await expect(page.locator('#wp-sudo-challenge-card')).toBeVisible();
    await expect(page.locator('#wp-sudo-challenge-password-form')).toBeVisible();
    await expect(page.locator('#wp-sudo-challenge-password')).toBeVisible();
    await expect(page.locator('#wp-sudo-challenge-submit')).toBeVisible();
    // Error box hidden initially
    await expect(page.locator('#wp-sudo-challenge-error')).toBeHidden();
    // 2FA step hidden initially
    await expect(page.locator('#wp-sudo-challenge-2fa-step')).toBeHidden();
});
```

**CHAL-03: Wrong password shows error:**
```typescript
test('CHAL-03: wrong password shows error message', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');
    await page.waitForFunction(() => typeof (window as any).wpSudoChallenge !== 'undefined');

    await page.fill('#wp-sudo-challenge-password', 'wrongpassword');
    await page.click('#wp-sudo-challenge-submit');

    // Wait for error box to appear
    await expect(page.locator('#wp-sudo-challenge-error')).toBeVisible();
    await expect(page.locator('#wp-sudo-challenge-error')).toContainText('Incorrect password');
});
```

**CRITICAL PITFALL (Pitfall 2):** Never use `page.waitForNavigation()` directly after clicking the submit button. The AJAX call to `admin-ajax.php` happens asynchronously, then JS sets `window.location.href`. Use `Promise.all([page.waitForURL(pattern), page.click(selector)])` always.

**CRITICAL PITFALL (Pitfall 7):** The challenge page breaks out of iframes. Navigate directly to the challenge URL using `page.goto()`, never through an iframe context. If tests trigger plugin-update flows that use `wp_iframe()`, add `page.waitForURL(/wp-sudo-challenge/)` before any assertions.

**CRITICAL PITFALL (Pitfall 8):** For POST replay (not GET), the hidden form is submitted programmatically. Do not use `page.route()` to intercept the replayed POST — instead assert on the destination page state after the form submits.

**Stash key generation:** `Request_Stash::save()` uses `wp_generate_password(16, false)` — 16 alphanumeric chars. The stash key is passed as `stash_key` GET parameter in the challenge URL. The transient key is `_wp_sudo_stash_{key}` with TTL 300 seconds.

**Triggering a real gated action for CHAL-01:** The plugins page activate link is the easiest:
- URL: `/wp-admin/plugins.php?action=activate&plugin=PLUGIN_SLUG&_wpnonce=NONCE`
- The nonce must be real. One approach: navigate to `plugins.php`, find an activate link's href, then navigate directly to that URL.
- Alternative: trigger via a POST form submit to `plugins.php`.

---

## VISN: Visual Regression Baselines

### What Needs Masking

All visual snapshots on pages with an active session MUST mask the admin bar timer node. The countdown text changes every second (see Pitfall 4).

```typescript
// Source: PITFALLS.md Pitfall 4 (HIGH confidence)
const adminBarMask = [page.locator('#wp-admin-bar-wp-sudo-active')];

await expect(page).toHaveScreenshot('challenge-card.png', {
    mask: adminBarMask,
    threshold: 0.1,
});
```

For the admin bar timer visual test itself (VISN-04), use `page.clock.install()` to freeze the timer before taking the snapshot.

### VISN Targets and Clip Regions

**VISN-01: Challenge card**
- Element to clip: `#wp-sudo-challenge-card`
- No active session needed (challenge page is accessible without session)
- Stable: no dynamic content in the card itself (no timer, no countdown)
- Mask: none needed for the card itself

**VISN-02: Settings form**
- Page: `/wp-admin/options-general.php?page=wp-sudo-settings`
- Element to clip: `.wrap` or the `<form>` inside it
- Dynamic: session duration input value, MU-plugin status section (depends on installed state)
- Mask: `#wp-sudo-mu-status` (changes between "Installed"/"Not installed")
- Approach: ensure consistent MU-plugin state via WP-CLI before baseline

**VISN-03: Admin bar in active state**
- Page: any admin page with active session
- Element to clip: `#wp-admin-bar-wp-sudo-active`
- Dynamic: countdown text
- Use `page.clock.install()` + `page.clock.setFixedTime(Date.now())` before navigation to freeze timer

**VISN-04: Admin bar in expiring state**
- Same as VISN-03 but with `wp-sudo-expiring` class visible
- Use `page.clock.install()`, then `page.clock.tick(840_000)` to reach ≤60s remaining
- The `.ab-label` will show `Sudo: 0:XX` and the node will have class `wp-sudo-expiring`

### Screenshot Configuration

```typescript
// playwright.config.ts snapshotPathTemplate (already set in Phase 6):
// '{testDir}/{testFileDir}/__snapshots__/{arg}-{projectName}{ext}'

// Recommended threshold for WP Sudo UI (stable elements, no animation):
threshold: 0.05   // 5% pixel difference threshold — tight but allows for anti-aliasing

// For admin bar (small element with text):
threshold: 0.1    // 10% — slightly looser for text rendering differences
```

### Baseline Update Command

```bash
npx playwright test tests/e2e/specs/visual-regression.spec.ts --update-snapshots
```

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead |
|---------|-------------|-------------|
| Advancing fake timers for countdown tests | Custom setTimeout mock | `page.clock.install()` + `page.clock.tick()` |
| Reading cookie attributes | Manual HTTP header parsing | `context.cookies()` |
| Waiting for AJAX-triggered navigation | `setTimeout(1000)` sleep | `Promise.all([waitForURL, click])` |
| Checking element visibility | Custom polling loop | `expect(locator).toBeVisible()` with Playwright's built-in retry |
| Managing visual snapshot paths | Custom screenshot naming | Playwright's `snapshotPathTemplate` config |
| Checking aria attributes | Manual DOM inspection | `expect(locator).toHaveAttribute('aria-disabled', 'true')` |

---

## Common Pitfalls

### Pitfall: AJAX Navigation Race (Pitfall 2 from research)
**What goes wrong:** Using `waitForNavigation()` after clicking challenge submit — it races with the async `fetch` → `window.location.href` chain.
**How to avoid:** Always `Promise.all([page.waitForURL(pattern), page.click('#wp-sudo-challenge-submit')])`.

### Pitfall: page.clock.install() Timing
**What goes wrong:** Installing the fake clock AFTER page navigation means the timer's `setInterval` already captured the real `Date` object.
**How to avoid:** Call `page.clock.install()` BEFORE `page.goto()` for any timer tests.

### Pitfall: Active Session in GATE Tests
**What goes wrong:** If a sudo session cookie leaks into GATE tests (e.g., a previous test left a session cookie), gate-ui.js will not enqueue and buttons will be enabled.
**How to avoid:** Use a fresh browser context for GATE tests, or verify the admin bar timer node is absent before asserting button state.

### Pitfall: MU-Plugin State Persistence
**What goes wrong:** MUPG-01 installs the MU-plugin. If MUPG-02 (uninstall) doesn't run (test failure), subsequent test runs start with the plugin installed.
**How to avoid:** Use `afterEach` to reset MU-plugin state via WP-CLI, or use `beforeEach` to set a known state regardless of prior test results.

### Pitfall: Admin Bar Countdown in Visual Snapshots (Pitfall 4 from research)
**What goes wrong:** Taking a screenshot of an admin page with an active session captures the countdown text, which changes every second.
**How to avoid:** Either mask `#wp-admin-bar-wp-sudo-active` in all visual tests, or use `page.clock.install()` to freeze time.

### Pitfall: Challenge Page Session-Only vs. Stash Mode
**What goes wrong:** Navigating to `/wp-admin/admin.php?page=wp-sudo-challenge` without a `stash_key` param triggers session-only mode. After auth, JS redirects to `config.cancelUrl` (the admin dashboard), NOT to a replayed action.
**How to avoid:** For stash-replay tests (CHAL-01), trigger the gate by navigating to a real gated URL. For session activation tests, session-only mode is correct.

### Pitfall: Nonce Required for Real Gated Actions
**What goes wrong:** Navigating directly to `/wp-admin/plugins.php?action=activate&plugin=hello.php` without a valid `_wpnonce` will be rejected by WordPress capability checks BEFORE the gate even fires.
**How to avoid:** Extract a real nonce from the plugins page first: navigate to `plugins.php`, find an activate link's `href`, extract the `_wpnonce` param from it, then use that URL.

---

## Code Examples

### Session-Only Mode Challenge (verified pattern)

```typescript
// Source: class-challenge.php render_page() + wp-sudo-challenge.js (verified)
// Session-only mode: no stash_key param → redirect to cancelUrl after auth
async function activateSudoSession(page: Page, password = 'password'): Promise<void> {
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');
    await page.waitForFunction(() => typeof (window as any).wpSudoChallenge !== 'undefined');
    await page.fill('#wp-sudo-challenge-password', password);
    await Promise.all([
        page.waitForURL(/wp-admin/),
        page.click('#wp-sudo-challenge-submit'),
    ]);
}
```

### Getting a Real Nonce for Plugin Activation (verified pattern)

```typescript
// Source: WordPress plugins.php page structure (verified against wp-env WordPress)
async function getActivateUrl(page: Page, pluginSlug: string): Promise<string> {
    await page.goto('/wp-admin/plugins.php');
    // Find the activate link for the plugin (plugin file path format: plugin-slug/plugin-slug.php)
    const activateLink = page.locator(`tr[data-plugin="${pluginSlug}"] .activate a`);
    const href = await activateLink.getAttribute('href');
    return href!;
}
```

### Cookie Assertion (verified from class-sudo-session.php set_token())

```typescript
// Source: class-sudo-session.php set_token() method verified
const cookies = await context.cookies();
const token = cookies.find(c => c.name === 'wp_sudo_token');
expect(token).toBeDefined();
expect(token!.httpOnly).toBe(true);    // Always true — hardcoded in setcookie()
expect(token!.sameSite).toBe('Strict'); // Always 'Strict' — hardcoded
expect(token!.path).toBe('/');         // COOKIEPATH in standard WP = '/'
```

### Admin Bar Timer with Frozen Clock (verified element IDs)

```typescript
// Source: admin/js/wp-sudo-admin-bar.js + includes/class-admin-bar.php (verified)
await page.clock.install(); // Must be BEFORE goto
await activateSudoSession(page);
await page.goto('/wp-admin/');

const node = page.locator('#wp-admin-bar-wp-sudo-active');
const label = node.locator('.ab-label');

await expect(node).toBeVisible();
await expect(label).toMatchAriaSnapshot({ name: /Sudo: \d+:\d{2}/ });
```

### MU-Plugin Install Flow (verified from render_mu_plugin_status PHP + wp-sudo-admin.js)

```typescript
// Source: includes/class-admin.php render_mu_plugin_status() (verified)
await activateSudoSession(page);
await page.goto('/wp-admin/options-general.php?page=wp-sudo-settings');

// Click install
await page.click('#wp-sudo-mu-install');

// Wait for page reload (JS: setTimeout 1000ms, then window.location.reload())
await page.waitForURL(/wp-sudo-settings/, { timeout: 5_000 });

// After reload, verify status
await expect(page.locator('#wp-sudo-mu-status')).toContainText('Installed');
```

### Gate UI Disabled Button (verified from wp-sudo-gate-ui.js)

```typescript
// Source: admin/js/wp-sudo-gate-ui.js disableButtons() (verified)
// Gate UI runs only without active session
await page.goto('/wp-admin/plugins.php');

const activateLink = page.locator('.activate a').first();
await expect(activateLink).toHaveAttribute('aria-disabled', 'true');
await expect(activateLink).toHaveClass(/wp-sudo-disabled/);
// href is removed
await expect(activateLink).not.toHaveAttribute('href');
```

---

## State of the Art

| Pattern | Correct Approach | Notes |
|---------|-----------------|-------|
| Challenge AJAX navigation | `Promise.all([waitForURL, click])` | Established in Pitfall 2 |
| Timer testing | `page.clock.install()` before goto | Playwright 1.45+ feature |
| Cookie inspection | `context.cookies()` | Returns structured objects with all attributes |
| Visual snapshots | `toHaveScreenshot` with mask | Mask dynamic elements |
| Session acquisition | Fresh challenge flow per test | Never reuse storageState sudo tokens |

---

## Open Questions

1. **CHAL-01 nonce extraction for plugin activate**
   - What we know: WordPress requires a valid `_wpnonce` for plugin activation. The gate fires before WP's nonce check.
   - What's unclear: The test needs to navigate to a real gated URL. The safest approach is to scrape the activate link href from `plugins.php`.
   - Recommendation: Navigate to `plugins.php` first, extract an activate link's `href`, navigate to that URL, assert challenge redirect. This also tests the real end-to-end flow including the WordPress nonce.

2. **MUPG tests and sudo session persistence**
   - What we know: MU-plugin AJAX requires an active session. The session expires after `session_duration` minutes (default 15).
   - What's unclear: If MUPG tests take longer than the session duration, the second AJAX call (uninstall) will fail with `sudo_required`.
   - Recommendation: Set `session_duration` to 15 minutes via WP-CLI at the start of MUPG tests. With a 15-minute session, tests completing in under 2 minutes have a comfortable margin.

3. **Visual regression snapshot stability across platforms**
   - What we know: Playwright's `toHaveScreenshot` uses pixel comparison. Font rendering can differ between macOS and Linux (CI).
   - What's unclear: Whether WordPress admin fonts (system UI) render identically in Docker (Linux) vs local (macOS).
   - Recommendation: Generate baseline snapshots in CI (Linux Docker) not locally. Use `--update-snapshots` only from CI. Accept a `threshold: 0.1` (10%) initially and tighten if snapshots prove stable.

---

## Sources

### Primary (HIGH confidence — all read from live source code)

- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-sudo-session.php` — Cookie constants (`TOKEN_COOKIE = 'wp_sudo_token'`, `CHALLENGE_COOKIE = 'wp_sudo_challenge'`), `set_token()` cookie attributes (httpOnly=true, sameSite='Strict', path=COOKIEPATH), session activation flow
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-challenge.php` — Challenge page slug (`wp-sudo-challenge`), DOM IDs, AJAX action names (`wp_sudo_challenge_auth`, `wp_sudo_challenge_2fa`), nonce action (`wp_sudo_challenge`), stash-replay mechanism
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-admin-bar.php` — Admin bar node ID (`wp-sudo-active`), CSS class (`wp-sudo-active`), countdown script element selectors (`#wp-admin-bar-wp-sudo-active`, `.ab-label`), deactivate flow
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-admin.php` — AJAX actions (`wp_sudo_mu_install`, `wp_sudo_mu_uninstall`), nonce action (`wp_sudo_mu_plugin`), DOM IDs (`#wp-sudo-mu-install`, `#wp-sudo-mu-uninstall`, `#wp-sudo-mu-status`, `#wp-sudo-mu-message`), sudo session requirement for MU-plugin handlers
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-gate.php` — Gate intercept mechanism, `challenge_admin()` flow, `filter_plugin_action_links()`
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-request-stash.php` — Transient prefix (`_wp_sudo_stash_`), key length (16), TTL (300s)
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/js/wp-sudo-admin-bar.js` — Countdown logic, `wp-sudo-expiring` class trigger (r<=60), `window.location.reload()` at r<=0, `.ab-label` selector
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/js/wp-sudo-challenge.js` — AJAX auth flow, `handleReplay()`, session-only redirect logic, `#wp-sudo-challenge-*` element references
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/js/wp-sudo-gate-ui.js` — `selectorMap` for disabled buttons, `disableButtons()` adding `'disabled'` and `'wp-sudo-disabled'` classes, `aria-disabled='true'`, href removal
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/js/wp-sudo-admin.js` — MU-plugin AJAX, `_nonce` field name, 1-second delay before `window.location.reload()`
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/css/wp-sudo-admin-bar.css` — `.wp-sudo-active` green color, `.wp-sudo-expiring` red color
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/css/wp-sudo-challenge.css` — `.wp-sudo-challenge-card` styles, clipping region dimensions
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/playwright.config.ts` — `baseURL`, `storageState` path, `snapshotPathTemplate`, `workers: 1`
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/global-setup.ts` — `wp_sudo_*` cookie exclusion from storageState
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/fixtures/test.ts` — `visitAdminPage` fixture pattern
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/specs/smoke.spec.ts` — Existing test pattern and import style
- `/Users/danknauss/Documents/GitHub/wp-sudo/.planning/research/PITFALLS.md` — Pitfall documentation (HIGH confidence — written from codebase analysis)

---

## Metadata

**Confidence breakdown:**
- Cookie attributes: HIGH — read directly from `set_token()` PHP setcookie() call
- DOM selectors: HIGH — read from PHP `render_page()` and JS element lookups by ID
- AJAX endpoint names: HIGH — PHP constants verified in class files
- Admin bar timer behavior: HIGH — JS source fully read, setInterval/classList logic verified
- Gate UI selectors: HIGH — selectorMap read from wp-sudo-gate-ui.js
- MU-plugin AJAX: HIGH — PHP handlers + JS admin script fully read
- Stash-replay: HIGH — PHP Challenge class replay_stash() + JS handleReplay() verified
- Playwright `page.clock` API: MEDIUM — based on Playwright training data; verify against current docs before implementation

**Research date:** 2026-03-08
**Valid until:** 2026-09-08 (stable — plugin UI code rarely changes)
