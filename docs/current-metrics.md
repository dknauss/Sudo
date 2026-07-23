# Current Metrics (Canonical)

This file is the single source of truth for current repository counts.

Last verified: 2026-07-23
Verification environment: local workspace, PHP 8.x

## Test Metrics

| Metric | Value | Verification |
|---|---:|---|
| Unit tests | 1,079 tests | `composer test:unit` |
| Unit assertions | 3,225 assertions | `composer test:unit` |
| Integration tests in suite | 234 test methods | `rg -c "function test" tests/Integration/*.php | awk -F: '{sum+=$2} END{print sum}'` |
| Unit test files | 34 | `ls tests/Unit/*.php | wc -l` |
| Integration test files | 31 | `ls tests/Integration/*.php | wc -l` |

## Size Metrics

| Metric | Value | Verification |
|---|---:|---|
| Production PHP lines (`includes/`, `wp-sudo.php`, `uninstall.php`, `mu-plugin/`, `bridges/`) | 18,795 | `find ./includes ./wp-sudo.php ./uninstall.php ./mu-plugin ./bridges -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Tests PHP lines (`tests/`) | 38,773 | `find ./tests -type f -name "*.php" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |
| Production + tests PHP lines | 57,568 | sum of the two rows above |
| Test-to-production ratio | 2.06:1 | `38773 / 18795` |
| Total repo PHP lines (excluding `vendor/`, `vendor_test/`, `.tmp/`, `.git/`, `.claude/`) | 58,709 | `find . -type f -name "*.php" ! -path "*/vendor/*" ! -path "*/vendor_test/*" ! -path "*/.tmp/*" ! -path "*/.git/*" ! -path "*/.claude/*" -print0 | xargs -0 wc -l | tail -1 | awk '{print $1}'` |

## Footprint & Performance

Persistent storage is near-static; the only growing table self-prunes. Per-request
DB cost is characterized from a full static hook sweep (2026-07-05), not runtime
profiling — a precise Query Monitor figure would confirm but not change the shape.

### Persistent storage

| Item | Value | Verification |
|---|---:|---|
| Persistent options | 3 | `wp_sudo_settings` (config, `Admin::OPTION_KEY`), `wp_sudo_activated`, `wp_sudo_governance_mode`: `grep -rhoE "wp_sudo_(settings\|activated\|governance_mode)\b" includes/ wp-sudo.php \| sort -u \| wc -l` → 3 |
| Custom tables | 1 | `{base_prefix}wpsudo_events` (dashboard activity log; one network-wide table on multisite): `grep -oE "wpsudo_events" includes/class-event-store.php \| sort -u \| wc -l` → 1 |
| Events retention (default) | 14 days, batch-pruned | `grep -c 'function prune( int \$days = 14' includes/class-event-store.php` → 1 |

Per-user meta (`_wp_sudo_*`: session token/expiry/bind + ephemeral rate-limit counters)
and transients (request stash, rate-limit keys, active-count cache) are written only for
users who hold a session or trigger gating; they self-expire and are removed on uninstall.

### Per-request cost (front-end)

WP Sudo is an event-gate — no per-page DB work. The only always-on per-request hooks are
three `init` callbacks (`enforce_editor_unfiltered_html`, `gate_non_interactive`,
`handle_deactivate`); `map_meta_cap` returns immediately for any non-governance capability.

| Context | Added DB queries | Basis |
|---|---:|---|
| Front-end visitor (logged out) | 0 | No admin bar; the `init` hooks do constant/global + cached-role checks only |
| Front-end, logged-in user | ≤ 1 (cached user-meta) | Admin-bar `Sudo_Session::is_active()` — static per-request cache, else one `get_user_meta` |
| Non-gated admin page | negligible | In-memory rule matching + the same cached session check |
| Gated action | bounded (grant/stash/rate-limit) | Only on the specific dangerous action, not on browsing |

Re-derive the hook inventory (confirm the always-on set is only the 3 `init` hooks):

```
grep -rhoE "add_(action|filter)\(\s*'[^']+'" includes/ wp-sudo.php bridges/ mu-plugin/ | grep -oE "'[^']+'" | sort | uniq -c | sort -rn
```

No production dependencies, no build step: `jq '.dependencies // {} | length' package.json` → `0`.

## Architectural Facts

Volatile counts that change when features ship. Every doc referencing these
numbers MUST point to or be verified against this table — never hardcode
the count in prose without a verification command.

