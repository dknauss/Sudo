/**
 * Block-editor reauth — Increment 1 (link-out snackbar)
 *
 * Verifies the build-free apiFetch middleware (admin/js/wp-sudo-editor-reauth.js)
 * enqueued on block/site-editor screens by Plugin::enqueue_editor_reauth():
 *
 *   EDITOR-01  A real gated REST action (PUT /wp/v2/plugins/<slug>) fired from the
 *              editor with no active sudo session surfaces a "Reauthenticate"
 *              snackbar whose action opens the SERVER-emitted challenge_url in a
 *              new tab; the message is generic (never echoes the rule label).
 *   EDITOR-02  A sudo_required nested in a /batch/v1 response envelope surfaces the
 *              snackbar (detect-and-surface only — no batch re-dispatch).
 *   EDITOR-03  A sudo_required with NO challenge_url (headless / app-password
 *              branch, C4) degrades to a plain message with NO action.
 *   EDITOR-04  A normal REST error does NOT surface the snackbar.
 *
 * Method: EDITOR-01 exercises the full real chain (server → middleware). The
 * synthetic cases override window.fetch narrowly so apiFetch's own chain — and
 * our middleware within it — runs in the real order against a controlled
 * response, then restore fetch. No production test surface is added.
 *
 * Source: includes/class-plugin.php enqueue_editor_reauth() (verified)
 * Source: includes/class-gate.php block_rest() — code 'sudo_required',
 *         data.challenge_url on the cookie-auth branch only (verified)
 * Source: admin/js/wp-sudo-editor-reauth.js (verified)
 *
 * PITFALL: The gate UI / this feature only matter when NO sudo session is active.
 * The global storageState (admin.json) excludes wp_sudo_* cookies, but we defensively
 * clear any sudo cookie in beforeEach so a leaked session cannot mask a gated response.
 */
import { test, expect } from '../fixtures/test';

const NOTICE_ID = 'wp-sudo-reauth-required';
const GENERIC_MESSAGE = 'This action requires reauthentication.';

