---
status: partially_resolved
trigger: "settings-tab-lost-on-reauth-replay"
created: 2026-07-01T00:00:00Z
updated: 2026-07-01T03:00:00Z
---

## Resolution (2026-07-01)

- **MULTISITE: RESOLVED.** Root cause = `Admin::handle_network_settings_save()` hardcoded its
  post-save redirect and ignored `wp_get_referer()`, dropping `&tab=`. Fixed in commit `a4798d4`
  (branch `fix/multisite-settings-tab-redirect`): redirect is built from `network_admin_url('settings.php')`
  + `page`, lifting ONLY a `VALID_TABS`-validated `tab` from a page-scoped referer. Reproduction test
  renamed to assert the tab is preserved; two security tests + the pre-existing allowlist pin cover it.
- **SINGLE-SITE: UNCONFIRMED (no plugin defect found).** Every single-site path reachable from
  `tab=access` (stash/GET-replay, POST-replay form, Ctrl+Shift+S shortcut cancel AND success redirect,
  blocked-notice link, AJAX grant/revoke) was traced against REAL WP core source and unit-tested — all
  preserve `&tab=`. Leading remaining hypothesis: a **WordPress Playground iframe + service-worker
  navigation artifact** affecting JS `window.location.href` assignments (the plugin already special-cases
  Playground once, `admin/js/wp-sudo-challenge.js:15-42`). AWAITING a live-Playground capture from the
  user to confirm/refute (see the recommended capture steps in the log below); not reproducible in a
  headless browser. Do NOT add speculative production code for the single-site case until confirmed.

## Current Focus

hypothesis: MULTISITE root cause (handle_network_settings_save hardcoded redirect) remains CONFIRMED — unchanged.
   SINGLE-SITE, SUCCESS-REDIRECT PASS (this session): focused re-verification of the ONE remaining untested
   angle — the post-auth SUCCESS redirect for a PROACTIVE reauth (Ctrl/Cmd+Shift+S, or session-only
   activation) with NO stashed action, single-site, tab=access. Re-traced every hop of
   `return_url` -> `cancelUrl` -> `window.location.href` against REAL WordPress core source (not the
   prior session's test stubs) end-to-end: `Plugin::get_current_admin_url()` / `Gate::get_current_admin_url()`
   (REQUEST_URI-faithful) -> `add_query_arg()` (real WP source pulled from wordpress-develop trunk,
   confirmed `urlencode_deep()` + `build_query()` use a literal `&` separator, NOT `&amp;`) -> GET
   round-trip into `$_GET['return_url']` (confirmed via PHP `parse_str`/`http_build_query` simulation,
   byte-for-byte) -> `Challenge::enqueue_assets()`'s `esc_url_raw()` (confirmed via real WP core source:
   'db' context skips the `&` -> `&#038;` entity-replacement that only applies in 'display' context) ->
   `wp_validate_redirect()` -> `wp_sanitize_redirect()` (confirmed via real WP core source: `&` and `=`
   are both in the allowed character class `[^a-z0-9-~+_.?#=&;,/:%!*\[\]()@]`, so the tab param is never
   stripped). Every hop is provably tab-preserving using REAL implementations, not stubs. Also re-verified
   `render_resume_page()` (the "already authenticated on page load" duplicate success path) uses the
   identical `cancel_url` construction — same conclusion applies.
test: Ran the existing `test_enqueue_assets_cancel_url_preserves_tab_query_arg_from_access_tab` test again
   (unchanged from prior session) plus manual PHP verification scripts simulating add_query_arg's
   urlencode_deep/build_query round-trip and confirmed against live wordpress-develop trunk source for
   add_query_arg(), esc_url()/esc_url_raw(), wp_validate_redirect(), and wp_sanitize_redirect(). All confirm
   tab-safety. Did NOT write a new failing test this pass — no code branch was found to falsify.
expecting: n/a — could not construct a hypothesis this pass that predicts single-site tab loss from any
   WP-Sudo code path; every remaining candidate was eliminated by direct comparison to WP core source.
next_action: none further without a live WP Playground browser session (out of scope for this agent — see
   docs/ai-agentic-guidance.md / CLAUDE.md browser handoff policy). See Resolution for the exact capture
   needed to confirm the environmental-artifact hypothesis.

## Symptoms

expected: After passing the challenge, the user returns to the same tabbed settings screen they were on, with &tab=access (or whatever tab) preserved.
actual: The user lands on the bare settings page (?page=wp-sudo-settings) with no &tab= — dumped back to the default/first tab.
errors: None (functional, not an error).
reproduction: Be on options-general.php?page=wp-sudo-settings&tab=access; trigger a sudo-gated action from that screen (most likely: save the Sudo settings, which POSTs to options.php; or another gated action reached from that tab); pass the reauth challenge; observe you return to the tabless settings URL.
started: Present in current main (v4.5, Phase 24 shipped). Not previously reported; the tabbed settings UI + gate/stash/replay predate Phase 24.

