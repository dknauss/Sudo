# Phase 8: Keyboard Navigation + Admin Bar Interaction E2E — Research

**Researched:** 2026-03-09
**Domain:** Playwright E2E testing of keyboard events, focus management, and admin bar click-to-deactivate
**Confidence:** HIGH (all findings sourced from live plugin source code)

---

## Summary

Phase 8 completes the E2E suite with six tests covering keyboard-driven interactions and admin bar deactivation. All research was performed by reading the actual PHP and JavaScript source files. No training-data assumptions were used for selector names, event handler details, or behavior specifics.

The critical architectural split: Ctrl+Shift+S has **two completely separate handlers** in two different JS files, one for each session state. When no session is active, `wp-sudo-shortcut.js` handles the shortcut and navigates to the challenge page. When a session IS active, `wp-sudo-admin-bar.js` handles the shortcut and flashes the admin bar node. They are mutually exclusive — only one is ever enqueued for a given page load.

Admin bar deactivation is a **full page navigation** (not AJAX): clicking the timer node follows the `href` (a nonce URL), PHP calls `Sudo_Session::deactivate()` and then `wp_safe_redirect( remove_query_arg(...) )` to redirect back to the same page minus the deactivation params. The URL after clicking is the current page URL with `wp_sudo_deactivate` and `_wpnonce` removed.

**Primary recommendation:** Write keyboard tests using `page.keyboard.press()` and `page.evaluate(() => document.activeElement?.id)`. Write shortcut tests by pressing `Control+Shift+S` and asserting on navigation or CSS property change. Write admin bar deactivation test by clicking the node and asserting the URL is unchanged (same path, no deactivation params) after the redirect completes.

---

## Standard Stack

The Phase 6/7 infrastructure is already in place. Phase 8 adds no new npm packages.

### Already Available (from Phase 6)

| Library | Purpose |
|---------|---------|
| `@playwright/test` | Test runner, page API, keyboard events, URL assertions |
| `@wordpress/env` | WordPress environment at `http://localhost:8889` |

### Playwright APIs Needed Per Requirement

| Requirement | Key Playwright APIs |
|-------------|-------------------|
| KEYB-01 | `page.keyboard.press('Tab')`, `page.evaluate(() => document.activeElement?.id)` |
| KEYB-02 | `page.keyboard.press('Enter')`, `Promise.all([waitForURL, ...])` |
| KEYB-03 | `page.keyboard.press('Control+Shift+S')`, `page.waitForURL()` |
| KEYB-04 | `page.keyboard.press('Control+Shift+S')`, `page.evaluate(() => getComputedStyle(...).background)` |
| ABAR-01 | `page.click()` on admin bar node, `page.waitForURL()`, `context.cookies()` |
| ABAR-02 | `page.url()` before and after click, assert same path and no deactivation params |

---

## Architecture Patterns

### Existing File Layout

```
tests/e2e/
├── playwright.config.ts          # baseURL=http://localhost:8889, workers:1
├── global-setup.ts               # WP login, storageState without wp_sudo_* cookies
├── fixtures/
│   └── test.ts                   # test fixture + activateSudoSession() exported function
├── specs/
│   ├── smoke.spec.ts
│   ├── cookie.spec.ts            # COOK-01-03 (done)
│   ├── admin-bar-timer.spec.ts   # TIMR-01-04 (done)
│   ├── challenge.spec.ts         # CHAL-01-03 (done)
│   ├── gate-ui.spec.ts           # GATE-01-03 (done)
│   ├── mu-plugin.spec.ts         # MUPG-01-03 (done)
│   └── visual/
│       └── regression-baselines.spec.ts  # VISN-01-04 (done)
```

### Phase 8 New Files (from roadmap)

```
tests/e2e/specs/
├── challenge/
│   └── keyboard-navigation.spec.ts   # KEYB-01, KEYB-02
└── session/
    ├── keyboard-shortcut.spec.ts     # KEYB-03, KEYB-04
    └── admin-bar-deactivate.spec.ts  # ABAR-01, ABAR-02
```

---

## KEYB-01: Tab Order on Challenge Page

### What the Code Does

**Source:** `includes/class-challenge.php render_page()` (verified, lines 212–328)

The challenge page renders in normal document order. The DOM structure inside `#wp-sudo-challenge-password-step` is:

```html
<div id="wp-sudo-challenge-password-step">
    <!-- optional notice (lockout/throttle, hidden by default) -->
    <div id="wp-sudo-challenge-error" hidden role="alert">...</div>

    <form id="wp-sudo-challenge-password-form" method="post">
        <p>
            <label for="wp-sudo-challenge-password">Password</label><br>
            <input type="password" id="wp-sudo-challenge-password"
                   class="regular-text" autocomplete="current-password"
                   aria-describedby="wp-sudo-challenge-error"
                   required autofocus />
        </p>
        <p class="submit">
            <button type="submit" class="button button-primary"
                    id="wp-sudo-challenge-submit">
                Confirm & Continue
            </button>
            <a href="[cancelUrl]" class="button">Cancel</a>
        </p>
    </form>
</div>

<!-- 2FA step: hidden by default -->
<div id="wp-sudo-challenge-2fa-step" hidden>...</div>
```

