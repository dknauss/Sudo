import { expect, test, type Page } from '@playwright/test';

const trustedPackage = [
    0x50, 0x4b, 0x03, 0x04, 0x00, 0xff, 0x80, 0x41,
];
const trustedDigest =
    '17f75876c4a7e94e98d23d54290a11c43d386ea46977a387b6aa249a2e930b01';

async function bootstrap( page: Page, reset = false ): Promise<string> {
    await page.goto(
        '/wp-admin/tools.php?page=phase27-research' +
            ( reset ? '&phase27_reset=1' : '' )
    );
    await expect( page.locator( '#phase27-research' ) ).toBeVisible();

    return page.locator( '#phase27-research' ).getAttribute( 'data-nonce' ) as Promise<string>;
}

async function preflight( page: Page, nonce: string ): Promise<string> {
    const result = await page.evaluate(
        async ( { digest, restNonce } ) => {
            const response = await fetch( '/wp-json/phase27/v1/preflight-upload', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce,
                },
                body: JSON.stringify( { digest } ),
            } );
            return {
                body: await response.json(),
                status: response.status,
            };
        },
        { digest: trustedDigest, restNonce: nonce }
    );

    expect( result.status, 'P27-WP-PREFLIGHT' ).toBe( 201 );
    return result.body.id;
}

async function approve(
    page: Page,
    nonce: string,
    intent: string
): Promise<number> {
    return page.evaluate(
        async ( { intentId, restNonce } ) => {
            const response = await fetch( '/wp-json/phase27/v1/approve', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce,
                },
                body: JSON.stringify( {
                    intent: intentId,
                    password: 'password',
                } ),
            } );
            return response.status;
        },
        { intentId: intent, restNonce: nonce }
    );
}

async function sinkEvidence(
    page: Page,
    nonce: string
): Promise<{ sink_count: number; sink_digests: string[] }> {
    return page.evaluate( async ( restNonce ) => {
        const response = await fetch( '/wp-json/phase27/v1/evidence', {
            headers: { 'X-WP-Nonce': restNonce },
        } );
        return response.json();
    }, nonce );
}