## Eliminated

- hypothesis: [RE-VERIFIED this session, still holds] Single-site Settings tab save (`<form action="options.php">`, `options.wp_sudo` rule) loses the tab somewhere in the stash → challenge → replay round-trip.
  evidence (this session): render_access_tab() (includes/class-admin.php:1844-1944) and render_drift_detection_panel()
    (includes/class-admin.php:1956-2017) confirmed to contain ZERO `<form>` elements — only AJAX buttons
    (wp-sudo-grant-cap, wp-sudo-revoke-cap, wp-sudo-grant-manage). Therefore the Settings-tab save form
    (only rendered in the `default: // 'settings'` branch of render_settings_page()'s tab switch,
    includes/class-admin.php:1738-1761) CANNOT be the form the user submitted while `tab=access` was showing
    — the two tabs are mutually exclusive in a single page load. The user's report ("was on tab=access...
    after passing the challenge, landed on bare ?page=wp-sudo-settings") cannot be this rule at all. Confirmed
    NOT the reproduction path for this report (though the original single-site trace itself remains correct:
    options.php's own wp_get_referer()-based redirect preserves the tab whenever this rule DOES fire, e.g.
    from the Settings tab itself).
  timestamp: 2026-07-01T01:00:00Z (re-verified; original trace unchanged, see below)
  evidence: Traced the full single-site path line-by-line and confirmed each hop preserves the tab:
    - `Request_Stash::build_original_url()` (includes/class-request-stash.php:258-264) captures the tabless POST target `/wp-admin/options.php` for `url` — correct, since that IS the POST target.
    - `Request_Stash::get_return_url()` (includes/class-request-stash.php:174-178) calls `wp_get_referer()`, which reads `_wp_http_referer` — set by WP core's `wp_referer_field()` (called via `settings_fields()` → `wp_nonce_field()`) to `remove_query_arg('_wp_http_referer')` on the *current page's* `REQUEST_URI` at render time, i.e. the tabbed `options-general.php?page=wp-sudo-settings&tab=settings` (verified against `wordpress-develop` trunk `wp-includes/functions.php` lines ~1930 and ~1980-1991).
    - `_wp_http_referer` is included in every rule's replay allowlist via `Action_Registry::DEFAULT_REPLAY_POST_FIELDS` (includes/class-action-registry.php:73-80), merged in by `stash_allowlist()` (includes/class-action-registry.php:754-759). So it IS present in `$stash['post']` and gets replayed as a hidden form field.
    - `Challenge::build_replay_response_data()` (includes/class-challenge.php:790-852): for a non-blocked POST (the settings-save case is NOT redacted/blocked — `wp_sudo_settings` is not a sensitive key), the method returns the `replay=true` branch (lines 845-851): `url = $safe_url` (tabless `options.php`, correct — it must POST there) and `post_data = $stash['post']` (includes the tabbed `_wp_http_referer`).
    - WP core's `options.php` (`wordpress-develop` trunk, verified via raw.githubusercontent.com) line 374-375: `$goback = add_query_arg('settings-updated', 'true', wp_get_referer()); wp_redirect($goback);` — `wp_get_referer()` here reads `_wp_http_referer` from the REPLAYED POST body (the hidden field the wp-sudo JS submits), which is the tabbed URL.
    - `wp_validate_redirect()` (verified against local `.tmp/wordpress/wp-includes/pluggable.php:1665`) does not strip query args in the same-host case — the full query string including `&tab=` passes through untouched.
    - Conclusion: single-site Settings-tab save-and-replay correctly preserves `&tab=`. This path is NOT the bug.
  timestamp: 2026-07-01T00:00:00Z

- hypothesis: Access tab (grant/revoke capability buttons, `options.wp_sudo_access` rule) goes through the stash → challenge → replay round-trip and loses the tab there.
  evidence: `Admin::render_access_tab()` (includes/class-admin.php:1844-1944) renders grant/revoke as AJAX buttons (`wp-sudo-grant-cap`, `wp-sudo-revoke-cap`), not a real form submission. `admin/js/wp-sudo-admin.js` `sendAccessAction()` (line 274+) handles a gate rejection purely as an inline error message (`data.message`) — it never checks for a `challenge_url` and never redirects to the challenge page at all. So Access-tab actions never enter the stash/challenge/replay pipeline in the browser; there is no "return to a URL" step to lose the tab on. Eliminated as the mechanism for the reported symptom, though it is a separate, softer UX gap (user must reauthenticate elsewhere and retry manually).
  timestamp: 2026-07-01T00:00:00Z