**Tab sequence (standard document order, no tabindex overrides found):**

1. `#wp-sudo-challenge-password` (password input — has `autofocus`, receives focus on page load)
2. `#wp-sudo-challenge-submit` (submit button)
3. Cancel `<a>` link inside `#wp-sudo-challenge-password-step` (`.button` anchor)

**Key facts:**
- The password input has `autofocus` (when not disabled/throttled), so it receives focus immediately on page load. Tab from it moves to submit button.
- The error box (`#wp-sudo-challenge-error`) is `hidden` by default — hidden elements are removed from the tab order. It does not appear in the tab sequence until shown.
- The 2FA step (`#wp-sudo-challenge-2fa-step`) is `hidden` by default — not in tab order.
- The `#wp-sudo-challenge-card` header, `<h1>`, description `<p>`, and lecture `<ol>` are not focusable — not in tab order.
- The Cancel link is a standard `<a href="...">` — it IS in the tab order.
- No `tabindex` attributes found anywhere in the challenge page HTML.

**Confidence: HIGH** — DOM structure read directly from `render_page()`.

### Test Pattern for KEYB-01

```typescript
// Source: class-challenge.php render_page() — tab order verified from DOM structure
test('KEYB-01: Tab key traverses challenge form in correct order', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');
    await page.waitForFunction(
        () => typeof (window as any).wpSudoChallenge !== 'undefined'
    );

    // Password input receives autofocus — verify it's the initial active element.
    // Source: render_page() — autofocus on #wp-sudo-challenge-password (verified)
    const activeId = await page.evaluate(() => document.activeElement?.id);
    expect(activeId).toBe('wp-sudo-challenge-password');

    // Tab once → submit button
    await page.keyboard.press('Tab');
    const afterFirstTab = await page.evaluate(() => document.activeElement?.id);
    expect(afterFirstTab).toBe('wp-sudo-challenge-submit');

    // Tab again → Cancel link (inside #wp-sudo-challenge-password-step)
    await page.keyboard.press('Tab');
    const afterSecondTab = await page.evaluate(() => {
        const el = document.activeElement;
        return el?.tagName + ':' + el?.className;
    });
    // Cancel is an <a class="button"> — no ID
    expect(afterSecondTab).toContain('A');
});
```

**Pitfall:** The `autofocus` attribute only fires when the page loads fresh. If the test navigates to the challenge page while it's already loaded (e.g., from a redirect), the autofocus may not fire again. Always use `page.goto()` directly to the challenge page URL.

---

## KEYB-02: Enter Key Submits Challenge Form

### What the Code Does

**Source:** `admin/js/wp-sudo-challenge.js` (verified, lines 65–161)

The form submission handler is:

```javascript
if (passwordForm) {
    passwordForm.addEventListener('submit', function (e) {
        e.preventDefault();
        // ... AJAX fetch to admin-ajax.php
    });
}
```

**Enter key behavior:** The password form is a `<form method="post">` with a single `<button type="submit">`. When focus is inside the form and Enter is pressed, the browser fires a native form `submit` event. The JS `submit` event listener intercepts it with `e.preventDefault()` and runs the AJAX chain.

There is **no explicit keydown listener for Enter** — Enter submits by triggering the native form submit event, which the JS submit listener intercepts. This is standard browser behavior.

**On success (session-only mode):** `window.location.href = config.cancelUrl` (the admin dashboard). Use `Promise.all([waitForURL, keyboard.press('Enter')])`.

**Confidence: HIGH** — verified from wp-sudo-challenge.js form submit handler.

### Test Pattern for KEYB-02

```typescript
// Source: admin/js/wp-sudo-challenge.js — form submit listener intercepts native submit (verified)
// Enter key triggers native form submit → caught by passwordForm submit event listener
test('KEYB-02: Enter key submits challenge form', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');
    await page.waitForFunction(
        () => typeof (window as any).wpSudoChallenge !== 'undefined'
    );

    // Password input has autofocus — it is already focused on load.
    // Type password directly (no need to click first).
    await page.keyboard.type('password');

    // Press Enter to submit. Uses Promise.all because Enter triggers AJAX then
    // window.location.href — same async chain as clicking #wp-sudo-challenge-submit.
    // Source: admin/js/wp-sudo-challenge.js — submit listener calls fetch() then sets window.location.href (verified)
    await Promise.all([
        page.waitForURL(
            (url) => url.pathname.includes('/wp-admin/') && !url.search.includes('wp-sudo-challenge'),
            { timeout: 15_000 }
        ),
        page.keyboard.press('Enter'),
    ]);

    // Should have navigated away from challenge page (session activated)
    expect(page.url()).not.toContain('wp-sudo-challenge');
});
```

**Pitfall:** Do NOT use `page.fill('#wp-sudo-challenge-password', ...)` and then `page.keyboard.press('Enter')` on the form field — `fill()` does not move focus in all cases. Use `page.keyboard.type()` after verifying autofocus is active, or `page.fill()` followed by `page.focus('#wp-sudo-challenge-password')` explicitly before pressing Enter.

