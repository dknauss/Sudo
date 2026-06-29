import { test, expect } from '../fixtures/test';

test.describe( 'WP Sudo smoke tests', () => {
    test( 'Settings page loads and displays correct title', async ( {
        visitAdminPage,
        page,
    } ) => {
        // Navigate to Settings > Sudo.
        // The settings page slug is wp-sudo-settings (not wp-sudo).
        // See: Admin::PAGE_SLUG in includes/class-admin.php.
        await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings' );

        // Assert the page title contains "Sudo".
        await expect( page.locator( 'h1' ) ).toContainText( 'Sudo' );

        // Assert we are on the correct settings page (not a WP error page).
        await expect( page.locator( '.wrap' ) ).toBeVisible();
    } );

    test( 'WordPress admin dashboard loads for authenticated user', async ( {
        visitAdminPage,
        page,
    } ) => {
        await visitAdminPage( 'index.php' );

        // Verify we are on the dashboard, not redirected to login.
        await expect( page ).toHaveURL( /wp-admin/ );
        await expect( page.locator( '#wpbody' ) ).toBeVisible();
    } );

    test( 'Policy preset applies and can be restored to Normal', async ( {
        visitAdminPage,
        page,
    } ) => {
        const presetSelection = page.locator( '#policy_preset_selection' );
        const isSettingsUrl = ( url: URL ) =>
            url.pathname === '/wp-admin/options-general.php' &&
            url.searchParams.get( 'page' ) === 'wp-sudo-settings';

        const applyPresetAndWait = async ( preset: string, expectedLabel: string ) => {
            const successNotice = page.locator( '.wp-sudo-notice.notice-success' );

            // The preset field is a <select> dropdown. Selecting a new preset
            // and clicking Save applies it directly (no separate checkbox).
            await presetSelection.selectOption( preset );

            const outcome = Promise.race( [
                page.waitForURL( /page=wp-sudo-challenge/, { timeout: 15_000 } ).then( () => 'challenge' ),
                expect( successNotice ).toContainText( `${ expectedLabel } preset applied.`, {
                    timeout: 15_000,
                } ).then( () => 'success' as const ),
            ] );

            await page
                .locator( '#submit' )
                .evaluate( ( button ) => ( button as HTMLInputElement ).form?.requestSubmit() );

            if ( 'challenge' === ( await outcome ) ) {
                await page.fill( '#wp-sudo-challenge-password', 'password' );

                await page
                    .locator( '#wp-sudo-challenge-password-form' )
                    .evaluate( ( form ) => ( form as HTMLFormElement ).requestSubmit() );
                await expect( page ).toHaveURL( isSettingsUrl, { timeout: 15_000 } );
                await expect( successNotice ).toContainText( `${ expectedLabel } preset applied.`, {
                    timeout: 15_000,
                } );
            }
        };

        await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings' );

        try {
            await applyPresetAndWait( 'incident_lockdown', 'Incident Lockdown' );

            await expect( page.locator( '#rest_app_password_policy' ) ).toHaveValue( 'disabled' );
            await expect( page.locator( '#cli_policy' ) ).toHaveValue( 'disabled' );
            await expect( page.locator( '#cron_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#xmlrpc_policy' ) ).toHaveValue( 'disabled' );
        } finally {
            await page.goto( '/wp-admin/options-general.php?page=wp-sudo-settings' );
            await applyPresetAndWait( 'normal', 'Normal' );
            await expect( page.locator( '#rest_app_password_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#cli_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#cron_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#xmlrpc_policy' ) ).toHaveValue( 'limited' );
        }
    } );
} );
