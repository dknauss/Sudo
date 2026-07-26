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
 *   b) Pin the label via a clone swap (freezeAdminBarTimer below) — never by
 *      stopping timers, see ROOT CAUSE below
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
 * ROOT CAUSE of #341 — measured, not inferred: **after the challenge interaction, the
 * page that performed it permanently stops producing compositor frames.**
 * requestAnimationFrame never fires on it again — not after `reload()`, not after
 * `bringToFront()`. `toHaveScreenshot` waits for a DOUBLE rAF before every capture, so on
 * that page it can only ever TIME OUT (5000ms, "waiting for element to be stable"). It is
 * never a pixel diff, which is why it survived `--update-snapshots`.
 *
 * Source (verified against the installed package, playwright-core 1.61.1):
 * `lib/coreBundle.js` — `Frame.rafrafTimeout()` awaits
 * `new Promise(x => requestAnimationFrame(() => requestAnimationFrame(x)))`; the
 * toHaveScreenshot path reaches it via `rafrafScreenshot` →
 * `rafrafTimeoutScreenshotElementWithProgress` → `rafrafTimeout`.
 *
 * Evidence (probe counting rAF callbacks in 1s, on one page across the flow):
 *   settings page, no session ....... 65/s      challenge page loaded ..... 71/s
 *   immediately after activation .....  0/s     after navigate + reload ...  0/s
 *   plugin admin-bar script blocked ..  0/s  -> not a WP Sudo bug
 *   FRESH page, same context+session . 66/s  -> the remedy
 *
 * So: activate the session on one page, take the baseline on a NEW page (freshAdminPage).
 *
 * Three earlier hypotheses were each falsified against CI before this was measured;
 * recorded so they are not re-tried: (1) "page.clock.install() freezes rAF" — removing it
 * changed nothing; (2) "the page-level clip is unstable" — an element screenshot changed
 * nothing, though its call log printed the decisive "waiting for element to be stable"
 * line; (3) "the dashboard's widgets keep reflowing" — a quiet admin screen changed
 * nothing. The element screenshot and the quiet screen are both KEPT (better isolation,
 * less churn), but neither was the fix.
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
 * Replaces the `.ab-label` with a clone carrying fixed text, so the auto-sizing node
 * keeps identical dimensions between runs. The countdown's interval is left running: it
 * holds a reference to the original label element, which is now detached, so its writes
 * are invisible.
 *
 * Crucially this touches NO timers. Both `page.clock.install()` and brute-force
 * `clearInterval` over the id range starve the page's timers (setTimeout and setInterval
 * share one id space), which starves requestAnimationFrame — and Playwright's screenshot
 * stability wait then never settles. That is the #341 hang; see the header.
 *
 * For the expiring baseline, apply `wp-sudo-expiring` directly rather than ticking to
 * `r <= 60` (wp-sudo-admin-bar.js adds it at that threshold, verified).
 */
async function freezeAdminBarTimer(
	page: Page,
	{ expiring = false }: { expiring?: boolean } = {}
): Promise< void > {
	await page.evaluate( ( isExpiring ) => {
		const node = document.getElementById( 'wp-admin-bar-wp-sudo-active' );
		if ( ! node ) {
			return;
		}
		if ( isExpiring ) {
			node.classList.add( 'wp-sudo-expiring' );
		}
		const label = node.querySelector( '.ab-label' );
		if ( label ) {
			// Swap in a clone and leave the countdown's timer alone. wp-sudo-admin-bar.js
			// holds a reference to the ORIGINAL .ab-label, so it keeps writing to the
			// detached node every second while the visible clone stays fixed.
			//
			// Do NOT stop the countdown by clearing timer ids: setTimeout and setInterval
			// share one id space, so clearing the range kills every pending timer on the
			// page. That starves requestAnimationFrame-driven work, and Playwright's
			// screenshot stability wait never settles — the exact hang in #341.
			const frozen = label.cloneNode( true ) as HTMLElement;
			frozen.textContent = isExpiring ? 'Sudo: 0:42' : 'Sudo: 15:00';
			label.replaceWith( frozen );
		}
	}, expiring );
}

