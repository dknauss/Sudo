/**
 * Post-challenge landing — LAND-01 through LAND-04
 *
 * The release invariant has a client half that PHP tests cannot reach:
 *
 *   No successful challenge automatically navigates to any requester-supplied
 *   destination.
 *
 * `admin/js/wp-sudo-challenge.js` performs the navigation. This repository has no
 * JavaScript unit harness, so a browser test is the only thing that can fail when
 * someone restores `window.location.href = config.cancelUrl` in a success branch —
 * and an independent review demonstrated that exact mutation surviving the entire
 * PHP suite.
 *
 * Each case supplies an attacker-shaped `return_url` and asserts the browser did
 * NOT go there. The queryless case matters most: an earlier fix filtered `action`
 * and nonce parameters out of `return_url`, which a path-borne custom action
 * sails straight through.
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

		await expect( page ).not.toHaveURL( /wp-sudo-hostile-landing/ );

		const html = await page.content();
		expect( html ).not.toContain( 'wp-sudo-hostile-landing' );
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
