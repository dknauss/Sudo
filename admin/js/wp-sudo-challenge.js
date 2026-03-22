/**
 * WP Sudo – Challenge page controller.
 *
 * Handles the password + optional 2FA flow on the challenge interstitial,
 * then either replays the stashed request (redirect for GET, self-submitting
 * form for POST) or redirects to the cancel URL in session-only mode.
 *
 * Session-only mode (no stash key): the user is activating a sudo session
 * proactively, e.g. via an admin notice link or the keyboard shortcut.
 * After successful authentication, the page redirects back to the
 * referring admin page instead of replaying a stashed request.
 *
 * @package WP_Sudo
 */
(function () {
	'use strict';

	// Break out of iframes (e.g. wp_iframe() used by plugin/theme updates).
	// The challenge page must render at the top level so the full admin
	// chrome is visible and the redirect/replay targets the correct frame.
	if (window.top !== window.self) {
		window.top.location.href = window.location.href;
		return;
	}

	var config = window.wpSudoChallenge || {};
	var strings = config.strings || {};

	if (!config.ajaxUrl) {
		return;
	}

	// Elements.
	var card = document.getElementById('wp-sudo-challenge-card');
	var passwordStep = document.getElementById('wp-sudo-challenge-password-step');
	var passwordForm = document.getElementById('wp-sudo-challenge-password-form');
	var passwordInput = document.getElementById('wp-sudo-challenge-password');
	var submitBtn = document.getElementById('wp-sudo-challenge-submit');
	var errorBox = document.getElementById('wp-sudo-challenge-error');
	var twofaStep = document.getElementById('wp-sudo-challenge-2fa-step');
	var twofaForm = document.getElementById('wp-sudo-challenge-2fa-form');
	var twofaSubmitBtn = document.getElementById('wp-sudo-challenge-2fa-submit');
	var twofaErrorBox = document.getElementById('wp-sudo-challenge-2fa-error');
	var twofaTimer = document.getElementById('wp-sudo-challenge-2fa-timer');
	var loadingOverlay = document.getElementById('wp-sudo-challenge-loading');
	var throttleNotice = document.getElementById('wp-sudo-challenge-throttle-notice');

	var throttleInterval = null;
	var countdownInterval = null;

	/**
	 * Announce a message to screen readers via wp.a11y.speak().
	 *
	 * @param {string} message  Text to announce.
	 * @param {string} priority 'assertive' or 'polite' (default: 'assertive').
	 */
	function announce(message, priority) {
		if (window.wp && wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, priority || 'assertive');
		}
	}

	// ── Password form submission ──────────────────────────────────────

	if (passwordForm) {
		passwordForm.addEventListener('submit', function (e) {
			e.preventDefault();

			var password = passwordInput.value;
			if (!password) {
				return;
			}

			hideError(errorBox);
			submitBtn.disabled = true;
			loadingOverlay.hidden = false;
			card.setAttribute('aria-busy', 'true');

			var body = new FormData();
			body.append('action', config.authAction);
			body.append('_wpnonce', config.nonce);
			body.append('password', password);
			if (config.stashKey) {
				body.append('stash_key', config.stashKey);
			}

			fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) {
					return r.text().then(function (text) {
						return { text: text, status: r.status };
					});
				})
				.then(function (result) {
					var response;
					try {
						response = JSON.parse(result.text);
					} catch (e) {
						loadingOverlay.hidden = true;
						submitBtn.disabled = false;
						card.removeAttribute('aria-busy');
						/* eslint-disable no-console */
						console.error('WP Sudo auth: non-JSON response (HTTP ' + result.status + '):', result.text);
						/* eslint-enable no-console */
						showError(errorBox, strings.unexpectedResponse);
						return;
					}

					loadingOverlay.hidden = true;
					submitBtn.disabled = false;
					card.removeAttribute('aria-busy');

					if (response.success) {
						if (response.data && response.data.code === '2fa_pending') {
							// Switch to 2FA step.
							passwordStep.hidden = true;
							twofaStep.hidden = false;
							announce(strings.twoFactorRequired);
							var firstInput = twofaStep.querySelector('input:not([type="hidden"])');
							if (firstInput) {
								firstInput.focus();
							}
							if (response.data.expires_at) {
								startCountdown(response.data.expires_at);
							}
							return;
						}

						// An already-active session may escape a stale challenge without replay data.
						if (response.data && response.data.code === 'authenticated') {
							window.location.href = config.cancelUrl || (window.location.origin + '/wp-admin/');
							return;
						}

						// Stash mode: replay the stashed request.
						handleReplay(response.data);
						return;
					}

					// Error.
					var data = response.data || {};
					if (data.code === 'locked_out' && data.remaining > 0) {
						startLockoutCountdown(data.remaining);
					} else if (data.delay > 0) {
						startThrottleCountdown(data.delay);
					} else {
						showError(errorBox, data.message || strings.genericError);
					}
					passwordInput.value = '';
					passwordInput.focus();
				})
				.catch(function (err) {
					loadingOverlay.hidden = true;
					submitBtn.disabled = false;
					card.removeAttribute('aria-busy');
					/* eslint-disable no-console */
					console.error('WP Sudo auth: fetch error:', err);
					/* eslint-enable no-console */
					showError(errorBox, strings.networkError);
				});
		});
	}

	// ── 2FA form submission ───────────────────────────────────────────

	if (twofaForm) {
		twofaForm.addEventListener('submit', function (e) {
			e.preventDefault();

			hideError(twofaErrorBox);
			if (twofaSubmitBtn) {
				twofaSubmitBtn.disabled = true;
			}
			loadingOverlay.hidden = false;
			card.setAttribute('aria-busy', 'true');

			var body = new FormData(twofaForm);

			// The Two Factor provider's authentication_page() may render hidden
			// fields named "action" and "_wpnonce". Delete them before setting
			// ours so the AJAX request hits our handler, not the provider's.
			body.delete('action');
			body.delete('_wpnonce');
			body.append('action', config.tfaAction);
			body.append('_wpnonce', config.nonce);
			if (config.stashKey) {
				body.append('stash_key', config.stashKey);
			}

			fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) {
					// Read as text first so non-JSON responses don't break the chain.
					return r.text().then(function (text) {
						return { text: text, status: r.status };
					});
				})
				.then(function (result) {
					var response;
					try {
						response = JSON.parse(result.text);
					} catch (e) {
						// Response is not valid JSON — show a meaningful error.
						loadingOverlay.hidden = true;
						card.removeAttribute('aria-busy');
						if (twofaSubmitBtn) {
							twofaSubmitBtn.disabled = false;
						}
						/* eslint-disable no-console */
						console.error('WP Sudo 2FA: non-JSON response (HTTP ' + result.status + '):', result.text);
						/* eslint-enable no-console */
						showError(twofaErrorBox, strings.unexpectedResponse);
						return;
					}

					loadingOverlay.hidden = true;
					card.removeAttribute('aria-busy');
					if (twofaSubmitBtn) {
						twofaSubmitBtn.disabled = false;
					}

					if (response.success) {
						if (response.data && response.data.code === '2fa_resent') {
							return; // Code resent — stay on 2FA step.
						}

						// An already-active session may escape a stale challenge without replay data.
						if (response.data && response.data.code === 'authenticated') {
							window.location.href = config.cancelUrl || (window.location.origin + '/wp-admin/');
							return;
						}

						// Session-only mode: redirect back instead of replaying.
						if (config.sessionOnly) {
							window.location.href = config.cancelUrl || (window.location.origin + '/wp-admin/');
							return;
						}

						handleReplay(response.data);
						return;
					}

					var data = response.data || {};
					if (data.code === 'locked_out' && data.remaining > 0) {
						var firstTwofaInput = twofaStep
							? twofaStep.querySelector('input:not([type="hidden"])')
							: null;
						startLockoutCountdown(
							data.remaining,
							twofaErrorBox,
							twofaSubmitBtn,
							firstTwofaInput
						);
					} else if (data.delay > 0) {
						startThrottleCountdown(data.delay, twofaErrorBox, twofaSubmitBtn);
					} else {
						showError(twofaErrorBox, data.message || strings.authenticationFailed);
					}
				})
				.catch(function (err) {
					loadingOverlay.hidden = true;
					card.removeAttribute('aria-busy');
					if (twofaSubmitBtn) {
						twofaSubmitBtn.disabled = false;
					}
					/* eslint-disable no-console */
					console.error('WP Sudo 2FA: fetch error:', err);
					/* eslint-enable no-console */
					showError(twofaErrorBox, strings.networkError);
				});
		});
	}

	// ── Request replay ────────────────────────────────────────────────

	/**
	 * Replay the stashed request.
	 *
	 * For GET: redirect to the original URL.
	 * For POST: build a self-submitting hidden form.
	 *
	 * Shows a visible "Replaying your action…" status message and announces
	 * it to screen readers before performing the redirect or form submit.
	 */
	function handleReplay(data) {
		// Show replay status to all users (visible + announced).
		loadingOverlay.hidden = false;
		var srOnly = loadingOverlay.querySelector('.wp-sudo-sr-only');
		if (srOnly) {
			srOnly.hidden = true;
		}
		var statusEl = loadingOverlay.querySelector('.wp-sudo-loading-text');
		if (statusEl) {
			statusEl.textContent = strings.replayingAction;
		}
		announce(strings.replayingAction);

		if (!data) {
			window.location.href = window.location.origin + '/wp-admin/';
			return;
		}

		// Simple redirect for GET or when no replay data.
		if (data.redirect && !data.replay) {
			window.location.href = data.redirect;
			return;
		}

		// POST replay: build and auto-submit a hidden form.
		if (data.replay && data.url) {
			var form = document.createElement('form');
			form.method = data.method || 'POST';
			form.action = data.url;
			form.style.display = 'none';

			// Add POST fields.
			var postData = data.post_data || {};
			appendFields(form, postData, '');

			document.body.appendChild(form);

			// Use the prototype method because stashed POST data may include
			// a field named "submit" which shadows the native form.submit().
			HTMLFormElement.prototype.submit.call(form);
			return;
		}

		// Fallback: just go to the dashboard.
		window.location.href = window.location.origin + '/wp-admin/';
	}

	/**
	 * Recursively append hidden inputs to a form.
	 *
	 * Handles nested arrays using PHP bracket notation (e.g. "checked[]").
	 */
	function appendFields(form, data, prefix) {
		var keys = Object.keys(data);
		for (var i = 0; i < keys.length; i++) {
			var key = keys[i];
			var value = data[key];
			var name = prefix ? prefix + '[' + key + ']' : key;

			if (typeof value === 'object' && value !== null) {
				appendFields(form, value, name);
			} else {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = name;
				input.value = value;
				form.appendChild(input);
			}
		}
	}

	// ── Escape key — announce then navigate to cancel URL ────────────

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && config.cancelUrl) {
			e.preventDefault();
			announce(strings.leavingChallenge);
			setTimeout(function () {
				window.location.href = config.cancelUrl;
			}, 600);
		}
	});

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
	function startLockoutCountdown(remaining, box, btn, focusInput) {
		var targetBox = box || errorBox;
		var targetBtn = btn || submitBtn;
		var targetInput = focusInput || passwordInput;

		if (lockoutInterval) {
			clearInterval(lockoutInterval);
		}

		if (targetBtn) {
			targetBtn.disabled = true;
		}

		// Suppress per-second screen reader announcements during countdown.
		// The error box has role="alert" which would announce every update.
		// Instead, we set aria-live="off" and announce only at milestones.
		if (targetBox) {
			targetBox.setAttribute('aria-live', 'off');
		}

		function tick() {
			if (remaining <= 0) {
				clearInterval(lockoutInterval);
				lockoutInterval = null;
				if (targetBtn) {
					targetBtn.disabled = false;
				}
				// Restore live region before hiding so SR announces clearance.
				if (targetBox) {
					targetBox.removeAttribute('aria-live');
				}
				hideError(targetBox);
				announce(strings.lockoutExpired || 'Lockout expired. You may try again.');
				if (targetInput) {
					targetInput.focus();
				}
				return;
			}

			var m = Math.floor(remaining / 60);
			var s = remaining % 60;
			var timeStr = m + ':' + (s < 10 ? '0' : '') + s;

			// Update visual text every second.
			var p = targetBox ? targetBox.querySelector('p') : null;
			if (targetBox && !targetBox.hidden) {
				// Direct DOM update to avoid triggering live region.
				if (p) {
					p.textContent = strings.lockoutCountdown.replace('%s', timeStr);
				}
			} else {
				showError(targetBox, strings.lockoutCountdown.replace('%s', timeStr));
			}

			// Announce to screen readers every 30 seconds and at 10 seconds.
			if (remaining % 30 === 0 || remaining === 10) {
				announce(strings.lockoutCountdown.replace('%s', timeStr), 'polite');
			}

			remaining--;
		}

		tick();
		lockoutInterval = setInterval(tick, 1000);
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
	function startThrottleCountdown(remaining, box, btn) {
		var targetBox = box || throttleNotice || errorBox;
		var targetBtn = btn || submitBtn;

		if (throttleInterval) {
			clearInterval(throttleInterval);
		}

		targetBtn.disabled = true;

		function tick() {
			if (remaining <= 0) {
				clearInterval(throttleInterval);
				throttleInterval = null;
				targetBtn.disabled = false;
				hideError(targetBox);
				return;
			}

			var m = Math.floor(remaining / 60);
			var s = remaining % 60;
			var timeStr = m + ':' + (s < 10 ? '0' : '') + s;

			var msg = strings.throttleCountdown ? strings.throttleCountdown.replace('%s', timeStr) : strings.lockoutCountdown.replace('%s', timeStr);
			showError(targetBox, msg);
			remaining--;
		}

		tick();
		throttleInterval = setInterval(tick, 1000);
	}

	// ── Helpers ──────────────────────────────────────────────────────

	function showError(box, message) {
		if (!box) return;
		// Unhide first so screen readers register the live region,
		// then populate content in the next frame so AT announces it.
		box.hidden = false;
		requestAnimationFrame(function () {
			var p = box.querySelector('p');
			if (p) {
				p.textContent = message;
			}
		});
	}

	function hideError(box) {
		if (!box) return;
		box.hidden = true;
		var p = box.querySelector('p');
		if (p) {
			p.textContent = '';
		}
	}

	// ── 2FA countdown timer ─────────────────────────────────────────

	/**
	 * Start a visible countdown to the 2FA window expiry.
	 *
	 * @param {number} expiresAt Unix timestamp (seconds) when the 2FA window ends.
	 */
	function startCountdown(expiresAt) {
		if (!twofaTimer) {
			return;
		}

		if (countdownInterval) {
			clearInterval(countdownInterval);
		}

		twofaTimer.hidden = false;

		function tick() {
			var remaining = Math.max(0, expiresAt - Math.floor(Date.now() / 1000));
			var minutes = Math.floor(remaining / 60);
			var seconds = remaining % 60;

			var timeStr = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

			if (remaining <= 60) {
				twofaTimer.classList.add('wp-sudo-expiring');
				twofaTimer.textContent = strings.timeRemainingWarn.replace('%s', timeStr);
			} else {
				twofaTimer.classList.remove('wp-sudo-expiring');
				twofaTimer.textContent = strings.timeRemaining.replace('%s', timeStr);
			}

			if (remaining <= 0) {
				clearInterval(countdownInterval);
				countdownInterval = null;
				twofaTimer.textContent = '';

				// Keep the button enabled — the server is the source of truth.
				// The client countdown is advisory; clock drift or network latency
				// may cause it to fire before the server transient expires.
				twofaTimer.classList.add('wp-sudo-expiring');

				// Show advisory warning with a restart option.
				var warningMsg = document.createElement('span');
				warningMsg.textContent = strings.sessionMayExpired + ' ';
				twofaTimer.appendChild(warningMsg);

				var restartBtn = document.createElement('button');
				restartBtn.type = 'button';
				restartBtn.className = 'button button-link';
				restartBtn.textContent = strings.startOver;
				restartBtn.addEventListener('click', function () {
					// Reload to restart the challenge from the password step.
					window.location.reload();
				});
				twofaTimer.appendChild(restartBtn);
			}
		}

		tick();
		countdownInterval = setInterval(tick, 1000);
	}

	// ── Initialization ───────────────────────────────────────────────

	if (config.throttleRemaining > 0) {
		startThrottleCountdown(config.throttleRemaining, throttleNotice);
	}
})();
