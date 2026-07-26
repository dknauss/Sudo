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

/**
 * The pinned header button PluginSidebar renders for this module (#288).
 *
 * `aria-controls` is `{plugin-name}:{sidebar-name}` — both are the module's own
 * `wp-sudo-session-indicator`, so this selector is plugin-scoped and, unlike
 * `aria-label`, locale-independent. Verified against the live WP 7.0 editor DOM:
 *   <button aria-controls="wp-sudo-session-indicator:wp-sudo-session-indicator"
 *           class="components-button is-compact has-icon" aria-label="Sudo · active">
 *     <span class="dashicon dashicons dashicons-unlock"></span></button>
 */
const PINNED_BUTTON =
	'.interface-pinned-items button[aria-controls="wp-sudo-session-indicator:wp-sudo-session-indicator"]';

/**
 * The three-state glyph vocabulary. State is carried by the GLYPH, with colour
 * reserved for the one urgent moment:
 *
 *   inactive   dashicons-lock      no chip (stock Gutenberg button)
 *   active     dashicons-unlock    no chip
 *   expiring   dashicons-warning   red chip (#c62828)
 *
 * Icon-only, no chip, for the two non-urgent states because that is what core
 * actually does on these controls: the only background change core applies to a
 * pinned-item button is the neutral `.is-pressed` fill (packages/components/src/
 * button/style.scss L342-348 — `background: $components-color-foreground`), never a
 * semantic colour. A state-driven GLYPH, by contrast, is precedented on this exact
 * button: core swaps its icon conditionally itself — `icon={ showIconLabels ? check :
 * icon }` (packages/interface/src/components/complementary-area/index.js L280) — and
 * ComplementaryAreaToggle takes a state-selected icon by design, `icon={ selectedIcon
 * && isSelected ? selectedIcon : icon }` (complementary-area-toggle/index.js L58).
 * Verified against Gutenberg trunk, fetched 2026-07-26. (The `isPinned ? starFilled :
 * starEmpty` swap at complementary-area/index.js L326 is the pin/unpin star inside the
 * panel header — a different control, and not the precedent for this.)
 *
 * Distinguishing the three by shape rather than hue also means they survive
 * greyscale, colour-vision deficiency, and forced-colors mode by construction,
 * instead of relying on the red chip to carry the expiring state alone.
 *
 * THE ONE EXCEPTION, pinned by INDICATOR-09: core's `showIconLabels` preference
 * replaces this icon with its own `check` for every state (the L280 line above) and
 * suppresses the tooltip with it (`showTooltip={ ! showIconLabels }`, L281). That
 * leaves the cohort using that preference with no shape channel and no hover
 * affordance, so active and inactive would be indistinguishable. There the active
 * chip comes back — colour is the only channel core has left us. It stays out of the
 * default view entirely.
 */