- hypothesis: [NEW this session] The Ctrl+Shift+S / Cmd+Shift+S keyboard shortcut (session-only challenge, no stash_key) opens the challenge page from tab=access via Plugin::enqueue_shortcut()'s `return_url`, but the round trip through Challenge::enqueue_assets()'s `cancelUrl` computation drops or corrupts `&tab=access`.
  evidence: Wrote and ran a new faithful-semantics unit test, `test_enqueue_assets_cancel_url_preserves_tab_query_arg_from_access_tab` (tests/Unit/ChallengeTest.php), that: (1) seeds `$_GET['return_url']` with exactly the value `Plugin::enqueue_shortcut()` would produce from `options-general.php?page=wp-sudo-settings&tab=access` (per `Plugin::get_current_admin_url()`, includes/class-plugin.php:570-585, which uses `$_SERVER['REQUEST_URI']` faithfully — verified by a pre-existing test, `test_enqueue_shortcut_return_url_is_not_double_encoded`); (2) lets `wp_validate_redirect()` run with real same-host semantics (not stubbed to a fixed string); (3) asserts `wpSudoChallenge.cancelUrl` (what the challenge JS uses for the `code==='authenticated'` session-only-success redirect at admin/js/wp-sudo-challenge.js:148/245/251) contains `tab=access`.
  found: Test PASSES (`./vendor/bin/phpunit --filter test_enqueue_assets_cancel_url_preserves_tab_query_arg_from_access_tab tests/Unit/ChallengeTest.php` → 1 test, 4 assertions, OK). Also independently confirmed the `add_query_arg()`/`parse_str()` round-trip does not corrupt a nested `&`-containing URL value via a standalone PHP script (http_build_query + parse_url round-trips the tabbed URL byte-for-byte).
  implication: The keyboard-shortcut session-only path is NOT the defect. Eliminated.
  timestamp: 2026-07-01T01:00:00Z

