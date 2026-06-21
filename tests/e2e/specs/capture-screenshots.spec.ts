/**
 * Capture WordPress.org listing screenshots (ORG-02 / Plan 14-04).
 *
 * Deterministic replacement for the manual "browser handoff" screenshot step.
 * Writes .wordpress-org/screenshot-{1..7}.png from the live wp-env dev site,
 * reusing the pre-authenticated admin storageState (see global-setup.ts).
 *
 * The seven shots and their order match the readme.txt `== Screenshots ==`
 * captions exactly (caption N <-> screenshot-N.png):
 *   1 Challenge page · 2 Settings tab · 3 Gated Actions tab · 4 Rule Tester tab
 *   5 Access tab · 6 Dashboard widget · 7 Break-glass recovery notice
 *
 * Run:      npm run screenshots          (sets WP_SUDO_CAPTURE=1)
 * Requires: wp-env running (npm run env:start) + Playwright browser installed.
 *
 * GUARDED: no-ops unless WP_SUDO_CAPTURE is set, so the normal e2e suite/CI
 * never overwrites the committed screenshot set.
 *
 * Verified surfaces (against live source):
 *   - Challenge page:   admin.php?page=wp-sudo-challenge, card #wp-sudo-challenge-card
 *                       (class-challenge.php PAGE_SLUG = 'wp-sudo-challenge')
 *   - Settings page:    options-general.php?page=wp-sudo-settings (add_options_page,
 *                       class-admin.php:303); tabs settings|actions|tester|access (:1187);
 *                       active tab nav .nav-tab-active (:1221)
 *   - Dashboard widget: #wp_sudo_activity (class-dashboard-widget.php:32)
 *   - Recovery notice:  .wp-sudo-notice rendered by render_recovery_mode_notice()
 *                       (class-admin.php:2509) only while WP_SUDO_RECOVERY_MODE is set
 */
import { test } from '../fixtures/test';
import { execSync } from 'child_process';
import { wpEnvRun } from '../fixtures/wp-env';
import * as path from 'path';

const CAPTURE = !! process.env.WP_SUDO_CAPTURE;
const OUT_DIR = path.resolve( __dirname, '../../../.wordpress-org' );
const CLI = wpEnvRun( 'cli' ); // dev site (port 8889) — the site the browser uses

const shot = ( n: number ): string => path.join( OUT_DIR, `screenshot-${ n }.png` );

test.describe( 'WordPress.org listing screenshots (ORG-02)', () => {
	test.skip(
		! CAPTURE,
		'Set WP_SUDO_CAPTURE=1 (npm run screenshots) to capture the .wordpress-org set.'
	);

	// Consistent capture viewport for the listing assets.
	test.use( { viewport: { width: 1280, height: 960 } } );

	test( 'capture listing screenshots', async ( { page, visitAdminPage } ) => {
		// 1 — Challenge page (reauthentication interstitial). Admin is logged in but
		// has no active sudo session, so the challenge card renders.
		await visitAdminPage( 'admin.php', 'page=wp-sudo-challenge' );
		await page.locator( '#wp-sudo-challenge-card' ).waitFor();
		await page.screenshot( { path: shot( 1 ), fullPage: true } );

		// 2..5 — Settings → Sudo tabs. The .nav-tab-active anchor only exists on the
		// Sudo settings page, so a wrong/error page can't satisfy the wait.
		const tabs: Array< [ number, string ] > = [
			[ 2, 'settings' ],
			[ 3, 'actions' ],
			[ 4, 'tester' ],
			[ 5, 'access' ], // post-Phase-13.1 user-picker + plain-English labels
		];
		for ( const [ n, tab ] of tabs ) {
			await visitAdminPage( 'options-general.php', `page=wp-sudo-settings&tab=${ tab }` );
			await page.locator( '.nav-tab-active' ).waitFor();
			await page.screenshot( { path: shot( n ), fullPage: true } );
		}

		// 6 — Session Activity dashboard widget (cropped to the widget postbox).
		await visitAdminPage( 'index.php' );
		const widget = page.locator( '#wp_sudo_activity' );
		await widget.scrollIntoViewIfNeeded();
		await widget.screenshot( { path: shot( 6 ) } );

		// 7 — Break-glass recovery notice. Toggle WP_SUDO_RECOVERY_MODE on for this one
		// shot only; the try/finally guarantees it is removed even if the shot fails.
		try {
			execSync( `${ CLI } wp config set WP_SUDO_RECOVERY_MODE true --raw`, { stdio: 'ignore' } );
			// PHP opcache can serve a stale wp-config (without the new constant) for up to
			// opcache.revalidate_freq seconds, so the notice may miss the first load. Poll:
			// reload the settings page until the recovery notice renders.
			const notice = page
				.locator( '.wp-sudo-notice' )
				.filter( { hasText: 'recovery mode is active' } );
			let rendered = false;
			for ( let attempt = 0; attempt < 6; attempt++ ) {
				await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings' );
				if ( ( await notice.count() ) > 0 ) {
					rendered = true;
					break;
				}
				await page.waitForTimeout( 2500 );
			}
			if ( ! rendered ) {
				throw new Error(
					'Recovery-mode notice did not render after enabling WP_SUDO_RECOVERY_MODE'
				);
			}
			await notice.first().waitFor();
			await page.screenshot( { path: shot( 7 ), fullPage: true } );
		} finally {
			try {
				execSync( `${ CLI } wp config delete WP_SUDO_RECOVERY_MODE`, { stdio: 'ignore' } );
			} catch {
				// already absent — nothing to clean up
			}
		}
	} );
} );
