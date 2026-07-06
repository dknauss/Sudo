import type { Locator } from '@playwright/test';
import { test, expect, activateSudoSession } from '../fixtures/test';

const DEFAULT_PASSWORD = process.env.WP_PASSWORD ?? 'password';
const RUN_MULTISITE_STACK_SMOKE = process.env.WP_SUDO_E2E_MULTISITE_STACK === '1';

// Generous navigation budget for the heavier multisite nginx stack, whose
// POST → redirect round-trips (settings save, reauth replay) run well past the
// single-site default under CI load.
const NAV_TIMEOUT = 30_000;

/**
 * Click that bypasses Playwright's actionability *stability* gate.
 *
 * On the heavier multisite nginx stack under CI load, elements resolve visible
 * and enabled but their bounding boxes never satisfy the two-consecutive-frames
 * "stable" check within the timeout — the admin-bar sudo countdown timer
 * (`#wp-admin-bar-wp-sudo-active`, re-renders every second), the async MU-plugin
 * status reflow above `#submit`, and even the challenge page's own late settling
 * (`#wp-sudo-challenge-submit`) all defeat it, though a real user could click at
 * any time. Every interactive click in this spec therefore goes through
 * `forceClick`. `force: true` skips that gate (and still scrolls the element into
 * view), so it is used rather than `scrollIntoViewIfNeeded()`, which would
 * re-impose the same stability wait. Every call site immediately asserts the
 * resulting navigation or value, so a mis-landed click fails loudly rather than
 * passing silently.
 */
const forceClick = ( locator: Locator ): Promise<void> =>
    locator.click( { force: true } );

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
                timeout: NAV_TIMEOUT,
            } ),
            forceClick( page.locator( '#wp-admin-bar-wp-sudo-active' ) ),
        ] );

        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).not.toBeVisible();

        const challengeLink = page.locator(
            '.wp-sudo-notice a:has-text("Confirm your identity")'
        ).first();

        await expect( challengeLink ).toBeVisible();

        await Promise.all( [
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: NAV_TIMEOUT } ),
            forceClick( challengeLink ),
        ] );

        await expect(
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' )
        ).toHaveAttribute( 'href', networkPluginsUrl );

        await Promise.all( [
            page.waitForURL( networkPluginsUrl, { timeout: NAV_TIMEOUT } ),
            forceClick(
                page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' )
            ),
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
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: NAV_TIMEOUT } ),
            forceClick( page.locator( '#submit' ) ),
        ] );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );
        await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

        await Promise.all( [
            page.waitForURL(
                /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/,
                { timeout: NAV_TIMEOUT }
            ),
            forceClick( page.locator( '#wp-sudo-challenge-submit' ) ),
        ] );

        await expect( sessionDuration ).toHaveValue( updatedValue );

        await sessionDuration.fill( originalValue );
        await Promise.all( [
            page.waitForURL(
                /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/,
                { timeout: NAV_TIMEOUT }
            ),
            forceClick( page.locator( '#submit' ) ),
        ] );
        await expect( sessionDuration ).toHaveValue( originalValue );
    } );

    test( 'MSTACK-03: network-admin policy preset applies and can be restored', async ( {
        page,
    } ) => {
        const presetSelection = page.locator( '#policy_preset_selection' );

        const applyPresetAndWait = async ( preset: string, expectedLabel: string ) => {
            await presetSelection.selectOption( preset );

            await forceClick( page.locator( '#submit' ) );

            await Promise.race( [
                page.waitForURL(
                    /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/,
                    { timeout: NAV_TIMEOUT }
                ),
                page.waitForURL( /page=wp-sudo-challenge/, { timeout: NAV_TIMEOUT } ),
            ] );

            if ( /page=wp-sudo-challenge/.test( page.url() ) ) {
                await page.waitForFunction(
                    () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
                );
                await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

                await Promise.all( [
                    page.waitForURL(
                        /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/,
                        { timeout: NAV_TIMEOUT }
                    ),
                    forceClick( page.locator( '#wp-sudo-challenge-submit' ) ),
                ] );
            }

            await expect(
                page
                    .locator( '.wp-sudo-notice.notice-success' )
                    .filter( { hasText: `${ expectedLabel } preset applied.` } )
                    .first()
            ).toContainText( `${ expectedLabel } preset applied.` );
        };

        await page.goto( '/wp-admin/network/settings.php?page=wp-sudo-settings' );
        await expect( page ).toHaveURL(
            /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings$/
        );

        try {
            await applyPresetAndWait( 'incident_lockdown', 'Incident Lockdown' );

            await expect( page.locator( '#rest_app_password_policy' ) ).toHaveValue( 'disabled' );
            await expect( page.locator( '#cli_policy' ) ).toHaveValue( 'disabled' );
            await expect( page.locator( '#cron_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#xmlrpc_policy' ) ).toHaveValue( 'disabled' );
        } finally {
            await page.goto( '/wp-admin/network/settings.php?page=wp-sudo-settings' );
            await applyPresetAndWait( 'normal', 'Normal' );

            await expect( page.locator( '#rest_app_password_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#cli_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#cron_policy' ) ).toHaveValue( 'limited' );
            await expect( page.locator( '#xmlrpc_policy' ) ).toHaveValue( 'limited' );
        }
    } );
} );
