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
	// at 6.6+. Below that it is absent and Part A carries the feature (design brief).
	var PluginSidebar = wp.editor && wp.editor.PluginSidebar;
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	if ( ! PluginSidebar || ! registerPlugin ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

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
		// The ticking countdown is static readable text updated in place, NOT an
		// aria-live region — a per-second live region would be an AT nuisance. The
		// grant snackbar (Part A) is the single announcement (brief a11y decision).
		return el(
			PluginSidebar,
			{
				name: 'wp-sudo-session-indicator',
				title: __( 'Sudo', 'wp-sudo' ),
				icon: 'shield',
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
