# WP Sudo Manual Testing Guide

Manual verification tests for WP Sudo v2.12.0+. These complement the
automated PHPUnit suite (`composer test`) and should be run against a
real WordPress environment before each release.

## Environments

Set up at least two local dev sites — one single-site and one multisite
(subdirectory). Use any local WordPress development tool (Studio, Local,
wp-env, DDEV, etc.).

| Name | Type | Example URL |
|------|------|-------------|
| Single-site | Single-site install | `https://your-single-site.local/` |
| Multisite | Multisite (subdirectory) | `https://your-multisite.local/` |

> **Tip:** Use the single-site for admin UI and challenge-page testing.
> Use the multisite for network admin rules (section 13), WP-CLI, and
> app-password `curl` tests.

---

## Prerequisites

- [ ] Plugin is active on the test site.
- [ ] A second test plugin is installed (e.g. Hello Dolly) for
      activate/deactivate/delete tests.
- [ ] An Application Password exists for `curl` tests (Users > Profile >
      Application Passwords). Note the username and password.
- [ ] A second user account exists for role-change and delete-user tests.
- [ ] Browser DevTools are open to the **Console** and **Network** tabs
      for observing errors and REST responses.
- [ ] The user conducting the tests has a role of Administrator or Super
      Administrator (on Multisite).

---

## 1. Sudo Session Lifecycle

### 1.1 Activate via Challenge Page

1. Ensure no sudo session is active (admin bar has no timer).
2. Navigate to **Settings > Sudo**.
3. Click **Save Changes**.
4. **Expected:** Redirected to the challenge page. The page shows
   "Confirm Your Identity" with the action label "Change WP Sudo
   settings."
5. Enter your password and click **Confirm & Continue**.
6. **Expected:** Redirected back to Settings > Sudo. The form is
   submitted. The admin bar shows a countdown timer.

### 1.2 Activate via Keyboard Shortcut

1. Ensure no sudo session is active.
2. Press **Ctrl+Shift+S** (Windows/Linux) or **Cmd+Shift+S** (Mac).
3. **Expected:** Navigated to the challenge page in session-only mode
   (no stash key, action label says "Activate sudo session").
4. Enter your password.
5. **Expected:** Session activates, redirected to the referring admin
   page (or the dashboard if there is no referrer).

### 1.3 Shortcut Flash During Active Session

1. With a sudo session active, press **Ctrl+Shift+S** / **Cmd+Shift+S**.
2. **Expected:** The admin bar timer flashes briefly. No navigation
   occurs. (Respects `prefers-reduced-motion`.)

### 1.4 Session Expiry

1. Set session duration to 1 minute in Settings > Sudo.
2. Activate sudo.
3. Wait for the timer to count down to zero.
4. **Expected:** Timer disappears from admin bar. Page auto-reloads.
   The next gated action triggers a challenge.

### 1.5 Rate Limiting (Non-Blocking)

1. Open the challenge page.
2. Enter an incorrect password 5 times.
3. **Expected:**
   - **Attempt 1–3**: Immediate "Authentication failed" error.
   - **Attempt 4**: Submit button disables for **2 seconds**. A countdown "0:02" is visible in the notice: "Please wait 0:02 before trying again."
   - **Attempt 5**: Submit button disables for **5 seconds**. A countdown "0:05" is visible in the notice.
   - **After Attempt 5**: Hard lockout triggers. The form is disabled for **5 minutes**. A visible countdown shows "Too many failed attempts. Try again in 5:00."
4. **Accessibility:**
   - Throttle countdowns (2s/5s) update visually without interrupting screen readers.
   - Lockout countdown announces to screen readers at 30-second intervals.
   - Re-enabling of the form after any wait is announced as "Lockout expired. You may try again." (or similar).

### 1.5.1 Rate Limiting (IP + User Combined Policy)

1. Create two administrator users (`admin-a`, `admin-b`) and sign out of both.
2. From the same browser/network, trigger the challenge for `admin-a`.
3. Enter a wrong password 4 times for `admin-a`.
4. Sign out, sign in as `admin-b`, and trigger the challenge from the same browser/network.
5. Enter a wrong password once.
6. **Expected:** The fifth same-source attempt triggers hard lockout (`locked_out`) even though `admin-b` has not individually reached 5 failures.
7. During that lockout window, submit the correct password for `admin-a` (same source IP).
8. **Expected:** Request remains blocked until lockout expires; correct password does not bypass active source-IP lockout.

### 1.6 Two-Factor Authentication

