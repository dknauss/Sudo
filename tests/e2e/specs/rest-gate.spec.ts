/**
 * REST gate tests — REST-01 through REST-08
 *
 * Closes the manual lane in #273. `4.8.0` changed **server-facing REST routing and
 * method matching** — the gates now match `POST` on `/wp/v2/users` (#213), add a
 * `user.change_email` rule (#214), and match the `show_in_rest` aliases on
 * `/wp/v2/settings` (#215, `siteurl`→`url`, `admin_email`→`email`). Nothing in the
 * E2E suite exercised any of it: `tests/Integration/RestGatingTest.php` covers the
 * matching at PHP level, but not through a real web server, so the Apache lane could
 * not catch a rewrite/header regression that breaks REST routing or Authorization
 * passthrough.
 *
 * These run in the standard chromium project, so `release-confidence.yml`'s
 * "Apache/wp-env full E2E" job (which runs `npm run test:e2e`) picks them up — that
 * is the Apache lane #273 asks for. They are also wired into the sharded `e2e.yml`
 * matrix; a spec absent from that matrix never runs there.
 *
 * WHY EACH ASSERTION CHECKS THE ERROR CODE, NOT JUST THE STATUS
 * A bare `expect(status).toBe(403)` would pass for the wrong reasons — a stale
 * nonce, a missing capability, or a route that 403s before the gate is consulted.
 * Every gated assertion therefore requires `code === 'sudo_required'`, which only
 * `Gate` emits. Source: includes/class-gate.php (verified).
 *
 * POSITIVE CONTROLS
 * REST-07 repeats a gated write *with* an active sudo session and requires it to
 * succeed. Without it, a suite-wide breakage that 403s everything would read as a
 * pass. REST-08 asserts a non-gated route is untouched.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import type { Page } from '@playwright/test';
import * as fs from 'fs';
import path from 'path';
import { wpEnvRunCli, containerBash } from '../fixtures/wp-env';

const E2E_REST_NONCE_MU_PLUGIN = 'wp-sudo-e2e-rest-nonce.php';
const LOCAL_SITE_PATH = ( process.env.WP_E2E_SITE_PATH ?? '' ).trim();
const WP_ENV_PLUGIN_DIR =
    process.env.WP_E2E_PLUGIN_DIR?.trim() || path.basename( process.cwd() );

type RestResult = { status: number; code: string | null; body: unknown };

async function installNonceMuPlugin(): Promise< void > {
    if ( LOCAL_SITE_PATH ) {
        const muDir = path.join( LOCAL_SITE_PATH, 'wp-content', 'mu-plugins' );
        fs.mkdirSync( muDir, { recursive: true } );
        fs.copyFileSync(
            path.join( process.cwd(), 'tests', 'e2e', 'fixtures', E2E_REST_NONCE_MU_PLUGIN ),
            path.join( muDir, E2E_REST_NONCE_MU_PLUGIN )
        );
        return;
    }

    await wpEnvRunCli(
        'cli',
        containerBash(
            'mkdir -p /var/www/html/wp-content/mu-plugins && cp "/var/www/html/wp-content/plugins/$1/tests/e2e/fixtures/$2" "/var/www/html/wp-content/mu-plugins/$2"',
            [ WP_ENV_PLUGIN_DIR, E2E_REST_NONCE_MU_PLUGIN ]
        ),
        { timeout: 30_000 }
    );
}

async function removeNonceMuPlugin(): Promise< void > {
    if ( LOCAL_SITE_PATH ) {
        fs.rmSync(
            path.join( LOCAL_SITE_PATH, 'wp-content', 'mu-plugins', E2E_REST_NONCE_MU_PLUGIN ),
            { force: true }
        );
        return;
    }

    await wpEnvRunCli(
        'cli',
        containerBash( 'rm -f "/var/www/html/wp-content/mu-plugins/$1"', [
            E2E_REST_NONCE_MU_PLUGIN,
        ] ),
        { timeout: 30_000 }
    );
}

/** Drop only the sudo cookies, leaving the login session intact. */
async function clearSudoCookies( page: Page ): Promise< void > {
    const context = page.context();
    const keep = ( await context.cookies() ).filter(
        ( cookie ) => ! cookie.name.startsWith( 'wp_sudo' )
    );
    await context.clearCookies();
    await context.addCookies( keep );
}

/**
 * Cookie-authenticated REST call from the page context.
 *
 * Uses `window.fetch` with `X-WP-Nonce` rather than Playwright's `request` fixture so
 * the browser's own cookie jar and same-origin handling apply — which is the path a
 * real admin screen takes, and the path the Apache rewrite rules have to serve.
 */
async function restRequest(
    page: Page,
    method: string,
    restPath: string,
    data?: Record< string, unknown >
): Promise< RestResult > {
    return page.evaluate(
        async ( [ m, p, payload ] ) => {
            const settings = ( window as unknown as {
                wpApiSettings?: { root: string; nonce: string };
            } ).wpApiSettings;

            if ( ! settings?.nonce ) {
                throw new Error(
                    'wpApiSettings.nonce missing — the REST-nonce mu-plugin did not load.'
                );
            }

            const headers: Record< string, string > = {
                'X-WP-Nonce': settings.nonce,
            };
            const init: RequestInit = { method: m as string, headers };

            if ( payload ) {
                headers[ 'Content-Type' ] = 'application/json';
                init.body = JSON.stringify( payload );
            }

            const response = await fetch( settings.root + ( p as string ), init );
            let body: unknown = null;
            try {
                body = await response.json();
            } catch {
                body = null;
            }

            return {
                status: response.status,
                code:
                    body && typeof body === 'object' && 'code' in ( body as object )
                        ? String( ( body as { code: unknown } ).code )
                        : null,
                body,
            };
        },
        [ method, restPath, data ?? null ] as const
    );
}