**Alternative:** Click the submit button instead of pressing Enter (exactly what KEYB-02 is testing — that Enter works). The safest approach is: fill the field via `page.fill()`, then press Tab to move focus to the submit button, then press Enter. This explicitly tests Enter-on-submit-button behavior.

---

## KEYB-03: Ctrl+Shift+S Navigates to Challenge Page (No Session)

### What the Code Does

**Source:** `admin/js/wp-sudo-shortcut.js` (verified, lines 13–29) + `includes/class-plugin.php enqueue_shortcut()` (verified, lines 183–224)

**The shortcut script (`wp-sudo-shortcut.js`):**

```javascript
var config = window.wpSudoShortcut || {};

if (!config.challengeUrl) {
    return;
}

document.addEventListener('keydown', function (e) {
    if (e.shiftKey && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        window.location.href = config.challengeUrl;
    }
});
```

**When it is enqueued (from `Plugin::enqueue_shortcut()`):**
- Enqueued on ALL admin pages
- Excluded when: no user logged in, session IS active (early return if `Sudo_Session::is_active()`), or on the challenge page itself
- The localized config `wpSudoShortcut.challengeUrl` is: `admin_url('admin.php') + '?page=wp-sudo-challenge&return_url=CURRENT_URL'`

**Navigation target:** `window.location.href = config.challengeUrl` — a standard navigation to the challenge page URL with `return_url` as the current admin page.

**Playwright shortcut:** `page.keyboard.press('Control+Shift+S')` on Linux/Windows. On macOS Chromium, the `e.metaKey` path would be `Meta+Shift+S`. The test environment (wp-env Docker on Linux CI) should use `Control+Shift+S`.

**Confidence: HIGH** — verified from wp-sudo-shortcut.js source and class-plugin.php enqueue conditions.

### Test Pattern for KEYB-03

```typescript
// Source: admin/js/wp-sudo-shortcut.js — keydown handler (verified)
// Source: class-plugin.php enqueue_shortcut() — enqueued when no session active (verified)
test('KEYB-03: Ctrl+Shift+S navigates to challenge page when no session active', async ({ page }) => {
    // No sudo session needed — shortcut script only loads without active session.
    // Navigate to any admin page. The shortcut JS will be enqueued.
    await page.goto('/wp-admin/');

    // Wait for the shortcut config to be available.
    // Source: class-plugin.php — wp_localize_script('wp-sudo-shortcut', 'wpSudoShortcut', ...) (verified)
    await page.waitForFunction(
        () => typeof (window as any).wpSudoShortcut !== 'undefined' && !!(window as any).wpSudoShortcut.challengeUrl
    );

    // Press Ctrl+Shift+S — triggers navigation to challenge page.
    // Source: wp-sudo-shortcut.js — window.location.href = config.challengeUrl (verified)
    await Promise.all([
        page.waitForURL(/page=wp-sudo-challenge/, { timeout: 10_000 }),
        page.keyboard.press('Control+Shift+S'),
    ]);

    // Verify we're on the challenge page.
    await expect(page.locator('#wp-sudo-challenge-card')).toBeVisible();
});
```

**Pitfall:** The shortcut is NOT loaded on the challenge page itself (class-plugin.php explicitly returns early if `page=wp-sudo-challenge`). Start on the admin dashboard, not on the challenge page.

**Pitfall:** The shortcut script uses `e.key.toLowerCase() === 's'` — Playwright's `Control+Shift+S` dispatches `key: 'S'` (uppercase because Shift is held). After `.toLowerCase()` this becomes `'s'`. This matches correctly.

---

## KEYB-04: Ctrl+Shift+S Flashes Admin Bar When Session Active

### What the Code Does

**Source:** `admin/js/wp-sudo-admin-bar.js` (verified, lines 83–101)

When a session IS active, `wp-sudo-admin-bar.js` is enqueued (not `wp-sudo-shortcut.js`). The shortcut handler inside it:

```javascript
document.addEventListener('keydown', function (e) {
    if (e.shiftKey && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (!a) {
            return;
        }
        // Skip animation if user prefers reduced motion.
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        a.style.setProperty('transition', 'background 0.15s ease', 'important');
        a.style.setProperty('background', '#4caf50', 'important');
        setTimeout(function () {
            a.style.removeProperty('background');
            a.style.removeProperty('transition');
        }, 300);
    }
});
```

Where `a = n.querySelector('.ab-item')` — the `<a>` element inside `#wp-admin-bar-wp-sudo-active`.

**Flash mechanism:**
- Selector: `.ab-item` inside `#wp-admin-bar-wp-sudo-active` — the actual clickable link element
- The flash sets `background: #4caf50` (bright green) as an inline style with `!important`
- After 300ms, the inline style properties are removed (reverting to the CSS-defined green `#2e7d32`)
- The `prefers-reduced-motion` media query bypasses the flash entirely

**What to assert:**
- Immediately after pressing the shortcut: `a.style.getPropertyValue('background')` or `a.style.background` should be `#4caf50` (inline style temporarily set)
- OR check for `transition` inline style (also temporarily set)
- After 300ms: the inline styles are removed

