# WP Sudo — UI/UX Testing Prompts

Structured checklists for evaluating the three UI surfaces of WP Sudo:

1. **Challenge Page**: Interstitial reauthentication (password step, optional 2FA step, lockout countdown, request replay, Escape key navigation)
2. **Settings Page**: Settings › Sudo (tabbed: Settings, Gated Actions, Rule Tester; session duration, policy dropdown presets, 5 entry point policy dropdowns, MU-plugin status section, gated actions table, 12 help tabs)
3. **Admin Bar Timer**: Live M:SS countdown during active sessions, turns red at 60s, click to deactivate, keyboard shortcut Cmd/Ctrl+Shift+S

Each section uses `- [ ]` checkboxes so the document works as a runnable checklist.

---

## 1. Heuristic Evaluation (Nielsen's 10 Usability Heuristics)

### H1 — Visibility of System Status

- [ ] **Challenge page:** Loading spinner and "Authenticating…" screen-reader text appear immediately on form submission and disappear when the server responds.
- [ ] **Challenge page:** Lockout countdown updates visually every second and displays the remaining time prominently in the error notice.
- [ ] **Challenge page:** 2FA timer shows remaining seconds for the authentication window and turns into a warning state at 60 s.
- [ ] **Challenge page:** "Replaying your action..." overlay and `wp.a11y.speak()` announcement appear between authentication success and the replayed request.
- [ ] **Settings page:** "Settings saved." success notice appears after saving (single-site via `options.php`, multisite via `edit.php` redirect with `?updated=true`).
- [ ] **Settings page:** MU-plugin install/uninstall shows spinner during AJAX, then updates the status text and displays a result message.
- [ ] **Admin bar timer:** Countdown ticks every second with M:SS format so the user always knows the session state.
- [ ] **Admin bar timer:** Node turns red (`wp-sudo-expiring` class) at 60 s remaining.
- [ ] **Admin bar timer:** Page reloads automatically when the timer reaches 0, removing the countdown node.

### H2 -- Match Between System and the Real World

- [ ] **Challenge page:** "Confirm Your Identity" heading and "sudo" metaphor (with the three-point "lecture") align with the Unix sudo concept users may recognize.
- [ ] **Challenge page:** Action label (e.g., "Activate plugin") in the description tells the user exactly what they are confirming.
- [ ] **Settings page:** "Session Duration" is labeled in minutes with an explicit 1-15 range.
- [ ] **Settings page:** Policy dropdown labels use plain language: "Disabled", "Limited (default)", "Unrestricted".
- [ ] **Settings page:** Gated actions table groups rules by familiar WordPress categories (Plugins, Themes, Users, Settings, etc.).
- [ ] **Admin bar timer:** "Sudo: M:SS" label is concise and recognizable within the admin bar idiom.

### H3 — User Control and Freedom

- [ ] **Challenge page:** Cancel button returns to the originating page (validates `return_url` parameter, falls back to Dashboard).
- [ ] **Challenge page:** Escape key announces "Leaving challenge page." and navigates to the cancel URL after a 600 ms delay for screen reader announcement.
- [ ] **Challenge page:** "Start over" button appears when the 2FA authentication window expires, allowing the user to reload and restart the flow.
- [ ] **Challenge page:** Password field clears after a failed attempt so the user can retype without manually selecting all.
- [ ] **Settings page:** All settings have visible defaults; reverting to defaults is possible by setting values back to documented defaults.
- [ ] **Admin bar timer:** Clicking the timer deactivates the session immediately (nonce-protected), keeping the user on the current page.

### H4 – Consistency and Standards

- [ ] **Challenge page:** Uses standard WordPress admin CSS classes (`wrap`, `button`, `button-primary`, `notice`, `notice-error`, `notice-warning`, `spinner`).
- [ ] **Challenge page:** Error notices use `role="alert"` with `aria-atomic="true"`, matching WordPress core notice patterns.
- [ ] **Settings page:** Uses the WordPress Settings API (`add_settings_section`, `add_settings_field`, `register_setting`) so the page structure matches other Settings pages.
- [ ] **Settings page:** Help tabs follow the standard contextual help pattern via `get_current_screen()->add_help_tab()`.
- [ ] **Settings page:** "Settings" action link on the Plugins list page matches the standard WordPress convention.
- [ ] **Admin bar timer:** Uses the `admin_bar_menu` hook and standard `$wp_admin_bar->add_node()` API with `ab-icon` and `ab-label` classes.

