import { expect, test } from '@playwright/test';

// Any well-formed 64-hex value works here: this spec only ever preflights, and
// never redeems, so the digest is never recomputed against real bytes. Named to
// say so rather than duplicating phase27-wordpress.spec.ts's trustedDigest,
// which is the sha256 of the actual trusted package and had already diverged
// from the stale copy this constant used to hold.
const wellFormedDigest = 'a'.repeat( 64 );

test.describe( 'Phase 27 WordPress storage boundaries', () => {
    test( 'authoritative storage failure refuses before creating authority', async ( {
        page,
    } ) => {
        await page.goto(
            '/wp-admin/tools.php?page=phase27-research&phase27_reset=1'
        );
        const fixture = page.locator( '#phase27-research' );
        await expect( fixture ).toBeVisible();
        const nonce = ( await fixture.getAttribute( 'data-nonce' ) ) ?? '';

        const request = async (
            path: string,
            body: Record<string, unknown>
        ) =>
            page.evaluate(
                async ( { payload, restNonce, route } ) => {
                    const response = await fetch(
                        `/wp-json/phase27/v1/${ route }`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': restNonce,
                            },
                            body: JSON.stringify( payload ),
                        }
                    );
                    return {
                        body: await response.json(),
                        status: response.status,
                    };
                },
                { payload: body, restNonce: nonce, route: path }
            );

        expect(
            ( await request( 'control', { operation: 'storage-fail' } ) )
                .status,
            'P27-WP-STORAGE-FAILURE-INJECTED'
        ).toBe( 200 );

        const refused = await request( 'preflight-upload', {
            digest: wellFormedDigest,
        } );
        expect(
            refused.status,
            'P27-WP-STORAGE-FAIL-CLOSED-STATUS'
        ).toBe( 503 );
        expect(
            refused.body.code,
            'P27-WP-STORAGE-FAIL-CLOSED-GUARD'
        ).toBe( 'phase27_storage' );

        expect(
            ( await request( 'control', { operation: 'storage-restore' } ) )
                .status
        ).toBe( 200 );
        expect(
            (
                await request( 'preflight-upload', {
                    digest: wellFormedDigest,
                } )
            ).status,
            'P27-WP-STORAGE-RECOVERY'
        ).toBe( 201 );
    } );
} );