test.describe( 'Block-editor reauth snackbar', () => {
	test.beforeEach( async ( { page, context } ) => {
		// Ensure no active sudo session (mirror gate-ui.spec.ts).
		const cookies = await context.cookies();
		if ( cookies.some( ( c ) => c.name.startsWith( 'wp_sudo' ) ) ) {
			const authCookies = cookies.filter(
				( c ) => ! c.name.startsWith( 'wp_sudo' )
			);
			await context.clearCookies();
			await context.addCookies( authCookies );
		}

		// Open the post editor (block editor) and wait for the editor stores +
		// apiFetch to be present — by then our middleware has registered (it is a
		// synchronous side effect of the enqueued script loading).
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() =>
				!! ( window as any ).wp?.apiFetch &&
				!! ( window as any ).wp?.data?.select?.( 'core/notices' ),
			undefined,
			{ timeout: 30_000 }
		);

		// Confirm no active session (admin bar timer node absent = no session).
		await expect(
			page.locator( '#wp-admin-bar-wp-sudo-active' )
		).not.toBeVisible();
	} );

	/**
	 * Read the current wp-sudo reauth notice from the core/notices store, or null.
	 * Returns a serializable snapshot (onClick handlers are dropped by evaluate).
	 */
	async function readNotice( page ) {
		return page.evaluate( ( noticeId ) => {
			const notices = ( window as any ).wp.data
				.select( 'core/notices' )
				.getNotices();
			const notice = notices.find( ( n: any ) => n.id === noticeId );
			if ( ! notice ) {
				return null;
			}
			return {
				content: notice.content,
				actionLabels: ( notice.actions || [] ).map(
					( a: any ) => a.label
				),
			};
		}, NOTICE_ID );
	}

	test( 'EDITOR-01: real gated action surfaces a link-out snackbar', async ( {
		page,
		context,
	} ) => {
		// Fire a real gated request. The route regex #^/wp/v2/plugins/[^/]+$#
		// (PUT/PATCH) matches before the controller runs, so the gate intercepts
		// with sudo_required + challenge_url regardless of the exact slug.
		// Source: class-action-registry.php plugin.activate rule (verified)
		const challengeUrl = await page.evaluate( async () => {
			try {
				await ( window as any ).wp.apiFetch( {
					path: '/wp/v2/plugins/hello',
					method: 'PUT',
					data: { status: 'active' },
				} );
				return null; // Should not resolve.
			} catch ( err: any ) {
				return err?.data?.challenge_url ?? null;
			}
		} );

		// The server must have emitted a challenge_url on this cookie-auth branch.
		expect(
			challengeUrl,
			'Gate must emit a challenge_url on the cookie-auth REST branch'
		).toBeTruthy();
		expect( challengeUrl ).toContain( 'page=wp-sudo-challenge' );

		// The middleware surfaced a generic, link-out snackbar.
		const notice = await readNotice( page );
		expect( notice, 'reauth snackbar must be present' ).not.toBeNull();
		expect( notice!.content ).toBe( GENERIC_MESSAGE );
		// SEV-3 / Q4: never echo the rule label.
		expect( notice!.content.toLowerCase() ).not.toContain( 'activate' );
		expect( notice!.content.toLowerCase() ).not.toContain( 'plugin' );
		expect( notice!.actionLabels ).toEqual( [ 'Reauthenticate' ] );

		// The action opens the server's challenge_url in a NEW tab (editor preserved).
		const action = page
			.locator( '.components-snackbar__action', {
				hasText: 'Reauthenticate',
			} )
			.first();
		await expect( action ).toBeVisible();
		const [ popup ] = await Promise.all( [
			context.waitForEvent( 'page' ),
			action.click(),
		] );
		await popup.waitForLoadState( 'domcontentloaded' );
		expect( popup.url() ).toContain( 'page=wp-sudo-challenge' );
		// Editor tab did not navigate away.
		expect( page.url() ).toContain( 'post-new.php' );
		await popup.close();
	} );

	test( 'EDITOR-02: batched sudo_required surfaces the snackbar', async ( {
		page,
	} ) => {
		await page.evaluate( async () => {
			const original = window.fetch;
			window.fetch = async ( input: any, init?: any ) => {
				const url = typeof input === 'string' ? input : input?.url ?? '';
				if ( url.includes( '/batch/v1' ) ) {
					return new Response(
						JSON.stringify( {
							failed: 'validation',
							responses: [
								{
									status: 403,
									body: {
										code: 'sudo_required',
										data: {
											status: 403,
											challenge_url:
												'/wp-admin/admin.php?page=wp-sudo-challenge',
										},
									},
								},
							],
						} ),
						{
							status: 200,
							headers: { 'Content-Type': 'application/json' },
						}
					);
				}
				return original( input, init );
			};
			try {
				await ( window as any ).wp.apiFetch( {
					path: '/batch/v1',
					method: 'POST',
					data: { requests: [] },
				} );
			} catch ( e ) {
				// ignore
			} finally {
				window.fetch = original;
			}
		} );

		const notice = await readNotice( page );
		expect(
			notice,
			'batched sudo_required must surface the snackbar (no silent no-op)'
		).not.toBeNull();
		expect( notice!.actionLabels ).toEqual( [ 'Reauthenticate' ] );
	} );

	test( 'EDITOR-03: sudo_required without challenge_url degrades to a plain message', async ( {
		page,
	} ) => {
		await page.evaluate( async () => {
			const original = window.fetch;
			window.fetch = async ( input: any, init?: any ) => {
				const url = typeof input === 'string' ? input : input?.url ?? '';
				if ( url.includes( '/wp/v2/plugins' ) ) {
					return new Response(
						JSON.stringify( {
							code: 'sudo_required',
							message: 'blocked',
							data: { status: 403 },
						} ),
						{
							status: 403,
							headers: { 'Content-Type': 'application/json' },
						}
					);
				}
				return original( input, init );
			};
			try {
				await ( window as any ).wp.apiFetch( {
					path: '/wp/v2/plugins/hello',
					method: 'PUT',
					data: { status: 'active' },
				} );
			} catch ( e ) {
				// ignore
			} finally {
				window.fetch = original;
			}
		} );

		const notice = await readNotice( page );
		expect(
			notice,
			'headless sudo_required still surfaces a message'
		).not.toBeNull();
		expect( notice!.content ).toBe( GENERIC_MESSAGE );
		// C4-adjacent: no reauth affordance when there is no challenge_url.
		expect( notice!.actionLabels ).toEqual( [] );
	} );

	test( 'EDITOR-04: a normal REST error does not surface the snackbar', async ( {
		page,
	} ) => {
		await page.evaluate( async () => {
			const original = window.fetch;
			window.fetch = async ( input: any, init?: any ) => {
				const url = typeof input === 'string' ? input : input?.url ?? '';
				if ( url.includes( '/wp/v2/__nope' ) ) {
					return new Response(
						JSON.stringify( {
							code: 'rest_no_route',
							message: 'No route',
							data: { status: 404 },
						} ),
						{
							status: 404,
							headers: { 'Content-Type': 'application/json' },
						}
					);
				}
				return original( input, init );
			};
			try {
				await ( window as any ).wp.apiFetch( { path: '/wp/v2/__nope' } );
			} catch ( e ) {
				// ignore
			} finally {
				window.fetch = original;
			}
		} );

		const notice = await readNotice( page );
		expect(
			notice,
			'a non-sudo error must not surface the reauth snackbar'
		).toBeNull();
	} );
} );
