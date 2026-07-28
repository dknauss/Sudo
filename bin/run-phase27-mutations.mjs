import {
    lstatSync,
    mkdtempSync,
    readFileSync,
} from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = new URL( '../', import.meta.url );
const rootPath = fileURLToPath( root );
const manifestPath = new URL(
    'tests/e2e/research/phase27-guard-manifest.json',
    root
);
const guardIdCheckPath = fileURLToPath(
    new URL( 'run-phase27-guard-ids.mjs', import.meta.url )
);
const manifest = JSON.parse( readFileSync( manifestPath, 'utf8' ) );
const artifactRoot = mkdtempSync( join( tmpdir(), 'phase27-mutations-' ) );

for ( const dependencyDirectory of [ 'node_modules', 'vendor' ] ) {
    const path = join( rootPath, dependencyDirectory );
    if ( lstatSync( path ).isSymbolicLink() ) {
        console.error( `${ dependencyDirectory } must not be a symlink.` );
        process.exit( 1 );
    }
}

for ( const guard of manifest.guards ) {
    if (
        typeof guard.id !== 'string' ||
        typeof guard.clause !== 'string' ||
        typeof guard.focusedTest !== 'string' ||
        typeof guard.assertionId !== 'string' ||
        ! [ 'pending', 'implemented', 'mutation-verified' ].includes(
            guard.implementationStatus
        )
    ) {
        console.error( `Invalid guard manifest row: ${ JSON.stringify( guard ) }` );
        process.exit( 1 );
    }
}

const classmap = spawnSync(
    'php',
    [
        '-r',
        '$m=require "vendor/composer/autoload_classmap.php";' +
            '$e=realpath(getcwd()."/includes/class-gate.php");' +
            '$a=realpath($m["WP_Sudo\\\\Gate"]??"");' +
            'exit($a===$e?0:1);',
    ],
    { cwd: rootPath }
);
if ( classmap.status !== 0 ) {
    console.error( 'Composer classmap resolves WP_Sudo production code elsewhere.' );
    process.exit( 1 );
}

const head = spawnSync( 'git', [ 'rev-parse', 'HEAD' ], {
    cwd: rootPath,
    encoding: 'utf8',
} ).stdout.trim();
if ( process.env.PHASE27_REQUIRE_CLEAN === '1' ) {
    const status = spawnSync( 'git', [ 'status', '--porcelain' ], {
        cwd: rootPath,
        encoding: 'utf8',
    } ).stdout.trim();
    if ( status !== '' ) {
        console.error( 'PHASE27_REQUIRE_CLEAN=1 but the worktree is dirty.' );
        process.exit( 1 );
    }
}

// Spawned rather than imported so the same script serves the workflow's
// evidence-audit job, which has no module to import into. Its stdout is routed
// to stderr so this runner's own stdout stays parseable JSON.
const guardIds = spawnSync( process.execPath, [ guardIdCheckPath ], {
    cwd: rootPath,
    encoding: 'utf8',
} );
if ( guardIds.status !== 0 ) {
    process.stderr.write( guardIds.stdout );
    process.stderr.write( guardIds.stderr );
    console.error( 'Phase 27 guard IDs do not match the manifest.' );
    process.exit( 1 );
}

const playwrightArgs = [
    'playwright',
    'test',
    '--config',
    'tests/e2e/research.playwright.config.ts',
    '--project=chromium',
    'tests/e2e/research/phase27-handoff.spec.ts',
];

const baseline = spawnSync( 'npx', [
    ...playwrightArgs,
    '--output',
    join( artifactRoot, 'baseline' ),
], {
    cwd: rootPath,
    encoding: 'utf8',
    env: { ...process.env, PHASE27_MUTATION: '' },
} );

if ( baseline.status !== 0 ) {
    process.stderr.write( baseline.stdout );
    process.stderr.write( baseline.stderr );
    console.error( 'Phase 27 mutation baseline failed.' );
    process.exit( 1 );
}

const results = [];

for ( const guard of manifest.guards ) {
    const result = spawnSync(
        'npx',
        [
            ...playwrightArgs,
            '--grep',
            guard.focusedTest,
            '--output',
            join( artifactRoot, guard.id ),
        ],
        {
            cwd: rootPath,
            encoding: 'utf8',
            env: { ...process.env, PHASE27_MUTATION: guard.id },
        }
    );
    const output = result.stdout + result.stderr;
    const killedAt = output.match( /Error: (P27-[A-Z0-9-]+)/ )?.[ 1 ] ?? '';
    const passed =
        result.status !== 0 &&
        killedAt === guard.assertionId &&
        output.includes( guard.focusedTest );

    results.push( {
        assertionId: guard.assertionId,
        guard: guard.id,
        killedAt,
        passed,
    } );
}

console.log(
    JSON.stringify(
        {
            artifactRoot,
            baseline: 'passed',
            head,
            mutations: results,
        },
        null,
        2
    )
);

if ( results.some( ( result ) => ! result.passed ) ) {
    process.exitCode = 1;
}
