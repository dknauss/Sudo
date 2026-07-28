import { expect, test } from '@playwright/test';

import { startPhase27ResearchServer } from '../fixtures/phase27-research-server';

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
                    ] )
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
                    ] )
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
                'Write sample-plugin/sample.php'
            );
            await expect( popup.getByTestId( 'digest' ) ).toHaveText(
                'sha256:server-held-proposed-bytes'
            );

            await popup.getByLabel( 'Password' ).fill( 'victim-secret' );
            await popup
                .getByRole( 'button', { name: 'Confirm and write file' } )
                .click();
            await expect( popup.getByRole( 'status' ) ).toHaveText( 'Written' );
            await expect( popup.getByTestId( 'response-contract' ) ).toHaveText(
                '{"status":"executed"}'
            );

            const replay = await popup.request.post(
                server.url + '/confirm-and-redeem',
                {
                    data: {
                        intent: 'file-write-1',
                        password: 'victim-secret',
                    },
                }
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
                    ] )
                );

            expect(
                server
                    .observations()
                    .filter( ( item ) => item.operation === 'effect' )
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
                    ] )
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
                server.url + '/top-level-confirm?intent=file-write-1'
            );

            await expect( page.getByTestId( 'action' ) ).toHaveText(
                'Write sample-plugin/sample.php'
            );
            await expect( page.getByTestId( 'digest' ) ).toHaveText(
                'sha256:server-held-proposed-bytes'
            );

            await page.getByLabel( 'Password' ).fill( 'victim-secret' );
            await page.waitForTimeout( 250 );

            expect( server.observations() ).toEqual(
                expect.arrayContaining( [
                    {
                        operation: 'parent-unloaded',
                        value: 'top-level-parent',
                    },
                ] )
            );
            expect(
                server
                    .observations()
                    .some(
                        ( item ) =>
                            item.operation === 'top-level-credential-read' ||
                            item.operation === 'effect'
                    )
            ).toBe( false );

            await page
                .getByRole( 'button', { name: 'Confirm and write file' } )
                .click();
            await expect( page.getByRole( 'status' ) ).toHaveText( 'Written' );
            await expect( page.getByTestId( 'response-contract' ) ).toHaveText(
                '{"status":"executed"}'
            );

            const replay = await page.request.post(
                server.url + '/confirm-and-redeem',
                {
                    data: {
                        intent: 'file-write-1',
                        password: 'victim-secret',
                    },
                }
            );
            expect( replay.status() ).toBe( 409 );

            expect(
                server
                    .observations()
                    .filter( ( item ) => item.operation === 'effect' )
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
                server.url + '/top-level-confirm?intent=file-write-1'
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
                    ] )
                );
            expect(
                server.observations().some(
                    ( item ) => item.operation === 'parent-unloaded'
                )
            ).toBe( false );
        } finally {
            await server.close();
        }
    } );
} );