/**
 * Open a FRESH page for the admin-bar baselines.
 *
 * #341: after the challenge interaction, the page that performed it stops producing
 * compositor frames — requestAnimationFrame never fires again on it, even after
 * `reload()` or `bringToFront()`. Playwright's screenshot stability wait polls rAF, so
 * `toHaveScreenshot` on that page can only ever time out. Measured, not inferred:
 * rafCount was 65-71/s before activation and exactly 0 after, on the same page; a new
 * page in the SAME context with the SAME active session reports 66/s. Blocking the
 * plugin's admin-bar script changes nothing, so this is a browser/page-state issue,
 * not a WP Sudo bug.
 */
async function freshAdminPage( page: Page, url: string ): Promise< Page > {
	const fresh = await page.context().newPage();
	await fresh.goto( url );
	return fresh;
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
     * dimension-stable, and an element screenshot is better isolated. Neither change
     * fixed #341 — taking the shot on a FRESH page did; see the header.
     *
     * Timer text is masked to keep pixel-level text rendering out of the diff — what we
     * test is the presence and background color of the WP Sudo node (green = active).
     *
     * Source: class-admin-bar.php — node id 'wp-sudo-active' (verified)
     * Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-active background: #2e7d32 (green) (verified)
     * Source: class-admin-bar.php — li#wp-admin-bar-wp-sudo-active is the element target (verified)
     *
     * Stability: freezeAdminBarTimer() makes the node dimension-stable; taking the shot
     * on a FRESH page is what keeps Playwright's stability wait from timing out (#341).
     */
    test( 'VISN-03: admin bar node in active session state baseline', async ( {
        page,
    } ) => {
        // Activate the session on `page`, then take the baseline on a FRESH page:
        // the activating page stops producing frames, so any screenshot on it times out
        // (#341 — see freshAdminPage). A quiet admin screen also avoids dashboard churn.
        await activateSudoSession( page );
        const shot = await freshAdminPage(
            page,
            '/wp-admin/options-general.php?page=wp-sudo-settings'
        );

        // Source: class-admin-bar.php — li#wp-admin-bar-wp-sudo-active (verified)
        const timerNode = shot.locator( adminBarTimerSelector );
        await expect( timerNode ).toBeVisible();

        // Source: admin/css/wp-sudo-admin-bar.css — .wp-sudo-active selector (verified)
        await expect( timerNode ).toHaveClass( /wp-sudo-active/ );

        // Freeze the countdown so the node keeps identical dimensions between runs.
        await freezeAdminBarTimer( shot );

        // Element screenshot of the frozen Sudo node (not a page clip): better isolated,
        // and freezeAdminBarTimer() pins the label so the node no longer auto-sizes —
        // removing the original reason a page clip was used (#341).
        // Mask the .ab-label (timer text) so any text rendering stays out of the diff.
        // threshold 0.1 — tolerate sub-pixel antialiasing.
        // This baseline primarily asserts: WP Sudo node is visible with green background.
        await expect( timerNode ).toHaveScreenshot(
            'admin-bar-active.png',
            {
                mask: [ timerNode.locator( '.ab-label' ) ],
                threshold: 0.1,
                // Element snapshots are ~112x32 and ~105x32; the lock glyph is only
                // ~170 non-background pixels, so a 200px budget could hide a whole-glyph
                // regression. 20px (~0.6% of the image) still absorbs antialiasing.
                maxDiffPixels: 20,
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
        // Activate on `page`, screenshot on a FRESH page — see VISN-03 / #341.
        await activateSudoSession( page );
        const shot = await freshAdminPage(
            page,
            '/wp-admin/options-general.php?page=wp-sudo-settings'
        );

        const timerNode = shot.locator( adminBarTimerSelector );
        await expect( timerNode ).toBeVisible();

        // Force the expiring state directly and freeze the countdown. The JS adds
        // wp-sudo-expiring at r <= 60 (wp-sudo-admin-bar.js, verified);
        // freezeAdminBarTimer applies it deterministically without clock bookkeeping.
        await freezeAdminBarTimer( shot, { expiring: true } );

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
                // Element snapshots are ~112x32 and ~105x32; the lock glyph is only
                // ~170 non-background pixels, so a 200px budget could hide a whole-glyph
                // regression. 20px (~0.6% of the image) still absorbs antialiasing.
                maxDiffPixels: 20,
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