> Requires the [Two Factor](https://wordpress.org/plugins/two-factor/)
> plugin with at least one method configured for the test user.

1. Ensure no sudo session is active.
2. Trigger any gated action to reach the challenge page.
3. Enter your password and click **Confirm & Continue**.
4. **Expected:** A second step appears requesting your verification
   code (TOTP, email, backup code, etc.).
5. Enter the correct code.
6. **Expected:** Session activates, original action is replayed.

---

## 2. Admin UI Gating (Stash-Challenge-Replay)

These tests verify the stash-challenge-replay flow. For each test,
log in with an Administrator account and ensure **no sudo session is
active** when you start.

**How it works:** Action links on plugin/theme screens are *disabled*
(grayed out) when no sudo session is active. A persistent notice links
to the challenge page. After authentication, links become operable and
actions proceed without further challenges until the session expires.

For form-based actions (user operations, settings changes, exports),
submitting the form triggers a redirect to the challenge page. After
authentication, the original POST request is replayed automatically.

### 2.1 Activate Plugin

1. Go to **Plugins**.
2. **Expected:** Activate links on inactive plugins are grayed out
   (disabled). A persistent notice links to the challenge page.
3. Activate a sudo session via the notice link or keyboard shortcut.
4. **Expected:** Redirected back to Plugins. Activate links are now
   operable.
5. Click **Activate** on a plugin.
6. **Expected:** Plugin activates immediately with no further challenge.

### 2.2 Deactivate Plugin

1. Go to **Plugins** with no sudo session active.
2. **Expected:** Deactivate links on active plugins are grayed out.
3. Activate a sudo session.
4. Click **Deactivate** on a plugin.
5. **Expected:** Plugin deactivates immediately.

### 2.3 Delete Plugin

1. Deactivate a plugin first (with sudo active), then let the session
   expire.
2. Go to **Plugins**.
3. **Expected:** Delete links on inactive plugins are grayed out.
4. Activate a sudo session.
5. Click **Delete** on an inactive plugin.
6. **Expected:** Plugin is deleted.

### 2.4 Switch Theme

1. Go to **Appearance > Themes** with no sudo session active.
2. **Expected:** The **Activate** button on inactive themes is grayed
   out. A persistent notice is visible.
3. Activate a sudo session.
4. Click **Activate** on an inactive theme.
5. **Expected:** Theme activates.

### 2.5 Delete User

1. Go to **Users**, hover over a non-admin user, click **Delete**.
2. WordPress shows a confirmation screen with content reassignment
   options.
3. Click **Confirm Deletion**.
4. **Expected:** Redirected to the challenge page. The action label
   shows "Delete user."
5. Authenticate.
6. **Expected:** User is deleted. Redirected back to Users.

### 2.6 Change User Role (Bulk Action)

1. Go to **Users**. Select a user via checkbox, choose a new role from
   the "Change role to" dropdown, click **Change**.
2. **Expected:** Redirected to the challenge page. Action label shows
   "Change user role."
3. Authenticate.
4. **Expected:** Role change is replayed. Redirected back to Users.

### 2.7 Change User Role (Profile Page)

1. Go to **Users**, click **Edit** on another user.
2. Change the **Role** dropdown, click **Update User**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Change user role."
4. Authenticate.
5. **Expected:** Profile update is replayed.

### 2.8 Create User

1. Go to **Users > Add New**.
2. Fill in the form and click **Add New User**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Create new user."
4. Authenticate.
5. **Expected:** User is created.

### 2.9 Change a Critical Site Setting

1. Go to **Settings > General**.
2. Change a field (e.g. Administration Email Address), click **Save
   Changes**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Change critical site settings."
4. Authenticate.
5. **Expected:** Settings save is replayed.

### 2.10 Change WP Sudo Settings (Self-Protection)

1. Go to **Settings > Sudo** and change any value. Click **Save
   Changes**.
2. **Expected:** Redirected to challenge page. Action label shows
   "Change WP Sudo settings."
3. Authenticate.
4. **Expected:** Settings save is replayed.

### 2.11 Export Site Data

1. Go to **Tools > Export**.
2. Click **Download Export File**.
3. **Expected:** Redirected to challenge page. Action label shows
   "Export site data."
4. Authenticate.
5. **Expected:** Export file downloads.

### 2.12 Edit Plugin/Theme File

> Plugin and theme file editors are often (and should be) disabled.

1. Go to **Plugins > Plugin File Editor** (or **Appearance > Theme
   File Editor**).
2. Edit a file and click **Update File**.
3. **Expected:** Redirected to challenge page.
4. Authenticate.
5. **Expected:** File edit is replayed.

### 2.13 Bypass Challenges with Active Session

1. Activate a sudo session.
2. Repeat any test above.
3. **Expected:** Actions proceed immediately with no challenge.

### 2.14 Cancel Returns to Originating Page

1. Trigger any stash-challenge-replay flow.
2. On the challenge page, click **Cancel**.
3. **Expected:** Returned to the page you started from, not the
   dashboard.

### 2.15 Change Password (Profile Page)

> Tests the `user.change_password` gated action (v2.6.0+).

1. Go to **Users > Your Profile** with **no sudo session active**.
2. Scroll to the **New Password** section, click **Set New Password**.
3. Enter a new password in the field, click **Update Profile**.
4. **Expected:** Redirected to the challenge page. Action label shows
   "Change password."
5. Authenticate.
6. **Expected:** Profile is updated (password changed). Redirected back
   to the profile page.
7. Verify: changing **only bio, email address, or display name** (with
   no password field filled in) does **not** trigger a challenge.

### 2.16 Change Password (Edit User Page)

> Tests the `user.change_password` gated action on another user (v2.6.0+).

1. Go to **Users**, click **Edit** on a non-admin user.
2. Scroll to **New Password**, click **Set New Password**, enter a value.
3. Click **Update User**.
4. **Expected:** Redirected to the challenge page. Action label shows
   "Change password."
5. Authenticate.
6. **Expected:** User's password is updated.

---

## 3. AJAX Gating (Plugin and Theme Installers)

Buttons are disabled when no sudo session is active. If an AJAX request
is somehow sent, it receives a JSON error and the button resets.

### 3.1 Install Plugin via Search

1. Ensure no sudo session is active.
2. Go to **Plugins > Add New**, search for a plugin.
3. **Expected:** All **Install Now** buttons are grayed out. A
   persistent notice links to the challenge page.
4. Activate a sudo session.
5. Click **Install Now** on a plugin.
6. **Expected:** Plugin installs successfully via AJAX.

### 3.2 Delete Theme via AJAX

1. Go to **Appearance > Themes** with no sudo session active.
2. **Expected:** Delete links are grayed out.
3. Activate a sudo session.
4. Click **Delete** on an inactive theme.
5. **Expected:** Theme is deleted via AJAX.

### 3.3 Update Plugin via AJAX

1. With a plugin update available and no sudo session active, go to
   **Plugins** or **Dashboard > Updates**.
2. **Expected:** Update links/buttons are grayed out.
3. Activate a sudo session.
4. Click **Update Now** on a plugin.
5. **Expected:** Plugin updates successfully via AJAX.

---

## 4. REST API — Cookie Auth (Browser)

### 4.1 Create Application Password (Profile Page)

1. Ensure no sudo session is active.
2. Go to **Users > Profile**, scroll to Application Passwords.
3. Enter a name, click **Add New Application Password**.
4. **Expected:** Error notice: "This action (Create application
   password) requires reauthentication. Press Cmd+Shift+S to start a
   sudo session, then try again."
5. Activate a sudo session via keyboard shortcut.
6. Retry step 3.
7. **Expected:** Password is created successfully.

### 4.2 `curl` with Cookie Auth

```bash
# Replace YOUR_SITE_URL, YOUR_USERNAME, and YOUR_PASSWORD below.

# 0. Clean up stale cookies
rm -f /tmp/wp-cookies.txt

# 1. Log in and capture cookies
curl -sk -L -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -X POST "YOUR_SITE_URL/wp-login.php" \
  -d 'log=YOUR_USERNAME&pwd=YOUR_PASSWORD&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1' \
  -H "Cookie: wordpress_test_cookie=WP+Cookie+check" -o /dev/null

# 2. Get the REST nonce
NONCE=$(curl -sk -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  "YOUR_SITE_URL/wp-admin/" \
  | grep -oE 'createNonceMiddleware\( "[a-f0-9]+" \)' \
  | grep -oE '[a-f0-9]{6,}')
echo "Nonce: $NONCE"

# 3. Test gated action WITHOUT sudo (should fail)
curl -sk -c /tmp/wp-cookies.txt -b /tmp/wp-cookies.txt \
  -H "X-WP-Nonce: $NONCE" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
  -d '{"name":"test"}'
# Expected: {"code":"sudo_required", ...}
```

