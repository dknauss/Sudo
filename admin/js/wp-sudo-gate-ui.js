/**
 * WP Sudo – Gate UI: disable gated buttons when no sudo session is active.
 *
 * Enqueued on theme and plugin admin pages when the current user does NOT
 * have an active sudo session. Disables Install, Activate, Update, and
 * Delete buttons so the user cannot trigger a gated AJAX request.
 *
 * A MutationObserver watches for dynamically added DOM nodes (theme
 * search results, infinite scroll, AJAX-installed cards) and disables
 * new buttons as they appear.
 *
 * @package WP_Sudo
 */
( function() {
	'use strict';

	var config = window.wpSudoGateUi || {};

	if ( ! config.page ) {
		return;
	}

	/**
	 * Selector map keyed by page identifier.
	 *
	 * Each entry targets only the dangerous action buttons for that page.
	 * Preview / Details / Live Preview buttons are intentionally excluded.
	 */
	var selectorMap = {
		'theme-install': [
			'.theme-install',
			'.update-now',
		].join( ', ' ),

		themes: [
			'.theme-actions .activate',
			'.theme-actions .delete-theme',
			'.button.update-now',
			'.submitdelete.deletion',
		].join( ', ' ),

		'plugin-install': [
			'.install-now',
			'.update-now',
			'.activate-now',
		].join( ', ' ),

		plugins: [
			'.activate a',
			'.deactivate a',
			'.delete a',
		].join( ', ' ),
	};

	var sel = selectorMap[ config.page ];
	if ( ! sel ) {
		return;
	}

	/**
	 * Block click handler — capture phase so it fires before wp.updates.
	 *
	 * @param {Event} e Click event.
	 */
	function blockClick( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();
	}

	/**
	 * Disable all matching buttons within a root element.
	 *
	 * @param {Element|Document} root The DOM subtree to search.
	 */
	function disableButtons( root ) {
		var buttons = ( root || document ).querySelectorAll( sel );

		for ( var i = 0; i < buttons.length; i++ ) {
			var btn = buttons[ i ];

			// Skip already-processed nodes.
			if ( btn.classList.contains( 'wp-sudo-disabled' ) ) {
				continue;
			}

			btn.classList.add( 'disabled', 'wp-sudo-disabled' );
			btn.setAttribute( 'aria-disabled', 'true' );

			// Links styled as buttons need role="button" so screen readers
			// announce the disabled state (aria-disabled is only recognised
			// on elements with an interactive ARIA role).
			if ( btn.tagName === 'A' && ! btn.getAttribute( 'role' ) ) {
				btn.setAttribute( 'role', 'button' );
			}

			// Remove href so the link does not navigate.
			if ( btn.hasAttribute( 'href' ) ) {
				btn.removeAttribute( 'href' );
			}

			// Capture-phase listener beats wp.updates click handlers.
			btn.addEventListener( 'click', blockClick, true );
		}
	}

	// Initial pass — handles server-rendered buttons.
	disableButtons( document );

	// Watch for dynamically added cards (theme search, infinite scroll).
	if ( typeof MutationObserver !== 'undefined' ) {
		var observer = new MutationObserver( function( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					if ( added[ j ].nodeType === 1 ) {
						disableButtons( added[ j ] );
					}
				}
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	// Inject a minimal inline style for pointer-events fallback.
	var style = document.createElement( 'style' );
	style.textContent = '.wp-sudo-disabled{pointer-events:none;opacity:.5;cursor:default}';
	document.head.appendChild( style );
}() );
