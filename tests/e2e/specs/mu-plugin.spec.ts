/**
 * MU-plugin AJAX tests — MUPG-01, MUPG-02, MUPG-03
 *
 * Tests the MU-plugin install/uninstall AJAX flow from the Settings page.
 * Requires an active sudo session for install/uninstall (403 without session).
 *
 * AJAX endpoints (verified from includes/class-admin.php):
 *   Install:   action = wp_sudo_mu_install   (Admin::AJAX_MU_INSTALL)
 *   Uninstall: action = wp_sudo_mu_uninstall (Admin::AJAX_MU_UNINSTALL)
 *   Nonce:     action = wp_sudo_mu_plugin    (used in check_ajax_referer)
 *   Nonce field in FormData: _nonce (NOT _wpnonce)
 *
 * DOM IDs (verified from class-admin.php render_mu_plugin_status()):
 *   #wp-sudo-mu-status    — status text ("Installed" / "Not installed")
 *   #wp-sudo-mu-install   — install button (visible when not installed AND dir writable)
 *   #wp-sudo-mu-uninstall — uninstall button (visible when installed)
 *   #wp-sudo-mu-spinner   — spinner element
 *   #wp-sudo-mu-message   — ARIA live region for success/error messages
 *
 * JS behaviour (verified from admin/js/wp-sudo-admin.js):
 *   On click: shows spinner, sends AJAX (fetch), on success: setTimeout(1000) then window.location.reload()
 *   After reload: page re-renders with updated MU-plugin status
 *
 * Status text (verified from class-admin.php render_mu_plugin_status()):
 *   Installed:     "Installed"
 *   Not installed: "Not installed"
 *
 * Success icon (verified from class-admin.php render_mu_plugin_status()):
 *   Installed: <span class="dashicons dashicons-yes-alt" ...></span>
 *
 * PITFALL (Pitfall 1 / MU-plugin state): MU-plugin state persists between test runs.
 * Use beforeAll/afterAll WP-CLI commands to set a known state before each test group.
 *
 * PITFALL (wp-env container): Use 'cli' container (targets port 8889 dev site — the
 * same site browser tests run against). NOT 'tests-cli' which targets port 8890.
 * Source: Wave-02 discovery — 'tests-cli' targets a different WordPress instance.
 *
 * PITFALL (session expiry during MUPG tests): The sudo session must remain active for
 * both install and uninstall. Default session is 15 minutes — tests complete well within
 * that window. No WP-CLI session_duration override needed.
 *
 * WP-CLI state management commands:
 *   Check:   npx wp-env run cli wp eval "echo file_exists(WPMU_PLUGIN_DIR.'/wp-sudo-gate.php') ? 'installed' : 'not-installed';"
 *   Remove:  npx wp-env run cli bash -c "rm -f /var/www/html/wp-content/mu-plugins/wp-sudo-gate.php"
 */
import { test, expect, activateSudoSession } from '../fixtures/test';
import { exec } from 'child_process';
import { promisify } from 'util';

const execAsync = promisify( exec );

/**
 * Run a WP-CLI command inside the wp-env cli container (targets port 8889 dev site).
 * Used for MU-plugin state setup/teardown.
 *
 * Source: Wave-02 key decision — 'cli' container for browser test site (port 8889) (verified)
 */
async function wpCli( command: string ): Promise<string> {
    const { stdout } = await execAsync(
        `npx wp-env run cli ${ command }`,
        { timeout: 30_000 }
    );
    return stdout.trim();
}

/**
 * Remove the MU-plugin file from wp-env to guarantee "not installed" state.
 * Source: class-admin.php — MU-plugin path: WPMU_PLUGIN_DIR . '/wp-sudo-gate.php' (verified)
 * Source: class-admin.php render_mu_plugin_status() — checks defined('WP_SUDO_MU_LOADED') (verified)
 */
async function removeMuPlugin(): Promise<void> {
    await wpCli(
        'bash -c "rm -f /var/www/html/wp-content/mu-plugins/wp-sudo-gate.php"'
    );
}

