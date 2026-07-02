---
status: root_cause_confirmed
trigger: "settings-tab-lost-on-reauth-replay"
created: 2026-07-01T00:00:00Z
updated: 2026-07-01T04:00:00Z
---

## Resolution (2026-07-01, corrected)

- **MULTISITE: RESOLVED.** Root cause = `Admin::handle_network_settings_save()` hardcoded its
  post-save redirect and ignored `wp_get_referer()`, dropping `&tab=`. Fixed in commit `0ef45f1`
  ("fix: preserve settings tab on multisite network settings save (#132)"): redirect is built from
  `network_admin_url('settings.php')` + `page`, lifting ONLY a `VALID_TABS`-validated `tab` from a
  page-scoped referer. Covered by `test_handle_network_settings_save_redirect_preserves_tab_query_arg`
  and sibling security tests (tests/Unit/AdminTest.php). Unaffected by the single-site finding below —
  different mechanism, different code path, already shipped and verified.

- **SINGLE-SITE: CONFIRMED, ROOT CAUSE FOUND (corrected from prior FALSIFIED conclusion).**
  A prior session in this file concluded the single-site report was an unreproducible "WordPress
  Playground iframe/Service-Worker navigation artifact." **That conclusion was wrong** and is
  retracted below. Live evidence (see Current Focus) proved the defect is real and upstream in
  WP-Sudo's own PHP. This session found the actual mechanism: it is **not** in
  `get_current_admin_url()` or `wp_validate_redirect()` (both are genuinely tab-safe, as the prior
  session proved) — it is in **`add_query_arg()` itself**, which every prior investigating session
  mis-modeled in its test stubs. See root_cause below for the full mechanism and the per-trigger
  table.

## Current Focus

hypothesis: [CORRECTED, this session] The prior session's simulation script (and every existing
   unit test stub for `add_query_arg()` in this suite) used PHP's native `http_build_query()` to
   emulate WordPress's `add_query_arg()`. `http_build_query()` URL-encodes its output by default.
   Real WordPress `add_query_arg()` does NOT: it calls `build_query()`, which calls
   `_http_build_query( $data, null, '&', '', false )` — the trailing `false` disables
   `urlencode()` for every value written into the query string via the function's `$key => $value`
   array argument (as opposed to values already present in the base URL's own query string, which
   *are* re-encoded via `urlencode_deep()` after being parsed back out with `wp_parse_str()`). Every
   call site in WP-Sudo that builds `return_url`/`redirect_to` places a FULL URL — itself already
   containing a query string with its own `&` — as a raw VALUE in the array passed to
   `add_query_arg()`. Because that value is never encoded, its embedded `&tab=access` (or any other
   nested query param) is emitted as a literal `&` in the final output URL, becoming a NEW
   top-level sibling query parameter instead of staying part of the value. When the browser then
   parses that URL's query string into PHP's `$_GET` on the next request, `$_GET['return_url']` (or
   `$_GET['wp_sudo_redirect_to']`) is truncated at the first `&` and everything after it — including
   `tab=access` — is silently lost. This is exactly Mechanism (B) from the reinvestigation brief:
   "return_url built from the full URL but appended to the challenge URL WITHOUT proper
   URL-encoding."
test: (1) Pulled add_query_arg()/build_query()/_http_build_query() from wordpress-develop trunk
   (wp-includes/functions.php, verified via raw.githubusercontent.com this session) and confirmed
   `build_query()` passes `$urlencode = false` to `_http_build_query()`, and that new `$key => $value`
   array entries are written via `$k . '=' . $v` (line "} else { array_push( $ret, $k . '=' . $v ); }"),
   i.e. completely unencoded, whereas parsed-from-existing-query values go through
   `urlencode_deep()` first (functions.php ~line 1183, inside `add_query_arg()` itself, BEFORE the
   new args are merged in). (2) Wrote a byte-for-byte PHP port of the real function
   (scratchpad/sim2.php) and ran it against the EXACT reported scenario
   (`Plugin::enqueue_shortcut()` on `options-general.php?page=wp-sudo-settings&tab=access`) end to
   end: return_url construction -> add_query_arg() merge -> browser round-trip via parse_url()/
   parse_str(). (3) Added a new shared test helper, `TestCase::stub_faithful_add_query_arg()`
   (tests/TestCase.php), that is a byte-for-byte port of the same real WP function (not
   http_build_query()-based), and used it to write 4 new failing PHPUnit tests exercising the real
   production code paths directly (not just a standalone simulation): `Plugin::enqueue_shortcut()`,
   `Gate::intercept()` -> `challenge_admin()`, `Gate::render_gate_notice()` (structurally identical
   to `render_blocked_notice()`), and `Admin_Bar::admin_bar_node()`.
