/**
 * Admin bar timer tests — TIMR-01, TIMR-02, TIMR-03, TIMR-04
 *
 * Verify the admin bar countdown timer behaviour during an active sudo session.
 * Uses page.clock to control JavaScript time deterministically — no real waiting.
 *
 * Admin bar node added by: includes/class-admin-bar.php admin_bar_node() (verified)
 * Countdown logic: admin/js/wp-sudo-admin-bar.js (verified)
 *
 * Key element selectors (verified from class-admin-bar.php and wp-sudo-admin-bar.js):
 *   - Admin bar list item:  #wp-admin-bar-wp-sudo-active   (li element)
 *   - Label span:           #wp-admin-bar-wp-sudo-active .ab-label
 *   - Timer text format:    'Sudo: M:SS'  (e.g. 'Sudo: 14:59')
 *   - Expiring CSS class:   wp-sudo-expiring  (added to li when remaining <= 60)
 *   - Reload trigger:       window.location.reload() when remaining <= 0
 *
 * CRITICAL PITFALL (Pitfall 4 / TIMR-specific):
 *   page.clock.install() MUST be called BEFORE page.goto() and BEFORE
 *   activateSudoSession(). The admin-bar.js setInterval() captures the Date object
 *   at script-load time. If clock is installed after navigation, the setInterval
 *   already has a reference to the real clock and will not respond to runFor().
 *
 * Playwright clock API (Playwright 1.45+):
 *   page.clock.install()    — freezes Date, setTimeout, setInterval
 *   page.clock.runFor(ms)   — runs all timer callbacks that would fire within ms
 *                             (equivalent to sinon's tick() — fires intermediate callbacks)
 *   page.clock.fastForward(ms) — jumps time to ms in the future WITHOUT running
 *                                 intermediate callbacks (does NOT fire setInterval)
 *   NOTE: There is no tick() method — use runFor() to trigger setInterval callbacks.
 *
 * PHP/JS clock separation (TIMR-04 specific):
 *   page.clock only affects browser-side JavaScript. PHP uses the real wall clock (time()).
 *   The JS countdown timer can reach zero and call window.location.reload() via fake clock,
 *   but the PHP session will still be active on the reloaded page unless we expire it
 *   server-side. TIMR-04 uses WP-CLI to zero out the server-side session expiry meta before
 *   ticking to zero, ensuring PHP also considers the session expired on reload.
 *   Source: includes/class-sudo-session.php META_KEY = '_wp_sudo_expires' (verified)
 *
 * Session duration: default is 15 minutes (900 seconds). Tests use runFor() to fast-
 * forward time rather than changing the session_duration setting — simpler and avoids
 * WP-CLI setup overhead.
 *
 * Session duration WP-CLI reference (not used here, kept for documentation):
 *   npx wp-env run tests-cli wp option patch update wp_sudo_settings session_duration 1
 */
import { execSync } from 'child_process';
import { test, expect, activateSudoSession } from '../fixtures/test';

