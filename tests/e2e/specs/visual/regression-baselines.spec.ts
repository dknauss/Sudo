/**
 * Visual regression baseline tests — VISN-01, VISN-02, VISN-03, VISN-04
 *
 * Captures baseline screenshots of WP Sudo's key UI surfaces. On first run,
 * Playwright creates the baseline .png files. On subsequent runs, it diffs
 * the current screenshot against the baseline and fails if they differ beyond
 * the configured threshold.
 *
 * Snapshot files are stored alongside this spec file per snapshotPathTemplate:
 *   tests/e2e/specs/visual/__snapshots__/{name}-chromium.png
 *
 * Source: playwright.config.ts — snapshotPathTemplate (verified)
 *   '{testDir}/{testFileDir}/__snapshots__/{arg}-{projectName}{ext}'
 *
 * To update baselines after intentional UI changes:
 *   npx playwright test --config tests/e2e/playwright.config.ts \
 *     tests/e2e/specs/visual/regression-baselines.spec.ts --update-snapshots
 *
 * PITFALL (Pitfall 4): Any admin page visited with an active sudo session will show
 * the countdown timer in the admin bar. The timer text changes every second.
 * ALL visual snapshots on admin pages with an active session MUST either:
 *   a) Mask the #wp-admin-bar-wp-sudo-active element, OR
 *   b) Freeze time via page.clock.install() before navigation
 *
 * PITFALL (element-level screenshots of auto-sized elements):
 * The `li#wp-admin-bar-wp-sudo-active` element auto-sizes to its text content.
 * "Sudo: 15:00" and "Sudo: 14:53" produce different element widths → different
 * screenshot dimensions → Playwright rejects with "Expected 312px, received 315px".
 *
 * The admin bar timer reads its initial `r` value from `window.wpSudoAdminBar.remaining`,
 * which is PHP-computed as `expires - time()` at page render. The JS timer then
 * decrements `r` every second using setInterval. Since `remaining` is PHP's real time()
 * computation, it varies by the elapsed real seconds between session creation and page load.
 *
 * Solution for VISN-03/04: take a page-level screenshot clipped to a fixed bounding box
 * covering the WordPress admin bar (x:0, y:0, width:1280, height:32). This gives a
 * stable 1280x32 px baseline regardless of timer text or element width. Mask the
 * timer label text within the clip so pixel-level text differences don't cause failures.
 * The background color (green/red) and layout are stable and correctly captured.
 *
 * Source: admin-bar-timer.spec.ts TIMR-02/03/04 — clock pattern validated (verified)
 *   1. activateSudoSession(page)   — real timers for AJAX challenge flow
 *   2. page.clock.install()        — freeze setInterval before admin page load
 *   3. page.goto('/wp-admin/')     — setInterval runs under frozen clock
 *   4. [VISN-04 only] page.clock.runFor(840_000) — advance to expiring state
 *   5. page screenshot with clip + mask for stable baseline
 *
 * PITFALL (platform differences): Snapshot pixel comparison can differ between macOS
 * (local) and Linux Docker (CI). The threshold values below are set to accommodate
 * font rendering differences. Baselines should be generated from whichever environment
 * will be used as the canonical comparison baseline (CI is recommended for consistency).
 *
 * Thresholds (source: 07-RESEARCH.md recommendations verified):
 *   - Challenge card (stable element):  threshold: 0.05 (5%)
 *   - Settings form (stable element):   threshold: 0.05 (5%)
 *   - Admin bar nodes (text-heavy):     threshold: 0.1  (10%)
 */
import { test, expect, activateSudoSession } from '../../fixtures/test';
import type { Page } from '@playwright/test';

/** Locator for the admin bar timer node. */
const adminBarTimerSelector = '#wp-admin-bar-wp-sudo-active';