expecting: If the hypothesis is correct, each of these 4 real-code-path tests, using genuinely
   faithful `add_query_arg()` semantics, will fail with the tab/query-string truncated at the first
   `&`. If wrong (e.g. if the prior session's Playground-artifact theory were correct instead), the
   tests would pass, because the defect would not be reproducible via pure PHP-level unit testing at
   all.
found: CONFIRMED for all 4 trigger points. Every test failed exactly as predicted, and passed
   (`composer test:unit`: 949 tests, 945 pass, only these 4 new tests fail, zero regressions) when
   run as part of the full existing suite — proving the new faithful stub is inert for every
   pre-existing test and the failures are specific to this defect.
   - `tests/Unit/PluginTest.php::test_enqueue_shortcut_challenge_url_preserves_tab_query_arg_from_access_tab`
     — FAILS: `Failed asserting that '.../options-general.php?page=wp-sudo-settings' contains "tab=access"`.
   - `tests/Unit/GateTest.php::test_intercept_admin_challenge_redirect_preserves_tab_query_arg`
     — FAILS: identical truncation on the gated-action (Save Changes / any admin gated request) bounce.
   - `tests/Unit/GateTest.php::test_gate_notice_challenge_link_preserves_query_string_on_current_page`
     — FAILS: identical truncation on the persistent gate-notice / one-shot blocked-notice
     "Confirm your identity" link (`paged=2` dropped from a `plugins.php?...` query string).
   - `tests/Unit/AdminBarTest.php::test_admin_bar_node_deactivate_url_preserves_tab_query_arg`
     — FAILS: identical truncation on the admin-bar "Sudo: M:SS" countdown node's deactivate link
     (`REDIRECT_PARAM`), a 4th, previously-unidentified trigger with the exact same mechanism.
implication: This is ONE shared root cause (a systemic misuse of `add_query_arg()`'s
   "values are expected to be encoded appropriately with urlencode()" contract — which the function's
   own docblock explicitly warns about) manifesting independently at every WP-Sudo call site that
   nests a full URL as an array value passed to `add_query_arg()`. It is NOT a WordPress Playground
   artifact, NOT a JS navigation issue, and NOT specific to the keyboard shortcut — the same defect
   pattern recurs at 4 distinct trigger points, all PHP-side, all in `includes/`. The reason 3 prior
   investigating sessions in this file failed to find it: every one of their "real semantics" test
   stubs for `add_query_arg()` (in this file's own historical notes, in `tests/Unit/AdminTest.php`'s
   `stub_real_add_query_arg_semantics()`, in `tests/Unit/GateTest.php`'s
   `mock_rest_challenge_url_helpers()`, and in more than a dozen other call sites across the test
   suite) used PHP's native `http_build_query()`, which encodes by default — silently "fixing" the
   exact bug under test before it could ever be observed. The one test that came closest
   (`test_enqueue_assets_cancel_url_preserves_tab_query_arg_from_access_tab`,
   tests/Unit/ChallengeTest.php) tested the WRONG HALF of the pipeline: it seeded `$_GET['return_url']`
   directly with an already-correct, already-tabbed value and only tested what `Challenge` does with
   it downstream (which is fine) — it never exercised how `return_url` is embedded into the
   `challenge_url` upstream (where the corruption actually happens).
next_action: None further needed for root-cause confirmation. Per this session's mode
   (`goal: reproduce_and_root_cause_ONLY`), NO production fix has been implemented. See Resolution
   below for the consolidated fix direction the orchestrator's Pre-Implementation Design Review
   should evaluate.

---

hypothesis: MULTISITE root cause (handle_network_settings_save hardcoded redirect) remains CONFIRMED — unchanged.
   SINGLE-SITE, SUCCESS-REDIRECT PASS (historical session): focused re-verification of the ONE remaining untested
   angle — the post-auth SUCCESS redirect for a PROACTIVE reauth (Ctrl/Cmd+Shift+S, or session-only
   activation) with NO stashed action, single-site, tab=access. Re-traced every hop of
   `return_url` -> `cancelUrl` -> `window.location.href` against REAL WordPress core source (not the
   prior session's test stubs) end-to-end: `Plugin::get_current_admin_url()` / `Gate::get_current_admin_url()`
   (REQUEST_URI-faithful) -> `add_query_arg()` (real WP source pulled from wordpress-develop trunk,
   INCORRECTLY believed at the time to confirm `urlencode_deep()` + `build_query()` use a literal `&`
   separator "safely" — this session found that belief was wrong: build_query's separator IS a literal
   `&`, but that is exactly the problem, because new values are never urlencoded before being joined
   with it) -> GET round-trip into `$_GET['return_url']` (the historical session's own manual
   simulation script used `parse_str`/`http_build_query`, which does NOT reproduce real
   `add_query_arg()` behavior — see correction above) -> `Challenge::enqueue_assets()`'s `esc_url_raw()`
   -> `wp_validate_redirect()` -> `wp_sanitize_redirect()`. NOTE: this pass's conclusion that "every hop
   is provably tab-preserving using REAL implementations" was INCORRECT specifically at the
   `add_query_arg()` hop — every other hop's analysis (esc_url_raw, wp_validate_redirect,
   wp_sanitize_redirect) remains correct and is NOT part of the defect.
test: Ran the existing `test_enqueue_assets_cancel_url_preserves_tab_query_arg_from_access_tab` test
   (tests/Unit/ChallengeTest.php) — this test seeds `$_GET['return_url']` directly with an
   already-tabbed value and therefore cannot detect the upstream `add_query_arg()` defect (it tests
   Challenge's consumption of an already-correct value, not the construction of that value). This is
   why it passed despite the real bug existing.
expecting: n/a (historical).
next_action: See corrected Current Focus above.

## Symptoms

expected: After passing the challenge, the user returns to the same tabbed settings screen they were on, with &tab=access (or whatever tab) preserved.
actual: The user lands on the bare settings page (?page=wp-sudo-settings) with no &tab= — dumped back to the default/first tab.
errors: None (functional, not an error).
reproduction: Be on options-general.php?page=wp-sudo-settings&tab=access; press Ctrl/Cmd+Shift+S (or trigger any gated action, or wait for the persistent gate notice / one-shot blocked notice on a gated page with a query string, or use the admin-bar sudo countdown's deactivate link from a tabbed/query-string page); pass the reauth challenge (or deactivate); observe you return to the tab-less/query-less URL.
started: Present in current main (v4.5, Phase 24 shipped). Not previously reported; the tabbed settings UI + gate/stash/replay predate Phase 24.

## Eliminated

- hypothesis: [RE-VERIFIED] Single-site Settings tab save (`<form action="options.php">`, `options.wp_sudo` rule) loses the tab somewhere in the stash → challenge → replay round-trip.
  evidence: `render_access_tab()` (includes/class-admin.php:1844-1944) contains ZERO `<form>` elements — only AJAX buttons. The Settings-tab save form only renders in the `default: // 'settings'` branch, so it cannot be what the user submitted while `tab=access` was showing. Separately, the full single-site Settings-tab save-and-replay chain (`Request_Stash::build_original_url()`/`get_return_url()` -> `_wp_http_referer` replay allowlist -> `Challenge::build_replay_response_data()`'s POST-replay branch -> WP core's own `options.php` `wp_get_referer()`-based redirect -> `wp_validate_redirect()`) was traced line-by-line against real WordPress core source and confirmed tab-safe: this path does NOT use the `add_query_arg(array $args, $base_url)` pattern that causes the confirmed defect — it uses `wp_get_referer()`'s value directly as the redirect target (a `$location`, not a nested array value), so there is no place for the "unencoded nested value" mechanism to apply.
  timestamp: 2026-07-01T00:00:00Z (re-verified 01:00:00Z; conclusion unchanged, still correctly eliminated)

- hypothesis: Access tab (grant/revoke capability buttons, `options.wp_sudo_access` rule) goes through the stash → challenge → replay round-trip and loses the tab there.
  evidence: `Admin::render_access_tab()` renders grant/revoke as AJAX buttons; `sendAccessAction()` (admin/js/wp-sudo-admin.js) handles a gate rejection purely as an inline error message and never redirects to the challenge page. Access-tab actions never enter the stash/challenge/replay pipeline in the browser.
  timestamp: 2026-07-01T00:00:00Z

- hypothesis: [RETRACTED — see correction] "WordPress Playground iframe + Service-Worker navigation artifact" affecting JS `window.location.href` assignments.
  evidence: This session proved the corruption happens entirely server-side, in PHP, before `wp_localize_script()` ever hands `cancelUrl`/`challengeUrl` to the browser. `window.wpSudoChallenge.cancelUrl` (and `wpSudoShortcut.challengeUrl`) are ALREADY tab-less at the moment PHP generates them — confirmed by 4 new unit tests exercising the actual production PHP methods (`Plugin::enqueue_shortcut()`, `Gate::challenge_admin()`, `Gate::render_gate_notice()`, `Admin_Bar::admin_bar_node()`) with zero browser, iframe, or Service Worker involved. Playground is not implicated; this is a plain PHP `add_query_arg()` usage defect that would reproduce identically on any single-site WordPress install, in any browser, with no plugins running JS at all (the countdown/deactivate case is a pure PHP round trip: `add_query_arg()` writes the URL, a later request reads `$_GET` back).
  timestamp: 2026-07-01T04:00:00Z

- hypothesis: `sendAccessAction()` (admin/js/wp-sudo-admin.js) redirects to a `challenge_url` on a `sudo_required` AJAX error, and that redirect drops the tab.
  evidence: Re-read `sendAccessAction()` end-to-end (admin/js/wp-sudo-admin.js:274-351); the error branch only sets `resultEl.textContent`/`window.alert` — no `challenge_url` field is read anywhere, no redirect occurs.
  timestamp: 2026-07-01T01:00:00Z

- hypothesis: `wp_validate_redirect()` / `wp_sanitize_redirect()` strip or corrupt the `&tab=` query argument.
  evidence: Both real functions (wordpress-develop trunk, wp-includes/pluggable.php:1553-1732) explicitly allow `&`, `=`, `:` in `wp_sanitize_redirect()`'s character-class filter, and `wp_validate_redirect()` returns `$location`/`$fallback_url` verbatim without rewriting the query string in any branch. These functions are NOT part of the defect — they correctly preserve whatever query string reaches them. The defect happens one step earlier, in `add_query_arg()`, before these functions ever see the URL.
  timestamp: 2026-07-01T01:00:00Z (re-confirmed 2026-07-01T04:00:00Z with the corrected root cause)

## Evidence

- timestamp: 2026-07-01T00:00:00Z
  checked: includes/class-admin.php `Admin::handle_network_settings_save()` (multisite).
  found: Hardcoded redirect, never reads `wp_get_referer()`/`$_POST['_wp_http_referer']`.
  implication: Multisite root cause — separate mechanism from the single-site finding, already fixed (commit 0ef45f1).

- timestamp: 2026-07-01T04:00:00Z
  checked: wp-includes/functions.php `add_query_arg()`/`build_query()`/`_http_build_query()`, pulled live from wordpress-develop trunk (raw.githubusercontent.com) this session.
  found: `build_query( $data )` calls `_http_build_query( $data, null, '&', '', false )` — the final `false` disables `urlencode()`. Inside `_http_build_query()`, when `$urlencode` is false, each pair is emitted as `$k . '=' . $v` with NO encoding at all. Critically, `add_query_arg()` only applies `urlencode_deep()` to values that were already present in the URL's EXISTING query string (parsed via `wp_parse_str()`) — newly supplied `$key => $value` array entries are merged in via `$qs[$k] = $v` AFTER that encoding step and are never touched by it. The function's own docblock states: "Values are expected to be encoded appropriately with urlencode() or rawurlencode()." — callers are contractually responsible for pre-encoding, and WP-Sudo's call sites do not.
  implication: This is the exact, singular mechanism (Mechanism B from the reinvestigation brief) behind every trigger in the table below.

- timestamp: 2026-07-01T04:00:00Z
  checked: includes/class-plugin.php `Plugin::enqueue_shortcut()` (lines 218-224) and `get_current_admin_url()` (lines 570-585).
  found: `get_current_admin_url()` correctly builds a full, correctly-encoded current URL from `$_SERVER['REQUEST_URI']` (this part IS safe). But `enqueue_shortcut()` then does `add_query_arg( array( 'page' => 'wp-sudo-challenge', 'return_url' => $this->get_current_admin_url() ), $base_url )` — nesting that full URL (with its own `&tab=access`) as a raw array value. Empirically confirmed via a byte-for-byte PHP port of real `add_query_arg()` (scratchpad/sim2.php) and via the new failing unit test `tests/Unit/PluginTest.php::test_enqueue_shortcut_challenge_url_preserves_tab_query_arg_from_access_tab`.
  implication: CONFIRMED root cause of the reported symptom (Ctrl/Cmd+Shift+S shortcut). PHP.

- timestamp: 2026-07-01T04:00:00Z
  checked: includes/class-gate.php `Gate::challenge_admin()` (lines 2597-2623, private, reached via `intercept()` for any gated admin-UI action without an active session) and `Gate::build_session_challenge_url()` (lines 2565-2588, private, reached via `intercept_rest()`'s cookie-auth soft-block).
  found: Both build `$query_args['return_url'] = $return_url` (a full `wp_get_referer()` URL, itself already containing a query string) and pass `$query_args` into `add_query_arg( $query_args, $base_url )` — the identical vulnerable pattern. Empirically confirmed for `challenge_admin()` via the new failing unit test `tests/Unit/GateTest.php::test_intercept_admin_challenge_redirect_preserves_tab_query_arg` (drives the real `intercept()` -> `challenge_admin()` -> `wp_safe_redirect()` path end to end). `build_session_challenge_url()` was traced by direct code comparison (byte-identical construction pattern to `challenge_admin()`) rather than a dedicated new test, since it is structurally the same code shape already proven vulnerable.
  implication: CONFIRMED for the gated-action bounce (any Save/Activate/Delete-type action intercepted on the admin surface) and for the REST/AJAX soft-block's session-only challenge link. PHP.

- timestamp: 2026-07-01T04:00:00Z
  checked: includes/class-gate.php `Gate::render_blocked_notice()` (lines 2764-2786) and `Gate::render_gate_notice()` (lines 2797-2850).
  found: Both build `$query_args['return_url'] = $current_url` (from `Gate::get_current_admin_url()`, lines 3037-3052 — itself correctly built, same as Plugin's version) and pass `$query_args` into `add_query_arg()` — identical vulnerable pattern, byte-identical code shape between the two methods. Empirically confirmed for `render_gate_notice()` via the new failing unit test `tests/Unit/GateTest.php::test_gate_notice_challenge_link_preserves_query_string_on_current_page` (a `plugins.php?plugin_status=active&paged=2` query string is truncated to `plugin_status=active` in the rendered "Confirm your identity" link's `return_url`). `render_blocked_notice()` was not given its own dedicated new test because it is textually identical in construction (same `$query_args`/`add_query_arg()` shape) — the `render_gate_notice()` test is dispositive for both.
  implication: CONFIRMED for the persistent gate notice (shown on every load of a gated page without an active session) and the one-shot blocked-transient notice. These fire on `themes.php`/`theme-install.php`/`plugins.php`/`plugin-install.php`, not directly on `options-general.php?page=wp-sudo-settings`, so they are not literally how the user reproduced THIS report's exact tab, but they share the identical defect and will drop any query string (tab, pagination, filters, etc.) on those pages. PHP.

- timestamp: 2026-07-01T04:00:00Z
  checked: includes/class-admin-bar.php `Admin_Bar::admin_bar_node()` (lines 82-94) — the "Sudo: M:SS" countdown node's deactivate link, and `handle_deactivate()` (lines 122-195) which reads `$_GET[REDIRECT_PARAM]` back.
  found: `admin_bar_node()` builds `add_query_arg( array( DEACTIVATE_PARAM => '1', REDIRECT_PARAM => $current_url ), admin_url() )`, nesting `$current_url` (from `self::current_url()`, itself correctly built) as a raw array value — the identical vulnerable pattern, now confirmed at a 4th, previously unidentified trigger point. Empirically confirmed via the new failing unit test `tests/Unit/AdminBarTest.php::test_admin_bar_node_deactivate_url_preserves_tab_query_arg`. Note: the EXISTING test `test_admin_bar_node_shows_for_active_session()` cannot detect this because it (a) hand-fabricates the entire `add_query_arg()`/`wp_nonce_url()` return chain via `Mockery::andReturn()` with a manually pre-percent-encoded string, and (b) uses a REQUEST_URI with no query string at all (`/sample-page/`), which cannot expose a "nested `&` becomes a sibling param" bug by construction.
  implication: CONFIRMED — a 4th, independent trigger for the same root cause: clicking the admin-bar "Sudo: M:SS" countdown to deactivate a session from any tabbed/query-string admin page (including `options-general.php?page=wp-sudo-settings&tab=access` itself) loses the intended post-deactivation return page's tab/query string. PHP.

- timestamp: 2026-07-01T04:00:00Z
  checked: Whether the existing test suite could have caught any of this, i.e. why 3 prior debugging sessions in this file concluded "no defect found."
  found: Every "real add_query_arg semantics" stub across the ENTIRE test suite (`tests/Unit/AdminTest.php::stub_real_add_query_arg_semantics()`, `tests/Unit/GateTest.php::mock_rest_challenge_url_helpers()`, and more than a dozen inline `Functions\when('add_query_arg')->alias(...)` closures across AdminTest.php, GateTest.php, and elsewhere) uses PHP's native `http_build_query()` to emulate the WordPress function. `http_build_query()` URL-encodes every value by default — the OPPOSITE of what real `add_query_arg()` does for newly-added array values. So every test in this codebase that call itself "real semantics" was actually testing an idealized, bug-free reimplementation of `add_query_arg()`, not WordPress's actual (contractually documented, intentional) behavior. `Challenge::enqueue_assets()`'s existing passing test (`test_enqueue_assets_cancel_url_preserves_tab_query_arg_from_access_tab`) additionally tested only the downstream half of the pipeline (starting from an already-correct `$_GET['return_url']`), never the upstream construction of `challengeUrl`/`return_url` where the actual corruption occurs.
  implication: This is a systemic test-authoring blind spot, not a one-off mistake, and likely affects other `add_query_arg()`-based assertions in the suite beyond the 4 confirmed triggers here (any test using `http_build_query()`-style stubs to check output correctness of a URL built by nesting another URL as a value would have the same blind spot — a broader sweep is recommended but out of scope for this root-cause-only session). Fixed going forward in this file's own new tests via `TestCase::stub_faithful_add_query_arg()`, a byte-for-byte port of the real WordPress implementation.

## Resolution

root_cause: |
  ONE shared root cause, occurring independently at FOUR confirmed PHP call sites (not a
  WordPress Playground artifact, not JS, not multisite-specific):

  WordPress core's `add_query_arg( array $args, string $url )` does NOT URL-encode values
  supplied via the `$args` array — only values already present in `$url`'s own existing query
  string get `urlencode_deep()`'d (they are parsed out via `wp_parse_str()` first, encoded, then
  the caller's NEW `$args` are merged in afterward, unencoded). `build_query()` — which
  `add_query_arg()` calls internally — explicitly passes `$urlencode = false` to
  `_http_build_query()`. This is documented plugin-author-facing WordPress behavior: the
  `add_query_arg()` docblock states "Values are expected to be encoded appropriately with
  urlencode() or rawurlencode()." — it is the CALLER's responsibility.

  Every one of the following WP-Sudo call sites nests a FULL URL — itself already containing a
  query string with its own `&`-separated pairs (e.g. `.../options-general.php?page=wp-sudo-settings&tab=access`)
  — as a raw, un-pre-encoded VALUE inside the `$args` array passed to `add_query_arg()`. Because
  that value is never encoded, its embedded `&tab=access` is emitted as a literal `&` in the
  output, becoming a NEW top-level sibling query parameter instead of remaining part of the
  value. When a later request's query string is parsed back into `$_GET` (or `$_POST`), the
  intended value is truncated at the first `&` and everything after it — including `tab=access`
  — is silently lost.

  | # | Trigger | File:Line | Mechanism | Layer |
  |---|---------|-----------|-----------|-------|
  | 1 | Ctrl/Cmd+Shift+S keyboard shortcut (session-only reauth) | `includes/class-plugin.php:218-224` (`Plugin::enqueue_shortcut()`) | B — `return_url` (full URL) nested as array value in `add_query_arg()`, unencoded | PHP |
  | 2 | Gated-action bounce (Save/Activate/Delete/any admin-surface gated request) | `includes/class-gate.php:2611-2619` (`Gate::challenge_admin()`, private, called from `intercept()`) | B — identical pattern, `return_url` from `wp_get_referer()` | PHP |
  | 3 | REST/AJAX cookie-auth soft-block session-only challenge link | `includes/class-gate.php:2582-2587` (`Gate::build_session_challenge_url()`, private, called from `intercept_rest()`) | B — identical pattern | PHP |
  | 4 | Persistent gate notice on gated pages (themes/plugins list, install screens) | `includes/class-gate.php:2831-2839` (`Gate::render_gate_notice()`) | B — identical pattern, `current_url` from `Gate::get_current_admin_url()` | PHP |
  | 5 | One-shot "blocked action" admin notice (after a blocked Access-tab AJAX action) | `includes/class-gate.php:2766-2774` (`Gate::render_blocked_notice()`) | B — byte-identical construction to #4 | PHP |
  | 6 | Admin-bar "Sudo: M:SS" countdown deactivate link | `includes/class-admin-bar.php:84-94` (`Admin_Bar::admin_bar_node()`) | B — `REDIRECT_PARAM` (full URL) nested as array value | PHP |
  | — | Settings-tab Save (`<form action="options.php">`) POST-replay | `includes/class-request-stash.php` / WP core `options.php` | SAFE — uses `wp_get_referer()`'s value directly as a redirect target `$location`, never as a nested array value inside `add_query_arg()` | PHP |
  | — | GET-stash-replay redirect (`Challenge::build_replay_response_data()`, non-redacted GET stash) | `includes/class-challenge.php:838-843` | SAFE — redirects to `$safe_url` (the stash's own `url`) directly, no nested-value `add_query_arg()` call | PHP |
  | — | Redacted/blocked-replay notice query arg | `includes/class-challenge.php:818` | SAFE — uses the SCALAR two-arg form `add_query_arg( 'key', 'value', $existing_url )`, which parses `$existing_url`'s query string via `wp_parse_str()` and re-encodes it with `urlencode_deep()`; no full URL is ever nested as a VALUE here | PHP |
  | — | `Challenge::enqueue_assets()` / `render_page()` — consuming `$_GET['return_url']` into `cancelUrl` | `includes/class-challenge.php:200-205, 260-263` | SAFE (downstream only) — `esc_url_raw()` and `wp_validate_redirect()`/`wp_sanitize_redirect()` do not strip or corrupt an already-intact query string; they cannot "fix" a `return_url` that was already truncated by an upstream `add_query_arg()` call, but they do not introduce any additional corruption of their own | PHP |
  | — | Challenge JS `window.location.href = config.cancelUrl` assignments | `admin/js/wp-sudo-challenge.js:148, 245, 251, 379` | SAFE (not implicated) — consumes an already-server-computed value; performs no URL construction of its own | JS |
  | — | Shortcut JS `window.location.href = config.challengeUrl` | `admin/js/wp-sudo-shortcut.js:26` | SAFE (not implicated) — same, purely a value consumer | JS |

  The bug is NOT the JS layer (no client-side URL construction happens anywhere in this chain —
  every `window.location.href` assignment consumes an already-fully-formed URL string handed to
  it via `wp_localize_script()`), and it is NOT specific to WordPress Playground (it reproduces
  identically in a pure PHP unit test with zero browser involvement, e.g. the admin-bar
  deactivate-link case, which round-trips entirely through `$_GET` on ordinary WordPress admin
  requests). The prior session's "environmental artifact" conclusion is retracted.

  Root-cause discovery was blocked for 3 prior sessions by a systemic test-suite blind spot: every
  "real add_query_arg() semantics" stub in this codebase (`AdminTest::stub_real_add_query_arg_semantics()`,
  `GateTest::mock_rest_challenge_url_helpers()`, and numerous inline closures) used PHP's native
  `http_build_query()`, which DOES urlencode by default — silently curing the exact defect under
  test before it could be observed. This session confirmed the mechanism first via a standalone
  byte-for-byte port of the real WordPress function (not `http_build_query()`), then via a new
  shared test helper (`TestCase::stub_faithful_add_query_arg()`) used to write 4 failing tests
  against the actual production code paths.

fix: |
  NOT IMPLEMENTED (goal: reproduce_and_root_cause_ONLY, per this session's constraints — return_url
  feeds redirects, so a Pre-Implementation Design Review is required before any production change).

  Consolidated fix direction covering all 6 affected call sites with ONE pattern change: stop
  nesting a full URL as a raw array VALUE inside `add_query_arg()`. Two equivalent options, in
  order of preference:

  1. **Pre-encode the nested value before merge (minimal diff, preferred):** wrap each
     `return_url`/`redirect_to`/`REDIRECT_PARAM` value in `rawurlencode()` before placing it in the
     `$args` array, e.g.:

         $query_args['return_url'] = rawurlencode( $return_url );
         // ... then when CONSUMING it back out of $_GET, the existing esc_url_raw()/
         // wp_unslash() call sites already correctly decode standard urlencoded values
         // (PHP's own $_GET parsing rawurldecode()s automatically) — no downstream change needed.

     This is the smallest, most mechanical fix: one `rawurlencode()` call at each of the 6 sites
     listed above (`Plugin::enqueue_shortcut()`, `Gate::challenge_admin()`,
     `Gate::build_session_challenge_url()`, `Gate::render_gate_notice()`,
     `Gate::render_blocked_notice()`, `Admin_Bar::admin_bar_node()`), no change to any consuming
     code, since PHP's native query-string parsing already decodes standard percent-encoding.

  2. **Use `add_query_arg()`'s scalar two-arg form against a URL that already has the base query
     string**, mirroring the ALREADY-SAFE pattern proven correct in
     `Challenge::build_replay_response_data()` (`add_query_arg( 'key', 'value', $existing_url )`,
     class-challenge.php:818) — not applicable here since these 6 sites are adding NEW keys to a
     FRESH base URL (`admin_url('admin.php')`, etc.), not modifying an existing URL's own query
     string, so option 1 is the more direct fix for this shape of call.

  Must NOT break:
    - `wp_validate_redirect()`'s open-redirect protection at the consuming end (`Challenge::enqueue_assets()`,
      `render_page()`, `handle_deactivate()`) — these are unaffected either way; they operate on
      whatever `esc_url_raw( wp_unslash( $_GET[...] ) )` decodes to, which will simply be correct
      once the value is properly encoded going in.
    - The scalar-form `add_query_arg( 'key', 'value', $redirect_url )` call in
      `Challenge::build_replay_response_data()` (class-challenge.php:818) — already safe, must not
      be "fixed" (it isn't broken) or accidentally double-encoded by a broad find/replace.
    - Existing passing tests for `Challenge::enqueue_assets()`/`render_page()` that seed
      `$_GET['return_url']` directly — those remain valid regression coverage for the downstream
      half of the pipeline and require no change.
    - Any test currently using an `http_build_query()`-based `add_query_arg()` stub — those tests
      will continue to pass unchanged (they were never testing the defective behavior in the first
      place), but should NOT be relied upon as regression coverage for this fix. The 4 new tests
      added this session (using `TestCase::stub_faithful_add_query_arg()`) are the correct
      regression coverage and should be updated from `assertStringContainsString` on the CURRENT
      (broken) truncated value to asserting the FULL tab/query-string survives, once the fix lands.
    - Should also consider (not required, flagged for the design review): a broader sweep of the
      test suite's other `http_build_query()`-based `add_query_arg()` stubs, since this same blind
      spot could be masking other, unrelated `add_query_arg()` misuse elsewhere in the plugin. Out
      of scope for this root-cause session.

verification: |
  4 new failing PHPUnit tests added and confirmed to fail against current `main`, using a new
  faithful `add_query_arg()` stub (`TestCase::stub_faithful_add_query_arg()`, a byte-for-byte port
  of wordpress-develop trunk's `add_query_arg()`/`build_query()`/`_http_build_query()`, verified
  against live trunk source this session):
    - `tests/Unit/PluginTest.php::test_enqueue_shortcut_challenge_url_preserves_tab_query_arg_from_access_tab`
    - `tests/Unit/GateTest.php::test_intercept_admin_challenge_redirect_preserves_tab_query_arg`
    - `tests/Unit/GateTest.php::test_gate_notice_challenge_link_preserves_query_string_on_current_page`
    - `tests/Unit/AdminBarTest.php::test_admin_bar_node_deactivate_url_preserves_tab_query_arg`

  `composer test:unit` (full suite): 949 tests, 945 pass, exactly these 4 fail — confirming
  isolation (the new shared test helper causes zero regressions elsewhere) and confirming the
  defect is reproducible via ordinary PHPUnit + Brain\Monkey, with no browser, iframe, or
  WordPress Playground involved.

  Per this session's mode (`goal: reproduce_and_root_cause_ONLY`): NO fix implemented, NO commit,
  NO `reviewer-approved` flag written, NO version/tag bump. All 4 new tests are LEFT FAILING
  (uncommitted) as the reproduction artifact for the next session's fix-and-verify pass, which
  must first pass a Pre-Implementation Design Review per CLAUDE.md (return_url feeds redirects —
  security-sensitive).

files_changed: []