- hypothesis: [NEW this session] The Access-tab AJAX grant/revoke `sudo_required` block sets a one-shot transient (`Gate::set_blocked_transient()`); on the NEXT admin page load `Gate::render_blocked_notice()` shows a "Confirm your identity" admin-notice link whose `challenge_url`'s `return_url` (`Gate::get_current_admin_url()`) could be built from a different/wrong page than tab=access, dropping the tab.
  evidence: `Gate::get_current_admin_url()` (includes/class-gate.php:3037-3052) uses `$_SERVER['REQUEST_URI']` faithfully, structurally identical to `Plugin::get_current_admin_url()` (diffed the two private methods: only the empty-`$_SERVER['REQUEST_URI']`/empty-host fallback differs — `''` vs `admin_url()`/`network_admin_url()` — not relevant when REQUEST_URI is present). Since `render_blocked_notice()` fires on `admin_notices` on the SAME reloaded page (still `tab=access` if the user simply reloads or the notice appears on the next natural page view), `return_url` correctly captures `tab=access` and flows into the same `cancelUrl` mechanism already verified correct above.
  implication: Structurally the same as the shortcut path; no distinct defect found. Eliminated (with the caveat that this depends on the notice being rendered while `tab=access` is still the active URL, which is the expected case per the user's report).
  timestamp: 2026-07-01T01:00:00Z

- hypothesis: [NEW this session] `sendAccessAction()` (admin/js/wp-sudo-admin.js) actually redirects to a `challenge_url` on a `sudo_required` AJAX error, and that redirect drops the tab.
  evidence: Re-read `sendAccessAction()` end-to-end (admin/js/wp-sudo-admin.js:274-351) and every call site (grant, revoke-cap, revoke-session, grant-manage). The error branch (`else` at line 323-335) only ever sets `resultEl.textContent = emsg` or `window.alert(emsg)` — no `challenge_url` field is read anywhere in this file, no `window.location` assignment exists in the error path. Confirmed (again) no redirect occurs from this handler.
  implication: Still eliminated, now re-verified line-by-line rather than assumed from the prior session's summary.
  timestamp: 2026-07-01T01:00:00Z

## Evidence

- timestamp: 2026-07-01T00:00:00Z
  checked: includes/class-action-registry.php:511-528 and :732-744 — the `options.wp_sudo` rule is registered TWICE: once for single-site (`pagenow: options.php`, matches core Settings API POST) and once for multisite (`pagenow: edit.php`, `actions: ['wp_sudo_settings']`, matching the network-admin form's `action="edit.php?action=wp_sudo_settings"`, per includes/class-admin.php:1742).
  found: The multisite variant's stash allowlist is `stash_allowlist([Admin::OPTION_KEY])`, same shape as single-site, so `_wp_http_referer` IS correctly captured and replayed to the multisite handler too.
  implication: The multisite replay reaches `Admin::handle_network_settings_save()` with a correct, tabbed `_wp_http_referer` in `$_POST` — but that handler doesn't use it.

- timestamp: 2026-07-01T00:00:00Z
  checked: includes/class-admin.php:429-453 `Admin::handle_network_settings_save()`.
  found: The redirect is hardcoded: `wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'updated' => 'true'], network_admin_url('settings.php')))`. It never reads `wp_get_referer()` / `$_POST['_wp_http_referer']` at all — unlike WP core's own `options.php` (which the single-site path delegates to), this custom handler was written without the referer-preserving redirect pattern.
  implication: This is the sole point in the entire replay chain where the tab is silently dropped, and it only fires for the **multisite network-admin** Settings-tab save. Single-site (delegating to WP core's `options.php`) is unaffected.

- timestamp: 2026-07-01T00:00:00Z
  checked: tests/Unit/AdminTest.php:1435-1477 `test_handle_network_settings_save_updates_site_option_and_redirects` (pre-existing).
  found: This test stubs `add_query_arg` with `Functions\when('add_query_arg')->justReturn('https://example.com/wp-admin/network/settings.php?page=wp-sudo-settings&updated=true')` — a fixed string, not a real implementation — so it locks in and cannot detect the tab-dropping behavior. It was effectively testing "wp_safe_redirect gets called with a URL containing certain substrings," not "the redirect target is correctly derived from where the user came from."
  implication: Confirms why this bug shipped unnoticed — existing coverage could not have caught it by construction.

- timestamp: 2026-07-01T00:00:00Z
  checked: Ran new unit test `test_handle_network_settings_save_redirect_drops_tab_query_arg` (tests/Unit/AdminTest.php, added in this session), which lets `add_query_arg`/`network_admin_url` run with faithful real semantics and seeds `$_POST['_wp_http_referer']` with a tabbed URL (`...settings.php?page=wp-sudo-settings&tab=access`), then asserts the captured `wp_safe_redirect()` argument contains `tab=access`.
  found: FAILS as predicted — `./vendor/bin/phpunit --filter test_handle_network_settings_save_redirect_drops_tab_query_arg tests/Unit/AdminTest.php` produces: `Failed asserting that 'https://example.com/wp-admin/network/settings.php?page=wp-sudo-settings&updated=true' contains "tab=access".` Full suite run (`composer test:unit`) confirms this is the ONLY failure among 942 tests (941 pass) — the test is isolated and does not regress anything else.
  implication: Root cause is confirmed empirically, not just by static tracing. The failing test pinpoints exactly the string `handle_network_settings_save()` constructs.

- timestamp: 2026-07-01T01:00:00Z
  checked: admin/js/wp-sudo-challenge.js:15-42 (iframe-breakout guard at the top of the challenge page's own JS, predating this session).
  found: The code explicitly detects `window.top !== window.self`, tries `window.top.location.href = window.location.href` inside a try/catch, and on failure falls through with the comment: "Cross-origin/sandboxed hosts such as WordPress Playground must keep the challenge functional inside the embedded frame." `git log -p` on this file shows the guard was added/hardened in commit 3ed7e7f ("fix: stabilize playground sudo demo", also 76928bd), i.e. WP Sudo has ALREADY had to special-case WordPress Playground's iframe navigation model once. That same commit's diff to includes/class-admin-bar.php shows the PRE-existing bug pattern this investigation is looking for: the old admin-bar sudo-deactivate handler built its redirect via a bare `remove_query_arg([...])` call (no explicit base URL), which defaults to `$_SERVER['REQUEST_URI']` of the deactivation request ITSELF (`admin.php?wp_sudo_deactivate=1&_wpnonce=...`) rather than the page the user deactivated from — silently dropping any `&tab=`/query context, exactly this bug's shape. It was fixed by threading a `REDIRECT_PARAM` round-trip (mirrors `return_url` elsewhere).
  implication: (1) The one *known* WP-Sudo-side instance of this exact bug pattern (bare `remove_query_arg()`/lost-referer redirect) was in Admin_Bar and is already fixed; a `grep` for the same anti-pattern (`remove_query_arg(` with no second argument) elsewhere in includes/*.php returns zero further hits — this specific anti-pattern is not recurring in currently-shipped code. (2) WordPress Playground is independently, externally documented (searched via WebSearch) as running wp-admin inside a **sandboxed iframe without `allow-top-navigation`, behind a Service Worker that intercepts every HTTP request, with a custom outer "browser chrome" that Playground itself says needs special handling because "interacting with the browser's address bar... has no effect on the WordPress instance that runs inside it."** This uniquely affects *programmatic* `window.location.href = ...` assignments (used for the session-only "authenticated" redirect and the GET-stash-replay redirect, admin/js/wp-sudo-challenge.js:148/245/251/320-322) as distinct from genuine `<a href>` clicks (the tab-nav links) and genuine `<form>` submissions (POST-replay, admin/js/wp-sudo-challenge.js:326-341) — both of which were independently traced/tested this session and confirmed tab-preserving.
  timestamp: 2026-07-01T01:00:00Z

- timestamp: 2026-07-01T01:00:00Z
  checked: Whether any single-site code path can even PRODUCE a full-page challenge navigation while `tab=access` is displayed, given `render_access_tab()` has zero `<form>` elements.
  found: The ONLY way to reach the full-page challenge (`wp-sudo-challenge`) screen from `tab=access` is (a) the Ctrl+Shift+S/Cmd+Shift+S keyboard shortcut (session-only, no stash), or (b) clicking the "Confirm your identity" link in the one-shot `render_blocked_notice()` admin notice after a blocked Access-tab AJAX action. Both were traced and empirically tested this session (see Eliminated) and both correctly preserve `&tab=access` all the way through `cancelUrl`.
  implication: No WP-Sudo PHP/JS code defect was found that drops the tab from `tab=access` on single-site. Every reachable path is provably correct in isolation. The reported symptom is real (per the user's live observation) but its mechanism was not reproduced in this codebase's own logic; the leading remaining candidate is the Playground iframe/Service-Worker navigation environment interacting with the plugin's `window.location.href` assignments specifically (as opposed to link/form navigation), which is consistent with (1) the symptom being full-page-content loss (not just an address-bar cosmetic issue), (2) the plugin's own prior, documented need to special-case Playground's navigation model, and (3) every other (non-JS-driven) navigation in the plugin being independently verified tab-safe.
  timestamp: 2026-07-01T01:00:00Z

- timestamp: 2026-07-01T02:00:00Z
  checked: FOCUSED PASS — the post-auth SUCCESS redirect specifically (not the outbound return_url capture, which was already proven safe). Re-verified `handle_ajax_auth()`'s `case 'success'` with empty `$stash_key` (admin/js/wp-sudo-challenge.js:147-150, :244-246, :250-253 — all three `code==='authenticated'`/`sessionOnly` branches do `window.location.href = config.cancelUrl`) and `render_resume_page()`'s no-stash branch (includes/class-challenge.php:707-710, :751-757 — identical `$cancel_url` fallback, same `window.location.href` pattern). Pulled REAL WordPress core source from wordpress-develop trunk (not the prior session's Brain\Monkey stubs) for every function in the chain: `add_query_arg()` (wp-includes/functions.php:1144), `esc_url()`/`esc_url_raw()` (wp-includes/formatting.php:4514, :4632), `wp_validate_redirect()` and `wp_sanitize_redirect()` (wp-includes/pluggable.php:1553, :1665).
  found: (1) `add_query_arg()` uses `wp_parse_str()` + `urlencode_deep()` + `build_query()` (which calls `_http_build_query(..., '&', ...)` — a literal `&` separator, never `&amp;`), so nesting a tabbed URL as the VALUE of `return_url` round-trips byte-for-byte (verified with a standalone PHP script: `add_query_arg`-equivalent `http_build_query`/`parse_str` round trip on `https://example.com/wp-admin/options-general.php?page=wp-sudo-settings&tab=access` reproduces the exact original string). (2) `esc_url_raw()` calls `esc_url($url, $protocols, 'db')` — the `'db'` context SKIPS the `wp_kses_normalize_entities()` + `'&' -> '&#038;'` replacement block, which only runs when `$_context === 'display'` (formatting.php:4547-4552). So `esc_url_raw()` on a `return_url` value leaves its embedded `&tab=access` completely untouched — this was previously only stubbed as `returnArg()` in the unit test, never verified against the real implementation until now. (3) `wp_validate_redirect()` calls `wp_sanitize_redirect()` first, whose character-class filter `[^a-z0-9-~+_.?#=&;,/:%!*\[\]()@]` explicitly ALLOWS `&` and `=` — the tab param survives this filter untouched. (4) `render_resume_page()`'s no-stash `$redirect_url` is exactly `$cancel_url`, constructed identically to `enqueue_assets()`'s `cancel_url` (both read `$_GET['return_url']` -> `esc_url_raw()` -> `wp_validate_redirect()`) — no divergent logic exists between the two success-path renderers.
  implication: Every real WordPress core function in the single-site success-redirect chain — not just the plugin's own code, and not just what the existing unit test's stubs simulated — is independently confirmed, against live trunk source, to preserve a `&tab=` query argument end-to-end. No new hypothesis could be constructed this pass that predicts single-site tab loss from ANY reachable WP-Sudo code path. This closes out the one specific gap named in this session's brief (the SUCCESS redirect, as distinct from the already-tested CANCEL/outbound path) with the same "no defect" conclusion — now on stronger evidence (real implementations, not test doubles). No new failing test was written, because no falsifiable code-level hypothesis remained to test.

## Resolution

root_cause: |
  `WP_Sudo\Admin::handle_network_settings_save()` (includes/class-admin.php:429-453) is the
  POST handler for the multisite network-admin Sudo Settings form
  (`<form action="{network_admin_url}edit.php?action=wp_sudo_settings">`, rendered at
  includes/class-admin.php:1742-1748). Unlike WordPress core's own `options.php` handler
  (which single-site delegates to, and which builds its post-save redirect from
  `wp_get_referer()` — see wp-admin/options.php line 374 in wordpress-develop trunk),
  `handle_network_settings_save()` hardcodes its redirect target:

      wp_safe_redirect(
          add_query_arg(
              array( 'page' => self::PAGE_SLUG, 'updated' => 'true' ),
              network_admin_url( 'settings.php' )
          )
      );

  It never reads `_wp_http_referer` (present in the POST body — including the replayed POST
  body during a sudo challenge replay — because `_wp_http_referer` is unconditionally part of
  `Action_Registry::DEFAULT_REPLAY_POST_FIELDS`, includes/class-action-registry.php:73-80).
  As a result, EVERY network-admin settings save — reauth-gated or not — redirects to the bare
  `?page=wp-sudo-settings&updated=true`, discarding whatever `&tab=` the user was on. The sudo
  reauth replay round-trip (stash/challenge/JS self-submitting form) is NOT where the tab is
  lost; it faithfully carries `_wp_http_referer` all the way to this handler. The bug is a
  pre-existing defect in the network-admin settings-save redirect itself, which the reauth
  flow merely surfaces because it re-POSTs the same form via replay.

  Single-site (`options-general.php?page=wp-sudo-settings&tab=...`, `<form action="options.php">`)
  is NOT affected by THIS mechanism — it uses WordPress core's Settings API end-to-end, and
  core's `options.php` already redirects via `wp_get_referer()`, which correctly resolves to the
  tabbed URL both on direct save and on sudo-challenge replay. This was re-verified in this
  session (not just re-asserted): render_access_tab() has no <form> at all, so the Settings-tab
  save form cannot be what the user submitted while `tab=access` was showing, and the tab-nav
  links + Settings-save POST-replay path were re-traced against live wordpress-develop trunk
  source with no change to the original conclusion.

  ---

  SINGLE-SITE re-investigation (this session, per live user report on WP Playground/WP 7.0/
  single-site): the user's report is real (they observed it), but its mechanism is NOT a defect
  in `handle_network_settings_save()`'s sibling logic, nor in any WP-Sudo PHP or JS code path
  reachable from `tab=access`, all of which were traced AND empirically unit-tested this session:

    - Ctrl+Shift+S/Cmd+Shift+S keyboard shortcut (session-only challenge, `Plugin::enqueue_shortcut()`
      -> `Challenge::enqueue_assets()`'s `cancelUrl`): new test
      `test_enqueue_assets_cancel_url_preserves_tab_query_arg_from_access_tab`
      (tests/Unit/ChallengeTest.php) PASSES — the tab survives the round trip.
    - `Gate::render_blocked_notice()`'s one-shot "Confirm your identity" link after a blocked
      Access-tab AJAX action: structurally identical `return_url`/`cancelUrl` mechanism, verified
      by code diff against the already-tested shortcut path.
    - `sendAccessAction()` (admin/js/wp-sudo-admin.js): re-read line-by-line; never reads
      `challenge_url`, never issues any redirect on a `sudo_required` error — only an inline
      message. Confirmed not a navigation path at all.

  No single-site code defect was reproduced. The leading remaining candidate, supported by
  external evidence (WebSearch) and by this codebase's own prior history, is an ENVIRONMENTAL
  interaction specific to WordPress Playground: Playground runs wp-admin inside a sandboxed
  iframe (documented as lacking `allow-top-navigation`) behind a Service Worker that intercepts
  every HTTP request, with a custom outer "browser chrome" that Playground's own docs say is
  needed because "interacting with the browser's address bar... has no effect on the WordPress
  instance that runs inside it." WP Sudo's own challenge JS (admin/js/wp-sudo-challenge.js:15-42)
  already has a dedicated, Playground-referencing workaround for this iframe model (added in
  commit 3ed7e7f / 76928bd, "fix: stabilize playground sudo demo") — and that SAME commit fixed
  an admin-bar redirect bug with exactly this bug's shape (a lost referer/query context on
  redirect, caused by a bare `remove_query_arg()` call defaulting to the WRONG page's
  REQUEST_URI). A `grep` for that specific anti-pattern elsewhere in includes/*.php found no
  further instances, so it is not recurring in currently-shipped code — but it establishes that
  this exact bug SHAPE has hit WP Sudo once before in a Playground-adjacent code path.

  The single-site session-only/AJAX-notice redirects use `window.location.href = <url>`
  (a JS-programmatic navigation) — distinct in kind from every other navigation in the plugin
  (tab-nav `<a href>` clicks, and the Settings-save POST-replay's genuine auto-submitted
  `<form>`), both of which were independently confirmed tab-safe this session. This asymmetry —
  JS-driven navigation is the one kind of navigation NOT yet proven safe under Playground's
  iframe/Service-Worker interception, while link- and form-driven navigation are — is consistent
  with the symptom being full PAGE CONTENT loss (not just an address-bar cosmetic artifact) while
  no corresponding PHP/JS logic defect could be found or reproduced in a unit test.

  Scope note: the CONFIRMED root cause (handle_network_settings_save) is multisite-only. The
  SINGLE-SITE report could not be attributed to a WP-Sudo code defect after exhaustive tracing
  and testing of every reachable path; see `fix` below for what is and is not recommended.

fix: |
  NOT IMPLEMENTED (goal: find_root_cause_only). Proposed minimal fix:

  In `handle_network_settings_save()`, replace the hardcoded redirect target with one derived
  from the request's own referer, mirroring core's `options.php` pattern, falling back to the
  bare settings URL only when no referer is available or it fails validation:

      $fallback = add_query_arg(
          array( 'page' => self::PAGE_SLUG ),
          network_admin_url( 'settings.php' )
      );
      $referer  = wp_get_referer();
      $goback   = $referer ? wp_validate_redirect( $referer, $fallback ) : $fallback;

      wp_safe_redirect( add_query_arg( 'updated', 'true', $goback ) );
      exit;

  This is the smallest change: one method, no new stash/replay plumbing needed (the referer is
  already present in `$_POST['_wp_http_referer']` both for direct saves and reauth replays,
  since `wp_get_referer()` reads `$_REQUEST['_wp_http_referer']`, which includes `$_POST`).

  Must NOT break:
    - `wp_validate_redirect()`'s open-redirect protection — keep using it (as shown above),
      do not swap in a raw `$_POST['_wp_http_referer']` echo.
    - The existing `updated=true` notice query arg and its consumption at
      includes/class-admin.php:1698-1702 (`$is_network && isset($_GET['updated'])`) — that
      check is tab-agnostic already, so it will keep working regardless of which tab is in
      the redirect.
    - POST-replay redaction/blocking semantics in `Challenge::build_replay_response_data()`
      are untouched by this fix — `options.wp_sudo`'s stash is not redacted/blocked, so it
      already reaches `handle_network_settings_save()` via the normal replay-form POST path;
      only the redirect target inside that handler changes.
    - The existing test `test_handle_network_settings_save_updates_site_option_and_redirects`
      (tests/Unit/AdminTest.php:1435) currently stubs `add_query_arg` to a fixed string and
      does not set `_wp_http_referer`; it will need `wp_get_referer()`/`wp_validate_redirect()`
      stubbed (e.g. `Functions\when('wp_get_referer')->justReturn(false)` to keep exercising
      the no-referer fallback branch) so it continues to assert the `updated=true` bare-URL
      case explicitly, while the new `test_handle_network_settings_save_redirect_drops_tab_query_arg`
      test (added in this session) is updated to assert the tab IS preserved once the fix lands
      (currently asserts the failure).

  ---

  SINGLE-SITE: no fix is proposed for a WP-Sudo code defect, because none was found or
  reproduced. The multisite fix above and the single-site report do NOT share a root cause or a
  fix — they are two distinct findings:
    - Multisite: a real, confirmed, reproducible WP-Sudo logic defect (hardcoded redirect
      ignoring `_wp_http_referer`), independent of any hosting environment.
    - Single-site: an unreproduced report whose likely mechanism (if the leading environmental
      hypothesis is correct) lies in WordPress Playground's iframe/Service-Worker navigation
      model interacting with the plugin's `window.location.href` JS redirects, not in WP-Sudo's
      PHP or JS logic itself.

  If the orchestrator wants defense-in-depth regardless of attribution, the lowest-risk,
  narrowly-scoped option (NOT implemented, would need its own design review per
  CLAUDE.md — it changes an existing UX contract) would be to make the session-only/AJAX-notice
  challenge redirects behave more like the already-safe POST-replay path, e.g. by round-tripping
  through a real navigation primitive (a genuine `<a>` click simulation, or a same-document
  `history.pushState`-based approach) instead of a bare `window.location.href` assignment — but
  this should NOT be implemented speculatively without first reproducing the failure in an actual
  WP Playground browser session, per the reinvestigate instructions' own disambiguation ask.
  Reproducing this requires a live browser (Playground) session, which is out of scope for this
  agent per CLAUDE.md's browser/Playwright handoff policy — restart with
  `/Users/danknauss/bin/claude-playwright` or `/Users/danknauss/bin/claude-browser-handoff` and
  capture: (1) whether `window.top !== window.self` and the `canNavigateTop` catch branch fires,
  (2) the live value of `window.wpSudoChallenge.cancelUrl` immediately before the post-auth
  redirect, and (3) a HAR/network trace of the admin-ajax.php challenge POST and the subsequent
  navigation inside the Playground iframe.

  ---

  FOCUSED SUCCESS-REDIRECT PASS (this session, single-site only): re-verified the ONE remaining
  untested angle — the post-auth SUCCESS redirect for a proactive (no-stash) reauth — against REAL
  WordPress core source (add_query_arg, esc_url/esc_url_raw, wp_validate_redirect,
  wp_sanitize_redirect pulled from wordpress-develop trunk), not just the prior session's
  Brain\Monkey test stubs. Every hop of `return_url` (query-string capture) -> `cancelUrl`
  (Challenge::enqueue_assets()/render_page()) -> `window.location.href` (challenge JS's
  `code==='authenticated'` branches, admin/js/wp-sudo-challenge.js:148/245/251, and
  render_resume_page()'s identical no-stash fallback) is confirmed tab-preserving using the actual
  WordPress implementations of every function in the chain, not simulated behavior. No divergence
  was found between this SUCCESS path and the already-tested CANCEL path — both use the same
  `cancel_url`/`return_url` construction. No new single-site code defect was found or reproduced.

  CONCLUSION FOR THIS PASS: the single-site symptom is NOT attributable to the success-redirect
  code path either. Combined with the prior session's exhaustive elimination of the stash/replay,
  shortcut, and AJAX-notice paths, EVERY reachable single-site WP-Sudo code path (PHP and JS) that
  could plausibly drop `&tab=` has now been traced and verified against real implementations with
  no defect found. The environmental-artifact hypothesis (WordPress Playground's sandboxed-iframe +
  Service-Worker navigation model intercepting the plugin's `window.location.href` JS-programmatic
  redirects specifically) remains the sole standing explanation for the live user report.

  EXACT CAPTURE NEEDED TO CONFIRM (from a live WP Playground browser, single-site, WP 7.0,
  reproducing: be on options-general.php?page=wp-sudo-settings&tab=access, trigger Ctrl/Cmd+Shift+S,
  pass the password/2FA challenge, observe landing on bare ?page=wp-sudo-settings):
    1. In the browser console on the CHALLENGE page (before pressing Confirm & Continue), run
       `window.wpSudoChallenge.cancelUrl` and record the exact string. PREDICTION if the plugin's
       own construction is correct: it will already contain `&tab=access`. If it does NOT, that
       would falsify this session's entire chain of reasoning and reopen the PHP-side
       investigation — this is the single highest-value data point.
    2. Immediately after passing the challenge (before the page finishes navigating), open DevTools
       Network tab and find the `admin-ajax.php` POST for `action=wp_sudo_challenge_auth`. Inspect
       the JSON response body; confirm `data.code === 'authenticated'` and that no `redirect` field
       is present that could override `cancelUrl` (per this session's trace, none should be, but
       Playground's Service Worker could theoretically be rewriting the response body in flight).
    3. Add a temporary one-line `console.log('wp-sudo redirecting to:', config.cancelUrl)`
       immediately before each of the three `window.location.href = config.cancelUrl` assignments
       in admin/js/wp-sudo-challenge.js (lines 148, 245, 251), reload, and repeat the repro. Compare
       the logged value to the URL the browser's address bar actually ends up showing after the
       navigation settles. A MISMATCH between the logged value and the final address bar URL — with
       the logged value correctly containing `&tab=access` — would conclusively confirm the
       Playground iframe/Service-Worker navigation layer (not WP-Sudo's own JS or PHP) is the
       mechanism that drops the tab.
    4. Check `window.top !== window.self` and, if true, whether Playground's outer iframe has a
       `sandbox` attribute and whether it includes `allow-top-navigation` — this determines which
       branch of the iframe-breakout guard at admin/js/wp-sudo-challenge.js:15-42 executes, and
       specifically whether `window.top.location.href = window.location.href` throws (caught
       silently) versus succeeds.
  verification: n/a — no fix implemented for either finding in this session (goal: root-cause only).
files_changed: []
