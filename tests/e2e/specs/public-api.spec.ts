/**
 * Public helper API browser tests — PUB-01 through PUB-02
 *
 * Covers the interactive redirect branch of wp_sudo_require() through a
 * test-only mu-plugin that invokes the helper from admin_init.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import type { Page } from '@playwright/test';
import * as fs from 'fs';
import path from 'path';
import { wpEnvRunCli, containerBash } from '../fixtures/wp-env';

const DEFAULT_PASSWORD = process.env.WP_PASSWORD ?? 'password';
const E2E_PUBLIC_API_MU_PLUGIN = 'wp-sudo-e2e-public-api.php';
const LOCAL_SITE_PATH = ( process.env.WP_E2E_SITE_PATH ?? '' ).trim();
const WP_ENV_PLUGIN_DIR = process.env.WP_E2E_PLUGIN_DIR?.trim() || path.basename( process.cwd() );

async function installPublicApiMuPlugin(): Promise<void> {
    if ( LOCAL_SITE_PATH ) {
        // Host-side file ops on a real Local/Studio site — use fs, never a shell.
        const muDir = path.join( LOCAL_SITE_PATH, 'wp-content', 'mu-plugins' );
        fs.mkdirSync( muDir, { recursive: true } );
        fs.copyFileSync(
            path.join( process.cwd(), 'tests', 'e2e', 'fixtures', E2E_PUBLIC_API_MU_PLUGIN ),
            path.join( muDir, E2E_PUBLIC_API_MU_PLUGIN )
        );
        return;
    }

    await wpEnvRunCli(
        'cli',
        containerBash(
            'mkdir -p /var/www/html/wp-content/mu-plugins && cp "/var/www/html/wp-content/plugins/$1/tests/e2e/fixtures/$2" "/var/www/html/wp-content/mu-plugins/$2"',
            [ WP_ENV_PLUGIN_DIR, E2E_PUBLIC_API_MU_PLUGIN ]
        ),
        { timeout: 30_000 }
    );
}

async function removePublicApiMuPlugin(): Promise<void> {
    if ( LOCAL_SITE_PATH ) {
        fs.rmSync(
            path.join( LOCAL_SITE_PATH, 'wp-content', 'mu-plugins', E2E_PUBLIC_API_MU_PLUGIN ),
            { force: true }
        );
        return;
    }

    await wpEnvRunCli(
        'cli',
        containerBash( 'rm -f "/var/www/html/wp-content/mu-plugins/$1"', [ E2E_PUBLIC_API_MU_PLUGIN ] ),
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

    test( 'PUB-01: wp_sudo_require redirects to the challenge; the helper passes on the user\'s next visit', async ( {
        page,
    } ) => {
        await page.goto( '/wp-admin/?wp_sudo_require_test=1' );

        await expect( page ).toHaveURL( /page=wp-sudo-challenge/ );
        await expect( page.locator( '#wp-sudo-challenge-password-step' ) ).toBeVisible();

        // The challenge URL still CARRIES return_url — Public_API::build_challenge_url()
        // still emits it — but #322 makes it inert: nothing consumes it, and the
        // challenge lands on a neutral page instead. Asserted here so the emit/consume
        // split is visible rather than looking like an oversight.
        const challengeUrl = new URL( page.url() );
        expect( challengeUrl.searchParams.get( 'return_url' ) ).toContain( 'wp_sudo_require_test=1' );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );
        await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

        await Promise.all( [
            page.waitForURL( ( url ) => ! /page=wp-sudo-challenge/.test( url.href ), { timeout: 15_000 } ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        // #322: the user is NOT returned to the guarded page automatically. That was
        // the documented behaviour and it is deliberately removed — a destination the
        // requester chose, reached straight after a successful challenge, executes
        // under the authority just minted.
        await expect( page ).not.toHaveURL( /wp_sudo_require_test=1/ );

        const cookies = await page.context().cookies();
        expect(
            cookies.some( ( cookie ) => cookie.name === 'wp_sudo_token' ),
            'The session must still be granted — the friction is the landing, not the grant.'
        ).toBeTruthy();

        // The guarantee that actually matters to an integrator: the helper passes on
        // the user's own next visit, with no second challenge.
        await page.goto( '/wp-admin/?wp_sudo_require_test=1' );
        await expect( page ).toHaveURL( /wp_sudo_require_test=1/ );
        await expect( page.locator( '#wp-sudo-e2e-public-api-ok' ) ).toBeVisible();
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