test.describe( 'Visual regression baselines', () => {
    /**
     * VISN-01: Challenge page card element.
     *
     * Navigate to the challenge page in session-only mode (no stash_key).
     * There is no active session here — the challenge card is static content.
     *
     * Source: class-challenge.php render_page() — #wp-sudo-challenge-card (verified)
     * Source: admin/css/wp-sudo-challenge.css — card styles (verified)
     *
     * No masking needed — no countdown timer on the challenge page itself.
     * The card does not contain any dynamic content (no timestamps, no user data).
     */
    test( 'VISN-01: challenge page card element baseline', async ( {
        page,
    } ) => {
        await page.goto( '/wp-admin/admin.php?page=wp-sudo-challenge' );

        // Wait for challenge JS to initialise before screenshotting.
        // Source: admin/js/wp-sudo-challenge.js — wpSudoChallenge config object (verified)
        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );

        // Clip the snapshot to the challenge card element only.
        // Source: class-challenge.php — id="wp-sudo-challenge-card" (verified)
        const card = page.locator( '#wp-sudo-challenge-card' );
        await expect( card ).toBeVisible();

        await expect( card ).toHaveScreenshot( 'challenge-card.png', {
            threshold: 0.05,
            // maxDiffPixels not set — use threshold percentage for element clips
        } );
    } );

    /**
     * VISN-02: Settings page form element.
     *
     * Navigate to the WP Sudo settings page without an active session.
     * The form contains the session duration input and the MU-plugin status section.
     *
     * Dynamic elements masked:
     * - #wp-sudo-mu-status: shows "Installed" or "Not installed" depending on state.
     *   Mask it to keep the baseline stable regardless of MU-plugin state.
     *
     * Source: class-admin.php render_settings_page() — .wrap element (verified)
     * Source: class-admin.php render_mu_plugin_status() — #wp-sudo-mu-status (verified)
     */
    test( 'VISN-02: settings page form element baseline', async ( { page } ) => {
        // Navigate without an active session — avoids admin bar timer.
        await page.goto(
            '/wp-admin/options-general.php?page=wp-sudo-settings'
        );

        // Wait for the settings form to be fully rendered.
        await expect( page.locator( '.wrap' ) ).toBeVisible();

        // Mask the MU-plugin status section — it changes between installed/not-installed.
        // Source: class-admin.php render_mu_plugin_status() — #wp-sudo-mu-status (verified)
        const muStatus = page.locator( '#wp-sudo-mu-status' );

        await expect( page.locator( '.wrap' ) ).toHaveScreenshot(
            'settings-form.png',
            {
                threshold: 0.05,
                mask: [ muStatus ],
            }
        );
    } );

    /**
     * VISN-03: Admin bar in active session state.
     *
     * Activate a sudo session and navigate to the admin dashboard.
     * Take a page-level screenshot clipped to the WordPress admin bar region
     * (x:0, y:0, width:1280, height:32) with the timer text masked.
     *
     * WHY a page clip rather than an element screenshot:
     * The `li#wp-sudo-active` element auto-sizes to its text content, and the timer
     * text varies by a few seconds each run (PHP computes remaining = expires - time()
     * at page render). Element-level screenshots have variable width → Playwright
     * fails with "Expected 312px, received 315px". A fixed 1280x32 clip of the admin
     * bar region is always the same dimensions regardless of timer text width.
     *
     * Timer text is masked to eliminate pixel-level text diffs — what we're testing
     * is the presence and background color of the WP Sudo node (green = active).
     *
     * Source: class-admin-bar.php — node id 'wp-sudo-active' (verified)
     * Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-active background: #2e7d32 (green) (verified)
     * Source: viewport 1280x900 → admin bar clip: x:0,y:0,w:1280,h:32 (verified from config)
     *
     * CRITICAL (clock ordering): activateSudoSession() FIRST (real timers for AJAX),
     * then page.clock.install() BEFORE goto('/wp-admin/') so setInterval runs under
     * the fake clock (prevents real-time countdown during screenshot assertion).
     */
    test( 'VISN-03: admin bar node in active session state baseline', async ( {
        page,
    } ) => {
        // Step 1: Activate session with real timers (AJAX challenge flow requires real clock).
        await activateSudoSession( page );

        // Step 2: Freeze clock AFTER session activation but BEFORE admin page load.
        // Prevents setInterval from firing during the screenshot assertion window.
        await page.clock.install();

        // Step 3: Navigate to admin dashboard — setInterval runs under frozen clock.
        await page.goto( '/wp-admin/' );

        // Source: class-admin-bar.php — li#wp-admin-bar-wp-sudo-active (verified)
        const timerNode = page.locator( adminBarTimerSelector );
        await expect( timerNode ).toBeVisible();

        // Verify the node has the active class (green background).
        // Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-active selector (verified)
        await expect( timerNode ).toHaveClass( /wp-sudo-active/ );

        // Snapshot the full admin bar (fixed 1280x32 clip) with timer text masked.
        // Clip dimensions: width=1280 (viewport), height=32 (WP admin bar standard height).
        // Mask the .ab-label (timer text) within the timer node to eliminate text-diff noise.
        // threshold: 0.1 — tolerate sub-pixel antialiasing differences.
        // maxDiffPixels: 200 — tolerate timer-node width variation at mask boundary.
        //   The .ab-label mask bounding box shifts slightly as timer text width changes
        //   (e.g. "Sudo: 14:59" vs "Sudo: 14:58" differ by a few pixels in rendered width).
        //   This leaves a handful of edge pixels outside the mask that vary between runs.
        //   200px is above the observed max drift (64px) and well below any real regression.
        // This baseline primarily asserts: WP Sudo node is visible with green background.
        await expect( page ).toHaveScreenshot(
            'admin-bar-active.png',
            {
                clip: { x: 0, y: 0, width: 1280, height: 32 },
                mask: [ timerNode.locator( '.ab-label' ) ],
                threshold: 0.1,
                maxDiffPixels: 200,
            }
        );
    } );

    /**
     * VISN-04: Admin bar in expiring state (wp-sudo-expiring class active).
     *
     * Activate a session, freeze time, tick 840 seconds to reach <= 60s remaining,
     * then take a page-level screenshot clipped to the admin bar region.
     *
     * At 60s remaining, the JS adds `wp-sudo-expiring` class to the li node, which
     * triggers the CSS background change from green (#2e7d32) to red (#c62828).
     *
     * WHY page clip: same reason as VISN-03 — element auto-sizes to text content.
     * After ticking 840s, the timer shows some value around "0:XX" depending on the
     * initial remaining time, causing width variation. Fixed clip avoids this.
     *
     * Source: admin/js/wp-sudo-admin-bar.js — if (r <= 60) n.classList.add('wp-sudo-expiring') (verified)
     * Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-expiring background: #c62828 (red) (verified)
     *
     * CRITICAL (clock ordering): activateSudoSession() FIRST, then page.clock.install()
     * BEFORE goto('/wp-admin/'). Use runFor() (not tick()) — Playwright 1.58.2 has no tick().
     *
     * Source: admin-bar-timer.spec.ts TIMR-03 — runFor(840_000) validated (verified)
     */
    test( 'VISN-04: admin bar node in expiring state baseline', async ( {
        page,
    } ) => {
        // Step 1: Activate session with real timers.
        await activateSudoSession( page );

        // Step 2: Freeze clock AFTER session activation but BEFORE admin page load.
        await page.clock.install();

        // Step 3: Navigate to admin dashboard.
        await page.goto( '/wp-admin/' );

        const timerNode = page.locator( adminBarTimerSelector );
        await expect( timerNode ).toBeVisible();

        // Advance 840 seconds: if session started with >= 900s, this reaches <= 60s remaining.
        // Use runFor() (not tick()) — Playwright 1.58.2 has no tick() method.
        // Source: wp-sudo-admin-bar.js — expiring threshold at r <= 60 (verified)
        // Source: admin-bar-timer.spec.ts TIMR-03 — runFor(840_000) validated (verified)
        await page.clock.runFor( 840_000 );

        // Verify expiring class is applied.
        await expect(
            timerNode,
            'wp-sudo-expiring class must be present after ticking to 60s remaining'
        ).toHaveClass( /wp-sudo-expiring/ );

        // Snapshot the full admin bar (fixed 1280x32 clip) with timer text masked.
        // This baseline primarily asserts: WP Sudo node has red background (expiring state).
        // Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-expiring background: #c62828 (verified)
        // maxDiffPixels: 200 — tolerate timer-node width variation at mask boundary (same
        // rationale as VISN-03: .ab-label mask bounding box shifts slightly between runs).
        await expect( page ).toHaveScreenshot(
            'admin-bar-expiring.png',
            {
                clip: { x: 0, y: 0, width: 1280, height: 32 },
                mask: [ timerNode.locator( '.ab-label' ) ],
                threshold: 0.1,
                maxDiffPixels: 200,
            }
        );
    } );
} );