### H5 – Error Prevention

- [ ] **Challenge page:** Password field has `required` attribute, preventing empty submissions.
- [ ] **Challenge page:** Submit button is disabled during AJAX requests, preventing double-submission.
- [ ] **Challenge page:** When the user is locked out on page load, both the password field and submit button are disabled.
- [ ] **Settings page:** Session duration input has `min="1"` and `max="15"` HTML attributes. Server-side sanitization clamps out-of-range values to 15.
- [ ] **Settings page:** Policy dropdowns only allow three predefined values; unknown values fall back to "Limited" on save.
- [ ] **Admin bar timer:** Deactivation URL includes a WordPress nonce; invalid nonces produce a `wp_die()` error rather than silently deactivating.

### H6 – Recognition Rather Than Recall

- [ ] **Challenge page:** The gated action label is displayed in the challenge description so the user knows what they are confirming without needing to remember.
- [ ] **Challenge page:** 2FA step heading "Two-Factor Verification" and the step-transition announcement make it clear which phase the user is in.
- [ ] **Settings page:** Each policy dropdown has an inline description explaining what the three values mean for that specific entry point.
- [ ] **Settings page:** 12 help tabs provide contextual reference (How Sudo Works, Session &amp; Policies, App Passwords, MU-Plugin, Security Features, Security Model, Environment, Recommended Plugins, Extending, Audit Hooks, Policy Presets, Rule Tester) without leaving the page.
- [ ] **Settings page:** Gated actions table shows all registered rules and their surfaces so the administrator does not need to consult code or docs.

### H7 – Flexibility and Efficiency of Use

- [ ] **Challenge page:** `autofocus` on the password field when not locked out allows immediate typing.
- [ ] **Challenge page:** Escape key shortcut provides a keyboard-only way to leave the challenge.
- [ ] **Settings page:** Plugin action link ("Settings") on the Plugins list page provides a one-click shortcut to the settings page.
- [ ] **Admin bar timer:** Keyboard shortcut Cmd/Ctrl+Shift+S activates a sudo session proactively (when no session exists) or flashes the timer (when active) so power users do not need to wait for a gated action.
- [ ] **Admin bar timer:** Clicking the timer is a single-action deactivation – no confirmation dialog.

### H8 – Aesthetic and Minimalist Design

- [ ] **Challenge page:** Card layout focuses on the single task (password entry) with minimal surrounding UI.
- [ ] **Challenge page:** 2FA step is hidden by default and only revealed when needed.
- [ ] **Challenge page:** Loading overlay covers the card to prevent interaction with stale UI during AJAX.
- [ ] **Settings page:** Settings form contains 7 fields when WPGraphQL is active (1 numeric + 1 preset chooser + 5 dropdowns), otherwise 6 fields (1 numeric + 1 preset chooser + 4 dropdowns). Additional context is in help tabs, not inline.
- [ ] **Settings page:** Policy Presets section shows the current configuration, three named presets, and an explicit apply-confirmation checkbox before settings are overwritten.
- [ ] **Settings page:** Gated actions table is read-only reference, not a configuration surface, reducing cognitive load.
- [ ] **Admin bar timer:** Single compact node with icon, label, and countdown; no extraneous elements.

### H9 – Help Users Recognize, Diagnose, and Recover from Errors

- [ ] **Challenge page:** "Incorrect password" error is specific (not generic "authentication failed").
- [ ] **Challenge page:** Lockout error includes a live countdown ("Too many failed attempts. Try again in M:SS.") so the user knows exactly when they can retry.
- [ ] **Challenge page:** "Your challenge session has expired" message appears when the stash is consumed or timed out, with a clear path to retry.
- [ ] **Challenge page:** "Your authentication session has expired. Please start over." appears if the 2FA pending state is missing, with the "Start over" button.
- [ ] **Challenge page:** Non-JSON server responses log to the browser console and show "The server returned an unexpected response" with a console hint.
- [ ] **Challenge page:** Network errors show "A network error occurred. Please try again."
- [ ] **Settings page:** MU-plugin install/uninstall error messages specify the cause (e.g., "Check file permissions.").
- [ ] **Admin bar timer:** On expiry, announces "Sudo session expired." and auto-reloads to reset state.

### H10 – Help and Documentation

