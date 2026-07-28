import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { createHash, randomBytes } from 'node:crypto';

type Operation =
    | 'read'
    | 'invoke'
    | 'popup-opener'
    | 'authority-read'
    | 'redeem'
    | 'parent-handle'
    | 'unauthorized-redemption-blocked'
    | 'mutation-blocked'
    | 'effect'
    | 'replay-blocked'
    | 'isolated-credential-read'
    | 'parent-unloaded'
    | 'top-level-credential-read'
    | 'counterfeit-credential-read'
    | 'candidate-preflight-clone-blocked'
    | 'clone-reauth-blocked'
    | 'clone-redemption-blocked'
    | 'candidate-mutation-blocked'
    | 'candidate-effect'
    | 'candidate-replay-blocked'
    | 'upload-preflight-clone-blocked'
    | 'upload-clone-blocked'
    | 'upload-mutation-blocked'
    | 'upload-envelope-digest'
    | 'upload-part-digest'
    | 'upload-effect'
    | 'upload-replay-blocked';

type Observation = {
    operation: Operation;
    value: string;
};

type ResearchServer = {
    close: () => Promise<void>;
    observations: () => Observation[];
    url: string;
};

type CandidateIntent = {
    action: 'core/write-extension-file';
    approvedBinding: string;
    digest: string;
    id: string;
    label: string;
    preparedBinding: string;
    target: string;
    used: boolean;
};

type UploadIntent = {
    action: 'core/upload-extension-package';
    approvedBinding: string;
    digest: string;
    id: string;
    kind: 'plugin';
    preparedBinding: string;
    used: boolean;
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

const isolatedParentFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 isolated self-redemption parent</title>
</head>
<body>
    <button id="review-file-write" type="button">Review exact file write</button>
    <script>
        // Model attempts available to script confined to this parent document.
        fetch( '/confirm-and-redeem', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify( {
                intent: 'file-write-1',
                password: '',
            } ),
        } );
        fetch( '/mutate-intent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify( {
                intent: 'file-write-1',
                target: 'attacker-plugin/attacker.php',
            } ),
        } );

        document.querySelector( '#review-file-write' ).addEventListener( 'click', () => {
            const handle = window.open(
                '/isolated-confirm?intent=file-write-1',
                'phase27-isolated-confirm',
                'noopener'
            );
            fetch( '/observe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    operation: 'parent-handle',
                    value: handle === null ? 'null' : 'present',
                } ),
            } );
        } );
    </script>
</body>
</html>`;

const isolatedConfirmationFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 isolated confirmation and redemption</title>
</head>
<body>
    <p data-testid="action"></p>
    <p data-testid="digest"></p>
    <label for="password">Password</label>
    <input id="password" type="password" autocomplete="current-password">
    <button id="confirm" type="button">Confirm and write file</button>
    <p role="status"></p>
    <div data-testid="response-contract"></div>
    <script>
        const params = new URLSearchParams( window.location.search );
        const intent = params.get( 'intent' );

        fetch( '/intent?id=' + encodeURIComponent( intent ) )
            .then( ( response ) => response.json() )
            .then( ( descriptor ) => {
                document.querySelector( '[data-testid="action"]' ).textContent =
                    descriptor.label;
                document.querySelector( '[data-testid="digest"]' ).textContent =
                    descriptor.digest;
            } );

        document.querySelector( '#confirm' ).addEventListener( 'click', async () => {
            const response = await fetch( '/confirm-and-redeem', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    intent,
                    password: document.querySelector( '#password' ).value,
                } ),
            } );
            const result = await response.json();

            document.querySelector( '[role="status"]' ).textContent =
                response.ok ? 'Written' : 'Rejected';
            document.querySelector( '[data-testid="response-contract"]' ).textContent =
                JSON.stringify( result );
        } );
    </script>
</body>
</html>`;

const tamperedPopupParentFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 tampered popup parent</title>
</head>
<body>
    <button id="review-file-write" type="button">Review exact file write</button>
    <script>
        // Model script that ran before the application's click handler.
        const nativeOpen = window.open.bind( window );
        window.open = ( url, target ) => {
            const handle = nativeOpen( url, target );

            fetch( '/observe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    operation: 'parent-handle',
                    value: handle === null ? 'null' : 'present',
                } ),
            } );

            if ( handle !== null ) {
                handle.addEventListener( 'load', () => {
                    const password = handle.document.querySelector( '#password' );
                    const confirm = handle.document.querySelector( '#confirm' );

                    password.addEventListener( 'input', () => {
                        fetch( '/observe', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify( {
                                operation: 'isolated-credential-read',
                                value: password.value,
                            } ),
                        } );
                        confirm.click();
                    } );
                } );
            }

            return handle;
        };

        // The application still requests noopener; the compromised wrapper drops it.
        document.querySelector( '#review-file-write' ).addEventListener( 'click', () => {
            window.open(
                '/isolated-confirm?intent=file-write-1',
                'phase27-isolated-confirm',
                'noopener'
            );
        } );
    </script>
</body>
</html>`;

const topLevelParentFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 top-level replacement parent</title>
</head>
<body>
    <button id="review-file-write" type="button">Review exact file write</button>
    <script>
        // Model script confined to this document polling for the credential UI.
        setInterval( () => {
            const password = document.querySelector( '#password' );

            if ( password !== null ) {
                fetch( '/observe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        operation: 'top-level-credential-read',
                        value: password.value,
                    } ),
                } );
            }
        }, 10 );

        window.addEventListener( 'pagehide', () => {
            navigator.sendBeacon(
                '/observe',
                JSON.stringify( {
                    operation: 'parent-unloaded',
                    value: 'top-level-parent',
                } )
            );
        } );

        document.querySelector( '#review-file-write' ).addEventListener( 'click', () => {
            window.location.assign(
                '/top-level-confirm?intent=file-write-1'
            );
        } );
    </script>
</body>
</html>`;

const counterfeitTopLevelParentFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 counterfeited top-level confirmation</title>
</head>
<body>
    <button id="review-file-write" type="button">Review exact file write</button>
    <script>
        // Model active script intercepting the user's launch gesture first.
        document.addEventListener( 'click', ( event ) => {
            if ( event.target.id !== 'review-file-write' ) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            history.pushState(
                {},
                '',
                '/top-level-confirm?intent=file-write-1'
            );
            document.body.innerHTML = \`
                <p data-testid="action">Write sample-plugin/sample.php</p>
                <p data-testid="digest">sha256:server-held-proposed-bytes</p>
                <label for="password">Password</label>
                <input id="password" type="password" autocomplete="current-password">
                <button id="confirm" type="button">Confirm and write file</button>
            \`;

            const password = document.querySelector( '#password' );
            password.addEventListener( 'input', () => {
                fetch( '/observe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        operation: 'counterfeit-credential-read',
                        value: password.value,
                    } ),
                } );
                fetch( '/confirm-and-redeem', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: 'file-write-1',
                        password: password.value,
                    } ),
                } );
            } );
        }, true );

        // The application attempts a real top-level replacement.
        document.querySelector( '#review-file-write' ).addEventListener( 'click', () => {
            window.location.assign(
                '/top-level-confirm?intent=file-write-1'
            );
        } );
    </script>
</body>
</html>`;

const copiedCookieCandidateFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 copied-cookie candidate</title>
</head>
<body>
    <p data-testid="action"></p>
    <p data-testid="digest"></p>
    <p data-testid="intent"></p>
    <button id="prepare-candidate" type="button">Prepare exact write</button>
    <label for="candidate-password">Password</label>
    <input
        id="candidate-password"
        type="password"
        autocomplete="current-password"
    >
    <button id="approve-candidate" type="button">Approve exact write</button>
    <button id="submit-candidate" type="button">Submit exact write once</button>
    <p role="status"></p>
    <div data-testid="approval-response-contract"></div>
    <script>
        let descriptorPromise;

        document.querySelector( '#prepare-candidate' ).addEventListener(
            'click',
            () => {
                descriptorPromise = fetch( '/candidate-preflight', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        action: 'core/write-extension-file',
                        digest: 'sha256:server-held-proposed-bytes',
                        target: 'sample-plugin/sample.php',
                    } ),
                } )
                    .then( ( response ) => response.json() )
                    .then( ( descriptor ) => {
                        document.querySelector(
                            '[data-testid="action"]'
                        ).textContent = descriptor.label;
                        document.querySelector(
                            '[data-testid="digest"]'
                        ).textContent = descriptor.digest;
                        document.querySelector(
                            '[data-testid="intent"]'
                        ).textContent = descriptor.id;
                        return descriptor;
                    } );
            }
        );

        document.querySelector( '#approve-candidate' ).addEventListener(
            'click',
            async () => {
                const descriptor = await descriptorPromise;
                const response = await fetch( '/candidate-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: descriptor.id,
                        password: document.querySelector(
                            '#candidate-password'
                        ).value,
                    } ),
                } );
                const result = await response.json();
                document.querySelector(
                    '[data-testid="approval-response-contract"]'
                ).textContent = JSON.stringify( result );
                document.querySelector( '[role="status"]' ).textContent =
                    response.ok ? 'Approved' : 'Rejected';
            }
        );

        document.querySelector( '#submit-candidate' ).addEventListener(
            'click',
            async () => {
                const descriptor = await descriptorPromise;
                const response = await fetch( '/candidate-effect', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: descriptor.id,
                        target: descriptor.target,
                        digest: descriptor.digest,
                    } ),
                } );
                document.querySelector( '[role="status"]' ).textContent =
                    response.ok ? 'Written' : 'Rejected';
            }
        );
    </script>
