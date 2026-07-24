# Manual / live security-test checklist

Automated tests prove the plugin's **logic** (unit) and that it **runs across the
matrix** (CI integration + E2E). This checklist is the **live end-to-end layer**
they do not replace: verifying that the gate actually challenges in a real
browser, that REST routes return a 403 sudo gate (`sudo_required` on the cookie
surface, `sudo_blocked` via App Password), and that the lockdown audit
behaves for an operator on a running site.

Introduced for the 4.8.0 security work (`user.change_email`, REST `POST`
gating on `/wp/v2/users` and `/wp/v2/settings`, and the role/capability
lockdown audit). Re-run the relevant sections whenever those paths change.

Legend: ☐ = to run · **[CLI]** WP-CLI only · **[REST]** HTTP client only ·
**[UI]** needs a browser (per `CLAUDE.md`, browser automation is a separate
`claude-playwright` session).

---

## 0. Environment & preconditions

- ☐ A running WordPress (wp-env, Studio, or a disposable staging site), **not production**.
- ☐ WordPress 6.4+ / PHP 8.2+; WP Sudo active.
- ☐ Test users: one **administrator** (`admin`), one **editor** (`ed`), and a
  **victim** admin (`victim`) for cross-user profile edits.
- ☐ Know how to reach an **"authenticated but *not* sudo-elevated"** state — this
  is the condition the gate acts on:
  - WP Sudo grants a sudo session on browser login (`wp_login`), so a fresh login
    is *elevated*. To test gating in the browser, **let the session lapse**: set
    Settings → Sudo → session duration to **1 minute** and wait it out, or trigger
    a path after the timer expires.
  - For **[REST]** checks, drive the route with an **Application Password** (Users →
    Profile → Application Passwords) via Basic auth, or a cookie+nonce request that
    does **not** carry an active sudo session. App-Password / CLI / cron entry-point
    policies are configured under Settings → Sudo — set them to **Limited** or
    **Disabled** as the scenario requires, and note the policy you used.
- ☐ Record the site URL, WP version, PHP version, and object-cache backend
  (none vs. Redis/Memcached) at the top of your results — the lockdown
  cache-bypass checks (§4) only differ under a **persistent** object cache.

> Tip: keep `wp-admin` open in one tab and a REST client (curl/Postman/`wp
> rest`) in another so you can compare surfaces for the same mutation.

> **Observed response codes (verified live on 4.8.0, WP 7.0.2).** A gated REST route
> returns **HTTP 403** with one of two codes depending on the surface you drive:
> - **`code: "sudo_blocked"`** — via an **Application Password** under a Limited/Disabled
>   entry-point policy: a hard policy block, because an App Password cannot answer an
>   interactive challenge. (This is the practical headless surface — see §0.)
> - **`code: "sudo_required"`** — on the **cookie / interactive** surface: the challenge
>   is offered rather than a hard block.
>
> Assert on **403 + the code for the surface you used**. The rows below say
> "`sudo_required`" for the cookie surface; substitute `sudo_blocked` when driving via
> an App Password.

---

## 1. Account email change — `user.change_email` (#214)

**Threat:** a hijacked session changes the account email, then triggers a
password reset to that address — a full takeover — without reauthenticating.

- ☐ **[UI]** As `admin` with **no active sudo session**, go to your **profile**
  (`profile.php`), change the email to a new address, Save → **expect the sudo
  challenge**. After passing it, the email change is applied.
- ☐ **[UI]** Save your profile again **without changing the email** (edit only,
  say, the nickname) → **expect NO challenge** (the rule fires only on a real
  change; `email_change_differs()` is fail-closed on malformed input but a no-op
  save must pass through).
- ☐ **[UI]** As `admin`, edit **another user** (`victim`, via `user-edit.php`),
  change *their* email, Save → **expect the challenge** (cross-user edit is gated;
  the target is resolved from `user_id`, not just the current user).
- ☐ **[REST]** Without an active sudo session, for **each** method — `POST`,
  `PUT`, `PATCH` — send an email change to **`/wp/v2/users/<id>`**:
  ```bash
  curl -s -X POST -u "admin:APP_PASSWORD" \
    -H 'Content-Type: application/json' \
    -d '{"email":"changed@example.com"}' \
    "$SITE/wp-json/wp/v2/users/2" | jq '.code // .id'
  ```
  → **expect `sudo_required`** (HTTP 403) for all three methods.
- ☐ **[REST]** Repeat the three methods against the **`/me` route**
  (`/wp/v2/users/me`) → **expect `sudo_required`** (the `/me` alias must be gated,
  not just numeric IDs).
- ☐ **[REST]** Send a `POST` to `/wp/v2/users/<id>` with the **same** email the
  user already has → **expect it to pass** (no real change → no gate).
- ☐ **Non-replayable stash:** after passing a challenge for a profile email
  change, confirm the original save applied **once**; then attempt to re-submit
  the captured request (browser back + re-POST, or replay the stash key) →
  **expect it NOT to re-apply** (`stash_no_replay()`).

## 2. REST `POST` to `/wp/v2/users` — password & role (#213)

**Threat:** the users route is registered under `WP_REST_Server::EDITABLE`
(`POST, PUT, PATCH`); the old rules matched only `PUT`/`PATCH`, so a `POST`
slipped the gate.

- ☐ **[REST]** No active sudo session. `POST /wp/v2/users/<id>` with
  `{"password":"NewStr0ngPass!"}` → **expect `sudo_required`**.
- ☐ **[REST]** `POST /wp/v2/users/<id>` with `{"roles":["administrator"]}`
  (promote) → **expect `sudo_required`**.
- ☐ **[REST]** Repeat both against **`/wp/v2/users/me`** → **expect
  `sudo_required`**.
