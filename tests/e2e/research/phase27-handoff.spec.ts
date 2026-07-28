import { expect, test } from '@playwright/test';
import { request as httpRequest } from 'node:http';

import { startPhase27ResearchServer } from '../fixtures/phase27-research-server';

function postRaw(
    serverUrl: string,
    headers: Record<string, string>,
    chunks: Buffer[],
): Promise<{
    body: string;
    headers: Record<string, string | string[]>;
    status: number;
}> {
    const url = new URL('/candidate-upload-effect', serverUrl);

    return new Promise((resolve, reject) => {
        const request = httpRequest(
            {
                headers,
                hostname: url.hostname,
                method: 'POST',
                path: url.pathname,
                port: url.port,
            },
            (response) => {
                const body: Buffer[] = [];
                response.on('data', (chunk: Buffer) => body.push(chunk));
                response.on('end', () =>
                    resolve({
                        body: Buffer.concat(body).toString('utf8'),
                        headers: response.headers,
                        status: response.statusCode ?? 0,
                    }),
                );
            },
        );
        request.on('error', reject);
        for (const chunk of chunks) {
            request.write(chunk);
        }
        request.end();
    });
}

test.describe( 'Phase 27 same-document modal negative control', () => {
    test( 'compromised parent script can read and drive the approval ceremony', async ( {
        page,
    } ) => {
        const server = await startPhase27ResearchServer();

        try {
            await page.goto( server.url + '/same-document' );

            await page.getByRole( 'button', { name: 'Upload plugin' } ).click();
            await page.getByLabel( 'Password' ).fill( 'victim-secret' );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'read',
                            value: 'victim-secret',
                        },
                        {
                            operation: 'invoke',
                            value: 'core/upload-extension-package',
                        },
                    ]),
                );
        } finally {
            await server.close();
        }
    } );
} );

test.describe( 'Phase 27 noopener popup return-channel probe', () => {
    test( 'popup has no opener but a same-origin return bearer reaches compromised parent script', async ( {
        page,
    } ) => {
        const server = await startPhase27ResearchServer();

        try {
            await page.goto( server.url + '/popup-parent' );

            const popupPromise = page.waitForEvent( 'popup' );
            await page
                .getByRole( 'button', { name: 'Open trusted confirmation' } )
                .click();
            const popup = await popupPromise;

            await popup.getByLabel( 'Password' ).fill( 'victim-secret' );
            await popup
                .getByRole( 'button', { name: 'Approve exact action' } )
                .click();

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'popup-opener',
                            value: 'null',
                        },
                        {
                            operation: 'authority-read',
                            value: 'phase27-action-bearer',
                        },
                        {
                            operation: 'redeem',
                            value: 'core/upload-extension-package',
                        },
                    ]),
                );
        } finally {
            await server.close();
        }
    } );
} );

test.describe( 'Phase 27 isolated confirmation and self-redemption', () => {
    test( 'isolated surface executes one immutable server-held intent without returning authority', async ( {
        page,
    } ) => {
        const server = await startPhase27ResearchServer();

        try {
            await page.goto( server.url + '/isolated-parent' );

            const popupPromise = page.waitForEvent( 'popup' );
            await page
                .getByRole( 'button', { name: 'Review exact file write' } )
                .click();
            const popup = await popupPromise;

            await expect( popup.getByTestId( 'action' ) ).toHaveText(
                'Write sample-plugin/sample.php',
            );
            await expect( popup.getByTestId( 'digest' ) ).toHaveText(
                'sha256:server-held-proposed-bytes',
            );

            await popup.getByLabel( 'Password' ).fill( 'victim-secret' );
            await popup
                .getByRole( 'button', { name: 'Confirm and write file' } )
                .click();
            await expect( popup.getByRole( 'status' ) ).toHaveText( 'Written' );
            await expect( popup.getByTestId( 'response-contract' ) ).toHaveText(
                '{"status":"executed"}',
            );

            const replay = await popup.request.post(
                server.url + '/confirm-and-redeem',
                {
                    data: {
                        intent: 'file-write-1',
                        password: 'victim-secret',
                    },
                },
            );
            expect( replay.status() ).toBe( 409 );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'parent-handle',
                            value: 'null',
                        },
                        {
                            operation: 'unauthorized-redemption-blocked',
                            value: 'file-write-1',
                        },
                        {
                            operation: 'mutation-blocked',
                            value: 'attacker-plugin/attacker.php',
                        },
                        {
                            operation: 'effect',
                            value: 'sample-plugin/sample.php',
                        },
                        {
                            operation: 'replay-blocked',
                            value: 'file-write-1',
                        },
                    ]),
                );

            expect(
                server
                    .observations()
                    .filter((item) => item.operation === 'effect'),
            ).toHaveLength( 1 );
        } finally {
            await server.close();
        }
    } );

    test( 'compromised parent can strip noopener before the application opens the popup', async ( {
        page,
    } ) => {
        const server = await startPhase27ResearchServer();

        try {
            await page.goto( server.url + '/tampered-popup-parent' );

            const popupPromise = page.waitForEvent( 'popup' );
            await page
                .getByRole( 'button', { name: 'Review exact file write' } )
                .click();
            const popup = await popupPromise;

            await popup.getByLabel( 'Password' ).fill( 'victim-secret' );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'parent-handle',
                            value: 'present',
                        },
                        {
                            operation: 'isolated-credential-read',
                            value: 'victim-secret',
                        },
                        {
                            operation: 'effect',
                            value: 'sample-plugin/sample.php',
                        },
                    ]),
                );
        } finally {
            await server.close();
        }
    } );
} );