---

## 5. REST API — App Password Policies

Replace `YOUR_SITE_URL` with your dev site URL and `YOUR_USERNAME:YOUR_APP_PASS`
with your Application Password credentials (strip spaces from the password).

### 5.1 Non-Gated Endpoint (All Policies)

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  "YOUR_SITE_URL/wp-json/wp/v2/users/me"
```

**Expected:** 200 with user data. Non-gated endpoints are never blocked.

### 5.2 Limited (Default)

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"test-limited"}'
```

**Expected:**
```json
{"code":"sudo_blocked","message":"This operation requires sudo and cannot be performed via Application Passwords.","data":{"status":403}}
```

### 5.3 Disabled

Set REST API policy to Disabled in Settings > Sudo, then:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"test-disabled"}'
```

**Expected:**
```json
{"code":"sudo_disabled","message":"This REST API operation is disabled by WP Sudo policy.","data":{"status":403}}
```

### 5.4 Unrestricted

> See also §19.1 for audit hook verification.

Set REST API policy to Unrestricted, then:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"test-unrestricted"}'
```

**Expected:** 200 with password data.

### 5.5 Per-Application-Password Override

> New in v2.3.0.

1. Set the global REST API (App Passwords) policy to **Limited**.
2. Go to **Users > Profile**, scroll to Application Passwords.
3. Find an existing application password and set its per-password policy
   dropdown to **Unrestricted**.
4. Use that specific password in a `curl` request to a gated endpoint.

**Expected:** The request succeeds (200) because the per-password
override takes precedence over the global Limited policy.

5. Change the per-password policy to **Disabled**.
6. Retry the same `curl` request.

**Expected:** `sudo_disabled` error — the per-password override blocks
the request even though the global policy is Limited.

7. Set the per-password policy back to **(Use global policy)** or
   delete the override.
8. Retry the `curl` request.

**Expected:** `sudo_blocked` error — falls back to the global Limited
policy.

> **Cleanup:** Delete any test app passwords and restore the policy to
> Limited after testing.

---

## 6. XML-RPC Policies

### 6.1 Limited (Default)

```bash
curl -sk -X POST "YOUR_SITE_URL/xmlrpc.php" \
  -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
```

**Expected:** A valid XML response listing available methods.

### 6.2 Disabled

Set XML-RPC policy to Disabled, then repeat the request above.

**Expected:** XML-RPC fault response indicating the service is disabled.

### 6.3 Unrestricted

> See also §19.3 for audit hook verification.

Set XML-RPC policy to Unrestricted. All methods pass through without
gating.

---

## 7. WP-CLI Policies

Run WP-CLI against a dev site where WP Sudo is active.

### 7.1 Limited (Default) — Non-Gated Command

```bash
wp option get blogname
```

**Expected:** Returns the site title.

### 7.2 Limited — Gated Command

```bash
wp plugin deactivate hello-dolly
```

**Expected:** Dies with a message indicating the action is blocked by
WP Sudo.

### 7.3 Disabled

Set CLI policy to Disabled, then:

```bash
wp option get blogname
```

**Expected:** Command exits immediately with an error. All CLI
operations are killed.

### 7.4 Unrestricted

> See also §19.2 for audit hook verification.

Set CLI policy to Unrestricted, then:

```bash
wp plugin deactivate hello-dolly
```

**Expected:** Plugin deactivated successfully.

### 7.5 CLI Enforces Cron Policy

With CLI set to any value and Cron set to Disabled:

```bash
wp cron event list
```

**Expected:** Dies with a message that WP-Cron is disabled. The CLI
gate enforces the Cron policy for `wp cron` subcommands.

---

## 8. Cron Policies

> **Surface distinction:** The cron policy only applies to HTTP requests
> to `wp-cron.php` (where `DOING_CRON` is defined). Running `wp cron
> event run` via WP-CLI is detected as `surface=cli` and governed by
> the **CLI policy** instead. See §7.5 for the CLI-enforces-Cron-policy
> crossover test.

### 8.1 Limited (Default) — HTTP `wp-cron.php`

Non-gated scheduled events run normally. Gated operations triggered by
cron are silently blocked (the callback calls `exit` instead of
`wp_die()` to avoid noisy error output in cron context).

```bash
curl -sk "YOUR_SITE_URL/wp-cron.php" -w "HTTP: %{http_code}, body: %{size_download} bytes\n"
```

**Expected:** HTTP 200, body > 0 bytes (WordPress runs due events and
returns normally).

### 8.2 Disabled — HTTP `wp-cron.php`

All cron execution is killed at `init`. Covers both WP-Cron (page-load
trigger) and server-level cron hitting `wp-cron.php`.

1. Set Cron policy to **Disabled** in Settings > Sudo.
2. Run:

```bash
curl -sk "YOUR_SITE_URL/wp-cron.php" -w "HTTP: %{http_code}, body: %{size_download} bytes\n"
```

3. **Expected:** HTTP 200, **body: 0 bytes**. The 200 status is sent by
   WordPress before `init` fires; the gate calls `exit` at `init`
   priority 1, which terminates execution before any cron events run or
   any response body is written.

> **Cleanup:** Restore Cron policy to **Limited** after testing.

### 8.3 Unrestricted — HTTP `wp-cron.php`

> See also §19.4 for audit hook verification.

1. Set Cron policy to **Unrestricted** in Settings > Sudo.
2. Run:

```bash
curl -sk "YOUR_SITE_URL/wp-cron.php" -w "HTTP: %{http_code}, body: %{size_download} bytes\n"
```

3. **Expected:** HTTP 200, body > 0 bytes. All scheduled events run as
   if WP Sudo is not installed. If a gated action fires during cron, the
   `wp_sudo_action_allowed` audit hook fires (see §19.4).

> **Cleanup:** Restore Cron policy to **Limited** after testing.

---

## 9. Settings Page

### 9.1 Three-Option Policy Dropdowns

1. Go to **Settings > Sudo**.
2. **Expected:** Five policy fields, each with three options: Disabled,
   Limited (default), Unrestricted. The fifth (WPGraphQL) is visible only
   when WPGraphQL is active.
3. Change a policy, save, reload.
4. **Expected:** The saved value persists.

### 9.2 Session Duration

