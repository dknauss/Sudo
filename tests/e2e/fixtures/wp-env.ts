/**
 * Safe `wp-env run` command helpers for E2E specs.
 *
 * These build argv ARRAYS and execute them with `execFile`/`execFileSync`
 * (no shell), so interpolated values (meta keys, fixture names, ids) can never
 * be reinterpreted as shell syntax. This is the pattern CodeQL's
 * shell-command-construction query wants; do NOT reintroduce string-concatenated
 * `exec`/`execSync` command lines.
 *
 * Rules of thumb for callers:
 *  - Plain WP-CLI: `wpEnvRunCli('cli', ['wp', 'user', 'meta', 'update', '1', key, val, '--quiet'])`.
 *  - Needs shell operators (&&, ||, redirects, rm -rf, mkdir -p): wrap a CONSTANT
 *    script with `containerBash()` and pass dynamic values as positionals ($1, $2…).
 *  - Ignore-on-failure (`… || true`): catch the rejection in JS, or keep the `|| true`
 *    inside a `containerBash` script — never interpolate into a host command string.
 */
import { execFile, execFileSync } from 'child_process';
import * as path from 'path';
import { promisify } from 'util';

const execFileAsync = promisify( execFile );

// wpEnvRunArgv() returns the logical `wp-env …` command; execute it shell-free as
// `node <wp-env bin>` (mirrors global-setup.ts) so it also works on native Windows,
// where `npx` is `npx.cmd` and execFile cannot launch a .cmd without a shell.
const WP_ENV_BIN = path.join( process.cwd(), 'node_modules/@wordpress/env/bin/wp-env' );

export interface WpEnvRunOptions {
	timeout?: number;
	stdio?: 'pipe' | 'ignore' | 'inherit';
}

/**
 * Build the argv for `wp-env run <container> -- <...args>` with NO shell.
 *
 * The trailing `--` makes wp-env/yargs forward every remaining token verbatim to
 * the container command (documented "double dash" passthrough), so tokens like
 * `--skip-plugins`, `--quiet`, or `-lc` are never parsed as wp-env options.
 */
export function wpEnvRunArgv( container: string, args: string[] ): string[] {
	const configPath = process.env.WP_ENV_CONFIG_PATH?.trim();
	const config = configPath ? [ '--config', configPath ] : [];

	return [ 'wp-env', ...config, 'run', container, '--', ...args ];
}

/**
 * Wrap a CONSTANT shell script for container-side execution, passing dynamic
 * values as positional args ($1, $2, …) instead of interpolating them into the
 * program text. `bash -lc` (login shell) is used so `wp` resolves on PATH.
 *
 * Example: containerBash( 'mkdir -p "$1" && cp "$2" "$3"', [ dir, src, dst ] )
 * runs as `bash -lc '…' bash <dir> <src> <dst>` — $0=bash, $1=dir, $2=src, $3=dst.
 */
export function containerBash( script: string, positionals: string[] = [] ): string[] {
	return [ 'bash', '-lc', script, 'bash', ...positionals ];
}

/** Map the logical `wp-env …` argv onto a shell-free `node <wp-env bin> …` exec. */
function wpEnvExecArgv( container: string, args: string[] ): string[] {
	const [ , ...rest ] = wpEnvRunArgv( container, args ); // drop the logical 'wp-env' token
	return [ WP_ENV_BIN, ...rest ];
}

/** Run `wp-env run <container> -- <...args>` with no shell (async). */
export async function wpEnvRunCli(
	container: string,
	args: string[],
	options: WpEnvRunOptions = {}
): Promise<{ stdout: string; stderr: string }> {
	const { stdout, stderr } = await execFileAsync(
		process.execPath,
		wpEnvExecArgv( container, args ),
		{ encoding: 'utf8', ...options }
	);

	return { stdout: String( stdout ?? '' ), stderr: String( stderr ?? '' ) };
}

/**
 * Run `wp-env run <container> -- <...args>` with no shell (sync); returns trimmed
 * stdout. Guards against `execFileSync` returning `null` when stdout is not piped
 * (e.g. `{ stdio: 'ignore' }`).
 */
export function wpEnvRunCliSync(
	container: string,
	args: string[],
	options: WpEnvRunOptions = {}
): string {
	const out = execFileSync( process.execPath, wpEnvExecArgv( container, args ), {
		encoding: 'utf8',
		...options,
	} );

	return ( out ?? '' ).toString().trim();
}
