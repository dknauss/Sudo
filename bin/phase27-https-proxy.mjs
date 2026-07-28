import { createServer } from 'node:https';
import { request } from 'node:http';
import { readFileSync } from 'node:fs';

const [ keyPath, certPath, listenPort = '9443', upstreamPort = '8889' ] =
    process.argv.slice( 2 );

if ( ! keyPath || ! certPath ) {
    throw new Error(
        'Usage: node bin/phase27-https-proxy.mjs KEY CERT [PORT] [UPSTREAM_PORT]'
    );
}

const server = createServer(
    {
        cert: readFileSync( certPath ),
        key: readFileSync( keyPath ),
    },
    ( incoming, outgoing ) => {
        const upstream = request(
            {
                headers: {
                    ...incoming.headers,
                    'x-forwarded-proto': 'https',
                },
                hostname: '127.0.0.1',
                method: incoming.method,
                path: incoming.url,
                port: Number( upstreamPort ),
            },
            ( response ) => {
                const headers = { ...response.headers };
                if ( typeof headers.location === 'string' ) {
                    headers.location = headers.location.replace(
                        `https://localhost:${ upstreamPort }`,
                        `https://localhost:${ listenPort }`
                    );
                }
                outgoing.writeHead( response.statusCode ?? 502, headers );
                response.pipe( outgoing );
            }
        );

        upstream.on( 'error', ( error ) => {
            outgoing.writeHead( 502, { 'content-type': 'text/plain' } );
            outgoing.end( error.message );
        } );
        incoming.pipe( upstream );
    }
);

server.listen( Number( listenPort ), '127.0.0.1', () => {
    process.stdout.write( `READY https://localhost:${ listenPort }\n` );
} );