1. Set session duration to a valid value (1–15 minutes). Save.
2. **Expected:** Saved successfully.
3. Try setting it to 0 or 99.
4. **Expected:** Sanitized back to the valid range.

### 9.3 Help Tabs

1. Click the **Help** button (top-right of Settings > Sudo).
2. **Expected:** 10 help tabs: How Sudo Works, Session & Policies,
   App Passwords, MU-Plugin, Security Features, Security Model,
   Environment, Recommended Plugins, Extending, Audit Hooks.

### 9.4 Gated Actions Table

1. Scroll down on Settings > Sudo.
2. **Expected:** A table listing all 32 gated rules grouped by
   category, showing which surfaces each action covers. When WPGraphQL
   is active, an additional GraphQL row appears at the bottom of the table.

### 9.5 MU-Plugin Toggle

1. Scroll to the MU-Plugin section.
2. Click **Install** to install the mu-plugin.
3. **Expected:** Success message. The mu-plugin file exists in
   `wp-content/mu-plugins/`.
4. Click **Uninstall** to remove it.
5. **Expected:** Success message. The mu-plugin file is removed.

### 9.6 MU Loader Resilience (Canonical + Non-Canonical Paths)

1. Install the MU-plugin shim (`wp-content/mu-plugins/wp-sudo-gate.php`) and
   keep the main plugin active in the default path
   `wp-content/plugins/wp-sudo/`.
2. Load an admin page with no active sudo session and trigger a gated action.
3. **Expected:** Normal challenge flow occurs (loader resolves plugin file and
   early gate hooks register).

4. Rename the plugin directory to a non-canonical slug (example:
   `wp-content/plugins/my-security-stack/`) while keeping the plugin active.
5. Load an admin page and trigger a gated action again.
6. **Expected:** Challenge flow still works. The MU loader should recognize the
   active plugin basename ending in `/wp-sudo.php` and remain functional even
   when the directory is not literally `wp-sudo`.

7. Leave the MU shim installed, but deactivate the main plugin.
8. Load several admin pages and attempt actions that were previously gated.
9. **Expected:** Loader is inert when plugin is inactive. No gated challenge
   flow should be triggered by the shim alone.

10. Force an unresolved loader-path scenario (for example: active plugin entry
    remains, but main plugin file is unavailable at all loader candidate paths
    in a disposable dev environment).
11. Add a temporary listener for:
    `wp_sudo_mu_loader_unresolved_plugin_path`.
12. **Expected:** The unresolved-path action fires with candidate file paths,
    giving operators a diagnosable signal instead of a silent failure.

---

## 10. Site Health

1. Go to **Tools > Site Health**.
2. **Expected:** A "WP Sudo Entry Point Policies" test result.

### 10.1 All Limited or Disabled

- Set all five policies to Limited (or Disabled).
- **Expected:** Status is **Good** (green/blue shield).

### 10.2 Any Unrestricted

- Set one or more policies to Unrestricted.
- **Expected:** Status is **Recommended** (orange shield) with a
  message identifying which policies are unrestricted.

---

## 11. Admin Bar Timer

1. Activate sudo.
2. **Expected:** A countdown timer appears in the admin bar (green
   background, `M:SS` format).
3. Navigate between admin pages.
4. **Expected:** Timer persists and updates.
5. Hover over the timer.
6. **Expected:** A deactivation link is visible.
7. In the final 60 seconds:
   - **Expected:** Timer turns red.
   - **Accessibility:** Milestone announcements at 1 minute, 30 seconds,
     and 10 seconds are read by screen readers.
8. Let the session expire.
9. **Expected:** Timer disappears, page auto-reloads.
10. Click the deactivation link during an active session.
11. **Expected:** Session ends, you stay on the current page (not
    redirected to the dashboard).

---

## 12. Capability Restriction (Single-Site)

> Skip on multisite — WordPress core already restricts
> `unfiltered_html` to Super Admins.

### 12.1 Editor Role on Activation

1. Activate WP Sudo.
2. Log in as an Editor.
3. Create a post with HTML like `<script>alert(1)</script>`.
4. **Expected:** KSES strips the script tag. Editors do not have
   `unfiltered_html`.
5. **Result:** PASS — 2026-02-20 (Studio, SQLite). Verified Editor role
   lacks `unfiltered_html` via direct DB query; Administrator retains it.

### 12.2 Tamper Detection

1. Manually add `unfiltered_html` back to the Editor role (via a
   snippet or direct DB edit on `wp_user_roles`).
2. Load any admin page.
3. **Expected:** The capability is automatically stripped. The
   `wp_sudo_capability_tampered` action fires (visible in debug log if
   an audit logger is connected).
4. **Result:** PASS — 2026-02-20 (Studio, SQLite). Injected
   `unfiltered_html` into Editor role via direct DB edit; loading any
   admin page stripped it automatically.

---

## 13. Multisite-Specific (Multisite Only)

### 13.1 Network Admin Gating

1. Go to **Network Admin > Themes**.
2. Click **Network Enable** on a theme.
3. **Expected:** Redirected to challenge page with "Network enable
   theme." Cancel returns to the Network Admin Themes screen.
4. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Challenge shows "Network enable theme." Cancel returns to Themes.

### 13.2 Network Admin Persistent Notice

1. Go to **Network Admin > Plugins** with no sudo session active.
2. **Expected:** Persistent notice linking to challenge page and
   showing keyboard shortcut.
3. Go to **Network Admin > Themes**.
4. **Expected:** Same persistent notice.
5. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Notice appears on both Plugins and Themes pages with "Confirm your
   identity" link and `Cmd+Shift+S` shortcut. Also verified: "Delete"
   actions converted to disabled `<span>` (Themes) or disabled `<a>`
   (Plugins) with grey `wp-sudo-disabled` styling.

### 13.3 Site Management

1. Go to **Network Admin > Sites**.
2. Try to deactivate, archive, spam, or delete a site.
3. **Expected:** Each action redirects to the challenge page. Cancel
   returns to the Sites screen.
4. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61697, Local multisite).
   After fix in `702534a` (action2 fallback in `match_request()`),
   clicking Archive on `/one` redirects to the challenge page with
   "Archive site." Cancel returns to Sites screen without archiving.
   Previously PARTIAL (2026-02-20): WordPress sends
   `action=confirm&action2=archiveblog` and the Gate only checked
   `action`, missing the real action in `action2`.

### 13.4 Super Admin Grant

1. Go to **Network Admin > Users**, edit a user.
2. Toggle the Super Admin checkbox and save.
3. **Expected:** Redirected to challenge page with "Grant or revoke
   super admin."
4. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Challenge shows "Grant or revoke super admin." Cancel returns to
   user edit page.

### 13.5 Network Settings

1. Go to **Network Admin > Settings** and save.
2. **Expected:** Redirected to challenge page with "Change network
   settings."
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61697, Local multisite).
   Challenge shows "Change network settings."

---

## 14. Edge Cases

### 14.1 Expired Stash

1. Trigger a stash-challenge-replay flow.
2. Wait more than 5 minutes on the challenge page (stash TTL expires).
3. Enter your password.
4. **Expected:** Error message: "Your challenge session has expired.
   Please try again."
5. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio). Triggered
   Save Changes on General Settings, waited 5.5 minutes on the challenge
   page, then entered password. Error displayed: "Your challenge session
   has expired. Please try again."

### 14.2 Multiple Tabs

1. Open two admin tabs.
2. Activate sudo in tab 1.
3. Reload tab 2, then perform a gated action.
4. **Expected:** Action succeeds — the session is shared via cookie +
   user meta.
5. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio). Activated
   sudo via Cmd+Shift+S in tab 1. Reloaded tab 2 (Plugins page) — admin
   bar showed the shared sudo timer, gate notice was absent, and action
   links were enabled. Session is shared via cookie + user meta.

### 14.3 Challenge Page in iframe Context

> Fixed in v2.3.0.

1. Trigger a gated action from a screen that uses `wp_iframe()` (e.g.
   the media uploader during a plugin or theme update).
2. **Expected:** The challenge page breaks out of the iframe and loads
   as a full page.
3. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio). Clicked
   "Update to latest 7.0 nightly" on update-core.php with no active
   sudo session. Gate intercepted the POST to `do-core-upgrade` and
   redirected to the challenge page as a full page (not inside an
   iframe). Challenge showed "Update WordPress core" with stash key.
   Cancel returned to the Updates page.

### 14.4 Uninstall Cleanup

1. Deactivate and delete WP Sudo.
2. Check the database:
   - `wp_options` should not contain `wp_sudo_settings` or
     `wp_sudo_db_version`.
   - User meta should not contain keys starting with `_wp_sudo_`.
   - Editor role should have `unfiltered_html` restored.
3. **Expected:** All WP Sudo data is cleaned up.
4. **Result:** PASS — 2026-02-19 (WP 7.0-alpha-61698, Studio).
   Deactivated then deleted WP Sudo. PHP DB check confirmed:
   `wp_sudo_settings` removed, `wp_sudo_db_version` removed, zero
   `_wp_sudo_*` user meta rows, Editor role `unfiltered_html` restored
   to YES. Plugin reinstalled and reactivated successfully afterward.

---

## 15. WP 7.0 Visual Compatibility

Run against a WP 7.0 beta/RC dev site.
For the WP 7.0 release cycle, rerun this section for each RC build
(RC1, RC2, etc.) and once more at GA. Record the build string and date
for each pass.
Document pass/fail and any visual regressions. These checks verify the plugin's
admin UI renders correctly under the WP 7.0 admin visual refresh (Trac #64308).

### 15.0 Release Signoff Log

Track the release-candidate and GA passes here so WP 7.0 readiness is explicit.

| Milestone | Status | Date | Build | Environment | Notes |
|-----------|--------|------|-------|-------------|-------|
| RC1 | PASS | 2026-03-24 | 7.0-RC1 | `wp-env` + Playwright | Sections 15.1-15.5 PASS after repinning forward lanes to RC1 |
| RC2 | Pending | — | — | — | |
| RC3 | Pending | — | — | — | Required checkpoint before GA |
| GA | Pending | — | — | — | Final "Tested up to" bump depends on this pass |

### 15.1 Settings Page (Settings > Sudo)

1. Load **Settings > Sudo** on a WP 7.0 site.
2. **Expected:** Settings page renders correctly under WP 7.0 admin chrome.
   - Form table rows, labels, and select dropdowns align properly.
   - Policy dropdowns respect the 140px minimum width.
   - Shield dashicon renders in the page title.
   - Help tabs open and display correctly.
   - Gated actions table (`.widefat.striped`) renders with correct spacing.
   - MU-plugin status section renders correctly.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)
4. **Result:** PASS — 2026-03-24 (WP 7.0-RC1, `wp-env` + Playwright browser verification)

### 15.2 Challenge Page

1. Ensure no sudo session is active.
2. Trigger a gated action (e.g., **Settings > General > Save Changes**).
3. **Expected:** Challenge card renders correctly under WP 7.0 admin chrome.
   - Card is centered with max-width 420px, white background, border, and shadow.
   - Password field fills the full card width.
   - "Confirm & Continue" and "Cancel" buttons render correctly.
   - No raw text or visible escape sequences appear in the card.
4. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)
5. **Result:** PASS — 2026-03-24 (WP 7.0-RC1, `wp-env` + Playwright browser verification)

### 15.3 Admin Bar Countdown

1. Activate a sudo session.
2. **Expected:** Green countdown node renders in the admin bar.
   - Timer text is readable against the green (#2e7d32) background.
   - Red state (#c62828) appears in the final 60 seconds.
   - Admin bar node does not conflict with WP 7.0 toolbar chrome.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)
4. **Result:** PASS — 2026-03-24 (WP 7.0-RC1, `wp-env` + Playwright browser verification, including expiring-state timer check)

### 15.4 Admin Notices (Gate Notice + Blocked Notice)

1. Go to **Plugins** with no sudo session active.
2. **Expected:** Persistent gate notice renders with correct styling.
   - `.notice.notice-warning` class applies correctly under WP 7.0.
   - Link to the challenge page is visible and styled.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)
4. **Result:** PASS — 2026-03-24 (WP 7.0-RC1, `wp-env` + Playwright browser verification)

### 15.5 Disabled Action Links (Plugin/Theme rows)

1. Go to **Plugins** with no sudo session active.
2. **Expected:** Activate, Deactivate, and Delete links are replaced with
   gray disabled spans.
   - Inline `color:#787c82; cursor:default` renders correctly.
   - No conflict with new row-action hover styles from the admin refresh.
3. **Result:** PASS — 2026-02-20 (WP 7.0-alpha-61698, Studio)
4. **Result:** PASS — 2026-03-24 (WP 7.0-RC1, `wp-env` + Playwright browser verification)

---

## 16. WPGraphQL Surface Policy

