# 4.8.0 live security-test results

Results of running [`security-manual-test-checklist.md`](security-manual-test-checklist.md)
against a live site. Automated unit/CI proves logic and matrix execution; this
records the live end-to-end verification.

- **Date:** 2026-07-24
- **Environment:** WordPress Studio site, WP 7.0.2 / PHP 8.5.8, SQLite, object cache: none (default) except where a Redis backend is noted
- **Plugin:** WP Sudo 4.8.0, policies at defaults (`cli`, `rest_app_password` = `limited`)
- **Method:** headless — WP-CLI + App-Password/cookie REST via curl, plus one in-app-browser pass for the challenge UI (2FA email lockout bypassed by testing surfaces that don't use interactive login)

## REST gating

| Check | Surface | Expected | Result |
|-------|---------|----------|--------|
| `user.change_email` — `POST`/`PUT`/`PATCH /wp/v2/users/{id}` | App Password | 403 | ✅ `403 sudo_blocked` (all 3) |
| `user.change_email` — `POST /wp/v2/users/me` | App Password | 403 | ✅ `403 sudo_blocked` |
| email no-op (unchanged) | App Password | pass | ✅ `200` |
| `user.promote` — `POST /wp/v2/users/{id}` | App Password | 403 | ✅ `403 sudo_blocked` (role unchanged) |
| critical settings — `POST /settings {"url"}` (siteurl alias) | App Password | 403 | ✅ `403 sudo_blocked` |
| critical settings — `POST /settings {"email"}` (admin_email alias) | App Password | 403 | ✅ `403 sudo_blocked` |
| non-critical — `POST /settings {"title"}` | App Password | pass | ✅ `200` |

## Stolen-cookie path (the literal threat model)

Replayed a session-token-bound logged-in cookie + REST nonce (auth without a sudo
session) via curl:

| Check | Expected | Result |
|-------|----------|--------|
| `GET /users/me` (auth sanity) | 200 | ✅ `200` |
| `POST /settings {"url"}` | 403 | ✅ `403 sudo_required` |
| `POST /users/me {"email"}` | 403 | ✅ `403 sudo_required` (admin email intact) |

**Two-code distinction confirmed:** App-Password policy path returns `sudo_blocked`
(hard block); cookie/interactive path returns `sudo_required` (challenge offered).

## Browser UI challenge (in-app browser)

Login → gated profile email change with no active sudo session:

- Challenge page rendered: **"Confirm Your Identity — Change email address — please enter your password."** ✅
- Entered password → Confirm → **"Reauthentication complete. For your security, this request was not replayed automatically. Review the form and submit it again."** ✅ — `stash_no_replay()` anti-replay working in the UI; the email change did **not** auto-apply.

## Role/capability lockdown audit

| Check | Result |
|-------|--------|
| Inert before opt-in; `manifest generate` + `diff` after opt-in | ✅ baseline clean |
| **P1** — `manage_options` added to non-admin `editor` role | ✅ `diff`: "Privileged role definition changed: editor" |
| Unauthorized principal (via drift) | ✅ detected |
| Site Health during drift / after remediation | ✅ `critical` → `good` |
| Sweep fires `wp_sudo_role_drift_detected` | ✅ `has_drift=yes` |
| Remediate → `diff` | ✅ back to clean |

## Cache-bypass (F3 / F4)

Injected drift via **raw `$wpdb` writes** (no `update_option` / `update_user_meta` →
object cache not invalidated — the audit's actual threat):

| Check | Cache backend | Result |
|-------|---------------|--------|
| **F3** role defs — cached `get_option()` / `wp_roles()` vs `current_role_definitions()` | default | cached **STALE**, fix **FRESH** ✅ |
| **F4** user query — cached `get_users()` vs `cache_results=>false` | default | cached **STALE**, fix **FRESH** ✅ |
| **F3 persistent, cross-process** — prime in one process, raw-write in another, read in a third | **Redis** (Predis) | stale persisted in Redis across processes; fix read fresh from DB ✅ |

## Notes / incidental findings

- **`wp user create` is CLI-gated** (`requires sudo… cannot be performed via WP-CLI`); `wp cap add` is not — consistent with the audit existing to catch role drift the gate does not prevent.
- Site enforces a **15-char minimum password** (`wpyeg_password_too_short`), which returns `400` on REST password params before the gate — so the REST password-gate row is confirmed by rule-family (sibling of `user.promote`, which gated) + the CLI block, not a direct 403.

## Not covered here

- Multisite network-wide audit (single-blog MVP scope — [#219](https://github.com/dknauss/Sudo/issues/219)).
- Alert-bridge push on role drift ([#222](https://github.com/dknauss/Sudo/issues/222)).
