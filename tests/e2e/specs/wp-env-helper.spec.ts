/**
 * Unit-style guard for the wp-env command helpers (WPENV-01..04).
 *
 * Pure-logic assertions — no browser, no wp-env container. Proves the argv
 * builders keep hostile-looking values as single literal arguments and that
 * `execFile` passes them verbatim (no shell expansion), which is the property
 * that keeps CodeQL's shell-command-construction alerts clear. Fast enough that
 * the ~0 marginal cost of sharing a wp-env-booting CI group is irrelevant.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import { wpEnvRunArgv, containerBash } from '../fixtures/wp-env';

// Values that would be dangerous if ever reinterpreted by a shell.
const HOSTILE = [ 'a b', 'a"b', "a'b", 'a;b', '$(id)', '`id`', 'a|b', 'a&&b', 'a>b', '../x' ];

test.describe( 'wp-env command helpers — argv safety', () => {
	// CI exports WP_ENV_CONFIG_PATH=.wp-env.e2e.json for the whole E2E run, which
	// makes wpEnvRunArgv() inject `--config …`. Neutralize it per-test so the argv
	// shape is deterministic regardless of the ambient environment; WPENV-02 sets it
	// back explicitly to exercise the --config path.
	let savedConfigPath: string | undefined;

	test.beforeEach( () => {
		savedConfigPath = process.env.WP_ENV_CONFIG_PATH;
		delete process.env.WP_ENV_CONFIG_PATH;
	} );

	test.afterEach( () => {
		if ( savedConfigPath === undefined ) {
			delete process.env.WP_ENV_CONFIG_PATH;
		} else {
			process.env.WP_ENV_CONFIG_PATH = savedConfigPath;
		}
	} );

	test( 'WPENV-01: wpEnvRunArgv keeps each value one literal element after --', () => {
		for ( const value of HOSTILE ) {
			const argv = wpEnvRunArgv( 'cli', [ 'wp', 'user', 'meta', 'update', '1', value ] );

			expect( argv ).toEqual( [
				'wp-env',
				'run',
				'cli',
				'--',
				'wp',
				'user',
				'meta',
				'update',
				'1',
				value,
			] );
			// Exactly one array slot equals the raw value — never split or merged.
			expect( argv.filter( ( token ) => token === value ) ).toHaveLength( 1 );
		}
	} );

	test( 'WPENV-02: wpEnvRunArgv threads --config from WP_ENV_CONFIG_PATH ahead of run', () => {
		// beforeEach cleared the ambient value; afterEach restores it.
		process.env.WP_ENV_CONFIG_PATH = '.wp-env.e2e.json';
		expect( wpEnvRunArgv( 'cli', [ 'wp', 'option', 'get', 'siteurl' ] ) ).toEqual( [
			'wp-env',
			'--config',
			'.wp-env.e2e.json',
			'run',
			'cli',
			'--',
			'wp',
			'option',
			'get',
			'siteurl',
		] );

		delete process.env.WP_ENV_CONFIG_PATH;
		expect( wpEnvRunArgv( 'cli', [ 'wp' ] ) ).toEqual( [ 'wp-env', 'run', 'cli', '--', 'wp' ] );
	} );

	test( 'WPENV-03: containerBash keeps the script constant and values as trailing positionals', () => {
		const argv = containerBash( 'mkdir -p "$1" && cp "$2" "$3"', [ '/a b', '$(id)', "x'y" ] );

		expect( argv ).toEqual( [
			'bash',
			'-lc',
			'mkdir -p "$1" && cp "$2" "$3"',
			'bash',
			'/a b',
			'$(id)',
			"x'y",
		] );
		// The script text never contains the dynamic values.
		expect( argv[ 2 ] ).not.toContain( '$(id)' );
	} );

	test( 'WPENV-04: execFile passes arguments verbatim — no shell expansion', () => {
		for ( const value of HOSTILE ) {
			const out = execFileSync(
				'node',
				[ '-e', 'process.stdout.write(process.argv[1]||"")', value ],
				{ encoding: 'utf8' }
			);
			expect( out ).toBe( value );
		}
	} );
} );
