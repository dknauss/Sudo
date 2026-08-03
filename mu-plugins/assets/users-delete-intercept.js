/**
 * Intercepts the REAL native "Delete" row-action link on wp-admin/users.php
 * and routes it through the digest-bound approval flow instead of letting
 * it follow WordPress's own two-step (confirm page -> dodelete POST) flow.
 *
 * This is Approach A from the intent-signal experiment: the native button
 * stays the native button -- no bespoke UI replaces it -- but the actual
 * mutating request goes through the same narrow, single-entry-point REST
 * route pattern already proven for install-plugin and create-user, rather
 * than through a second server-side path. Nothing here changes what the
 * server trusts; it only changes what the browser does before the request
 * is sent.
 */
( function () {
	'use strict';

	function sha256Hex( text ) {
		var bytes = new TextEncoder().encode( text );
		return crypto.subtle.digest( 'SHA-256', bytes ).then( function ( buf ) {
			return Array.prototype.map
				.call( new Uint8Array( buf ), function ( b ) {
					return b.toString( 16 ).padStart( 2, '0' );
				} )
				.join( '' );
		} );
	}

	function userIdFromLink( link ) {
		var url = new URL( link.href, window.location.origin );
		var fromQuery = url.searchParams.get( 'user' );
		if ( fromQuery ) {
			return fromQuery;
		}
		var row = link.closest( 'tr[id^="user-"]' );
		return row ? row.id.replace( 'user-', '' ) : null;
	}

	function restFetch( path, params ) {
		return fetch( capFloorDeleteIntercept.restUrl + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-WP-Nonce': capFloorDeleteIntercept.nonce,
			},
			credentials: 'same-origin',
			body: new URLSearchParams( params ).toString(),
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				return { ok: res.ok, status: res.status, json: json };
			} );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		// core's real markup uses class 'submitdelete', not 'delete' --
		// verified against wp-admin/includes/class-wp-users-list-table.php
		// rather than assumed.
		var link = event.target.closest( 'a.submitdelete[href*="action=delete"]' );
		if ( ! link ) {
			return;
		}
		event.preventDefault();

		var userId = userIdFromLink( link );
		if ( ! userId ) {
			window.alert( 'Could not determine which user this link targets.' );
			return;
		}

		var password = window.prompt(
			'Confirm your password to delete this user (reassignment is not offered by this intercepted flow):'
		);
		if ( null === password || '' === password ) {
			return;
		}

		var reassign = '0';
		sha256Hex( userId + '\n' + reassign )
			.then( function ( targetHash ) {
				return restFetch( 'request-approval', {
					capability: 'delete_users',
					password: password,
					target_hash: targetHash,
				} ).then( function ( approvalResult ) {
					if ( ! approvalResult.ok ) {
						throw new Error(
							( approvalResult.json && approvalResult.json.message ) || 'Approval request failed.'
						);
					}
					return restFetch( 'delete-user', {
						approval_id: approvalResult.json.approval_id,
						user_id: userId,
						reassign: reassign,
					} );
				} );
			} )
			.then( function ( deleteResult ) {
				if ( ! deleteResult.ok ) {
					throw new Error(
						( deleteResult.json && deleteResult.json.message ) || 'Delete request failed.'
					);
				}
				var row = link.closest( 'tr[id^="user-"]' );
				if ( row ) {
					row.remove();
				}
			} )
			.catch( function ( err ) {
				window.alert( 'User was not deleted: ' + err.message );
			} );
	} );
} )();
