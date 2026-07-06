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
 * - When `challenge_url` is absent (headless / application-password sessions get
 *   `sudo_blocked` with no URL), the snackbar degrades to a plain message with no
 *   action — it must never fabricate a reauth affordance for a headless client.
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
	 * Classify a REST error / inner-response body as a sudo_required payload.
	 *
	 * Tri-state so callers can distinguish "not ours" from "ours but no URL":
	 *   undefined → not a sudo_required payload (ignore).
	 *   null      → sudo_required with no challenge_url (headless — plain message).
	 *   string    → sudo_required with a challenge_url (offer the link-out action).
	 *
	 * @param {Object} payload A REST error object ({code, data}) or a batch inner body.
	 * @return {(string|null|undefined)} See above.
	 */
	function sudoChallenge( payload ) {
		if ( ! payload || 'sudo_required' !== payload.code ) {
			return undefined;
		}
		var url = payload.data && payload.data.challenge_url;
		return ( 'string' === typeof url && url ) ? url : null;
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
