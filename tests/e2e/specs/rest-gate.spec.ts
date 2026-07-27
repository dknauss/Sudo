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
 * Every gated assertion therefore requires `code === 'sudo_required'`.
 *
 * That code is NOT unique to `Gate` repo-wide: `class-admin.php` also emits it from
 * `handle_mu_install()`, `handle_mu_uninstall()` and `handle_app_password_policy_save()`.
 * The narrower claim these tests actually rely on is that it is unique **on REST
 * routes** — all three of those are admin-ajax handlers and cannot serve `/wp/v2/*`.
 * Stated precisely because the broad version would be silently invalidated by any
 * future REST-facing emitter outside `Gate`, and this is the sentence a reviewer
 * leans on to decide the suite is not vacuous.
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
const E2E_TARGET_LOGIN = 'wp_sudo_rest_gate_target';
const WP_PASSWORD = process.env.WP_PASSWORD ?? 'password';
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

/** Run a WP-CLI command, returning stdout. */
async function wpCli( args: string[] ): Promise< string > {
    const { stdout } = await wpEnvRunCli( 'cli', args, { timeout: 60_000 } );
    return stdout;
}

/**
 * Hit the site once over HTTP so mu-plugin `init` work runs in a web context.
 *
 * A plain unauthenticated GET is enough — `init` fires on every WordPress request,
 * and the fixture only needs that hook to run somewhere that is not WP-CLI. Issued
 * from the runner rather than from inside the CLI container, which cannot reach the
 * web container on localhost.
 */
async function requestWebPageOnce(): Promise< void > {
    const base = process.env.WP_BASE_URL ?? 'http://localhost:8889';
    try {
        await fetch( base, { redirect: 'follow' } );
    } catch {
        // A failed warm-up is not fatal here: the lookup below reports the real
        // problem with a clearer message than a swallowed network error would.
    }
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
    let targetId = '';
    let appPasswordAuth = '';
    let appPasswordRaw = '';

    test.beforeAll( async () => {
        await installNonceMuPlugin();

        // The mu-plugin creates the probe target at `init` during a WEB request, so a
        // page load has to happen before the lookup. Ordering matters and is not
        // incidental: the mu-plugin refuses to run under WP-CLI, because the CLI
        // policy maps `wp_insert_user()` itself and would refuse it there.
        await requestWebPageOnce();

        // Look the target up rather than creating it here. `wp user create` is refused by the gate on the CLI
        // surface, and the policy cannot be relaxed from CLI either because changing
        // WP Sudo's settings is gated on that same surface — the gate cannot be
        // disarmed from the surface it protects.
        targetId = ( await wpCli( [
            'wp', 'user', 'get', E2E_TARGET_LOGIN, '--field=ID',
        ] ) ).trim();

        appPasswordRaw = ( await wpCli( [
            'wp', 'user', 'application-password', 'create', 'admin', 'rest-gate-e2e', '--porcelain',
        ] ) ).trim();

        // Application Password for the Authorization-header half of the Apache lane.
        // Created for the ADMIN, because the assertion is that a non-gated read
        // authenticates — not that a subscriber can read users.
        appPasswordAuth = 'Basic ' + Buffer.from( `admin:${ appPasswordRaw }` ).toString( 'base64' );
    } );

    test.afterAll( async () => {
        await removeNonceMuPlugin();
        // The probe target is left in place: deleting a user is gated on this surface
        // too, and it is a subscriber owned by the fixture mu-plugin, removed with it.
        await wpCli( [ 'wp', 'user', 'application-password', 'delete', 'admin', '--all' ] );
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
     * REST-01: #213 — the password-change rule matched only PUT/PATCH, so a POST
     * slipped past it. The rule's own `methods` now lists POST; this test is the
     * behavioural check that it does, and does not restate why core routes it that
     * way — that reasoning lives in `Action_Registry`'s own comment, next to the
     * rule, where it can be checked against the code it describes.
     */
    test( 'REST-01: POST /wp/v2/users/{id} changing a password is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', `wp/v2/users/${ targetId }`, {
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
        // /me must stay pointed at the admin — the route IS the thing under test
        // (core's update_current_item). Made safe on regression by submitting the
        // password the admin already has: `user.change_password` gates on key
        // PRESENCE (`array_key_exists( 'password', … )`, no value comparison), so the
        // gate still fires, while a regression writes the value that is already set.
        const result = await restRequest( page, 'POST', 'wp/v2/users/me', {
            password: WP_PASSWORD,
        } );

        expectSudoRequired( result, 'a REST password change on /me' );
    } );

    /**
     * REST-03: #214 — user.change_email, the pivot toward a password-reset takeover.
     */
    test( 'REST-03: POST /wp/v2/users/{id} changing an email is gated', async ( {
        page,
    } ) => {
        const result = await restRequest( page, 'POST', `wp/v2/users/${ targetId }`, {
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
        const result = await restRequest( page, 'POST', `wp/v2/users/${ targetId }`, {
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
     *
     * WHY THIS ROUTE, AND WHY IT IS NOT INTERCHANGEABLE. The write sets `email` back
     * to its existing value, so it must still trip the gate to be a control at all.
     * It does, because `options.critical` matches on **key presence**
     * (`array_key_exists`) with no value comparison — a no-op write is still a gated
     * write on `/wp/v2/settings`.
     *
     * The identical trick aimed at `/wp/v2/users/{id}` would be **ungated**:
     * `user.change_email` is *difference*-matched via
     * `Action_Registry::email_change_differs()`, which compares the submitted address
     * against the stored one. Writing the same value back there matches nothing, the
     * request passes, and the control would report success no matter what the gate
     * did — proving nothing while looking green.
     *
     * So this control is one route away from vacuous. If it is ever moved, the
     * replacement must be key-matched, not difference-matched.
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
     * REST-09: Application Password authentication over the `Authorization` header.
     *
     * This is the half of #273's Apache lane that cookie auth cannot cover. The lane
     * exists because `mod_rewrite`/`mod_headers` can silently drop `Authorization`
     * before PHP sees it — a server-layer failure invisible to every other test here,
     * and one that would disable App-Password auth entirely while the gate itself
     * looked healthy.
     *
     * Deliberately a NON-gated read: the assertion is that the credential survives the
     * hop and authenticates. Gating behaviour for App-Password callers is a policy
     * question covered by `tests/Integration/RestGatingTest.php`, not a routing one.
     */
    test( 'REST-09: Application Password auth survives the Authorization header', async ( {
        page,
    } ) => {
        const result = await page.evaluate( async ( auth ) => {
            const response = await fetch( '/wp-json/wp/v2/users/me?context=edit', {
                headers: { Authorization: auth as string },
                // Cookies would authenticate this regardless and mask a dropped header.
                credentials: 'omit',
            } );
            let body: unknown = null;
            try {
                body = await response.json();
            } catch {
                body = null;
            }
            return { status: response.status, body };
        }, appPasswordAuth );

        expect(
            result.status,
            'the Authorization header must reach PHP — a 401 here means the server dropped it'
        ).toBe( 200 );
        expect(
            ( result.body as { slug?: string } )?.slug,
            'the authenticated identity must be the admin the app password belongs to'
        ).toBe( 'admin' );
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