</body>
</html>`;

const copiedCookieUploadCandidateFixture = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Phase 27 copied-cookie upload candidate</title>
</head>
<body>
    <p data-testid="action"></p>
    <p data-testid="digest"></p>
    <p data-testid="intent"></p>
    <label for="package">Plugin package</label>
    <input id="package" type="file" accept=".zip,application/zip">
    <button id="prepare-upload" type="button">Prepare exact upload</button>
    <label for="upload-password">Password</label>
    <input
        id="upload-password"
        type="password"
        autocomplete="current-password"
    >
    <button id="approve-upload-candidate" type="button">
        Approve exact upload
    </button>
    <button id="submit-upload-candidate" type="button">
        Upload approved package once
    </button>
    <p role="status"></p>
    <script>
        const packageInput = document.querySelector( '#package' );
        let descriptorPromise;

        async function selectedFileDigest() {
            const file = packageInput.files[ 0 ];
            const digest = await crypto.subtle.digest(
                'SHA-256',
                await file.arrayBuffer()
            );
            return Array.from( new Uint8Array( digest ) )
                .map( ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) )
                .join( '' );
        }

        document.querySelector( '#prepare-upload' ).addEventListener(
            'click',
            async () => {
                const digest = await selectedFileDigest();
                descriptorPromise = fetch( '/candidate-upload-preflight', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        digest,
                        kind: 'plugin',
                    } ),
                } ).then( ( response ) => response.json() );
                const descriptor = await descriptorPromise;
                document.querySelector( '[data-testid="action"]' ).textContent =
                    descriptor.label;
                document.querySelector( '[data-testid="digest"]' ).textContent =
                    'sha256:' + descriptor.digest;
                document.querySelector( '[data-testid="intent"]' ).textContent =
                    descriptor.id;
            }
        );

        document.querySelector( '#approve-upload-candidate' ).addEventListener(
            'click',
            async () => {
                const descriptor = await descriptorPromise;
                const response = await fetch( '/candidate-upload-approve', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify( {
                        intent: descriptor.id,
                        password: document.querySelector(
                            '#upload-password'
                        ).value,
                    } ),
                } );
                document.querySelector( '[role="status"]' ).textContent =
                    response.ok ? 'Approved' : 'Rejected';
            }
        );

        document.querySelector( '#submit-upload-candidate' ).addEventListener(
            'click',
            async () => {
                const descriptor = await descriptorPromise;
                const form = new FormData();
                form.append( 'package', packageInput.files[ 0 ] );
                const response = await fetch( '/candidate-upload-effect', {
                    method: 'POST',
                    headers: {
                        'X-Phase27-Intent': descriptor.id,
                    },
                    body: form,
                } );
                document.querySelector( '[role="status"]' ).textContent =
                    response.ok ? 'Uploaded' : 'Rejected';
            }
        );
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

function readBodyBuffer( request: IncomingMessage ): Promise<Buffer> {
    return new Promise( ( resolve, reject ) => {
        const chunks: Buffer[] = [];

        request.on( 'data', ( chunk: Buffer ) => {
            chunks.push( chunk );
        } );
        request.on( 'end', () => resolve( Buffer.concat( chunks ) ) );
        request.on( 'error', reject );
    } );
}

function multipartFileBytes(
    request: IncomingMessage,
    body: Buffer,
    fieldName: string
): Buffer | null {
    const contentType = request.headers[ 'content-type' ] ?? '';
    const boundaryMatch = contentType.match(
        /^multipart\/form-data;\s*boundary=(?:"([^"]+)"|([^;\s]+))$/i
    );
    const boundary = boundaryMatch?.[ 1 ] ?? boundaryMatch?.[ 2 ] ?? '';

    if ( boundary === '' ) {
        return null;
    }

    const marker = Buffer.from( '--' + boundary );
    const headerSeparator = Buffer.from( '\r\n\r\n' );
    const closing = Buffer.from( '\r\n--' + boundary );
    const matches: Buffer[] = [];
    let cursor = 0;

    while ( true ) {
        const markerIndex = body.indexOf( marker, cursor );
        if ( markerIndex === -1 ) {
            break;
        }

        if (
            body
                .subarray(
                    markerIndex + marker.length,
                    markerIndex + marker.length + 2
                )
                .equals( Buffer.from( '--' ) )
        ) {
            break;
        }

        const headerStart = markerIndex + marker.length + 2;
        const headerEnd = body.indexOf( headerSeparator, headerStart );
        if ( headerEnd === -1 ) {
            return null;
        }

        const headers = body.subarray( headerStart, headerEnd ).toString( 'utf8' );
        const contentStart = headerEnd + headerSeparator.length;
        const contentEnd = body.indexOf( closing, contentStart );
        if ( contentEnd === -1 ) {
            return null;
        }

        if (
            headers.toLowerCase().includes( 'content-disposition: form-data' ) &&
            headers.includes( 'name="' + fieldName + '"' )
        ) {
            matches.push( body.subarray( contentStart, contentEnd ) );
        }

        cursor = contentEnd + 2;
    }

    if ( mutationEnabled( 'UPLOAD_SINGLE_PART' ) && matches.length > 0 ) {
        return matches[ 0 ];
    }

    return matches.length === 1 ? matches[ 0 ] : null;
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

function cookieValue( request: IncomingMessage, name: string ): string {
    const cookies = request.headers.cookie?.split( ';' ) ?? [];

    for ( const cookie of cookies ) {
        const [ key, ...value ] = cookie.trim().split( '=' );

        if ( key === name ) {
            return value.join( '=' );
        }
    }

    return '';
}

function mutationEnabled( guardId: string ): boolean {
    return process.env.PHASE27_MUTATION === guardId;
}

export async function startPhase27ResearchServer(): Promise<ResearchServer> {
    const observations: Observation[] = [];
    const approvalRateDisabled = mutationEnabled( 'APPROVE_ACCOUNT_RATE' );
    const bindingCookieAttributes = mutationEnabled( 'BIND_SECURE' )
        ? '; HttpOnly; SameSite=Strict; Path=/'
        : '; Secure; HttpOnly; SameSite=Strict; Path=/';
    const preflightAttempts = new Map<
        string,
        { attempts: number; windowStartedAt: number }
    >();
    const approvalFailures = new Map<string, number>();
    const intent = {
        action: 'core/write-extension-file',
        digest: 'sha256:server-held-proposed-bytes',
        id: 'file-write-1',
        label: 'Write sample-plugin/sample.php',
        target: 'sample-plugin/sample.php',
        used: false,
    };
    const candidateIntents = new Map<string, CandidateIntent>();
    const uploadIntents = new Map<string, UploadIntent>();

    function authorizePreflight(
        request: IncomingMessage,
        response: ServerResponse,
        scope: string
    ): boolean {
        const auth = cookieValue( request, 'wp_auth' );
        const binding = cookieValue( request, '__Host-phase27_binding' );

        if (
            process.env.PHASE27_DISABLE_PREFLIGHT_CAPABILITY !== '1' &&
            auth !== 'copied-login-session'
        ) {
            send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
            return false;
        }

        if ( binding === '' ) {
            send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
            return false;
        }

        // Bind the budget to the authenticated login session, not the
        // attacker-rotatable browser cookie used to bind an approval.
        const rateKey = scope + ':' + auth;
        const now = Date.now();
        const existingBudget = preflightAttempts.get( rateKey );
        const budget =
            existingBudget === undefined ||
            now - existingBudget.windowStartedAt >= 60_000
                ? { attempts: 1, windowStartedAt: now }
                : {
                      attempts: existingBudget.attempts + 1,
                      windowStartedAt: existingBudget.windowStartedAt,
                  };
        preflightAttempts.set( rateKey, budget );

        if (
            ! mutationEnabled( 'PREFLIGHT_RATE' ) &&
            process.env.PHASE27_DISABLE_PREFLIGHT_RATE_LIMIT !== '1' &&
            budget.attempts > 3
        ) {
            send( response, 429, 'text/plain; charset=utf-8', 'Too many requests' );
            return false;
        }

        return true;
    }

    function authorizeApprovalFactor(
        auth: string,
        password: string,
        response: ServerResponse
    ): boolean {
        const failures = approvalFailures.get( auth ) ?? 0;

        if (
            ! approvalRateDisabled &&
            failures >= 3
        ) {
            send(
                response,
                429,
                'application/json; charset=utf-8',
                JSON.stringify( { status: 'rate_limited' } )
            );
            return false;
        }

        if ( password !== 'victim-secret' ) {
            if ( ! approvalRateDisabled ) {
                approvalFailures.set( auth, failures + 1 );
            }
            send(
                response,
                403,
                'application/json; charset=utf-8',
                JSON.stringify( { status: 'rejected' } )
            );
            return false;
        }

        return true;
    }

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

        if ( request.method === 'GET' && request.url === '/isolated-parent' ) {
            send(
                response,
                200,
                'text/html; charset=utf-8',
                isolatedParentFixture
            );
            return;
        }

        if (
            request.method === 'GET' &&
            request.url === '/tampered-popup-parent'
        ) {
            send(
                response,
                200,
                'text/html; charset=utf-8',
                tamperedPopupParentFixture
            );
            return;
        }

        if ( request.method === 'GET' && request.url === '/top-level-parent' ) {
            send(
                response,
                200,
                'text/html; charset=utf-8',
                topLevelParentFixture
            );
            return;
        }

        if (
            request.method === 'GET' &&
            request.url === '/counterfeit-top-level-parent'
        ) {
            send(
                response,
                200,
                'text/html; charset=utf-8',
                counterfeitTopLevelParentFixture
            );
            return;
        }

        if (
            request.method === 'GET' &&
            request.url === '/copied-cookie-candidate'
        ) {
            let binding = cookieValue( request, '__Host-phase27_binding' );

            if ( binding === '' ) {
                binding = randomBytes( 32 ).toString( 'base64url' );
            }

            response.writeHead( 200, {
                'Cache-Control': 'no-store',
                'Content-Type': 'text/html; charset=utf-8',
                'Set-Cookie':
                    '__Host-phase27_binding=' +
                    binding +
                    bindingCookieAttributes,
            } );
            response.end( copiedCookieCandidateFixture );
            return;
        }

        if (
            request.method === 'GET' &&
            request.url === '/copied-cookie-upload-candidate'
        ) {
            let binding = cookieValue( request, '__Host-phase27_binding' );

            if ( binding === '' ) {
                binding = randomBytes( 32 ).toString( 'base64url' );
            }

            response.writeHead( 200, {
                'Cache-Control': 'no-store',
                'Content-Type': 'text/html; charset=utf-8',
                'Set-Cookie':
                    '__Host-phase27_binding=' +
                    binding +
                    bindingCookieAttributes,
            } );
            response.end( copiedCookieUploadCandidateFixture );
            return;
        }

        if (
            request.method === 'POST' &&
            request.url === '/candidate-upload-preflight'
        ) {
            if ( ! authorizePreflight( request, response, 'upload' ) ) {
                return;
            }

            const preflight = JSON.parse( await readBody( request ) ) as {
                digest: string;
                kind: string;
            };
            const binding = cookieValue( request, '__Host-phase27_binding' );

            if (
                preflight.kind !== 'plugin' ||
                ! /^[a-f0-9]{64}$/.test( preflight.digest )
            ) {
                send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
                return;
            }

            const uploadIntent: UploadIntent = {
                action: 'core/upload-extension-package',
                approvedBinding: '',
                digest: preflight.digest,
                id: randomBytes( 32 ).toString( 'base64url' ),
                kind: preflight.kind,
                preparedBinding: binding,
                used: false,
            };
            uploadIntents.set( uploadIntent.id, uploadIntent );
            send(
                response,
                200,
                'application/json; charset=utf-8',
                JSON.stringify( {
                    digest: uploadIntent.digest,
                    id: uploadIntent.id,
                    label: 'Upload one plugin package',
                } )
            );
            return;
        }

        if (
            request.method === 'POST' &&
            request.url === '/candidate-upload-approve'
        ) {
            const approval = JSON.parse( await readBody( request ) ) as {
                intent: string;
                password: string;
            };
            const binding = cookieValue( request, '__Host-phase27_binding' );
            const authenticated =
                cookieValue( request, 'wp_auth' ) === 'copied-login-session';
            const uploadIntent = uploadIntents.get( approval.intent );

            if (
                ! authenticated ||
                binding === '' ||
                uploadIntent === undefined ||
                ( process.env
                    .PHASE27_DISABLE_UPLOAD_APPROVAL_BINDING !== '1' &&
                    binding !== uploadIntent.preparedBinding ) ||
                ( process.env.PHASE27_DISABLE_UPLOAD_PASSWORD_CHECK !== '1' &&
                    approval.password !== 'victim-secret' )
            ) {
                send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
                return;
            }

            uploadIntent.approvedBinding = binding;
            send(
                response,
                200,
                'application/json; charset=utf-8',
                JSON.stringify( { status: 'approved' } )
            );
            return;
        }

        if (
            request.method === 'POST' &&
            request.url === '/candidate-upload-effect'
        ) {
            const envelope = await readBodyBuffer( request );
            const parsedBody = multipartFileBytes( request, envelope, 'package' );
            const body =
                parsedBody === null && mutationEnabled( 'UPLOAD_MULTIPART' )
                    ? envelope
                    : parsedBody;
            const binding = cookieValue( request, '__Host-phase27_binding' );
            const authenticated =
                cookieValue( request, 'wp_auth' ) === 'copied-login-session';
            const submittedIntent =
                request.headers[ 'x-phase27-intent' ] ?? '';
            const uploadIntent = uploadIntents.get( String( submittedIntent ) );

            if ( body === null ) {
                send(
                    response,
                    400,
                    'text/plain; charset=utf-8',
                    'One package file is required'
                );
                return;
            }

            if (
                ! authenticated ||
                binding === '' ||
                uploadIntent === undefined ||
                ( process.env.PHASE27_DISABLE_UPLOAD_BINDING !== '1' &&
                    binding !== uploadIntent.approvedBinding )
            ) {
                observations.push( {
                    operation: 'upload-clone-blocked',
                    value: String( submittedIntent ),
                } );
                send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
                return;
            }

            if (
                process.env.PHASE27_DISABLE_UPLOAD_ONE_USE !== '1' &&
                uploadIntent.used
            ) {
                observations.push( {
                    operation: 'upload-replay-blocked',
                    value: String( submittedIntent ),
                } );
                send( response, 409, 'text/plain; charset=utf-8', 'Already used' );
                return;
            }

            const submittedDigest = createHash( 'sha256' )
                .update( body )
                .digest( 'hex' );
            observations.push( {
                operation: 'upload-envelope-digest',
                value: createHash( 'sha256' ).update( envelope ).digest( 'hex' ),
            } );
            observations.push( {
                operation: 'upload-part-digest',
                value: submittedDigest,
            } );

            if (
                ! mutationEnabled( 'UPLOAD_CONTENT_HASH' ) &&
                process.env.PHASE27_DISABLE_UPLOAD_DIGEST_CHECK !== '1' &&
                submittedDigest !== uploadIntent.digest
            ) {
                observations.push( {
                    operation: 'upload-mutation-blocked',
                    value: String( submittedIntent ),
                } );
                send( response, 409, 'text/plain; charset=utf-8', 'Mismatch' );
                return;
            }

            uploadIntent.used = true;
            observations.push( {
                operation: 'upload-effect',
                value: submittedDigest,
            } );
            send( response, 204, 'text/plain; charset=utf-8', '' );
            return;
        }

        if (
            request.method === 'POST' &&
            request.url === '/candidate-preflight'
        ) {
            if ( ! authorizePreflight( request, response, 'file-write' ) ) {
                return;
            }

            const preflight = JSON.parse( await readBody( request ) ) as {
                action: string;
                digest: string;
                target: string;
            };
            const binding = cookieValue( request, '__Host-phase27_binding' );

            if (
                preflight.action !== 'core/write-extension-file' ||
                preflight.target !== 'sample-plugin/sample.php' ||
                preflight.digest !== 'sha256:server-held-proposed-bytes'
            ) {
                send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
                return;
            }

            const candidateIntent: CandidateIntent = {
                action: 'core/write-extension-file',
                approvedBinding: '',
                digest: preflight.digest,
                id: mutationEnabled( 'PREFLIGHT_RANDOM_ID' )
                    ? 'mutated-shared-intent'
                    : randomBytes( 32 ).toString( 'base64url' ),
                label: 'Write sample-plugin/sample.php',
                preparedBinding: binding,
                target: preflight.target,
                used: false,
            };
            if ( mutationEnabled( 'PREFLIGHT_NEW_RECORD' ) ) {
                candidateIntents.clear();
            }
            candidateIntents.set( candidateIntent.id, candidateIntent );
            send(
                response,
                200,
                'application/json; charset=utf-8',
                JSON.stringify( {
                    digest: candidateIntent.digest,
                    id: candidateIntent.id,
                    label: candidateIntent.label,
                    target: candidateIntent.target,
                } )
            );
            return;
        }

        if ( request.method === 'POST' && request.url === '/candidate-approve' ) {
            const approval = JSON.parse( await readBody( request ) ) as {
                intent: string;
                password: string;
            };
            const binding = cookieValue( request, '__Host-phase27_binding' );
            const auth = cookieValue( request, 'wp_auth' );
            const authenticated = auth === 'copied-login-session';
            const candidateIntent = mutationEnabled( 'APPROVE_LOOKUP' )
                ? candidateIntents.values().next().value
                : candidateIntents.get( approval.intent );

            if (
                ! authenticated ||
                binding === '' ||
                candidateIntent === undefined ||
                ( process.env
                    .PHASE27_DISABLE_CANDIDATE_APPROVAL_BINDING !== '1' &&
                    binding !== candidateIntent.preparedBinding )
            ) {
                observations.push( {
                    operation: 'clone-reauth-blocked',
                    value: approval.intent,
                } );
                send(
                    response,
                    403,
                    'application/json; charset=utf-8',
                    JSON.stringify( { status: 'rejected' } )
                );
                return;
            }

            if (
                process.env.PHASE27_DISABLE_CANDIDATE_PASSWORD_CHECK !== '1' &&
                ! authorizeApprovalFactor( auth, approval.password, response )
            ) {
                return;
            }

            candidateIntent.approvedBinding = binding;
            send(
                response,
                200,
                'application/json; charset=utf-8',
                JSON.stringify( { status: 'approved' } )
            );
            return;
        }

        if ( request.method === 'POST' && request.url === '/candidate-effect' ) {
            const submission = JSON.parse( await readBody( request ) ) as {
                digest: string;
                intent: string;
                target: string;
            };
            const binding = cookieValue( request, '__Host-phase27_binding' );
            const authenticated =
                cookieValue( request, 'wp_auth' ) === 'copied-login-session';
            const candidateIntent = mutationEnabled( 'EFFECT_LOOKUP' )
                ? candidateIntents.values().next().value
                : candidateIntents.get( submission.intent );

            if (
                ! authenticated ||
                binding === '' ||
                candidateIntent === undefined ||
                ( process.env.PHASE27_DISABLE_CANDIDATE_BINDING !== '1' &&
                    binding !== candidateIntent.approvedBinding )
            ) {
                observations.push( {
                    operation: 'clone-redemption-blocked',
                    value: submission.intent,
                } );
                send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
                return;
            }

            if (
                process.env.PHASE27_DISABLE_CANDIDATE_ONE_USE !== '1' &&
                candidateIntent.used
            ) {
                observations.push( {
                    operation: 'candidate-replay-blocked',
                    value: submission.intent,
                } );
                send( response, 409, 'text/plain; charset=utf-8', 'Already used' );
                return;
            }

            if (
                process.env.PHASE27_DISABLE_CANDIDATE_DESCRIPTOR_CHECK !==
                    '1' &&
                ( submission.target !== candidateIntent.target ||
                    submission.digest !== candidateIntent.digest )
            ) {
                observations.push( {
                    operation: 'candidate-mutation-blocked',
                    value: submission.target,
                } );
                send( response, 409, 'text/plain; charset=utf-8', 'Mismatch' );
                return;
            }

            // This synchronous state transition models atomic compare-and-consume.
            candidateIntent.used = true;
            observations.push( {
                operation: 'candidate-effect',
                value: candidateIntent.target,
            } );
            send( response, 204, 'text/plain; charset=utf-8', '' );
            return;
        }

        if (
            request.method === 'GET' &&
            request.url?.startsWith( '/isolated-confirm?' )
        ) {
            send(
                response,
                200,
                'text/html; charset=utf-8',
                isolatedConfirmationFixture
            );
            return;
        }

        if (
            request.method === 'GET' &&
            request.url?.startsWith( '/top-level-confirm?' )
        ) {
            send(
                response,
                200,
                'text/html; charset=utf-8',
                isolatedConfirmationFixture
            );
            return;
        }

        if (
            request.method === 'GET' &&
            request.url === '/intent?id=file-write-1'
        ) {
            send(
                response,
                200,
                'application/json; charset=utf-8',
                JSON.stringify( {
                    action: intent.action,
                    digest: intent.digest,
                    id: intent.id,
                    label: intent.label,
                    target: intent.target,
                } )
            );
            return;
        }

        if ( request.method === 'POST' && request.url === '/mutate-intent' ) {
            const mutation = JSON.parse( await readBody( request ) ) as {
                intent: string;
                target: string;
            };
            observations.push( {
                operation: 'mutation-blocked',
                value: mutation.target,
            } );
            send( response, 409, 'text/plain; charset=utf-8', 'Immutable' );
            return;
        }

        if (
            request.method === 'POST' &&
            request.url === '/confirm-and-redeem'
        ) {
            const confirmation = JSON.parse( await readBody( request ) ) as {
                intent: string;
                password: string;
            };

            if ( confirmation.intent !== intent.id ) {
                send( response, 404, 'text/plain; charset=utf-8', 'Not found' );
                return;
            }

            if ( intent.used ) {
                observations.push( {
                    operation: 'replay-blocked',
                    value: intent.id,
                } );
                send( response, 409, 'text/plain; charset=utf-8', 'Already used' );
                return;
            }

            if ( confirmation.password !== 'victim-secret' ) {
                observations.push( {
                    operation: 'unauthorized-redemption-blocked',
                    value: intent.id,
                } );
                send( response, 403, 'text/plain; charset=utf-8', 'Forbidden' );
                return;
            }

            // This synchronous state transition models atomic compare-and-consume.
            intent.used = true;
            observations.push( {
                operation: 'effect',
                value: intent.target,
            } );
            send(
                response,
                200,
                'application/json; charset=utf-8',
                JSON.stringify( { status: 'executed' } )
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
                server.closeAllConnections();
            } ),
        observations: () => [ ...observations ],
        url: 'http://127.0.0.1:' + address.port,
    };
}