test.describe( 'Phase 27 copied-cookie candidate', () => {
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
                'Write sample-plugin/sample.php'
            );
            await expect( pageA.getByTestId( 'digest' ) ).toHaveText(
                'sha256:server-held-proposed-bytes'
            );

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
                return response.status;
            } );
            expect( browserBPreflight ).toBe( 409 );

            const wrongPasswordApproval = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-file-write-1',
                        password: '',
                    } ),
                } );
                return response.status;
            } );
            expect( wrongPasswordApproval ).toBe( 403 );

            const browserBApproval = await pageB.evaluate( async () => {
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-file-write-1',
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            } );
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
                pageA.getByTestId( 'approval-response-contract' )
            ).toHaveText( '{"status":"approved"}' );

            const browserBRedemption = await pageB.evaluate( async () => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-file-write-1',
                        target: 'sample-plugin/sample.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
            } );
            expect( browserBRedemption ).toBe( 403 );

            const mutation = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-file-write-1',
                        target: 'attacker-plugin/attacker.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
            } );
            expect( mutation ).toBe( 409 );

            await pageA
                .getByRole( 'button', { name: 'Submit exact write once' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Written' );

            const replay = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-file-write-1',
                        target: 'sample-plugin/sample.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
            } );
            expect( replay ).toBe( 409 );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'candidate-preflight-clone-blocked',
                            value: 'candidate-file-write-1',
                        },
                        {
                            operation: 'clone-reauth-blocked',
                            value: 'attacker-file-write',
                        },
                        {
                            operation: 'clone-redemption-blocked',
                            value: 'candidate-file-write-1',
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
                            value: 'candidate-file-write-1',
                        },
                    ] )
                );
            expect(
                server
                    .observations()
                    .filter(
                        ( item ) => item.operation === 'candidate-effect'
                    )
            ).toHaveLength( 1 );
        } finally {
            await Promise.allSettled( [
                browserA.close(),
                browserB.close(),
            ] );
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
                'Write sample-plugin/sample.php'
            );

            const binding = ( await browserA.cookies() ).find(
                ( cookie ) => cookie.name === 'phase27_binding'
            );
            expect( binding ).toBeDefined();

            await browserClone.addCookies( [
                {
                    name: 'wp_auth',
                    value: 'copied-login-session',
                    url: server.url,
                },
                {
                    httpOnly: true,
                    name: 'phase27_binding',
                    sameSite: 'Strict',
                    value: binding?.value ?? '',
                    url: server.url,
                },
            ] );

            const clonePage = await browserClone.newPage();
            await clonePage.goto( server.url + '/copied-cookie-candidate' );

            await pageA.getByLabel( 'Password' ).fill( 'victim-secret' );
            await pageA
                .getByRole( 'button', { name: 'Approve exact write' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Approved' );

            const cloneRedemption = await clonePage.evaluate( async () => {
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-file-write-1',
                        target: 'sample-plugin/sample.php',
                        digest: 'sha256:server-held-proposed-bytes',
                    } ),
                } );
                return response.status;
            } );

            expect( cloneRedemption ).toBe( 204 );
            expect(
                server
                    .observations()
                    .filter(
                        ( item ) => item.operation === 'candidate-effect'
                    )
            ).toHaveLength( 1 );
        } finally {
            await Promise.allSettled( [
                browserA.close(),
                browserClone.close(),
            ] );
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
                'Upload one plugin package'
            );
            await expect( pageA.getByTestId( 'digest' ) ).toHaveText(
                'sha256:17f75876c4a7e94e98d23d54290a11c43d386ea46977a387b6aa249a2e930b01'
            );

            const browserBPreflight = await pageB.evaluate( async () => {
                const response = await fetch( '/candidate-upload-preflight', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        digest: '0'.repeat( 64 ),
                        kind: 'plugin',
                    } ),
                } );
                return response.status;
            } );
            expect( browserBPreflight ).toBe( 409 );

            const wrongPasswordApproval = await pageA.evaluate( async () => {
                const response = await fetch( '/candidate-upload-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-upload-1',
                        password: '',
                    } ),
                } );
                return response.status;
            } );
            expect( wrongPasswordApproval ).toBe( 403 );

            const browserBApproval = await pageB.evaluate( async () => {
                const response = await fetch( '/candidate-upload-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'candidate-upload-1',
                        password: 'victim-secret',
                    } ),
                } );
                return response.status;
            } );
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

            const browserBRedemption = await pageB.evaluate( async ( bytes ) => {
                const response = await fetch( '/candidate-upload-effect', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/octet-stream',
                        'X-Phase27-Intent': 'candidate-upload-1',
                    },
                    body: new Uint8Array( bytes ),
                } );
                return response.status;
            }, Array.from( trustedPackage ) );
            expect( browserBRedemption ).toBe( 403 );

            await pageA.getByLabel( 'Plugin package' ).setInputFiles( {
                name: 'sample-plugin.zip',
                mimeType: 'application/zip',
                buffer: mutatedPackage,
            } );
            await pageA
                .getByRole( 'button', { name: 'Upload approved package once' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Rejected' );

            await pageA.getByLabel( 'Plugin package' ).setInputFiles( {
                name: 'sample-plugin.zip',
                mimeType: 'application/zip',
                buffer: trustedPackage,
            } );
            await pageA
                .getByRole( 'button', { name: 'Upload approved package once' } )
                .click();
            await expect( pageA.getByRole( 'status' ) ).toHaveText( 'Uploaded' );

            const replay = await pageA.evaluate( async ( bytes ) => {
                const response = await fetch( '/candidate-upload-effect', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/octet-stream',
                        'X-Phase27-Intent': 'candidate-upload-1',
                    },
                    body: new Uint8Array( bytes ),
                } );
                return response.status;
            }, Array.from( trustedPackage ) );
            expect( replay ).toBe( 409 );

            await expect
                .poll( async () => server.observations() )
                .toEqual(
                    expect.arrayContaining( [
                        {
                            operation: 'upload-preflight-clone-blocked',
                            value: 'candidate-upload-1',
                        },
                        {
                            operation: 'upload-clone-blocked',
                            value: 'candidate-upload-1',
                        },
                        {
                            operation: 'upload-mutation-blocked',
                            value: 'candidate-upload-1',
                        },
                        {
                            operation: 'upload-effect',
                            value: '17f75876c4a7e94e98d23d54290a11c43d386ea46977a387b6aa249a2e930b01',
                        },
                        {
                            operation: 'upload-replay-blocked',
                            value: 'candidate-upload-1',
                        },
                    ] )
                );
            expect(
                server
                    .observations()
                    .filter( ( item ) => item.operation === 'upload-effect' )
            ).toHaveLength( 1 );
        } finally {
            await Promise.allSettled( [
                browserA.close(),
                browserB.close(),
            ] );
            await server.close();
        }
    } );
} );