**Important — CSS specificity:** The CSS file defines `background: #2e7d32 !important` on `.wp-sudo-active .ab-item`. The JS sets the inline style with `'important'` flag via `style.setProperty(..., 'important')` which overrides the stylesheet. This is the flash mechanism.

**Confidence: HIGH** — verified from wp-sudo-admin-bar.js lines 83–101.

### Test Pattern for KEYB-04

```typescript
// Source: admin/js/wp-sudo-admin-bar.js — keydown handler sets inline style on .ab-item (verified)
test('KEYB-04: Ctrl+Shift+S flashes admin bar node when session is active', async ({ page }) => {
    // Activate a sudo session first — shortcut handler only exists in admin-bar.js
    // which is enqueued only when session IS active.
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    // Verify admin bar timer node is visible (session confirmed active).
    await expect(page.locator('#wp-admin-bar-wp-sudo-active')).toBeVisible();

    // Press Ctrl+Shift+S — triggers flash animation on .ab-item.
    await page.keyboard.press('Control+Shift+S');

    // Assert inline background style was set immediately (before setTimeout fires).
    // Source: admin/js/wp-sudo-admin-bar.js — a.style.setProperty('background', '#4caf50', 'important') (verified)
    // Target: #wp-admin-bar-wp-sudo-active > .ab-item (the <a> element)
    const flashBackground = await page.evaluate(() => {
        const el = document.querySelector('#wp-admin-bar-wp-sudo-active .ab-item');
        return el ? el.style.getPropertyValue('background') : null;
    });
    expect(flashBackground).toBe('#4caf50');
});
```

**Alternative assertion approach** — check for the transition inline style (also set at the same time):

```typescript
const hasTransition = await page.evaluate(() => {
    const el = document.querySelector('#wp-admin-bar-wp-sudo-active .ab-item');
    return el ? el.style.getPropertyValue('transition') : null;
});
expect(hasTransition).toContain('0.15s');
```

**Pitfall:** The flash lasts only 300ms. The assertion must run BEFORE the `setTimeout(fn, 300)` fires and removes the inline styles. Since `page.keyboard.press()` is synchronous from the test perspective and `page.evaluate()` runs in the same JS tick, this timing is reliable — we check the style before the 300ms timeout fires.

**Pitfall:** If `prefers-reduced-motion: reduce` is active in the test environment, the flash is skipped entirely. Chromium headless respects system prefers-reduced-motion. Force the media query override in the test if needed: `await page.emulateMedia({ reducedMotion: 'no-preference' })`.

---

## ABAR-01: Clicking Admin Bar Timer Deactivates Session

### What the Code Does

**Source:** `includes/class-admin-bar.php` — `admin_bar_node()` and `handle_deactivate()` (verified)

**The admin bar node:**

```php
$wp_admin_bar->add_node([
    'id'   => 'wp-sudo-active',
    'href' => $deactivate_url,  // wp_nonce_url(add_query_arg('wp_sudo_deactivate', '1', $current_url), 'wp_sudo_deactivate', '_wpnonce')
    'meta' => ['class' => 'wp-sudo-active'],
]);
```

The `<li id="wp-admin-bar-wp-sudo-active">` contains an `<a class="ab-item">` whose `href` is the current page URL with `?wp_sudo_deactivate=1&_wpnonce=NONCE` appended.

**The deactivate handler (at `admin_init` priority 5):**

```php
public function handle_deactivate(): void {
    if (!isset($_GET[self::DEACTIVATE_PARAM])) {
        return;
    }
    // ... verify nonce
    Sudo_Session::deactivate($user_id);
    wp_safe_redirect(remove_query_arg([self::DEACTIVATE_PARAM, '_wpnonce']));
    exit;
}
```

**Full flow when clicking the admin bar node:**
1. Browser navigates to current URL + `?wp_sudo_deactivate=1&_wpnonce=NONCE`
2. WordPress fires `admin_init`
3. `handle_deactivate()` runs at priority 5 — sees `wp_sudo_deactivate` param, verifies nonce
4. `Sudo_Session::deactivate($user_id)` — removes token meta and expires the cookie
5. `wp_safe_redirect(remove_query_arg(['wp_sudo_deactivate', '_wpnonce']))` — redirects to current URL WITHOUT the deactivation params
6. `exit`

**Result:** The browser ends up on the SAME PAGE (same path, same other query params) but without `wp_sudo_deactivate` and `_wpnonce`. The URL is effectively the same as before the click (minus only the params that were added for deactivation).

**Session state after:** No `wp_sudo_token` cookie (expired), admin bar timer node absent.

**Confidence: HIGH** — verified from class-admin-bar.php handle_deactivate() source.

### Test Pattern for ABAR-01