test.describe( 'Admin bar timer', () => {
    /**
     * TIMR-01: Timer is visible and has correct M:SS format.
     *
     * Does NOT use page.clock — we just verify the initial render is correct.
     * The real clock runs; we check the text within a few seconds of activation
     * so the countdown text changes won't affect the format assertion.
     */
    test( 'TIMR-01: admin bar shows Sudo: M:SS countdown during active session', async ( {
        page,
    } ) => {
        // Activate session then navigate to any admin page.
        // activateSudoSession() uses session-only mode → redirects to dashboard.
        await activateSudoSession( page );
        await page.goto( '/wp-admin/' );

        // Source: class-admin-bar.php — node id 'wp-sudo-active' → li#wp-admin-bar-wp-sudo-active (verified)
        const timerNode = page.locator( '#wp-admin-bar-wp-sudo-active' );
        await expect(
            timerNode,
            'Admin bar timer node must be visible when session is active'
        ).toBeVisible();

        // Source: wp-sudo-admin-bar.js — text format: 'Sudo: ' + m + ':' + (s<10?'0':'') + s (verified)
        // Regex matches 'Sudo: 14:59', 'Sudo: 1:00', 'Sudo: 0:01'
        const label = timerNode.locator( '.ab-label' );
        await expect(
            label,
            'Timer label must match Sudo: M:SS format'
        ).toHaveText( /^Sudo: \d+:\d{2}$/ );
    } );

    /**
     * TIMR-02: Timer text updates each second.
     *
     * Uses page.clock to advance time by 1000ms and verify the text changed.
     * clock.install() called before activateSudoSession() so the challenge page's
     * scripts also run under the fake clock, then we navigate to admin dashboard.
     */
    test( 'TIMR-02: timer text updates after 1 second (clock-controlled)', async ( {
        page,
    } ) => {
        // Activate session FIRST with real clock — challenge page JS needs real timers
        // to process the AJAX auth flow. Then install fake clock BEFORE navigating to
        // the admin dashboard (where the admin-bar timer JS captures setInterval).
        await activateSudoSession( page );

        // Install fake clock AFTER session activation but BEFORE admin page load.
        await page.clock.install();

        await page.goto( '/wp-admin/' );

        // Source: wp-sudo-admin-bar.js — setInterval(fn, 1000) decrements remaining (verified)
        const label = page.locator( '#wp-admin-bar-wp-sudo-active .ab-label' );
        await expect( label ).toBeVisible();

        const textBefore = await label.textContent();

        // Advance 1 second of fake time — runFor() fires the setInterval callback once.
        // Source: Playwright clock API — runFor(ms) runs timer callbacks within the time range
        // NOTE: tick() does not exist in this Playwright version; runFor() is the equivalent.
        await page.clock.runFor( 1000 );

        const textAfter = await label.textContent();

        expect(
            textBefore,
            'Timer text must exist before tick'
        ).toBeTruthy();
        expect(
            textAfter,
            'Timer text must exist after tick'
        ).toBeTruthy();
        expect(
            textAfter,
            'Timer text must change after 1 second tick (TIMR-02)'
        ).not.toBe( textBefore );
    } );

    /**
     * TIMR-03: wp-sudo-expiring CSS class added at 60 seconds remaining.
     *
     * Default session = 15 minutes = 900 seconds.
     * Advance 840 seconds (840_000ms) to reach exactly 60 seconds remaining.
     * Source: wp-sudo-admin-bar.js — if (r <= 60) n.classList.add('wp-sudo-expiring') (verified)
     */
    test( 'TIMR-03: wp-sudo-expiring class added when 60 seconds remain', async ( {
        page,
    } ) => {
        // Activate session FIRST with real clock, then install fake clock.
        await activateSudoSession( page );
        await page.clock.install();

        await page.goto( '/wp-admin/' );

        const node = page.locator( '#wp-admin-bar-wp-sudo-active' );
        await expect( node ).toBeVisible();

        // Verify no expiring class yet (session has ~900 seconds remaining).
        // Source: wp-sudo-admin-bar.js — class only added when r <= 60 (verified)
        await expect(
            node,
            'wp-sudo-expiring class must NOT be present at session start'
        ).not.toHaveClass( /wp-sudo-expiring/ );

        // Advance 840 seconds: 900s - 840s = 60s remaining → triggers expiring class.
        // runFor() fires each setInterval callback at each 1000ms step as time advances.
        // Source: Playwright clock API — runFor(ms) runs timer callbacks (verified)
        // NOTE: tick() does not exist in this Playwright version; runFor() is the equivalent.
        await page.clock.runFor( 840_000 );

        await expect(
            node,
            'wp-sudo-expiring class must be added when 60 or fewer seconds remain'
        ).toHaveClass( /wp-sudo-expiring/ );

        // Also verify the label still shows a valid time (should be ~0:60 → 1:00 or 0:59).
        const label = node.locator( '.ab-label' );
        await expect( label ).toHaveText( /^Sudo: \d+:\d{2}$/ );
    } );

    /**
     * TIMR-04: Page reloads when timer reaches zero.
     *
     * After reload, the sudo session is expired (both JS countdown reached zero AND
     * PHP session is invalidated). The admin bar timer node should be absent after reload.
     *
     * Source: wp-sudo-admin-bar.js — if (r <= 0) window.location.reload() (verified)
     *
     * PHP/JS clock separation: page.clock.runFor() advances ONLY browser JavaScript time.
     * PHP uses real wall clock (time()). To ensure the session is also expired PHP-side
     * when the page reloads, we use WP-CLI to zero out the _wp_sudo_expires user meta
     * before triggering the JS reload. This makes both JS and PHP agree: session expired.
     * Source: includes/class-sudo-session.php META_KEY = '_wp_sudo_expires' (verified)
     *
     * We runFor 910_000ms (910 seconds) to go well past the 900-second JS countdown.
     * page.waitForURL() is used here (not for AJAX) — window.location.reload()
     * is a synchronous JS call that triggers a standard page navigation. This is safe.
     */
    test( 'TIMR-04: page reloads when timer reaches zero and session has expired', async ( {
        page,
    } ) => {
        // Activate session FIRST with real clock — challenge page JS needs real timers
        // to process the AJAX auth flow. Then install fake clock BEFORE navigating to
        // the admin dashboard (where the admin-bar timer JS captures setInterval).
        await activateSudoSession( page );

        // Install fake clock AFTER session activation but BEFORE admin page load.
        // The admin-bar JS's setInterval will capture the fake clock.
        await page.clock.install();

        await page.goto( '/wp-admin/' );

        const node = page.locator( '#wp-admin-bar-wp-sudo-active' );
        await expect( node ).toBeVisible();

        // Expire the server-side PHP session before triggering the JS reload.
        // PHP uses real time() to check expiry; page.clock only affects browser JS.
        // Setting _wp_sudo_expires to 1 (distant past) makes PHP see session as expired.
        // Source: class-sudo-session.php — is_active() checks META_KEY against time() (verified)
        //
        // WP-CLI container: 'cli' targets the development site on port 8889 (same site
        // the browser tests use). 'tests-cli' targets the tests site on port 8890.
        // Source: wp-env.json — "port": 8889 is the development site (verified)
        execSync(
            'npx wp-env run cli wp user meta update 1 _wp_sudo_expires 1',
            { stdio: 'ignore' }
        );

        // Start listening for navigation before ticking past zero.
        // window.location.reload() is synchronous so waitForURL starts first.
        const reloadPromise = page.waitForURL( /wp-admin/, {
            waitUntil: 'load',
            timeout: 15_000,
        } );

        // Advance 910 seconds (past the 900-second default session).
        // runFor() fires each setInterval callback at each 1000ms step as time advances.
        // Source: wp-sudo-admin-bar.js — window.location.reload() fires when r <= 0 (verified)
        // NOTE: tick() does not exist in this Playwright version; runFor() is the equivalent.
        await page.clock.runFor( 910_000 );

        await reloadPromise;

        // After reload, both JS and PHP agree the session is expired.
        // PHP admin_bar_node() checks is_active() which checks _wp_sudo_expires — now past.
        // Source: class-admin-bar.php — admin_bar_node() only adds node when session active (verified)
        await expect(
            page.locator( '#wp-admin-bar-wp-sudo-active' ),
            'Timer node must be absent after session-expiry reload'
        ).not.toBeVisible();
    } );
} );
