/**
 * Capture WordPress.org listing screenshots (ORG-02 / Plan 14-04).
 *
 * Deterministic replacement for the manual "browser handoff" screenshot step.
 * Writes .wordpress-org/screenshot-{1..5}.png from the live wp-env dev site,
 * reusing the pre-authenticated admin storageState (see global-setup.ts).
 *
 * Run:      npm run screenshots          (sets WP_SUDO_CAPTURE=1)
 * Requires: wp-env running (npm run env:start); recovery mode OFF (the default —
 *           WP_SUDO_RECOVERY_MODE undefined).
 *
 * GUARDED: this spec no-ops unless WP_SUDO_CAPTURE is set, so the normal e2e
 * suite (test:e2e / CI) never overwrites the committed screenshot set.
 *
 * Verified admin surfaces (includes/class-admin.php, includes/class-dashboard-widget.php):
 *   - Settings page URL:   options-general.php?page=wp-sudo-settings (add_options_page, class-admin.php:303)
 *   - Tab slugs:           settings | actions | tester | access   (class-admin.php:1187)
 *   - Dashboard widget id: wp_sudo_activity                       (class-dashboard-widget.php:32)
 */
import { test } from '../fixtures/test';
import * as path from 'path';

const CAPTURE = !! process.env.WP_SUDO_CAPTURE;
const OUT_DIR = path.resolve( __dirname, '../../../.wordpress-org' );

test.describe( 'WordPress.org listing screenshots (ORG-02)', () => {
	test.skip(
		! CAPTURE,
		'Set WP_SUDO_CAPTURE=1 (npm run screenshots) to capture the .wordpress-org set.'
	);

	// Consistent capture viewport for the listing assets.
	test.use( { viewport: { width: 1280, height: 960 } } );

	test( 'capture listing screenshots', async ( { page, visitAdminPage } ) => {
		// 1 — Settings → Sudo (main Settings tab)
		await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings&tab=settings' );
		// Sudo-specific anchor: only the Settings → Sudo page renders this tab nav,
		// so a wrong/error page can't satisfy the wait and capture silently.
		await page.locator( '.nav-tab-active' ).waitFor();
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-1.png' ),
			fullPage: true,
		} );

		// 2 — Gated Actions tab
		await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings&tab=actions' );
		// Sudo-specific anchor: only the Settings → Sudo page renders this tab nav,
		// so a wrong/error page can't satisfy the wait and capture silently.
		await page.locator( '.nav-tab-active' ).waitFor();
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-2.png' ),
			fullPage: true,
		} );

		// 3 — Rule Tester tab
		await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings&tab=tester' );
		// Sudo-specific anchor: only the Settings → Sudo page renders this tab nav,
		// so a wrong/error page can't satisfy the wait and capture silently.
		await page.locator( '.nav-tab-active' ).waitFor();
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-3.png' ),
			fullPage: true,
		} );

		// 4 — Session Activity dashboard widget (cropped to the widget postbox)
		await visitAdminPage( 'index.php' );
		const widget = page.locator( '#wp_sudo_activity' );
		await widget.scrollIntoViewIfNeeded();
		await widget.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-4.png' ),
		} );

		// 5 — Access tab (post-Phase-13.1 user-picker + plain-English labels)
		await visitAdminPage( 'options-general.php', 'page=wp-sudo-settings&tab=access' );
		// Sudo-specific anchor: only the Settings → Sudo page renders this tab nav,
		// so a wrong/error page can't satisfy the wait and capture silently.
		await page.locator( '.nav-tab-active' ).waitFor();
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-5.png' ),
			fullPage: true,
		} );
	} );
} );
