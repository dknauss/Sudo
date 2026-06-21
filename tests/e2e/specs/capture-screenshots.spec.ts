/**
 * Capture WordPress.org listing screenshots (ORG-02 / Plan 14-04).
 *
 * Deterministic replacement for the manual "browser handoff" screenshot step.
 * Writes .wordpress-org/screenshot-{1..9}.png from the live wp-env dev site,
 * reusing the pre-authenticated admin storageState (see global-setup.ts).
 *
 * The nine shots and their order match the readme.txt `== Screenshots ==`
 * captions exactly (caption N <-> screenshot-N.png):
 *   1 Challenge page · 2 Gated plugin activation · 3 Settings tab · 4 Gated Actions
 *   5 Rule Tester · 6 Access tab · 7 Dashboard widget · 8 Admin-bar timer
 *   9 Break-glass recovery notice
 *
 * Ordering matters: shots that must run WITHOUT an active sudo session (1 challenge,
 * 2 gated plugins) come first; shot 8 activates a session for the admin-bar timer.
 *
 * Run:      npm run screenshots          (sets WP_SUDO_CAPTURE=1)
 * Requires: wp-env running (npm run env:start) + Playwright browser installed.
 *
 * GUARDED: no-ops unless WP_SUDO_CAPTURE is set, so the normal e2e suite/CI
 * never overwrites the committed screenshot set.
 *
 * Verified surfaces (against live source):
 *   - Challenge page:    admin.php?page=wp-sudo-challenge, card #wp-sudo-challenge-card
 *   - Gated activation:  plugins.php, .wp-sudo-disabled span (class-gate.php:2228;
 *                        filter_plugin_action_links replaces the Activate link)
 *   - Settings page:     options-general.php?page=wp-sudo-settings (add_options_page,
 *                        class-admin.php:303); tabs settings|actions|tester|access (:1187);
 *                        active tab nav .nav-tab-active (:1221)
 *   - Dashboard widget:  #wp_sudo_activity (class-dashboard-widget.php:32)
 *   - Admin-bar timer:   #wp-admin-bar-wp-sudo-active (class-admin-bar.php node 'wp-sudo-active')
 *   - Recovery notice:   .wp-sudo-notice (render_recovery_mode_notice(), class-admin.php:2509)
 *                        only while WP_SUDO_RECOVERY_MODE is set
 */
import { test, activateSudoSession } from '../fixtures/test';
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

		// 2 — Gated plugin activation. With no active sudo session the gate replaces
		// inactive plugins' "Activate" link with a reauthentication prompt (.wp-sudo-disabled).
		// MUST run before shot 8 activates a session.
		await visitAdminPage( 'plugins.php' );
		await page.locator( '.wp-sudo-disabled' ).first().waitFor();
		await page.screenshot( { path: shot( 2 ), fullPage: true } );

		// 3..6 — Settings → Sudo tabs. The .nav-tab-active anchor only exists on the
		// Sudo settings page, so a wrong/error page can't satisfy the wait.
		const tabs: Array< [ number, string ] > = [
			[ 3, 'settings' ],
			[ 4, 'actions' ],
			[ 5, 'tester' ],
			[ 6, 'access' ], // post-Phase-13.1 user-picker + plain-English labels
		];
		for ( const [ n, tab ] of tabs ) {
			await visitAdminPage( 'options-general.php', `page=wp-sudo-settings&tab=${ tab }` );
			await page.locator( '.nav-tab-active' ).waitFor();
			await page.screenshot( { path: shot( n ), fullPage: true } );
		}

		// 7 — Session Activity dashboard widget (cropped to the widget postbox).
		await visitAdminPage( 'index.php' );
		const widget = page.locator( '#wp_sudo_activity' );
		await widget.scrollIntoViewIfNeeded();
		await widget.screenshot( { path: shot( 7 ) } );

		// 8 — Admin-bar live session timer. Activate a sudo session, then the toolbar
		// shows the countdown node. Clip the top strip so the toolbar is the focus.
		await activateSudoSession( page );
		await visitAdminPage( 'index.php' );
		await page.locator( '#wp-admin-bar-wp-sudo-active' ).waitFor( { state: 'visible' } );
		await page.screenshot( { path: shot( 8 ), clip: { x: 0, y: 0, width: 1280, height: 150 } } );

		// 9 — Break-glass recovery notice. Toggle WP_SUDO_RECOVERY_MODE on for this one
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
			await page.screenshot( { path: shot( 9 ), fullPage: true } );
		} finally {
			try {
				execSync( `${ CLI } wp config delete WP_SUDO_RECOVERY_MODE`, { stdio: 'ignore' } );
			} catch {
				// already absent — nothing to clean up
			}
		}
	} );
} );
