import { test as base, expect, type Page } from '@playwright/test';

type WpAdminFixtures = {
    visitAdminPage: ( path: string, query?: string ) => Promise<void>;
};

export const test = base.extend<WpAdminFixtures>( {
    visitAdminPage: async ( { page }, use ) => {
        const visitAdminPage = async (
            adminPath: string,
            query?: string
        ) => {
            const url =
                '/wp-admin/' + adminPath + ( query ? '?' + query : '' );
            await page.goto( url );

            // Handle WordPress database upgrade screen (appears with trunk WP).
            if ( page.url().includes( 'upgrade.php' ) ) {
                await page.click( 'input[type="submit"]' );
                // After upgrade, there may be a "Continue" link.
                const continueLink = page.locator( 'a.button' );
                if ( await continueLink.isVisible( { timeout: 2000 } ) ) {
                    await continueLink.click();
                }
            }

            // Fail fast if WordPress redirected to login (storageState stale).
            if ( page.url().includes( 'wp-login.php' ) ) {
                throw new Error(
                    'Not authenticated — storageState may be stale. ' +
                    'Delete tests/e2e/artifacts/storage-states/admin.json and re-run.'
                );
            }
        };
        await use( visitAdminPage );
    },
} );

/**
 * Acquire a fresh sudo session by submitting the challenge form in session-only mode.
 *
 * Session-only mode (no stash_key param) authenticates and redirects to the admin
 * dashboard (cancelUrl). Subsequent navigation in the same browser context will carry
 * the wp_sudo_token cookie, giving the test an active sudo session.
 *
 * Source: includes/class-challenge.php render_page() + admin/js/wp-sudo-challenge.js (verified)
 * Source: includes/class-sudo-session.php set_token() (verified — cookie set on success)
 *
 * PITFALL (Pitfall 2): Use Promise.all([waitForURL, click]) — never bare waitForNavigation()
 * after clicking submit. The AJAX auth call completes asynchronously, then JS sets
 * window.location.href. A bare waitForNavigation() races with the fetch completion.
 *
 * PITFALL (Pitfall 5): Wait for window.wpSudoChallenge to be defined before filling the
 * password field. The challenge JS sets this config object via wp_localize_script. If the
 * field is filled before the script initialises, the AJAX call will not fire correctly.
 *
 * @param page     Playwright Page object from the test.
 * @param password WordPress admin password. Defaults to 'password' (wp-env default).
 */
export async function activateSudoSession(
    page: Page,
    password = 'password'
): Promise<void> {
    // Navigate to challenge page in session-only mode (no stash_key param).
    // Source: class-challenge.php — PAGE_SLUG = 'wp-sudo-challenge' (verified)
    await page.goto( '/wp-admin/admin.php?page=wp-sudo-challenge' );

    // Wait for JS config object before interacting.
    // Source: admin/js/wp-sudo-challenge.js — wpSudoChallenge localised config (verified)
    await page.waitForFunction(
        () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
    );

    // Fill password field.
    // Source: class-challenge.php render_page() — id="wp-sudo-challenge-password" (verified)
    await page.fill( '#wp-sudo-challenge-password', password );

    // Submit and wait for redirect (session-only mode redirects to cancelUrl = admin dashboard).
    // Source: admin/js/wp-sudo-challenge.js — on success sets window.location.href = config.cancelUrl (verified)
    //
    // NOTE: waitForURL must exclude the challenge page itself — the challenge page URL already
    // contains '/wp-admin/' so a bare /wp-admin/ pattern resolves immediately on click.
    // Use a URL predicate that waits for a URL that no longer contains 'wp-sudo-challenge'.
    await Promise.all( [
        page.waitForURL(
            ( url ) => url.pathname.includes( '/wp-admin/' ) && ! url.search.includes( 'wp-sudo-challenge' ),
            { timeout: 15_000 }
        ),
        page.click( '#wp-sudo-challenge-submit' ),
    ] );
}

export { expect };
