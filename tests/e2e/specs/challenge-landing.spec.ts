/**
 * Post-challenge landing — LAND-01 through LAND-05
 *
 * The release invariant:
 *
 *   No successful challenge automatically navigates to any requester-supplied
 *   destination.
 *
 * The server half is covered by PHP tests. This file covers the CLIENT half, and
 * covers it in two different ways on purpose, because the obvious way is not
 * sufficient:
 *
 * LAND-01..04 plant a hostile `return_url` and assert the browser never lands
 * there. Those prove the end-to-end behaviour, but they cannot fail for a
 * client-only regression — the server forces `cancelUrl` to the neutral page
 * before the client ever sees it, so restoring `config.cancelUrl` in a success
 * branch still lands on the dashboard. An independent review established that;
 * an earlier version of this header claimed the opposite and was wrong.
 *
 * LAND-05 closes that gap by injecting the hostile value into the localized
 * config from the browser, before the page script runs. That tests the client's
 * contract independently of what the server currently supplies — which is the
 * only way to catch a regression that is dangerous *in combination with* a
 * future server regression, the combination nothing else tests.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import type { Page } from '@playwright/test';

const DEFAULT_PASSWORD = process.env.WP_PASSWORD ?? 'password';

/** A destination the attacker chose. Never a legitimate landing. */
const HOSTILE_RETURN = '/wp-admin/options-general.php?page=wp-sudo-hostile-landing';

function challengeUrl( returnUrl: string ): string {
	return `/wp-admin/admin.php?page=wp-sudo-challenge&return_url=${ encodeURIComponent( returnUrl ) }`;
}

async function clearSudoCookies( page: Page ): Promise< void > {
	const context = page.context();
	const cookies = await context.cookies();
	await context.clearCookies();
	await context.addCookies( cookies.filter( ( c ) => ! c.name.startsWith( 'wp_sudo' ) ) );
}

/**
 * The assertion every case shares.
 *
 * Deliberately checks the landing rather than the absence of a redirect: what
 * matters is where the authenticated browser ended up, not how it got there.
 */
async function expectNeutralLanding( page: Page ): Promise< void > {
	await expect( page ).not.toHaveURL( /wp-sudo-hostile-landing/ );
	await expect( page ).not.toHaveURL( /page=wp-sudo-challenge/ );
}

test.describe( 'Post-challenge landing is never requester-supplied', () => {
	test.beforeEach( async ( { page } ) => {
		await clearSudoCookies( page );
	} );

	test( 'LAND-01: password success does not navigate to a queryless custom action path', async ( {
		page,
	} ) => {
		await page.goto( challengeUrl( HOSTILE_RETURN ) );
		await expect( page.locator( '#wp-sudo-challenge-password-step' ) ).toBeVisible();

		await page.waitForFunction(
			() => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
		);
		await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

		await Promise.all( [
			page.waitForURL( ( url ) => ! /page=wp-sudo-challenge/.test( url.href ), { timeout: 15_000 } ),
			page.click( '#wp-sudo-challenge-submit' ),
		] );

		await expectNeutralLanding( page );
	} );

	test( 'LAND-02: the localized config offers no requester destination', async ( { page } ) => {
		await page.goto( challengeUrl( HOSTILE_RETURN ) );

		const config = await page.evaluate(
			() => ( window as Window & { wpSudoChallenge?: Record< string, unknown > } ).wpSudoChallenge
		);

		// Server-side guarantee, asserted from the browser so it cannot drift from
		// what the client actually receives.
		expect( JSON.stringify( config ?? {} ) ).not.toContain( 'wp-sudo-hostile-landing' );
	} );

	test( 'LAND-03: an already-active session is not navigated to the requester destination', async ( {
		page,
	} ) => {
		await activateSudoSession( page, DEFAULT_PASSWORD );

		await page.goto( challengeUrl( HOSTILE_RETURN ) );

		// The resume page must render and stay put — no DOMContentLoaded navigation.
		await expect( page.locator( '#wp-sudo-challenge-card' ) ).toBeVisible();
		await page.waitForTimeout( 1200 );

		// Assert we are STILL on the challenge page. Checking `not.toHaveURL(hostile)`
		// is wrong here and failed in CI: the hostile value is a query parameter of
		// the URL we navigated to, so the address bar contains it even though the
		// browser never went anywhere. Staying put is the property under test.
		await expect( page ).toHaveURL( /page=wp-sudo-challenge/ );

		// The offered destinations must be neutral — the requester's value must not
		// appear in any href the page renders.
		const hrefs = await page.locator( '.wp-sudo-challenge-card a' ).evaluateAll(
			( links ) => links.map( ( a ) => ( a as HTMLAnchorElement ).getAttribute( 'href' ) ?? '' )
		);
		expect( hrefs.join( ' ' ) ).not.toContain( 'wp-sudo-hostile-landing' );
	} );

	test( 'LAND-05: a hostile cancelUrl in the config is still never navigated to', async ( {
		page,
	} ) => {
		// Inject before any page script runs. The server currently supplies a neutral
		// cancelUrl, so without this the client's own contract is untested: a success
		// branch reading config.cancelUrl would still land on the dashboard and pass.
		await page.addInitScript( () => {
			let held: Record< string, unknown > | undefined;
			Object.defineProperty( window, 'wpSudoChallenge', {
				configurable: true,
				set( value: Record< string, unknown > ) {
					value.cancelUrl = '/wp-admin/options-general.php?page=wp-sudo-hostile-landing';
					held = value;
				},
				get() {
					return held;
				},
			} );
		} );

		await page.goto( challengeUrl( '/wp-admin/' ) );
		await expect( page.locator( '#wp-sudo-challenge-password-step' ) ).toBeVisible();

		await page.waitForFunction(
			() => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
		);

		// Confirm the injection actually took, so a silently-failing setup cannot
		// masquerade as a passing test.
		const injected = await page.evaluate(
			() =>
				( window as Window & { wpSudoChallenge?: Record< string, unknown > } ).wpSudoChallenge
					?.cancelUrl
		);
		expect( String( injected ) ).toContain( 'wp-sudo-hostile-landing' );

		await page.fill( '#wp-sudo-challenge-password', DEFAULT_PASSWORD );

		await Promise.all( [
			page.waitForURL( ( url ) => ! /page=wp-sudo-challenge/.test( url.href ), { timeout: 15_000 } ),
			page.click( '#wp-sudo-challenge-submit' ),
		] );

		await expect( page ).not.toHaveURL( /wp-sudo-hostile-landing/ );
	} );

	test( 'LAND-04: cancelling does not reach the requester destination either', async ( { page } ) => {
		await page.goto( challengeUrl( HOSTILE_RETURN ) );

		const cancel = page.locator( '.wp-sudo-challenge-card a', { hasText: /cancel/i } ).first();

		if ( await cancel.count() ) {
			const href = await cancel.getAttribute( 'href' );
			expect( href ?? '' ).not.toContain( 'wp-sudo-hostile-landing' );
		}
	} );
} );