test.describe( 'Phase 27 top-level replacement and self-redemption', () => {
    test( 'replacement destroys confined parent script before credential entry and executes once', async ( {
        page,
    } ) => {
        const server = await startPhase27ResearchServer();

        try {
            await page.goto( server.url + '/top-level-parent' );
            await page
                .getByRole( 'button', { name: 'Review exact file write' } )
                .click();
            await page.waitForURL(
                server.url + '/top-level-confirm?intent=file-write-1',
            );

            await expect( page.getByTestId( 'action' ) ).toHaveText(
                'Write sample-plugin/sample.php',
            );
            await expect( page.getByTestId( 'digest' ) ).toHaveText(
                'sha256:server-held-proposed-bytes',
            );

            await page.getByLabel( 'Password' ).fill( 'victim-secret' );
            await page.waitForTimeout( 250 );

            expect( server.observations() ).toEqual(
                expect.arrayContaining( [
                    {
                        operation: 'parent-unloaded',
                        value: 'top-level-parent',
                    },
                ]),
            );
            expect(
                server
                    .observations()
                    .some(
                        ( item ) =>
                            item.operation === 'top-level-credential-read' ||
                            item.operation === 'effect',
                    ),
            ).toBe( false );

            await page
                .getByRole( 'button', { name: 'Confirm and write file' } )
                .click();
            await expect( page.getByRole( 'status' ) ).toHaveText( 'Written' );
            await expect( page.getByTestId( 'response-contract' ) ).toHaveText(
                '{"status":"executed"}',
            );

            const replay = await page.request.post(
                server.url + '/confirm-and-redeem',
                {
                    data: {
                        intent: 'file-write-1',
                        password: 'victim-secret',
                    },
                },
            );
            expect( replay.status() ).toBe( 409 );

            expect(
                server
                    .observations()
                    .filter((item) => item.operation === 'effect'),
            ).toEqual( [
                {
                    operation: 'effect',
                    value: 'sample-plugin/sample.php',
                },
            ] );
        } finally {
            await server.close();
        }
    } );

    test( 'confined parent script can counterfeit the trusted path without navigating', async ( {
        page,
    } ) => {
        const server = await startPhase27ResearchServer();

        try {
            await page.goto( server.url + '/counterfeit-top-level-parent' );
            await page
                .getByRole( 'button', { name: 'Review exact file write' } )
                .click();
            await page.waitForURL(
                server.url + '/top-level-confirm?intent=file-write-1',
            );

            await page.getByLabel( 'Password' ).fill( 'victim-secret' );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'counterfeit-credential-read',
                            value: 'victim-secret',
                        },
                        {
                            operation: 'effect',
                            value: 'sample-plugin/sample.php',
                        },
                    ]),
                );
            expect(
                server
                    .observations()
                    .some((item) => item.operation === 'parent-unloaded'),
            ).toBe( false );
        } finally {
            await server.close();
        }
    } );
} );