| Fact | Value | Verification | Last changed |
|---|---:|---|---|
| Request surfaces | 7 | `printf '%s\n' admin ajax rest cli cron xmlrpc wpgraphql | wc -l` | v2.5.0 (WPGraphQL) |
| Gated rules (single-site) | 27 | `grep "'id'" includes/class-action-registry.php \| grep -v network \| grep -v "rule\[" \| wc -l` | v3.2.0 |
| Gated rules (multisite) | 8 | `grep "'id'" includes/class-action-registry.php \| grep -c "network"` | v3.2.0 |
| Gated rules (total) | 35 | `grep "'id'" includes/class-action-registry.php \| grep -v "rule\[" \| wc -l` | v3.2.0 |
| Help tabs | 6 | `grep -c -- "->add_help_tab(" includes/class-admin.php` | v3.2.0 |
| Audit hooks | 20 | `python3 - <<'PY'\nimport pathlib, re\nhooks = set()\nfor path in pathlib.Path('includes').glob('class-*.php'):\n    hooks.update(re.findall(r\"do_action\\(\\s*'([^']+)'\", path.read_text()))\nhooks.discard('wp_sudo_render_two_factor_fields')\nprint(len(hooks))\nPY` | v4.1.0 (wp_sudo_escalation_blocked) |
| Settings fields (base) | 6 | 1 numeric (duration) + 1 preset chooser + 4 policy dropdowns (REST, CLI, Cron, XML-RPC) | v3.0.0 |
| Settings fields (with WPGraphQL) | 7 | +1 conditional WPGraphQL policy dropdown | v3.0.0 |
| E2E tests | 81 | `npx playwright test --config tests/e2e/playwright.config.ts --list` (verified 2026-07-15; 17 active `editor-reauth.spec.ts` in-editor-modal tests — EDITOR-01 modal-cancel→link-out, EDITOR-02 batch detect-and-surface (Q2), EDITOR-03/05 no-safe-URL degradation (C4), EDITOR-06 modal grant, EDITOR-07 rejected-submission recovery, EDITOR-08 2fa_pending links-out, EDITOR-09 stale-nonce refresh recovery, EDITOR-10 owner-scoped concurrent re-dispatch + EDITOR-11 shared-modal cancel fallback (Q3), EDITOR-12 modal never echoes the rule label (Q4), EDITOR-13 non-capable 2FA skips the modal + EDITOR-14 C4 wins over the 2FA skip, and Milestone B in-modal 2FA — EDITOR-15 modal-capable 2FA completes the second factor in place + generic serialize + neutralized native submit + re-dispatch, EDITOR-16 link_out partial falls back to snackbar, EDITOR-17 pending-expired 403 mid-2FA links out — verified green against a live WP Sudo env, no remaining `test.fixme`) | v4.0.0 |

### Files that reference these counts

When any fact above changes, update this table first, then grep for the old
value across these known consumers:

- `readme.md`, `readme.txt` — plugin description
- `docs/abilities-api-assessment.md` — Gate surfaces table
- `docs/ui-ux-testing-prompts.md` — settings page field count
- `docs/developer-reference.md` — hook signatures, audit hooks
- `tests/MANUAL-TESTING.md` — gated rules count
- `docs/ROADMAP.md` — unit test coverage notes

## CI Matrix Snapshot

Source: `.github/workflows/phpunit.yml`, `.github/workflows/e2e.yml`, `.github/workflows/e2e-nginx.yml`, `.github/workflows/e2e-sqlite.yml`, `.github/workflows/compat-wordpress-minors.yml`

- Unit test matrix: PHP 8.2, 8.3, 8.4
- Integration matrix: trimmed from 10 to 7 lanes — PHP 8.2/8.3 across WordPress 6.4, 6.7, and 7.0; five single-site MySQL 8.0 lanes, one multisite MySQL 8.0 representative lane (PHP 8.3 / WP 7.0), and one MariaDB LTS lane
- Scheduled compat sweep: PHP 8.2 on WordPress 6.4, 6.5, and 6.6, plus MariaDB LTS overlap lanes on WordPress 6.4 and 6.5
- Browser stack smoke workflows: Apache + MariaDB (`wp-env`), nginx + php-fpm + MariaDB, and Playground SQLite

## Verification Notes

