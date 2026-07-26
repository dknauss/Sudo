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
 *   '{testDir}/{testFileDir}/__snapshots__/{arg}-chromium{ext}'
 *
 * To update baselines after intentional UI changes:
 *   npx playwright test --config tests/e2e/playwright.config.ts \
 *     tests/e2e/specs/visual/regression-baselines.spec.ts --update-snapshots
 *
 * PITFALL (Pitfall 4): Any admin page visited with an active sudo session will show
 * the countdown timer in the admin bar. The timer text changes every second.
 * ALL visual snapshots on admin pages with an active session MUST either:
 *   a) Mask the #wp-admin-bar-wp-sudo-active element, OR
 *   b) Stop the countdown interval + pin the label (freezeAdminBarTimer below)
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
 * Approach for VISN-03/04 (issue #341 — three attempts, only the third was the cause):
 *   1. activateSudoSession(page)  — real timers; the AJAX challenge flow needs them
 *   2. goto a QUIET admin screen  — NOT /wp-admin/. This was the actual bug: the
 *      dashboard's welcome-panel images and "Events and News" widget keep reflowing
 *      layout after the network settles, so Playwright's "waiting for element to be
 *      stable" step never completes and toHaveScreenshot times out (5000ms) instead of
 *      reporting a pixel diff. The admin bar renders on every admin screen, and VISN-02
 *      already proves the settings screen is stable.
 *   3. freezeAdminBarTimer(page[, {expiring:true}]) — stop the countdown, pin the label
 *      to a fixed-width string, and (VISN-04) apply wp-sudo-expiring directly
 *   4. element screenshot of the frozen node + mask for a stable baseline
 *
 * Two hypotheses were tested against CI and FALSIFIED before the page-stability cause
 * was found; both are recorded here so they are not re-tried:
 *   - "page.clock.install() freezes rAF, which the stability check polls on." The trace
 *     disproved it: with the clock left live and the countdown frozen, the DOM showed a
 *     frozen "Sudo: 15:00" and the hang was byte-identical. clock.install() is still
 *     avoided (it makes runFor() the only way to advance, for no benefit here), but it
 *     was never the cause.
 *   - "the page-level clip is the problem." Switching to an element screenshot changed
 *     nothing — though it did surface the decisive call-log line ("attempting scroll into
 *     view action / waiting for element to be stable") that page screenshots never print.
 *     The element screenshot is kept because it is better isolated, not because it fixed
 *     anything on its own.
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

/**
 * Make the admin-bar countdown node static for a stable screenshot WITHOUT freezing
 * the page clock.
 *
 * Stops the countdown interval — its id is closure-private in `wp-sudo-admin-bar.js`,
 * so clear the whole live range — and pins the `.ab-label` to a fixed-width string, so
 * the auto-sizing node keeps identical dimensions between runs.
 *
 * This is what makes the NODE dimension-stable. It is not what fixed #341's timeout;
 * that was the reflowing dashboard page (see the header). `page.clock.install()` is
 * avoided because freezing the clock forces runFor() bookkeeping for no gain here.
 *
 * For the expiring baseline, apply `wp-sudo-expiring` directly rather than ticking to
 * `r <= 60` (wp-sudo-admin-bar.js adds it at that threshold, verified).
 */
async function freezeAdminBarTimer(
	page: Page,
	{ expiring = false }: { expiring?: boolean } = {}
): Promise< void > {
	await page.evaluate( ( isExpiring ) => {
		// The countdown's intervalId is private to the IIFE in wp-sudo-admin-bar.js,
		// so clear the full live range to guarantee it stops.
		const maxId = setInterval( () => {}, 1_000_000 ) as unknown as number;
		for ( let id = 1; id <= maxId; id++ ) {
			clearInterval( id );
		}

		const node = document.getElementById( 'wp-admin-bar-wp-sudo-active' );
		if ( ! node ) {
			return;
		}
		if ( isExpiring ) {
			node.classList.add( 'wp-sudo-expiring' );
		}
		const label = node.querySelector( '.ab-label' );
		if ( label ) {
			// Fixed text → deterministic node width → stable pixels outside the mask.
			label.textContent = isExpiring ? 'Sudo: 0:42' : 'Sudo: 15:00';
		}
	}, expiring );
}

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
     * Activate a sudo session and navigate to a quiet admin screen (not the dashboard).
     * Take an element screenshot of the frozen Sudo node with the timer text masked.
     *
     * WHY an element screenshot (like every other passing baseline in this file):
     * the `li#wp-sudo-active` element auto-sizes to its timer text, which is why a page
     * clip was tried first (element widths varied → "Expected 312px, received 315px").
     * But freezeAdminBarTimer() now pins the label to a fixed width, so the node is
     * dimension-stable. The page clip was itself the bug: it captured the whole busy
     * dashboard admin bar, which never settled for toHaveScreenshot's stability check
     * (#341). An element screenshot of the frozen node is stable and isolated.
     *
     * Timer text is masked to keep pixel-level text rendering out of the diff — what we
     * test is the presence and background color of the WP Sudo node (green = active).
     *
     * Source: class-admin-bar.php — node id 'wp-sudo-active' (verified)
     * Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-active background: #2e7d32 (green) (verified)
     * Source: class-admin-bar.php — li#wp-admin-bar-wp-sudo-active is the element target (verified)
     *
     * Stability: freezeAdminBarTimer() makes the node dimension-stable; taking the shot
     * on a quiet admin screen (not the reflowing dashboard) is what keeps Playwright's
     * element-stability wait from timing out — see the header for #341's full history.
     */
    test( 'VISN-03: admin bar node in active session state baseline', async ( {
        page,
    } ) => {
        // Activate the session (real timers — the AJAX challenge flow needs a real clock).
        await activateSudoSession( page );
        // NOT the dashboard (/wp-admin/): its welcome-panel images and the "Events and
        // News" widget keep reflowing the page, so Playwright's "waiting for element to
        // be stable" step never settles and the screenshot times out (#341). The admin
        // bar — and the Sudo node — render on every admin screen; this one is quiet and
        // is already proven stable by VISN-02.
        await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );

        // Source: class-admin-bar.php — li#wp-admin-bar-wp-sudo-active (verified)
        const timerNode = page.locator( adminBarTimerSelector );
        await expect( timerNode ).toBeVisible();

        // Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-active selector (verified)
        await expect( timerNode ).toHaveClass( /wp-sudo-active/ );

        // Freeze the countdown so the node keeps identical dimensions between runs.
        await freezeAdminBarTimer( page );

        // Element screenshot of the frozen Sudo node (not a page clip): better isolated,
        // and freezeAdminBarTimer() pins the label so the node no longer auto-sizes —
        // removing the original reason a page clip was used (#341).
        // Mask the .ab-label (timer text) so any text rendering stays out of the diff.
        // threshold 0.1 / maxDiffPixels 200 — tolerate sub-pixel antialiasing.
        // This baseline primarily asserts: WP Sudo node is visible with green background.
        await expect( timerNode ).toHaveScreenshot(
            'admin-bar-active.png',
            {
                mask: [ timerNode.locator( '.ab-label' ) ],
                threshold: 0.1,
                maxDiffPixels: 200,
            }
        );
    } );

    /**
     * VISN-04: Admin bar in expiring state (wp-sudo-expiring class active).
     *
     * Activate a session, force the wp-sudo-expiring state directly, then take an
     * element screenshot of the frozen Sudo node.
     *
     * At 60s remaining the JS adds `wp-sudo-expiring` to the li node, which triggers the
     * CSS background change from green (#2e7d32) to red (#c62828); we apply that class
     * directly rather than ticking down to it.
     *
     * WHY an element screenshot: same as VISN-03 — freezeAdminBarTimer() pins the label
     * to a fixed width so the node is dimension-stable, and an element screenshot of the
     * frozen node avoids the page-clip instability on the busy dashboard (#341).
     *
     * Source: admin/js/wp-sudo-admin-bar.js — if (r <= 60) n.classList.add('wp-sudo-expiring') (verified)
     * Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-expiring background: #c62828 (red) (verified)
     *
     * Stability: freezeAdminBarTimer(page, { expiring: true }) applies the class and stops
     * the countdown; the quiet admin screen keeps the element-stability wait settling (#341).
     */
    test( 'VISN-04: admin bar node in expiring state baseline', async ( {
        page,
    } ) => {
        // Activate the session (real timers for the AJAX challenge flow).
        await activateSudoSession( page );
        // NOT the dashboard (/wp-admin/): its welcome-panel images and the "Events and
        // News" widget keep reflowing the page, so Playwright's "waiting for element to
        // be stable" step never settles and the screenshot times out (#341). The admin
        // bar — and the Sudo node — render on every admin screen; this one is quiet and
        // is already proven stable by VISN-02.
        await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );

        const timerNode = page.locator( adminBarTimerSelector );
        await expect( timerNode ).toBeVisible();

        // Force the expiring state directly and freeze the countdown. The JS adds
        // wp-sudo-expiring at r <= 60 (wp-sudo-admin-bar.js, verified);
        // freezeAdminBarTimer applies it deterministically without clock bookkeeping.
        await freezeAdminBarTimer( page, { expiring: true } );

        // Verify expiring class is applied.
        await expect(
            timerNode,
            'wp-sudo-expiring class must be present for the expiring-state baseline'
        ).toHaveClass( /wp-sudo-expiring/ );

        // Element screenshot of the frozen Sudo node (see VISN-03 / #341).
        // Asserts: WP Sudo node has the red expiring background.
        // Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-expiring background: #c62828 (verified)
        await expect( timerNode ).toHaveScreenshot(
            'admin-bar-expiring.png',
            {
                mask: [ timerNode.locator( '.ab-label' ) ],
                threshold: 0.1,
                maxDiffPixels: 200,
            }
        );
    } );

    /**
     * VISN-05 / VISN-06 / VISN-07: in-editor pinned header padlock (#288).
     *
     * The full-screen editor hides the admin bar, so VISN-03/04 above cover a surface
     * the editing user never sees. These three baselines pin the editor's equivalent —
     * one per state, with the sidebar panel CLOSED:
     *
     *   VISN-07 inactive   dashicons-lock      stock button, no chip
     *   VISN-05 active     dashicons-unlock    stock button, no chip
     *   VISN-06 expiring   dashicons-warning   red #c62828 chip
     *
     * Three baselines rather than two because the vocabulary is now carried by SHAPE,
     * with colour reinforcing only the urgent state. That claim is only meaningful if
     * each state is pinned as pixels: a regression that silently reverted two of the
     * three glyphs to one shape would leave colour as the sole differentiator again,
     * and no assertion about class names would catch how it actually renders.
     *
     * WHY an element screenshot is safe here (unlike VISN-03/04): the admin-bar node
     * auto-sizes to its "Sudo: M:SS" text, so its width drifts run to run. This button
     * carries NO text — #288 deliberately omits a compact M:SS from the header, exactly
     * so the header neither churns per second nor varies in width. It is a fixed 32x32
     * icon button, which makes the snapshot dimension-stable with no clip and no mask.
     * Clipping to the button alone also isolates it from unrelated header churn (the
     * neighbouring Settings button's is-pressed state, Publish-button label changes).
     *
     * WHERE THESE PNGs COME FROM: the Linux runner in .github/workflows/e2e-visual.yml,
     * which is the only environment that ever asserts them (that workflow is nightly +
     * workflow_dispatch, never on a PR). Regenerating them on macOS looks fine locally
     * and then fails CI on antialiasing alone — observed at 5, 18 and 71 differing
     * pixels on a 33x32 button, which is enough because `threshold` is a PER-PIXEL
     * colour tolerance, not a pixel-count budget: any differing pixel fails. To update
     * them, dispatch the workflow and take the `-actual.png` files from its
     * `playwright-visual-report` artifact. Expect these three to fail a local macOS run.
     *
     * Source: admin/css/wp-sudo-editor-indicator.css — the single expiring rule (verified)
     * Source: admin/js/wp-sudo-session-indicator.js — sessionState(), the IndicatorPanel
     *         `icon` selection, the body-class toggle, EXPIRING_THRESHOLD
     * Source: live WP 7.0 editor DOM — button[aria-controls="…"] inside .interface-pinned-items
     */
    const pinnedButtonSelector =
        '.interface-pinned-items button[aria-controls="wp-sudo-session-indicator:wp-sudo-session-indicator"]';

    /** Open the post editor and settle it (mirrors editor-session-indicator.spec.ts). */
    async function openEditor( page: Page ): Promise< void > {
        await page.goto( '/wp-admin/post-new.php' );
        await page.waitForFunction(
            () =>
                !! ( window as any ).wp?.apiFetch &&
                !! ( window as any ).wp?.data?.select?.( 'core/notices' ),
            undefined,
            { timeout: 30_000 }
        );
        await page.evaluate( () => {
            const prefs = ( window as any ).wp?.data?.dispatch?.( 'core/preferences' );
            prefs?.set?.( 'core/edit-post', 'welcomeGuide', false );
            prefs?.set?.( 'core', 'welcomeGuide', false );
        } );
        await page
            .locator( '.components-modal__screen-overlay' )
            .waitFor( { state: 'detached', timeout: 10_000 } )
            .catch( () => {} );
    }

    /**
     * Drive the indicator to a chosen `remaining` via feed #2 (the grant CustomEvent).
     *
     * Deterministic by construction: the expiring state needs `remaining <= 60`, and the
     * settings floor for session duration is 1 minute, so waiting out a real session
     * would be both slow and racy. This is the same seeding INDICATOR-04 uses.
     */
    async function seedRemaining( page: Page, remaining: number ): Promise< void > {
        await page.evaluate( ( secs ) => {
            window.dispatchEvent(
                new CustomEvent( 'wp-sudo-session-granted', { detail: { remaining: secs } } )
            );
        }, remaining );
    }

    test( 'VISN-05: editor pinned padlock in active state baseline', async ( {
        page,
    } ) => {
        await openEditor( page );
        test.skip(
            ! ( await page.evaluate(
                () => !! ( window as any ).wp?.editor?.PluginSidebar
            ) ),
            'The pinned header button requires the unified PluginSidebar (WP 6.6+).'
        );

        await seedRemaining( page, 600 );

        const button = page.locator( pinnedButtonSelector );
        await expect( button ).toBeVisible();
        // Panel closed — the state must be legible without opening the sidebar.
        await expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
        // Stock button: colour is spent only on the final minute, not on the whole
        // session. Asserted before the snapshot so a reintroduced chip fails loudly
        // here rather than as an opaque pixel diff.
        await expect( button ).toHaveCSS( 'background-color', 'rgba(0, 0, 0, 0)' );

        await expect( button ).toHaveScreenshot( 'editor-indicator-active.png', {
            threshold: 0.05,
        } );
    } );

    test( 'VISN-07: editor pinned padlock in inactive state baseline', async ( {
        page,
    } ) => {
        await openEditor( page );
        test.skip(
            ! ( await page.evaluate(
                () => !! ( window as any ).wp?.editor?.PluginSidebar
            ) ),
            'The pinned header button requires the unified PluginSidebar (WP 6.6+).'
        );

        // No seeding — this is the at-rest state, which is what the header shows for
        // most of a session-less editing session, and therefore the one most worth
        // pinning: it must read CLOSED padlock, not open.
        const button = page.locator( pinnedButtonSelector );
        await expect( button ).toBeVisible();
        await expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
        await expect( button ).toHaveCSS( 'background-color', 'rgba(0, 0, 0, 0)' );

        await expect( button ).toHaveScreenshot( 'editor-indicator-inactive.png', {
            threshold: 0.05,
        } );
    } );

    test( 'VISN-06: editor pinned padlock in expiring state baseline', async ( {
        page,
    } ) => {
        await openEditor( page );
        test.skip(
            ! ( await page.evaluate(
                () => !! ( window as any ).wp?.editor?.PluginSidebar
            ) ),
            'The pinned header button requires the unified PluginSidebar (WP 6.6+).'
        );

        // 45 s — inside the admin bar's own `remaining <= 60` expiring threshold.
        await seedRemaining( page, 45 );

        const button = page.locator( pinnedButtonSelector );
        await expect( button ).toBeVisible();
        await expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
        await expect( button ).toHaveCSS( 'background-color', 'rgb(198, 40, 40)' );

        await expect( button ).toHaveScreenshot( 'editor-indicator-expiring.png', {
            threshold: 0.05,
        } );
    } );
} );
