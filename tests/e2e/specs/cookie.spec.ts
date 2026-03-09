/**
 * Cookie attribute tests — COOK-01, COOK-02, COOK-03
 *
 * Verify the wp_sudo_token cookie has the correct security attributes after a
 * sudo session is activated. These attributes cannot be tested via PHPUnit because
 * setcookie() is PHP-level and requires a real browser to observe cookie headers.
 *
 * Cookie set by: includes/class-sudo-session.php set_token() (verified)
 * Cookie name constant: Sudo_Session::TOKEN_COOKIE = 'wp_sudo_token' (verified)
 * httpOnly: always true (hardcoded in setcookie() call) (verified)
 * sameSite: always 'Strict' (hardcoded in setcookie() call) (verified)
 * path: COOKIEPATH = '/' in standard WordPress (verified)
 * secure: false for http://localhost:8889 — NOT asserted here (correct behaviour)
 */
import { test, expect, activateSudoSession } from '../fixtures/test';

test.describe( 'Cookie attributes', () => {
    test.beforeEach( async ( { context } ) => {
        // Clear any leftover sudo session cookies from a prior test so each
        // test starts without an active session, then activates a fresh one.
        // Source: global-setup.ts exclusion pattern (wp_sudo prefix) (verified)
        const cookies = await context.cookies();
        const sudoCookies = cookies.filter( ( c ) =>
            c.name.startsWith( 'wp_sudo' )
        );
        if ( sudoCookies.length > 0 ) {
            await context.clearCookies();
            // Restore WP auth cookies (all non-sudo cookies).
            const authCookies = cookies.filter(
                ( c ) => ! c.name.startsWith( 'wp_sudo' )
            );
            await context.addCookies( authCookies );
        }
    } );

    test( 'COOK-01: wp_sudo_token cookie has httpOnly=true', async ( {
        page,
        context,
    } ) => {
        await activateSudoSession( page );

        // Source: class-sudo-session.php set_token() — 'httponly' => true (verified)
        const cookies = await context.cookies();
        const sudoCookie = cookies.find( ( c ) => c.name === 'wp_sudo_token' );

        expect(
            sudoCookie,
            'wp_sudo_token cookie must exist after session activation'
        ).toBeDefined();
        expect(
            sudoCookie!.httpOnly,
            'wp_sudo_token must have httpOnly=true — prevents XSS token theft'
        ).toBe( true );
    } );

    test( 'COOK-02: wp_sudo_token cookie has sameSite=Strict', async ( {
        page,
        context,
    } ) => {
        await activateSudoSession( page );

        // Source: class-sudo-session.php set_token() — 'samesite' => 'Strict' (verified)
        const cookies = await context.cookies();
        const sudoCookie = cookies.find( ( c ) => c.name === 'wp_sudo_token' );

        expect(
            sudoCookie,
            'wp_sudo_token cookie must exist after session activation'
        ).toBeDefined();
        expect(
            sudoCookie!.sameSite,
            'wp_sudo_token must have sameSite=Strict — prevents CSRF token leakage'
        ).toBe( 'Strict' );
    } );

    test( 'COOK-03: wp_sudo_token cookie path is root /', async ( {
        page,
        context,
    } ) => {
        await activateSudoSession( page );

        // Source: class-sudo-session.php set_token() — 'path' => COOKIEPATH (verified)
        // COOKIEPATH = '/' in standard WordPress install (wp-env default).
        // Note: the plugin also sends an expiry cookie for ADMIN_COOKIE_PATH (/wp-admin/)
        // to clear any stale token. The new token is always set at path '/'.
        const cookies = await context.cookies();
        const sudoCookies = cookies.filter( ( c ) => c.name === 'wp_sudo_token' );

        // Should have exactly one active (non-expired) token cookie.
        // Filter to the root-path cookie (not the expiry cookie at /wp-admin/).
        const rootCookie = sudoCookies.find( ( c ) => c.path === '/' );

        expect(
            rootCookie,
            'wp_sudo_token cookie at path "/" must exist after session activation'
        ).toBeDefined();
        expect(
            rootCookie!.path,
            'wp_sudo_token must have path="/" (COOKIEPATH) — applies to all WP pages'
        ).toBe( '/' );
    } );
} );
