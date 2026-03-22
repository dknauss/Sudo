/**
 * Challenge flow tests — CHAL-01, CHAL-02, CHAL-03
 *
 * Tests the full stash-challenge-replay flow and challenge page form elements.
 *
 * Challenge page: /wp-admin/admin.php?page=wp-sudo-challenge
 * Source: includes/class-challenge.php (verified)
 *
 * Stash-replay flow (verified from class-challenge.php + class-request-stash.php):
 *   1. User navigates to gated URL (e.g. plugin activate)
 *   2. Gate intercepts at admin_init, calls challenge_admin()
 *   3. Request_Stash::save() stores request in transient _wp_sudo_stash_{16-char-key}
 *   4. Gate redirects to /wp-admin/admin.php?page=wp-sudo-challenge&stash_key=KEY
 *   5. User enters password → AJAX to admin-ajax.php?action=wp_sudo_challenge_auth
 *   6. On success: data.redirect returned, JS sets window.location.href = data.redirect
 *   7. Destination page loads (the replayed action)
 *
 * DOM IDs (verified from class-challenge.php render_page()):
 *   #wp-sudo-challenge-card          — the card wrapper
 *   #wp-sudo-challenge-password-step — password step container
 *   #wp-sudo-challenge-password-form — the form element
 *   #wp-sudo-challenge-password      — password input (type=password)
 *   #wp-sudo-challenge-submit        — submit button
 *   #wp-sudo-challenge-error         — error box (role=alert, hidden by default)
 *   #wp-sudo-challenge-2fa-step      — 2FA step (hidden by default)
 *
 * PITFALL (Pitfall 2): Always use Promise.all([waitForURL, click]) when submitting
 * the challenge form. The AJAX call completes asynchronously then JS sets
 * window.location.href. A bare waitForNavigation() races with the AJAX chain.
 *
 * PITFALL (Pitfall 7): Challenge page breaks out of iframes. Always navigate to the
 * challenge URL from the top-level frame (page.goto), never from an iframe context.
 *
 * PITFALL (nonce): WordPress rejects plugin activation without a valid _wpnonce.
 * The nonce is checked by wp_verify_nonce() BEFORE the gate intercepts. Extract a
 * real nonce from the plugins page activate link. See getActivateUrl() below.
 *
 * PITFALL (gate-ui): When no sudo session is active, the PHP gate (class-gate.php
 * filter_plugin_action_links) replaces <a> activate links with <span> elements
 * with no href. To scrape a real activate URL with nonce, we must first activate a
 * sudo session so the real <a> links render, then clear the session before
 * navigating to the gated URL to trigger the challenge.
 *
 * PITFALL (Pitfall 6): CHAL tests must NOT have an active sudo session when testing
 * the gate redirect. Ensure no wp_sudo_token cookie before CHAL-01.
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import type { Page } from '@playwright/test';
import { exec } from 'child_process';
import { promisify } from 'util';

const execAsync = promisify( exec );

/**
 * Extract a real plugin activate URL (with valid nonce) from plugins.php.
 *
 * WordPress generates nonces per-session and they expire. The only reliable way
 * to get a valid nonce for the plugin activate action in a test is to scrape it
 * from the plugins page — just as a real user would click the link.
 *
 * CRITICAL: When no sudo session is active, the PHP gate replaces <a> links with
 * <span> elements on the server side (class-gate.php filter_plugin_action_links).
 * To scrape a real activate URL we must:
 *   1. Activate a sudo session (so real <a> links render)
 *   2. Navigate to plugins.php
 *   3. Extract the href for Hello Dolly's activate action
 *   4. Clear the sudo cookie (so the gate intercepts when we navigate to that URL)
 *
 * Source: WordPress plugins.php — activate links include _wpnonce query param (verified)
 * Source: class-gate.php — admin_init gate fires BEFORE WordPress nonce/cap checks (verified)
 * Source: class-challenge.php — stash stores the gated URL including nonce (verified)
 *
 * @param page       Playwright Page object.
 * @returns          The full admin-relative URL for Hello Dolly's activate action with nonce,
 *                   e.g. '/wp-admin/plugins.php?action=activate&plugin=hello.php&_wpnonce=abc123'
 *                   Returns null if the expected activate link is unavailable.
 */
