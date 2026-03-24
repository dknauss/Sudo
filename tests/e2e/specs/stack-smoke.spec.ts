import { test, expect, activateSudoSession } from '../fixtures/test';

test.describe( 'WP Sudo alternative stack smoke tests', () => {
    test( 'STACK-01: admin dashboard and settings page load', async ( {
        visitAdminPage,
        page,
    } ) => {
        await visitAdminPage( 'index.php' );

        await expect( page ).toHaveURL( /\/wp-admin\/(?:index\.php)?$/ );
        await expect( page.locator( '#wpbody' ) ).toBeVisible();

        await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings' );

        await expect( page ).toHaveURL( /page=wp-sudo-settings$/ );
        await expect( page.locator( 'h1' ) ).toContainText( 'Sudo' );
        await expect( page.locator( '.wrap' ) ).toBeVisible();
    } );

    test( 'STACK-02: session-only challenge activates sudo and sets the cookie', async ( {
        page,
        context,
    } ) => {
        await activateSudoSession( page );

        await expect( page ).toHaveURL( /\/wp-admin\/(?:index\.php)?$/ );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();

        const cookies = await context.cookies();
        expect(
            cookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' )
        ).toBeDefined();
    } );

    test( 'STACK-03: stashed settings POST replays after password auth', async ( {
        page,
    } ) => {
        await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );
        await expect( page ).toHaveURL(
            /\/wp-admin\/options-general\.php\?page=wp-sudo-settings$/
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
        await page.fill( '#wp-sudo-challenge-password', 'password' );

        await Promise.all( [
            page.waitForURL(
                /\/wp-admin\/options-general\.php\?page=wp-sudo-settings$/,
                { timeout: 15_000 }
            ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        await expect( sessionDuration ).toHaveValue( updatedValue );

        // Restore the original setting so stack smoke runs stay side-effect-light.
        await sessionDuration.fill( originalValue );
        await Promise.all( [
            page.waitForURL(
                /\/wp-admin\/options-general\.php\?page=wp-sudo-settings$/,
                { timeout: 15_000 }
            ),
            page.locator( '#submit' ).click(),
        ] );
        await expect( sessionDuration ).toHaveValue( originalValue );
    } );

    test( 'STACK-04: admin bar AJAX deactivation clears the sudo cookie', async ( {
        page,
        context,
    } ) => {
        await activateSudoSession( page );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();

        await Promise.all( [
            page.waitForURL( /\/wp-admin\/(?:index\.php)?$/, { timeout: 15_000 } ),
            page.locator( '#wp-admin-bar-wp-sudo-active' ).click(),
        ] );

        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).not.toBeVisible();

        const cookies = await context.cookies();
        expect(
            cookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' )
        ).toBeUndefined();
    } );

    test( 'STACK-05: session-only challenge respects an explicit return_url after auth', async ( {
        page,
    } ) => {
        await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );
        const returnUrl = page.url();

        await page.goto(
            '/wp-admin/admin.php?page=wp-sudo-challenge&return_url=' +
                encodeURIComponent( returnUrl )
        );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );

        await page.fill( '#wp-sudo-challenge-password', 'password' );

        await Promise.all( [
            page.waitForURL( returnUrl, { timeout: 15_000 } ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        await expect( page.locator( 'h1' ) ).toContainText( 'Sudo' );
    } );

    test( 'STACK-06: cancel respects an explicit return_url without creating a sudo cookie', async ( {
        page,
        context,
    } ) => {
        await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );
        const returnUrl = page.url();

        await page.goto(
            '/wp-admin/admin.php?page=wp-sudo-challenge&return_url=' +
                encodeURIComponent( returnUrl )
        );

        await Promise.all( [
            page.waitForURL( returnUrl, { timeout: 15_000 } ),
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' ).click(),
        ] );

        await expect( page.locator( 'h1' ) ).toContainText( 'Sudo' );

        const cookies = await context.cookies();
        expect(
            cookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' )
        ).toBeUndefined();
    } );

    test( 'STACK-07: canceling a stashed settings POST does not replay the change', async ( {
        page,
        context,
    } ) => {
        await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );
        await expect( page ).toHaveURL(
            /\/wp-admin\/options-general\.php\?page=wp-sudo-settings$/
        );

        const sessionDuration = page.locator( '#session_duration' );
        const originalValue = await sessionDuration.inputValue();
        const updatedValue = originalValue === '14' ? '13' : '14';

        await sessionDuration.fill( updatedValue );

        await Promise.all( [
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: 15_000 } ),
            page.locator( '#submit' ).click(),
        ] );

        await Promise.all( [
            page.waitForURL(
                /\/wp-admin\/options-general\.php\?page=wp-sudo-settings$/,
                { timeout: 15_000 }
            ),
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' ).click(),
        ] );

        await page.reload();
        await expect( sessionDuration ).toHaveValue( originalValue );

        const cookies = await context.cookies();
        expect(
            cookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' )
        ).toBeUndefined();
    } );

    test( 'STACK-08: MU install AJAX fails closed without an active sudo session', async ( {
        page,
        context,
    } ) => {
        const cookies = await context.cookies();
        const authCookies = cookies.filter(
            ( cookie ) => ! cookie.name.startsWith( 'wp_sudo' )
        );
        await context.clearCookies();
        await context.addCookies( authCookies );

        await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );

        const result = await page.evaluate( async () => {
            const body = new FormData();
            body.append( 'action', wpSudoAdmin.installAction );
            body.append( '_nonce', wpSudoAdmin.nonce );

            const response = await fetch( wpSudoAdmin.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body,
            } );

            return {
                status: response.status,
                json: await response.json(),
            };
        } );

        expect( result.status ).toBe( 403 );
        expect( result.json?.data?.code ).toBe( 'sudo_required' );
        expect( result.json?.data?.message ).toContain( 'sudo session is required' );

        const updatedCookies = await context.cookies();
        expect(
            updatedCookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' )
        ).toBeUndefined();
    } );
} );
