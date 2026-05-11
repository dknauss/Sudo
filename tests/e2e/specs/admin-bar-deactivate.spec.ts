/**
 * Admin bar deactivation tests — ABAR-01, ABAR-02, ABAR-03
 *
 * Verify that clicking the admin bar timer node deactivates the sudo session
 * and that the URL is unchanged after the redirect completes.
 *
 * Tests:
 *   ABAR-01 — Clicking admin bar timer node deactivates the sudo session
 *             (wp_sudo_token cookie absent, timer node gone)
 *   ABAR-02 — URL does not change after admin bar deactivation click
 *             (same pathname, deactivation params stripped)
 *   ABAR-03 — Clicking the front-end admin bar timer deactivates the sudo session
 *             and returns to the same front-end path
 *
 * Deactivation flow (full page navigation, NOT AJAX):
 *   1. Clicking the timer node follows its href:
 *      /wp-admin/?wp_sudo_deactivate=1&wp_sudo_redirect_to=CURRENT_URL&_wpnonce=NONCE
 *   2. WordPress fires init
 *   3. handle_deactivate() runs at priority 5:
 *      - Verifies nonce
 *      - Calls Sudo_Session::deactivate($user_id) — removes token meta, expires cookie
 *      - Calls wp_safe_redirect() with the cleaned wp_sudo_redirect_to target
 *      - exits
 *   4. Browser follows 302 redirect back to same URL minus deactivation params
 *
 * Source: includes/class-admin-bar.php handle_deactivate() (verified)
 * Source: includes/class-admin-bar.php admin_bar_node() — href is wp_nonce_url with wp_sudo_deactivate=1 and wp_sudo_redirect_to (verified)
 * Source: includes/class-sudo-session.php deactivate() — expires wp_sudo_token cookie (verified)
 *
 * PITFALL (Pitfall D from 08-RESEARCH.md): This is a full page navigation, NOT AJAX.
 * Use Promise.all([waitForURL, click]) — do NOT use page.waitForResponse() or
 * page.waitForRequest() (there is no XHR). The redirect is a standard 302 GET.
 *
 * PITFALL (Pitfall E): No session = no admin bar node = cannot click it.
 * Always call activateSudoSession(page) in beforeEach and verify node is visible
 * before clicking (fail-fast guard).
 *
 * Admin bar selector (verified from class-admin-bar.php admin_bar_node()):
 *   li#wp-admin-bar-wp-sudo-active  — list item added by wp_admin_bar->add_node()
 *   with id 'wp-sudo-active' (WP prefixes 'wp-admin-bar-' to node IDs)
 *
 * Cookie verified via context.cookies() — Playwright API returns all cookies
 * for the current browser context including HttpOnly cookies.
 */
import type { Page } from '@playwright/test';
import { test, expect, activateSudoSession } from '../fixtures/test';

/**
 * Click the admin bar timer link and wait for the full deactivation redirect cycle.
 *
 * Why this helper exists:
 * - On admin pages, the starting URL and final redirected URL are both `/wp-admin/`
 * - A bare waitForURL(/wp-admin/) can therefore resolve immediately on the pre-click page
 * - To prove a real navigation occurred, we first wait for the document navigation request
 *   that includes `wp_sudo_deactivate=1` and `_wpnonce`, then confirm the browser lands
 *   back on the param-free original URL
 *
 * This follows the review feedback from 2026-04-19: split the assertion into two steps
 * so the tests cannot pass without observing the click-driven redirect cycle.
 */
async function clickTimerAndWaitForRedirect( page: Page ): Promise<{
    urlBefore: URL;
    urlAfter: URL;
}> {
    const urlBefore = new URL( page.url() );
    const timerLink = page.locator( '#wp-admin-bar-wp-sudo-active .ab-item' );

    await Promise.all( [
        page.waitForRequest( ( request ) => {
            if ( ! request.isNavigationRequest() ) {
                return false;
            }

            const requestUrl = new URL( request.url() );

            return requestUrl.searchParams.get( 'wp_sudo_deactivate' ) === '1' &&
                requestUrl.searchParams.get( 'wp_sudo_redirect_to' ) === urlBefore.href &&
                requestUrl.searchParams.has( '_wpnonce' );
        } ),
        timerLink.click(),
    ] );

    await page.waitForURL(
        ( url ) =>
            url.pathname === urlBefore.pathname &&
            ! url.searchParams.has( 'wp_sudo_deactivate' ) &&
            ! url.searchParams.has( 'wp_sudo_redirect_to' ) &&
            ! url.searchParams.has( '_wpnonce' ),
        { waitUntil: 'load', timeout: 10_000 }
    );

    return {
        urlBefore,
        urlAfter: new URL( page.url() ),
    };
}