async function getActivateUrl( page: Page ): Promise<string | null> {
    // Step A: Activate a sudo session so the gate renders real <a> links.
    // Without a session, filter_plugin_action_links() replaces <a> with <span> (no href).
    // Source: class-gate.php filter_plugin_action_links() (verified)
    await activateSudoSession( page );

    // Step B: Navigate to plugins.php with active session — real <a> links render.
    await page.goto( '/wp-admin/plugins.php' );

    // Use Hello Dolly specifically. Akismet activation can redirect to its own
    // settings screen, which makes CHAL-01's replay assertion against plugins.php
    // brittle even when stash replay is working correctly.
    const activateLink = page.locator(
        '.activate a[href*="action=activate"][href*="plugin=hello.php"]'
    ).first();

    if ( await activateLink.count() === 0 ) {
        return null;
    }

    const href = await activateLink.getAttribute( 'href' );
    if ( ! href ) {
        return null;
    }

    // WordPress plugins.php renders activate links as relative paths (e.g. "plugins.php?action=activate&...")
    // without a leading slash. When passed to page.goto(), Playwright resolves them relative to the
    // current origin (not the current path), so "plugins.php?..." becomes
    // "http://localhost:8889/plugins.php?..." instead of the correct
    // "http://localhost:8889/wp-admin/plugins.php?...". The gate only fires for paths under /wp-admin/.
    // Fix: prefix with /wp-admin/ when the href is a bare relative path.
    if ( ! href.startsWith( '/' ) && ! href.startsWith( 'http' ) ) {
        return '/wp-admin/' + href;
    }

    return href;
}

/**
 * Clear sudo session cookies from the browser context while preserving WP auth cookies.
 * This forces the gate to intercept the next gated request.
 */
async function clearSudoSession( page: Page ): Promise<void> {
    const context = page.context();
    const cookies = await context.cookies();
    const authCookies = cookies.filter( ( c ) => ! c.name.startsWith( 'wp_sudo' ) );
    await context.clearCookies();
    await context.addCookies( authCookies );
}

/**
 * Delete all WordPress transients to clear WP Sudo's IP-based rate-limit state.
 *
 * WP Sudo stores per-IP failure events in two transient families:
 *   - wp_sudo_ip_failure_event_{sha256(ip)}  — rolling list of failure timestamps
 *   - wp_sudo_ip_lockout_until_{sha256(ip)}  — lockout expiry timestamp
 *
 * These accumulate across test runs. When 5 events exist from prior runs, the next
 * wrong-password attempt in CHAL-03 triggers a 5-minute lockout instead of returning
 * "Incorrect password". Clear them in beforeAll and afterAll.
 *
 * Implementation: `wp transient delete --all` clears all transients in the database.
 * This is the only reliable way to clear transients — `wp option list --search` does
 * not enumerate transients (they are excluded from option list output).
 *
 * Note: This clears ALL transients in the wp-env development site, which is acceptable
 * because the test environment is ephemeral and transients are auto-regenerated on demand.
 *
 * Source: includes/class-sudo-session.php — IP_FAILURE_EVENT_TRANSIENT_PREFIX (verified)
 * Source: includes/class-sudo-session.php — IP_LOCKOUT_UNTIL_TRANSIENT_PREFIX (verified)
 */
async function clearSudoIpTransients(): Promise<void> {
    try {
        await execAsync(
            'npx wp-env run cli wp transient delete --all --quiet 2>/dev/null || true',
            { timeout: 15_000 }
        );
    } catch {
        // Ignore — if wp transient delete fails, the test may still pass.
    }
}

/**
 * Set the wp-env CLI policy temporarily to unrestricted, run a callback that
 * executes WP-CLI gated commands, then restore the original setting.
 *
 * WP Sudo's default cli_policy is 'limited', which blocks gated WP-CLI commands
 * like `wp plugin deactivate`. To run them in test setup/teardown, we temporarily
 * override the policy via `wp option set` (not a gated action), execute the
 * commands, then restore.
 *
 * Source: includes/class-gate.php — CLI policy check in intercept() (verified)
 * Source: includes/class-admin.php — default cli_policy = 'limited' (verified)
 */
async function withCliPolicyUnrestricted( fn: () => Promise<void> ): Promise<void> {
    try {
        await execAsync(
            `npx wp-env run cli wp option set wp_sudo_settings '{"cli_policy":"unrestricted"}' --format=json --quiet 2>/dev/null`,
            { timeout: 15_000 }
        );
    } catch {
        // Ignore — settings may not exist yet (first run).
    }
    try {
        await fn();
    } finally {
        // Always restore — even if the inner function throws.
        await execAsync(
            'npx wp-env run cli wp option delete wp_sudo_settings --quiet 2>/dev/null || true',
            { timeout: 15_000 }
        );
    }
}

