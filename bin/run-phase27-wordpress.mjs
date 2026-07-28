import { createHash } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '../', import.meta.url ) );
const adapter = fileURLToPath(
  new URL(
    "../tests/e2e/fixtures/phase27-wordpress-adapter.php",
    import.meta.url,
  ),
);
const upgraderHelper = fileURLToPath(
  new URL("../tests/e2e/fixtures/phase27-real-upgrader.php", import.meta.url),
);
const mutationManifest = JSON.parse(
    readFileSync(
        new URL(
            '../tests/e2e/research/phase27-wordpress-guard-manifest.json',
      import.meta.url,
        ),
    "utf8",
  ),
);
const expectedPhpVersion = '8.2';
const expectedWordPressVersion = '7.0.2';
const isMultisiteLane = process.env.PHASE27_MULTISITE === "1";
const expectedWordPressPort =
  process.env.PHASE27_WP_PORT ?? (isMultisiteLane ? "8893" : "8892");
const expectedProxyPort = isMultisiteLane ? "9444" : "9443";
const wpEnvConfig = fileURLToPath(
  new URL(
    isMultisiteLane
      ? "../tests/e2e/phase27.multisite.wp-env.json"
      : "../tests/e2e/phase27.wp-env.json",
    import.meta.url,
  ),
);
const wpEnvBin = fileURLToPath(
  new URL("../node_modules/@wordpress/env/bin/wp-env", import.meta.url),
);

function run( command, args, options = {} ) {
    const result = spawnSync( command, args, {
        cwd: root,
        encoding: 'utf8',
        ...options,
    } );

    if ( result.status !== 0 ) {
        throw new Error(
            [
                `${ command } exited ${ result.status ?? 'without a status' }.`,
                result.stdout ?? '',
                result.stderr ?? '',
            ]
                .filter( Boolean )
        .join("\n"),
        );
    }

    return String( result.stdout ?? '' ).trim();
}

function findContainer( installPath ) {
    const names = run( 'docker', [
        'ps',
        '--filter',
        'label=com.docker.compose.service=wordpress',
        '--format',
        '{{.Names}}',
    ] )
        .split( '\n' )
        .filter( Boolean );
    return (
        names.find( ( name ) => {
            const workingDirectory = run( 'docker', [
                'inspect',
                '--format',
                '{{index .Config.Labels "com.docker.compose.project.working_dir"}}',
                name,
            ] );
            return workingDirectory === installPath;
        } ) ?? ''
    );
}

function cleanupContainer(
    container,
    adapterTouched,
    hadAdapter,
    adapterBackup,
  hadUpgraderHelper,
  upgraderHelperBackup,
    hadMutationMarker,
  mutationBackup,
) {
    const errors = [];
    if ( container === '' || ! adapterTouched ) {
        return errors;
    }

    if ( hadMutationMarker ) {
        const restoreMutation = spawnSync(
            'docker',
            [ 'cp', mutationBackup, `${ container }:/tmp/phase27-mutation` ],
      { cwd: root, stdio: "ignore" },
        );
        if ( restoreMutation.status !== 0 ) {
            errors.push( 'Could not restore the pre-existing mutation marker.' );
        }
    } else {
        const removeMutation = spawnSync(
            'docker',
            [ 'exec', container, 'rm', '-f', '/tmp/phase27-mutation' ],
      { cwd: root, stdio: "ignore" },
        );
        if ( removeMutation.status !== 0 ) {
            errors.push( 'Could not remove the Phase 27 mutation marker.' );
        }
    }
    if ( hadAdapter ) {
        const restoreAdapter = spawnSync(
            'docker',
            [
                'cp',
                adapterBackup,
                `${ container }:/var/www/html/wp-content/mu-plugins/phase27-wordpress-adapter.php`,
            ],
      { cwd: root, stdio: "ignore" },
        );
        if ( restoreAdapter.status !== 0 ) {
            errors.push( 'Could not restore the pre-existing research adapter.' );
        }
    } else {
        const removeAdapter = spawnSync(
            'docker',
            [
                'exec',
                container,
                'rm',
                '-f',
                '/var/www/html/wp-content/mu-plugins/phase27-wordpress-adapter.php',
            ],
      { cwd: root, stdio: "ignore" },
        );
        if ( removeAdapter.status !== 0 ) {
            errors.push( 'Could not remove the Phase 27 research adapter.' );
        }
    }
  if (hadUpgraderHelper) {
    const restoreHelper = spawnSync(
      "docker",
      [
        "cp",
        upgraderHelperBackup,
        `${container}:/var/www/html/wp-content/mu-plugins/phase27-real-upgrader.php`,
      ],
      { cwd: root, stdio: "ignore" },
    );
    if (restoreHelper.status !== 0) {
      errors.push("Could not restore the pre-existing upgrader helper.");
    }
  } else {
    const removeHelper = spawnSync(
      "docker",
      [
        "exec",
        container,
        "rm",
        "-f",
        "/var/www/html/wp-content/mu-plugins/phase27-real-upgrader.php",
      ],
      { cwd: root, stdio: "ignore" },
    );
    if (removeHelper.status !== 0) {
      errors.push("Could not remove the Phase 27 upgrader helper.");
        }
    }

    return errors;
}

