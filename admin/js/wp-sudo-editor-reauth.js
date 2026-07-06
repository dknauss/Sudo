/**
 * WP Sudo – Block/Site editor reauth: surface `sudo_required` as a link-out snackbar.
 *
 * Increment 1 (build-free, link-out only). An `apiFetch` middleware detects a
 * `sudo_required` REST rejection — including one nested in a `/batch/v1` response
 * envelope — and, instead of letting the editor dead-end on an opaque 403, shows
 * an in-editor snackbar with a "Reauthenticate" action that opens the
 * server-emitted `challenge_url` in a new tab. The editor state is preserved; the
 * user grants a sudo session on the challenge page and manually retries.
 *
 * NOT in this increment: the in-editor password/2FA modal, the AJAX session
 * grant, and automatic re-dispatch of the original request (Increment 2). This
 * layer only *notifies*; it never grants a session and never re-fires a request.
 *
 * Deliberate boundaries (design brief Parts 2–3.6):
 * - The `challenge_url` is consumed verbatim from the server error — never rebuilt
 *   in JS (multisite/network-admin routing is server-side and referrer-fragile).
 * - The rule label is never echoed: `plugin.activate`/`plugin.deactivate` share a
 *   route and the matched label can be wrong, so the message stays generic.
 * - The link-out is DEFENSIVE about the URL. Because this middleware fires on any
 *   REST payload carrying `code: 'sudo_required'`, the `challenge_url` is offered
 *   only when it is present AND a same-origin http(s) URL; a missing, malformed,
 *   cross-origin, or `javascript:` URL degrades to a plain message with no action.
 *   The URL is validated, never rewritten. (In practice the cookie-auth
 *   `sudo_required` branch always carries a valid same-origin URL; headless /
 *   application-password requests are blocked under a different code this
 *   middleware ignores, so the no-URL path is a safety net, not a normal flow.)
 *
 * @package WP_Sudo
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! wp.data ) {
		return;
	}

	var __ = ( wp.i18n && wp.i18n.__ ) || function ( text ) {
		return text;
	};

	// Fixed id → createNotice replaces rather than stacks, so concurrent gated
	// requests (and a grant that just landed in the grace window) collapse to a
	// single snackbar instead of piling up.
	var NOTICE_ID = 'wp-sudo-reauth-required';

	/**
	 * Accept a challenge URL only if it is a same-origin http(s) URL.
	 *
	 * The URL is server-emitted, but this middleware fires on any REST payload
	 * carrying `code: 'sudo_required'`, so a buggy or hostile response must not be
	 * able to drive window.open() to a `javascript:` URI or a cross-origin target.
	 * The candidate is parsed against the current location purely to validate it;
	 * the original string is what gets opened, so the URL is never rewritten.
	 *
	 * @param {string} url Candidate challenge_url from the error payload.
	 * @return {boolean} True when the URL is safe to open.
	 */
	function isSafeChallengeUrl( url ) {
		try {
			var parsed = new URL( url, window.location.href );
			return (
				( 'https:' === parsed.protocol || 'http:' === parsed.protocol ) &&
				parsed.origin === window.location.origin
			);
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Classify a REST error / inner-response body as a sudo_required payload.
	 *
	 * Tri-state so callers can distinguish "not ours" from "ours but no usable URL":
	 *   undefined → not a sudo_required payload (ignore).
	 *   null      → sudo_required with no safe challenge_url (plain message, no action).
	 *   string    → sudo_required with a validated challenge_url (offer the link-out).
	 *
	 * @param {Object} payload A REST error object ({code, data}) or a batch inner body.
	 * @return {(string|null|undefined)} See above.
	 */
	function sudoChallenge( payload ) {
		if ( ! payload || 'sudo_required' !== payload.code ) {
			return undefined;
		}
		var url = payload.data && payload.data.challenge_url;
		return ( 'string' === typeof url && url && isSafeChallengeUrl( url ) ) ? url : null;
	}

	/**
	 * Detect a sudo_required nested inside a /batch/v1 response envelope.
	 *
	 * The gated `plugins` controller is not batchable in core today, so this is
	 * detect-and-surface only (no batch re-dispatch): it prevents a future or
	 * third-party gated batch route from silently no-opping. Returns the same
	 * tri-state as sudoChallenge() for the first matching inner response.
	 *
	 * @param {Object} response A resolved apiFetch response.
	 * @return {(string|null|undefined)} See sudoChallenge().
	 */
	function batchChallenge( response ) {
		if ( ! response || ! Array.isArray( response.responses ) ) {
			return undefined;
		}
		for ( var i = 0; i < response.responses.length; i++ ) {
			var entry = response.responses[ i ];
			var result = sudoChallenge( entry && entry.body );
			if ( undefined !== result ) {
				return result;
			}
		}
		return undefined;
	}

	/**
	 * Show (or replace) the reauth snackbar.
	 *
	 * @param {(string|null)} challengeUrl URL for the link-out action, or null for
	 *                                     a plain message with no action (headless).
	 * @return {void}
	 */
	function surface( challengeUrl ) {
		var notices = wp.data.dispatch( 'core/notices' );
		if ( ! notices || ! notices.createNotice ) {
			return;
		}

		var actions = [];
		if ( challengeUrl ) {
			actions.push( {
				label: __( 'Reauthenticate', 'wp-sudo' ),
				// Open in a new tab so the editor (and any unsaved state) stays put.
				onClick: function () {
					window.open( challengeUrl, '_blank', 'noopener' );
				},
			} );
		}

		notices.createNotice(
			'warning',
			__( 'This action requires reauthentication.', 'wp-sudo' ),
			{
				id: NOTICE_ID,
				type: 'snackbar',
				isDismissible: true,
				actions: actions,
			}
		);
	}

	wp.apiFetch.use( function ( options, next ) {
		return next( options ).then(
			function ( response ) {
				var result = batchChallenge( response );
				if ( undefined !== result ) {
					surface( result );
				}
				return response;
			},
			function ( error ) {
				var result = sudoChallenge( error );
				if ( undefined !== result ) {
					surface( result );
				}
				// Increment 1 does not re-dispatch — let the original call reject
				// as before so callers are not left hanging.
				throw error;
			}
		);
	} );
} )( window.wp );
