/**
 * Multisite network admin reauthentication flow — MULTI-01
 *
 * Local-only regression for the network-admin return_url bug fixed in 903bc32.
 *
 * This spec is intentionally excluded from default CI expectations because the
 * GitHub-hosted wp-env stack is single-site. It is meant for a real multisite
 * Local/Studio environment exposed through WP_BASE_URL.
 *
 * Flow covered:
 *   1. Start with an active sudo session.
 *   2. Navigate to /wp-admin/network/plugins.php.
 *   3. Deactivate sudo from the admin bar on that page.
 *   4. Follow the gate notice challenge link.
 *   5. Reauthenticate.
 *   6. Verify redirect returns to /wp-admin/network/plugins.php with sudo active.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import { exec } from 'child_process';
import { promisify } from 'util';

const LOCAL_MULTISITE_HOST = 'multisite-subdomains.local';
const DEFAULT_PASSWORD = process.env.WP_PASSWORD ?? 'password';
const E2E_TWO_FACTOR_CODE = '123456';
const E2E_TWO_FACTOR_MU_PLUGIN = 'wp-sudo-e2e-two-factor.php';
const E2E_TWO_FACTOR_REQUIRE_META = '_wp_sudo_e2e_require_two_factor';
const E2E_TWO_FACTOR_CODE_META = '_wp_sudo_e2e_two_factor_code';
const E2E_LOCKOUT_SECONDS_META = '_wp_sudo_e2e_lockout_seconds';
const execAsync = promisify( exec );

function getMultisiteSitePath(): string {
    const configured = process.env.WP_E2E_SITE_PATH ?? '';

    return configured.trim();
}

async function runWpCli( sitePath: string, baseUrl: string, args: string ): Promise<void> {
    await execAsync(
        `wp --path='${ sitePath }' --url='${ baseUrl }' ${ args }`,
        { timeout: 30_000 }
    );
}

async function installE2eTwoFactorBridge( sitePath: string ): Promise<void> {
    await execAsync(
        `mkdir -p '${ sitePath }/wp-content/mu-plugins' && cp '${ process.cwd() }/tests/e2e/fixtures/${ E2E_TWO_FACTOR_MU_PLUGIN }' '${ sitePath }/wp-content/mu-plugins/${ E2E_TWO_FACTOR_MU_PLUGIN }'`,
        { timeout: 30_000 }
    );
}

async function removeE2eTwoFactorBridge( sitePath: string ): Promise<void> {
    await execAsync(
        `rm -f '${ sitePath }/wp-content/mu-plugins/${ E2E_TWO_FACTOR_MU_PLUGIN }'`,
        { timeout: 30_000 }
    );
}

async function clearSudoFailureMeta( sitePath: string, baseUrl: string ): Promise<void> {
    for ( const metaKey of [
        '_wp_sudo_lockout_until',
        '_wp_sudo_failure_event',
        '_wp_sudo_failed_attempts',
        '_wp_sudo_throttle_until',
        E2E_LOCKOUT_SECONDS_META,
    ] ) {
        await runWpCli(
            sitePath,
            baseUrl,
            `user meta delete 1 ${ metaKey } --quiet 2>/dev/null || true`
        );
    }
}

async function enableE2eTwoFactor( sitePath: string, baseUrl: string ): Promise<void> {
    await runWpCli(
        sitePath,
        baseUrl,
        `user meta update 1 ${ E2E_TWO_FACTOR_REQUIRE_META } 1 --quiet`
    );
    await runWpCli(
        sitePath,
        baseUrl,
        `user meta update 1 ${ E2E_TWO_FACTOR_CODE_META } ${ E2E_TWO_FACTOR_CODE } --quiet`
    );
}

async function disableE2eTwoFactor( sitePath: string, baseUrl: string ): Promise<void> {
    await runWpCli(
        sitePath,
        baseUrl,
        `user meta delete 1 ${ E2E_TWO_FACTOR_REQUIRE_META } --quiet 2>/dev/null || true`
    );
    await runWpCli(
        sitePath,
        baseUrl,
        `user meta delete 1 ${ E2E_TWO_FACTOR_CODE_META } --quiet 2>/dev/null || true`
    );
}

async function setE2eLockoutSeconds(
    sitePath: string,
    baseUrl: string,
    seconds: number
): Promise<void> {
    await runWpCli(
        sitePath,
        baseUrl,
        `user meta update 1 ${ E2E_LOCKOUT_SECONDS_META } ${ seconds } --quiet`
    );
}

test.describe( 'Multisite network admin flow', () => {
    test.beforeAll( async () => {
        const configuredBaseUrl = process.env.WP_BASE_URL ?? '';
        const sitePath = getMultisiteSitePath();

        if (
            configuredBaseUrl &&
            new URL( configuredBaseUrl ).hostname === LOCAL_MULTISITE_HOST &&
            sitePath
        ) {
            await installE2eTwoFactorBridge( sitePath );
        }
    } );

    test.afterAll( async () => {
        const configuredBaseUrl = process.env.WP_BASE_URL ?? '';
        const sitePath = getMultisiteSitePath();

        if (
            configuredBaseUrl &&
            new URL( configuredBaseUrl ).hostname === LOCAL_MULTISITE_HOST &&
            sitePath
        ) {
            await disableE2eTwoFactor( sitePath, configuredBaseUrl );
            await clearSudoFailureMeta( sitePath, configuredBaseUrl );
            await removeE2eTwoFactorBridge( sitePath );
        }
    } );

    test( 'MULTI-01: network plugins reauth returns to the same network admin page', async ( {
        page,
        context,
    } ) => {
        const configuredBaseUrl = process.env.WP_BASE_URL ?? '';

        test.skip(
            ! configuredBaseUrl || new URL( configuredBaseUrl ).hostname !== LOCAL_MULTISITE_HOST,
            `Requires WP_BASE_URL=http://${ LOCAL_MULTISITE_HOST }`
        );

        await activateSudoSession( page, DEFAULT_PASSWORD );
        await page.goto( '/wp-admin/network/plugins.php' );

        // Fail fast if this environment is not a multisite network admin.
        await expect( page ).toHaveURL( /\/wp-admin\/network\/plugins\.php(?:\?.*)?$/ );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();

        const networkPluginsUrl = page.url();
        const networkOrigin = new URL( networkPluginsUrl ).origin;

        // Deactivate from the current network-admin page so the gate notice is rendered
        // for the exact URL that previously built an incorrect return_url host.
        await Promise.all( [
            page.waitForURL( /\/wp-admin\/network\/plugins\.php(?:\?.*)?$/, {
                waitUntil: 'load',
                timeout: 10_000,
            } ),
            page.locator( '#wp-admin-bar-wp-sudo-active' ).click(),
        ] );

        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).not.toBeVisible();

        const cookies = await context.cookies();
        expect( cookies.find( ( cookie ) => cookie.name === 'wp_sudo_token' ) ).toBeUndefined();

        const challengeLink = page.locator(
            '.wp-sudo-notice a:has-text("Confirm your identity")'
        ).first();

        await expect( challengeLink ).toBeVisible();
        const challengeHref = await challengeLink.getAttribute( 'href' );

        expect( challengeHref ).not.toBeNull();

        const challengeUrl = new URL( challengeHref ?? '' );

        expect( challengeUrl.origin ).toBe( networkOrigin );
        expect( challengeUrl.pathname ).toBe( '/wp-admin/network/admin.php' );
        expect( challengeUrl.searchParams.get( 'page' ) ).toBe( 'wp-sudo-challenge' );
        expect( challengeUrl.searchParams.get( 'return_url' ) ).toBe( networkPluginsUrl );

        await Promise.all( [
            page.waitForURL( /page=wp-sudo-challenge/, { timeout: 10_000 } ),
            challengeLink.click(),
        ] );

        const visitedChallengeUrl = new URL( page.url() );

        expect( visitedChallengeUrl.origin ).toBe( networkOrigin );
        expect( visitedChallengeUrl.pathname ).toBe( '/wp-admin/network/admin.php' );
        expect( visitedChallengeUrl.searchParams.get( 'page' ) ).toBe( 'wp-sudo-challenge' );
        expect( visitedChallengeUrl.searchParams.get( 'return_url' ) ).toBe( networkPluginsUrl );
        await expect(
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' )
        ).toHaveAttribute( 'href', networkPluginsUrl );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );
        await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

        await Promise.all( [
            page.waitForURL( networkPluginsUrl, { timeout: 15_000 } ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        await expect( page ).toHaveURL( networkPluginsUrl );
        await expect( page.locator( '#wp-admin-bar-wp-sudo-active' ) ).toBeVisible();
    } );

    test( 'MULTI-02: network settings POST replay survives 2FA lockout expiry recovery', async ( {
        page,
    } ) => {
        const configuredBaseUrl = process.env.WP_BASE_URL ?? '';
        const sitePath = getMultisiteSitePath();

        test.skip(
            ! configuredBaseUrl || new URL( configuredBaseUrl ).hostname !== LOCAL_MULTISITE_HOST,
            `Requires WP_BASE_URL=http://${ LOCAL_MULTISITE_HOST }`
        );
        test.skip(
            ! sitePath,
            'Requires WP_E2E_SITE_PATH to point at the multisite local WordPress root.'
        );

        await enableE2eTwoFactor( sitePath, configuredBaseUrl );
        await clearSudoFailureMeta( sitePath, configuredBaseUrl );
        await setE2eLockoutSeconds( sitePath, configuredBaseUrl, 3 );

        try {
            await page.goto( '/wp-admin/network/settings.php?page=wp-sudo-settings' );
            await expect( page ).toHaveURL( /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings$/ );

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
            await page.click( '#wp-sudo-challenge-submit' );

            await expect(
                page.locator( '#wp-sudo-challenge-2fa-step' ),
                'A correct password should advance the multisite POST replay flow to the 2FA step'
            ).toBeVisible( { timeout: 15_000 } );

            for ( let attempt = 1; attempt <= 3; attempt++ ) {
                await page.fill( '#wp-sudo-e2e-two-factor-code', '000000' );
                await page.click( '#wp-sudo-challenge-2fa-submit' );

                await expect(
                    page.locator( '#wp-sudo-challenge-2fa-error' )
                ).toContainText( 'Invalid authentication code', { timeout: 10_000 } );
            }

            await page.fill( '#wp-sudo-e2e-two-factor-code', '000000' );
            await page.click( '#wp-sudo-challenge-2fa-submit' );
            await expect( page.locator( '#wp-sudo-challenge-2fa-submit' ) ).toBeDisabled();
            await expect(
                page.locator( '#wp-sudo-challenge-2fa-submit' )
            ).toBeEnabled( { timeout: 10_000 } );

            await page.fill( '#wp-sudo-e2e-two-factor-code', '000000' );
            await page.click( '#wp-sudo-challenge-2fa-submit' );

            await expect(
                page.locator( '#wp-sudo-challenge-2fa-error' )
            ).toContainText( 'Too many failed attempts', { timeout: 10_000 } );
            await expect( page.locator( '#wp-sudo-challenge-2fa-submit' ) ).toBeDisabled();
            await expect(
                page.locator( '#wp-sudo-challenge-2fa-submit' )
            ).toBeEnabled( { timeout: 10_000 } );
            await expect(
                page.locator( '#wp-sudo-challenge-2fa-error' )
            ).toBeHidden( { timeout: 10_000 } );

            await page.fill( '#wp-sudo-e2e-two-factor-code', E2E_TWO_FACTOR_CODE );

            await Promise.all( [
                page.waitForURL( /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/, {
                    timeout: 15_000,
                } ),
                page.click( '#wp-sudo-challenge-2fa-submit' ),
            ] );

            await expect( page ).toHaveURL(
                /\/wp-admin\/network\/settings\.php\?page=wp-sudo-settings(?:&updated=true)?$/
            );
            await expect( page.locator( '#session_duration' ) ).toHaveValue( updatedValue );
        } finally {
            await disableE2eTwoFactor( sitePath, configuredBaseUrl );
            await clearSudoFailureMeta( sitePath, configuredBaseUrl );
        }
    } );
} );
