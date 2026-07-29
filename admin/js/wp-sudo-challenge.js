/**
 * WP Sudo – Challenge page controller.
 *
 * Handles the password + optional 2FA flow on the challenge interstitial,
 * then redirects to a landing page. It never re-issues the intercepted request:
 * automatic replay was removed outright in 4.9.0 (#322): the server sends a
 * redirect, plus informational flags when a stash was consumed, and never a
 * request to re-issue — no replay/url/post_data keys — and the user performs
 * the action again themselves.
 *
 * Session-only mode (no stash key): the user is activating a sudo session
 * proactively, e.g. via an admin notice link or the keyboard shortcut. After
 * successful authentication the page goes to the server-supplied neutral
 * destination (see neutralDestination()) — NOT back to the referring screen.
 * #322 traded that convenience for the guarantee that no success branch
 * navigates to a requester-supplied URL.
 *
 * @package WP_Sudo
 */
( function() {
	'use strict';

	// Break out of iframes (e.g. wp_iframe() used by plugin/theme updates).
	// The challenge page must render at the top level so the full admin
	// chrome is visible and the redirect targets the correct frame.
	if ( window.top !== window.self ) {
		var canNavigateTop = true;
		try {
			var frame = window.frameElement;
			if ( frame && frame.hasAttribute( 'sandbox' ) ) {
				var sandboxTokens = ( frame.getAttribute( 'sandbox' ) || '' ).split( /\s+/ );
				canNavigateTop = sandboxTokens.indexOf( 'allow-top-navigation' ) !== -1;
			}
		} catch ( e ) {
			canNavigateTop = false;
		}

		if ( canNavigateTop ) {
			try {
				window.top.location.href = window.location.href;
				return;
			} catch ( e ) {
				// Cross-origin/sandboxed hosts such as WordPress Playground must
				// keep the challenge functional inside the embedded frame.
			}
		}
	}

	var config = window.wpSudoChallenge || {};
	var strings = config.strings || {};

	if ( ! config.ajaxUrl ) {
		return;
	}

	// Elements.
	var card = document.getElementById( 'wp-sudo-challenge-card' );
	var passwordStep = document.getElementById( 'wp-sudo-challenge-password-step' );
	var passwordForm = document.getElementById( 'wp-sudo-challenge-password-form' );
	var passwordInput = document.getElementById( 'wp-sudo-challenge-password' );
	var submitBtn = document.getElementById( 'wp-sudo-challenge-submit' );
	var errorBox = document.getElementById( 'wp-sudo-challenge-error' );
	var twofaStep = document.getElementById( 'wp-sudo-challenge-2fa-step' );
	var twofaForm = document.getElementById( 'wp-sudo-challenge-2fa-form' );
	var twofaSubmitBtn = document.getElementById( 'wp-sudo-challenge-2fa-submit' );
	var twofaErrorBox = document.getElementById( 'wp-sudo-challenge-2fa-error' );
	var twofaTimer = document.getElementById( 'wp-sudo-challenge-2fa-timer' );
	var loadingOverlay = document.getElementById( 'wp-sudo-challenge-loading' );
	var throttleNotice = document.getElementById( 'wp-sudo-challenge-throttle-notice' );

	var throttleInterval = null;
	var countdownInterval = null;

	/**
	 * Announce a message to screen readers via wp.a11y.speak().
	 *
	 * @param {string} message  Text to announce.
	 * @param {string} priority 'assertive' or 'polite' (default: 'assertive').
	 */
	function announce( message, priority ) {
		if ( window.wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, priority || 'assertive' );
		}
	}

	// ── Password form submission ──────────────────────────────────────

	if ( passwordForm ) {
		passwordForm.addEventListener( 'submit', function( e ) {
			e.preventDefault();

			var password = passwordInput.value;
			if ( ! password ) {
				return;
			}

			hideError( errorBox );
			submitBtn.disabled = true;
			loadingOverlay.hidden = false;
			card.setAttribute( 'aria-busy', 'true' );

			var body = new FormData();
			body.append( 'action', config.authAction );
			body.append( '_wpnonce', config.nonce );
			body.append( 'password', password );
			if ( config.stashKey ) {
				body.append( 'stash_key', config.stashKey );
			}

			fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function( r ) {
					return r.text().then( function( text ) {
						return { text: text, status: r.status };
					} );
				} )
				.then( function( result ) {
					var response;
					try {
						response = JSON.parse( result.text );
					} catch ( parseError ) {
						loadingOverlay.hidden = true;
						submitBtn.disabled = false;
						card.removeAttribute( 'aria-busy' );
						// Preserve the response body for administrator diagnostics while
						// the user-facing box below receives only the generic safe message.
						// eslint-disable-next-line no-console
						console.error( 'WP Sudo auth: non-JSON response (HTTP ' + result.status + '):', result.text );
						showError( errorBox, strings.unexpectedResponse );
						return;
					}

					loadingOverlay.hidden = true;
					submitBtn.disabled = false;
					card.removeAttribute( 'aria-busy' );

					if ( response.success ) {
						if ( response.data && response.data.code === '2fa_pending' ) {
							// Switch to 2FA step.
							passwordStep.hidden = true;
							twofaStep.hidden = false;
							announce( strings.twoFactorRequired );
							var firstInput = twofaStep.querySelector( 'input:not([type="hidden"])' );
							if ( firstInput ) {
								firstInput.focus();
							}
							if ( response.data.expires_at ) {
								startCountdown( response.data.expires_at );
							}
							return;
						}

						// An already-active session may escape a stale challenge with no landing data.
						if ( response.data && response.data.code === 'authenticated' ) {
							window.location.href = neutralDestination();
							return;
						}

						// Stash mode: the server has already discarded the stash; go to the
						// landing page it chose.
						handleReplay( response.data );
						return;
					}

					// Error.
					var data = response.data || {};
					if ( data.code === 'locked_out' && data.remaining > 0 ) {
						startLockoutCountdown( data.remaining );
					} else if ( data.delay > 0 ) {
						startThrottleCountdown( data.delay );
					} else {
						showError( errorBox, data.message || strings.genericError );
					}
					passwordInput.value = '';
					passwordInput.focus();
				} )
				.catch( function( err ) {
					loadingOverlay.hidden = true;
					submitBtn.disabled = false;
					card.removeAttribute( 'aria-busy' );
					// Network diagnostics stay in developer tools; the user-facing box
					// below receives the localized recovery message.
					// eslint-disable-next-line no-console
					console.error( 'WP Sudo auth: fetch error:', err );
					showError( errorBox, strings.networkError );
				} );
		} );
	}

	// ── 2FA form submission ───────────────────────────────────────────

	if ( twofaForm ) {
		twofaForm.addEventListener( 'submit', function( e ) {
			e.preventDefault();

			hideError( twofaErrorBox );
			if ( twofaSubmitBtn ) {
				twofaSubmitBtn.disabled = true;
			}
			loadingOverlay.hidden = false;
			card.setAttribute( 'aria-busy', 'true' );

			var body = new FormData( twofaForm );

			// The Two Factor provider's authentication_page() may render hidden
			// fields named "action", "_wpnonce", or "_ajax_nonce". Delete them
			// before setting ours so the AJAX request hits our handler, not the
			// provider's, and carries our nonce. `_ajax_nonce` is load-bearing:
			// check_ajax_referer() reads $_REQUEST['_ajax_nonce'] BEFORE
			// '_wpnonce', so a provider-emitted one would take precedence over the
			// value we append and fail verification.
			body.delete( 'action' );
			body.delete( '_wpnonce' );
			body.delete( '_ajax_nonce' );
			body.append( 'action', config.tfaAction );
			body.append( '_wpnonce', config.nonce );
			if ( config.stashKey ) {
				body.append( 'stash_key', config.stashKey );
			}

			fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function( r ) {
					// Read as text first so non-JSON responses don't break the chain.
					return r.text().then( function( text ) {
						return { text: text, status: r.status };
					} );
				} )
				.then( function( result ) {
					var response;
					try {
						response = JSON.parse( result.text );
					} catch ( parseError ) {
						// Response is not valid JSON — show a meaningful error.
						loadingOverlay.hidden = true;
						card.removeAttribute( 'aria-busy' );
						if ( twofaSubmitBtn ) {
							twofaSubmitBtn.disabled = false;
						}
						// Preserve the response body for administrator diagnostics while
						// the user-facing box below receives only the generic safe message.
						// eslint-disable-next-line no-console
						console.error( 'WP Sudo 2FA: non-JSON response (HTTP ' + result.status + '):', result.text );
						showError( twofaErrorBox, strings.unexpectedResponse );
						return;
					}

					loadingOverlay.hidden = true;
					card.removeAttribute( 'aria-busy' );
					if ( twofaSubmitBtn ) {
						twofaSubmitBtn.disabled = false;
					}

					if ( response.success ) {
						if ( response.data && response.data.code === '2fa_resent' ) {
							return; // Code resent — stay on 2FA step.
						}

						// An already-active session may escape a stale challenge with no landing data.
						if ( response.data && response.data.code === 'authenticated' ) {
							window.location.href = neutralDestination();
							return;
						}

						// Session-only mode: go to the server-supplied neutral destination —
						// never a requester-supplied URL (see neutralDestination()).
						if ( config.sessionOnly ) {
							window.location.href = neutralDestination();
							return;
						}

						handleReplay( response.data );
						return;
					}

					var data = response.data || {};
					if ( data.code === 'locked_out' && data.remaining > 0 ) {
						var firstTwofaInput = twofaStep
							? twofaStep.querySelector( 'input:not([type="hidden"])' )
							: null;
						startLockoutCountdown(
							data.remaining,
							twofaErrorBox,
							twofaSubmitBtn,
							firstTwofaInput,
						);
					} else if ( data.delay > 0 ) {
						startThrottleCountdown( data.delay, twofaErrorBox, twofaSubmitBtn );
					} else {
						showError( twofaErrorBox, data.message || strings.authenticationFailed );
					}
				} )
				.catch( function( err ) {
					loadingOverlay.hidden = true;
					card.removeAttribute( 'aria-busy' );
					if ( twofaSubmitBtn ) {
						twofaSubmitBtn.disabled = false;
					}
					// Network diagnostics stay in developer tools; the user-facing box
					// below receives the localized recovery message.
					// eslint-disable-next-line no-console
					console.error( 'WP Sudo 2FA: fetch error:', err );
					showError( twofaErrorBox, strings.networkError );
				} );
		} );
	}

	// ── Post-challenge landing ────────────────────────────────────────

	/**
	 * The destination for ANY successful challenge.
	 *
	 * #322: never `config.cancelUrl`, and never anything else derived from the URL
	 * that opened this page. A successful challenge mints sudo authority, so a
	 * requester-supplied destination reached at that moment executes under it — with
	 * no click, immediately after the victim typed their password. Classifying such
	 * a URL was tried and failed: a queryless custom-action path has nothing to
	 * strip and passed every filter.
	 *
	 * The server supplies `neutralUrl`; the hardcoded dashboard is a last resort if
	 * localization is ever missing, and is itself not requester-influenced.
	 */
	function neutralDestination() {
		return config.neutralUrl || ( window.location.origin + '/wp-admin/' );
	}

	/**
	 * Go to the post-challenge landing page.
	 *
	 * Nothing is replayed (#322): the response carries a server-computed redirect,
	 * plus informational flags when a stash was consumed, and never a request to
	 * re-issue. Shows the "Returning you to your page…" status message and
	 * announces it to screen readers first.
	 * @param {Object} data Server-computed post-challenge response data.
	 */
	function handleReplay( data ) {
		// #322: nothing is ever replayed, so the message is always the honest one.
		// A "replaying" announcement would tell screen-reader users the opposite of
		// what happened — the one cohort that cannot see the notice on the next page.
		var message = strings.returningToPage;

		loadingOverlay.hidden = false;
		var srOnly = loadingOverlay.querySelector( '.wp-sudo-sr-only' );
		if ( srOnly ) {
			srOnly.hidden = true;
		}
		var statusEl = loadingOverlay.querySelector( '.wp-sudo-loading-text' );
		if ( statusEl ) {
			statusEl.textContent = message;
		}
		announce( message );

		if ( ! data ) {
			window.location.href = window.location.origin + '/wp-admin/';
			return;
		}

		// The server's chosen landing. It is the only destination this function
		// honours, and the server computes it with the effect-bearing query stripped
		// and re-validated same-origin. `data.replay` is deliberately not consulted:
		// see below.
		if ( data.redirect ) {
			window.location.href = data.redirect;
			return;
		}

		// #322: there is NO client-side replay branch, deliberately.
		//
		// This function used to build a hidden form from `data.replay`/`data.url`/
		// `data.post_data` and auto-submit it via
		// HTMLFormElement.prototype.submit(). The server no longer emits those keys,
		// so that engine was dormant — but dormant by restraint, in one place, with
		// no test on this side of the wire at all. Anything that ever emitted those
		// keys again (a regression, a merge that reverts one PHP method, a
		// third-party AJAX handler answering this endpoint) would have been executed
		// faithfully, with no second control.
		//
		// The invariant is now enforced on both sides: the server cannot ask for a
		// replay, and the client would not perform one if asked. Removing the gadget
		// is what makes that true rather than merely currently-unreachable.

		// Fallback: just go to the dashboard.
		window.location.href = window.location.origin + '/wp-admin/';
	}

	// ── Escape key — announce then navigate to cancel URL ────────────

	document.addEventListener( 'keydown', function( e ) {
		if ( e.key === 'Escape' && config.cancelUrl ) {
			e.preventDefault();
			announce( strings.leavingChallenge );
			setTimeout( function() {
				window.location.href = config.cancelUrl;
			}, 600 );
		}
	} );

	// ── Lockout countdown ────────────────────────────────────────────

	var lockoutInterval = null;

	/**
	 * Show a countdown when the user is locked out.
	 *
	 * Disables the submit button and updates the error message each second
	 * until the lockout expires, then re-enables the form.
	 *
	 * @param {number}  remaining  Seconds until lockout expires.
	 * @param {Element} box        Optional error box element.
	 * @param {Element} btn        Optional submit button element.
	 * @param {Element} focusInput Optional input to focus when lockout expires.
	 */
	function startLockoutCountdown( remaining, box, btn, focusInput ) {
		var targetBox = box || errorBox;
		var targetBtn = btn || submitBtn;
		var targetInput = focusInput || passwordInput;

		if ( lockoutInterval ) {
			clearInterval( lockoutInterval );
		}

		if ( targetBtn ) {
			targetBtn.disabled = true;
		}

		// Suppress per-second screen reader announcements during countdown.
		// The error box has role="alert" which would announce every update.
		// Instead, we set aria-live="off" and announce only at milestones.
		if ( targetBox ) {
			targetBox.setAttribute( 'aria-live', 'off' );
		}

		function tick() {
			if ( remaining <= 0 ) {
				clearInterval( lockoutInterval );
				lockoutInterval = null;
				if ( targetBtn ) {
					targetBtn.disabled = false;
				}
				// Restore live region before hiding so SR announces clearance.
				if ( targetBox ) {
					targetBox.removeAttribute( 'aria-live' );
				}
				hideError( targetBox );
				announce( strings.lockoutExpired || 'Lockout expired. You may try again.' );
				if ( targetInput ) {
					targetInput.focus();
				}
				return;
			}

			var m = Math.floor( remaining / 60 );
			var s = remaining % 60;
			var timeStr = m + ':' + ( s < 10 ? '0' : '' ) + s;

			// Update visual text every second.
			var p = targetBox ? targetBox.querySelector( 'p' ) : null;
			if ( targetBox && ! targetBox.hidden ) {
				// Direct DOM update to avoid triggering live region.
				if ( p ) {
					p.textContent = strings.lockoutCountdown.replace( '%s', timeStr );
				}
			} else {
				showError( targetBox, strings.lockoutCountdown.replace( '%s', timeStr ) );
			}

			// Announce to screen readers every 30 seconds and at 10 seconds.
			if ( remaining % 30 === 0 || remaining === 10 ) {
				announce( strings.lockoutCountdown.replace( '%s', timeStr ), 'polite' );
			}

			remaining--;
		}

		tick();
		lockoutInterval = setInterval( tick, 1000 );
	}

	/**
	 * Show a short countdown when the user is throttled (non-blocking).
	 *
	 * Disables the submit button and updates the error message until the
	 * throttle delay expires.
	 *
	 * @param {number}  remaining Seconds until throttle expires.
	 * @param {Element} box       Optional error box element.
	 * @param {Element} btn       Optional submit button element.
	 */
	function startThrottleCountdown( remaining, box, btn ) {
		var targetBox = box || throttleNotice || errorBox;
		var targetBtn = btn || submitBtn;

		if ( throttleInterval ) {
			clearInterval( throttleInterval );
		}

		targetBtn.disabled = true;

		function tick() {
			if ( remaining <= 0 ) {
				clearInterval( throttleInterval );
				throttleInterval = null;
				targetBtn.disabled = false;
				hideError( targetBox );
				return;
			}

			var m = Math.floor( remaining / 60 );
			var s = remaining % 60;
			var timeStr = m + ':' + ( s < 10 ? '0' : '' ) + s;

			var msg = strings.throttleCountdown ? strings.throttleCountdown.replace( '%s', timeStr ) : strings.lockoutCountdown.replace( '%s', timeStr );
			showError( targetBox, msg );
			remaining--;
		}

		tick();
		throttleInterval = setInterval( tick, 1000 );
	}

	// ── Helpers ──────────────────────────────────────────────────────

	function showError( box, message ) {
		if ( ! box ) {
			return;
		}
		// Unhide first so screen readers register the live region,
		// then populate content in the next frame so AT announces it.
		// Some headless/background browser runs may throttle animation frames,
		// so also queue a zero-delay timer as a deterministic fallback.
		box.hidden = false;
		var setMessage = function() {
			var p = box.querySelector( 'p' );
			if ( p ) {
				p.textContent = message;
			}
		};
		requestAnimationFrame( setMessage );
		setTimeout( setMessage, 0 );
	}

	function hideError( box ) {
		if ( ! box ) {
			return;
		}
		box.hidden = true;
		var p = box.querySelector( 'p' );
		if ( p ) {
			p.textContent = '';
		}
	}

	// ── 2FA countdown timer ─────────────────────────────────────────

	/**
	 * Start a visible countdown to the 2FA window expiry.
	 *
	 * @param {number} expiresAt Unix timestamp (seconds) when the 2FA window ends.
	 */
	function startCountdown( expiresAt ) {
		if ( ! twofaTimer ) {
			return;
		}

		if ( countdownInterval ) {
			clearInterval( countdownInterval );
		}

		twofaTimer.hidden = false;

		function tick() {
			var remaining = Math.max( 0, expiresAt - Math.floor( Date.now() / 1000 ) );
			var minutes = Math.floor( remaining / 60 );
			var seconds = remaining % 60;

			var timeStr = minutes + ':' + ( seconds < 10 ? '0' : '' ) + seconds;

			if ( remaining <= 60 ) {
				twofaTimer.classList.add( 'wp-sudo-expiring' );
				twofaTimer.textContent = strings.timeRemainingWarn.replace( '%s', timeStr );
			} else {
				twofaTimer.classList.remove( 'wp-sudo-expiring' );
				twofaTimer.textContent = strings.timeRemaining.replace( '%s', timeStr );
			}

			if ( remaining <= 0 ) {
				clearInterval( countdownInterval );
				countdownInterval = null;
				twofaTimer.textContent = '';

				// Keep the button enabled — the server is the source of truth.
				// The client countdown is advisory; clock drift or network latency
				// may cause it to fire before the server transient expires.
				twofaTimer.classList.add( 'wp-sudo-expiring' );

				// Show advisory warning with a restart option.
				var warningMsg = document.createElement( 'span' );
				warningMsg.textContent = strings.sessionMayExpired + ' ';
				twofaTimer.appendChild( warningMsg );

				var restartBtn = document.createElement( 'button' );
				restartBtn.type = 'button';
				restartBtn.className = 'button button-link';
				restartBtn.textContent = strings.startOver;
				restartBtn.addEventListener( 'click', function() {
					// Reload to restart the challenge from the password step.
					window.location.reload();
				} );
				twofaTimer.appendChild( restartBtn );
			}
		}

		tick();
		countdownInterval = setInterval( tick, 1000 );
	}

	// ── Initialization ───────────────────────────────────────────────

	if ( config.throttleRemaining > 0 ) {
		startThrottleCountdown( config.throttleRemaining, throttleNotice );
	}
}() );
