/**
 * Access tab — grant capability flow (ACCESS-01, ACCESS-02)
 *
 * Regression for the dead-button bug: the Settings → Sudo → Access "Grant
 * Capability" button had NO client JavaScript, so clicking it did nothing. The
 * unit tests exercised the PHP handler (handle_grant_cap) directly and never
 * clicked the button, so the inert UI shipped unnoticed. These specs click the
 * real button and assert the end-to-end effect.
 *
 * The grant AJAX (wp_sudo_grant_cap) is gated by the options.wp_sudo_access rule,
 * so it requires an active sudo session:
 *
 * - ACCESS-01: with NO active sudo session, the Gate blocks the request and the
 *   result span shows the sudo_required reauthentication message; the capability
 *   is NOT granted.
 * - ACCESS-02: with an active sudo session, the grant succeeds and the capability
 *   lands on the target user.
 *
 * Source: includes/class-admin.php handle_grant_cap() + render_access_tab() (verified)
 * Source: admin/js/wp-sudo-admin.js — Access module wires #wp-sudo-grant-submit (verified)
 * Source: includes/class-action-registry.php — options.wp_sudo_access gates wp_sudo_grant_cap (verified)
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import { execSync } from 'child_process';
import { wpEnvRun } from '../fixtures/wp-env';

// 'cli' targets the development site (port 8889) — the same site the browser uses.
const WP_ENV_RUN_CLI = wpEnvRun( 'cli' );
const ACCESS_QUERY = 'page=wp-sudo-settings&tab=access';

/** Run a WP-CLI command in the dev-site container and return trimmed stdout. */
function cli( command: string ): string {
	return execSync( `${ WP_ENV_RUN_CLI } ${ command }`, { encoding: 'utf8' } ).trim();
}

/**
 * Run a fixture-only WP-CLI command without loading plugins.
 *
 * Access grants are themselves gateable in browser/admin flows. Test setup and
 * cleanup should not be intercepted by Sudo's CLI policy while preparing users.
 */
function fixtureCli( command: string ): string {
	return cli( `wp --skip-plugins ${ command }` );
}

/** Whether a user effectively holds a capability (robust vs. list-caps formatting). */
function userCan( userId: number, cap: string ): boolean {
	return fixtureCli( `eval "echo user_can( ${ userId }, '${ cap }' ) ? 'yes' : 'no';"` ) === 'yes';
}

test.describe( 'Access tab — grant capability', () => {
	let targetId: number;

	test.beforeAll( () => {
		// The granting admin (user 1) must hold manage_wp_sudo to reach the Access
		// tab and authorize grants — guarantee it regardless of activation state.
		fixtureCli( 'user add-cap 1 manage_wp_sudo --quiet' );

		// Dedicated editor target (idempotent — reuse if a previous run left it).
		const existing = fixtureCli(
			`user get e2e_grant_target --field=ID 2>/dev/null || echo ''`
		);
		targetId = existing
			? parseInt( existing, 10 )
			: parseInt(
					fixtureCli(
						'user create e2e_grant_target e2e_grant@example.com ' +
							'--role=editor --user_pass=password --porcelain'
					),
					10
			  );
	} );

	test.afterAll( () => {
		fixtureCli( `user delete ${ targetId } --yes --reassign=1` );
	} );

	test.beforeEach( () => {
		// Clean slate: strip the cap and clear the Gate's blocked-action transients
		// (which otherwise persist between runs and affect gating).
		fixtureCli( `user remove-cap ${ targetId } manage_wp_sudo --quiet 2>/dev/null || true` );
		fixtureCli( 'transient delete --all --quiet 2>/dev/null || true' );
	} );

	test( 'ACCESS-01: grant is gated without an active sudo session', async ( {
		page,
		visitAdminPage,
		context,
	} ) => {
		// The global storageState carries no sudo session.
		const cookies = await context.cookies();
		expect( cookies.some( ( c ) => c.name.startsWith( 'wp_sudo' ) ) ).toBe( false );

		await visitAdminPage( 'options-general.php', ACCESS_QUERY );
		await page.fill( '#wp-sudo-grant-user', String( targetId ) );
		await page.selectOption( '#wp-sudo-grant-cap', 'manage_wp_sudo' );
		await page.click( '#wp-sudo-grant-submit' );

		// The Gate returns sudo_required; the JS surfaces data.message verbatim.
		await expect( page.locator( '#wp-sudo-grant-result' ) ).toContainText(
			/reauthentication|sudo session/i
		);

		// Nothing was granted.
		expect( userCan( targetId, 'manage_wp_sudo' ) ).toBe( false );
	} );

	test( 'ACCESS-02: grant succeeds with an active sudo session', async ( {
		page,
		visitAdminPage,
	} ) => {
		await activateSudoSession( page );

		await visitAdminPage( 'options-general.php', ACCESS_QUERY );
		await page.fill( '#wp-sudo-grant-user', String( targetId ) );
		await page.selectOption( '#wp-sudo-grant-cap', 'manage_wp_sudo' );
		await page.click( '#wp-sudo-grant-submit' );

		// Success feedback in the aria-live result span.
		await expect( page.locator( '#wp-sudo-grant-result' ) ).toContainText(
			/granted/i
		);

		// The capability landed on the target user.
		expect( userCan( targetId, 'manage_wp_sudo' ) ).toBe( true );
	} );
} );
