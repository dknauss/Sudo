/**
 * Public helper API browser tests — PUB-01 through PUB-02
 *
 * Covers the interactive redirect branch of wp_sudo_require() through a
 * test-only mu-plugin that invokes the helper from admin_init.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import type { Page } from '@playwright/test';
import { exec } from 'child_process';
import path from 'path';
import { promisify } from 'util';

const execAsync = promisify( exec );
const DEFAULT_PASSWORD = process.env.WP_PASSWORD ?? 'password';
const E2E_PUBLIC_API_MU_PLUGIN = 'wp-sudo-e2e-public-api.php';
const LOCAL_SITE_PATH = ( process.env.WP_E2E_SITE_PATH ?? '' ).trim();
const WP_ENV_PLUGIN_DIR = process.env.WP_E2E_PLUGIN_DIR?.trim() || path.basename( process.cwd() );

async function installPublicApiMuPlugin(): Promise<void> {
    if ( LOCAL_SITE_PATH ) {
        await execAsync(
            `mkdir -p '${ LOCAL_SITE_PATH }/wp-content/mu-plugins' && cp '${ process.cwd() }/tests/e2e/fixtures/${ E2E_PUBLIC_API_MU_PLUGIN }' '${ LOCAL_SITE_PATH }/wp-content/mu-plugins/${ E2E_PUBLIC_API_MU_PLUGIN }'`,
            { timeout: 30_000 }
        );
        return;
    }

    await execAsync(
        `npx wp-env run cli bash -lc 'mkdir -p /var/www/html/wp-content/mu-plugins && cp /var/www/html/wp-content/plugins/${ WP_ENV_PLUGIN_DIR }/tests/e2e/fixtures/${ E2E_PUBLIC_API_MU_PLUGIN } /var/www/html/wp-content/mu-plugins/${ E2E_PUBLIC_API_MU_PLUGIN }'`,
        { timeout: 30_000 }
    );
}

async function removePublicApiMuPlugin(): Promise<void> {
    if ( LOCAL_SITE_PATH ) {
        await execAsync(
            `rm -f '${ LOCAL_SITE_PATH }/wp-content/mu-plugins/${ E2E_PUBLIC_API_MU_PLUGIN }'`,
            { timeout: 30_000 }
        );
        return;
    }

    await execAsync(
        `npx wp-env run cli bash -lc 'rm -f /var/www/html/wp-content/mu-plugins/${ E2E_PUBLIC_API_MU_PLUGIN }'`,
        { timeout: 30_000 }
    );
}

async function clearSudoCookies( page: Page ): Promise<void> {
    const context = page.context();
    const cookies = await context.cookies();
    const authCookies = cookies.filter( ( cookie ) => ! cookie.name.startsWith( 'wp_sudo' ) );

    await context.clearCookies();
    await context.addCookies( authCookies );
}

test.describe( 'Public API helper flow', () => {
    test.beforeAll( async () => {
        await installPublicApiMuPlugin();
    } );

    test.afterAll( async () => {
        await removePublicApiMuPlugin();
    } );

    test.beforeEach( async ( { page } ) => {
        await clearSudoCookies( page );
    } );

    test( 'PUB-01: wp_sudo_require redirects inactive sessions to the challenge and returns after auth', async ( {
        page,
    } ) => {
        await page.goto( '/wp-admin/?wp_sudo_require_test=1' );

        await expect( page ).toHaveURL( /page=wp-sudo-challenge/ );
        await expect( page.locator( '#wp-sudo-challenge-password-step' ) ).toBeVisible();

        const challengeUrl = new URL( page.url() );
        expect( challengeUrl.searchParams.get( 'return_url' ) ).toContain( 'wp_sudo_require_test=1' );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );
        await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

        await Promise.all( [
            page.waitForURL( /wp_sudo_require_test=1/, { timeout: 15_000 } ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        await expect( page.locator( '#wp-sudo-e2e-public-api-ok' ) ).toBeVisible();

        const cookies = await page.context().cookies();
        expect( cookies.some( ( cookie ) => cookie.name === 'wp_sudo_token' ) ).toBeTruthy();
    } );

    test( 'PUB-02: wp_sudo_require passes in place when a sudo session is already active', async ( {
        page,
    } ) => {
        await activateSudoSession( page, DEFAULT_PASSWORD );
        await page.goto( '/wp-admin/?wp_sudo_require_test=1' );

        await expect( page ).toHaveURL( /wp_sudo_require_test=1/ );
        await expect( page.locator( '#wp-sudo-e2e-public-api-ok' ) ).toBeVisible();
        await expect( page ).not.toHaveURL( /page=wp-sudo-challenge/ );
    } );
} );