/** Assert a response is the gate's refusal, not an incidental 403. */
function expectSudoRequired( result: RestResult, what: string ): void {
    expect( result.status, `${ what } must be refused with 403` ).toBe( 403 );
    expect(
        result.code,
        `${ what } must be refused BY THE GATE (sudo_required), not by a nonce or capability failure`
    ).toBe( 'sudo_required' );
}

test.describe( 'REST gate', () => {
    let adminId = '';

    test.beforeAll( async () => {
        await installNonceMuPlugin();
    } );

    test.afterAll( async () => {
        await removeNonceMuPlugin();
    } );

    test.beforeEach( async ( { page, visitAdminPage } ) => {
        await visitAdminPage( 'index.php' );
        await clearSudoCookies( page );
        await visitAdminPage( 'index.php' );

        if ( ! adminId ) {
            const me = await restRequest( page, 'GET', 'wp/v2/users/me' );
            adminId = String( ( me.body as { id?: number } )?.id ?? '' );
            expect( adminId, 'could not resolve the admin user id' ).not.toBe( '' );
        }
    } );

    /**
     * REST-01: #213 — the password-change rule matched only PUT/PATCH, but core
     * registers the users route under WP_REST_Server::EDITABLE, which includes POST.
     */
    test( 'REST-01: POST /wp/v2/users/{id} changing a password is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', `wp/v2/users/${ adminId }`, {
            password: 'a-new-password-that-must-not-be-set',
        } );

        expectSudoRequired( result, 'a REST password change' );
    } );

    /**
     * REST-02: #213 — same rule via the /me route (update_current_item).
     */
    test( 'REST-02: POST /wp/v2/users/me changing a password is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', 'wp/v2/users/me', {
            password: 'a-new-password-that-must-not-be-set',
        } );

        expectSudoRequired( result, 'a REST password change on /me' );
    } );

    /**
     * REST-03: #214 — user.change_email, the pivot toward a password-reset takeover.
     */
    test( 'REST-03: POST /wp/v2/users/{id} changing an email is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', `wp/v2/users/${ adminId }`, {
            email: 'attacker-controlled@example.com',
        } );

        expectSudoRequired( result, 'a REST email change' );
    } );

    /**
     * REST-04: #213 — role promotion over POST.
     */
    test( 'REST-04: POST /wp/v2/users/{id} promoting a role is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', `wp/v2/users/${ adminId }`, {
            roles: [ 'administrator' ],
        } );

        expectSudoRequired( result, 'a REST role promotion' );
    } );

    /**
     * REST-05: #215 — the rule matched the raw option name `siteurl`, but
     * /wp/v2/settings keys by the show_in_rest alias `url`, so it never fired. A site
     * URL takeover loads attacker-origin scripts same-origin in wp-admin.
     */
    test( 'REST-05: POST /wp/v2/settings changing url (siteurl alias) is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', 'wp/v2/settings', {
            url: 'https://attacker.example.com',
        } );

        expectSudoRequired( result, 'a REST site-URL change via the show_in_rest alias' );
    } );

    /**
     * REST-06: #215 — the admin_email → email alias, same defect.
     */
    test( 'REST-06: POST /wp/v2/settings changing email (admin_email alias) is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', 'wp/v2/settings', {
            email: 'attacker-controlled@example.com',
        } );

        expectSudoRequired( result, 'a REST admin-email change via the show_in_rest alias' );
    } );

    /**
     * REST-07: POSITIVE CONTROL. The same write must succeed once sudo is active.
     * Without this the whole file would pass if something 403'd every REST write.
     * Restores the original value so the run stays side-effect-light.
     */
    test( 'REST-07: the gated settings write succeeds with an active sudo session', async ( {
        page,
        visitAdminPage,
    } ) => {
        const before = await restRequest( page, 'GET', 'wp/v2/settings' );
        const originalEmail = String(
            ( before.body as { email?: string } )?.email ?? ''
        );
        expect( originalEmail, 'could not read the current admin email' ).not.toBe( '' );

        await activateSudoSession( page );
        await visitAdminPage( 'index.php' );

        const changed = await restRequest( page, 'POST', 'wp/v2/settings', {
            email: originalEmail,
        } );

        expect(
            changed.status,
            'with an active sudo session the same write must pass the gate'
        ).toBe( 200 );
        expect( changed.code, 'an active sudo session must not produce a gate refusal' ).toBeNull();
    } );

    /**
     * REST-08: a non-gated route is untouched. On the Apache lane this doubles as the
     * routing check #273 asks for — a 200 here means pretty REST routes resolve
     * through mod_rewrite and the gate did not over-match.
     */
    test( 'REST-08: GET /wp/v2/users/me is not gated and routes cleanly', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'GET', 'wp/v2/users/me' );

        expect( result.status, 'a read of the current user must not be gated' ).toBe( 200 );
        expect( result.code, 'a non-gated read must produce no gate refusal' ).toBeNull();
    } );
} );