> Requires the [WPGraphQL](https://wordpress.org/plugins/wp-graphql/) plugin to be
> active. If WPGraphQL is not installed, skip this section.

WPGraphQL gating is **surface-level** (not per-action): in Limited mode, all
mutations are blocked regardless of which operation they perform. Queries always
pass through. Gating hooks into WPGraphQL's own lifecycle so it works regardless
of how the endpoint is named.

For these tests, use an Application Password for authentication. In the `curl`
commands below, replace `YOUR_SITE_URL` and `YOUR_USERNAME:YOUR_APP_PASS`
accordingly (strip spaces from the application password).

### 16.0 Without WPGraphQL Installed

> For these tests, deactivate or remove the WPGraphQL plugin.

1. Go to **Settings > Sudo**.
2. **Expected:** No WPGraphQL policy dropdown is shown. The four non-GraphQL
   dropdowns (REST/App Passwords, WP-CLI, Cron, XML-RPC) remain visible.
3. Open the **Session & Policies** help tab.
4. **Expected:** The WPGraphQL paragraph reads: "WPGraphQL is also supported
   as an entry point — its policy setting appears on this page when WPGraphQL
   is installed."
5. Go to **Tools > Site Health > Tests**.
6. **Expected:** Even if the saved WPGraphQL policy is "Unrestricted" (from a
   prior test run), no "WPGraphQL policy is unrestricted" warning appears.
   The policy review test shows only the four active surfaces.

> **Cleanup:** Reinstall/reactivate WPGraphQL to continue with §16.1–16.5.

### 16.1 Limited (Default) — Query passes through

Ensure WPGraphQL policy is set to **Limited** (the default), then:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"{ __typename }"}'
```

**Expected:** HTTP 200 with a valid GraphQL response body (e.g.
`{"data":{"__typename":"RootQuery"}}`). The query is not blocked.

### 16.2 Limited — Mutation blocked (no sudo session)

With the policy still on **Limited** and **no active sudo session**:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"mutation { __typename }"}'
```

**Expected:** HTTP 403 with a `sudo_blocked` error:

```json
{"code":"sudo_blocked","message":"This GraphQL mutation requires sudo. Activate a sudo session and try again.","data":{"status":403}}
```

### 16.3 Disabled — All requests blocked

Set WPGraphQL policy to **Disabled** in Settings > Sudo, then:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"{ __typename }"}'
```

**Expected:** HTTP 403 with a `sudo_disabled` error:

```json
{"code":"sudo_disabled","message":"WPGraphQL is disabled by WP Sudo policy.","data":{"status":403}}
```

### 16.4 Unrestricted — Mutation passes through

> See also §19.5 for audit hook verification.

Set WPGraphQL policy to **Unrestricted**, then repeat the mutation request
from 16.2.

**Expected:** HTTP 200. WP Sudo does not block the request. (WPGraphQL may
still return a schema-level error if the mutation is invalid — that is
expected and unrelated to WP Sudo.)

> **Cleanup:** Restore the WPGraphQL policy to **Limited** after testing.

### 16.5 Bypass Filter — JWT Login Mutation

> Requires the [wp-graphql-jwt-authentication](https://github.com/wp-graphql/wp-graphql-jwt-authentication) plugin to be active and configured with a `GRAPHQL_JWT_AUTH_SECRET_KEY` constant.

1. Set WPGraphQL policy to **Limited** (the default).
2. **Without** the bypass filter, send a JWT login mutation:

```bash
curl -sk -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"mutation { login(input: {username: \"admin\", password: \"admin\"}) { authToken } }"}'
```

**Expected:** HTTP 403 with `sudo_blocked` — the login mutation is blocked because the request is unauthenticated.

3. Add the bypass filter mu-plugin (see `docs/developer-reference.md` for the full example), then repeat the same request.

**Expected:** HTTP 200 with a valid `authToken` in the response. The `login` mutation passes through because the filter exempts it.

4. Use the returned `authToken` to send an authenticated mutation:

```bash
curl -sk -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"mutation { __typename }"}'
```

**Expected:** HTTP 403 with `sudo_blocked` — the user is authenticated (JWT sets `get_current_user_id()`), but has no sudo session. Non-exempt mutations remain gated.

5. Send a query with the same token:

```bash
curl -sk -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"query":"{ viewer { name } }"}'
```

**Expected:** HTTP 200 with the authenticated user's name. Queries always pass through in Limited mode.

> **Cleanup:** Restore the WPGraphQL policy to **Limited** after testing. Remove the bypass filter mu-plugin if it was only for testing.

### 16.6 Persisted Queries — Classifier Filter

> Use this when WPGraphQL Persisted Queries (or APQ) is active.

1. Keep WPGraphQL policy at **Limited**.
2. Add a temporary classifier mu-plugin:

```php
<?php
add_filter( 'wp_sudo_wpgraphql_classification', function ( string $classification, string $body ): string {
    if ( str_contains( $body, 'persisted-mutation-hash' ) ) {
        return 'mutation';
    }
    if ( str_contains( $body, 'persisted-query-hash' ) ) {
        return 'query';
    }
    return $classification;
}, 10, 2 );
```

3. Send a persisted-query mutation payload (hash-only body):

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"extensions":{"persistedQuery":{"version":1,"sha256Hash":"persisted-mutation-hash"}}}'
```

4. **Expected:** HTTP 403 with `sudo_blocked`.
5. Send a persisted-query query payload:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X POST "YOUR_SITE_URL/graphql" \
  -d '{"extensions":{"persistedQuery":{"version":1,"sha256Hash":"persisted-query-hash"}}}'
```

6. **Expected:** HTTP 200 (query pass-through).

> **Cleanup:** Remove the temporary classifier mu-plugin after testing.

---

## 17. v2.6.0 Feature Verification

### 17.1 Login Grants Sudo Session

1. Log out completely.
2. Log back in via the standard WordPress login form.
3. **Expected:** After login, the admin bar immediately shows a green
   countdown timer — no challenge page is shown.
4. Verify you can perform a gated action (e.g., go to **Plugins** and
   click an Activate link) without being redirected to the challenge page.
5. **Expected:** Action proceeds immediately.
6. Verify: logging in via **Application Passwords** (REST API) does
   **not** start a sudo session — the admin bar shows no timer when
   accessed directly after an app-password API call.

### 17.2 Change Password Gated (Admin UI — Profile)

> See also section 2.15.

1. Log out and log back in (so a sudo session is active from the login grant).
2. Let the session expire (set duration to 1 minute in Settings > Sudo,
   then wait).
3. Go to **Users > Your Profile**, scroll to **New Password**, set a new
   password, click **Update Profile**.
4. **Expected:** Redirected to the challenge page. Action label shows
   "Change password."
5. Authenticate.
6. **Expected:** Password is changed. No challenge fires for a plain
   profile update (bio, email) in the same session.

### 17.3 Change Password Gated (REST API)

1. Ensure **no sudo session is active**.
2. Send a `PATCH` request to `/wp/v2/users/me` with a `password` field:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -H "Content-Type: application/json" \
  -X PATCH "YOUR_SITE_URL/wp-json/wp/v2/users/me" \
  -d '{"password":"NewPassword123!"}'
```

