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
 * - Single-flight, owner-scoped re-dispatch: the first `sudo_required` opens ONE
 *   modal and OWNS the grant; concurrent gated rejections attach to the same
 *   pending-grant promise (no duplicate modals). On a grant ONLY the owner (the
 *   request that opened the modal = the user's actioned request) is re-dispatched.
 *   Concurrent non-owner rejections — background/secondary gated requests the user
 *   did not action at that moment — are left rejected rather than replayed, so no
 *   unconfirmed mutation fires (Q3). They self-heal on a natural retry against the
 *   now-active session.
 *
 * In-modal 2FA (Milestone B): when the password step returns `2fa_pending` for a
 * modal-capable account (localized `twoFactorModalCapable`), the modal fetches the
 * primary provider's server-rendered 2FA partial, injects it into a contained
 * non-form node, and POSTs the generically-serialized fields back to the unchanged
 * validator — hosting TOTP / email-OTP / backup-code reauth in place. A non-capable
 * 2FA account (WebAuthn/push/unknown/hook-only), a throttled email send, an expired
 * pending state, or a missing grant config all fall back to the Increment 1 link-out
 * snackbar (open the server `challenge_url` in a new tab). It never fabricates a
 * reauth affordance when there is no `challenge_url` (headless / application-password
 * requests are blocked under a different code this middleware ignores).
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
	// rejections share this promise, but re-dispatch is owner-scoped — only the
	// caller that opened the modal re-fires on a grant; non-owners stay rejected
	// (see requestGrant() / handleSudoRequired()).
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
	 * Fetch the server-rendered 2FA partial for the current pending state.
	 *
	 * Single-flight is enforced by the caller (one fetch per modal). Sends the
	 * login + challenge cookie (credentials) that bind the 2fa_pending state.
	 *
	 * @param {string} nonce The wp_sudo_challenge nonce.
	 * @return {Promise<Object>} Resolves { ok, code, html } — code is 'partial' or 'link_out'.
	 */
	function fetchPartial( nonce ) {
		var body = new FormData();
		body.append( 'action', cfg.twoFactorPartialAction );
		body.append( '_wpnonce', nonce );

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
					return { ok: false, code: 'unexpected', html: '' };
				}
				if ( json && json.success && json.data ) {
					return { ok: true, code: json.data.code, html: json.data.html || '' };
				}
				return { ok: false, code: ( json && json.data && json.data.code ) || 'error', html: '' };
			} );
		}, function () {
			return { ok: false, code: 'network', html: '' };
		} );
	}

	/**
	 * Serialize every non-submit field inside the injected partial GENERICALLY.
	 *
	 * The provider owns the field names (TOTP `authcode`, email `two-factor-email-code`
	 * + hidden fields, backup `two-factor-backup-code`); the client must never
	 * hardcode them — that would re-introduce the render/validate coupling Milestone
	 * B removes. Submit/button/reset inputs are excluded (an unclicked submit is not
	 * part of a normal form submission; the email "Resend Code" submit is thus never
	 * auto-fired — in-modal resend is not wired in v1). The reserved routing/CSRF/replay
	 * names (`action`, `_wpnonce`, `_ajax_nonce`, `stash_key`) are excluded so a
	 * provider- or hook-emitted hidden field of that name can never override WP Sudo's
	 * own AJAX action/nonce (PHP keeps the last duplicate) or smuggle a stash_key into
	 * this session-only flow — mirrors the full-page flow's `body.delete()` guard in
	 * `wp-sudo-challenge.js`.
	 *
	 * @param {HTMLElement} container The injected-partial container node.
	 * @return {Object} name → value map.
	 */
	function serializePartial( container ) {
		var fields = {};
		if ( ! container ) {
			return fields;
		}
		var reserved = { action: true, _wpnonce: true, _ajax_nonce: true, stash_key: true };
		var nodes = container.querySelectorAll( 'input, select, textarea' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var name = node.getAttribute( 'name' );
			if ( ! name || reserved[ name ] ) {
				continue;
			}
			var type = ( node.getAttribute( 'type' ) || '' ).toLowerCase();
			if ( 'submit' === type || 'button' === type || 'reset' === type ) {
				continue;
			}
			if ( ( 'checkbox' === type || 'radio' === type ) && ! node.checked ) {
				continue;
			}
			fields[ name ] = node.value;
		}
		return fields;
	}

	/**
	 * Defuse any native submit/button inside the injected partial so a click or an
	 * Enter keypress cannot trigger a full-page navigation that would destroy
	 * unsaved editor state. The React Confirm button drives submission instead.
	 *
	 * @param {HTMLElement} container The injected-partial container node.
	 * @return {void}
	 */
	function neutralizeSubmits( container ) {
		if ( ! container ) {
			return;
		}
		var subs = container.querySelectorAll(
			'input[type="submit"], input[type="button"], input[type="reset"], button'
		);
		for ( var i = 0; i < subs.length; i++ ) {
			subs[ i ].setAttribute( 'type', 'button' );
			subs[ i ].disabled = true;
		}
	}

	/**
	 * POST the serialized 2FA fields to the unchanged handle_ajax_2fa validator.
	 *
	 * @param {Object} fields name → value map from serializePartial().
	 * @param {string} nonce  The refreshed wp_sudo_challenge nonce.
	 * @return {Promise<Object>} Resolves { ok, code, message }.
	 */
	function postTwoFactor( fields, nonce ) {
		var body = new FormData();
		body.append( 'action', cfg.twoFactorAction );
		body.append( '_wpnonce', nonce );
		// Session-only: no stash_key — the editor re-dispatches its own request.
		Object.keys( fields ).forEach( function ( name ) {
			body.append( name, fields[ name ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		} ).then( function ( r ) {
			var status = r.status;
			return r.text().then( function ( text ) {
				var json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					return { ok: false, code: 'unexpected', status: status, message: __( 'Unexpected server response.', 'wp-sudo' ) };
				}
				if ( json && json.success && json.data ) {
					return { ok: true, code: json.data.code, status: status, message: '' };
				}
				var msg = ( json && json.data && json.data.message ) || __( 'Authentication failed.', 'wp-sudo' );
				return { ok: false, code: ( json && json.data && json.data.code ) || 'error', status: status, message: msg };
			} );
		}, function () {
			return { ok: false, code: 'network', status: 0, message: __( 'Network error. Please try again.', 'wp-sudo' ) };
		} );
	}

	/**
	 * The modal form component (functional, build-free via createElement).
	 *
	 * Two phases: a password step and, for a modal-capable 2FA account, an
	 * in-place second-factor step that injects the server-rendered provider
	 * partial and POSTs it back to the unchanged validator.
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
		// 'password' → the password step; 'twofactor' → the in-place second factor.
		var phaseState = element.useState( 'password' );
		var phase = phaseState[ 0 ];
		var setPhase = phaseState[ 1 ];
		var htmlState = element.useState( '' );
		var partialHtml = htmlState[ 0 ];
		var setPartialHtml = htmlState[ 1 ];
		var partialRef = element.useRef ? element.useRef( null ) : { current: null };

		// After the provider partial is injected, defuse its native submit buttons
		// (so a click/Enter can't navigate away and lose editor state) and focus the
		// code field.
		if ( element.useEffect ) {
			element.useEffect( function () {
				if ( 'twofactor' !== phase ) {
					return;
				}
				var container = partialRef.current;
				neutralizeSubmits( container );
				if ( container ) {
					var field = container.querySelector(
						'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"])'
					);
					if ( field && field.focus ) {
						field.focus();
					}
				}
			}, [ phase, partialHtml ] );
		}

		// Fetch the 2FA partial (single-flight: one fetch per modal open) and either
		// transition to the in-place second-factor step or link out to the full-page
		// challenge (WebAuthn/unknown/hook-only, a throttled email send, or an
		// expired pending state).
		function beginTwoFactor( nonce ) {
			return fetchPartial( nonce ).then( function ( res ) {
				if ( res.ok && 'partial' === res.code && res.html ) {
					setPartialHtml( res.html );
					setPhase( 'twofactor' );
					setBusy( false );
					return;
				}
				props.resolve( false );
			} );
		}

		function submitPassword( e ) {
			if ( e && e.preventDefault ) {
				e.preventDefault();
			}
			if ( ! password || busy ) {
				return;
			}
			setBusy( true );
			setError( '' );
			refreshNonce().then( function ( nonce ) {
				return postPassword( password, nonce ).then( function ( res ) {
					if ( res.ok && 'authenticated' === res.code ) {
						props.resolve( true );
						return undefined;
					}
					if ( res.ok && '2fa_pending' === res.code ) {
						// A modal-capable 2FA account: host the second factor in place.
						// Reuse the just-refreshed nonce for the partial fetch.
						return beginTwoFactor( nonce );
					}
					setBusy( false );
					setError( res.message );
					return undefined;
				} );
			} ).catch( function () {
				// Terminal safety net: a rejected transport or body-read (e.g. a
				// failed r.text()) must never leave the modal stuck busy, because
				// onRequestClose and Cancel are disabled while busy. Restore the
				// form so the user can retry or cancel.
				setBusy( false );
				setError( __( 'Unexpected error. Please try again.', 'wp-sudo' ) );
			} );
		}

		function submitTwoFactor() {
			if ( busy ) {
				return;
			}
			// Serialize the injected provider fields GENERICALLY (never hardcode a
			// field name) and POST to the unchanged validator.
			var fields = serializePartial( partialRef.current );
			setBusy( true );
			setError( '' );
			refreshNonce().then( function ( nonce ) {
				return postTwoFactor( fields, nonce ).then( function ( res ) {
					if ( res.ok && 'authenticated' === res.code ) {
						props.resolve( true );
						return;
					}
					if ( res.ok && '2fa_resent' === res.code ) {
						// Defensive only: generic serialization never fires the email
						// "Resend Code" submit, so this is not normally reachable in
						// the modal. Keep it open with a benign notice.
						setBusy( false );
						setError( __( 'A new code was sent to your email.', 'wp-sudo' ) );
						return;
					}
					if ( 403 === res.status ) {
						// The pending state expired mid-2FA — restart on the full-page
						// challenge rather than showing a misleading "invalid code".
						props.resolve( false );
						return;
					}
					setBusy( false );
					setError( res.message );
				} );
			} ).catch( function () {
				setBusy( false );
				setError( __( 'Unexpected error. Please try again.', 'wp-sudo' ) );
			} );
		}

		var errorNotice = error
			? el( components.Notice, { status: 'error', isDismissible: false }, error )
			: null;

		var cancelButton = el(
			components.Button,
			{ variant: 'tertiary', disabled: busy, onClick: function () { props.resolve( false ); } },
			__( 'Cancel', 'wp-sudo' )
		);

		var body;
		if ( 'twofactor' === phase ) {
			// The injected partial lives in a plain (NON-form) node so its native
			// submits can't navigate the page; submission runs only through the React
			// Confirm button. The message stays generic (no rule-label echo, Q4).
			body = el(
				'div',
				{ className: 'wp-sudo-reauth-modal__twofactor' },
				el(
					'p',
					null,
					__( 'Enter your two-factor authentication code to continue.', 'wp-sudo' )
				),
				el( 'div', {
					className: 'wp-sudo-reauth-modal__partial',
					ref: partialRef,
					onKeyDown: function ( e ) {
						if ( e && 'Enter' === e.key ) {
							e.preventDefault();
							submitTwoFactor();
						}
					},
					// eslint-disable-next-line react/no-danger
					dangerouslySetInnerHTML: { __html: partialHtml },
				} ),
				errorNotice,
				el(
					'div',
					{ className: 'wp-sudo-reauth-modal__actions', style: { display: 'flex', gap: '8px', marginTop: '12px' } },
					el(
						components.Button,
						{ variant: 'primary', type: 'button', isBusy: busy, disabled: busy, onClick: submitTwoFactor },
						__( 'Confirm', 'wp-sudo' )
					),
					cancelButton
				)
			);
		} else {
			body = el(
				'form',
				{ onSubmit: submitPassword },
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
				errorNotice,
				el(
					'div',
					{ className: 'wp-sudo-reauth-modal__actions', style: { display: 'flex', gap: '8px', marginTop: '12px' } },
					el(
						components.Button,
						{ variant: 'primary', type: 'submit', isBusy: busy, disabled: busy || ! password },
						__( 'Confirm', 'wp-sudo' )
					),
					cancelButton
				)
			);
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
			body
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
	 * Ensure a single in-flight grant; concurrent callers share it. The caller
	 * that opens the modal (observed `pendingGrant === null`) is the OWNER; callers
	 * that attach to an existing flight are non-owners. Ownership is captured
	 * synchronously per-caller (JS is single-threaded, so the check-and-set is
	 * race-free) so it rides each caller's OWN continuation, not the shared
	 * promise's single resolution value.
	 *
	 * @return {Promise<{granted: boolean, isOwner: boolean}>} granted is true when a
	 *   session was granted in-editor; isOwner is true only for the modal-opening
	 *   caller (the user's actioned request).
	 */
	function requestGrant() {
		var isOwner = ! pendingGrant;
		if ( isOwner ) {
			pendingGrant = openModal().then(
				function ( granted ) { pendingGrant = null; return granted; },
				function () { pendingGrant = null; return false; }
			);
		}
		return pendingGrant.then( function ( granted ) {
			return { granted: granted, isOwner: isOwner };
		} );
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
		// C4: only offer a reauth affordance when the response carried a validated
		// same-origin challenge_url (the interactive cookie-auth branch). A null
		// challengeUrl means the headless/app-password branch omitted it, or an
		// unsafe URL was rejected by isSafeChallengeUrl — surface a plain notice
		// with no action, and never open the grant modal.
		if ( ! canGrant || null === challengeUrl ) {
			surface( challengeUrl );
			return Promise.resolve( undefined );
		}
		// Milestone B: a 2FA account whose primary provider is NOT modal-capable
		// (WebAuthn/push/unknown/hook-only) still can't finish reauth in the modal —
		// the password step yields 2fa_pending and the partial would return link_out,
		// making the user type their password twice (once here, once on the challenge
		// page, which starts on its own password form). Skip the modal for those users
		// and link out directly, so they enter their password ONCE. A modal-capable
		// 2FA user (OTP family) falls through and hosts the second factor in place.
		// Must sit AFTER the C4 guard above: `cfg` may be null (then `canGrant` is
		// false and we already returned), so `cfg.*` is only read when cfg is truthy.
		// The flags are a page-load UX hint; the server stays authoritative (the
		// partial re-classifies and returns link_out on any mismatch).
		if ( cfg.hasTwoFactor && ! cfg.twoFactorModalCapable ) {
			surface( challengeUrl );
			return Promise.resolve( undefined );
		}
		return requestGrant().then( function ( result ) {
			if ( result.granted ) {
				if ( result.isOwner && options ) {
					// Owner (the request that opened the modal = the user's
					// actioned request): re-dispatch through apiFetch so the
					// user's own wp_rest nonce is attached and the now-active
					// session is re-evaluated (C3). The resolved value is the
					// successful response for the original caller.
					return wp.apiFetch( options );
				}
				// Granted, but a NON-owner concurrent rejection (Q3): the session
				// is now active, so there is nothing to link out to — and this is
				// a request the user did not action at this moment, so it is NOT
				// auto-replayed. Returning undefined leaves the caller's original
				// sudo_required rejection in place; it self-heals on a natural
				// retry against the active session. No surprise mutation fires.
				return undefined;
			}
			// Not granted in-editor (2FA / cancel) — offer the link-out fallback
			// to every waiting caller.
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
				// Q2: a batched sudo_required is detect-and-surface ONLY. No gated
				// route is batchable in core today; if one becomes so, link out (or
				// show a plain notice when there is no safe URL) rather than
				// re-dispatching the whole /batch/v1 envelope — replaying it could
				// repeat successful sibling mutations. Never open the grant modal
				// for a batch. The original response is returned unchanged so the
				// caller still observes the failure.
				surface( result );
				return response;
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
