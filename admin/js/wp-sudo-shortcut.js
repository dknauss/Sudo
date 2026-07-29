/**
 * WP Sudo – Keyboard shortcut for proactive sudo activation.
 *
 * Listens for Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac)
 * and navigates to the challenge page in session-only mode.
 *
 * When a sudo session is already active the shortcut script is not
 * enqueued, so the shortcut is silently unavailable — the admin bar
 * countdown provides visual feedback instead.
 *
 * @package WP_Sudo
 */
( function() {
	'use strict';

	var config = window.wpSudoShortcut || {};

	if ( ! config.challengeUrl ) {
		return;
	}

	document.addEventListener( 'keydown', function( e ) {
		// Ctrl+Shift+S (Windows/Linux) or Cmd+Shift+S (Mac).
		if ( e.shiftKey && ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 's' ) {
			e.preventDefault();
			window.location.href = config.challengeUrl;
		}
	} );
}() );