- `composer test:unit` passed on 2026-07-06 (unit totals in the Test Metrics table above). Block-editor reauth, **Increment 2, Task 2** (grant plumbing; PHP-only slice — modal/2FA/re-dispatch JS deferred to a wp-env session): `Plugin::enqueue_editor_reauth()` now localizes `wpSudoEditorReauth` (grant nonce = the single `Challenge::NONCE_ACTION` `wp_sudo_challenge`, C1; AJAX action names; current-site `admin_url('admin-ajax.php')`, never network-admin, so a subsite editor posts its grant locally — design-review obj. 6), loaded even when a session is active (C2). New AJAX endpoint `Challenge::handle_ajax_refresh_nonce()` (`wp_sudo_refresh_grant_nonce`) re-mints a fresh grant nonce for an editor open past the ~24 h nonce lifetime — the primary staleness fix, since `check_ajax_referer` hard-`wp_die`s and can't be caught client-side (design-review obj. 2); logged-in-only, grants nothing (+3 unit tests).
- `composer test:unit` passed on 2026-07-05 (unit totals in the Test Metrics table above). Block-editor reauth, Increment 1 (link-out snackbar): `Plugin::enqueue_editor_reauth()` enqueues a build-free `apiFetch` middleware (`admin/js/wp-sudo-editor-reauth.js`) on every block/site-editor screen — including when a sudo session is active at page load (condition C2, revised: the long-lived editor SPA outlives the short session) — that turns a gated action's `sudo_required` REST 403 into an in-editor "Reauthenticate" snackbar linking out to the challenge page. Notify + link-out only (no in-editor grant/modal yet); message stays generic (no rule-label echo). The `challenge_url` is validated (same-origin http(s)) before it is offered — a missing, malformed, cross-origin, or `javascript:` URL degrades to a plain message with no action (the cookie-auth branch always carries a valid same-origin URL; the no-action path is a defensive safety net) (+3 unit tests; +5 E2E in `editor-reauth.spec.ts`).
- `composer test:unit` passed on 2026-07-05 (`997 tests`, `3032 assertions`). Harmonized user identity across the dashboard Session Activity widget and the Settings → Sudo Access tab: both now lead with the user's **full real name** (primary), with the **username secondary** (linked to user-edit when permitted), an avatar, and translated role chip(s). A shared `WP_Sudo\User_Identity` helper (`primary_name()` / `role_labels()`) is the single source of truth so the two surfaces cannot drift; role names go through `translate_user_role()`. Also fixed a latent no-op: `get_avatar()`'s force key is `force_display`, not `force` (the widget's avatar previously honored the site "Show Avatars" setting instead of always rendering); the stale Psalm baseline entry for it was removed (+11 tests: 6 `UserIdentityTest`, 5 Access-tab/widget cell + permission-branch).
- `composer test:unit` passed on 2026-07-05 (`986 tests`, `3003 assertions`). Access-tab capability-holder table: each capability now renders as its own `wp-sudo-cap-item` row showing the human-readable label instead of the raw slug, with the Revoke control paired directly to it (responsive at narrow widths, no orphaned links); the slug moves to a tooltip + `screen-reader-text` span, and the Grant Capability dropdown drops the parenthetical slug from its visible option text. The revoke JS now removes the whole capability item container rather than a position-dependent `<code>` sibling. Each Revoke button also carries a capability-specific `aria-label` ("Revoke <label> capability") so screen-reader users tabbing through otherwise-identical controls get the capability context, and `get_cap_label()` now returns its labels through the text domain so localized installs get translated capability names (the label is the primary visible text now that the slug is hidden) (+1 test).
- `composer test:unit` passed on 2026-07-04 (`985 tests`, `2993 assertions`). Phase 25 (Governance Coverage Panel Fix, GCOV-01/02): the Access-tab coverage panel names the context-correct capability (manage_network_options on multisite) and excludes multisite super admins from the "cannot access" list while still listing genuinely drifted non-super-admin network operators (+3 tests).
- `composer test:unit` passed on 2026-07-04 (`982 tests`, `2986 assertions`). Session revocation moved from the Users-list toolbar button + admin-post interstitial to a native "Revoke sudo sessions" bulk action (one rate slot per batch, self-skip, per-user audit fires with the `users_list_bulk_action` reason); the Sudo Active badge count transient is now invalidated on session grant/teardown; the stale `wp_sudo_revoke_session` AJAX reference was scrubbed from the registry. External-review hardening: bulk handling moved behind a nonce-verified `load-users.php` interceptor (core does not nonce-check custom users.php bulk actions) and both revocation paths gained a current-site membership guard placed before the liveness check.
- `composer test:unit` passed on 2026-07-04 (`968 tests`, `2948 assertions`). The Event_Recorder now subscribes to `wp_sudo_session_revoked` and stores `session_revoked` event rows, and the dashboard widget renders human-readable labels for `session_revoked` ("Revoked") and `escalation_blocked` ("Escalation") with distinct pill styling (+4 tests).
- `composer test:unit` passed on 2026-07-01 (`956 tests`, `2888 assertions`). Phase 24 (Session Revocation UI) added the shared revoke-all/liveness methods, the factored revocation core, the Users-list row action + revoke-all interstitial + distinct result notices, and removed the Access-tab session-revoke button and the orphaned AJAX path. Follow-up fixes preserve the settings `tab` across a sudo reauth: on multisite network settings save, and single-site via a shared `wp_sudo_build_challenge_url()` helper that rawurlencodes the nested return_url across all challenge-URL builders + the admin-bar deactivate link (+11 tests, incl. a faithful add_query_arg test stub).
- `composer lint` passed on 2026-06-30.
- Static analysis passed on 2026-06-30 (PHPStan L6 `[OK] No errors`; Psalm `No errors found!`, baseline current).
- `composer verify:metrics` passed on 2026-06-30 (after this update).
- `npx playwright test --config tests/e2e/playwright.config.ts --list` reported `69 tests in 16 files` on 2026-07-05 after `editor-reauth.spec.ts` (Block-editor reauth, Increment 1 — 5 tests) was added.
- Plugin Check CI passed on 2026-06-14 against a clean production dist; warning triage remains a follow-up.
- `composer test:integration` passed locally on 2026-06-27: single-site `213 tests`, `728 assertions`, `14 skipped`, `0 failures`; multisite (`WP_MULTISITE=1 composer test:integration`) `213 tests`, `725 assertions`, `8 skipped`, `0 failures`.

## Update Procedure

1. Re-run all verification commands listed above.
2. Update this file first.
3. Run `composer verify:metrics` to confirm the document matches live counts.
4. Keep other docs referencing this file instead of duplicating current counts.
