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
} )();