/**
 * Check current MU-plugin installation state.
 * Returns 'installed' or 'not-installed'.
 *
 * Note: class-admin.php uses defined('WP_SUDO_MU_LOADED') for the page render check,
 * but the constant is only defined when the MU-plugin file is loaded at startup.
 * For WP-CLI state verification, we check file existence directly.
 * Source: class-admin.php render_mu_plugin_status() — file_exists() for is_mu_plugin_installed() (verified)
 */
async function getMuPluginState(): Promise<string> {
    const result = await wpCli(
        `wp eval "echo file_exists(WPMU_PLUGIN_DIR.'/wp-sudo-gate.php') ? 'installed' : 'not-installed';"`
    );
    // wp-env run prepends a status line; extract just the last non-empty word
    const match = result.match( /(installed|not-installed)/ );
    return match ? match[ 1 ] : result;
}

test.describe( 'MU-plugin AJAX install/uninstall', () => {
    /**
     * MUPG-01 + MUPG-02 + MUPG-03: Install then uninstall the MU-plugin.
     *
     * Run in a serial describe so install always runs before uninstall.
     * wp-env uses workers:1 so serial order is guaranteed, but we make it explicit.
     *
     * beforeAll: ensure MU-plugin is NOT installed (clean state for install test).
     * afterAll: ensure MU-plugin is NOT installed (leave wp-env clean for next run).
     */
    test.describe( 'Install and uninstall flow', () => {
        test.beforeAll( async () => {
            // Guarantee not-installed state before install test.
            await removeMuPlugin();
            const state = await getMuPluginState();
            if ( state !== 'not-installed' ) {
                throw new Error(
                    `MU-plugin state setup failed: expected not-installed, got ${ state }`
                );
            }
        } );

        test.afterAll( async () => {
            // Clean up: remove MU-plugin so wp-env is left in a clean state.
            await removeMuPlugin();
        } );

        /**
         * MUPG-01: Install button triggers AJAX and shows Installed status after reload.
         *
         * Flow:
         * 1. Activate sudo session (required for mu_install AJAX handler)
         * 2. Navigate to Settings page
         * 3. Verify install button visible and status shows "Not installed"
         * 4. Click install button
         * 5. Verify spinner appears (AJAX in progress)
         * 6. Wait for page reload (JS: setTimeout(1000, window.location.reload))
         * 7. Verify status shows "Installed" after reload
         *
         * Source: class-admin.php handle_mu_install() — Sudo_Session::is_active() check (verified)
         * Source: admin/js/wp-sudo-admin.js — spinner, reload after 1000ms (verified)
         */
        test( 'MUPG-01: install button triggers AJAX and shows Installed status after reload', async ( {
            page,
        } ) => {
            // Activate sudo session — required for MU-plugin AJAX handlers.
            // Source: class-admin.php wp_sudo_mu_install handler — Sudo_Session::is_active() check (verified)
            await activateSudoSession( page );

            await page.goto(
                '/wp-admin/options-general.php?page=wp-sudo-settings'
            );

            // Verify install button is present (MU-plugin not installed + dir writable in wp-env).
            // Source: class-admin.php render_mu_plugin_status() — install btn visible when not installed AND $writable (verified)
            const installBtn = page.locator( '#wp-sudo-mu-install' );
            await expect(
                installBtn,
                'Install button must be visible when MU-plugin is not installed'
            ).toBeVisible();

            // Verify current status shows "Not installed".
            // Source: class-admin.php render_mu_plugin_status() — "Not installed" when WP_SUDO_MU_LOADED not defined (verified)
            await expect(
                page.locator( '#wp-sudo-mu-status' ),
                'Status must say "Not installed" before install'
            ).toContainText( 'Not installed' );

            // Click install. JS: shows spinner, sends AJAX fetch, then reloads after 1000ms.
            // Source: admin/js/wp-sudo-admin.js — muPluginAction() — spinner.classList.add('is-active') (verified)
            await installBtn.click();

            // Spinner should appear briefly (is-active class added).
            // Source: admin/js/wp-sudo-admin.js — spinner.classList.add('is-active') on click (verified)
            // MUPG-01 requirement: "triggers AJAX and shows spinner"
            await expect(
                page.locator( '#wp-sudo-mu-spinner' ),
                'Spinner must appear after install button click'
            ).toBeVisible( { timeout: 2_000 } );

            // Wait for page reload triggered by JS setTimeout(1000, window.location.reload).
            // Source: admin/js/wp-sudo-admin.js — setTimeout(fn, 1000) then reload (verified)
            await page.waitForURL( /wp-sudo-settings/, { timeout: 15_000 } );

            // After reload, status must show "Installed".
            // Source: class-admin.php render_mu_plugin_status() — "Installed" when WP_SUDO_MU_LOADED defined (verified)
            await expect(
                page.locator( '#wp-sudo-mu-status' ),
                'Status must say "Installed" after install completes'
            ).toContainText( 'Installed' );

            // Uninstall button should now be visible; install button should be gone.
            // Source: class-admin.php render_mu_plugin_status() — uninstall btn when installed (verified)
            await expect(
                page.locator( '#wp-sudo-mu-uninstall' ),
                'Uninstall button must appear after install'
            ).toBeVisible();
            await expect(
                page.locator( '#wp-sudo-mu-install' ),
                'Install button must not be visible after install'
            ).toBeHidden();
        } );

        /**
         * MUPG-02: Success indication visible after install completes.
         *
         * After the AJAX install succeeds and the page reloads, the status section
         * must show "Installed" with the dashicons-yes-alt success icon.
         *
         * Source: class-admin.php render_mu_plugin_status() — "Installed" + dashicons-yes-alt (verified)
         *
         * Note: This test runs after MUPG-01 (MU-plugin is now installed).
         */
        test( 'MUPG-02: success indication visible after install completes', async ( {
            page,
        } ) => {
            // MU-plugin was installed by MUPG-01. Verify installed state.
            const state = await getMuPluginState();
            if ( state !== 'installed' ) {
                test.skip(
                    true,
                    `MU-plugin is not installed (state: ${ state }) — MUPG-02 requires MUPG-01 to have run first`
                );
                return;
            }

            // Activate a fresh sudo session.
            await activateSudoSession( page );

            await page.goto(
                '/wp-admin/options-general.php?page=wp-sudo-settings'
            );

            // Verify installed status text.
            // Source: class-admin.php render_mu_plugin_status() — "Installed" when installed (verified)
            await expect(
                page.locator( '#wp-sudo-mu-status' ),
                'Status must show "Installed" with success indication'
            ).toContainText( 'Installed' );

            // Verify the success icon is visible.
            // Source: class-admin.php render_mu_plugin_status() — dashicons-yes-alt icon for installed state (verified)
            await expect(
                page.locator( '#wp-sudo-mu-status .dashicons-yes-alt' ),
                'Success icon (dashicons-yes-alt) must be visible in MU-plugin status'
            ).toBeVisible();
        } );

        /**
         * MUPG-03: Uninstall button removes MU-plugin and shows Not installed after reload.
         *
         * Flow:
         * 1. Activate sudo session
         * 2. Navigate to Settings page
         * 3. Verify uninstall button visible
         * 4. Click uninstall button
         * 5. Wait for page reload
         * 6. Verify status shows "Not installed"
         * 7. Verify install button reappears
         *
         * Source: class-admin.php handle_mu_uninstall() — unlinks wp-sudo-gate.php (verified)
         * Source: admin/js/wp-sudo-admin.js — same muPluginAction() flow as install (verified)
         */
        test( 'MUPG-03: uninstall button removes MU-plugin and shows Not installed after reload', async ( {
            page,
        } ) => {
            // This test runs after MUPG-01 + MUPG-02 (MU-plugin is now installed).
            // Verify installed state before proceeding.
            const state = await getMuPluginState();
            if ( state !== 'installed' ) {
                test.skip(
                    true,
                    `MU-plugin is not installed (state: ${ state }) — MUPG-03 requires MUPG-01 to have run first`
                );
                return;
            }

            // Activate a fresh sudo session.
            await activateSudoSession( page );

            await page.goto(
                '/wp-admin/options-general.php?page=wp-sudo-settings'
            );

            // Verify uninstall button is present.
            // Source: class-admin.php render_mu_plugin_status() — uninstall btn when installed (verified)
            const uninstallBtn = page.locator( '#wp-sudo-mu-uninstall' );
            await expect(
                uninstallBtn,
                'Uninstall button must be visible when MU-plugin is installed'
            ).toBeVisible();

            // Click uninstall. Same JS flow: AJAX fetch → reload after 1000ms.
            // Source: admin/js/wp-sudo-admin.js — uninstall click handler via muPluginAction() (verified)
            await uninstallBtn.click();

            // Wait for page reload.
            await page.waitForURL( /wp-sudo-settings/, { timeout: 15_000 } );

            // After reload, status must show "Not installed".
            // Source: class-admin.php render_mu_plugin_status() — "Not installed" when WP_SUDO_MU_LOADED not defined (verified)
            await expect(
                page.locator( '#wp-sudo-mu-status' ),
                'Status must say "Not installed" after uninstall'
            ).toContainText( 'Not installed' );

            await expect(
                page.locator( '#wp-sudo-mu-install' ),
                'Install button must reappear after uninstall'
            ).toBeVisible();
        } );
    } );

    /**
     * Bonus: MU-plugin install fails with 403 when no sudo session is active.
     *
     * Not a named Phase 7 requirement (MUPG-01-03 are covered above), but verifies
     * the server-side session check works correctly from the browser's perspective.
     *
     * Navigate to the settings page WITHOUT activating a session, then attempt
     * to click install. The AJAX handler checks Sudo_Session::is_active() and
     * returns HTTP 403 with code 'sudo_required'.
     *
     * Source: class-admin.php handle_mu_install() — wp_send_json_error(['code'=>'sudo_required'], 403) (verified)
     * Source: admin/js/wp-sudo-admin.js — shows error in #wp-sudo-mu-message on !result.success (verified)
     *
     * PITFALL: The install button may not render at all if the MU-plugin is installed.
     * Ensure not-installed state via WP-CLI first.
     *
     * PITFALL: The JS sends a FormData body with action=wp_sudo_mu_install.
     * We intercept by matching the URL (admin-ajax.php) and verifying the HTTP status.
     * Source: admin/js/wp-sudo-admin.js — body.append('action', action) (verified)
     */
    test( 'Bonus: install AJAX returns error when no sudo session is active', async ( {
        page,
        context,
    } ) => {
        // Ensure MU-plugin is not installed (install button must be visible).
        await removeMuPlugin();

        // Ensure no active sudo session for this test.
        // Source: class-admin.php handle_mu_install() — Sudo_Session::is_active() returns false (verified)
        const cookies = await context.cookies();
        const authCookies = cookies.filter(
            ( c ) => ! c.name.startsWith( 'wp_sudo' )
        );
        await context.clearCookies();
        await context.addCookies( authCookies );

        await page.goto(
            '/wp-admin/options-general.php?page=wp-sudo-settings'
        );

        const installBtn = page.locator( '#wp-sudo-mu-install' );
        await expect( installBtn ).toBeVisible();

        // Intercept the AJAX response to verify the 403 error code.
        // Source: class-admin.php — wp_send_json_error(['code'=>'sudo_required'], 403) (verified)
        const ajaxResponsePromise = page.waitForResponse(
            ( response ) =>
                response.url().includes( 'admin-ajax.php' ) &&
                response.request().method() === 'POST',
            { timeout: 10_000 }
        );

        await installBtn.click();

        const ajaxResponse = await ajaxResponsePromise;
        expect(
            ajaxResponse.status(),
            'AJAX install endpoint must return 403 when no sudo session is active'
        ).toBe( 403 );

        const responseBody = await ajaxResponse.json();
        // WordPress wp_send_json_error format: {"success":false,"data":{"code":"...","message":"..."}}
        // Source: class-admin.php handle_mu_install() — wp_send_json_error(['code'=>'sudo_required'], 403) (verified)
        expect(
            responseBody?.data?.code,
            'Error code must be sudo_required'
        ).toBe( 'sudo_required' );

        // The JS error handler also shows a message in #wp-sudo-mu-message.
        // Source: admin/js/wp-sudo-admin.js — messageEl.textContent = data.message on !result.success (verified)
        await expect(
            page.locator( '#wp-sudo-mu-message' ),
            'Error message must appear in #wp-sudo-mu-message after failed install attempt'
        ).not.toBeEmpty( { timeout: 5_000 } );
    } );
} );