- [ ] **Settings page:** 12 help tabs cover all aspects of the plugin: How Sudo Works, Session &amp; Policies, App Passwords, MU-Plugin, Security Features, Security Model, Environment, Recommended Plugins, Extending, Audit Hooks, Policy Presets, Rule Tester.
- [ ] **Settings page:** Help sidebar links to external resources: Wikipedia sudo article, Two Factor plugin, WebAuthn Provider, WP Activity Log, Stream, Roles & Capabilities developer documentation.
- [ ] **Settings page:** Inline descriptions on the session duration field and each policy dropdown explain defaults and implications.
- [ ] **Settings page:** MU-plugin status section explains what the shim does and why it is optional.
- [ ] **Challenge page:** The three-point "lecture" provides brief contextual guidance before the password prompt.

---

## 2. Navigation Flow Tests

### 2a. Admin UI form submission (POST replay)

- [ ] Navigate to Plugins > Installed Plugins. Click "Activate" on an inactive plugin.
- [ ] Verify the Gate intercepts and redirects to the challenge page with a `stash_key` parameter.
- [ ] Verify the challenge page shows the correct action label (e.g., "Activate Plugin: Hello Dolly").
- [ ] Enter the correct password and submit. If 2FA is configured, complete the second step.
- [ ] Verify the "Replaying your action..." overlay appears.
- [ ] Verify the browser lands on the Plugins page with the plugin now active and a standard WordPress "Plugin activated" notice.
- [ ] Verify the stash transient is consumed (a second attempt to load the same `stash_key` produces an "Invalid or expired challenge" error).

### 2b. AJAX request (plugin activate from plugin-install.php)

- [ ] Navigate to Plugins > Add New and search for a plugin. Click "Install Now", then "Activate".
- [ ] Verify the AJAX activation request returns an error response (the gate blocks AJAX without a sudo session).
- [ ] Verify an admin notice appears explaining the action was gated and linking to the challenge page.
- [ ] Click the challenge link. Complete the password (and 2FA if applicable) challenge.
- [ ] After authentication, verify the page redirects back to the Plugins page and the user can retry the activation successfully.

### 2c. REST cookie-auth request

- [ ] Trigger a REST API request that is gated (e.g., a Gutenberg editor action that uses cookie authentication).
- [ ] Verify the REST response returns an appropriate error (the gate blocks the REST request without a sudo session).
- [ ] Press Cmd/Ctrl+Shift+S to open the challenge page in session-only mode.
- [ ] Complete the challenge. Verify the redirect returns to the originating admin page.
- [ ] Retry the REST request. Verify it succeeds now that a sudo session is active.

### 2d. Cancel button

- [ ] From the Plugins page, trigger a gated action to land on the challenge page.
- [ ] Click the "Cancel" button.
- [ ] Verify the browser returns to the Plugins page (the `return_url` parameter determines this).
- [ ] Verify no sudo session was activated and no action was performed.

### 2e. Escape key

- [ ] From any admin page, trigger a gated action to land on the challenge page.
- [ ] Press the Escape key.
- [ ] Verify the screen reader announcement "Leaving challenge page." fires via `wp.a11y.speak()`.
- [ ] Verify the browser navigates to the cancel URL after approximately 600 ms.
- [ ] Verify no sudo session was activated.

### 2f. Session-only mode (no stash)

- [ ] From any admin page (no gated action pending), press Cmd/Ctrl+Shift+S.
- [ ] Verify the challenge page loads in session-only mode (no `stash_key` parameter; action label reads "Activate sudo session").
- [ ] Enter the correct password (and 2FA if applicable).
- [ ] Verify the page redirects back to the `return_url` (the page where the shortcut was pressed) or the Dashboard.
- [ ] Verify the admin bar timer is now visible and counting down.
- [ ] Trigger a gated action. Verify it proceeds without a second challenge (the session is already active).

### 2g. Lockout during challenge

- [ ] On the challenge page, enter an incorrect password 5 times.
- [ ] Verify the lockout countdown appears with "Too many failed attempts. Try again in M:SS."
- [ ] Verify the submit button is disabled during the lockout period.
- [ ] Verify the password input is cleared after each failed attempt.
- [ ] Wait for the lockout to expire. Verify the form re-enables, an announcement fires ("Lockout expired. You may try again."), and focus returns to the password field.

### 2h. Admin bar deactivation

