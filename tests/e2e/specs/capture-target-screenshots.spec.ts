/**
 * Capture the two challenge-page target states for the WordPress.org listing.
 *
 * WHY THIS IS A SEPARATE FILE
 *
 * `capture-screenshots.spec.ts` writes shots 1-9 from a single test, and its own
 * docblock records that running it overwrites `screenshot-9` with a different
 * surface than the readme advertises (issue #301, undecided). Shots 13/14 are
 * additive — nothing here re-captures an existing asset — so keeping them out of
 * that file means the #301 landmine is never armed to add them.
 *
 * WHAT IS BEING CAPTURED, AND WHY IT IS NOT A REFRESH
 *
 * `screenshot-1` shows the challenge page reached directly
 * (`admin.php?page=wp-sudo-challenge`), which is the SESSION-ONLY flow: no stash,
 * so `Challenge::describe_stash_target( null )` returns '' and the `Target:` line
 * correctly does not render. That asset is not stale — it depicts a flow that
 * legitimately has no target.
 *
 * What no asset covered before this file is the challenge reached THROUGH an
 * intercepted request, which is the only flow where the #322 v2 `Target:` panel
 * appears. That is a missing surface, not a stale one.
 *
 * The panel is conditional, so a happy-path-only capture would document a control
 * that is not always present — the complaint #386 already records. Hence two:
 *
 *   13  a named target      — `Target: <code>…</code>` rendered
 *   14  the coarse label    — same challenge, no `Target:` line
 *
 * Shot 14 uses `tools.export`, the honest built-in for the unnamed state and the
 * one rule this release changes. Since #397 there are three producers of that
 * state (empty target, non-array target, and a target whose values are all
 * non-scalar so `describe_stash_target()` skips them); one reproducible instance
 * a reader can verify from `Action_Registry` is what the listing needs.
 *
 * Both shots ASSERT the state before capturing, so a silent render change fails
 * the run instead of quietly committing a misleading listing image.
 *
 * Run:      WP_SUDO_CAPTURE=1 playwright test … capture-target-screenshots.spec.ts
 * Requires: wp-env dev site on 8889.
 *
 * GUARDED: no-ops unless WP_SUDO_CAPTURE is set, so the normal suite never
 * overwrites the committed set.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import { wpEnvRunCliSync } from '../fixtures/wp-env';
import * as path from 'path';

const CAPTURE = !! process.env.WP_SUDO_CAPTURE;
const OUT_DIR = path.resolve( __dirname, '../../../.wordpress-org' );

const shot = ( n: number ): string => path.join( OUT_DIR, `screenshot-${ n }.png` );

const CHALLENGE_CARD = '#wp-sudo-challenge-card';
const TARGET_LINE = '.wp-sudo-challenge-target';


test.describe( 'Listing screenshots — challenge target states (#322 v2)', () => {
	test.skip(
		! CAPTURE,
		'Set WP_SUDO_CAPTURE=1 to capture the .wordpress-org set.'
	);

	// reducedMotion: the challenge stylesheet explicitly disables its loading-spinner
	// animation under prefers-reduced-motion (wp-sudo-challenge.css:156-159). Using the
	// app's own affordance is more reliable than Playwright's animations:'disabled',
	// which settles CSS animations but does not stop a spinner from perturbing layout.
	test.use( {
		viewport: { width: 1280, height: 960 },
		reducedMotion: 'reduce',
	} );

	test( 'capture challenge target states', async ( { page } ) => {
		// ── 13 — named target ───────────────────────────────────────────────
		// Reach the challenge the way a user does: by attempting a gated action.
		// The gate stashes the request at admin_init and redirects with a
		// stash_key, which is what gives describe_stash_target() something to name.
		//
		// Two-step, and the order is forced by the gate itself: with no sudo
		// session, filter_plugin_action_links() replaces the Activate <a> with a
		// <span>, so there is no href to follow. So activate a session to read the
		// real link, then revoke it so the gate actually fires on the navigation.
		await activateSudoSession( page );
		await page.goto( '/wp-admin/plugins.php' );

		const activateLink = page
			.locator( '.activate a[href*="action=activate"]' )
			.first();

		expect(
			await activateLink.count(),
			'an inactive plugin must be present to capture a named target'
		).toBeGreaterThan( 0 );

		const href = await activateLink.getAttribute( 'href' );
		expect( href, 'activate link must have an href' ).toBeTruthy();

		// plugins.php renders activate links as bare relative paths; page.goto()
		// resolves those against the origin, not the current path, so the /wp-admin/
		// prefix is required or the gate (which only fires under /wp-admin/) is missed.
		const target =
			href!.startsWith( '/' ) || href!.startsWith( 'http' )
				? href!
				: '/wp-admin/' + href!;

		// Drop the session so the navigation is intercepted rather than allowed.
		wpEnvRunCliSync( 'cli', [ 'wp', 'sudo', 'revoke', '--user=admin' ] );

		await page.goto( target );
		await page.locator( CHALLENGE_CARD ).waitFor();

		// Assert before capturing: this shot exists to show the panel, so a run
		// that silently lost it must fail rather than commit a misleading image.
		await expect(
			page.locator( TARGET_LINE ),
			'shot 13 must show the Target: line — it is the whole point of the shot'
		).toBeVisible();

		// Element-scoped rather than page-scoped. The card IS the subject, and a
		// page screenshot waits for whole-page stability that this admin screen does
		// not reach within the timeout.
		await page.screenshot( {
			path: shot( 13 ),
			animations: 'disabled',
			timeout: 30_000,
		} );

		// ── 14 — coarse label, no target line ───────────────────────────────
		// tools.export gates export.php's WXR download. Its stash carries no
		// nameable target, so the panel is correctly absent and the user sees only
		// the rule label. This is the state a happy-path-only capture would hide.
		await page.goto( '/wp-admin/export.php?download=true&content=all' );
		await page.locator( CHALLENGE_CARD ).waitFor();

		await expect(
			page.locator( TARGET_LINE ),
			'shot 14 must NOT show a Target: line — it documents the conditional absence'
		).toHaveCount( 0 );

		await page.screenshot( {
			path: shot( 14 ),
			animations: 'disabled',
			timeout: 30_000,
		} );
	} );
} );
