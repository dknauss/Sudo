/**
 * Admin bar deactivation tests — ABAR-01, ABAR-02
 *
 * Verify that clicking the admin bar timer node deactivates the sudo session
 * and that the URL is unchanged after the redirect completes.
 *
 * Tests:
 *   ABAR-01 — Clicking admin bar timer node deactivates the sudo session
 *             (wp_sudo_token cookie absent, timer node gone)
 *   ABAR-02 — URL does not change after admin bar deactivation click
 *             (same pathname, deactivation params stripped)
 *
 * Deactivation flow (full page navigation, NOT AJAX):
 *   1. Clicking the timer node follows its href:
 *      current-page-url?wp_sudo_deactivate=1&_wpnonce=NONCE
 *   2. WordPress fires admin_init
 *   3. handle_deactivate() runs at priority 5:
 *      - Verifies nonce
 *      - Calls Sudo_Session::deactivate($user_id) — removes token meta, expires cookie
 *      - Calls wp_safe_redirect(remove_query_arg(['wp_sudo_deactivate', '_wpnonce']))
 *      - exit
 *   4. Browser follows 302 redirect back to same URL minus deactivation params
 *
 * Source: includes/class-admin-bar.php handle_deactivate() (verified)
 * Source: includes/class-admin-bar.php admin_bar_node() — href is wp_nonce_url with wp_sudo_deactivate=1 (verified)
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
import { test, expect, activateSudoSession } from '../fixtures/test';

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
     *   Sudo_Session::deactivate($user_id), then wp_safe_redirect(remove_query_arg([...])) (verified)
     * Source: class-sudo-session.php deactivate() — expires wp_sudo_token cookie with past expiry (verified)
     * Source: class-admin-bar.php admin_bar_node() — returns early if !Sudo_Session::is_active() (verified)
     *
     * PITFALL: This is a full-page navigation (not AJAX). The timerNode.click() follows
     * the href (a real anchor href containing the deactivation URL). Use
     * Promise.all([waitForURL, click]) to wait for the redirect to complete before
     * asserting the session state. Never use page.waitForResponse() or XHR patterns.
     */
    test( 'ABAR-01: clicking admin bar timer node deactivates sudo session', async ( { page, context } ) => {
        // Locate the admin bar timer node (visible due to beforeEach guard above).
        // Source: class-admin-bar.php add_node() — id 'wp-sudo-active' (WP adds 'wp-admin-bar-' prefix) (verified)
        const timerNode = page.locator( '#wp-admin-bar-wp-sudo-active' );

        // Click the timer node — follows the href (deactivation URL) which is a standard
        // anchor navigation. PHP processes the deactivation params and issues a 302 redirect.
        // Promise.all waits for the redirect to complete before proceeding to assertions.
        //
        // waitForURL predicate: /wp-admin/ is safe here because:
        //   - We start on /wp-admin/ (dashboard)
        //   - The redirect target is always /wp-admin/ (remove_query_arg strips only deactivation params)
        //   - There is no ambiguity with the challenge page URL (no page=wp-sudo-challenge involved)
        //
        // Source: class-admin-bar.php handle_deactivate() — wp_safe_redirect(remove_query_arg([...])) (verified)
        await Promise.all( [
            page.waitForURL( /wp-admin/, { waitUntil: 'load', timeout: 10_000 } ),
            timerNode.click(),
        ] );

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
     * The admin bar click navigates to current-page?wp_sudo_deactivate=1&_wpnonce=X.
     * PHP calls wp_safe_redirect(remove_query_arg(['wp_sudo_deactivate', '_wpnonce']))
     * which strips ONLY the deactivation params and redirects back.
     *
     * Starting from /wp-admin/:
     *   Before click: http://localhost:8889/wp-admin/
     *   Deactivation URL: http://localhost:8889/wp-admin/?wp_sudo_deactivate=1&_wpnonce=X
     *   After redirect: http://localhost:8889/wp-admin/  (same as before)
     *
     * Assertions:
     *   - pathname is the same (e.g., /wp-admin/)
     *   - wp_sudo_deactivate param is absent from final URL
     *   - _wpnonce param is absent from final URL
     *
     * Source: class-admin-bar.php — DEACTIVATE_PARAM = 'wp_sudo_deactivate' (verified)
     * Source: class-admin-bar.php handle_deactivate() — wp_safe_redirect(remove_query_arg([...])) (verified)
     *
     * PITFALL: There IS a navigation (click → deactivation URL → 302 redirect → final URL).
     * Do NOT read page.url() immediately after click() without awaiting the redirect.
     * The Promise.all / waitForURL pattern ensures the assertion runs only after the
     * browser has landed on the final URL.
     */
    test( 'ABAR-02: URL does not change after admin bar deactivation click', async ( { page } ) => {
        // Capture the URL before clicking (beforeEach navigated to /wp-admin/).
        // Use the URL object for structured comparison (pathname, searchParams).
        const urlBefore = new URL( page.url() );

        // Click the admin bar node and wait for the full redirect to complete.
        // The redirect strips only the deactivation params — the path is unchanged.
        // Source: class-admin-bar.php handle_deactivate() — remove_query_arg([DEACTIVATE_PARAM, '_wpnonce']) (verified)
        await Promise.all( [
            page.waitForURL( /wp-admin/, { waitUntil: 'load', timeout: 10_000 } ),
            page.locator( '#wp-admin-bar-wp-sudo-active' ).click(),
        ] );

        // Capture the URL after the redirect completes.
        const urlAfter = new URL( page.url() );

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
            urlAfter.searchParams.has( '_wpnonce' ),
            '_wpnonce query param must be absent from URL after redirect'
        ).toBe( false );
    } );

} );