- [ ] Activate a sudo session (via the challenge page or keyboard shortcut).
- [ ] Click the admin bar timer node ("Sudo: M:SS").
- [ ] Verify the session is deactivated and the timer disappears.
- [ ] Verify the browser stays on the same admin page (no redirect to the Dashboard).
- [ ] Trigger a gated action. Verify the gate intercepts again (no active session).

### 2i. Stale stash / expired challenge

- [ ] Trigger a gated action to create a stash and land on the challenge page.
- [ ] Wait for the stash transient to expire (default WordPress transient expiry, or manually delete it).
- [ ] Submit the password on the challenge page.
- [ ] Verify the response is "Your challenge session has expired. Please try again." (HTTP 403).

---

## 3. Responsive Tests

For each viewport size, verify the listed checkpoints on each UI surface.

### 3a. Desktop – 1920x1080

- [ ] **Challenge page:** Card is centered and does not stretch to full width. Password field and buttons are comfortably sized.
- [ ] **Settings page:** Form table layout is standard two-column (label left, field right). Gated actions table is fully visible without horizontal scroll.
- [ ] **Admin bar timer:** Timer node is visible in the admin bar. Text is not truncated.

### 3b. Desktop – 1366x768

- [ ] **Challenge page:** Card is centered. No horizontal scrollbar. Error messages do not overflow the card.
- [ ] **Settings page:** Help tabs are accessible via the "Help" button in the standard location. Gated actions table fits without horizontal overflow.
- [ ] **Admin bar timer:** Timer text and icon are visible; admin bar does not wrap.

### 3c. Tablet – 768x1024 (portrait)

- [ ] **Challenge page:** Card adapts to narrower width. Password field fills available space. Both "Confirm & Continue" and "Cancel" buttons are tappable (at least 44x44 px touch target).
- [ ] **Settings page:** Form fields stack or reduce to a single-column layout. Policy dropdowns are usable with touch.
- [ ] **Settings page:** Gated actions table is readable. If it overflows horizontally, confirm it scrolls within its container.
- [ ] **Admin bar timer:** Timer is visible. On WordPress mobile admin bar, verify the node is accessible in the collapsed menu if the bar collapses.

### 3d. Tablet – 1024x768 (landscape)

- [ ] **Challenge page:** Layout is similar to narrow desktop. Card is centered with adequate margins.
- [ ] **Settings page:** Two-column form layout is intact. Help tabs are accessible.
- [ ] **Admin bar timer:** Timer node displays normally.

### 3e. Mobile – 375x667

- [ ] **Challenge page:** Card fills most of the viewport width with adequate padding. No horizontal scroll. Password field is 100% width within the card.
- [ ] **Challenge page:** Both buttons are full-width or stacked vertically with sufficient spacing. Touch targets meet 44x44 px minimum.
- [ ] **Challenge page:** Error messages wrap correctly within the card width.
- [ ] **Challenge page:** 2FA step fields and timer are visible without horizontal overflow.
- [ ] **Settings page:** Form fields are single-column. Dropdowns are usable. "Save Settings" button is accessible.
- [ ] **Settings page:** MU-plugin status section buttons and spinner are visible and tappable.
- [ ] **Settings page:** Gated actions table scrolls horizontally if needed (confirm no content is hidden without any scroll affordance).
- [ ] **Admin bar timer:** Timer is accessible in the WordPress mobile admin bar menu.

### 3f. Mobile – 390x844

- [ ] **Challenge page:** Same checks as 375x667. Verify that the taller viewport does not cause the card to appear too far down the page.
- [ ] **Settings page:** Verify scrolling behavior is smooth and all sections are reachable.
- [ ] **Admin bar timer:** Timer is accessible and text is legible.

---

## 4. Accessibility Spot Checks