test.describe( 'Challenge flow', () => {
    test.beforeAll( async () => {
        // Ensure at least one plugin is inactive for CHAL-01 stash-replay test.
        // Without an inactive plugin, getActivateUrl() returns null and CHAL-01 skips.
        // Source: wp-env ships with Hello Dolly (hello.php) which can be deactivated (verified)
        //
        // PITFALL (CLI policy): WP Sudo's default cli_policy='limited' blocks gated WP-CLI
        // commands. We temporarily set cli_policy='unrestricted' to allow deactivation.
        await withCliPolicyUnrestricted( async () => {
            await execAsync(
                'npx wp-env run cli wp plugin deactivate hello --quiet 2>/dev/null || true',
                { timeout: 30_000 }
            );
        } );

        // Clear any leftover failure/lockout meta from previous test runs.
        // If a prior run left user 1 locked out (e.g. from CHAL-03 incorrect password
        // not cleaned up), the challenge page form is disabled which causes tests to fail.
        // Source: includes/class-sudo-session.php — failure meta keys (verified)
        for ( const metaKey of [
            '_wp_sudo_lockout_until',
            '_wp_sudo_failure_event',
            '_wp_sudo_failed_attempts',
            '_wp_sudo_throttle_until',
        ] ) {
            await execAsync(
                `npx wp-env run cli wp user meta delete 1 ${ metaKey } --quiet 2>/dev/null || true`,
                { timeout: 15_000 }
            );
        }

        // Clear IP-based lockout/failure transients from previous runs.
        // WP Sudo also tracks failures per IP address in WordPress transients
        // (prefixes: wp_sudo_ip_failure_event_ and wp_sudo_ip_lockout_until_).
        // These persist across test runs and can cause "Too many failed attempts" lockouts
        // even when user meta is clean.
        // Source: includes/class-sudo-session.php — IP_FAILURE_EVENT_TRANSIENT_PREFIX (verified)
        // Source: includes/class-sudo-session.php — IP_LOCKOUT_UNTIL_TRANSIENT_PREFIX (verified)
        await clearSudoIpTransients();
    } );

    test.afterAll( async () => {
        // CHAL-01 replays the plugin activate stash, which activates hello.php.
        // Deactivate it again so that gate-ui.spec.ts (which runs after challenge.spec.ts
        // alphabetically) has an inactive plugin to test against.
        // Source: wp-env alphabetical spec order: challenge → cookie → gate-ui → mu-plugin (verified)
        //
        // Also clear all sudo failure meta for user 1 so subsequent specs that call
        // activateSudoSession() are not blocked by rate-limiting or lockout from
        // CHAL-03's incorrect password attempt.
        // Source: includes/class-sudo-session.php — lockout/throttle meta keys (verified)
        await withCliPolicyUnrestricted( async () => {
            await execAsync(
                'npx wp-env run cli wp plugin deactivate hello --quiet 2>/dev/null || true',
                { timeout: 30_000 }
            );
        } );

        // Clear failure meta — these do not require the unrestricted policy because
        // `wp user meta delete` is not a gated WP-CLI action.
        for ( const metaKey of [
            '_wp_sudo_lockout_until',
            '_wp_sudo_failure_event',
            '_wp_sudo_failed_attempts',
            '_wp_sudo_throttle_until',
        ] ) {
            await execAsync(
                `npx wp-env run cli wp user meta delete 1 ${ metaKey } --quiet 2>/dev/null || true`,
                { timeout: 15_000 }
            );
        }

        // Clear IP-based lockout/failure transients accumulated during CHAL-03.
        // Source: includes/class-sudo-session.php — IP transient prefixes (verified)
        await clearSudoIpTransients();
    } );

    test.beforeEach( async ( { page } ) => {
        // Ensure no active sudo session before challenge flow tests.
        // Gate only fires when there is NO active session. If wp_sudo_token is present,
        // the request passes through without a challenge redirect.
        await clearSudoSession( page );
    } );

    /**
     * CHAL-01: Full stash-replay flow.
     *
     * Trigger a real gated GET action (plugin activate), verify redirect to challenge
     * page, enter password, verify redirect back to plugins.php with action replayed.
     *
     * The plugin activate action is chosen because:
     * - It is a GET request (simpler replay than POST)
     * - It is always available in a wp-env install (at least one inactive plugin exists)
     * - The gate intercepts it before WordPress's own nonce/capability checks (admin_init)
     *
     * After replay, WordPress processes the activation (or rejects it — the outcome
     * does not matter for CHAL-01; what matters is that the challenge page was shown,
     * password was accepted, and the stash was replayed to plugins.php).
     */
    test( 'CHAL-01: gated action redirects to challenge, correct password replays action', async ( {
        page,
    } ) => {
        // Step 1: Get a real activate URL with a valid nonce.
        // This activates a sudo session internally, scrapes the URL, then we clear
        // the session so the gate intercepts our subsequent navigation.
        // Source: WordPress plugins.php (verified) + gate-ui pitfall note
        const activateUrl = await getActivateUrl( page );

        if ( activateUrl === null ) {
            // Skip test if no deactivatable plugin found (unlikely in wp-env).
            test.skip( true, 'No activate link found on plugins.php — cannot test stash-replay' );
            return;
        }

        // Step 2: Clear the sudo session cookie so the gate will intercept.
        // Gate checks Sudo_Session::is_active() at admin_init; session must be absent.
        // Source: class-gate.php gate_admin() — checks session before stashing (verified)
        await clearSudoSession( page );

        // Step 3: Navigate to the gated URL (plugin activate).
        // Gate fires at admin_init and redirects to challenge page.
        // Source: class-gate.php challenge_admin() + class-request-stash.php save() (verified)
        await page.goto( activateUrl );

        // Step 4: Verify challenge page redirect occurred.
        // Source: class-challenge.php — PAGE_SLUG = 'wp-sudo-challenge' (verified)
        await page.waitForURL( /page=wp-sudo-challenge/, { timeout: 10_000 } );
        await expect(
            page.locator( '#wp-sudo-challenge-card' ),
            'Challenge card must appear after gate intercepts gated URL'
        ).toBeVisible();

        // Step 5: Wait for JS config object before interacting.
        // Source: admin/js/wp-sudo-challenge.js — wpSudoChallenge localised config (verified)
        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );

        // Step 6: Enter password and submit.
        // Source: class-challenge.php render_page() — id="wp-sudo-challenge-password" (verified)
        await page.fill( '#wp-sudo-challenge-password', 'password' );

        // Step 7: Submit and wait for redirect back to plugins.php.
        // CRITICAL (Pitfall 2): Promise.all pattern — AJAX triggers window.location.href.
        // Source: admin/js/wp-sudo-challenge.js handleReplay() — sets window.location.href = data.redirect (verified)
        await Promise.all( [
            page.waitForURL( /plugins\.php/, { timeout: 15_000 } ),
            page.click( '#wp-sudo-challenge-submit' ),
        ] );

        // Step 8: Verify we landed on plugins.php (stash replayed).
        await expect(
            page,
            'Must be back on plugins.php after stash-replay'
        ).toHaveURL( /plugins\.php/ );
    } );

    /**
     * CHAL-02: Challenge page has all required form elements.
     *
     * Navigate to challenge page in session-only mode (no stash_key) and verify
     * the DOM structure matches what the plugin renders.
     *
     * Source: class-challenge.php render_page() — all IDs verified
     */
    test( 'CHAL-02: challenge page renders required form elements', async ( {
        page,
    } ) => {
        // Session-only mode: no stash_key param.
        // Source: class-challenge.php — without stash_key, renders session-only UI (verified)
        await page.goto( '/wp-admin/admin.php?page=wp-sudo-challenge' );

        // Wait for challenge JS to initialise.
        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );

        // Source: class-challenge.php render_page() — all element IDs (verified)
        await expect(
            page.locator( '#wp-sudo-challenge-card' ),
            'Challenge card wrapper must be visible'
        ).toBeVisible();

        await expect(
            page.locator( '#wp-sudo-challenge-password-form' ),
            'Password form must be visible'
        ).toBeVisible();

        await expect(
            page.locator( '#wp-sudo-challenge-password' ),
            'Password input (type=password) must be visible'
        ).toBeVisible();

        await expect(
            page.locator( '#wp-sudo-challenge-submit' ),
            'Submit button must be visible'
        ).toBeVisible();

        // Error box is hidden by default (shown only after a failed attempt).
        // Source: class-challenge.php render_page() — hidden div, role=alert (verified)
        await expect(
            page.locator( '#wp-sudo-challenge-error' ),
            'Error box must be hidden initially'
        ).toBeHidden();

        // 2FA step is hidden (no 2FA plugin active in wp-env by default).
        // Source: class-challenge.php render_page() — 2FA step hidden if no 2FA active (verified)
        await expect(
            page.locator( '#wp-sudo-challenge-2fa-step' ),
            '2FA step must be hidden when 2FA is not active'
        ).toBeHidden();

        // Cancel link must be present in the password step form footer.
        // Source: class-challenge.php render_page() — <a href="cancelUrl" class="button">Cancel</a> (verified)
        // NOTE: There are two Cancel links on the page (one in password step, one in hidden 2FA form).
        // Scope to the password step container to avoid strict mode violation.
        await expect(
            page.locator( '#wp-sudo-challenge-password-step a.button:has-text("Cancel")' ),
            'Cancel link must be present in the password step'
        ).toBeVisible();
    } );

    /**
     * CHAL-03: Wrong password shows error message without page reload.
     *
     * Submit an incorrect password and verify the error box becomes visible.
     * The page must NOT navigate — the error appears inline via AJAX response.
     *
     * Source: admin/js/wp-sudo-challenge.js — on auth failure, unhides #wp-sudo-challenge-error (verified)
     * Source: class-challenge.php wp_sudo_challenge_auth AJAX handler — returns 'Incorrect password. Please try again.' (verified)
     *
     * Error text (verified from class-challenge.php handle_ajax_auth() 'invalid_password' case):
     *   'Incorrect password. Please try again.'
     */
    test( 'CHAL-03: wrong password shows inline error without page reload', async ( {
        page,
    } ) => {
        await page.goto( '/wp-admin/admin.php?page=wp-sudo-challenge' );

        await page.waitForFunction(
            () => typeof ( window as Window & { wpSudoChallenge?: unknown } ).wpSudoChallenge !== 'undefined'
        );

        const initialUrl = page.url();

        await page.fill( '#wp-sudo-challenge-password', 'this-is-wrong-password' );

        // Click submit — do NOT use Promise.all(waitForURL) here because we expect
        // the page to STAY on the challenge page (no navigation on failure).
        await page.click( '#wp-sudo-challenge-submit' );

        // Wait for error box to become visible.
        // Source: admin/js/wp-sudo-challenge.js showError() — sets box.hidden = false (verified)
        // Note: showError() uses requestAnimationFrame to set textContent, so we wait
        // for visibility first, then check text content.
        await expect(
            page.locator( '#wp-sudo-challenge-error' ),
            'Error box must become visible after wrong password'
        ).toBeVisible( { timeout: 10_000 } );

        // Error text matches "Incorrect password" substring.
        // Source: class-challenge.php handle_ajax_auth() — 'Incorrect password. Please try again.' (verified)
        await expect(
            page.locator( '#wp-sudo-challenge-error' ),
            'Error message must contain "Incorrect password"'
        ).toContainText( 'Incorrect password', { timeout: 5_000 } );

        // URL must not have changed — no page navigation on wrong password.
        expect(
            page.url(),
            'URL must remain on challenge page after wrong password attempt'
        ).toBe( initialUrl );
    } );

    /**
     * CHAL-04: A stale challenge URL should recover when the sudo session is already active.
     *
     * This covers the stale-tab case fixed in class-challenge.php: if the browser already
     * has an active sudo session, navigating to a challenge URL with an expired or bogus
     * stash key must not trap the user on another password prompt.
     */
    test( 'CHAL-04: active session escapes stale challenge URL', async ( {
        page,
    } ) => {
        await activateSudoSession( page );

        await page.goto(
            '/wp-admin/admin.php?page=wp-sudo-challenge&stash_key=expired-key&return_url=' +
            encodeURIComponent( 'http://localhost:8889/wp-admin/plugins.php' )
        );

        await page.waitForURL( /plugins\.php/, { timeout: 15_000 } );

        await expect(
            page,
            'Active sudo session must recover to the requested admin page'
        ).toHaveURL( /plugins\.php/ );

        await expect(
            page.locator( '#wp-sudo-challenge-password-form' ),
            'Recovered stale challenge must not leave the password form onscreen'
        ).toHaveCount( 0 );
    } );
} );