```typescript
// Source: class-admin-bar.php handle_deactivate() — deactivates session and wp_safe_redirect (verified)
test('ABAR-01: clicking admin bar timer node deactivates sudo session', async ({ page, context }) => {
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    // Verify session is active (admin bar node visible).
    const timerNode = page.locator('#wp-admin-bar-wp-sudo-active');
    await expect(timerNode).toBeVisible();

    // Record URL before clicking (minus any dynamic parts).
    const urlBefore = page.url();

    // Click the admin bar node (its href is the deactivate URL).
    // This triggers a full page navigation (not AJAX).
    // Source: class-admin-bar.php — href is wp_nonce_url with wp_sudo_deactivate=1 (verified)
    await Promise.all([
        page.waitForURL(/wp-admin/, { waitUntil: 'load', timeout: 10_000 }),
        timerNode.click(),
    ]);

    // After deactivation, the timer node must be absent.
    // Source: class-admin-bar.php admin_bar_node() — only adds node when session active (verified)
    await expect(timerNode).not.toBeVisible();

    // Session cookie must be gone.
    // Source: class-sudo-session.php deactivate() — expires the wp_sudo_token cookie (verified)
    const cookies = await context.cookies();
    const sudoCookie = cookies.find(c => c.name === 'wp_sudo_token');
    expect(sudoCookie).toBeUndefined();
});
```

---

## ABAR-02: URL Does Not Change After Admin Bar Deactivation Click

### What the Code Does

**Source:** `includes/class-admin-bar.php handle_deactivate()` (verified)

```php
wp_safe_redirect(remove_query_arg([self::DEACTIVATE_PARAM, '_wpnonce']));
```

`self::DEACTIVATE_PARAM = 'wp_sudo_deactivate'`

`remove_query_arg()` strips `wp_sudo_deactivate` and `_wpnonce` from the URL, leaving all other params intact. The redirect target is the same path as before the click.

**When starting from `/wp-admin/`:** The current URL is `http://localhost:8889/wp-admin/`. The deactivate URL adds `?wp_sudo_deactivate=1&_wpnonce=X`. After deactivation, `remove_query_arg()` strips those, resulting in `http://localhost:8889/wp-admin/` (same as before).

**Expected behavior:** Before click = `http://localhost:8889/wp-admin/`. After click = `http://localhost:8889/wp-admin/`. Paths are identical. No additional query params are added.

**Confidence: HIGH** — verified from handle_deactivate() source.

### Test Pattern for ABAR-02

```typescript
// Source: class-admin-bar.php handle_deactivate() — wp_safe_redirect(remove_query_arg(...)) (verified)
// The redirect target is the same URL without wp_sudo_deactivate and _wpnonce params.
test('ABAR-02: URL does not change after admin bar deactivation click', async ({ page }) => {
    await activateSudoSession(page);

    // Navigate to a specific admin page to have a known URL before click.
    await page.goto('/wp-admin/');
    const urlBefore = new URL(page.url());

    // Click admin bar node (follows href with deactivation params → PHP redirects back).
    await Promise.all([
        page.waitForURL(/wp-admin/, { waitUntil: 'load', timeout: 10_000 }),
        page.locator('#wp-admin-bar-wp-sudo-active').click(),
    ]);

    const urlAfter = new URL(page.url());

    // Path must be the same.
    expect(urlAfter.pathname).toBe(urlBefore.pathname);

    // Deactivation params must NOT be present in final URL.
    // Source: class-admin-bar.php DEACTIVATE_PARAM = 'wp_sudo_deactivate' (verified)
    expect(urlAfter.searchParams.has('wp_sudo_deactivate')).toBe(false);
    expect(urlAfter.searchParams.has('_wpnonce')).toBe(false);
});
```

**Pitfall:** There IS a navigation (the click navigates to the deactivation URL and PHP redirects back). The test must use `waitForURL` to await the redirect completion before asserting the final URL. Do not assert the URL immediately after clicking — assert after `waitForURL` resolves.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Reading active element for focus assertions | Custom DOM query | `page.evaluate(() => document.activeElement?.id)` | Playwright provides direct browser execution |
| Checking inline style after flash | CSS computed style snapshot + timer | `page.evaluate(() => el.style.getPropertyValue('background'))` immediately after keypress | Flash inline style is synchronously set before setTimeout fires |
| Waiting for admin bar deactivation | Custom polling for timer node disappearance | `Promise.all([page.waitForURL(...), node.click()])` then `expect(node).not.toBeVisible()` | Standard Playwright navigation pattern |
| Clearing sudo session cookies | Relogging in | `clearSudoSession()` helper from challenge.spec.ts | Already established in Phase 7 |
| Advancing fake timers for flash timing | `page.waitForTimeout(350)` | The flash is synchronous — assert immediately after `keyboard.press()`, no timer advance needed | The 300ms setTimeout fires browser-side; the style is set synchronously before it fires |

---

## Common Pitfalls

### Pitfall A: Two Separate Shortcut Handlers, Two Separate Scripts

**What goes wrong:** Testing Ctrl+Shift+S on a page with an active session expecting navigation — it won't navigate because `wp-sudo-admin-bar.js` handles the shortcut (flash only). Testing without a session expecting a flash — `wp-sudo-shortcut.js` navigates instead.

**Root cause:** The two behaviors are in mutually exclusive scripts. `enqueue_shortcut()` returns early if session is active; `enqueue_assets()` in Admin_Bar only runs when session IS active.

**How to avoid:** KEYB-03 must test with NO session active (dashboard, no sudo cookie). KEYB-04 must test WITH an active session (call `activateSudoSession()` first).

**Warning signs:** KEYB-03 test navigates but the URL matches the wrong pattern; KEYB-04 test sees no inline style change.