test.describe( 'Phase 27 copied-cookie candidate', () => {
    test( 'preflight withholds descriptors by capability and rate-limits authorized probing', async ( {
        browser,
    } ) => {
        const fileServer = await startPhase27ResearchServer();
        const uploadServer = await startPhase27ResearchServer();

        async function exercisePreflight(
            serverUrl: string,
            pagePath: string,
            endpoint: string,
            body: Record<string, string>,
        ) {
            const anonymous = await browser.newContext();
            const lowPrivilege = await browser.newContext();
            const authorizedA = await browser.newContext();
            const authorizedB = await browser.newContext();

            try {
                await lowPrivilege.addCookies( [
                    {
                        name: 'wp_auth',
                        value: 'low-privilege-session',
                        url: serverUrl,
                    },
                ] );
                for ( const context of [ authorizedA, authorizedB ] ) {
                    await context.addCookies( [
                        {
                            name: 'wp_auth',
                            value: 'copied-login-session',
                            url: serverUrl,
                        },
                    ] );
                }

                const anonymousPage = await anonymous.newPage();
                const lowPrivilegePage = await lowPrivilege.newPage();
                const pageA = await authorizedA.newPage();
                const pageB = await authorizedB.newPage();
                for ( const page of [
                    anonymousPage,
                    lowPrivilegePage,
                    pageA,
                    pageB,
                ] ) {
                    await page.goto( serverUrl + pagePath );
                }

                for ( const page of [ anonymousPage, lowPrivilegePage ] ) {
                    const result = await page.evaluate(
                        async ( request ) => {
                            const response = await fetch( request.endpoint, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify( request.body ),
                            } );
                            return {
                                body: await response.text(),
                                status: response.status,
                            };
                        },
                        { body, endpoint },
                    );
                    expect( result ).toEqual( {
                        body: 'Forbidden',
                        status: 403,
                    } );
                }

                const initial = await pageA.evaluate(
                    async ( request ) => {
                        const response = await fetch( request.endpoint, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify( request.body ),
                        } );
                        return response.status;
                    },
                    { body, endpoint },
                );
                expect( initial ).toBe( 200 );

                const probeStatuses: number[] = [];
                for ( let probe = 0; probe < 3; probe++ ) {
                    probeStatuses.push(
                        await pageB.evaluate(
                            async ( request ) => {
                                const response = await fetch(request.endpoint, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                        },
                                        body: JSON.stringify( request.body ),
                                });
                                return response.status;
                            },
                            { body, endpoint },
                        ),
                    );
                }
                expect(probeStatuses, 'P27-PREFLIGHT-RATE').toEqual([
                    200, 200, 429,
                ]);
            } finally {
                await Promise.allSettled( [
                    anonymous.close(),
                    lowPrivilege.close(),
                    authorizedA.close(),
                    authorizedB.close(),
                ] );
            }
        }

        try {
            await exercisePreflight(
                fileServer.url,
                '/copied-cookie-candidate',
                '/candidate-preflight',
                {
                    action: 'core/write-extension-file',
                    digest: 'sha256:server-held-proposed-bytes',
                    target: 'sample-plugin/sample.php',
                },
            );
            await exercisePreflight(
                uploadServer.url,
                '/copied-cookie-upload-candidate',
                '/candidate-upload-preflight',
                {
                    digest: '17f75876c4a7e94e98d23d54290a11c43d386ea46977a387b6aa249a2e930b01',
                    kind: 'plugin',
                },
            );
        } finally {
            await Promise.allSettled( [
                fileServer.close(),
                uploadServer.close(),
            ] );
        }
    } );

    test( 'browser-bound exact-action approval cannot be redeemed from a cloned auth-cookie context', async ( {
        browser,
    } ) => {
        const server = await startPhase27ResearchServer();
        const browserA = await browser.newContext();
        const browserB = await browser.newContext();

        try {
            for ( const context of [ browserA, browserB ] ) {
                await context.addCookies( [
                    {
                        name: 'wp_auth',
                        value: 'copied-login-session',
                        url: server.url,
                    },
                ] );
            }

            const pageA = await browserA.newPage();
            const pageB = await browserB.newPage();
            await pageA.goto( server.url + '/copied-cookie-candidate' );
            await pageB.goto( server.url + '/copied-cookie-candidate' );

            await pageA
                .getByRole( 'button', { name: 'Prepare exact write' } )
                .click();
            await expect( pageA.getByTestId( 'action' ) ).toHaveText(
                'Write sample-plugin/sample.php',
            );
            await expect( pageA.getByTestId( 'digest' ) ).toHaveText(
                'sha256:server-held-proposed-bytes',
            );
            const intentA = await pageA.getByTestId( 'intent' ).textContent();
            expect( intentA ).not.toBeNull();

            const browserBPreflight = await pageB.evaluate( async () => {
                const response = await fetch( '/candidate-preflight', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        action: 'core/write-extension-file',
                        digest: 'sha256:server-held-proposed-bytes',
                        target: 'sample-plugin/sample.php',
                    } ),
                } );
                return {
                    body: response.ok ? await response.json() : {},
                    status: response.status,
                };
            } );
            expect( browserBPreflight.status ).toBe( 200 );
            expect( browserBPreflight.body.id ).not.toBe( intentA );

            const wrongPasswordApproval = await pageA.evaluate(
                async (intentId) => {
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        password: '',
                    } ),
                } );
                return response.status;
                },
                intentA,
            );
            expect( wrongPasswordApproval ).toBe( 403 );

            const browserBApproval = await pageB.evaluate( async ( intentId ) => {
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            }, intentA );
            expect( browserBApproval ).toBe( 403 );

            const wrongIntentApproval = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'attacker-file-write',
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            } );
            expect( wrongIntentApproval ).toBe( 403 );

            await pageA.getByLabel( 'Password' ).fill( 'victim-secret' );
            await pageA
                .getByRole( 'button', { name: 'Approve exact write' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Approved' );
            await expect(
                pageA.getByTestId('approval-response-contract'),
            ).toHaveText( '{"status":"approved"}' );

            const browserBRedemption = await pageB.evaluate(
                async (intentId) => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        target: 'sample-plugin/sample.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
                },
                intentA,
            );
            expect( browserBRedemption ).toBe( 403 );

            const mutation = await pageA.evaluate( async ( intentId ) => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        target: 'attacker-plugin/attacker.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
            }, intentA );
            expect( mutation ).toBe( 409 );

            await pageA
                .getByRole( 'button', { name: 'Submit exact write once' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Written' );

            const replay = await pageA.evaluate( async ( intentId ) => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        target: 'sample-plugin/sample.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
            }, intentA );
            expect( replay ).toBe( 409 );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'clone-reauth-blocked',
                            value: 'attacker-file-write',
                        },
                        {
                            operation: 'clone-redemption-blocked',
                            value: intentA,
                        },
                        {
                            operation: 'candidate-mutation-blocked',
                            value: 'attacker-plugin/attacker.php',
                        },
                        {
                            operation: 'candidate-effect',
                            value: 'sample-plugin/sample.php',
                        },
                        {
                            operation: 'candidate-replay-blocked',
                            value: intentA,
                        },
                    ]),
                );
            expect(
                server
                    .observations()
                    .filter((item) => item.operation === 'candidate-effect'),
            ).toHaveLength( 1 );
        } finally {
            await Promise.allSettled([browserA.close(), browserB.close()]);
            await server.close();
        }
    } );

    test( 'independent browsers sharing one login session create separate immutable file intents', async ( {
        browser,
    } ) => {
        const server = await startPhase27ResearchServer();
        const browserA = await browser.newContext();
        const browserB = await browser.newContext();

        try {
            for ( const context of [ browserA, browserB ] ) {
                await context.addCookies( [
                    {
                        name: 'wp_auth',
                        value: 'copied-login-session',
                        url: server.url,
                    },
                ] );
            }

            const pageA = await browserA.newPage();
            const pageB = await browserB.newPage();
            await pageA.goto( server.url + '/copied-cookie-candidate' );
            await pageB.goto( server.url + '/copied-cookie-candidate' );
            const bindingA = ( await browserA.cookies() ).find(
                (cookie) => cookie.name === '__Host-phase27_binding',
            );

            expect( bindingA?.secure, 'P27-BIND-HOST-SECURE' ).toBe( true );
            expect( bindingA?.httpOnly, 'P27-BIND-HOST-HTTPONLY' ).toBe( true );
            expect( bindingA?.path, 'P27-BIND-HOST-PATH' ).toBe( '/' );
            expect( bindingA?.domain, 'P27-BIND-HOST-HOSTONLY' ).toBe(
                '127.0.0.1',
            );
            expect(
                await pageA.evaluate( () => document.cookie ),
                'P27-BIND-NOT-SCRIPT-READABLE',
            ).not.toContain( '__Host-phase27_binding' );

            const preflight = async (
                page: typeof pageA,
            ): Promise< { body: Record< string, string >; status: number } > =>
                page.evaluate( async () => {
                    const response = await fetch( '/candidate-preflight', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify( {
                            action: 'core/write-extension-file',
                            digest: 'sha256:server-held-proposed-bytes',
                            target: 'sample-plugin/sample.php',
                        } ),
                    } );

                    return {
                        body:
                            response.status === 200
                                ? await response.json()
                                : {},
                        status: response.status,
                    };
                } );

            const intentA = await preflight( pageA );
            const intentB = await preflight( pageB );

            expect(intentA.status, 'P27-PREFLIGHT-A-CREATES-INTENT').toBe(200);
            expect(
                intentB.status,
                'P27-PREFLIGHT-B-CREATES-INDEPENDENT-INTENT',
            ).toBe( 200 );
            expect(intentB.body.id, 'P27-PREFLIGHT-INDEPENDENT-IDS').not.toBe(
                intentA.body.id,
            );

            const wrongIntentApproval = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'missing-intent',
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            } );

            expect( wrongIntentApproval, 'P27-APPROVE-EXACT-A' ).toBe( 403 );

            const approvalA = await pageA.evaluate( async ( intentId ) => {
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            }, intentA.body.id );

            expect(approvalA, 'P27-PREFLIGHT-B-DOES-NOT-OVERWRITE-A').toBe(200);

            const wrongIntentEffect = await pageA.evaluate(
                async ( { digest, target } ) => {
                    const response = await fetch( '/candidate-effect', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify( {
                            digest,
                            intent: 'missing-intent',
                            target,
                        } ),
                    } );
                    return response.status;
                },
                {
                    digest: intentA.body.digest,
                    target: intentA.body.target,
                },
            );

            expect( wrongIntentEffect, 'P27-EFFECT-EXACT-A' ).toBe( 403 );

            const effectA = await pageA.evaluate(
                async ( { digest, intentId, target } ) => {
                    const response = await fetch( '/candidate-effect', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify( {
                            digest,
                            intent: intentId,
                            target,
                        } ),
                    } );
                    return response.status;
                },
                {
                    digest: intentA.body.digest,
                    intentId: intentA.body.id,
                    target: intentA.body.target,
                },
            );

            expect( effectA, 'P27-EFFECT-A-SUCCEEDS' ).toBe( 204 );
        } finally {
            await Promise.allSettled([browserA.close(), browserB.close()]);
            await server.close();
        }
    } );

    test( 'approval throttling survives intent and binding rotation and exposes victim lockout', async ( {
        browser,
    } ) => {
        const server = await startPhase27ResearchServer();
        const browserA = await browser.newContext();
        const browserB = await browser.newContext();

        try {
            for ( const context of [ browserA, browserB ] ) {
                await context.addCookies( [
                    {
                        name: 'wp_auth',
                        value: 'copied-login-session',
                        url: server.url,
                    },
                ] );
            }

            const pageA = await browserA.newPage();
            const pageB = await browserB.newPage();
            await pageA.goto( server.url + '/copied-cookie-candidate' );
            await pageB.goto( server.url + '/copied-cookie-candidate' );

            const createIntent = ( page: typeof pageA ) =>
                page.evaluate( async () => {
                    const response = await fetch( '/candidate-preflight', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify( {
                            action: 'core/write-extension-file',
                            digest: 'sha256:server-held-proposed-bytes',
                            target: 'sample-plugin/sample.php',
                        } ),
                    } );
                    return response.json();
                } );
            const intentA = await createIntent( pageA );
            const intentB = await createIntent( pageB );

            // The same browser binding covers both approval endpoints, so an
            // upload intent prepared here reaches /candidate-upload-approve's
            // factor check rather than stopping at its binding check.
            const uploadA = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-upload-preflight', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        digest: '0'.repeat( 64 ),
                        kind: 'plugin',
                    } ),
                } );
                return response.json();
            } );

            const fail = ( page: typeof pageA, intent: string ) =>
                page.evaluate( async ( intentId ) => {
                    const response = await fetch( '/candidate-approve', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify( {
                            intent: intentId,
                            password: 'wrong',
                        } ),
                    } );
                    return response.status;
                }, intent );

            const failUpload = ( page: typeof pageA, intent: string ) =>
                page.evaluate( async ( intentId ) => {
                    const response = await fetch( '/candidate-upload-approve', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify( {
                            intent: intentId,
                            password: 'wrong',
                        } ),
                    } );
                    return response.status;
                }, intent );

            // Interleaved on purpose. The third element proves an upload
            // failure spends the shared budget; the fourth proves the upload
            // endpoint is itself refused once that budget is gone. Exercising
            // only /candidate-approve leaves the upload twin unbudgeted, which
            // is how 500 wrong-password upload approvals previously ran clean.
            expect(
                [
                    await fail( pageA, intentA.id ),
                    await fail( pageB, intentB.id ),
                    await failUpload( pageA, uploadA.id ),
                    await failUpload( pageA, uploadA.id ),
                ],
                'P27-APPROVE-ACCOUNT-RATE',
            ).toEqual( [ 403, 403, 403, 429 ] );

            expect(
                await pageA.evaluate( async ( intentId ) => {
                    const response = await fetch( '/candidate-approve', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify( {
                            intent: intentId,
                            password: 'victim-secret',
                        } ),
                    } );
                    return response.status;
                }, intentA.id ),
                'P27-APPROVE-VICTIM-LOCKOUT-BOUNDARY',
            ).toBe( 429 );
        } finally {
            await Promise.allSettled([browserA.close(), browserB.close()]);
            await server.close();
        }
    } );

    test( 'a full cookie-state clone can redeem ambient browser approval', async ( {
        browser,
    } ) => {
        const server = await startPhase27ResearchServer();
        const browserA = await browser.newContext();
        const browserClone = await browser.newContext();

        try {
            await browserA.addCookies( [
                {
                    name: 'wp_auth',
                    value: 'copied-login-session',
                    url: server.url,
                },
            ] );

            const pageA = await browserA.newPage();
            await pageA.goto( server.url + '/copied-cookie-candidate' );
            await pageA
                .getByRole( 'button', { name: 'Prepare exact write' } )
                .click();
            await expect( pageA.getByTestId( 'action' ) ).toHaveText(
                'Write sample-plugin/sample.php',
            );
            const intentA = await pageA.getByTestId( 'intent' ).textContent();
            expect( intentA ).not.toBeNull();

            const binding = ( await browserA.cookies() ).find(
                (cookie) => cookie.name === '__Host-phase27_binding',
            );
            expect( binding ).toBeDefined();

            await browserClone.addCookies( [
                {
                    name: 'wp_auth',
                    value: 'copied-login-session',
                    url: server.url,
                },
                {
                    domain: binding?.domain ?? '127.0.0.1',
                    httpOnly: true,
                    name: '__Host-phase27_binding',
                    path: '/',
                    sameSite: 'Strict',
                    secure: true,
                    value: binding?.value ?? '',
                },
            ] );

            const clonePage = await browserClone.newPage();
            await clonePage.goto( server.url + '/copied-cookie-candidate' );

            await pageA.getByLabel( 'Password' ).fill( 'victim-secret' );
            await pageA
                .getByRole( 'button', { name: 'Approve exact write' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Approved' );

            const cloneRedemption = await clonePage.evaluate(
                async (intentId) => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        target: 'sample-plugin/sample.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
                },
                intentA,
            );

            expect( cloneRedemption ).toBe( 204 );
            expect(
                server
                    .observations()
                    .filter((item) => item.operation === 'candidate-effect'),
            ).toHaveLength( 1 );
        } finally {
            await Promise.allSettled([browserA.close(), browserClone.close()]);
            await server.close();
        }
    });

    test('preflight failures do not reveal target validity or resolve a target before authorization', async ({
        browser,
    }) => {
        const server = await startPhase27ResearchServer();
        const context = await browser.newContext();

        try {
            const page = await context.newPage();
            await page.goto(server.url + '/copied-cookie-candidate');
            const preflight = (target: string) =>
                page.evaluate(async (requestedTarget) => {
                    const response = await fetch('/candidate-preflight', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'core/write-extension-file',
                            digest: 'sha256:server-held-proposed-bytes',
                            target: requestedTarget,
                        }),
                    });
                    return {
                        body: await response.text(),
                        cacheControl: response.headers.get('cache-control'),
                        status: response.status,
                    };
                }, target);

            const validTarget = await preflight('sample-plugin/sample.php');
            const invalidTarget = await preflight(
                'attacker-plugin/attacker.php',
            );

            expect(validTarget, 'P27-PREFLIGHT-ORACLE-EQUIVALENT').toEqual(
                invalidTarget,
            );
            expect(validTarget).toEqual({
                body: 'Forbidden',
                cacheControl: 'no-store',
                status: 403,
            });
            expect(
                server
                    .observations()
                    .filter(
                        (item) =>
                            item.operation ===
                            'preflight-target-resolver-called',
                    ),
                'P27-PREFLIGHT-NO-UNAUTHORIZED-TARGET-RESOLUTION',
            ).toHaveLength(0);
        } finally {
            await Promise.allSettled([context.close(), server.close()]);
        }
    });

    test('upload body limits reject declared length before reading and stop chunked input at cap plus one byte', async () => {
        const server = await startPhase27ResearchServer();
        const cap = 1024;

        try {
            const declared = await postRaw(
                server.url,
                {
                    'Content-Length': String(cap + 1),
                    'Content-Type': 'application/octet-stream',
                },
                [Buffer.alloc(cap + 1)],
            );
            expect(
                declared,
                'P27-UPLOAD-DECLARED-LENGTH-PREFLIGHT',
            ).toMatchObject({
                body: 'Payload Too Large',
                headers: { 'cache-control': 'no-store' },
                status: 413,
            });

            const chunked = await postRaw(
                server.url,
                { 'Content-Type': 'application/octet-stream' },
                [Buffer.alloc(cap), Buffer.alloc(1)],
            );
            expect(chunked, 'P27-UPLOAD-CHUNKED-CAP-PLUS-ONE').toMatchObject({
                body: 'Payload Too Large',
                headers: { 'cache-control': 'no-store' },
                status: 413,
            });
            expect(
                server.observations(),
                'P27-UPLOAD-BYTES-READ-EVIDENCE',
            ).toEqual(
                expect.arrayContaining([
                    { operation: 'upload-bytes-read', value: '0' },
                    { operation: 'upload-bytes-read', value: String(cap + 1) },
                ]),
            );
        } finally {
            await server.close();
        }
    } );

    test( 'browser-bound package approval sends bytes once and rejects clone, mutation, and replay', async ( {
        browser,
    } ) => {
        const server = await startPhase27ResearchServer();
        const browserA = await browser.newContext();
        const browserB = await browser.newContext();
        const trustedPackage = Buffer.from( [
            0x50, 0x4b, 0x03, 0x04, 0x00, 0xff, 0x80, 0x41,
        ] );
        const mutatedPackage = Buffer.from( [
            0x50, 0x4b, 0x03, 0x04, 0x00, 0xff, 0x80, 0x42,
        ] );

        try {
            for ( const context of [ browserA, browserB ] ) {
                await context.addCookies( [
                    {
                        name: 'wp_auth',
                        value: 'copied-login-session',
                        url: server.url,
                    },
                ] );
            }

            const pageA = await browserA.newPage();
            const pageB = await browserB.newPage();
            await pageA.goto( server.url + '/copied-cookie-upload-candidate' );
            await pageB.goto( server.url + '/copied-cookie-upload-candidate' );

            await pageA.getByLabel( 'Plugin package' ).setInputFiles( {
                name: 'sample-plugin.zip',
                mimeType: 'application/zip',
                buffer: trustedPackage,
            } );
            await pageA
                .getByRole( 'button', { name: 'Prepare exact upload' } )
                .click();
            await expect( pageA.getByTestId( 'action' ) ).toHaveText(
                'Upload one plugin package',
            );
            await expect( pageA.getByTestId( 'digest' ) ).toHaveText(
                'sha256:17f75876c4a7e94e98d23d54290a11c43d386ea46977a387b6aa249a2e930b01',
            );
            const intentA = await pageA.getByTestId( 'intent' ).textContent();
            expect( intentA ).not.toBeNull();

            const rawUploadStatus = await pageA.evaluate(
                async ( { bytes, intentId } ) => {
                    const response = await fetch( '/candidate-upload-effect', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/octet-stream',
                            'X-Phase27-Intent': intentId,
                        },
                        body: new Uint8Array( bytes ),
                    } );
                    return response.status;
                },
                { bytes: Array.from(trustedPackage), intentId: intentA },
            );
            expect(rawUploadStatus, 'P27-UPLOAD-REQUIRES-MULTIPART').toBe(400);

            const duplicateUploadStatus = await pageA.evaluate(
                async ( { bytes, intentId } ) => {
                    const file = new File(
                        [ new Uint8Array( bytes ) ],
                        'sample-plugin.zip',
                        {
                            type: 'application/zip',
                        },
                    );
                    const form = new FormData();
                    form.append( 'package', file );
                    form.append( 'package', file );
                    const response = await fetch( '/candidate-upload-effect', {
                        method: 'POST',
                        headers: { 'X-Phase27-Intent': intentId },
                        body: form,
                    } );
                    return response.status;
                },
                { bytes: Array.from(trustedPackage), intentId: intentA },
            );
            expect(
                duplicateUploadStatus,
                'P27-UPLOAD-REJECTS-DUPLICATE-PART',
            ).toBe( 400 );

            const browserBPreflight = await pageB.evaluate( async () => {
                const response = await fetch( '/candidate-upload-preflight', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        digest: '0'.repeat( 64 ),
                        kind: 'plugin',
                    } ),
                } );
                return {
                    body: response.ok ? await response.json() : {},
                    status: response.status,
                };
            } );
            expect( browserBPreflight.status ).toBe( 200 );
            expect( browserBPreflight.body.id ).not.toBe( intentA );

            const wrongPasswordApproval = await pageA.evaluate(
                async (intentId) => {
                const response = await fetch( '/candidate-upload-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        password: '',
                    } ),
                } );
                return response.status;
                },
                intentA,
            );
            expect( wrongPasswordApproval ).toBe( 403 );

            const browserBApproval = await pageB.evaluate( async ( intentId ) => {
                const response = await fetch( '/candidate-upload-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: intentId,
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            }, intentA );
            expect( browserBApproval ).toBe( 403 );

            const wrongIntentApproval = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-upload-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'attacker-upload',
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            } );
            expect( wrongIntentApproval ).toBe( 403 );

            await pageA.getByLabel( 'Password' ).fill( 'victim-secret' );
            await pageA
                .getByRole( 'button', { name: 'Approve exact upload' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Approved' );

            const browserBRedemption = await pageB.evaluate(
                async ({ bytes, intentId }) => {
                const form = new FormData();
                form.append(
                    'package',
                    new File( [ new Uint8Array( bytes ) ], 'sample-plugin.zip', {
                        type: 'application/zip',
                        }),
                );
                const response = await fetch( '/candidate-upload-effect', {
                    method: 'POST',
                    headers: {
                        'X-Phase27-Intent': intentId,
                    },
                    body: form,
                } );
                return response.status;
                },
                { bytes: Array.from(trustedPackage), intentId: intentA },
            );
            expect( browserBRedemption ).toBe( 403 );

            await pageA.getByLabel( 'Plugin package' ).setInputFiles( {
                name: 'sample-plugin.zip',
                mimeType: 'application/zip',
                buffer: mutatedPackage,
            } );
            await pageA
                .getByRole( 'button', { name: 'Upload approved package once' } )
                .click();
            await expect(
                pageA.getByRole( 'status' ),
                'P27-UPLOAD-CONTENT-HASH',
            ).toHaveText( 'Rejected' );

            await pageA.getByLabel( 'Plugin package' ).setInputFiles( {
                name: 'sample-plugin.zip',
                mimeType: 'application/zip',
                buffer: trustedPackage,
            } );
            await pageA
                .getByRole( 'button', { name: 'Upload approved package once' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Uploaded' );

            const replay = await pageA.evaluate(
                async ({ bytes, intentId }) => {
                const form = new FormData();
                form.append(
                    'package',
                    new File( [ new Uint8Array( bytes ) ], 'sample-plugin.zip', {
                        type: 'application/zip',
                        }),
                );
                const response = await fetch( '/candidate-upload-effect', {
                    method: 'POST',
                    headers: {
                        'X-Phase27-Intent': intentId,
                    },
                    body: form,
                } );
                return response.status;
                },
                { bytes: Array.from(trustedPackage), intentId: intentA },
            );
            expect( replay ).toBe( 409 );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'upload-clone-blocked',
                            value: intentA,
                        },
                        {
                            operation: 'upload-mutation-blocked',
                            value: intentA,
                        },
                        {
                            operation: 'upload-effect',
                            value: '17f75876c4a7e94e98d23d54290a11c43d386ea46977a387b6aa249a2e930b01',
                        },
                        {
                            operation: 'upload-part-digest',
                            value: '17f75876c4a7e94e98d23d54290a11c43d386ea46977a387b6aa249a2e930b01',
                        },
                        {
                            operation: 'upload-replay-blocked',
                            value: intentA,
                        },
                    ]),
                );
            expect(
                server
                    .observations()
                    .filter((item) => item.operation === 'upload-effect'),
            ).toHaveLength( 1 );
            const envelopeDigest = server
                .observations()
                .find( ( item ) => item.operation === 'upload-envelope-digest' );
            const partDigest = server
                .observations()
                .find( ( item ) => item.operation === 'upload-part-digest' );
            expect(
                envelopeDigest?.value,
                'P27-UPLOAD-HASHES-PART-NOT-ENVELOPE',
            ).not.toBe( partDigest?.value );
        } finally {
            await Promise.allSettled([browserA.close(), browserB.close()]);
            await server.close();
        }
    } );
} );