async function main() {
    let container = process.env.PHASE27_WP_CONTAINER?.trim() ?? '';
    let adapterTouched = false;
    let hadAdapter = false;
  let hadUpgraderHelper = false;
    let hadMutationMarker = false;
    let proxy;
    let stackStarted = false;
    let runDirectory = '';
    let adapterBackup = '';
  let upgraderHelperBackup = "";
    let mutationBackup = '';

    try {
        if ( container === '' ) {
            const initialStatus = spawnSync(
                process.execPath,
                [ wpEnvBin, '--config', wpEnvConfig, 'status' ],
        { cwd: root, encoding: "utf8" },
            );
            let status =
        String(initialStatus.stdout ?? "") + String(initialStatus.stderr ?? "");
      let installPath = status.match(/install path:\s*(.+)/)?.[1]?.trim() ?? "";
      container = installPath === "" ? "" : findContainer(installPath);
            if ( container === '' ) {
                stackStarted = true;
        run(process.execPath, [wpEnvBin, "--config", wpEnvConfig, "start"]);
                status = run( process.execPath, [
                    wpEnvBin,
                    '--config',
                    wpEnvConfig,
                    'status',
                ] );
        installPath = status.match(/install path:\s*(.+)/)?.[1]?.trim() ?? "";
        container = installPath === "" ? "" : findContainer(installPath);
            }
            if ( installPath === '' ) {
                throw new Error( 'wp-env did not report its install path.' );
            }
        }
        if ( container === '' ) {
            throw new Error(
        `No Phase 27 WordPress container exposes localhost:${expectedWordPressPort}.`,
            );
        }

        runDirectory = mkdtempSync( join( tmpdir(), 'phase27-run-' ) );
        adapterBackup = join( runDirectory, 'prior-adapter.php' );
    upgraderHelperBackup = join(runDirectory, "prior-upgrader-helper.php");
        mutationBackup = join( runDirectory, 'prior-mutation' );
        hadAdapter =
            spawnSync(
                'docker',
                [
                    'exec',
                    container,
                    'test',
                    '-f',
                    '/var/www/html/wp-content/mu-plugins/phase27-wordpress-adapter.php',
                ],
        { cwd: root, stdio: "ignore" },
            ).status === 0;
        if ( hadAdapter ) {
            run( 'docker', [
                'cp',
                `${ container }:/var/www/html/wp-content/mu-plugins/phase27-wordpress-adapter.php`,
                adapterBackup,
            ] );
        }
    hadUpgraderHelper =
      spawnSync(
        "docker",
        [
          "exec",
          container,
          "test",
          "-f",
          "/var/www/html/wp-content/mu-plugins/phase27-real-upgrader.php",
        ],
        { cwd: root, stdio: "ignore" },
      ).status === 0;
    if (hadUpgraderHelper) {
      run("docker", [
        "cp",
        `${container}:/var/www/html/wp-content/mu-plugins/phase27-real-upgrader.php`,
        upgraderHelperBackup,
      ]);
    }
        hadMutationMarker =
            spawnSync(
                'docker',
                [ 'exec', container, 'test', '-f', '/tmp/phase27-mutation' ],
        { cwd: root, stdio: "ignore" },
            ).status === 0;
        if ( hadMutationMarker ) {
            run( 'docker', [
                'cp',
                `${ container }:/tmp/phase27-mutation`,
                mutationBackup,
            ] );
        }
        run( 'docker', [
            'exec',
            container,
            'mkdir',
            '-p',
            '/var/www/html/wp-content/mu-plugins',
        ] );
    run("docker", ["exec", container, "rm", "-f", "/tmp/phase27-mutation"]);
        adapterTouched = true;
        run( 'docker', [
            'cp',
            adapter,
            `${ container }:/var/www/html/wp-content/mu-plugins/phase27-wordpress-adapter.php`,
        ] );
    run("docker", [
      "cp",
      upgraderHelper,
      `${container}:/var/www/html/wp-content/mu-plugins/phase27-real-upgrader.php`,
        ] );

        const hostAdapterDigest = createHash( 'sha256' )
            .update( readFileSync( adapter ) )
            .digest( 'hex' );
        const containerAdapterDigest = run( 'docker', [
            'exec',
            container,
            'sha256sum',
            '/var/www/html/wp-content/mu-plugins/phase27-wordpress-adapter.php',
        ] ).split( /\s+/ )[ 0 ];
        if ( containerAdapterDigest !== hostAdapterDigest ) {
            throw new Error(
        "The adapter installed in WordPress differs from this checkout.",
            );
        }
    const hostUpgraderHelperDigest = createHash("sha256")
      .update(readFileSync(upgraderHelper))
      .digest("hex");
    const containerUpgraderHelperDigest = run("docker", [
      "exec",
      container,
      "sha256sum",
      "/var/www/html/wp-content/mu-plugins/phase27-real-upgrader.php",
    ]).split(/\s+/)[0];
    if (containerUpgraderHelperDigest !== hostUpgraderHelperDigest) {
      throw new Error(
        "The upgrader helper installed in WordPress differs from this checkout.",
            );
        }

        const wordpressVersion = run( 'docker', [
            'exec',
            container,
            'php',
            '-r',
            'require "/var/www/html/wp-load.php"; echo get_bloginfo("version");',
        ] );
        if ( wordpressVersion !== expectedWordPressVersion ) {
            throw new Error(
        `Expected WordPress ${expectedWordPressVersion}, found ${wordpressVersion}.`,
            );
        }

        const phpVersion = run( 'docker', [
            'exec',
            container,
            'php',
            '-r',
            'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;',
        ] );
        if ( phpVersion !== expectedPhpVersion ) {
            throw new Error(
        `Expected PHP ${expectedPhpVersion}, found ${phpVersion}.`,
            );
        }
    const isMultisite = run("docker", [
      "exec",
      container,
      "php",
      "-r",
      'require "/var/www/html/wp-load.php"; echo is_multisite() ? "1" : "0";',
    ]);
    if ((isMultisite === "1") !== isMultisiteLane) {
      throw new Error(
        `Expected ${isMultisiteLane ? "a multisite" : "a single-site"} WordPress install.`,
            );
        }

        const keyPath = join( runDirectory, 'localhost-key.pem' );
        const certPath = join( runDirectory, 'localhost-cert.pem' );
        run( 'openssl', [
            'req',
            '-x509',
            '-newkey',
            'rsa:2048',
            '-nodes',
            '-keyout',
            keyPath,
            '-out',
            certPath,
            '-days',
            '1',
            '-subj',
            '/CN=localhost',
            '-addext',
            'subjectAltName=DNS:localhost,IP:127.0.0.1',
        ] );

        proxy = spawn(
            process.execPath,
            [
                'bin/phase27-https-proxy.mjs',
                keyPath,
                certPath,
        expectedProxyPort,
                expectedWordPressPort,
            ],
      { cwd: root, stdio: ["ignore", "pipe", "inherit"] },
        );
        await new Promise( ( resolve, reject ) => {
            const timeout = setTimeout(
                () => reject( new Error( 'Timed out starting the HTTPS proxy.' ) ),
        5_000,
            );
            proxy.once( 'error', reject );
            proxy.once( 'exit', ( code ) => {
                reject( new Error( `HTTPS proxy exited early with ${ code }.` ) );
            } );
            proxy.stdout.once( 'data', ( chunk ) => {
                if ( String( chunk ).includes( 'READY ' ) ) {
                    clearTimeout( timeout );
                    resolve();
                }
            } );
        } );

        const playwrightEnvironment = {
            ...process.env,
            WP_BASE_URL:
        process.env.WP_BASE_URL ?? `https://localhost:${expectedProxyPort}`,
            WP_SUDO_SKIP_WP_ENV_CAP_SETUP: '1',
        };
        const playwrightArgs = [
            'playwright',
            'test',
            '--config',
            'tests/e2e/research-wordpress.playwright.config.ts',
            '--project=chromium',
        ];
        const baseline = spawnSync( 'npx', playwrightArgs, {
            cwd: root,
            encoding: 'utf8',
            env: playwrightEnvironment,
        } );
        process.stdout.write( baseline.stdout ?? '' );
        process.stderr.write( baseline.stderr ?? '' );
        if ( baseline.status !== 0 ) {
            return baseline.status ?? 1;
        }

        const mutationResults = [];
        for ( const guard of mutationManifest.guards ) {
            const mutationSource = fileURLToPath(
        new URL(`../tests/e2e/research/mutations/${guard.id}`, import.meta.url),
            );
            run( 'docker', [
                'cp',
                mutationSource,
                `${ container }:/tmp/phase27-mutation`,
            ] );
            const result = spawnSync(
                'npx',
                [ ...playwrightArgs, '--grep', guard.focusedTest ],
                {
                    cwd: root,
                    encoding: 'utf8',
                    env: playwrightEnvironment,
        },
            );
      const output = String(result.stdout ?? "") + String(result.stderr ?? "");
            const firstFailure =
                output.match( /Error: (P27-WP-[A-Z0-9-]+)/ )?.[ 1 ] ?? '';
            mutationResults.push( {
                assertionId: guard.assertionId,
                firstFailure,
                guard: guard.id,
                killed:
                    result.status !== 0 &&
                    firstFailure === guard.assertionId &&
                    output.includes( guard.focusedTest ),
            } );
        }

        console.log(
            JSON.stringify(
                {
                    adapterSha256: hostAdapterDigest,
          upgraderHelperSha256: hostUpgraderHelperDigest,
                    mutations: mutationResults,
          multisite: isMultisiteLane,
                    phpVersion,
                    wordpressVersion,
                },
                null,
        2,
      ),
        );

        return mutationResults.some( ( result ) => ! result.killed ) ? 1 : 0;
    } finally {
        proxy?.kill( 'SIGTERM' );
        const cleanupErrors = cleanupContainer(
            container,
            adapterTouched,
            hadAdapter,
            adapterBackup,
      hadUpgraderHelper,
      upgraderHelperBackup,
            hadMutationMarker,
      mutationBackup,
        );
        if ( stackStarted ) {
            const stop = spawnSync(
                process.execPath,
                [ wpEnvBin, '--config', wpEnvConfig, 'stop' ],
        { cwd: root, stdio: "inherit" },
            );
            if ( stop.status !== 0 ) {
                cleanupErrors.push( 'Could not stop the runner-owned wp-env stack.' );
            }
        }
        if ( runDirectory !== '' && cleanupErrors.length === 0 ) {
            rmSync( runDirectory, { force: true, recursive: true } );
        }
        if ( cleanupErrors.length > 0 ) {
            throw new Error(
                [
                    ...cleanupErrors,
                    runDirectory === ''
                        ? ''
                        : `Recovery files retained at ${ runDirectory }.`,
                ]
                    .filter( Boolean )
          .join("\n"),
            );
        }
    }
}

try {
    process.exitCode = await main();
} catch ( error ) {
    console.error( error instanceof Error ? error.message : String( error ) );
    process.exitCode = 1;
}
