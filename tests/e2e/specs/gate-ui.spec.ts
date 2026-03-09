/**
 * Gate UI tests — GATE-01, GATE-02, GATE-03
 *
 * Verify the gate UI disables plugin action buttons when no sudo session is active.
 *
 * Implementation note: WP Sudo uses a two-layer approach:
 * 1. PHP (class-gate.php filter_plugin_action_links) — server-side: replaces the
 *    <a> action link with <span class="wp-sudo-disabled" aria-disabled="true">.
 *    This is the primary mechanism for server-rendered plugin list rows.
 * 2. JavaScript (admin/js/wp-sudo-gate-ui.js disableButtons) — client-side: adds
 *    wp-sudo-disabled/aria-disabled to dynamically-injected content (theme search,
 *    infinite scroll). The JS selectorMap targets '.activate a', but on plugins.php
 *    the PHP filter has already replaced the <a> with a <span>, so the JS finds
 *    nothing to process on the initial page render.
 *
 * Because the PHP filter runs first, the DOM on plugins.php contains:
 *   <td class="activate">
 *     <span class="wp-sudo-disabled" aria-disabled="true" ...>Activate</span>
 *   </td>
 *
 * Tests target the PHP-rendered output (.activate .wp-sudo-disabled), which is
 * what users actually see in their browser.
 *
 * Source: includes/class-gate.php filter_plugin_action_links() (verified)
 * Source: admin/js/wp-sudo-gate-ui.js disableButtons() (verified — JS fallback)
 *
 * PITFALL: Gate UI only runs when NO session is active. These tests must NOT have
 * an active sudo session. The global storageState (admin.json) excludes wp_sudo_token
 * (see global-setup.ts), so tests start without a session — which is what we need.
 * Verify no session is active by asserting the timer node is absent before each test.
 */
import { test, expect } from '../fixtures/test';

test.describe( 'Gate UI disabled buttons', () => {
    test.beforeEach( async ( { page, context } ) => {
        // Ensure no active sudo session exists for these tests.
        // Source: global-setup.ts — storageState excludes wp_sudo_* cookies (verified)
        const cookies = await context.cookies();
        const hasSudoCookie = cookies.some( ( c ) =>
            c.name.startsWith( 'wp_sudo' )
        );
        if ( hasSudoCookie ) {
            // Clear sudo cookies only, preserving WP auth cookies.
            const authCookies = cookies.filter(
                ( c ) => ! c.name.startsWith( 'wp_sudo' )
            );
            await context.clearCookies();
            await context.addCookies( authCookies );
        }

        // Navigate to plugins page.
        // Source: class-gate.php — filter_plugin_action_links() runs on plugins.php (verified)
        await page.goto( '/wp-admin/plugins.php' );

        // Confirm no active session (admin bar timer node absent = no session).
        // Source: class-admin-bar.php — node 'wp-sudo-active' only added when session active (verified)
        await expect(
            page.locator( '#wp-admin-bar-wp-sudo-active' )
        ).not.toBeVisible();
    } );

    test( 'GATE-01: activate links have aria-disabled=true', async ( {
        page,
    } ) => {
        // PHP filter replaces <a> with <span aria-disabled="true"> on the server side.
        // Source: class-gate.php filter_plugin_action_links() — 'aria-disabled' => 'true' (verified)
        //
        // Selector: '.activate [aria-disabled="true"]' — targets the disabled element
        // inside the activate table cell, regardless of tag name (span or a).
        const disabledActivate = page.locator( '.activate [aria-disabled="true"]' );
        const count = await disabledActivate.count();

        // wp-env installs WordPress with Hello Dolly inactive.
        // At least one plugin should have a disabled activate element.
        expect(
            count,
            'At least one plugin must have a disabled activate element on plugins.php'
        ).toBeGreaterThan( 0 );

        // Check the first disabled activate element has aria-disabled.
        await expect(
            disabledActivate.first(),
            'Activate element must have aria-disabled=true when no sudo session is active'
        ).toHaveAttribute( 'aria-disabled', 'true' );
    } );

    test( 'GATE-02: disabled buttons have wp-sudo-disabled CSS class', async ( {
        page,
    } ) => {
        // Source: class-gate.php filter_plugin_action_links() — class="wp-sudo-disabled" (verified)
        // Source: admin/js/wp-sudo-gate-ui.js — btn.classList.add('wp-sudo-disabled') (verified)
        const disabledLinks = page.locator( '.wp-sudo-disabled' );

        await expect(
            disabledLinks.first(),
            'At least one element must have wp-sudo-disabled class on plugins.php'
        ).toBeVisible();

        // Also confirm the inline style is injected (pointer-events: none).
        // PHP sets inline style directly; gate-ui.js injects a stylesheet rule.
        // Source: class-gate.php — style="color:#787c82;cursor:default" (verified)
        // Source: admin/js/wp-sudo-gate-ui.js — '.wp-sudo-disabled{pointer-events:none}' (verified)
        const firstDisabled = disabledLinks.first();
        const pointerEvents = await firstDisabled.evaluate(
            ( el ) => window.getComputedStyle( el ).pointerEvents
        );
        expect(
            pointerEvents,
            'wp-sudo-disabled elements must have pointer-events: none'
        ).toBe( 'none' );
    } );

    test( 'GATE-03: clicking a disabled activate element does not navigate', async ( {
        page,
    } ) => {
        // Source: class-gate.php — PHP replaces <a> with <span>, no href to follow (verified)
        // Source: admin/js/wp-sudo-gate-ui.js — blockClick listener on capture phase (verified)
        //
        // The disabled element is a <span> (no href) with pointer-events:none.
        // Use force:true to bypass Playwright's own accessibility checks and
        // test that clicking the element genuinely does not navigate.
        const disabledActivate = page.locator( '.activate .wp-sudo-disabled' ).first();
        const initialUrl = page.url();

        // Use force:true because the element has pointer-events:none.
        await disabledActivate.click( { force: true } );

        // Small wait to allow any navigation that would have occurred.
        await page.waitForTimeout( 500 );

        expect(
            page.url(),
            'URL must remain on plugins.php after clicking a gate-disabled element'
        ).toBe( initialUrl );
    } );
} );