test.describe( 'Admin bar deactivation', () => {

    /**
     * Activate a fresh sudo session and navigate to wp-admin/ before each test.
     *
     * Both ABAR-01 and ABAR-02 require:
     *   - An active session so the admin bar timer node is present
     *   - Starting on a known URL (/wp-admin/) for URL-before comparisons
     *
     * The explicit visibility assertion after goto() is a fail-fast guard: if
     * activateSudoSession() failed silently, the test fails here with a clear
     * message rather than failing later with a confusing "element not found" error.
     *
     * Source: class-admin-bar.php admin_bar_node() — node only present when session active (verified)
     */
    test.beforeEach( async ( { page } ) => {
        await activateSudoSession( page );
        await page.goto( '/wp-admin/' );
        // Fail fast if session is not active (guard against silent activateSudoSession failure).
        await expect(
            page.locator( '#wp-admin-bar-wp-sudo-active' ),
            'Admin bar timer node must be visible before test — activateSudoSession may have failed'
        ).toBeVisible();
    } );

    /**
     * ABAR-01: Clicking admin bar timer node deactivates sudo session.
     *
     * After clicking the node:
     *   - PHP removes the token meta and expires the cookie (Sudo_Session::deactivate())
     *   - PHP issues a 302 redirect back to the same page
     *   - The admin bar node is absent on the redirected page (session no longer active)
     *   - The wp_sudo_token cookie is absent from the browser context
     *
     * Source: class-admin-bar.php handle_deactivate() — verifies nonce, calls
     *   Sudo_Session::deactivate($user_id), then redirects to the cleaned redirect target (verified)
     * Source: class-sudo-session.php deactivate() — expires wp_sudo_token cookie with past expiry (verified)
     * Source: class-admin-bar.php admin_bar_node() — returns early if !Sudo_Session::is_active() (verified)
     *
     * PITFALL: The starting URL and final redirected URL are both /wp-admin/.
     * A bare waitForURL(/wp-admin/) can therefore resolve before the click-driven
     * navigation happens. This test uses a two-step wait:
     *   1. observe the document navigation request with wp_sudo_deactivate + _wpnonce
     *   2. confirm the browser lands back on the param-free final URL
     */
    test( 'ABAR-01: clicking admin bar timer node deactivates sudo session', async ( { page, context } ) => {
        // Locate the admin bar timer node (visible due to beforeEach guard above).
        // Source: class-admin-bar.php add_node() — id 'wp-sudo-active' (WP adds 'wp-admin-bar-' prefix) (verified)
        const timerNode = page.locator( '#wp-admin-bar-wp-sudo-active' );

        // Click the anchor inside the admin bar node and wait for the full redirect cycle.
        // We intentionally do not use waitForURL(/wp-admin/) directly because the final URL
        // equals the starting URL. See clickTimerAndWaitForRedirect() above.
        await clickTimerAndWaitForRedirect( page );

        // After deactivation + redirect, the admin bar node must be absent.
        // PHP admin_bar_node() only adds the node when Sudo_Session::is_active() returns true.
        // After Sudo_Session::deactivate(), is_active() returns false — node is not added.
        // Source: class-admin-bar.php admin_bar_node() — early return if !is_active() (verified)
        await expect(
            timerNode,
            'Admin bar timer node must be absent after session deactivation'
        ).not.toBeVisible();

        // wp_sudo_token cookie must be absent — Sudo_Session::deactivate() expires it.
        // context.cookies() returns all cookies including HttpOnly, so this is a direct check.
        // Source: class-sudo-session.php deactivate() — setcookie with past expiry (verified)
        const cookies = await context.cookies();
        const sudoCookie = cookies.find( ( c ) => c.name === 'wp_sudo_token' );
        expect(
            sudoCookie,
            'wp_sudo_token cookie must be absent after session deactivation'
        ).toBeUndefined();
    } );

    /**
     * ABAR-02: URL does not change after admin bar deactivation click.
     *
     * The admin bar click navigates to /wp-admin/?wp_sudo_deactivate=1
     * with wp_sudo_redirect_to set to the current URL. PHP cleans the redirect
     * target and redirects back.
     *
     * Starting from /wp-admin/:
     *   Before click: http://localhost:8889/wp-admin/
     *   Deactivation URL: http://localhost:8889/wp-admin/?wp_sudo_deactivate=1&wp_sudo_redirect_to=...
     *   After redirect: http://localhost:8889/wp-admin/  (same as before)
     *
     * Assertions:
     *   - pathname is the same (e.g., /wp-admin/)
     *   - wp_sudo_deactivate param is absent from final URL
     *   - wp_sudo_redirect_to param is absent from final URL
     *   - _wpnonce param is absent from final URL
     *
     * Source: class-admin-bar.php — DEACTIVATE_PARAM = 'wp_sudo_deactivate' (verified)
     * Source: class-admin-bar.php handle_deactivate() — wp_safe_redirect() to cleaned redirect target (verified)
     *
     * PITFALL: There IS a navigation (click → deactivation URL → 302 redirect → final URL).
     * Because the final URL equals the starting URL, the test must first observe the
     * deactivation request before asserting on the final param-free URL.
     */
    test( 'ABAR-02: URL does not change after admin bar deactivation click', async ( { page } ) => {
        // Capture both the starting URL and final redirected URL through the helper.
        const { urlBefore, urlAfter } = await clickTimerAndWaitForRedirect( page );

        // Pathname must be identical (same admin page, no redirect to a different section).
        expect(
            urlAfter.pathname,
            'URL pathname must be unchanged after admin bar deactivation click'
        ).toBe( urlBefore.pathname );

        // Deactivation params must be absent from the final URL (stripped by remove_query_arg).
        // Source: class-admin-bar.php — DEACTIVATE_PARAM = 'wp_sudo_deactivate' (verified)
        expect(
            urlAfter.searchParams.has( 'wp_sudo_deactivate' ),
            'wp_sudo_deactivate query param must be absent from URL after redirect'
        ).toBe( false );

        expect(
            urlAfter.searchParams.has( 'wp_sudo_redirect_to' ),
            'wp_sudo_redirect_to query param must be absent from URL after redirect'
        ).toBe( false );

        expect(
            urlAfter.searchParams.has( '_wpnonce' ),
            '_wpnonce query param must be absent from URL after redirect'
        ).toBe( false );
    } );

    /**
     * ABAR-03: Clicking the timer node from the front-end still deactivates sudo mode.
     *
     * This protects the Playground regression where the admin bar was visible on
     * the front-end, but the click only navigated without ending the sudo session.
     */
    test( 'ABAR-03: front-end admin bar click deactivates sudo session', async ( { page, context } ) => {
        await page.goto( '/' );
        await expect(
            page.locator( '#wp-admin-bar-wp-sudo-active' ),
            'Admin bar timer node must be visible on the front-end before deactivation'
        ).toBeVisible();

        const timerNode = page.locator( '#wp-admin-bar-wp-sudo-active' );
        const { urlBefore, urlAfter } = await clickTimerAndWaitForRedirect( page );

        expect(
            urlAfter.pathname,
            'Front-end path must be preserved after admin bar deactivation'
        ).toBe( urlBefore.pathname );

        await expect(
            timerNode,
            'Admin bar timer node must disappear on the front-end after session deactivation'
        ).not.toBeVisible();

        const cookies = await context.cookies();
        const sudoCookie = cookies.find( ( c ) => c.name === 'wp_sudo_token' );
        expect(
            sudoCookie,
            'wp_sudo_token cookie must be absent after front-end session deactivation'
        ).toBeUndefined();
    } );

} );