3. **Expected:** HTTP 403 with a `sudo_blocked` error (or `sudo_disabled`
   if the REST App Passwords policy is set to Disabled).
4. A `PATCH` request **without** a `password` field (e.g. only `name`)
   should return HTTP 200.

### 17.4 Grace Period (In-Flight Form)

> This test requires precise timing. Set session duration to 1 minute.

1. Set session duration to **1 minute** in Settings > Sudo.
2. Activate a sudo session.
3. Navigate to **Users > Your Profile**.
4. Wait for the admin bar timer to expire (the timer disappears and the
   page auto-reloads after expiry).
5. **Immediately** (within 2 minutes of expiry) fill in a non-password
   profile field and click **Update Profile**.
6. **Expected:** Profile update succeeds **without** a challenge. The
   grace window (120 s) allows the in-flight submission through.
7. Wait more than 2 minutes after expiry, then repeat steps 5–6.
8. **Expected:** The update is intercepted and the challenge page appears
   — the grace window has closed.

> **Cleanup:** Restore session duration to its original value after testing.

---

## 18. Password Change Expires Sudo Session

> Available since v2.8.0. Confirms that changing a user's password
> invalidates any active sudo session for that user.

### 18.1 Admin profile (profile.php)

1. Start an active sudo session (perform any gated action).
2. Confirm the admin bar shows the session countdown timer.
3. Go to **Users > Profile** and change your password. Click **Update Profile**.
4. **Expected:** The admin bar countdown disappears on the next page load —
   the session has been invalidated.
5. Trigger any gated action (e.g., navigate to Plugins).
6. **Expected:** The challenge page appears (reauthentication required).

> **Cleanup:** No cleanup needed. Log back in with your new password.

### 18.2 User edit (user-edit.php) — editor's own session is not affected

1. Log in as an administrator with an active sudo session.
2. Go to **Users > All Users** and edit a different user's profile.
3. Change that user's password. Click **Update User**.
4. **Expected:** Your own sudo session is NOT invalidated.
   The admin bar countdown continues normally.
5. Verify: if that other user is logged in concurrently, their session
   should be expired (their admin bar timer vanishes on next page load).

### 18.3 Lost-password reset flow

1. Trigger a password reset for any user via the lost-password link,
   or via WP-CLI: `wp user reset-password <user_id>`.
2. Complete the reset and save the new password.
3. **Expected:** After the reset, the user's sudo session (if any) is
   invalidated. Verify via WP-CLI:
   ```bash
   wp user meta get <user_id> _wp_sudo_expires
   ```
   **Expected:** Empty output — the meta key has been deleted.

---

## 19. Unrestricted Audit Hook (v2.9.0)

> Verifies that the `wp_sudo_action_allowed` hook fires on all five
> non-interactive surfaces when their policy is set to Unrestricted.

### Prerequisites

Add a listener mu-plugin to your dev site so hook calls are logged to
`debug.log`:

```php
<?php
// mu-plugins/wp-sudo-audit-log.php
add_action( 'wp_sudo_action_allowed', function ( int $user_id, string $rule_id, string $surface ): void {
    error_log( sprintf( '[WP Sudo] action_allowed: user=%d rule=%s surface=%s', $user_id, $rule_id, $surface ) );
}, 10, 3 );
```

Ensure `WP_DEBUG` and `WP_DEBUG_LOG` are enabled in `wp-config.php`.
Tail the log during testing:

```bash
tail -f /path/to/wp-content/debug.log | grep 'action_allowed'
```

### 19.1 REST App Passwords

1. Set REST API (App Passwords) policy to **Unrestricted** in Settings > Sudo.
2. Send a gated request:

```bash
curl -sk -u "YOUR_USERNAME:YOUR_APP_PASS" \
  -X POST "YOUR_SITE_URL/wp-json/wp/v2/users/me/application-passwords" \
  -H "Content-Type: application/json" \
  -d '{"name":"audit-test"}'
```

3. **Expected:** HTTP 200 with password data.
4. **Expected in debug.log:**
   `[WP Sudo] action_allowed: user=<id> rule=user.create_app_password surface=rest_app_password`

> **Cleanup:** Delete the test app password.

### 19.2 WP-CLI

1. Set CLI policy to **Unrestricted**.
2. Run a gated command:

```bash
wp plugin deactivate hello-dolly --path=/path/to/wordpress
```

3. **Expected:** Plugin deactivated successfully.
4. **Expected in debug.log:**
   `[WP Sudo] action_allowed: user=0 rule=plugin.deactivate surface=cli`

> **Cleanup:** Reactivate the plugin.

### 19.3 XML-RPC

1. Set XML-RPC policy to **Unrestricted**.
2. Send a request (any listed method triggers it, though XML-RPC rules
   match specific method names — use `system.listMethods` to confirm
   connectivity, then a gated method if your XML-RPC client supports it):

```bash
curl -sk -X POST "YOUR_SITE_URL/xmlrpc.php" \
  -H "Content-Type: text/xml" \
  -d '<?xml version="1.0"?><methodCall><methodName>wp.getOptions</methodName><params><param><value>1</value></param><param><value>YOUR_USERNAME</value></param><param><value>YOUR_PASSWORD</value></param></params></methodCall>'
```

3. **Expected:** Valid XML response.
4. **Expected in debug.log:**
   `[WP Sudo] action_allowed: user=0 rule=<matched_rule> surface=xmlrpc`

### 19.4 Cron

1. Set Cron policy to **Unrestricted**.
2. Trigger a gated cron action. The simplest method is via WP-CLI:

```bash
wp cron event run --all --path=/path/to/wordpress
```

   Or wait for a natural wp-cron trigger (page load after due time).

3. **Expected:** Cron events execute normally.
4. **Expected in debug.log** (if a gated action fires during cron):
   `[WP Sudo] action_allowed: user=0 rule=<matched_rule> surface=cron`

