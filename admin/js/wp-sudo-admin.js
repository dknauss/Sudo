/**
 * WP Sudo — Admin settings page scripts.
 *
 * Handles the MU-plugin install/uninstall toggle via AJAX and
 * bidirectional sync between the policy-preset selector and the
 * individual surface-policy dropdowns.
 *
 * @package WP_Sudo
 */

/* global wpSudoAdmin */
( function () {
	'use strict';

	var strings      = ( wpSudoAdmin && wpSudoAdmin.strings ) || {};
	var installBtn   = document.getElementById( 'wp-sudo-mu-install' );
	var uninstallBtn = document.getElementById( 'wp-sudo-mu-uninstall' );
	var spinner      = document.getElementById( 'wp-sudo-mu-spinner' );
	var messageEl    = document.getElementById( 'wp-sudo-mu-message' );

	/**
	 * Send an AJAX request for MU-plugin install or uninstall.
	 *
	 * @param {string} action  The AJAX action name.
	 * @param {Element} button The button element that was clicked.
	 */
	function muPluginAction( action, button ) {
		if ( ! wpSudoAdmin || ! wpSudoAdmin.ajaxUrl ) {
			return;
		}

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		if ( spinner ) {
			spinner.classList.add( 'is-active' );
		}
		if ( messageEl ) {
			messageEl.textContent = '';
		}

		var body = new FormData();
		body.append( 'action', action );
		body.append( '_nonce', wpSudoAdmin.nonce );

		fetch( wpSudoAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( spinner ) {
					spinner.classList.remove( 'is-active' );
				}
				button.setAttribute( 'aria-busy', 'false' );

				var data = result.data || {};

				if ( result.success ) {
					if ( messageEl ) {
						messageEl.textContent = data.message || '';
						messageEl.focus();
					}
					// Reload the page so the status indicator updates
					// (WP_SUDO_MU_LOADED will be defined or not on next load).
					setTimeout( function () {
						window.location.reload();
					}, 1000 );
				} else {
					button.disabled = false;
					if ( messageEl ) {
						messageEl.textContent = data.message || strings.genericError || '';
						messageEl.focus();
					}
				}
			} )
			.catch( function () {
				if ( spinner ) {
					spinner.classList.remove( 'is-active' );
				}
				button.disabled = false;
				button.setAttribute( 'aria-busy', 'false' );
				if ( messageEl ) {
					messageEl.textContent = strings.networkError || '';
					messageEl.focus();
				}
			} );
	}

	if ( installBtn ) {
		installBtn.addEventListener( 'click', function () {
			muPluginAction( wpSudoAdmin.installAction, installBtn );
		} );
	}

	if ( uninstallBtn ) {
		uninstallBtn.addEventListener( 'click', function () {
			muPluginAction( wpSudoAdmin.uninstallAction, uninstallBtn );
		} );
	}

	// --- Bidirectional preset ↔ surface policy sync ---
	var presetSelect   = document.getElementById( 'policy_preset_selection' );
	var descriptionEl  = document.getElementById( 'wp-sudo-preset-description' );
	var descriptions   = ( wpSudoAdmin && wpSudoAdmin.presetDescriptions ) || {};
	var presetPolicies = ( wpSudoAdmin && wpSudoAdmin.presetPolicies ) || {};
	var surfaceKeys    = ( wpSudoAdmin && wpSudoAdmin.surfaceKeys ) || [];

	if ( presetSelect && descriptionEl && surfaceKeys.length ) {

		/**
		 * Ensure a disabled "Custom" option exists in the preset selector.
		 *
		 * The server only renders this option when current settings do not
		 * match any named preset. When the user changes individual surfaces
		 * at runtime, JS must add the option dynamically.
		 *
		 * @return {HTMLOptionElement} The custom option element.
		 */
		var ensureCustomOption = function () {
			var option = presetSelect.querySelector( 'option[value="custom"]' );
			if ( ! option ) {
				option = document.createElement( 'option' );
				option.value       = 'custom';
				option.textContent = 'Custom';
				option.disabled    = true;
				presetSelect.appendChild( option );
			}
			return option;
		};

		/**
		 * Select a preset by key and update the description text.
		 *
		 * Uses option.selected for the disabled "Custom" option because
		 * select.value cannot programmatically select a disabled option.
		 *
		 * @param {string} key Preset key (or 'custom').
		 */
		var selectPreset = function ( key ) {
			if ( 'custom' === key ) {
				ensureCustomOption().selected = true;
			} else {
				presetSelect.value = key;
			}
			if ( descriptions[ key ] ) {
				descriptionEl.textContent = descriptions[ key ];
			}
		};

		/**
		 * Read current surface dropdown values.
		 *
		 * Surfaces whose elements are not in the DOM (e.g. WPGraphQL when
		 * the plugin is inactive) are omitted from the result.
		 *
		 * @return {Object} Map of surface key → current value.
		 */
		var getCurrentSurfaceValues = function () {
			var values = {};
			var i, el;
			for ( i = 0; i < surfaceKeys.length; i++ ) {
				el = document.getElementById( surfaceKeys[ i ] );
				if ( el ) {
					values[ surfaceKeys[ i ] ] = el.value;
				}
			}
			return values;
		};

		/**
		 * Detect which preset matches the current surface values.
		 *
		 * Surfaces absent from the DOM are skipped in the comparison so
		 * that presets still match when an optional surface (WPGraphQL)
		 * is not rendered.
		 *
		 * @return {string} Matching preset key, or 'custom'.
		 */
		var detectPreset = function () {
			var current     = getCurrentSurfaceValues();
			var presetNames = Object.keys( presetPolicies );
			var i, j, name, policies, key, match;
			for ( i = 0; i < presetNames.length; i++ ) {
				name     = presetNames[ i ];
				policies = presetPolicies[ name ];
				match    = true;
				for ( j = 0; j < surfaceKeys.length; j++ ) {
					key = surfaceKeys[ j ];
					if ( ! current.hasOwnProperty( key ) ) {
						continue;
					}
					if ( current[ key ] !== policies[ key ] ) {
						match = false;
						break;
					}
				}
				if ( match ) {
					return name;
				}
			}
			return 'custom';
		};

		// Forward sync: preset change → update surface dropdowns + description.
		presetSelect.addEventListener( 'change', function () {
			var key      = presetSelect.value;
			var policies = presetPolicies[ key ];
			var i, el;

			if ( descriptions[ key ] ) {
				descriptionEl.textContent = descriptions[ key ];
			}
			if ( policies ) {
				for ( i = 0; i < surfaceKeys.length; i++ ) {
					el = document.getElementById( surfaceKeys[ i ] );
					if ( el && policies[ surfaceKeys[ i ] ] ) {
						el.value = policies[ surfaceKeys[ i ] ];
					}
				}
			}
		} );

		// Reverse sync: any surface change → detect matching preset.
		var i, el;
		for ( i = 0; i < surfaceKeys.length; i++ ) {
			el = document.getElementById( surfaceKeys[ i ] );
			if ( el ) {
				el.addEventListener( 'change', function () {
					selectPreset( detectPreset() );
				} );
			}
		}
	}

	// --- Access tab: grant / revoke governance capabilities ---
	//
	// The grant/revoke/manage buttons POST to admin-ajax. Each carries its own
	// wp_sudo_access data-nonce (NOT wpSudoAdmin.nonce, which is the MU-plugin
	// nonce). These actions are gated by the Action Registry, so the Gate returns
	// success:false with data.code === 'sudo_required' (plus a helpful message)
	// when there is no active sudo session — surfaced verbatim, never bypassed.
	var accessStrings = ( wpSudoAdmin && wpSudoAdmin.access ) || {};

	/**
	 * Announce a message to assistive technology (wp.a11y.speak is guarded).
	 *
	 * @param {string} msg Message to announce.
	 */
	function announce( msg ) {
		if ( msg && window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( msg );
		}
	}

	/**
	 * POST an access action and route the outcome.
	 *
	 * Surfaces data.message verbatim on both success and ALL error responses,
	 * so the operator sees the server's actionable guidance: sudo_required
	 * (reauthenticate and retry), the 409 last-manager block, and the 429
	 * revoke rate-limit all carry a specific message.
	 *
	 * @param {string}   action    AJAX action name.
	 * @param {string}   nonce     The wp_sudo_access nonce from the element.
	 * @param {Object}   fields    Extra POST fields (user_id, cap).
	 * @param {Element}  button    The clicked button (disabled during the request).
	 * @param {Function} onSuccess Optional DOM update to run on success.
	 * @param {Element}  resultEl  Optional aria-live element for inline feedback.
	 */
	function sendAccessAction( action, nonce, fields, button, onSuccess, resultEl ) {
		if ( ! wpSudoAdmin || ! wpSudoAdmin.ajaxUrl || ! action ) {
			return;
		}

		if ( button ) {
			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );
		}
		if ( resultEl ) {
			resultEl.textContent = '';
		}

		var body = new FormData();
		body.append( 'action', action );
		body.append( '_nonce', nonce || '' );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		fetch( wpSudoAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( button ) {
					button.setAttribute( 'aria-busy', 'false' );
				}
				var data = result.data || {};

				if ( result.success ) {
					var smsg = data.message || accessStrings.success || '';
					if ( resultEl ) {
						resultEl.textContent = smsg;
					}
					announce( smsg );
					// Re-enable for repeated use BEFORE onSuccess, so the controls
					// that should not stay active still win: drift-grant/revoke-cap
					// remove the button, and revoke-session re-disables + relabels.
					if ( button ) {
						button.disabled = false;
					}
					if ( onSuccess ) {
						onSuccess();
					}
				} else {
					if ( button ) {
						button.disabled = false;
					}
					var emsg = data.message || strings.genericError || '';
					if ( resultEl ) {
						resultEl.textContent = emsg;
					} else {
						// eslint-disable-next-line no-alert
						window.alert( emsg );
					}
					announce( emsg );
				}
			} )
			.catch( function () {
				if ( button ) {
					button.disabled = false;
					button.setAttribute( 'aria-busy', 'false' );
				}
				var nmsg = strings.networkError || '';
				if ( resultEl ) {
					resultEl.textContent = nmsg;
				} else {
					// eslint-disable-next-line no-alert
					window.alert( nmsg );
				}
				announce( nmsg );
			} );
	}

	// Progressive enhancement for the native grant-user select.
	var grantUserSearch = document.getElementById( 'wp-sudo-grant-user-search' );
	var grantUserSelect = document.getElementById( 'wp-sudo-grant-user' );
	if ( grantUserSearch && grantUserSelect ) {
		grantUserSearch.addEventListener( 'input', function () {
			var query   = grantUserSearch.value.toLowerCase().trim();
			var matches = [];

			Array.prototype.forEach.call( grantUserSelect.options, function ( option ) {
				if ( '0' === option.value ) {
					option.hidden = false;
					return;
				}

				var searchText = ( option.getAttribute( 'data-search-text' ) || option.textContent || '' ).toLowerCase();
				var isMatch    = ! query || -1 !== searchText.indexOf( query );
				option.hidden  = ! isMatch;

				if ( query && isMatch ) {
					matches.push( option );
				}
			} );

			grantUserSelect.value = 1 === matches.length ? matches[ 0 ].value : '0';
		} );

		grantUserSelect.addEventListener( 'change', function () {
			var selected = grantUserSelect.options[ grantUserSelect.selectedIndex ];
			grantUserSearch.value = selected && '0' !== selected.value ? selected.textContent : '';
		} );
	}

	// Main "Grant Capability" form.
	var grantBtn    = document.getElementById( 'wp-sudo-grant-submit' );
	var grantResult = document.getElementById( 'wp-sudo-grant-result' );
	if ( grantBtn ) {
		grantBtn.addEventListener( 'click', function () {
			var userInput = document.getElementById( 'wp-sudo-grant-user' );
			var capSelect = document.getElementById( 'wp-sudo-grant-cap' );
			var userId    = userInput ? parseInt( userInput.value, 10 ) : 0;

			if ( ! userId || userId < 1 ) {
				var vmsg = accessStrings.invalidUser || '';
				if ( grantResult ) {
					grantResult.textContent = vmsg;
				}
				announce( vmsg );
				if ( userInput ) {
					userInput.focus();
				}
				return;
			}

			sendAccessAction(
				wpSudoAdmin.grantAction,
				grantBtn.getAttribute( 'data-nonce' ),
				{ user_id: userId, cap: capSelect ? capSelect.value : '' },
				grantBtn,
				null,
				grantResult
			);
		} );
	}

	// Delegated handlers for the (re-rendered) grantee/drift table rows.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.classList ) {
			return;
		}

		// Drift-panel: grant manage_wp_sudo. On success the user no longer
		// drifts, so remove their row.
		if ( target.classList.contains( 'wp-sudo-grant-manage' ) ) {
			var driftRow = target.closest( 'tr' );
			sendAccessAction(
				wpSudoAdmin.grantAction,
				target.getAttribute( 'data-nonce' ),
				{ user_id: target.getAttribute( 'data-user-id' ), cap: target.getAttribute( 'data-cap' ) },
				target,
				function () {
					if ( driftRow && driftRow.parentNode ) {
						driftRow.parentNode.removeChild( driftRow );
					}
				},
				null
			);
			return;
		}

		// Revoke a single capability. Remove the cap label + its button; if the
		// holder has no caps left, remove the whole row.
		if ( target.classList.contains( 'wp-sudo-revoke-cap' ) ) {
			var capCell = target.closest( 'td' );
			var capRow  = target.closest( 'tr' );
			var capCode = target.previousElementSibling;
			sendAccessAction(
				wpSudoAdmin.revokeCapAction,
				target.getAttribute( 'data-nonce' ),
				{ user_id: target.getAttribute( 'data-user-id' ), cap: target.getAttribute( 'data-cap' ) },
				target,
				function () {
					if ( capCode && 'CODE' === capCode.tagName ) {
						capCode.parentNode.removeChild( capCode );
					}
					if ( target.parentNode ) {
						target.parentNode.removeChild( target );
					}
					if ( capRow && capCell && ! capCell.querySelector( '.wp-sudo-revoke-cap' ) && capRow.parentNode ) {
						capRow.parentNode.removeChild( capRow );
					}
				},
				null
			);
			return;
		}
	} );
} )();
