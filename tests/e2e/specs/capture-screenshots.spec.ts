/**
 * Capture WordPress.org listing screenshots (ORG-02 / Plan 14-04).
 *
 * Deterministic replacement for the manual "browser handoff" screenshot step.
 * Writes .wordpress-org/screenshot-*.png from the live wp-env dev site, reusing the
 * pre-authenticated admin storageState (see global-setup.ts).
 *
 * Shot order matches the readme.txt `== Screenshots ==` captions (caption N <->
 * screenshot-N.png):
 *   1 Challenge page · 2 Gated plugin activation · 3 Settings tab · 4 Gated Actions
 *   5 Rule Tester · 6 Access tab · 7 Dashboard widget · 8 Admin-bar timer
 *   9 Break-glass recovery notice
 *   11 In-editor indicator panel · 12 In-editor padlock chip + grant snackbar
 *
 * KNOWN DRIFT (pre-existing, not introduced by the 11/12 addition): the committed
 * screenshot-9.png is the Users-list "Sudo Active" view, which is also what readme
 * caption 9 describes — but the first test below writes the break-glass recovery
 * notice to slot 9, and readme caption 10 (in-editor two-factor modal) has no capture
 * step at all. Running this file therefore overwrites screenshot-9 with a different
 * surface than the readme advertises. Left as found; it needs a decision about which
 * asset set is canonical, not a silent renumber.
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

	/**
	 * 11–12 — the in-editor session indicator (#262 / #277, padlock chip from #288).
	 *
	 * Filed as #284: the indicator shipped with no listing screenshot, so nothing in the
	 * asset set showed the one surface that carries sudo state while the admin bar is
	 * hidden. Kept in a SEPARATE test from the 1–9 run above because these two need the
	 * block editor booted and a live session, and because the snackbar is time-boxed —
	 * core/notices auto-dismisses it, so the dispatch and the screenshot must happen
	 * back-to-back in one process. (Capturing it by hand through a browser-automation
	 * round trip loses the race every time.)
	 *
	 *   11 PluginSidebar panel open — "Sudo active — M:SS remaining" (Part B, WP 6.6+).
	 *      The pinned button reads is-pressed/dark here, by design: #288 hands the button
	 *      back to Gutenberg while the panel is open, so the header glyph is shot 12's job.
	 *   12 Panel closed — the unlocked padlock in the header marking the live session
	 *      (#288) plus the announce-once grant snackbar (Part A, the WP 6.4-6.5 baseline).
	 *
	 * Verified surfaces (against live source):
	 *   - Panel:    .wp-sudo-indicator-panel, registerPlugin('wp-sudo-session-indicator')
	 *   - Glyph:    .interface-pinned-items button[aria-controls="…"] carrying
	 *               dashicons-unlock while a session is live (dashicons-lock at rest;
	 *               only the final 60 s adds a red chip, via a body class)
	 *   - Snackbar: .components-snackbar, notice id 'wp-sudo-session-active'
	 */
	test( 'capture in-editor indicator screenshots', async ( { page } ) => {
		const pinned =
			'.interface-pinned-items button[aria-controls="wp-sudo-session-indicator:wp-sudo-session-indicator"]';

		// Seed the indicator with the `wp-sudo-session-granted` event rather than
		// acquiring a real session with activateSudoSession(). Not a shortcut for its own
		// sake: with a REAL session live, this editor screen stops producing rendering
		// frames under headless Chromium, and every capture path — page.screenshot(),
		// locator.screenshot(), even CDP Page.captureScreenshot — blocks until it times
		// out. Seeding drives the same handler a real grant does (admin/js/
		// wp-sudo-session-indicator.js, feed #2), so the panel, the chip and the snackbar
		// are the genuine UI; only the way the clock got set differs.
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() =>
				!! ( window as any ).wp?.apiFetch &&
				!! ( window as any ).wp?.data?.select?.( 'core/notices' ),
			undefined,
			{ timeout: 30_000 }
		);
		// Dismiss the Welcome Guide — its overlay would cover the whole editor.
		await page.evaluate( () => {
			const prefs = ( window as any ).wp?.data?.dispatch?.( 'core/preferences' );
			prefs?.set?.( 'core/edit-post', 'welcomeGuide', false );
			prefs?.set?.( 'core', 'welcomeGuide', false );
		} );
		await page
			.locator( '.components-modal__screen-overlay' )
			.waitFor( { state: 'detached', timeout: 10_000 } )
			.catch( () => {} );
		await page.locator( pinned ).waitFor( { state: 'visible' } );
		await page.evaluate( () =>
			window.dispatchEvent(
				new CustomEvent( 'wp-sudo-session-granted', { detail: { remaining: 900 } } )
			)
		);

		// 12 FIRST, then 11 — the grant snackbar is time-boxed (core/notices auto-dismisses
		// it) and only reliably lands in a capture on the frame sequence right after the
		// grant that created it. Shooting it first, on the seeding grant above, is what
		// makes this deterministic; the panel shot has no such window and can follow.
		//
		// 12 — panel closed: the unlocked padlock in the header (#288) plus the
		// announce-once grant snackbar (Part A). "visible" alone is not enough — it is
		// reached while the snackbar is still fading in, and a shot on that signal catches
		// it at opacity 0, absent from the PNG.
		// Close the default Settings sidebar so the header chip is the subject.
		await page.evaluate( () => {
			for ( const store of [ 'core/edit-post', 'core/edit-site' ] ) {
				const d = ( window as any ).wp.data.dispatch( store );
				if ( d && typeof d.closeGeneralSidebar === 'function' ) {
					d.closeGeneralSidebar();
					return;
				}
			}
		} );
		await page.locator( '.interface-complementary-area' ).waitFor( { state: 'detached' } );
		await page.locator( '.components-snackbar' ).waitFor( { state: 'visible' } );
		// `animations: 'disabled'` finishes the snackbar's slide-and-fade entrance before
		// the frame is taken; without it the capture lands mid-animation and the notice
		// comes out translucent and clipped.
		await page.screenshot( { path: shot( 12 ), animations: 'disabled' } );

		// 11 — panel open, live countdown. Clear the snackbar first so this shot is only
		// about the panel, and wait for it to detach so it cannot bleed into the frame.
		await page.evaluate( () => {
			( window as any ).wp.data
				.dispatch( 'core/notices' )
				.removeNotice( 'wp-sudo-session-active' );
			for ( const store of [ 'core/edit-post', 'core/edit-site' ] ) {
				const d = ( window as any ).wp.data.dispatch( store );
				if ( d && typeof d.openGeneralSidebar === 'function' ) {
					d.openGeneralSidebar(
						'wp-sudo-session-indicator/wp-sudo-session-indicator'
					);
					return;
				}
			}
		} );
		await page.locator( '.wp-sudo-indicator-panel' ).waitFor( { state: 'visible' } );
		await page.locator( '.components-snackbar' ).waitFor( { state: 'detached' } );
		// The sidebar slides in under framer-motion, which is JS-driven and therefore NOT
		// frozen by `animations: 'disabled'` (that only settles CSS animations). Wait until
		// the panel has actually reached the right edge of the viewport, or the capture
		// lands mid-slide and the panel comes out clipped.
		await page.waitForFunction(
			() => {
				const el = document.querySelector( '.interface-complementary-area' );
				return !! el && Math.abs( el.getBoundingClientRect().right - window.innerWidth ) < 1;
			},
			undefined,
			{ timeout: 10_000 }
		);
		await page.screenshot( { path: shot( 11 ), animations: 'disabled' } );
	} );
} );
