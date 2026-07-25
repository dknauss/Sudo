# #262 — in-editor session indicator: implementation-draft skeletons

> ⚠ **UNVERIFIED DRAFT for a browser-capable, dev-provisioned session.** None of the
> code below has been run in a live editor or through Playwright. It is a copy-paste
> starting point that follows the plugin's established build-free vanilla-JS idiom
> (`admin/js/wp-sudo-editor-reauth.js`, `admin/js/wp-sudo-admin-bar.js`). The picking-up
> session must place these into the real files, resolve the flagged open decisions, run
> the E2E, and follow the TDD + pre-commit reviewer workflow (`CLAUDE.md`) before commit.
> Do **not** commit this code path from a non-browser session.
>
> Authoritative design: [`in-editor-session-indicator-design-brief.md`](in-editor-session-indicator-design-brief.md)
> (see the 2026-07-25 implementation addendum for the cross-module contract). Tracking: #262.

Server-side feed is already shipped (#204) and re-verified 2026-07-25 — do **not** re-add it.

---

## 1. New module — `admin/js/wp-sudo-session-indicator.js`

```js
/**
 * WP Sudo – In-editor sudo session-status indicator (#262 / #182).
 *
 * The full-screen block/site editor hides the admin bar, so the admin-bar
 * countdown never renders while editing. This surfaces the same session state:
 *
 *   Part A (baseline, WP 6.4+): an announce-once `core/notices` snackbar on a
 *     successful in-editor grant and once at page load when already active.
 *   Part B (enhancement, WP 6.6+): a feature-detected PluginSidebar whose panel
 *     shows a live M:SS countdown that ticks down locally (no network, no polling).
 *
 * Data feeds (NO new endpoint, NO polling):
 *   #1 page load  — window.wpSudoEditorReauth.remaining (server-gated on is_active()).
 *   #2 post-grant — the `wp-sudo-session-granted` CustomEvent from
 *                   wp-sudo-editor-reauth.js (detail.remaining).
 *
 * Informational only: never mints/extends/refreshes a session.
 *
 * @package WP_Sudo
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.data ) {
		return;
	}

	var cfg = window.wpSudoEditorReauth || {};
	var i18n = wp.i18n || {};
	var __ = i18n.__ || function ( t ) { return t; };
	var sprintf = i18n.sprintf || function ( f ) { return f; };

	var GRANT_EVENT = 'wp-sudo-session-granted';
	var SNACKBAR_ID = 'wp-sudo-session-active';

	// --- shared countdown state (feed #1 seeds; feed #2 re-seeds) -----------
	var remaining = parseInt( cfg.remaining, 10 ) || 0; // seconds; 0 = inactive
	var intervalId = null;
	var listeners = [];

	function notify() {
		for ( var i = 0; i < listeners.length; i++ ) { listeners[ i ](); }
	}
	function subscribe( fn ) {
		listeners.push( fn );
		return function () { listeners = listeners.filter( function ( l ) { return l !== fn; } ); };
	}
	function formatMSS( secs ) {
		var m = Math.floor( secs / 60 );
		var s = secs % 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}
	function startTicker() {
		if ( intervalId || remaining <= 0 ) { return; }
		intervalId = setInterval( function () {
			remaining = remaining - 1;
			if ( remaining <= 0 ) {
				remaining = 0;
				clearInterval( intervalId );
				intervalId = null;
			}
			notify();
		}, 1000 );
	}
	function reseed( secs ) {
		remaining = parseInt( secs, 10 ) || 0;
		if ( intervalId ) { clearInterval( intervalId ); intervalId = null; }
		startTicker();
		notify();
	}

	// pagehide cleanup (bfcache safety) — mirrors wp-sudo-admin-bar.js.
	window.addEventListener( 'pagehide', function () {
		if ( intervalId ) { clearInterval( intervalId ); intervalId = null; }
	} );

	// --- Part A: announce-once snackbar -------------------------------------
	function snackbar( secs ) {
		var notices = wp.data.dispatch( 'core/notices' );
		if ( ! notices || ! notices.createNotice ) { return; }
		var mins = Math.max( 1, Math.round( secs / 60 ) );
		notices.createNotice(
			'success',
			sprintf(
				/* translators: %d: whole minutes remaining in the sudo session */
				__( 'Reauthenticated — sudo active for about %d min.', 'wp-sudo' ),
				mins
			),
			{ id: SNACKBAR_ID, type: 'snackbar', isDismissible: true }
		);
	}

	// --- feed #2: grant event from the reauth modal ------------------------
	window.addEventListener( GRANT_EVENT, function ( e ) {
		var secs = ( e && e.detail && parseInt( e.detail.remaining, 10 ) ) || 0;
		if ( secs > 0 ) {
			reseed( secs );
			snackbar( secs );
		}
	} );

	// --- feed #1: page load (active-at-load) -------------------------------
	if ( remaining > 0 ) {
		startTicker();
		snackbar( remaining ); // brief a11y §: closes the "active-at-load + unpinned" silent path
	}

	// --- Part B: feature-detected PluginSidebar (WP 6.6+ unified API) ------
	var PluginSidebar = wp.editor && wp.editor.PluginSidebar;
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	if ( ! PluginSidebar || ! registerPlugin ) {
		return; // 6.4–6.5: snackbar-only, per the brief.
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	function IndicatorPanel() {
		var st = useState( remaining );
		var secs = st[ 0 ];
		var setSecs = st[ 1 ];
		useEffect( function () {
			return subscribe( function () { setSecs( remaining ); } );
		}, [] );

		var active = secs > 0;
		// NOTE: the ticking text is static readable text (not an aria-live region),
		// per the brief's announce-once a11y decision.
		return el(
			PluginSidebar,
			{
				name: 'wp-sudo-session-indicator',
				title: __( 'Sudo', 'wp-sudo' ),
				icon: 'shield' // OPEN DECISION: pick the dashicon.
			},
			el(
				'div',
				{ className: 'wp-sudo-indicator-panel', style: { padding: '16px' } },
				active
					? el( 'p', null, sprintf(
						/* translators: %s: M:SS time remaining */
						__( 'Sudo active — %s remaining.', 'wp-sudo' ),
						formatMSS( secs )
					) )
					: el( 'p', null, __( 'No active sudo session.', 'wp-sudo' ) )
			)
		);
	}

	registerPlugin( 'wp-sudo-session-indicator', { render: IndicatorPanel } );
} )( window.wp );
```

### Open decisions the browser session must resolve
- **Dashicon** for the pinned button (`icon:` above) — pick one (`shield`, `lock`, `clock`).
- **Dynamic pinned-button state.** The brief says the button "communicates active/inactive
  statically." `PluginSidebar` auto-creates the pinned button from `title`/`icon`; making the
  *button itself* flip active→inactive live is non-trivial (dynamic icon/title). v1: keep the
  button static ("Sudo") and put the live state in the panel body. Confirm this reads acceptably.
- **Page-load snackbar** is included above (brief leaned toward it). Confirm it isn't noisy when
  a user reloads mid-session; if it is, gate it behind a "not shown this pageview" flag.

---

## 2. Edits to `admin/js/wp-sudo-editor-reauth.js` (emit feed #2)

Add a helper near the top of the IIFE:

```js
/**
 * Notify the in-editor session indicator (#262) of a fresh grant. Best-effort:
 * if CustomEvent is unavailable the indicator still works off the page-load feed.
 *
 * @param {number} remaining Seconds left in the granted session (from the AJAX body).
 * @return {void}
 */
function dispatchGranted( remaining ) {
	try {
		window.dispatchEvent( new CustomEvent( 'wp-sudo-session-granted', {
			detail: { remaining: parseInt( remaining, 10 ) || 0 }
		} ) );
	} catch ( e ) { /* no-op: indicator falls back to the page-load feed */ }
}
```

Widen the two success bodies to carry `remaining`:

```js
// in postPassword(): success branch
if ( json && json.success && json.data ) {
	return { ok: true, code: json.data.code, message: '', remaining: parseInt( json.data.remaining, 10 ) || 0 };
}

// in postTwoFactor(): success branch (keep status)
if ( json && json.success && json.data ) {
	return { ok: true, code: json.data.code, status: status, message: '', remaining: parseInt( json.data.remaining, 10 ) || 0 };
}
```

Dispatch on `authenticated` in both submit handlers, **before** `props.resolve( true )`:

```js
// in submitPassword():
if ( res.ok && 'authenticated' === res.code ) {
	dispatchGranted( res.remaining );
	props.resolve( true );
	return undefined;
}

// in submitTwoFactor():
if ( res.ok && 'authenticated' === res.code ) {
	dispatchGranted( res.remaining );
	props.resolve( true );
	return;
}
```

---

## 3. Enqueue — `includes/class-plugin.php` `enqueue_editor_reauth()` (~L344)

```php
// After the existing wp-sudo-editor-reauth registration + localize:
wp_enqueue_script(
	'wp-sudo-session-indicator',
	WP_SUDO_PLUGIN_URL . 'admin/js/wp-sudo-session-indicator.js',
	array( 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-plugins', 'wp-editor' ),
	WP_SUDO_VERSION,
	true
);
wp_set_script_translations( 'wp-sudo-session-indicator', 'wp-sudo' );
```

Notes:
- The indicator reads `window.wpSudoEditorReauth` (already localized on the modal handle in
  the same function), so **no separate `wp_localize_script`** is needed — the global exists on
  `window` regardless of which handle carried it. If lint/PHPStan prefers, localize a dedicated
  object instead; not required for correctness.
- `wp-editor` in deps is deliberate — its presence at runtime is the ~6.6 signal Part B
  feature-detects. On 6.4–6.5 `wp.editor.PluginSidebar` is absent and the module returns early
  to snackbar-only.
- PHP-side unit test (Brain\Monkey): assert the new handle is registered with these deps on
  `enqueue_block_editor_assets`. This part **is** verifiable without a browser.

---

## 4. E2E skeleton — `tests/e2e/specs/editor-session-indicator.spec.ts`

Mirror the harness/helpers of the existing `editor-reauth.spec.ts` (login, open editor,
trigger a gated action to raise the modal, grant). Pseudocode:

```ts
// UNVERIFIED skeleton — align imports/helpers with editor-reauth.spec.ts.
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'in-editor sudo session indicator (#262)', () => {
	test( 'grant → indicator active + countdown; expiry clears; re-grant re-seeds', async ( { admin, page, editor } ) => {
		// 1. Open the block editor; trigger a gated action so the reauth modal appears.
		//    (reuse editor-reauth.spec.ts's trigger + grant helpers)
		// 2. Complete the password grant.
		// 3. Assert Part A: a success snackbar appeared once.
		// 4. Part B (gate on wp.editor presence — 6.6+ target):
		//    const hasUnified = await page.evaluate( () => !! ( window.wp?.editor?.PluginSidebar ) );
		//    if ( hasUnified ) { open the "Sudo" sidebar; expect "Sudo active — M:SS remaining"; }
		// 5. Wait for a short-duration session to expire; expect "No active sudo session".
		// 6. Re-trigger + re-grant; expect the panel re-seeds to a positive M:SS.
	} );
} );
```

Coverage to add per the brief's test plan:
- **JS unit** (tick/format): if a JS unit harness is introduced, test `formatMSS`, clamp-at-0,
  and the active→inactive transition. Otherwise E2E is the layer (no JS unit harness today).
- **Multisite**: run under the existing `WP_MULTISITE=1` sweep; note the subdomain
  cookie/token nuance from the brief (feed #1 correctly seeds 0 on a sibling subsite).
- **`docs/current-metrics.md`**: bump the asset count (+1) and E2E spec count if a new file lands.
