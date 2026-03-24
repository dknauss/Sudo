import { test, expect, activateSudoSession } from '../fixtures/test';

const DEFAULT_PASSWORD = process.env.WP_PASSWORD ?? 'password';
const RUN_MULTISITE_STACK_SMOKE = process.env.WP_SUDO_E2E_MULTISITE_STACK === '1';

test.describe( 'WP Sudo multisite alternative stack smoke tests', () => {
    test.skip(
        ! RUN_MULTISITE_STACK_SMOKE,
        'Multisite alternative stack smoke runs only in the dedicated multisite lane.'
    );

    test( 'MSTACK-01: network-admin challenge cancel returns to the same page without sudo', async ( {
        page,
        context,
    } ) => {
        await activateSudoSession( page, DEFAULT_PASSWORD );
        await page.goto( '/wp-admin/network/plugins.php' );

        await expect( page ).toHaveURL( /\/wp-admin\/network\/plugins\.php(?:\?.*)?$/ );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();

        const networkPluginsUrl = page.url();

        await Promise.all( [
            page.waitForURL( /\/wp-admin\/network\/plugins\.php(?:\?.*)?$/, {
                waitUntil: 'load',
                timeout: 10_000,
            } ),
            page.locator( '#wp-admin-bar-wp-sudo-active' ).click(),
        ] );

        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).not.toBeVisible();

        const challengeLink = page.locator(
            '.wp-sudo-notice a:has-text("Confirm your identity")'
        ).first();

        await expect( challengeLink ).toBeVisible();

        await Promise.all( [
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: 10_000 } ),
            challengeLink.click(),
        ] );

        await expect(
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' )
        ).toHaveAttribute( 'href', networkPluginsUrl );

        await Promise.all( [
            page.waitForURL( networkPluginsUrl, { timeout: 15_000 } ),
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' ).click(),
        ] );

        await expect( page ).toHaveURL( networkPluginsUrl );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).not.toBeVisible();

        const cookies = await context.cookies();
        expect(
            cookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' )
        ).toBeUndefined();
    } );

    test( 'MSTACK-02: network-admin settings POST replays after password auth', async ( {
        page,
    } ) => {
        await page.goto( '/wp-admin/network/settings.php?page=wp-sudo-settings' );
        await expect( page ).toHaveURL(
            /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings$/
        );

        const sessionDuration = page.locator( '#session_duration' );
        const originalValue = await sessionDuration.inputValue();
        const updatedValue = originalValue === '14' ? '13' : '14';

        await sessionDuration.fill( updatedValue );

        await Promise.all( [
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: 15_000 } ),
            page.locator( '#submit' ).click(),
        ] );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );
        await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

        await Promise.all( [
            page.waitForURL(
                /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/,
                { timeout: 15_000 }
            ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        await expect( sessionDuration ).toHaveValue( updatedValue );

        await sessionDuration.fill( originalValue );
        await Promise.all( [
            page.waitForURL(
                /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/,
                { timeout: 15_000 }
            ),
            page.locator( '#submit' ).click(),
        ] );
        await expect( sessionDuration ).toHaveValue( originalValue );
    } );
} );