### Pitfall B: Flash Animation Skipped Under prefers-reduced-motion

**What goes wrong:** KEYB-04 assertion on the flash inline style returns null because the animation was skipped.

**Root cause:** `wp-sudo-admin-bar.js` checks `window.matchMedia('(prefers-reduced-motion: reduce)')` and returns early if true.

**How to avoid:** Add `await page.emulateMedia({ reducedMotion: 'no-preference' })` before pressing the shortcut in KEYB-04.

**Warning signs:** KEYB-04 passes sometimes, fails other times depending on system media preferences.

### Pitfall C: autofocus Not Reliable After Navigation Redirects

**What goes wrong:** KEYB-01 asserts autofocus on `#wp-sudo-challenge-password` but the active element is `<body>` or another element.

**Root cause:** `autofocus` fires on initial page load. If the test arrives at the challenge page via a redirect (e.g., from a gated URL), the autofocus attribute fires correctly because it's a fresh page load. But if the JS is still initializing, `document.activeElement` may transiently be `body`.

**How to avoid:** After `page.goto('/wp-admin/admin.php?page=wp-sudo-challenge')`, wait for `wpSudoChallenge` to be defined before evaluating `document.activeElement`. The `waitForFunction(() => typeof window.wpSudoChallenge !== 'undefined')` call ensures the page is fully initialized.

**Warning signs:** KEYB-01 fails intermittently with `activeElement?.id` being undefined or 'body'.

### Pitfall D: Admin Bar Click is a Full Navigation, Not AJAX

**What goes wrong:** Treating the admin bar deactivation click like an AJAX action and using `page.waitForResponse()` to detect completion. The click follows a real `href` and PHP redirects — there is no XHR.

**Root cause:** `handle_deactivate()` uses `wp_safe_redirect()` + `exit`, not `wp_send_json_success()`. It's a GET request that returns a 302 redirect.

**How to avoid:** Use `Promise.all([page.waitForURL(...), node.click()])`. Assert final URL after `waitForURL` resolves.

**Warning signs:** `page.waitForResponse()` or `page.waitForRequest()` timing out; URL assertion running before the redirect completes.

### Pitfall E: No Session = No Admin Bar Node = Cannot Click It

**What goes wrong:** Clicking `#wp-admin-bar-wp-sudo-active` when there is no active session — the node doesn't exist.

**Root cause:** `admin_bar_node()` returns early if `Sudo_Session::is_active()` is false.

**How to avoid:** Always call `activateSudoSession(page)` before ABAR-01 and ABAR-02 tests. Add an explicit `expect(timerNode).toBeVisible()` assertion before clicking to fail fast if the session is absent.

### Pitfall F: Shortcut script not enqueued on challenge page itself

**What goes wrong:** KEYB-03 test navigates to the challenge page first, then presses the shortcut expecting navigation — but `wpSudoShortcut` config is not set because the shortcut script is excluded on the challenge page.

**Root cause:** `enqueue_shortcut()` has an explicit guard: `if ('wp-sudo-challenge' === $page) { return; }`.

**How to avoid:** For KEYB-03, navigate to the DASHBOARD (`/wp-admin/`) first, then press the shortcut. Never test KEYB-03 from the challenge page.

---

## Code Examples

### Complete Tab Order Test (KEYB-01)

```typescript
// Source: class-challenge.php render_page() — DOM order: password input → submit → cancel (verified)
test('KEYB-01: Tab traverses challenge form in correct order', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');
    await page.waitForFunction(
        () => typeof (window as any).wpSudoChallenge !== 'undefined'
    );

    // Password input has autofocus.
    // Source: render_page() — autofocus attribute on #wp-sudo-challenge-password (verified)
    let activeId = await page.evaluate(() => document.activeElement?.id);
    expect(activeId).toBe('wp-sudo-challenge-password');

    // Tab → submit button
    await page.keyboard.press('Tab');
    activeId = await page.evaluate(() => document.activeElement?.id);
    expect(activeId).toBe('wp-sudo-challenge-submit');

    // Tab → Cancel link (an <a class="button"> with no ID)
    await page.keyboard.press('Tab');
    const activeTag = await page.evaluate(() => document.activeElement?.tagName?.toLowerCase());
    const activeText = await page.evaluate(() => (document.activeElement as HTMLElement)?.textContent?.trim());
    expect(activeTag).toBe('a');
    expect(activeText).toBe('Cancel');
});
```

### Enter Key Submits via Form Submit Event (KEYB-02)

```typescript
// Source: admin/js/wp-sudo-challenge.js — submit event listener, not keydown (verified)
// Enter on focused password input fires native form submit → caught by JS submit handler
test('KEYB-02: Enter key submits challenge form', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wp-sudo-challenge');
    await page.waitForFunction(
        () => typeof (window as any).wpSudoChallenge !== 'undefined'
    );

    // Fill password field (autofocus ensures it's active).
    await page.fill('#wp-sudo-challenge-password', 'password');

    // Press Enter — triggers native form submit → JS submit handler → AJAX → navigation
    await Promise.all([
        page.waitForURL(
            (url) => url.pathname.includes('/wp-admin/') && !url.search.includes('wp-sudo-challenge'),
            { timeout: 15_000 }
        ),
        page.keyboard.press('Enter'),
    ]);

    expect(page.url()).not.toContain('wp-sudo-challenge');
});
```