const ACTIVE_BG = 'rgb(46, 125, 50)'; // #2e7d32 — admin-bar parity; icon-labels mode only
const EXPIRING_BG = 'rgb(198, 40, 40)'; // #c62828 — admin-bar parity, every mode
const STOCK_BG = 'rgba(0, 0, 0, 0)'; // Gutenberg's `.components-button { background: none }`

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

	// --- #288: at-a-glance padlock state on the PINNED HEADER button ----------
	//
	// Before #288 the header control was a static `shield` dashicon whose only state
	// signal was its accessible name, so a sighted user editing full-screen — where the
	// admin bar (and its countdown) never renders — saw an identical glyph whether sudo
	// was active, about to expire, or inactive. These tests pin the visual contract:
	// a padlock that opens and closes with the session, and one red chip for the final
	// minute.
	//
	// The red chip's state is carried by a class on <body> rather than on the button,
	// because Gutenberg re-renders the pinned button with a fresh `className` whenever
	// `is-pressed` flips (panel open/close) and would wipe an externally-added class.
	// Asserting the COMPUTED background rather than the body class keeps these tests
	// honest about the delivered pixel — a body class that no CSS rule consumes would
	// still fail here.

	/** Seed the module's countdown deterministically via feed #2 (see INDICATOR-04). */
	async function seedRemaining( page: Page, remaining: number ): Promise< void > {
		await page.evaluate( ( secs ) => {
			window.dispatchEvent(
				new CustomEvent( 'wp-sudo-session-granted', { detail: { remaining: secs } } )
			);
		}, remaining );
	}

	test( 'INDICATOR-06: with no session the button shows a CLOSED padlock and no chip', async ( {
		page,
	} ) => {
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);

		// The button is registered unconditionally, so it sits in the header whether or
		// not sudo is active. It must therefore READ correctly at rest: a CLOSED padlock
		// for "not elevated". Shipping `unlock` here (as #288 first did) said the
		// opposite of the truth for the state the user sees the vast majority of the time.
		const glyph = page.locator( `${ PINNED_BUTTON } .dashicon` );
		await expect( glyph ).toHaveClass( /dashicons-lock/ );
		await expect( glyph ).not.toHaveClass( /dashicons-unlock/ );
		await expect( glyph ).not.toHaveClass( /dashicons-shield/ );
		await expect( page.locator( PINNED_BUTTON ) ).toHaveAttribute(
			'aria-label',
			/inactive/
		);

		// No chip at rest — core puts no semantic colour on these buttons.
		await expect( page.locator( PINNED_BUTTON ) ).toHaveCSS(
			'background-color',
			STOCK_BG
		);
	} );

	test( 'INDICATOR-07: only the final minute paints a chip; active is the stock button', async ( {
		page,
	} ) => {
		// Feed #1: a real session acquired before the editor loads, so the active state
		// is proven on the page-load path and not just the synthetic-event path.
		await activateSudoSession( page );
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);

		const button = page.locator( PINNED_BUTTON );
		await expect( button ).toBeVisible();

		// Active — the OPEN padlock, on the stock button. Colour is deliberately NOT
		// spent here: a green chip would sit in the header for the whole session, and
		// core reserves that header's colour for the Publish CTA. The panel stays CLOSED
		// throughout: the point of #288 is that state is legible without opening it.
		await expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( button ).toHaveCSS( 'background-color', STOCK_BG );
		await expect( button.locator( '.dashicon' ) ).toHaveClass( /dashicons-unlock/ );

		// Final minute — the one moment that earns colour, at the same `remaining <= 60`
		// threshold the admin bar uses (wp-sudo-admin-bar.js: if (r <= 60) → expiring).
		await seedRemaining( page, 45 );
		await expect( button ).toHaveCSS( 'background-color', EXPIRING_BG );

		// Expiry reverts to the stock button — no lingering red chip, and no leaked body
		// class. This also pins that the module-level body-class sync has no unmount path
		// to get wrong: the class comes off because the STATE changed, not because React
		// tore something down. (Grace reads inactive here, per the module's design.)
		await seedRemaining( page, 2 );
		await expect( button ).toHaveCSS( 'background-color', STOCK_BG, { timeout: 8_000 } );
		await expect
			.poll( async () =>
				page.evaluate( () =>
					Array.from( document.body.classList ).filter( ( c ) =>
						c.startsWith( 'wp-sudo-editor-session' )
					)
				)
			)
			.toEqual( [] );

		// A later grant re-paints the chip on its way back through expiring — the
		// transition is not one-way.
		await seedRemaining( page, 45 );
		await expect( button ).toHaveCSS( 'background-color', EXPIRING_BG );
	} );

	test( 'INDICATOR-08: all three states differ by SHAPE, not only by colour', async ( {
		page,
	} ) => {
		// WCAG 1.4.1. Two of the three states now carry no colour at all, so shape is
		// not merely a supplement here — it is the primary channel, and the red chip
		// only reinforces the one urgent state. That makes the vocabulary survive
		// greyscale, colour-vision deficiency, and forced-colors mode by construction,
		// rather than relying on green-vs-red, which is 1.09:1 against ITSELF and would
		// be the same swatch to those users. The accessible name cannot discharge 1.4.1
		// — a name is programmatic, not visual — but it is asserted alongside, so the
		// sighted and AT vocabularies cannot drift.
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);

		const button = page.locator( PINNED_BUTTON );
		const glyph = button.locator( '.dashicon' );

		// inactive → closed padlock
		await expect( glyph ).toHaveClass( /dashicons-lock/ );
		await expect( button ).toHaveAttribute( 'aria-label', /inactive/ );

		// active → open padlock. Distinct from BOTH other states without colour.
		// (`dashicons-unlock` does not contain the substring `dashicons-lock` — the
		// `-u` breaks it — so these two regexes cannot cross-match.)
		await seedRemaining( page, 600 );
		await expect( glyph ).toHaveClass( /dashicons-unlock/ );
		await expect( glyph ).not.toHaveClass( /dashicons-lock/ );
		await expect( button ).toHaveAttribute( 'aria-label', /active/ );

		// expiring → a third, non-padlock shape.
		await seedRemaining( page, 45 );
		await expect( glyph ).toHaveClass( /dashicons-warning/ );
		await expect( glyph ).not.toHaveClass( /dashicons-unlock/ );
		await expect( button ).toHaveAttribute( 'aria-label', /expiring/ );

		// ...and crossing back restores the open padlock, so no swap is one-way.
		await seedRemaining( page, 600 );
		await expect( glyph ).toHaveClass( /dashicons-unlock/ );
		await expect( button ).toHaveAttribute( 'aria-label', /active/ );

		// The chip must come OFF on the way back up too. INDICATOR-07 ends in the
		// expiring state, so without this a stuck `wp-sudo-editor-session-expiring`
		// would leave a red chip on a full-length session with nothing failing.
		await expect( button ).toHaveCSS( 'background-color', STOCK_BG );
	} );

	test( 'INDICATOR-09: under core icon labels the active chip returns, and only there', async ( {
		page,
	} ) => {
		// Core's "Show button text labels" preference takes the icon slot on this
		// button — `icon={ showIconLabels ? check : icon }` — and suppresses the
		// tooltip alongside it. Verified in a live editor: every state renders the
		// same `check` SVG, `.dashicon` is absent, and the button has no text. So for
		// that cohort the glyph vocabulary does not exist and active/inactive would be
		// identical, which is the #288 bug this feature exists to fix.
		//
		// The fix is scoped, and this test is mostly here to keep it scoped: the chip
		// must appear ONLY when the preference is on, so colour cannot leak back into
		// the default view it was deliberately removed from.
		await openEditor( page );
		test.skip(
			! ( await hasUnifiedSidebar( page ) ),
			'Part B requires the unified PluginSidebar (WP 6.6+).'
		);

		const button = page.locator( PINNED_BUTTON );
		const setIconLabels = ( on: boolean ) =>
			page.evaluate( ( value ) => {
				( window as any ).wp.data
					.dispatch( 'core/preferences' )
					.set( 'core', 'showIconLabels', value );
			}, on );

		try {
			// Default mode: active stays the stock button. This is the guard against
			// the mitigation leaking — if it ever paints here, #288's whole premise
			// (core puts no semantic colour on these buttons) is broken again.
			await seedRemaining( page, 600 );
			await expect( button ).toHaveCSS( 'background-color', STOCK_BG );
			await expect( button.locator( '.dashicon' ) ).toHaveClass(
				/dashicons-unlock/
			);

			// Preference on: core swaps in its own glyph, so the chip carries the state.
			await setIconLabels( true );
			await expect( button ).toHaveCSS( 'background-color', ACTIVE_BG );
			// Core really has taken the glyph — no dashicon left to read state from.
			await expect( button.locator( '.dashicon' ) ).toHaveCount( 0 );

			// Inactive must NOT paint, even here: the chip means "elevated", and a
			// permanently-green button would say the opposite of the truth at rest.
			await seedRemaining( page, 2 );
			await expect( button ).toHaveCSS( 'background-color', STOCK_BG, {
				timeout: 8_000,
			} );

			// Expiring still wins over active in this mode (admin-bar parity).
			await seedRemaining( page, 45 );
			await expect( button ).toHaveCSS( 'background-color', EXPIRING_BG );

			// Turning the preference back off drops the chip live, without a reload:
			// the glyph is back, so colour is not needed to tell the states apart.
			await seedRemaining( page, 600 );
			await expect( button ).toHaveCSS( 'background-color', ACTIVE_BG );
			await setIconLabels( false );
			await expect( button ).toHaveCSS( 'background-color', STOCK_BG );
			await expect( button.locator( '.dashicon' ) ).toHaveClass(
				/dashicons-unlock/
			);
		} finally {
			// The preference persists per user (server-side), so leaving it on would
			// silently break INDICATOR-06/07/08 on the next run — they assert dashicons.
			await setIconLabels( false ).catch( () => {} );
		}
	} );
} );
