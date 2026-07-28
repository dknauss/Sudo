/**
 * Cross-checks the Phase 27 Node-lane guard IDs used in fixture code against the
 * checked-in guard manifest, and is the single place that check exists.
 *
 * It used to exist twice — here and inline in the `phase27-evidence-audit` job
 * of `.github/workflows/phase27-research.yml` — and the copies had drifted. The
 * workflow's regex accepted single quotes only, so a guard written
 * `mutationEnabled( "FOO" )` was seen locally and invisible to CI: the runner
 * passed, then the audit job failed on a code/manifest mismatch that no local
 * command could reproduce. Both callers now spawn this script, so there is one
 * regex to keep correct instead of two written in different quoting contexts.
 *
 * Silent on success; diagnostics go to stderr, so a caller parsing stdout for
 * its own result contract is unaffected. Exits 1 on any mismatch.
 *
 * Node lane only. The WordPress lane (`bin/run-phase27-wordpress.mjs`) selects
 * mutations by patch directory (`tests/e2e/research/mutations/<guard-id>`) over
 * a disjoint manifest, and has no code/manifest bijection check to share.
 */

import { readFileSync } from 'node:fs';

const root = new URL( '../', import.meta.url );
const manifest = JSON.parse(
    readFileSync(
        new URL( 'tests/e2e/research/phase27-guard-manifest.json', root ),
        'utf8'
    )
);
const fixture = readFileSync(
    new URL( 'tests/e2e/fixtures/phase27-research-server.ts', root ),
    'utf8'
);

const codeGuardIds = [
    ...fixture.matchAll( /mutationEnabled\(\s*['"]([A-Z][A-Z0-9_]+)['"]\s*\)/g ),
].map( ( match ) => match[ 1 ] );
const manifestGuardIds = manifest.guards.map( ( guard ) => guard.id );

const unique = ( values ) => [ ...new Set( values ) ].sort();
const repeated = ( values ) =>
    unique(
        values.filter( ( value, index ) => values.indexOf( value ) !== index )
    );

const codeIds = unique( codeGuardIds );
const declaredIds = unique( manifestGuardIds );

const failures = {
    codeOnly: codeIds.filter( ( id ) => ! declaredIds.includes( id ) ),
    // A repeated manifest ID would run the same mutation twice into one
    // artifact directory in bin/run-phase27-mutations.mjs.
    duplicateManifestIds: repeated( manifestGuardIds ),
    duplicateCodeIds: repeated( codeGuardIds ),
    manifestOnly: declaredIds.filter( ( id ) => ! codeIds.includes( id ) ),
};

if ( Object.values( failures ).some( ( ids ) => ids.length > 0 ) ) {
    console.error( JSON.stringify( failures, null, 2 ) );
    process.exit( 1 );
}
