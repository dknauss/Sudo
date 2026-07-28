import { expect, test } from '@playwright/test';

import { startPhase27ResearchServer } from '../../fixtures/phase27-research-server';

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
