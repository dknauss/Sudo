/**
 * Block-editor reauth — Increment 2/3 (in-editor grant modal)
 *
 * Verifies the build-free apiFetch middleware (admin/js/wp-sudo-editor-reauth.js)
 * enqueued on block/site-editor screens by Plugin::enqueue_editor_reauth().
 *
 * BEHAVIOUR CHANGE (Increment 2/3): the middleware now opens an in-editor
 * password modal on `sudo_required` and, on a successful grant, transparently
 * re-dispatches the original request. The Increment 1 link-out snackbar is now
 * only the FALLBACK (2FA pending, modal cancelled, or no grant config).
 *
 *   EDITOR-04  A normal REST error does NOT trigger the modal or snackbar. (active)
 *   EDITOR-06  A real gated action opens the modal; a correct password grants a
 *              sudo session and the original request re-dispatches and succeeds.
 *
 * EDITOR-01/02/03/05 originally asserted the Increment 1 snackbar-PRIMARY
 * behaviour, which the modal now supersedes. They have been reconciled to the
 * middleware's actual decision, which turns on the validated challenge_url:
 *   - EDITOR-01: a real gated action carries a safe same-origin challenge_url,
 *     so the modal opens (primary); cancelling drops to the link-out snackbar.
 *   - EDITOR-02: a batched sudo_required is detect-and-surface ONLY (Q2) — it
 *     links out via the snackbar; the modal never opens and the /batch/v1
 *     envelope is never re-dispatched.
 *   - EDITOR-03 / EDITOR-05: a sudo_required with no safe challenge_url (absent,
 *     or an unsafe javascript: URL rejected by isSafeChallengeUrl) offers no
 *     reauth affordance (C4) — a plain notice with no action and no modal.
 * 02/03/05 inject synthetic sudo_required payloads to drive these branches
 * deterministically. Step 4 layers the deeper Q3 concerns on top (single-flight
 * modal, concurrent-rejection queue re-dispatching only the user-actioned
 * request).
 *
 * Method: the real cases exercise the full chain (server → middleware → modal).
 * The synthetic cases override window.fetch narrowly so apiFetch's own chain —
 * and our middleware within it — runs in the real order against a controlled
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
import type { Page } from '@playwright/test';

const NOTICE_ID = 'wp-sudo-reauth-required';
const GENERIC_MESSAGE = 'This action requires reauthentication.';

type NoticeSnapshot = { content: string; actionLabels: string[] };

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

		// Dismiss the editor Welcome Guide. On a fresh CI user profile it opens
		// automatically, and its modal screen-overlay intercepts pointer events —
		// which blocks clicking the snackbar action in EDITOR-01. Clearing the
		// preference unmounts the modal reactively (scope differs across WP
		// versions, so set both). Then wait for any open overlay to detach.
		await page.evaluate( () => {
			const prefs = ( window as any ).wp?.data?.dispatch?.(
				'core/preferences'
			);
			prefs?.set?.( 'core/edit-post', 'welcomeGuide', false );
			prefs?.set?.( 'core', 'welcomeGuide', false );
		} );
		await page
			.locator( '.components-modal__screen-overlay' )
			.waitFor( { state: 'detached', timeout: 10_000 } )
			.catch( () => {} );

		// Confirm no active session (admin bar timer node absent = no session).
		await expect(
			page.locator( '#wp-admin-bar-wp-sudo-active' )
		).not.toBeVisible();
	} );

	/**
	 * Read the current wp-sudo reauth notice from the core/notices store, or null.
	 * Returns a serializable snapshot (onClick handlers are dropped by evaluate).
	 */
	async function readNotice( page: Page ): Promise< NoticeSnapshot | null > {
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

	/**
	 * Poll until the reauth notice appears, then return its snapshot.
	 *
	 * The snackbar is created via a core/notices store dispatch, so reading it
	 * synchronously right after the apiFetch call is race-prone under CI. Poll
	 * for it instead of asserting on a single immediate read.
	 */
	async function waitForNotice( page: Page ): Promise< NoticeSnapshot > {
		let snapshot: NoticeSnapshot | null = null;
		await expect
			.poll( async () => {
				snapshot = await readNotice( page );
				return snapshot !== null;
			}, { timeout: 5_000, message: 'reauth snackbar must appear' } )
			.toBe( true );
		return snapshot as NoticeSnapshot;
	}

	test( 'EDITOR-01: cancelling the modal falls back to a link-out snackbar', async ( {
		page,
	} ) => {
		// Fire a real gated request WITHOUT awaiting. The route regex
		// #^/wp/v2/plugins/[^/]+$# (PUT/PATCH) matches before the controller runs,
		// so the gate intercepts with sudo_required + challenge_url regardless of
		// the exact slug. The request stays pending behind the modal (primary path).
		// Source: class-action-registry.php plugin.activate rule (verified)
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

		// The modal opens (Increment 2/3 primary path).
		const modal = page.locator( '.wp-sudo-reauth-modal' );
		await expect( modal ).toBeVisible();

		// Cancelling drops to the Increment 1 link-out snackbar fallback.
		await modal
			.locator( '.components-button', { hasText: 'Cancel' } )
			.click();
		await expect( modal ).toBeHidden();

		// The middleware surfaced a generic, link-out snackbar.
		const notice = await waitForNotice( page );
		expect( notice.content ).toBe( GENERIC_MESSAGE );
		// SEV-3 / Q4: never echo the rule label.
		expect( notice.content.toLowerCase() ).not.toContain( 'activate' );
		expect( notice.content.toLowerCase() ).not.toContain( 'plugin' );
		expect( notice.actionLabels ).toEqual( [ 'Reauthenticate' ] );

		// The action opens the server's challenge_url in a NEW tab (editor preserved).
		const action = page
			.locator( '.components-snackbar__action', {
				hasText: 'Reauthenticate',
			} )
			.first();
		await expect( action ).toBeVisible();

		// Spy on window.open rather than waiting for a real popup: the handler
		// calls window.open(url, '_blank', 'noopener'), and a noopener popup does
		// not reliably surface as a Playwright 'page' event under headless CI. The
		// contract we care about — clicking Reauthenticate opens the server's
		// challenge_url in a new tab — is verified deterministically by the spy.
		await page.evaluate( () => {
			( window as any ).__wpSudoOpened = [];
			( window as any ).open = ( url: string, target?: string ) => {
				( window as any ).__wpSudoOpened.push( { url, target } );
				return null;
			};
		} );
		await action.click();
		const opened = await page.evaluate(
			() => ( window as any ).__wpSudoOpened as Array< { url: string; target?: string } >
		);
		expect( opened ).toHaveLength( 1 );
		// The server emitted the challenge_url on the cookie-auth REST branch.
		expect( opened[ 0 ].url ).toContain( 'page=wp-sudo-challenge' );
		expect( opened[ 0 ].target ).toBe( '_blank' );
		// Editor tab did not navigate away.
		expect( page.url() ).toContain( 'post-new.php' );

		// The original request rejected (no in-editor grant occurred).
		expect( await pending ).toContain( 'rejected' );
	} );

	test( 'EDITOR-02: batched sudo_required is detected and surfaced — no modal, no replay', async ( {
		page,
	} ) => {
		// Inject a /batch/v1 envelope whose inner response is a sudo_required.
		await page.evaluate( () => {
			( window as any ).__wpSudoOrigFetch = window.fetch;
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
				return ( window as any ).__wpSudoOrigFetch( input, init );
			};
		} );

		// Q2 (detect-and-surface ONLY): the batched sudo_required is recognised
		// and links out via the snackbar; the middleware must NOT open the grant
		// modal and must NOT re-dispatch the envelope (which could repeat
		// successful sibling mutations). The original batch response resolves.
		const settled = await page.evaluate( () =>
			( window as any ).wp
				.apiFetch( {
					path: '/batch/v1',
					method: 'POST',
					data: { requests: [] },
				} )
				.then( () => 'resolved', () => 'rejected' )
		);

		// No grant modal for a batch.
		await expect(
			page.locator( '.wp-sudo-reauth-modal' )
		).toHaveCount( 0 );

		// Surfaced, not silently swallowed — link-out snackbar present.
		const notice = await waitForNotice( page );
		expect( notice.actionLabels ).toEqual( [ 'Reauthenticate' ] );

		// The caller still observes the original (unreplayed) batch response.
		expect( settled ).toBe( 'resolved' );

		await page.evaluate( () => {
			window.fetch = ( window as any ).__wpSudoOrigFetch;
		} );
	} );

	test( 'EDITOR-03: sudo_required without challenge_url degrades to a plain message', async ( {
		page,
	} ) => {
		await page.evaluate( () => {
			( window as any ).__wpSudoOrigFetch = window.fetch;
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
				return ( window as any ).__wpSudoOrigFetch( input, init );
			};
		} );

		// C4: with no safe challenge_url the middleware offers no reauth
		// affordance at all — no grant modal, just a plain dismissible notice.
		const settled = await page.evaluate( () =>
			( window as any ).wp
				.apiFetch( {
					path: '/wp/v2/plugins/hello',
					method: 'PUT',
					data: { status: 'active' },
				} )
				.then( () => 'resolved', ( e: any ) => 'rejected:' + ( e?.code ?? 'unknown' ) )
		);

		await expect(
			page.locator( '.wp-sudo-reauth-modal' )
		).toHaveCount( 0 );

		const notice = await waitForNotice( page );
		expect( notice.content ).toBe( GENERIC_MESSAGE );
		expect( notice.actionLabels ).toEqual( [] );
		// The original request is left rejected (no in-editor grant path).
		expect( settled ).toContain( 'rejected' );

		await page.evaluate( () => {
			window.fetch = ( window as any ).__wpSudoOrigFetch;
		} );
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

	test( 'EDITOR-05: an unsafe challenge_url degrades to a plain message', async ( {
		page,
	} ) => {
		// A sudo_required carrying a javascript: URL must never reach window.open:
		// the middleware validates the URL and drops the action, degrading to the
		// same plain message as the no-URL case.
		await page.evaluate( () => {
			( window as any ).__wpSudoOrigFetch = window.fetch;
			window.fetch = async ( input: any, init?: any ) => {
				const url = typeof input === 'string' ? input : input?.url ?? '';
				if ( url.includes( '/wp/v2/plugins' ) ) {
					return new Response(
						JSON.stringify( {
							code: 'sudo_required',
							message: 'blocked',
							data: {
								status: 403,
								// A hostile scheme the middleware must reject (never opened).
								challenge_url: 'javascript:alert(document.domain)',
							},
						} ),
						{
							status: 403,
							headers: { 'Content-Type': 'application/json' },
						}
					);
				}
				return ( window as any ).__wpSudoOrigFetch( input, init );
			};
		} );

		// The unsafe URL is rejected by isSafeChallengeUrl → the challenge resolves
		// to null, so (C4) no reauth affordance is offered: no grant modal, no
		// link-out action — the javascript: URL never reaches window.open.
		const settled = await page.evaluate( () =>
			( window as any ).wp
				.apiFetch( {
					path: '/wp/v2/plugins/hello',
					method: 'PUT',
					data: { status: 'active' },
				} )
				.then( () => 'resolved', ( e: any ) => 'rejected:' + ( e?.code ?? 'unknown' ) )
		);

		await expect(
			page.locator( '.wp-sudo-reauth-modal' )
		).toHaveCount( 0 );

		const notice = await waitForNotice( page );
		expect( notice.content ).toBe( GENERIC_MESSAGE );
		expect( notice.actionLabels ).toEqual( [] );
		expect( settled ).toContain( 'rejected' );

		await page.evaluate( () => {
			window.fetch = ( window as any ).__wpSudoOrigFetch;
		} );
	} );

	// -------------------------------------------------------------------------
	// Increment 2/3 — in-editor grant modal
	// -------------------------------------------------------------------------

	/**
	 * EDITOR-06 — the shippable password-grant floor. Fire a real gated action;
	 * the middleware opens the in-editor modal (wp.components.Modal). Enter the
	 * admin password, submit, and the modal grants a sudo session via the
	 * localized authAction, then transparently re-dispatches the original request
	 * which now succeeds.
	 *
	 * Selectors (.wp-sudo-reauth-modal, the password TextControl, the Confirm
	 * button) are the contract from admin/js/wp-sudo-editor-reauth.js, confirmed
	 * green against a live WP Sudo editor session.
	 */
	test(
		'EDITOR-06: modal password grant re-dispatches the original request',
		async ( { page } ) => {
			// Fire a gated action WITHOUT awaiting — it stays pending while the
			// modal grants, then should resolve (re-dispatched) rather than reject.
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

			// The modal appears.
			const modal = page.locator( '.wp-sudo-reauth-modal' );
			await expect( modal ).toBeVisible();

			// Enter the admin password and confirm.
			await modal.locator( 'input[type="password"]' ).fill( 'password' );
			await modal
				.locator( '.components-button', { hasText: 'Confirm' } )
				.click();

			// Modal closes and the original request re-dispatches to completion.
			await expect( modal ).toBeHidden();
			expect( await pending ).toBe( 'resolved' );
		}
	);

	/**
	 * EDITOR-07 — a rejected password submission must never trap the editor.
	 * Force the grant POST's body-read to reject (the path that escapes
	 * postPassword's own network handler); the modal's terminal .catch must clear
	 * the busy state so the form shows an error and Confirm/Cancel work again, and
	 * no re-dispatch happens (the original request stays rejected until the user
	 * retries or cancels). Guards the robustness fix for the modal-lock defect.
	 */
	test( 'EDITOR-07: a rejected submission restores the modal and replays nothing', async ( {
		page,
	} ) => {
		// Open the modal via a real gated action (carries a safe challenge_url).
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

		// Make ONLY the grant POST (authAction) fail its body-read, so the submit
		// promise rejects through to the terminal .catch. refreshNonce and the
		// editor's own admin-ajax traffic pass through untouched.
		await page.evaluate( () => {
			( window as any ).__wpSudoOrigFetch = window.fetch;
			const authAction = ( window as any ).wpSudoEditorReauth?.authAction;
			window.fetch = ( ( input: any, init?: any ) => {
				const url =
					typeof input === 'string' ? input : input?.url ?? '';
				if (
					url.includes( 'admin-ajax.php' ) &&
					init?.body instanceof FormData &&
					init.body.get( 'action' ) === authAction
				) {
					return Promise.resolve( {
						ok: true,
						text: () => Promise.reject( new Error( 'boom' ) ),
						json: () => Promise.reject( new Error( 'boom' ) ),
					} as any );
				}
				return ( window as any ).__wpSudoOrigFetch( input, init );
			} ) as any;
		} );

		await modal.locator( 'input[type="password"]' ).fill( 'password' );
		await modal
			.locator( '.components-button', { hasText: 'Confirm' } )
			.click();

		// The modal stays open and surfaces an error (busy cleared).
		await expect( modal ).toBeVisible();
		await expect( modal.locator( '.components-notice' ) ).toBeVisible();

		// Confirm and Cancel are usable again (not stuck in the busy state).
		await expect(
			modal.locator( '.components-button', { hasText: 'Confirm' } )
		).toBeEnabled();
		const cancel = modal.locator( '.components-button', {
			hasText: 'Cancel',
		} );
		await expect( cancel ).toBeEnabled();

		// Cancel closes the modal; nothing was granted, so the original request
		// stays rejected — it is never replayed.
		await cancel.click();
		await expect( modal ).toBeHidden();
		expect( await pending ).toContain( 'rejected' );

		await page.evaluate( () => {
			window.fetch = ( window as any ).__wpSudoOrigFetch;
		} );
	} );

	/**
	 * EDITOR-08 — a 2FA account's password step must NOT grant in-editor; it links
	 * out to the full challenge page. The security invariant that `handle_ajax_auth`
	 * returns `2fa_pending` (never `authenticated`) and mints no session for a 2FA
	 * user is enforced server-side (SudoSessionTest unit + TwoFactorTest integration).
	 * This test drives the client contract: given that `2fa_pending` response, the
	 * modal resolves to the link-out fallback rather than granting. A synthetic
	 * response makes it deterministic without provisioning a full TOTP account,
	 * mirroring EDITOR-02/03/05.
	 */
	test( 'EDITOR-08: a 2fa_pending password response links out instead of granting', async ( {
		page,
	} ) => {
		// Open the modal via a real gated action (safe challenge_url present).
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

		// Make ONLY the grant POST (authAction) return `2fa_pending` — the server
		// response a 2FA account gets — instead of `authenticated`. No session is
		// granted. refreshNonce and other admin-ajax traffic pass through.
		await page.evaluate( () => {
			( window as any ).__wpSudoOrigFetch = window.fetch;
			const authAction = ( window as any ).wpSudoEditorReauth?.authAction;
			window.fetch = ( ( input: any, init?: any ) => {
				const url =
					typeof input === 'string' ? input : input?.url ?? '';
				if (
					url.includes( 'admin-ajax.php' ) &&
					init?.body instanceof FormData &&
					init.body.get( 'action' ) === authAction
				) {
					return Promise.resolve(
						new Response(
							JSON.stringify( {
								success: true,
								data: {
									code: '2fa_pending',
									expires_at:
										Math.floor( Date.now() / 1000 ) + 300,
								},
							} ),
							{
								status: 200,
								headers: {
									'Content-Type': 'application/json',
								},
							}
						)
					);
				}
				return ( window as any ).__wpSudoOrigFetch( input, init );
			} ) as any;
		} );

		await modal.locator( 'input[type="password"]' ).fill( 'password' );
		await modal
			.locator( '.components-button', { hasText: 'Confirm' } )
			.click();

		// No in-editor grant: the modal closes to the link-out snackbar so the
		// user finishes 2FA on the full challenge page.
		await expect( modal ).toBeHidden();
		const notice = await waitForNotice( page );
		expect( notice.actionLabels ).toEqual( [ 'Reauthenticate' ] );

		// The original gated request was never granted/re-dispatched.
		expect( await pending ).toContain( 'rejected' );

		await page.evaluate( () => {
			window.fetch = ( window as any ).__wpSudoOrigFetch;
		} );
	} );

	/**
	 * EDITOR-09 — overnight-tab stale-nonce recovery (client side). The editor
	 * localizes the grant nonce at page load (C1); a tab left open past the ~24h
	 * nonce life holds a stale nonce that `handle_ajax_auth`'s check_ajax_referer
	 * would reject. submit() defends against this by calling refreshNonce()
	 * (login-gated refreshNonceAction — it does not require the stale nonce) to
	 * re-mint cfg.nonce BEFORE postPassword(). Poison the localized nonce to a
	 * stale value and assert the grant STILL succeeds and re-dispatches — proof
	 * that the refresh recovered it. Without the refresh, the poisoned nonce would
	 * fail server-side (asserted deterministically in the integration
	 * StaleNonceRecoveryTest::test_stale_nonce_rejected_then_refreshed_nonce_grants).
	 */
	test( 'EDITOR-09: a stale localized grant nonce is recovered via refresh and grants', async ( {
		page,
	} ) => {
		// Precondition: the refresh affordance must actually be wired (C1), else the
		// "recovery" would be vacuous — a stale nonce that is never refreshed.
		const cfg = await page.evaluate( () => {
			const c = ( window as any ).wpSudoEditorReauth || {};
			return {
				hasRefresh: !! c.refreshNonceAction,
				originalNonce: c.nonce,
			};
		} );
		expect(
			cfg.hasRefresh,
			'refreshNonceAction must be localized for the recovery path to exist'
		).toBe( true );

		// Simulate the overnight-stale tab: replace the localized grant nonce with a
		// value check_ajax_referer will reject.
		await page.evaluate( () => {
			( window as any ).wpSudoEditorReauth.nonce =
				'stale-overnight-nonce-000000';
		} );

		// Fire a real gated action WITHOUT awaiting — it stays pending while the
		// modal grants. Despite the poisoned nonce, refreshNonce() re-mints a valid
		// one before the grant POST, so this should resolve (re-dispatched).
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

		// The grant succeeded and the original request re-dispatched — the stale
		// nonce did not block it.
		await expect( modal ).toBeHidden();
		expect( await pending ).toBe( 'resolved' );

		// The localized nonce was replaced by refreshNonce() (no longer the poisoned
		// value), confirming the refresh — not a still-valid original — is what
		// carried the grant.
		const finalNonce = await page.evaluate(
			() => ( window as any ).wpSudoEditorReauth.nonce
		);
		expect( finalNonce ).not.toBe( 'stale-overnight-nonce-000000' );
	} );

	/**
	 * EDITOR-10 — Q3 single-flight re-dispatch is OWNER-SCOPED. Concurrent
	 * sudo_required rejections share ONE modal (single-flight), but on a grant
	 * only the request that OPENED the modal (the owner — the user's actioned
	 * request) is re-dispatched. A concurrent rejection that attached while the
	 * modal was already open (a non-owner: a background/secondary gated request
	 * the user did not action at that moment) must NOT be auto-replayed — it stays
	 * rejected with its original error, so no surprise mutation fires. Prefer
	 * under-replay (self-healing: the app can retry against the now-active session)
	 * over over-replay (an unintended side effect the user never confirmed).
	 *
	 * Determinism: fire A and WAIT for the modal (A is unambiguously the owner),
	 * then fire B — by then pendingGrant is non-null, so B is unambiguously a
	 * non-owner. On the old replay-all code B would also re-dispatch and resolve;
	 * owner-scoping makes B stay rejected.
	 */
	test( 'EDITOR-10: concurrent grant re-dispatches only the owner, not a background request', async ( {
		page,
	} ) => {
		// A: the user-actioned gated request — fire WITHOUT awaiting; it opens the
		// modal and becomes the single-flight owner.
		const pendingOwner = page.evaluate( () =>
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

		// B: a second gated request that arrives WHILE the modal is open — it
		// attaches to the same pending grant as a NON-owner.
		const pendingNonOwner = page.evaluate( () =>
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

		// Grant once.
		await modal.locator( 'input[type="password"]' ).fill( 'password' );
		await modal
			.locator( '.components-button', { hasText: 'Confirm' } )
			.click();
		await expect( modal ).toBeHidden();

		// The owner re-dispatched and resolved; the non-owner was NOT replayed and
		// stays rejected with its original sudo_required error.
		expect( await pendingOwner ).toBe( 'resolved' );
		expect( await pendingNonOwner ).toBe( 'rejected:sudo_required' );
	} );

	/**
	 * EDITOR-11 — the not-granted path stays shared for ALL concurrent callers.
	 * Owner-scoping only narrows the GRANTED re-dispatch; when the grant is
	 * declined (here: cancel), both the owner and the non-owner must still fall
	 * back to the link-out snackbar and both original requests stay rejected.
	 * Guards against the owner-scoping change accidentally suppressing the
	 * non-owner's fallback surface.
	 */
	test( 'EDITOR-11: cancelling a shared modal links out and rejects every concurrent caller', async ( {
		page,
	} ) => {
		const pendingOwner = page.evaluate( () =>
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

		const pendingNonOwner = page.evaluate( () =>
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

		// Decline the single shared modal.
		await modal
			.locator( '.components-button', { hasText: 'Cancel' } )
			.click();
		await expect( modal ).toBeHidden();

		// Both callers are rejected (nothing granted, nothing replayed).
		expect( await pendingOwner ).toContain( 'rejected' );
		expect( await pendingNonOwner ).toContain( 'rejected' );

		// The shared fallback surfaced the link-out snackbar.
		const notice = await waitForNotice( page );
		expect( notice.actionLabels ).toEqual( [ 'Reauthenticate' ] );
	} );
} );
