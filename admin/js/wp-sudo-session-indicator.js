/**
 * WP Sudo – In-editor sudo session-status indicator (#262 / #182).
 *
 * The full-screen block/site editor hides the admin bar, so the admin-bar
 * countdown (wp-sudo-admin-bar.js) never renders while editing. This module
 * surfaces the same session state without a read endpoint or polling:
 *
 *   Part A (baseline, WP 6.4+): an announce-once `core/notices` snackbar — once
 *     on a fresh in-editor grant, and once at page load when a session is already
 *     active (gated so a reload does not re-announce).
 *   Part B (enhancement, WP 6.6+): a feature-detected PluginSidebar whose panel
 *     shows a live M:SS countdown that ticks down locally.
 *
 * Data feeds (NO new endpoint, NO polling):
 *   #1 page load  — window.wpSudoEditorReauth.remaining (server-gated on is_active()).
 *   #2 post-grant — the `wp-sudo-session-granted` window CustomEvent that
 *                   wp-sudo-editor-reauth.js dispatches on a successful grant
 *                   (detail.remaining). The indicator and the modal are decoupled
 *                   ON PURPOSE: neither imports the other; they meet only at this event.
 *
 * The countdown is anchored to an ABSOLUTE deadline (Date.now() + remaining), never
 * a per-second decrementing counter: browsers throttle background-tab timers, so a
 * decrementing counter would leave the panel showing a confidently-wrong "still
 * active" time for an already-expired session after a long edit. The deadline lets
 * every tick — and a visibilitychange/focus re-sync — re-derive the truth locally.
 *
 * Informational only: it never mints, extends, or refreshes a session. During the
 * 120 s post-expiry grace window it reads "inactive" (feed values are already 0),
 * matching the todo's conservative direction; the server stays authoritative.
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
	var __ = i18n.__ || function ( text ) { return text; };
	var sprintf = i18n.sprintf || function ( fmt ) { return fmt; };
	var domReady = wp.domReady || function ( fn ) { fn(); };

	var GRANT_EVENT = 'wp-sudo-session-granted';
	var SNACKBAR_ID = 'wp-sudo-session-active';
	var ANNOUNCE_KEY = 'wpSudoIndicatorAnnounced';

	// --- #288: at-a-glance state on the pinned header button -----------------
	// The admin bar's own expiring threshold (wp-sudo-admin-bar.js: if (r <= 60)),
	// reused verbatim so the two surfaces flip red at the same moment.
	var EXPIRING_THRESHOLD = 60;
	// The expiring chip paints in every mode; the active chip paints only where core
	// has taken the glyph away (see syncIconLabels below), so CSS needs all three.
	var BODY_CLASS_EXPIRING = 'wp-sudo-editor-session-expiring';
	var BODY_CLASS_ACTIVE = 'wp-sudo-editor-session-active';
	var BODY_CLASS_ICON_LABELS = 'wp-sudo-editor-icon-labels';
	// Two page loads of the SAME session compute deadlines within a few seconds of
	// each other; a genuinely new (re-granted) session extends the deadline by the
	// full duration (>= 60 s), so this tolerance separates "same session, reloaded"
	// (suppress) from "new session" (announce) without a server-issued session id.
	var ANNOUNCE_TOLERANCE_MS = 60000;

	// --- countdown state: an ABSOLUTE deadline, not a decrementing counter ----
	var deadline = 0; // epoch ms; 0 = inactive.
	var intervalId = null;
	var listeners = [];

	/**
	 * Seconds left, derived from the absolute deadline. 0 when inactive or expired.
	 *
	 * @return {number} Whole seconds remaining, clamped at 0.
	 */
	function currentRemaining() {
		if ( ! deadline ) {
			return 0;
		}
		var secs = Math.round( ( deadline - Date.now() ) / 1000 );
		return secs > 0 ? secs : 0;
	}

	function notify() {
		for ( var i = 0; i < listeners.length; i++ ) {
			listeners[ i ]();
		}
	}

	function subscribe( fn ) {
		listeners.push( fn );
		return function () {
			listeners = listeners.filter( function ( l ) { return l !== fn; } );
		};
	}

	function formatMSS( secs ) {
		var m = Math.floor( secs / 60 );
		var s = secs % 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	/**
	 * Collapse the per-second countdown to the THREE states the UI actually has.
	 *
	 * The single source of truth for both #288 state signals — the body class that
	 * colours the pinned header button and the accessible name / glyph the button
	 * carries — so the two cannot drift apart. Coarse by design: keying off this
	 * string is what keeps the body-class toggle off the per-second path.
	 *
	 * @param {number} secs Seconds remaining.
	 * @return {string} 'inactive' | 'expiring' | 'active'.
	 */
	function sessionState( secs ) {
		if ( secs <= 0 ) {
			return 'inactive';
		}
		return secs <= EXPIRING_THRESHOLD ? 'expiring' : 'active';
	}

	function stopTicker() {
		if ( intervalId ) {
			clearInterval( intervalId );
			intervalId = null;
		}
	}

	function startTicker() {
		if ( intervalId || currentRemaining() <= 0 ) {
			return;
		}
		intervalId = setInterval( function () {
			if ( currentRemaining() <= 0 ) {
				stopTicker();
			}
			notify();
		}, 1000 );
	}

	/**
	 * (Re)seed the countdown from a seconds value (feed #1 seeds; feed #2 re-seeds).
	 *
	 * @param {(number|string)} secs Seconds remaining from a feed.
	 * @return {void}
	 */
	function seed( secs ) {
		var s = parseInt( secs, 10 ) || 0;
		deadline = s > 0 ? Date.now() + s * 1000 : 0;
		stopTicker();
		startTicker();
		notify();
	}

	// Re-derive from the absolute deadline when the tab returns to the foreground —
	// this closes the background-tab timer-throttling gap so a returning user never
	// sees a stale "still active" number for a session that already expired.
	function resync() {
		if ( currentRemaining() <= 0 ) {
			stopTicker();
		} else {
			startTicker();
		}
		notify();
	}
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			resync();
		}
	} );
	window.addEventListener( 'focus', resync );

	// pagehide cleanup (bfcache safety) — mirrors wp-sudo-admin-bar.js.
	window.addEventListener( 'pagehide', stopTicker );

	// --- announce-once helpers (a11y) ---------------------------------------
	// The page-load snackbar must not re-fire on every reload / editor navigation
	// while a session is active (that would regress the brief's announce-once a11y
	// contract). Keyed on the absolute deadline as a stable per-session marker.
	function alreadyAnnounced() {
		try {
			var prev = parseInt( window.sessionStorage.getItem( ANNOUNCE_KEY ), 10 );
			return !! prev && Math.abs( prev - deadline ) < ANNOUNCE_TOLERANCE_MS;
		} catch ( e ) {
			return false;
		}
	}
	function markAnnounced() {
		try {
			window.sessionStorage.setItem( ANNOUNCE_KEY, String( deadline ) );
		} catch ( e ) {
			/* private-mode storage — degrade to "may re-announce"; not fatal. */
		}
	}

	// --- Part A: the snackbar ------------------------------------------------
	function snackbar( secs ) {
		var notices = wp.data.dispatch( 'core/notices' );
		if ( ! notices || ! notices.createNotice ) {
			return;
		}
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

	// --- feed #2: grant event from the reauth modal -------------------------
	// Registered BEFORE the Part B feature-detect early-return so 6.4–6.5 (snackbar
	// only) still re-seeds and confirms a grant. The modal dispatches this after a
	// successful `authenticated` response; the two modules never import each other.
	window.addEventListener( GRANT_EVENT, function ( e ) {
		var secs = ( e && e.detail && parseInt( e.detail.remaining, 10 ) ) || 0;
		if ( secs > 0 ) {
			seed( secs );
			// A grant is a genuine new event — always confirm, then record the marker
			// so an immediate reload does not double-announce the same session.
			snackbar( secs );
			markAnnounced();
		}
	} );

	// --- feed #1: page load (active-at-load) --------------------------------
	// Seed synchronously so Part B's first render is correct; defer the snackbar to
	// domReady (notices UI mounted) and gate it announce-once so reloads stay quiet.
	seed( cfg.remaining );
	domReady( function () {
		var secs = currentRemaining();
		if ( secs > 0 && ! alreadyAnnounced() ) {
			snackbar( secs );
			markAnnounced();
		}
	} );

	// --- Part B: feature-detected PluginSidebar (WP 6.6+ unified API) --------
	// The unified wp.editor.PluginSidebar renders in BOTH the post and site editors
	// starting in WordPress 6.6; before 6.6 it existed only as wp.editPost.PluginSidebar
	// (post editor only). Verified against the 6.6 dev note "Editor unified extensibility
	// APIs in 6.6" (make.wordpress.org/core/2024/06/18/editor-unified-extensibility-apis-in-6-6/).
	// Below 6.6 wp.editor.PluginSidebar is absent and Part A (snackbar) carries the feature.
	var PluginSidebar = wp.editor && wp.editor.PluginSidebar;
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	if ( ! PluginSidebar || ! registerPlugin ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	// --- #288: the final-minute chip on the pinned header button ---------------
	// The pinned button is the only part of this feature visible while the panel is
	// CLOSED, which is the whole point in the full-screen editor: no admin bar, so no
	// chip and no countdown anywhere else on screen.
	//
	// ONLY the expiring state paints. State is otherwise carried by the glyph (the
	// `icon` pick in IndicatorPanel below), because that is what core does here: the sole
	// background change core applies to a pinned-item button is the neutral
	// `.is-pressed` fill, never a semantic colour, while a conditional icon on this very
	// button is core's own construction (`icon={ showIconLabels ? check : icon }`).
	// So a green chip for the whole session would invent a convention and park colour
	// in the header for 15 minutes at a time; one red chip for the last 60 s spends it
	// where it is earned.
	//
	// State is carried by a class on <body>, not on the button. Gutenberg re-renders
	// that button with a fresh `className` prop every time `is-pressed` flips (opening
	// or closing the panel), silently wiping any class added to it from outside React.
	// Body class rather than a `:has()` selector because `:has()` post-dates the
	// plugin's WP 6.4 floor in Firefox (121, Dec 2023).
	//
	// Deliberately NOT a `useEffect` in IndicatorPanel: that component re-renders every
	// second (the subscribe/setSecs pump below), so a component-scoped effect would need
	// exactly-correct cleanup or the site editor — an SPA, where document.body outlives
	// route changes — keeps a stale chip painted after unmount. A module-level subscriber
	// with a `lastState` guard has no unmount to get wrong and touches classList only on
	// a real transition, never per tick.
	//
	// Armed only AFTER the WP 6.6+ feature detect above, so no class is set on 6.4-6.5,
	// where wp.editor.PluginSidebar is absent and Part A carries the feature. It IS armed
	// on any other screen that fires enqueue_block_editor_assets — notably the widgets
	// editor — because `wp-editor` is a declared dependency of this script, so the detect
	// passes there too. Harmless: no button with our `aria-controls` renders outside the
	// post/site editor, so the class matches no rule and nothing paints.
	// The matching CSS is admin/css/wp-sudo-editor-indicator.css.
	var lastState = null;
	function syncBodyState() {
		var state = sessionState( currentRemaining() );
		if ( state === lastState ) {
			return;
		}
		lastState = state;
		var classes = document.body.classList;
		classes.toggle( BODY_CLASS_EXPIRING, 'expiring' === state );
		// EXACTLY 'active', not "not inactive": the two classes are mutually exclusive,
		// so the stylesheet needs no source-order tiebreak between them.
		classes.toggle( BODY_CLASS_ACTIVE, 'active' === state );
	}
	subscribe( syncBodyState );
	syncBodyState();

	// --- the one place the active state still earns colour ---------------------
	// Core's "Show button text labels" preference takes this button's icon slot for
	// its own `check` glyph — `icon={ showIconLabels ? check : icon }` — and disables
	// the tooltip in the same breath (`showTooltip={ ! showIconLabels }`,
	// complementary-area/index.js L280-281, trunk, fetched 2026-07-26). Verified in a
	// live WP 7.0 editor: with it on, all three states render that same `check` SVG,
	// `.dashicon` is gone, and the button renders no visible text.
	//
	// So for that cohort the glyph vocabulary does not exist and there is no hover
	// affordance either — active and inactive would be one appearance, which is the
	// #288 bug this feature exists to fix. Colour is the only channel core leaves,
	// so the active chip comes back THERE and only there: the default view keeps no
	// semantic colour but the urgent red, which is the whole point of the redesign.
	//
	// A body class again rather than a React prop: the paint is CSS either way, and
	// this keeps the preference out of IndicatorPanel's per-second render path.
	var lastIconLabels = null;
	function syncIconLabels() {
		var prefs = wp.data.select( 'core/preferences' );
		// `core/preferences` is registered by the editor, not by wp.data itself, and
		// this module also loads on the widgets screen — hence the capability check
		// rather than an assumption. Absent store reads as "preference off", which is
		// the safe default: no chip.
		var on = !! ( prefs && prefs.get && prefs.get( 'core', 'showIconLabels' ) );
		if ( on === lastIconLabels ) {
			return;
		}
		lastIconLabels = on;
		document.body.classList.toggle( BODY_CLASS_ICON_LABELS, on );
	}
	// Scoped to the preferences store (`subscribe( listener, storeNameOrDescriptor )`,
	// @wordpress/data registry.ts L62-86) so this does not run on every editor
	// keystroke. The `lastIconLabels` guard makes it a no-op even where an older
	// registry ignores the second argument and subscribes globally.
	wp.data.subscribe( syncIconLabels, 'core/preferences' );
	syncIconLabels();

	function IndicatorPanel() {
		var st = useState( currentRemaining() );
		var secs = st[ 0 ];
		var setSecs = st[ 1 ];
		useEffect( function () {
			return subscribe( function () {
				setSecs( currentRemaining() );
			} );
		}, [] );

		var active = secs > 0;
		var state = sessionState( secs );

		// The pinned header button exposes state through its accessible name (the
		// PluginSidebar `title`), which changes only on a transition — NOT per second —
		// so the persistent control reflects state even while the panel is closed
		// (design brief Part B), without a per-tick live region (that would be an AT
		// nuisance; the grant snackbar is the single spoken announcement). The ticking
		// M:SS itself stays static readable text inside the panel.
		//
		// "expiring" is a THIRD name state added in #288, giving AT users parity with
		// the sighted red cue below. It costs one extra transition per session, and an
		// accessible-name change on an unfocused button is not announced at all — a
		// screen reader reads it only when the user reaches the control — so it adds no
		// interruption. Note `title` is also the open panel's heading and the Options-menu
		// entry, so all three surfaces stay in step.
		var title;
		if ( 'expiring' === state ) {
			title = __( 'Sudo · expiring', 'wp-sudo' );
		} else if ( 'active' === state ) {
			title = __( 'Sudo · active', 'wp-sudo' );
		} else {
			title = __( 'Sudo · inactive', 'wp-sudo' );
		}

		// Glyph — the PRIMARY state channel, one shape per state:
		//
		//   inactive   lock      a closed padlock: not elevated
		//   active     unlock    the admin bar's own glyph (class-admin-bar.php)
		//   expiring   warning   a third, non-padlock shape for the final 60 s
		//
		// A state-driven icon on this control is a core pattern, not an invention. Core
		// swaps this button's icon conditionally itself — `icon={ showIconLabels ? check
		// : icon }` (packages/interface/src/components/complementary-area/index.js L280)
		// — and the component it renders takes a second, state-selected icon by design:
		// `icon={ selectedIcon && isSelected ? selectedIcon : icon }`
		// (packages/interface/src/components/complementary-area-toggle/index.js L58).
		// Both Gutenberg trunk, fetched 2026-07-26. (The `isPinned ? starFilled :
		// starEmpty` swap at complementary-area/index.js L326 is a DIFFERENT control —
		// the pin/unpin star inside the panel header — so it is not the precedent here.)
		// Semantic BACKGROUND colour has no such precedent: core's only background change
		// on these buttons is the neutral `.is-pressed` fill
		// (packages/components/src/button/style.scss L342-348), which is why colour here
		// is spent on the expiring state alone.
		//
		// Carrying state on shape also settles WCAG 1.4.1 by construction rather than by
		// supplement: #2e7d32 and #c62828 are 1.09:1 against EACH OTHER, so a colour-led
		// vocabulary collapses to one swatch under a red-green deficiency, in greyscale,
		// and in forced-colors mode. Three distinct shapes survive all three. The
		// accessible name above cannot discharge 1.4.1 (a name is programmatic, not
		// visual), so it tracks the same three states rather than substituting for them.
		//
		// This channel is unavailable under core's "Show button text labels" preference,
		// which discards the icon for its own `check` glyph (the L280 line above) and
		// renders no visible text for a pinned item. That is handled by falling back to
		// colour in exactly that mode — see syncIconLabels() above and the paired rules
		// in admin/css/wp-sudo-editor-indicator.css — because a shape channel core has
		// taken away cannot carry the vocabulary. The accessible name is unaffected in
		// either mode.
		//
		// Per-transition, not per-second — the churn the design brief forbids is a value
		// that changes on every tick, which is why the compact M:SS stays out of `title`.
		var icon;
		if ( 'expiring' === state ) {
			icon = 'warning';
		} else if ( 'active' === state ) {
			icon = 'unlock';
		} else {
			icon = 'lock';
		}

		return el(
			PluginSidebar,
			{
				name: 'wp-sudo-session-indicator',
				title: title,
				icon: icon,
			},
			el(
				'div',
				{ className: 'wp-sudo-indicator-panel', style: { padding: '16px' } },
				active
					? el(
							'p',
							null,
							sprintf(
								/* translators: %s: time remaining in M:SS format */
								__( 'Sudo active — %s remaining.', 'wp-sudo' ),
								formatMSS( secs )
							)
					  )
					: el( 'p', null, __( 'No active sudo session.', 'wp-sudo' ) )
			)
		);
	}

	registerPlugin( 'wp-sudo-session-indicator', { render: IndicatorPanel } );
} )( window.wp );