- ☐ **[REST]** Sanity: the same mutations via `PUT` and `PATCH` remain gated
  (regression guard).
- ☐ **[REST]** After a valid sudo session is established, the same `POST`
  **succeeds** (gate elevates, does not permanently block).

## 3. Critical-settings REST — `options.critical` (#215)

**Threat:** `/wp/v2/settings` is keyed by each setting's `show_in_rest` name, so
`siteurl`→`url` and `admin_email`→`email`; the old rule matched only raw names
and never fired. Repointing `siteurl` loads attacker-origin scripts same-origin
in wp-admin (XSS-as-RCE).

- ☐ **[REST]** No active sudo session. `POST /wp/v2/settings` with
  `{"url":"https://example.org"}` (the `siteurl` alias) → **expect
  `sudo_required`**.
  ```bash
  curl -s -X POST -u "admin:APP_PASSWORD" \
    -H 'Content-Type: application/json' \
    -d '{"url":"https://example.org"}' \
    "$SITE/wp-json/wp/v2/settings" | jq '.code // .url'
  ```
- ☐ **[REST]** `POST /wp/v2/settings` with `{"email":"new-admin@example.org"}`
  (the `admin_email` alias) → **expect `sudo_required`**.
- ☐ **[REST]** Confirm a **non-critical** setting (e.g. `{"title":"..."}`) still
  writes without a challenge (the gate is scoped to critical options, not all of
  `/wp/v2/settings`).
- ☐ **[REST]** After passing a challenge, the critical write succeeds and the
  option actually changes (then revert it).

## 4. Role / capability lockdown audit (#206)

Audit-only MVP — it **detects and reports**, it does not block or auto-revert.

### 4a. Opt-in & baseline
- ☐ **[CLI]** Feature is **inert** before opt-in: `wp sudo manifest diff` with no
  `WP_SUDO_ROLE_MANIFEST` defined reports the feature is not configured;
  Site Health shows no manifest test.
- ☐ Define the constant in `wp-config.php`:
  ```php
  define( 'WP_SUDO_ROLE_MANIFEST', '/path/to/role-manifest.json' );
  ```
- ☐ **[CLI]** Build the baseline: `wp sudo manifest generate
  --manifest-path=/path/to/role-manifest.json` → file is written.
- ☐ **[CLI]** Immediately after: `wp sudo manifest diff` → **no drift**.
- ☐ **[UI]** Site Health (Tools → Site Health → Status) shows **"Sudo role/
  capability state matches the manifest"** (green/good).
- ☐ Confirm a **daily** cron event `wp_sudo_role_audit_sweep` is scheduled
  (`wp cron event list`).

### 4b. Drift detection — unauthorized principals
- ☐ **[CLI]** Create a new admin *after* the baseline (`wp user create intruder
  intruder@example.org --role=administrator`) → `wp sudo manifest diff` reports it
  as an **unauthorized administrator**; Site Health flips to **critical**.
- ☐ **[CLI]** Grant a governance cap directly
  (`wp user add-cap ed manage_wp_sudo`) → reported as an unauthorized
  governance-cap holder.
- ☐ **[CLI] multisite:** `grant_super_admin` a user → reported under
  `network.super_admins`. *(Note: the MVP audits the **current blog**; subsite
  drift on another blog is a known limitation — issue #219.)*

### 4c. Non-admin role drift — the P1 fix
- ☐ **[CLI]** Add a privileged primitive to a **non-admin** role:
  `wp cap add editor manage_options` (or `promote_users`) → `wp sudo manifest
  diff` reports a **changed role definition** for `editor`. (Pre-4.8.0 this was
  missed — generate now watches *every* role, not just administrator.)

### 4d. Cache-bypass — the F3/F4 fixes (only meaningful with a persistent object cache)
- ☐ With **Redis/Memcached** active, perform a **raw DB grant** that bypasses the
  WP API (e.g. `wp db query "UPDATE wp_usermeta SET meta_value=... WHERE
  meta_key='wp_capabilities' AND user_id=<id>"`, or a direct `wp_user_roles`
  option edit) → `wp sudo manifest diff` **still detects it** on the next run
  (the audit reads users with `cache_results => false` and role definitions from a
  cache-busted `{prefix}user_roles`, so a stale cache cannot mask the drift).
- ☐ Negative control: with **no** persistent object cache, the same raw write is
  detected too (baseline behavior unchanged).

### 4e. CLI flag & remediation loop
- ☐ **[CLI]** `--manifest-path` is the flag (not `--path`, which collides with
  WP-CLI's global install-path parameter). Confirm `wp sudo manifest generate
  --manifest-path=<f>` writes to `<f>`, not a bootstrap error.
- ☐ **[UI]** On drift, the dashboard **Session Activity** widget records a
  high-severity `role_drift_detected` event (actor shown as system / `user_id 0`).
- ☐ **Remediate:** remove the rogue admin/cap, re-run `wp sudo manifest diff` →
  back to **clean**. **Or re-baseline** a legitimate change with `wp sudo manifest
  generate` → clean. (The plugin never changes the state for you.)
- ☐ **Alerting gap (known):** the bundled critical-alert bridge does **not** push
  on `role_drift_detected` yet (issue #222) — verify drift surfaces in Site
  Health + the dashboard, but do **not** expect an email/Slack alert out of the
  box.

---

## Results template

```
Date / tester:
Environment: WP <ver> / PHP <ver> / object cache: <none|redis|…> / <wp-env|studio|staging>
Section 1 (email change):        PASS / FAIL — notes:
Section 2 (POST /wp/v2/users):   PASS / FAIL — notes:
Section 3 (critical settings):   PASS / FAIL — notes:
Section 4 (lockdown audit):      PASS / FAIL — notes:
Regressions / surprises:
```
