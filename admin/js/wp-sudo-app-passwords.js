/**
 * Per-application-password policy management.
 *
 * Augments the WordPress Application Passwords table on user profile
 * pages with a "Sudo Policy" column. Each application password can be
 * assigned an individual policy (Disabled, Limited, Unrestricted) that
 * overrides the global REST API (App Passwords) policy for that
 * specific credential.
 *
 * @since 2.3.0
 * @package WP_Sudo
 */
( function () {
	'use strict';

	var config = window.wpSudoAppPasswords;
	if ( ! config ) {
		return;
	}

	/**
	 * Announce a message to screen readers via wp.a11y.speak().
	 *
	 * @param {string} message The message to announce.
	 */
	function announce( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, 'assertive' );
		}
	}

	/**
	 * Build a policy <select> element for a given application password UUID.
	 *
	 * @param {string} uuid The application password UUID.
	 * @return {HTMLSelectElement} The select element.
	 */
	function buildSelect( uuid ) {
		var select = document.createElement( 'select' );
		select.className = 'wp-sudo-app-password-policy';
		select.setAttribute( 'data-uuid', uuid );
		select.setAttribute( 'aria-label', config.i18n.policyAriaLabel );

		var options = config.options || {};
		var currentPolicy = ( config.policies && config.policies[ uuid ] ) || '';

		Object.keys( options ).forEach( function ( value ) {
			var option = document.createElement( 'option' );
			option.value = value;
			option.textContent = options[ value ];
			if ( value === currentPolicy ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );

		select.addEventListener( 'change', function () {
			savePolicy( uuid, select.value, select );
		} );

		return select;
	}

	/**
	 * Save a policy override via AJAX.
	 *
	 * @param {string}            uuid   The application password UUID.
	 * @param {string}            policy The policy value (or '' for global default).
	 * @param {HTMLSelectElement} select The select element (for visual feedback).
	 */
	function savePolicy( uuid, policy, select ) {
		select.disabled = true;

		var data = new FormData();
		data.append( 'action', 'wp_sudo_app_password_policy' );
		data.append( '_nonce', config.nonce );
		data.append( 'user_id', config.userId || 0 );
		data.append( 'uuid', uuid );
		data.append( 'policy', policy );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				select.disabled = false;
				if ( result.success ) {
					// Update local cache.
					if ( ! config.policies ) {
						config.policies = {};
					}
					if ( policy ) {
						config.policies[ uuid ] = policy;
					} else {
						delete config.policies[ uuid ];
					}
					// Brief visual confirmation.
					select.style.outline = '2px solid #00a32a';
					setTimeout( function () {
						select.style.outline = '';
					}, 1000 );
					announce( config.i18n.policySaved );
				} else if ( result.data && result.data.code === 'sudo_required' ) {
					// No active sudo session — restore the previous value and inform the user.
					var previous = ( config.policies && config.policies[ uuid ] ) || '';
					select.value = previous;
					select.style.outline = '2px solid #dba617';
					setTimeout( function () {
						select.style.outline = '';
					}, 3000 );
					// eslint-disable-next-line no-alert
					window.alert( result.data.message || config.i18n.sudoRequired );
				} else {
					select.style.outline = '2px solid #d63638';
					setTimeout( function () {
						select.style.outline = '';
					}, 2000 );
					announce( config.i18n.policyError );
				}
			} )
			.catch( function () {
				select.disabled = false;
				select.style.outline = '2px solid #d63638';
				setTimeout( function () {
					select.style.outline = '';
				}, 2000 );
				announce( config.i18n.policyError );
			} );
	}

	/**
	 * Initialize: add the Sudo Policy column to the Application Passwords table.
	 *
	 * WordPress renders the Application Passwords table via JavaScript
	 * (wp.template + Backbone). We use a MutationObserver to detect when
	 * rows are added, then augment them with our policy dropdown.
	 */
	function init() {
		// WordPress renders the Application Passwords table inside a
		// .application-passwords-list-table-wrapper div. The <table> itself
		// carries generic WP_List_Table classes, not a unique class of its own.
		var wrapper = document.querySelector( '.application-passwords-list-table-wrapper' );
		var table = wrapper ? wrapper.querySelector( 'table' ) : null;
		if ( ! table ) {
			return;
		}

		// Add header column.
		var thead = table.querySelector( 'thead tr' );
		if ( thead ) {
			var th = document.createElement( 'th' );
			th.scope = 'col';
			th.textContent = config.i18n.policyColumnHeader;
			th.className = 'column-wp-sudo-policy';
			// Insert before the last column (Revoke).
			var lastTh = thead.querySelector( 'th:last-child' );
			if ( lastTh ) {
				thead.insertBefore( th, lastTh );
			} else {
				thead.appendChild( th );
			}
		}

		// Process existing rows.
		var rows = table.querySelectorAll( 'tbody tr' );
		rows.forEach( function ( row ) {
			augmentRow( row );
		} );

		// Watch for new rows (Application Passwords are rendered dynamically).
		var tbody = table.querySelector( 'tbody' );
		if ( tbody ) {
			var observer = new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					mutation.addedNodes.forEach( function ( node ) {
						if ( node.nodeType === 1 && node.tagName === 'TR' ) {
							augmentRow( node );
						}
					} );
				} );
			} );
			observer.observe( tbody, { childList: true } );
		}
	}

	/**
	 * Augment a single Application Password table row with a policy dropdown.
	 *
	 * @param {HTMLTableRowElement} row The table row element.
	 */
	function augmentRow( row ) {
		// Skip if already augmented.
		if ( row.querySelector( '.wp-sudo-app-password-policy' ) ) {
			return;
		}

		// Find the UUID from the row data. WordPress stores application
		// password data in the row's data attributes or the revoke button.
		var uuid = extractUuid( row );
		if ( ! uuid ) {
			return;
		}

		var td = document.createElement( 'td' );
		td.className = 'column-wp-sudo-policy';
		td.setAttribute( 'data-colname', config.i18n.policyColumnName );
		td.appendChild( buildSelect( uuid ) );

		// Insert before the last cell (Revoke button).
		var lastTd = row.querySelector( 'td:last-child' );
		if ( lastTd ) {
			row.insertBefore( td, lastTd );
		} else {
			row.appendChild( td );
		}
	}

	/**
	 * Extract the UUID from an Application Password table row.
	 *
	 * WordPress stores the UUID in the revoke button's data attribute
	 * or in the Backbone model accessible via the row.
	 *
	 * @param {HTMLTableRowElement} row The table row element.
	 * @return {string|null} The UUID, or null if not found.
	 */
	function extractUuid( row ) {
		// WordPress sets data-uuid directly on the <tr> element in both the
		// PHP-rendered and Backbone JS-rendered versions of the table.
		return row.getAttribute( 'data-uuid' ) || null;
	}

	// Run on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
