<?php
/**
 * Test-only real WordPress upgrader handoff for the Phase 27 adapter.
 *
 * The destination is a fresh directory below uploads, never WP_PLUGIN_DIR.
 */

declare(strict_types=1);

const PHASE27_UPGRADER_PROOF_FILE = 'phase27-proof.php';
const PHASE27_SUBSTITUTED_PACKAGE = 'UEsDBBQAAAAAAIJN/FwZAs/UNAAAADQAAAAfAAAAcGhhc2UyNy1wcm9vZi9waGFzZTI3LXByb29mLnBocDw/cGhwCi8qClBsdWdpbiBOYW1lOiBQaGFzZSAyNyBTdWJzdGl0dXRlZCBQcm9vZgoqLwpQSwECPwMUAAAAAACCTfxcGQLP1DQAAAA0AAAAHwAAAAAAAAAAAAAAtoEAAAAAcGhhc2UyNy1wcm9vZi9waGFzZTI3LXByb29mLnBocFBLBQYAAAAAAQABAE0AAABxAAAAAAA=';

/**
 * Exercise the production WP_Upgrader package pipeline without installing or
 * activating a plugin. The response is derived from its installed proof file.
 *
 * @return string|WP_Error Digest of the proof file that WP_Upgrader installed.
 */
function phase27_real_upgrader_effect(string $uploaded_tmp_name) {
	$package = $uploaded_tmp_name;
	$mutated = false;
	if (phase27_mutation_enabled('EFFECT_INPUT')) {
		$package = tempnam(sys_get_temp_dir(), 'p27-substitute-');
		$mutated = true;
		if (! is_string($package) || false === file_put_contents($package, base64_decode(PHASE27_SUBSTITUTED_PACKAGE, true))) {
			return new WP_Error('phase27_effect_setup', 'Could not prepare the mutated upgrader package.', array('status' => 500));
		}
	}

	if (! is_file($package)) {
		return new WP_Error('phase27_effect_package', 'The upgrader package is unavailable.', array('status' => 500));
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
	require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

	$uploads     = wp_upload_dir();
	$root        = trailingslashit((string) $uploads['basedir']) . 'phase27-upgrader';
	if (! wp_mkdir_p($root)) {
		return new WP_Error('phase27_effect_destination', 'Could not create the isolated upgrader root.', array('status' => 500));
	}
	$destination = trailingslashit($root) . wp_generate_uuid4();

	// WP_Upgrader::unpack_package() empties the whole of wp-content/upgrade/
	// before unpacking (WP 7.0.2, "Clean up contents of upgrade directory
	// beforehand"), and $upgrade_folder is a fixed path rather than a per-call
	// one. Two overlapping run() calls therefore delete each other's working
	// directory: the loser's $source disappears, install_package() returns
	// new_source_read_failed, and the adapter maps that to 500.
	//
	// That matters here because the concurrency test deliberately fires two
	// simultaneous redemptions. On the pristine path only one of them ever
	// reaches the upgrader, so the race is invisible. Under the ATOMIC_CONSUME
	// mutation both reach it, and a 500 in place of the expected 409 made the
	// mutation score as unkilled roughly 3% of the time — a flaky evidence
	// artifact in the one lane whose product is reproducible evidence.
	//
	// Serialising the upgrader is the honest fix: it repairs the fixture rather
	// than loosening the assertion. With the mutation applied both requests now
	// complete and return 200, so the guard dies deterministically at its
	// declared one-winner assertion instead of at the losers assertion.
	$lock_path = trailingslashit($root) . '.phase27-upgrader.lock';
	$lock      = fopen($lock_path, 'c');
	if (! is_resource($lock)) {
		return new WP_Error('phase27_effect_lock', 'Could not open the upgrader lock.', array('status' => 500));
	}
	if (! flock($lock, LOCK_EX)) {
		fclose($lock);
		return new WP_Error('phase27_effect_lock', 'Could not acquire the upgrader lock.', array('status' => 500));
	}

	$upgrader = new WP_Upgrader(new Automatic_Upgrader_Skin());
	$result   = $upgrader->run(
		array(
			'package'                     => $package,
			'destination'                 => $destination,
			'clear_destination'           => false,
			'clear_working'               => true,
			'abort_if_destination_exists' => true,
			'hook_extra'                  => array(
				'type'   => 'plugin',
				'action' => 'install',
			),
		)
	);

	$proof = trailingslashit($destination) . PHASE27_UPGRADER_PROOF_FILE;
	$digest = ! is_wp_error($result) && is_file($proof) ? hash_file('sha256', $proof) : false;

	global $wp_filesystem;
	if (isset($wp_filesystem) && is_object($wp_filesystem)) {
		$wp_filesystem->delete($destination, true);
	}

	flock($lock, LOCK_UN);
	fclose($lock);
	if ($mutated && $package !== $uploaded_tmp_name) {
		unlink($package);
	}

	if (is_wp_error($result)) {
		return new WP_Error('phase27_effect', $result->get_error_message(), array('status' => 500));
	}
	if (! is_string($digest)) {
		return new WP_Error('phase27_effect_digest', 'The upgrader proof file could not be fingerprinted.', array('status' => 500));
	}

	return $digest;
}
