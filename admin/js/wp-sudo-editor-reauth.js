/**
 * WP Sudo – Block/Site editor reauth: in-editor grant modal (Increment 2/3).
 *
 * An `apiFetch` middleware detects a gated action's `sudo_required` REST
 * rejection — including one nested in a `/batch/v1` response envelope — and,
 * instead of dead-ending on an opaque 403, opens an in-editor password modal
 * that grants a sudo session in place and then TRANSPARENTLY re-dispatches the
 * original request, so the editor flow (e.g. Block Directory install/activate)
 * resumes without the user leaving the page.
 *
 * Password-grant floor (this increment):
 * - Modal password step mirrors the proven `wp-sudo-challenge.js` fetch flow:
 *   POST `authAction` to `admin-ajax.php` with the localized `wp_sudo_challenge`
 *   nonce, session-only (NO `stash_key`), `credentials: 'same-origin'`.
 * - On `{ code: 'authenticated' }` the modal closes and the original request is
 *   re-dispatched via `apiFetch` (NOT raw fetch) so it carries the user's own
 *   first-party `wp_rest` nonce and re-passes the now-active session (C3).
 * - Single-flight: the first `sudo_required` opens ONE modal; concurrent gated
 *   rejections attach to the same pending-grant promise and all re-dispatch once
 *   the grant lands (no duplicate modals, no double install/activate).
 *
 * Deferred to Task 4 (2FA): when the password step returns `2fa_pending`, or
 * when no grant config is present, this layer falls back to the Increment 1
 * link-out snackbar (open the server `challenge_url` in a new tab). It never
 * fabricates a reauth affordance when there is no `challenge_url` (headless /
 * application-password requests are blocked under a different code this
 * middleware ignores).
 *
 * Boundaries (design brief Parts 2–3.6):
 * - The grant nonce is the single `wp_sudo_challenge` CSRF token localized at
 *   page load (C1); a stale one is refreshed via `refreshNonceAction`.
 * - The `challenge_url` (link-out fallback) is validated same-origin http(s) and
 *   consumed verbatim, never rebuilt in JS.
 * - The rule label is never echoed; the message stays generic.
 *
 * @package WP_Sudo
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! wp.data ) {
		return;
	}

	var cfg = window.wpSudoEditorReauth || null;

	var __ = ( wp.i18n && wp.i18n.__ ) || function ( text ) {
		return text;
	};

	var NOTICE_ID = 'wp-sudo-reauth-required';

	// ---------------------------------------------------------------------
	// sudo_required detection (shared with the link-out fallback)
	// ---------------------------------------------------------------------

	/**
	 * Accept a challenge URL only if it is a same-origin http(s) URL.
	 *
	 * @param {string} url Candidate challenge_url from the error payload.
	 * @return {boolean} True when safe to open.
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
	 *   undefined → not a sudo_required payload (ignore).
	 *   null      → sudo_required with no safe challenge_url (plain message).
	 *   string    → sudo_required with a validated challenge_url.
	 *
	 * @param {Object} payload A REST error object ({code, data}) or batch inner body.
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

	// ---------------------------------------------------------------------
	// Link-out snackbar (fallback: 2FA pending, no grant config, headless)
	// ---------------------------------------------------------------------

	/**
	 * Show (or replace) the reauth snackbar.
	 *
	 * @param {(string|null)} challengeUrl URL for the link-out action, or null.
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

	// ---------------------------------------------------------------------
	// In-editor grant modal
	// ---------------------------------------------------------------------

	var element = wp.element;
	var components = wp.components;

	// The modal needs the element + components libraries and the grant config.
	// Without them we can only link out (Increment 1 behaviour).
	var canGrant = !! (
		cfg &&
		cfg.ajaxUrl &&
		cfg.nonce &&
		cfg.authAction &&
		element &&
		element.createElement &&
		components &&
		components.Modal
	);

	// Single-flight: at most one grant modal at a time. Concurrent sudo_required
	// rejections share this promise and all re-dispatch once it resolves.
	var pendingGrant = null;

	/**
	 * POST the password step to admin-ajax, mirroring wp-sudo-challenge.js.
	 *
	 * @param {string} password Plaintext password (never stored/sanitized here).
	 * @param {string} nonce    The wp_sudo_challenge grant nonce to send.
	 * @return {Promise<Object>} Resolves { ok, code, message } describing the result.
	 */
	function postPassword( password, nonce ) {
		var body = new FormData();
		body.append( 'action', cfg.authAction );
		body.append( '_wpnonce', nonce );
		body.append( 'password', password );
		// Session-only: no stash_key — the editor re-dispatches its own request.

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		} ).then( function ( r ) {
			return r.text().then( function ( text ) {
				var json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					return { ok: false, code: 'unexpected', message: __( 'Unexpected server response.', 'wp-sudo' ) };
				}
				if ( json && json.success && json.data ) {
					return { ok: true, code: json.data.code, message: '' };
				}
				var msg = ( json && json.data && json.data.message ) || __( 'Authentication failed.', 'wp-sudo' );
				return { ok: false, code: ( json && json.data && json.data.code ) || 'error', message: msg };
			} );
		}, function () {
			return { ok: false, code: 'network', message: __( 'Network error. Please try again.', 'wp-sudo' ) };
		} );
	}

	/**
	 * Fetch a fresh grant nonce (for an editor open past the ~24h nonce life).
	 *
	 * @return {Promise<string>} Resolves a fresh nonce, or the current one on failure.
	 */
	function refreshNonce() {
		if ( ! cfg.refreshNonceAction ) {
			return Promise.resolve( cfg.nonce );
		}
		var body = new FormData();
		body.append( 'action', cfg.refreshNonceAction );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success && json.data && json.data.nonce ) {
					cfg.nonce = json.data.nonce;
				}
				return cfg.nonce;
			}, function () { return cfg.nonce; } );
	}

	/**
	 * The modal form component (functional, build-free via createElement).
	 *
	 * @param {Object} props           Component props.
	 * @param {Function} props.resolve Called with true (granted) / false (link-out/cancel).
	 * @return {Object} React element.
	 */
	function ReauthModal( props ) {
		var el = element.createElement;
		var state = element.useState( '' );
		var password = state[ 0 ];
		var setPassword = state[ 1 ];
		var busyState = element.useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var errState = element.useState( '' );
		var error = errState[ 0 ];
		var setError = errState[ 1 ];

		function submit( e ) {
			if ( e && e.preventDefault ) {
				e.preventDefault();
			}
			if ( ! password || busy ) {
				return;
			}
			setBusy( true );
			setError( '' );
			refreshNonce().then( function ( nonce ) {
				return postPassword( password, nonce );
			} ).then( function ( res ) {
				if ( res.ok && 'authenticated' === res.code ) {
					props.resolve( true );
					return;
				}
				if ( res.ok && '2fa_pending' === res.code ) {
					// 2FA is not handled in-modal yet (Task 4) — link out.
					props.resolve( false );
					return;
				}
				setBusy( false );
				setError( res.message );
			} );
		}

		return el(
			components.Modal,
			{
				title: __( 'Confirm your identity', 'wp-sudo' ),
				onRequestClose: function () {
					if ( ! busy ) {
						props.resolve( false );
					}
				},
				className: 'wp-sudo-reauth-modal',
			},
			el(
				'form',
				{ onSubmit: submit },
				el(
					'p',
					null,
					__( 'This action requires reauthentication. Enter your password to continue.', 'wp-sudo' )
				),
				el( components.TextControl, {
					type: 'password',
					label: __( 'Password', 'wp-sudo' ),
					value: password,
					disabled: busy,
					autoComplete: 'current-password',
					onChange: function ( value ) {
						setPassword( value );
					},
				} ),
				error
					? el( components.Notice, { status: 'error', isDismissible: false }, error )
					: null,
				el(
					'div',
					{ className: 'wp-sudo-reauth-modal__actions', style: { display: 'flex', gap: '8px', marginTop: '12px' } },
					el(
						components.Button,
						{ variant: 'primary', type: 'submit', isBusy: busy, disabled: busy || ! password },
						__( 'Confirm', 'wp-sudo' )
					),
					el(
						components.Button,
						{ variant: 'tertiary', disabled: busy, onClick: function () { props.resolve( false ); } },
						__( 'Cancel', 'wp-sudo' )
					)
				)
			)
		);
	}

	/**
	 * Open the grant modal once and resolve true (granted) / false (fallback).
	 *
	 * @return {Promise<boolean>}
	 */
	function openModal() {
		return new Promise( function ( resolve ) {
			var container = document.createElement( 'div' );
			document.body.appendChild( container );
			var root = element.createRoot ? element.createRoot( container ) : null;

			function cleanup( granted ) {
				if ( root ) {
					root.unmount();
				} else if ( element.unmountComponentAtNode ) {
					element.unmountComponentAtNode( container );
				}
				if ( container.parentNode ) {
					container.parentNode.removeChild( container );
				}
				resolve( granted );
			}

			var node = element.createElement( ReauthModal, { resolve: cleanup } );
			if ( root ) {
				root.render( node );
			} else if ( element.render ) {
				element.render( node, container );
			} else {
				cleanup( false );
			}
		} );
	}

	/**
	 * Ensure a single in-flight grant; concurrent callers share it.
	 *
	 * @return {Promise<boolean>} Resolves true when a session was granted in-editor.
	 */
	function requestGrant() {
		if ( ! pendingGrant ) {
			pendingGrant = openModal().then(
				function ( granted ) { pendingGrant = null; return granted; },
				function () { pendingGrant = null; return false; }
			);
		}
		return pendingGrant;
	}

	// ---------------------------------------------------------------------
	// apiFetch middleware
	// ---------------------------------------------------------------------

	/**
	 * Handle a detected sudo_required: open the grant modal and, on success,
	 * transparently re-dispatch the original request. Falls back to the link-out
	 * snackbar when a grant is not possible (no config) or not completed here
	 * (2FA pending / cancelled). challengeUrl is the validated link-out target
	 * (string) or null.
	 *
	 * @param {(string|null)} challengeUrl Link-out fallback URL, or null.
	 * @param {(Object|undefined)} options apiFetch options to re-dispatch on grant.
	 * @return {(Promise|undefined)} A re-dispatch promise when granted, else undefined.
	 */
	function handleSudoRequired( challengeUrl, options ) {
		if ( ! canGrant ) {
			surface( challengeUrl );
			return Promise.resolve( undefined );
		}
		return requestGrant().then( function ( granted ) {
			if ( granted && options ) {
				// Re-dispatch through apiFetch so the user's own wp_rest nonce is
				// attached and the now-active session is re-evaluated (C3). The
				// resolved value is the successful response for the original caller.
				return wp.apiFetch( options );
			}
			// Not granted in-editor (2FA / cancel / no options) — offer link-out.
			surface( challengeUrl );
			return undefined;
		} );
	}

	wp.apiFetch.use( function ( options, next ) {
		return next( options ).then(
			function ( response ) {
				var result = batchChallenge( response );
				if ( undefined === result ) {
					return response;
				}
				// On grant, resolve with the re-dispatched result; otherwise keep
				// the original batch response.
				return handleSudoRequired( result, options ).then( function ( redispatched ) {
					return undefined !== redispatched ? redispatched : response;
				} );
			},
			function ( error ) {
				var result = sudoChallenge( error );
				if ( undefined === result ) {
					throw error;
				}
				// On a successful in-editor grant, resolve with the re-dispatched
				// result so the original caller transparently succeeds. Otherwise
				// preserve the original rejection so callers are not left hanging.
				return handleSudoRequired( result, options ).then( function ( redispatched ) {
					if ( undefined !== redispatched ) {
						return redispatched;
					}
					throw error;
				} );
			}
		);
	} );
} )( window.wp );
