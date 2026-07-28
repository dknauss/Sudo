import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';

type Operation =
    | 'read'
    | 'invoke'
    | 'popup-opener'
    | 'authority-read'
    | 'redeem';

type Observation = {
    operation: Operation;
    value: string;
};

type ResearchServer = {
    close: () => Promise<void>;
    observations: () => Observation[];
    url: string;
};

const compromisedSameDocumentHandler =
    process.env.PHASE27_DISABLE_COMPROMISED_HANDLER === '1'
        ? ''
        : `
        // Model an active same-origin script already running in this document.
        password.addEventListener( 'input', () => {
            fetch( '/observe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    operation: 'read',
                    value: password.value,
                } ),
            } );
            approve.click();
        } );`;

const sameDocumentFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 same-document negative control</title>
</head>
<body>
    <button id="start-upload" type="button">Upload plugin</button>
    <dialog id="approval">
        <form method="dialog">
            <label for="password">Password</label>
            <input id="password" type="password" autocomplete="current-password">
            <button id="approve-upload" type="button">Approve upload</button>
        </form>
    </dialog>
    <script>
        const approval = document.querySelector( '#approval' );
        const password = document.querySelector( '#password' );
        const approve = document.querySelector( '#approve-upload' );

        document.querySelector( '#start-upload' ).addEventListener( 'click', () => {
            approval.showModal();
        } );

        approve.addEventListener( 'click', () => {
            fetch( '/observe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    operation: 'invoke',
                    value: 'core/upload-extension-package',
                } ),
            } );
        } );

        ${ compromisedSameDocumentHandler }
    </script>
</body>
</html>`;

const compromisedPopupReturnHandler =
    process.env.PHASE27_DISABLE_POPUP_RETURN_HANDLER === '1'
        ? ''
        : `
        channel.addEventListener( 'message', ( event ) => {
            fetch( '/observe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    operation: 'authority-read',
                    value: event.data.authority,
                } ),
            } );
            fetch( '/redeem', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    action: event.data.action,
                    authority: event.data.authority,
                } ),
            } );
        } );`;

const popupParentFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 popup parent</title>
</head>
<body>
    <button id="open-confirmation" type="button">Open trusted confirmation</button>
    <script>
        const channel = new BroadcastChannel( 'phase27-approval' );

        ${ compromisedPopupReturnHandler }

        document.querySelector( '#open-confirmation' ).addEventListener( 'click', () => {
            window.open( '/popup-confirm', 'phase27-confirm', 'noopener' );
        } );
    </script>
</body>
</html>`;

const popupConfirmationFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 isolated popup</title>
</head>
<body>
    <label for="password">Password</label>
    <input id="password" type="password" autocomplete="current-password">
    <button id="approve" type="button">Approve exact action</button>
    <script>
        fetch( '/observe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify( {
                operation: 'popup-opener',
                value: window.opener === null ? 'null' : 'present',
            } ),
        } );

        document.querySelector( '#approve' ).addEventListener( 'click', async () => {
            const response = await fetch( '/issue', { method: 'POST' } );
            const approval = await response.json();
            const channel = new BroadcastChannel( 'phase27-approval' );
            channel.postMessage( approval );
        } );
    </script>
</body>
</html>`;

function readBody( request: IncomingMessage ): Promise<string> {
    return new Promise( ( resolve, reject ) => {
        let body = '';

        request.setEncoding( 'utf8' );
        request.on( 'data', ( chunk ) => {
            body += chunk;
        } );
        request.on( 'end', () => resolve( body ) );
        request.on( 'error', reject );
    } );
}

function send(
    response: ServerResponse,
    status: number,
    contentType: string,
    body: string
): void {
    response.writeHead( status, {
        'Content-Type': contentType,
        'Cache-Control': 'no-store',
    } );
    response.end( body );
}

export async function startPhase27ResearchServer(): Promise<ResearchServer> {
    const observations: Observation[] = [];
    const server = createServer( async ( request, response ) => {
        if ( request.method === 'GET' && request.url === '/same-document' ) {
            send( response, 200, 'text/html; charset=utf-8', sameDocumentFixture );
            return;
        }

        if ( request.method === 'GET' && request.url === '/popup-parent' ) {
            send( response, 200, 'text/html; charset=utf-8', popupParentFixture );
            return;
        }

        if ( request.method === 'GET' && request.url === '/popup-confirm' ) {
            send(
                response,
                200,
                'text/html; charset=utf-8',
                popupConfirmationFixture
            );
            return;
        }

        if ( request.method === 'POST' && request.url === '/issue' ) {
            send(
                response,
                200,
                'application/json; charset=utf-8',
                JSON.stringify( {
                    action: 'core/upload-extension-package',
                    authority: 'phase27-action-bearer',
                } )
            );
            return;
        }

        if ( request.method === 'POST' && request.url === '/redeem' ) {
            const redemption = JSON.parse( await readBody( request ) ) as {
                action: string;
                authority: string;
            };

            if ( redemption.authority !== 'phase27-action-bearer' ) {
                send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
                return;
            }

            observations.push( {
                operation: 'redeem',
                value: redemption.action,
            } );
            send( response, 204, 'text/plain; charset=utf-8', '' );
            return;
        }

        if ( request.method === 'POST' && request.url === '/observe' ) {
            const observation = JSON.parse(
                await readBody( request )
            ) as Observation;
            observations.push( observation );
            send( response, 204, 'text/plain; charset=utf-8', '' );
            return;
        }

        send( response, 404, 'text/plain; charset=utf-8', 'Not found' );
    } );

    await new Promise<void>( ( resolve, reject ) => {
        server.once( 'error', reject );
        server.listen( 0, '127.0.0.1', resolve );
    } );

    const address = server.address();

    if ( ! address || typeof address === 'string' ) {
        await new Promise<void>( ( resolve ) => server.close( () => resolve() ) );
        throw new Error( 'Research server did not bind to a TCP port.' );
    }

    return {
        close: () =>
            new Promise<void>( ( resolve, reject ) => {
                server.close( ( error ) => {
                    if ( error ) {
                        reject( error );
                        return;
                    }
                    resolve();
                } );
            } ),
        observations: () => [ ...observations ],
        url: 'http://127.0.0.1:' + address.port,
    };
}