test.describe( 'Phase 27 real WordPress/PHP adapter', () => {
    test( 'copied session gets an independent binding and cannot approve Browser A intent', async ( {
        browser,
    } ) => {
        const browserA = await browser.newContext( {
            storageState: './tests/e2e/artifacts/storage-states/admin.json',
        } );
        const browserB = await browser.newContext( {
            storageState: './tests/e2e/artifacts/storage-states/admin.json',
        } );

        try {
            const pageA = await browserA.newPage();
            const pageB = await browserB.newPage();
            const nonceA = await bootstrap( pageA, true );
            const nonceB = await bootstrap( pageB );
            const bindingA = ( await browserA.cookies() ).find(
                ( cookie ) => cookie.name === '__Host-wp_sudo_action_binding'
            );
            const bindingB = ( await browserB.cookies() ).find(
                ( cookie ) => cookie.name === '__Host-wp_sudo_action_binding'
            );

            expect( bindingA?.httpOnly, 'P27-WP-BINDING-HTTPONLY' ).toBe( true );
            expect( bindingA?.secure, 'P27-WP-BINDING-SECURE' ).toBe( true );
            expect( bindingA?.path, 'P27-WP-BINDING-PATH' ).toBe( '/' );
            expect( bindingA?.domain, 'P27-WP-BINDING-HOSTONLY' ).toBe(
                'localhost'
            );
            expect(
                await pageA.evaluate( () => document.cookie ),
                'P27-WP-BINDING-NOT-SCRIPT-READABLE'
            ).not.toContain( '__Host-wp_sudo_action_binding' );
            expect( bindingB?.value, 'P27-WP-INDEPENDENT-BINDINGS' ).not.toBe(
                bindingA?.value
            );

            const intentA = await preflight( pageA, nonceA );
            const intentB = await preflight( pageB, nonceB );
            expect( intentB, 'P27-WP-INDEPENDENT-INTENTS' ).not.toBe( intentA );

            expect(
                await approve( pageB, nonceB, intentA ),
                'P27-WP-COPIED-SESSION-CANNOT-APPROVE-A'
            ).toBe( 403 );
            expect(
                await approve( pageA, nonceA, intentA ),
                'P27-WP-A-APPROVES-OWN-INTENT'
            ).toBe( 200 );

            const cookies = await browserA.cookies();
            const rawCookie = cookies
                .map( ( cookie ) => `${ cookie.name }=${ cookie.value }` )
                .join( '; ' );
            const duplicateResponse = await pageA.request.post(
                `${ process.env.WP_BASE_URL ?? 'https://localhost:9443' }/wp-json/phase27/v1/preflight-upload`,
                {
                    headers: {
                        'Content-Type': 'application/json',
                        Cookie:
                            rawCookie +
                            `; __Host-wp_sudo_action_binding=${ bindingA?.value ?? '' }`,
                        'X-WP-Nonce': nonceA,
                    },
                    data: { digest: trustedDigest },
                }
            );
            expect(
                duplicateResponse.status(),
                'P27-WP-DUPLICATE-BINDING-REJECTED'
            ).toBe( 403 );
            expect(
                ( await duplicateResponse.json() ).code,
                'P27-WP-DUPLICATE-BINDING-GUARD'
            ).toBe( 'phase27_binding' );
        } finally {
            await Promise.allSettled( [ browserA.close(), browserB.close() ] );
        }
    } );

    test( 'real PHP multipart bytes are bound and concurrent consumption has one winner', async ( {
        page,
    } ) => {
        const nonce = await bootstrap( page, true );
        let expectedSinkCount = 0;
        expect( ( await sinkEvidence( page, nonce ) ).sink_count ).toBe(
            expectedSinkCount
        );

        for ( const workers of [ 2, 8, 32 ] ) {
            const intent = await preflight( page, nonce );
            expect( await approve( page, nonce, intent ) ).toBe( 200 );

            const statuses = await page.evaluate(
                async ( { bytes, count, intentId, restNonce } ) =>
                    Promise.all(
                        Array.from( { length: count }, async () => {
                            const form = new FormData();
                            form.append( 'intent', intentId );
                            form.append(
                                'package',
                                new File(
                                    [ new Uint8Array( bytes ) ],
                                    'sample-plugin.zip',
                                    { type: 'application/zip' }
                                )
                            );
                            const response = await fetch(
                                '/wp-json/phase27/v1/effect-upload',
                                {
                                    method: 'POST',
                                    headers: { 'X-WP-Nonce': restNonce },
                                    body: form,
                                }
                            );
                            return response.status;
                        } )
                    ),
                {
                    bytes: trustedPackage,
                    count: workers,
                    intentId: intent,
                    restNonce: nonce,
                }
            );

            expect(
                statuses.filter( ( status ) => status === 200 ),
                `P27-WP-ATOMIC-CONSUME-ONE-WINNER-${ workers }`
            ).toHaveLength( 1 );
            expect(
                statuses.filter( ( status ) => status === 409 ),
                `P27-WP-ATOMIC-CONSUME-LOSERS-${ workers }`
            ).toHaveLength( workers - 1 );
            expectedSinkCount += 1;
            const evidence = await sinkEvidence( page, nonce );
            expect(
                evidence.sink_count,
                `P27-WP-EFFECT-ONCE-${ workers }`
            ).toBe( expectedSinkCount );
            expect(
                evidence.sink_digests,
                `P27-WP-EFFECT-CONSUMED-APPROVED-BYTES-${ workers }`
            ).toEqual(
                Array.from( { length: expectedSinkCount }, () => trustedDigest )
            );
        }
    } );

    test( 'PHP hashes the uploaded temporary-file bytes and rejects substitution', async ( {
        page,
    } ) => {
        const nonce = await bootstrap( page, true );
        const intent = await preflight( page, nonce );
        expect( await approve( page, nonce, intent ) ).toBe( 200 );

        const result = await page.evaluate(
            async ( { intentId, restNonce } ) => {
                const form = new FormData();
                form.append( 'intent', intentId );
                form.append(
                    'package',
                    new File(
                        [ new Uint8Array( [ 0x50, 0x4b, 0x03, 0x04, 0x42 ] ) ],
                        'sample-plugin.zip',
                        { type: 'application/zip' }
                    )
                );
                const response = await fetch(
                    '/wp-json/phase27/v1/effect-upload',
                    {
                        method: 'POST',
                        headers: { 'X-WP-Nonce': restNonce },
                        body: form,
                    }
                );
                return {
                    body: await response.json(),
                    status: response.status,
                };
            },
            { intentId: intent, restNonce: nonce }
        );

        expect( result.status, 'P27-WP-UPLOAD-SUBSTITUTION' ).toBe( 409 );
        expect(
            result.body.code,
            'P27-WP-UPLOAD-SUBSTITUTION-GUARD'
        ).toBe( 'phase27_digest' );
    } );

    test( 'account approval throttle survives binding and intent rotation', async ( {
        browser,
    } ) => {
        const browserA = await browser.newContext( {
            storageState: './tests/e2e/artifacts/storage-states/admin.json',
        } );
        const browserB = await browser.newContext( {
            storageState: './tests/e2e/artifacts/storage-states/admin.json',
        } );

        try {
            const pageA = await browserA.newPage();
            const pageB = await browserB.newPage();
            const nonceA = await bootstrap( pageA, true );
            const nonceB = await bootstrap( pageB );
            const intentA = await preflight( pageA, nonceA );
            const intentB = await preflight( pageB, nonceB );

            const fail = ( page: Page, nonce: string, intent: string ) =>
                page.evaluate(
                    async ( { intentId, restNonce } ) => {
                        const response = await fetch(
                            '/wp-json/phase27/v1/approve',
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': restNonce,
                                },
                                body: JSON.stringify( {
                                    intent: intentId,
                                    password: 'wrong',
                                } ),
                            }
                        );
                        return response.status;
                    },
                    { intentId: intent, restNonce: nonce }
                );

            expect(
                [
                    await fail( pageA, nonceA, intentA ),
                    await fail( pageB, nonceB, intentB ),
                    await fail( pageA, nonceA, intentA ),
                    await fail( pageB, nonceB, intentB ),
                ],
                'P27-WP-APPROVAL-RATE-ROTATION'
            ).toEqual( [ 403, 403, 403, 429 ] );
            expect(
                await approve( pageA, nonceA, intentA ),
                'P27-WP-APPROVAL-RATE-VICTIM-DOS'
            ).toBe( 429 );
        } finally {
            await Promise.allSettled( [ browserA.close(), browserB.close() ] );
        }
    } );
} );
