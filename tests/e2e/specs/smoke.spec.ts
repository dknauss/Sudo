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
        const applyPreset     = page.locator( 'input[name="wp_sudo_settings[apply_policy_preset]"]' );

        const applyPresetAndWait = async ( preset: string, expectedLabel: string ) => {
            // The preset field is a <select> dropdown, not radio buttons.
            await presetSelection.selectOption( preset );
            await applyPreset.check();

            await page.click( '#submit' );

            await Promise.race( [
                page.waitForURL( /page=wp-sudo-settings/, { timeout: 15_000 } ),
                page.waitForURL( /page=wp-sudo-challenge/, { timeout: 15_000 } ),
            ] );

            if ( /page=wp-sudo-challenge/.test( page.url() ) ) {
                await page.fill( '#wp-sudo-challenge-password', 'password' );

                await Promise.all( [
                    page.waitForURL( /page=wp-sudo-settings/, { timeout: 15_000 } ),
                    page.click( '#wp-sudo-challenge-submit' ),
                ] );
            }

            await expect( page.locator( '.wp-sudo-notice.notice-success' ) ).toContainText(
                `Applied the ${ expectedLabel } preset.`
            );
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