> **Note:** The hook only fires when a registered gated action actually
> runs during cron. If no gated action fires, no log entry is expected.

### 19.5 WPGraphQL

> Requires WPGraphQL plugin to be installed and active.

1. Set WPGraphQL policy to **Unrestricted**.
2. Send a mutation:

```bash
curl -sk -X POST "YOUR_SITE_URL/graphql" \
  -H "Content-Type: application/json" \
  -d '{"query":"mutation { updateSettings(input: {}) { allSettings { generalSettingsTitle } } }"}'
```

3. **Expected:** The request completes (WPGraphQL may return a schema error if
   the mutation is invalid — that is expected and unrelated to WP Sudo).
4. **Expected in debug.log:**
   `[WP Sudo] action_allowed: user=<id> rule=wpgraphql surface=wpgraphql`

5. Now send a query (not a mutation):

```bash
curl -sk -X POST "YOUR_SITE_URL/graphql" \
  -H "Content-Type: application/json" \
  -d '{"query":"{ generalSettings { title } }"}'
```

6. **Expected:** HTTP 200 with query result. **No** `action_allowed` log entry
   is written — only mutations fire the hook.

> **Cleanup:** Restore WPGraphQL policy to **Limited**. Remove the
> mu-plugin listener when testing is complete.

### 19.6 WSAL Sensor Bridge

> Requires WP Activity Log (WSAL) plugin + `bridges/wp-sudo-wsal-sensor.php`
> copied into `wp-content/mu-plugins/`.

1. Install and activate WP Activity Log.
2. Copy bridge:
   `wp-content/plugins/wp-sudo/bridges/wp-sudo-wsal-sensor.php`
   → `wp-content/mu-plugins/wp-sudo-wsal-sensor.php`
3. Trigger a blocked action (for example, plugin activation without sudo).
4. **Expected:** A WSAL event with ID `1900006` is recorded with payload fields
   including `source=wp-sudo`, `hook=wp_sudo_action_blocked`, `rule_id`, and
   `surface`.
5. Activate sudo and repeat on a gated action that is allowed in policy flow.
6. **Expected:** Corresponding WSAL event IDs fire based on hook type (for
   example `1900005` gated, `1900007` allowed, `1900008` replayed).
7. Temporarily deactivate/uninstall WSAL while leaving the bridge file in place.
8. **Expected:** No fatal errors; bridge remains inert when WSAL APIs are absent.

### 19.7 Stream Bridge

> Requires Stream plugin + `bridges/wp-sudo-stream-bridge.php`
> copied into `wp-content/mu-plugins/`.

1. Install and activate Stream.
2. Copy bridge:
   `wp-content/plugins/wp-sudo/bridges/wp-sudo-stream-bridge.php`
   → `wp-content/mu-plugins/wp-sudo-stream-bridge.php`
3. Trigger a blocked action (for example, plugin activation without sudo).
4. Open **Stream > Records**.
5. **Expected:** A record appears with connector `wp_sudo`, context
   `wp_sudo`, and action `blocked`.
6. Open the record details.
7. **Expected:** Record args/meta include `source=wp-sudo`,
   `hook=wp_sudo_action_blocked`, plus `rule_id` and `surface`.
8. Trigger other lifecycle events (activate/deactivate sudo, lockout,
   replay) and confirm corresponding actions (`activated`,
   `deactivated`, `lockout`, `replayed`) appear in Stream.
9. Temporarily deactivate/uninstall Stream while leaving the bridge file in place.
10. **Expected:** No fatal errors; bridge remains inert when Stream APIs are absent.

---

## 20. WebAuthn Bridge (Two Factor WebAuthn Provider)

> Requires: WP Sudo 2.0+, Two Factor plugin, Two Factor Provider for
> WebAuthn plugin. Install the bridge as a mu-plugin:
> `cp bridges/wp-sudo-webauthn-bridge.php wp-content/mu-plugins/`

### 20.1 Register Security Key — Blocked Without Sudo

1. Ensure no sudo session is active.
2. Go to **Users > Profile**, scroll to the WebAuthn security keys section.
3. Click **Register New Key**.
4. **Expected:** The registration ceremony does not start. An AJAX error
   response is returned. The blocked-action notice appears on next page
   load: "This action (Register security key (WebAuthn)) requires
   reauthentication."

### 20.2 Register Security Key — Allowed With Sudo

1. Activate a sudo session.
2. Click **Register New Key**.
3. **Expected:** The browser's WebAuthn ceremony starts (key tap / biometric
   prompt). The key is registered successfully.

### 20.3 Delete Security Key — Blocked Without Sudo

1. Ensure no sudo session is active. At least one key must be registered.
2. Click the **Delete** button (or equivalent) on a registered key.
3. **Expected:** AJAX error. Key is not deleted. Blocked-action notice
   appears on next page load.

### 20.4 Delete Security Key — Allowed With Sudo

1. Activate a sudo session.
2. Click **Delete** on a registered key.
3. **Expected:** Key is deleted successfully.

### 20.5 Rename Security Key — Not Gated

1. Without sudo, rename a registered key.
2. **Expected:** Rename succeeds. This action is not security-sensitive
   and is intentionally not gated by the bridge.

---

## 21. Public API Helpers (`wp_sudo_check()` / `wp_sudo_require()`)

### 21.1 Session Check Returns False Without Active Sudo

1. Ensure sudo is inactive for your user.
2. Run in `wp shell`:
   `wp eval 'var_export( wp_sudo_check( get_current_user_id() ) );'`
3. **Expected:** `false`.

### 21.2 `wp_sudo_require()` Redirects to Challenge by Default

1. Create a temporary mu-plugin with:
   `add_action( 'admin_init', static function () { if ( isset( $_GET['wp_sudo_require_test'] ) ) { wp_sudo_require( array( 'rule_id' => 'manual.require_test' ) ); } } );`
2. Visit `/wp-admin/?wp_sudo_require_test=1` while sudo is inactive.
3. **Expected:** Redirect to `admin.php?page=wp-sudo-challenge` (session-only mode).
4. Authenticate successfully.
5. **Expected:** Return/cancel behavior follows the challenge page flow; future manual trigger passes once sudo is active.

### 21.3 `wp_sudo_require()` Returns False When Redirect Disabled

1. Run in `wp shell`:
   `wp eval 'var_export( wp_sudo_require( array( \"redirect\" => false, \"rule_id\" => \"manual.require_no_redirect\" ) ) );'`
2. **Expected:** `false` when sudo is inactive.
3. Activate a sudo session, rerun the command.
4. **Expected:** `true`.