These are quick checks beyond a full WCAG audit. For the resolved accessibility items, see [ROADMAP.md Appendix A](ROADMAP.md#appendix-a-accessibility-roadmap).

### 4a. Screen Reader Announcement Flow During Challenge

- [ ] On page load, verify the page title "Confirm Your Identity – Sudo" is announced.
- [ ] Submit an incorrect password. Verify the error notice (`role="alert"`, `aria-atomic="true"`) is announced with the specific error message.
- [ ] Submit the correct password when 2FA is configured. Verify "Password verified. Two-factor authentication required." is announced via `wp.a11y.speak()`.
- [ ] On the 2FA step, verify focus moves to the first 2FA input field.
- [ ] Submit an invalid 2FA code. Verify the 2FA error notice (`role="alert"`) is announced.
- [ ] Submit a valid 2FA code. Verify "Replaying your action..." is announced before the redirect/replay.
- [ ] During lockout, verify announcements occur at 30-second intervals and at 10 seconds remaining, not every second.
- [ ] When the lockout expires, verify "Lockout expired. You may try again." is announced and focus moves to the password input.
- [ ] Press Escape. Verify "Leaving challenge page." is announced before navigation.

### 4b. Admin Bar Timer Screen Reader Behavior

- [ ] Verify the countdown label has `role="timer"` and `aria-live="off"` (not announced every second).
- [ ] Verify the separate milestone live region (`role="status"`, `aria-live="assertive"`) announces at 60 s, 30 s, and 10 s.
- [ ] Verify "Sudo session expired." is announced when the timer reaches 0.
- [ ] Verify the admin bar node has a `title` attribute ("Click to deactivate sudo mode") for tooltip and AT.

### 4c. Keyboard-Only Completion of Full Challenge Flow

- [ ] Tab to the challenge page password field (should be autofocused; verify focus indicator is visible via `:focus-visible`).
- [ ] Type a password and press Enter to submit the form.
- [ ] If 2FA is required, verify focus moves to the first 2FA input. Tab through any additional fields and submit with Enter.
- [ ] Verify the replay/redirect happens without needing mouse interaction.
- [ ] On the challenge page, verify Tab order: password field, "Confirm & Continue" button, "Cancel" link. No focus traps.
- [ ] On the 2FA step, verify Tab order: 2FA input(s), "Verify & Continue" button, "Cancel" link. No focus traps.
- [ ] Verify Escape key works as documented (announces and navigates).
- [ ] On the settings page, verify Cmd/Ctrl+Shift+S opens the challenge page from any focusable element.

### 4d. Color Contrast in Warning and Expiring States

- [ ] **Admin bar timer (normal state):** Verify the green background (`#00a32a` or similar) against white text meets WCAG AA 4.5:1 contrast ratio.
- [ ] **Admin bar timer (expiring state):** Verify the red `wp-sudo-expiring` background against white text meets WCAG AA 4.5:1 contrast ratio.
- [ ] **Challenge page (lockout notice):** Verify the `notice-warning` yellow background against text meets WCAG AA 4.5:1.
- [ ] **Challenge page (error notice):** Verify the `notice-error` red border/background against text meets WCAG AA 4.5:1.
- [ ] **Challenge page (2FA timer warning):** Verify the `wp-sudo-expiring` class on the timer span provides adequate contrast.
- [ ] **Settings page (disabled link contrast):** Verify disabled links use `#787c82` (4.6:1 ratio, per the accessibility roadmap fix).
- [ ] **Gate UI (disabled buttons):** Verify `wp-sudo-disabled` buttons at `opacity: 0.5` still have visible text (not required to meet interactive contrast since they are non-interactive, but text should be distinguishable from the background).

### 4e. Focus Management After Step Transitions

- [ ] After the password step succeeds and the 2FA step appears, verify focus moves to the first 2FA input (not left on the now-hidden password step).
- [ ] After a failed password attempt, verify focus returns to the password input.
- [ ] After the lockout expires, verify focus returns to the password input.
- [ ] After clicking "Start over" on an expired 2FA timer, verify the page reloads and focus lands on the password field (via `autofocus`).
- [ ] On the settings page, after clicking "Install MU-Plugin" or "Remove MU-Plugin", verify focus moves to the result message area (`#wp-sudo-mu-message`).
- [ ] Verify no focus is trapped inside the loading overlay (it has no focusable elements and is only shown during brief AJAX calls).

### 4f. Reduced Motion Preferences

- [ ] Enable `prefers-reduced-motion: reduce` in the OS or browser.
- [ ] On the challenge page, verify the loading spinner does not animate (CSS `@media (prefers-reduced-motion: reduce)` rule in `wp-sudo-challenge.css`).
- [ ] On the admin bar, press Cmd/Ctrl+Shift+S during an active session. Verify the green flash animation is skipped (the JS checks `matchMedia`).
- [ ] Verify the admin bar CSS (`wp-sudo-admin-bar.css`) disables transitions under `prefers-reduced-motion: reduce`.