### Shortcut Navigation When No Session (KEYB-03)

```typescript
// Source: admin/js/wp-sudo-shortcut.js — keydown handler navigates to wpSudoShortcut.challengeUrl (verified)
// Source: class-plugin.php enqueue_shortcut() — only enqueued when session NOT active (verified)
test('KEYB-03: Ctrl+Shift+S navigates to challenge page when no session active', async ({ page }) => {
    // No sudo session — shortcut script is loaded (not admin-bar.js).
    await page.goto('/wp-admin/');

    await page.waitForFunction(
        () => typeof (window as any).wpSudoShortcut !== 'undefined' && !!(window as any).wpSudoShortcut.challengeUrl
    );

    await Promise.all([
        page.waitForURL(/page=wp-sudo-challenge/, { timeout: 10_000 }),
        page.keyboard.press('Control+Shift+S'),
    ]);

    await expect(page.locator('#wp-sudo-challenge-card')).toBeVisible();
});
```

### Flash Assertion When Session Active (KEYB-04)

```typescript
// Source: admin/js/wp-sudo-admin-bar.js — a.style.setProperty('background', '#4caf50', 'important') (verified)
// Target element: #wp-admin-bar-wp-sudo-active .ab-item (the <a> element)
test('KEYB-04: Ctrl+Shift+S flashes admin bar when session active', async ({ page }) => {
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    await expect(page.locator('#wp-admin-bar-wp-sudo-active')).toBeVisible();

    // Ensure prefers-reduced-motion does not suppress the animation.
    // Source: admin/js/wp-sudo-admin-bar.js — returns early if prefers-reduced-motion:reduce (verified)
    await page.emulateMedia({ reducedMotion: 'no-preference' });

    // Press shortcut — inline style is set synchronously (before setTimeout fires).
    await page.keyboard.press('Control+Shift+S');

    // Assert flash inline style is set.
    // The setTimeout(fn, 300) removes the style after 300ms — assert before that fires.
    const flashBg = await page.evaluate(() => {
        const el = document.querySelector('#wp-admin-bar-wp-sudo-active .ab-item') as HTMLElement | null;
        return el ? el.style.getPropertyValue('background') : null;
    });
    expect(flashBg).toBe('#4caf50');
});
```

### Admin Bar Deactivation (ABAR-01 + ABAR-02 combined pattern)

```typescript
// Source: class-admin-bar.php handle_deactivate() — wp_safe_redirect(remove_query_arg(...)) (verified)
// Full navigation: click href → PHP deactivates → redirects back to same URL minus deactivation params
test('ABAR-01+02: clicking admin bar deactivates session, URL unchanged', async ({ page, context }) => {
    await activateSudoSession(page);
    await page.goto('/wp-admin/');

    const timerNode = page.locator('#wp-admin-bar-wp-sudo-active');
    await expect(timerNode).toBeVisible();

    const urlBefore = new URL(page.url());

    await Promise.all([
        page.waitForURL(/wp-admin/, { waitUntil: 'load', timeout: 10_000 }),
        timerNode.click(),
    ]);

    // ABAR-01: session deactivated — timer node gone, no sudo cookie
    await expect(timerNode).not.toBeVisible();
    const cookies = await context.cookies();
    const sudoCookie = cookies.find(c => c.name === 'wp_sudo_token');
    expect(sudoCookie).toBeUndefined();

    // ABAR-02: URL unchanged (same path, deactivation params removed)
    const urlAfter = new URL(page.url());
    expect(urlAfter.pathname).toBe(urlBefore.pathname);
    expect(urlAfter.searchParams.has('wp_sudo_deactivate')).toBe(false);
    expect(urlAfter.searchParams.has('_wpnonce')).toBe(false);
});
```

---

## State of the Art

| Pattern | Correct Approach | Notes |
|---------|-----------------|-------|
| Focus assertion | `page.evaluate(() => document.activeElement?.id)` | Works in all Playwright versions |
| Shortcut test (no session) | Press `Control+Shift+S`, `Promise.all([waitForURL, press])` | `wp-sudo-shortcut.js` does `window.location.href` — standard navigation |
| Shortcut test (with session) | Press `Control+Shift+S`, assert inline style immediately | Flash is synchronous; no clock manipulation needed |
| Admin bar deactivation | `Promise.all([waitForURL, click])`, assert URL and cookie | Full navigation (not AJAX) |
| prefers-reduced-motion | `page.emulateMedia({ reducedMotion: 'no-preference' })` | Must be called before the interaction that checks the media query |
| Clearing sudo cookie | `clearSudoSession()` from challenge.spec.ts | Already established in Phase 7 |

---

## Open Questions

1. **KEYB-02: Enter key from which element?**
   - What we know: The password input has `autofocus`. The form submit event fires when Enter is pressed within the form.
   - What's unclear: Is Enter better tested from the password field (more realistic) or from the submit button (more explicit)?
   - Recommendation: Test Enter from the password field (which has autofocus). This covers the most common user interaction. For the submit button case, Tab to it first, then press Enter.

