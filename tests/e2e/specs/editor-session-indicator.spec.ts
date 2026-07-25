/**
 * In-editor sudo session-status indicator (#262 / #182)
 *
 * Verifies the build-free indicator module (admin/js/wp-sudo-session-indicator.js)
 * enqueued on block/site-editor screens by Plugin::enqueue_editor_reauth(). The
 * full-screen editor hides the admin bar, so the admin-bar countdown never shows;
 * this module surfaces the same session state via two feeds, NO endpoint, NO polling:
 *
 *   Feed #1 (page load): window.wpSudoEditorReauth.remaining, gated server-side on
 *     Sudo_Session::is_active() (class-plugin.php:383, verified).
 *   Feed #2 (post-grant): the `wp-sudo-session-granted` window CustomEvent the reauth
 *     modal dispatches on a successful `authenticated` grant (detail.remaining).
 *
 *   INDICATOR-01  A successful in-editor grant announces the once-only success
 *                 snackbar (id `wp-sudo-session-active`). (Part A — WP 6.4+.)
 *   INDICATOR-02  On the unified editor API (wp.editor.PluginSidebar, WP 6.6+) the
 *                 "Sudo" sidebar panel shows a live "Sudo active — M:SS remaining"
 *                 after a grant re-seeds it via feed #2.
 *   INDICATOR-03  A session active at page load seeds the panel active via feed #1,
 *                 with no grant required this pageview.
 *
 * The Part-B assertions are gated on `wp.editor.PluginSidebar` presence so the suite
 * stays meaningful below 6.6 (this wp-env targets WP 7.0, so Part B runs here).
 *
 * Grant trigger mirrors editor-reauth.spec.ts EDITOR-06: a real gated action
 * (PUT /wp/v2/plugins/hello {status:active}) raises the modal; the modal grants and
 * dispatches feed #2. Selectors (.wp-sudo-reauth-modal, the password field, Confirm)
 * are the contract from admin/js/wp-sudo-editor-reauth.js.
 *
 * Source: includes/class-plugin.php enqueue_editor_reauth() (verified)
 * Source: admin/js/wp-sudo-session-indicator.js — SNACKBAR_ID, .wp-sudo-indicator-panel,
 *         registerPlugin('wp-sudo-session-indicator') (verified)
 *
 * PITFALL: the feature only matters when NO sudo session is active. The global
 * storageState excludes wp_sudo_* cookies; beforeEach defensively clears any leaked
 * sudo cookie so a stale session cannot mask a gated response.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import type { Page } from '@playwright/test';

const SNACKBAR_ID = 'wp-sudo-session-active';
const SIDEBAR_NAME = 'wp-sudo-session-indicator/wp-sudo-session-indicator';

type NoticeSnapshot = { content: string };

test.describe( 'In-editor sudo session indicator', () => {
	test.beforeEach( async ( { page, context } ) => {
		// Ensure no active sudo session (mirror editor-reauth.spec.ts).
		const cookies = await context.cookies();
		if ( cookies.some( ( c ) => c.name.startsWith( 'wp_sudo' ) ) ) {
			const keep = cookies.filter( ( c ) => ! c.name.startsWith( 'wp_sudo' ) );
			await context.clearCookies();
			await context.addCookies( keep );
		}
	} );

	/**
	 * Open the post editor and wait for the editor stores + apiFetch, by which time
	 * both enqueued modules (the reauth middleware and this indicator) have run their
	 * synchronous load-time side effects.
	 */
	async function openEditor( page: Page ): Promise< void > {
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() =>
				!! ( window as any ).wp?.apiFetch &&
				!! ( window as any ).wp?.data?.select?.( 'core/notices' ),
			undefined,
			{ timeout: 30_000 }
		);
		// Dismiss the Welcome Guide (its overlay intercepts pointer events).
		await page.evaluate( () => {
			const prefs = ( window as any ).wp?.data?.dispatch?.( 'core/preferences' );
			prefs?.set?.( 'core/edit-post', 'welcomeGuide', false );
			prefs?.set?.( 'core', 'welcomeGuide', false );
		} );
		await page
			.locator( '.components-modal__screen-overlay' )
			.waitFor( { state: 'detached', timeout: 10_000 } )
			.catch( () => {} );
	}

	/** Read a core/notices notice by id, or null. */
	async function readNotice(
		page: Page,
		id: string
	): Promise< NoticeSnapshot | null > {
		return page.evaluate( ( noticeId ) => {
			const notice = ( window as any ).wp.data
				.select( 'core/notices' )
				.getNotices()
				.find( ( n: any ) => n.id === noticeId );
			return notice ? { content: notice.content } : null;
		}, id );
	}

	/** Poll until the given notice appears, then return its snapshot. */
	async function waitForNotice( page: Page, id: string ): Promise< NoticeSnapshot > {
		let snapshot: NoticeSnapshot | null = null;
		await expect
			.poll( async () => {
				snapshot = await readNotice( page, id );
				return snapshot !== null;
			}, { timeout: 5_000, message: `notice ${ id } must appear` } )
			.toBe( true );
		return snapshot as NoticeSnapshot;
	}

	/** Whether the unified PluginSidebar API (WP 6.6+) is present. */
	async function hasUnifiedSidebar( page: Page ): Promise< boolean > {
		return page.evaluate( () => !! ( window as any ).wp?.editor?.PluginSidebar );
	}

	/** Open the indicator's PluginSidebar via the editor store (version-tolerant). */
	async function openIndicatorSidebar( page: Page ): Promise< void > {
		await page.evaluate( ( name ) => {
			for ( const store of [ 'core/edit-post', 'core/edit-site' ] ) {
				const d = ( window as any ).wp.data.dispatch( store );
				if ( d && typeof d.openGeneralSidebar === 'function' ) {
					d.openGeneralSidebar( name );
					return;
				}
			}
		}, SIDEBAR_NAME );
	}

	/** Fire a real gated action and complete the password grant in the modal. */
	async function grantInEditor( page: Page ): Promise< void > {
		const pending = page.evaluate( () =>
			( window as any ).wp
				.apiFetch( {
					path: '/wp/v2/plugins/hello',
					method: 'PUT',
					data: { status: 'active' },
				} )
				.then(
					() => 'resolved',
					( err: any ) => 'rejected:' + ( err?.code ?? 'unknown' )
				)
		);
		const modal = page.locator( '.wp-sudo-reauth-modal' );
		await expect( modal ).toBeVisible();
		await modal.locator( 'input[type="password"]' ).fill( 'password' );
		await modal
			.locator( '.components-button', { hasText: 'Confirm' } )
			.click();
		await expect( modal ).toBeHidden();
		expect( await pending ).toBe( 'resolved' );
	}

	test( 'INDICATOR-01: an in-editor grant announces the success snackbar', async ( {
		page,
	} ) => {
		await openEditor( page );
		await grantInEditor( page );

		// Feed #2: the modal dispatched wp-sudo-session-granted; the indicator
		// created the once-only success snackbar.
		const notice = await waitForNotice( page, SNACKBAR_ID );
		expect( notice.content ).toMatch( /sudo active/i );
	} );

	test( 'INDICATOR-02: the sidebar panel shows a live countdown after a grant', async ( {
		page,
	} ) => {
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);

		await grantInEditor( page );
		await openIndicatorSidebar( page );

		const panel = page.locator( '.wp-sudo-indicator-panel' );
		await expect( panel ).toBeVisible();
		// Feed #2 re-seeded a positive countdown rendered as "Sudo active — M:SS remaining".
		await expect( panel ).toHaveText( /Sudo active — \d+:\d{2} remaining/ );
	} );

	test( 'INDICATOR-03: a session active at page load seeds the panel (feed #1)', async ( {
		page,
	} ) => {
		// Acquire a real session BEFORE opening the editor, so feed #1's localized
		// `remaining` (is_active()-gated) seeds the panel with no grant this pageview.
		await activateSudoSession( page );
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);

		await openIndicatorSidebar( page );

		const panel = page.locator( '.wp-sudo-indicator-panel' );
		await expect( panel ).toBeVisible();
		await expect( panel ).toHaveText( /Sudo active — \d+:\d{2} remaining/ );
	} );

	test( 'INDICATOR-04: the countdown ticks to expiry and re-seeds on a later grant', async ( {
		page,
	} ) => {
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);
		await openIndicatorSidebar( page );

		const panel = page.locator( '.wp-sudo-indicator-panel' );
		await expect( panel ).toBeVisible();
		// Inactive at open (no session, no grant this pageview).
		await expect( panel ).toHaveText( /No active sudo session/ );

		// Feed #2 with a short remaining drives the real deadline ticker
		// deterministically — no need for a live short-duration session. This
		// exercises seed → tick → active→inactive transition at 0.
		await page.evaluate( () =>
			window.dispatchEvent(
				new CustomEvent( 'wp-sudo-session-granted', { detail: { remaining: 3 } } )
			)
		);
		await expect( panel ).toHaveText( /Sudo active — 0:0\d remaining/ );

		// The local ticker reaches 0 and the panel flips to inactive (grace reads
		// inactive too, per the design). Poll past the 3 s deadline.
		await expect( panel ).toHaveText( /No active sudo session/, { timeout: 6_000 } );

		// A later grant re-seeds the countdown from the new event (feed #2 re-seed).
		await page.evaluate( () =>
			window.dispatchEvent(
				new CustomEvent( 'wp-sudo-session-granted', { detail: { remaining: 240 } } )
			)
		);
		await expect( panel ).toHaveText( /Sudo active — [34]:\d{2} remaining/ );
	} );

	// NOTE ON SCOPE (verified by mutation testing during development): this test pins
	// that the displayed state derives from the ABSOLUTE deadline — after the wall clock
	// passes it, the panel reads inactive even though the 1 s interval never advanced a
	// counter. A naive decrementing counter would still show ~2:00 here and fail the
	// final assertion. It does NOT isolate the `visibilitychange`/`focus` resync handler
	// specifically: in the editor a focus event also remounts the sidebar, and the panel
	// re-derives `remaining` from the deadline on remount — so removing the resync handler
	// still passes this test. The resync handler matters for a genuinely backgrounded tab
	// (no remount) and is verified by code review; it is not reliably isolatable in E2E.
	test( 'INDICATOR-05: after the deadline passes, refocusing shows inactive (display honors the absolute deadline)', async ( {
		page,
	} ) => {
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);
		await openIndicatorSidebar( page );
		const panel = page.locator( '.wp-sudo-indicator-panel' );
		await expect( panel ).toBeVisible();

		// Install the fake clock AFTER the editor has loaded, so ONLY the indicator's
		// own ticker + deadline (created at seed time below) bind to it — the editor
		// booted on the real clock. Then seed a 2-minute session; deadline = now+120s.
		await page.clock.install();
		await page.evaluate( () =>
			window.dispatchEvent(
				new CustomEvent( 'wp-sudo-session-granted', { detail: { remaining: 120 } } )
			)
		);
		await expect( panel ).toHaveText( /Sudo active — [12]:\d{2} remaining/ );

		// Simulate a throttled/backgrounded tab: move Date.now() PAST the deadline via
		// setFixedTime, which — unlike fastForward/runFor — does NOT fire the pending
		// 1 s interval. So the ticker never runs and the panel stays STALE, still
		// showing the ~2:00 it last rendered. (fastForward would fire the interval once
		// at the target and self-heal here, which would NOT isolate the resync path.)
		const target = await page.evaluate( () => Date.now() + 130_000 );
		await page.clock.setFixedTime( target );
		await expect( panel ).toHaveText( /Sudo active — [12]:\d{2} remaining/ );

		// Refocusing re-derives `remaining` from the ABSOLUTE deadline (via resync and/or
		// the sidebar remount) while the interval is still dormant, so the panel flips to
		// inactive. This fails if the deadline were replaced by a decrementing counter
		// (still ~2:00 here) — pinning that the display honors wall-clock expiry, not an
		// interval tick count (design-review objection 1). See the scope note above.
		await page.evaluate( () => window.dispatchEvent( new Event( 'focus' ) ) );
		await expect( panel ).toHaveText( /No active sudo session/ );
	} );
} );
