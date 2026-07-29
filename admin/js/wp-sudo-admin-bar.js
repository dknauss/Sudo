/**
 * WP Sudo – Admin bar countdown timer.
 *
 * CSP-compatible: loaded as an external enqueued script with
 * configuration passed via wp_localize_script().
 *
 * @package WP_Sudo
 */
( function() {
	'use strict';

	var config = window.wpSudoAdminBar || {};
	var r = parseInt( config.remaining, 10 ) || 0;

	if ( r <= 0 ) {
		return;
	}

	var n = document.getElementById( 'wp-admin-bar-wp-sudo-active' );
	if ( ! n ) {
		return;
	}

	var l = n.querySelector( '.ab-label' );
	if ( ! l ) {
		return;
	}

	var a = n.querySelector( '.ab-item' );
	l.setAttribute( 'role', 'timer' );
	l.setAttribute( 'aria-live', 'off' );
	l.setAttribute( 'aria-atomic', 'true' );

	// Create a separate live region for milestone announcements
	// so we don't flood AT with every-second updates.
	var sr = document.createElement( 'span' );
	sr.className = 'wp-sudo-sr-only';
	sr.setAttribute( 'role', 'status' );
	sr.setAttribute( 'aria-live', 'assertive' );
	sr.setAttribute( 'aria-atomic', 'true' );
	n.appendChild( sr );

	// Track which milestones have been announced.
	var milestones = { 60: false, 30: false, 10: false, 0: false };
	var intervalId = null;

	/**
	 * Render the current remaining time.
	 *
	 * @return {void}
	 */
	function renderTime() {
		var m = Math.floor( r / 60 );
		var s = r % 60;
		l.textContent = 'Sudo: ' + m + ':' + ( s < 10 ? '0' : '' ) + s;

		if ( r <= 60 ) {
			n.classList.add( 'wp-sudo-expiring' );
		} else {
			n.classList.remove( 'wp-sudo-expiring' );
		}
	}

	/**
	 * Advance the timer by one second.
	 *
	 * @return {void}
	 */
	function tick() {
		r--;
		if ( r <= 0 ) {
			clearInterval( intervalId );
			intervalId = null;
			l.textContent = 'Sudo: 0:00';
			sr.textContent = 'Sudo session expired.';
			if ( 0 !== Number( config.reload_on_expiry ) ) {
				window.location.reload();
			} else {
				if ( a ) {
					a.hidden = true;
				}
				n.classList.remove( 'wp-sudo-active', 'wp-sudo-expiring' );
			}
			return;
		}

		renderTime();

		// Announce at milestone intervals only.
		if ( r === 60 && ! milestones[ 60 ] ) {
			milestones[ 60 ] = true;
			sr.textContent = 'Sudo session: 1 minute remaining.';
		} else if ( r === 30 && ! milestones[ 30 ] ) {
			milestones[ 30 ] = true;
			sr.textContent = 'Sudo session: 30 seconds remaining.';
		} else if ( r === 10 && ! milestones[ 10 ] ) {
			milestones[ 10 ] = true;
			sr.textContent = 'Sudo session: 10 seconds remaining.';
		}
	}

	/**
	 * Start or restart the countdown.
	 *
	 * @return {void}
	 */
	function startTimer() {
		if ( null !== intervalId ) {
			clearInterval( intervalId );
		}
		intervalId = setInterval( tick, 1000 );
	}

	startTimer();

	// The block editor can grant a new session without navigating. Keep the
	// persistent admin-bar status synchronized with the editor indicator's
	// established session event.
	window.addEventListener( 'wp-sudo-session-granted', function( event ) {
		var remaining = parseInt( event.detail && event.detail.remaining, 10 ) || 0;
		if ( remaining <= 0 ) {
			return;
		}

		r = remaining;
		milestones = { 60: false, 30: false, 10: false, 0: false };
		if ( a ) {
			a.hidden = false;
		}
		n.classList.add( 'wp-sudo-active' );
		sr.textContent = '';
		renderTime();
		startTimer();
	} );

	// Clean up interval on page unload to prevent bfcache issues.
	window.addEventListener( 'pagehide', function() {
		clearInterval( intervalId );
	} );

	// Keyboard shortcut: Ctrl+Shift+S / Cmd+Shift+S flashes the
	// admin bar node to acknowledge the session is already active.
	document.addEventListener( 'keydown', function( e ) {
		if ( e.shiftKey && ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 's' ) {
			e.preventDefault();
			if ( ! a ) {
				return;
			}
			// Skip animation if user prefers reduced motion.
			if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
				return;
			}
			a.style.setProperty( 'transition', 'background 0.15s ease', 'important' );
			a.style.setProperty( 'background', '#4caf50', 'important' );
			setTimeout( function() {
				a.style.removeProperty( 'background' );
				a.style.removeProperty( 'transition' );
			}, 300 );
		}
	} );
}() );