2. **KEYB-04 timing precision**
   - What we know: The flash sets inline styles synchronously (in the keydown handler), then removes them after 300ms via `setTimeout`.
   - What's unclear: In some headless browser configurations, `page.evaluate()` after `page.keyboard.press()` may run after micro-tasks settle. Is 300ms enough margin?
   - Recommendation: Assert the inline style immediately after `keyboard.press()`. In practice, `page.keyboard.press()` is awaited, then `page.evaluate()` runs in the same JavaScript event loop — well within the 300ms window. If this proves flaky, use `page.clock.install()` before pressing to freeze the setTimeout.

3. **macOS vs Linux shortcut key**
   - What we know: The JS checks `e.ctrlKey || e.metaKey`. Playwright's `Control+Shift+S` dispatches `ctrlKey=true`. On macOS Chromium, `Meta+Shift+S` would dispatch `metaKey=true`.
   - What's unclear: CI runs on Linux (Ubuntu) where Ctrl is the correct modifier.
   - Recommendation: Use `Control+Shift+S` in all tests. On Linux CI this matches `e.ctrlKey`. Local macOS developers may need to know `Meta+Shift+S` also works, but tests should be CI-first.

---

## Sources

### Primary (HIGH confidence — all read from live source code)

- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/js/wp-sudo-shortcut.js` — keydown handler (`e.shiftKey && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'`), `window.location.href = config.challengeUrl` navigation, guard on `config.challengeUrl`
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/js/wp-sudo-admin-bar.js` — shortcut handler that flashes `.ab-item` with `background: #4caf50`, `style.setProperty(..., 'important')`, 300ms `setTimeout` removal, `prefers-reduced-motion` guard
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/js/wp-sudo-challenge.js` — form submit event listener (`passwordForm.addEventListener('submit', ...)`), `e.preventDefault()`, AJAX fetch chain, `window.location.href = config.cancelUrl` on success, Escape key `keydown` listener (navigate to cancelUrl)
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-challenge.php render_page()` — full DOM structure: tab order (password input → submit button → cancel link), `autofocus` on password input, `hidden` on error box and 2FA step, no `tabindex` attributes
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-admin-bar.php` — `handle_deactivate()` full flow: nonce verify → `Sudo_Session::deactivate()` → `wp_safe_redirect(remove_query_arg(['wp_sudo_deactivate', '_wpnonce']))` → `exit`; `admin_bar_node()` — `href` is nonce URL with `wp_sudo_deactivate=1`; `DEACTIVATE_PARAM = 'wp_sudo_deactivate'`
- `/Users/danknauss/Documents/GitHub/wp-sudo/includes/class-plugin.php enqueue_shortcut()` — enqueue conditions: no session active, not on challenge page, user logged in; `wpSudoShortcut.challengeUrl` = `admin_url('admin.php')` + `?page=wp-sudo-challenge&return_url=CURRENT_URL`
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/css/wp-sudo-admin-bar.css` — `.wp-sudo-active .ab-item { background: #2e7d32 !important }` (baseline green), `.wp-sudo-expiring .ab-item { background: #c62828 !important }` (red), `transition: background 0.3s ease` on active state, `transition: none` under `prefers-reduced-motion`
- `/Users/danknauss/Documents/GitHub/wp-sudo/admin/css/wp-sudo-challenge.css` — `.wp-sudo-challenge-card :focus-visible { outline: 2px solid #2271b1; outline-offset: 2px }` (focus-visible ring)
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/specs/challenge.spec.ts` — `clearSudoSession()`, `clearSudoIpTransients()`, `withCliPolicyUnrestricted()` helpers (patterns to reuse)
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/specs/admin-bar-timer.spec.ts` — `page.clock.install()` after `activateSudoSession()` before `page.goto()` pattern, `runFor()` not `tick()` (Playwright 1.58.2 API)
- `/Users/danknauss/Documents/GitHub/wp-sudo/tests/e2e/fixtures/test.ts` — `activateSudoSession()` function, `clearSudoSession()` pattern established in challenge.spec.ts, `waitForURL` predicate pattern

---

## Metadata

**Confidence breakdown:**
- Tab order (KEYB-01): HIGH — DOM structure read directly from render_page() PHP
- Enter key behavior (KEYB-02): HIGH — submit event listener verified in challenge.js; no explicit Enter keydown listener
- Shortcut navigation (KEYB-03): HIGH — wp-sudo-shortcut.js fully read; enqueue conditions verified from class-plugin.php
- Shortcut flash (KEYB-04): HIGH — flash handler fully read from wp-sudo-admin-bar.js; inline style mechanism verified
- Admin bar deactivation flow (ABAR-01/02): HIGH — handle_deactivate() fully read from class-admin-bar.php; redirect behavior verified
- Playwright keyboard API (`page.keyboard.press`): HIGH — used in Phase 7 tests, well-established
- `page.emulateMedia()`: MEDIUM — Playwright training data; verify exact API if issues arise

**Research date:** 2026-03-09
**Valid until:** 2026-09-09 (stable — plugin keyboard and admin bar code rarely changes)
