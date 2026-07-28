<?php
/**
 * Plugin Name: Phase 27 WordPress Research Adapter
 * Description: Test-only WordPress/PHP adapter for the Phase 27 trusted-flow experiment.
 */

declare(strict_types=1);

const PHASE27_BINDING_COOKIE = '__Host-wp_sudo_action_binding';
const PHASE27_BINDING_OPTION = 'phase27_research_bindings';
const PHASE27_SINK_OPTION = 'phase27_research_sink_count';
const PHASE27_SINK_DIGESTS_OPTION = 'phase27_research_sink_digests';

/**
 * Whether the runner intentionally disabled a named guard.
 */
function phase27_mutation_enabled(string $id): bool {
	$selected = @file_get_contents('/tmp/phase27-mutation');

	return is_string($selected) && trim($selected) === $id;
}

/**
 * Return the research intent table name.
 */
function phase27_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'phase27_research_intents';
}

/**
 * Return the research approval-rate table name.
 */
function phase27_rate_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'phase27_research_rates';
}

/**
 * Create the test-only intent table when necessary.
 */
function phase27_create_table(): void {
	global $wpdb;

	$table   = phase27_table();
	$charset = $wpdb->get_charset_collate();
	$sql     = "CREATE TABLE {$table} (
		id varchar(64) NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		session_hash char(64) NOT NULL,
		binding_hash char(64) NOT NULL,
		digest char(64) NOT NULL,
		state varchar(16) NOT NULL,
		created_at bigint(20) unsigned NOT NULL,
		PRIMARY KEY  (id),
		KEY owner_state (user_id, state)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);

	$rate_table = phase27_rate_table();
	$rate_sql   = "CREATE TABLE {$rate_table} (
		user_id bigint(20) unsigned NOT NULL,
		failures int unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (user_id)
	) {$charset};";
	dbDelta($rate_sql);
}
add_action('init', 'phase27_create_table');

/**
 * Parse all exact-name cookie values from the raw request header.
 *
 * PHP's normalized cookie map discards duplicate-name information, so the
 * research adapter deliberately inspects HTTP_COOKIE.
 *
 * @return list<string>
 */
function phase27_raw_cookie_values(string $name): array {
	$header = isset($_SERVER['HTTP_COOKIE']) ? (string) $_SERVER['HTTP_COOKIE'] : '';
	$values = array();

	foreach (explode(';', $header) as $pair) {
		$parts = explode('=', trim($pair), 2);
		if (count($parts) === 2 && $parts[0] === $name) {
			$values[] = rawurldecode($parts[1]);
		}
	}

	return $values;
}

/**
 * Validate or mint the server-owned browser binding.
 *
 * @return string|WP_Error Raw binding value or refusal.
 */
function phase27_binding() {
	static $resolved = null;

	if (is_string($resolved)) {
		return $resolved;
	}

	$user_id = get_current_user_id();
	$session = wp_get_session_token();
	$values  = phase27_raw_cookie_values(PHASE27_BINDING_COOKIE);

	if ($user_id < 1 || '' === $session) {
		return new WP_Error('phase27_auth', 'Authentication required.', array('status' => 401));
	}

	if (count($values) > 1) {
		return new WP_Error('phase27_duplicate_binding', 'Ambiguous binding cookie.', array('status' => 400));
	}

	$registry = get_option(PHASE27_BINDING_OPTION, array());
	$binding  = $values[0] ?? '';
	$hash     = '' === $binding ? '' : hash('sha256', $binding);
	$owner    = $registry[$hash] ?? null;

	if (
		'' !== $binding &&
		is_array($owner) &&
		(int) ($owner['user_id'] ?? 0) === $user_id &&
		hash_equals((string) ($owner['session_hash'] ?? ''), hash('sha256', $session))
	) {
		$resolved = $binding;
		return $binding;
	}

	$binding          = bin2hex(random_bytes(32));
	$hash             = hash('sha256', $binding);
	$registry[$hash]  = array(
		'user_id'     => $user_id,
		'session_hash' => hash('sha256', $session),
	);
	update_option(PHASE27_BINDING_OPTION, $registry, false);
	setcookie(
		PHASE27_BINDING_COOKIE,
		$binding,
		array(
			'expires'  => time() + 600,
			'path'     => '/',
			'secure'   => true,
			'httponly' => true,
			'samesite' => 'Strict',
		)
	);

	$resolved = $binding;
	return $binding;
}

/**
 * Require the current request's registered binding without minting one.
 *
 * @return string|WP_Error
 */
function phase27_require_binding() {
	$values = phase27_raw_cookie_values(PHASE27_BINDING_COOKIE);
	if (1 !== count($values)) {
		return new WP_Error('phase27_binding', 'Exactly one binding is required.', array('status' => 403));
	}

	$binding = phase27_binding();
	if (is_wp_error($binding) || ! hash_equals($values[0], $binding)) {
		return new WP_Error('phase27_binding', 'Registered binding required.', array('status' => 403));
	}

	return $binding;
}

/**
 * Render the authenticated fixture page and bootstrap a binding.
 */
function phase27_render_page(): void {
	global $wpdb;

	if (! current_user_can('install_plugins')) {
		wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
	}

	$binding = phase27_binding();
	if (is_wp_error($binding)) {
		wp_die(esc_html($binding->get_error_message()));
	}

	if (isset($_GET['phase27_reset']) && '1' === (string) $_GET['phase27_reset']) {
		$wpdb->query('DELETE FROM ' . phase27_table());
		$wpdb->query('DELETE FROM ' . phase27_rate_table());
		delete_option(PHASE27_SINK_OPTION);
		delete_option(PHASE27_SINK_DIGESTS_OPTION);
	}

	printf(
		'<div class="wrap" id="phase27-research" data-nonce="%s"><h1>Phase 27 WordPress adapter</h1></div>',
		esc_attr(wp_create_nonce('wp_rest'))
	);
}

add_action(
	'admin_init',
	static function (): void {
		if (isset($_GET['page']) && 'phase27-research' === (string) $_GET['page']) {
			phase27_binding();
		}
	},
	1
);

add_action(
	'admin_menu',
	static function (): void {
		add_management_page(
			'Phase 27 Research',
			'Phase 27 Research',
			'install_plugins',
			'phase27-research',
			'phase27_render_page'
		);
	}
);

/**
 * Shared REST permission.
 */
function phase27_permission(): bool {
	return current_user_can('install_plugins');
}

/**
 * Load and validate an intent against the current WordPress session/binding.
 *
 * @return object|WP_Error
 */
function phase27_intent_for_request(string $id) {
	global $wpdb;

	$binding = phase27_require_binding();
	if (is_wp_error($binding)) {
		return $binding;
	}

	$table = phase27_table();
	$row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %s", $id));
	if (
		! is_object($row) ||
		(int) $row->user_id !== get_current_user_id() ||
		! hash_equals((string) $row->session_hash, hash('sha256', wp_get_session_token())) ||
		! hash_equals((string) $row->binding_hash, hash('sha256', $binding))
	) {
		return new WP_Error('phase27_intent', 'Intent unavailable.', array('status' => 403));
	}

	return $row;
}

/**
 * Apply the account-scoped approval budget and verify the password.
 *
 * @return true|WP_Error
 */
function phase27_verify_factor(string $password) {
	global $wpdb;

	$user_id   = get_current_user_id();
	$rate_table = phase27_rate_table();
	$failures  = (int) $wpdb->get_var(
		$wpdb->prepare("SELECT failures FROM {$rate_table} WHERE user_id = %d", $user_id)
	);
	if ($failures >= 3) {
		return new WP_Error('phase27_rate', 'Approval temporarily unavailable.', array('status' => 429));
	}

	$user = wp_get_current_user();
	if (wp_check_password($password, $user->user_pass, $user->ID)) {
		return true;
	}

	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$rate_table} (user_id, failures) VALUES (%d, 1)
			ON DUPLICATE KEY UPDATE failures = failures + 1",
			$user_id
		)
	);

	return new WP_Error('phase27_factor', 'Fresh authentication failed.', array('status' => 403));
}

/**
 * Consume the selected temporary file in a minimal capturing file-write sink.
 *
 * @return string|WP_Error Digest of the bytes written by the effect.
 */
function phase27_capture_upload_effect(string $uploaded_tmp_name) {
	$source = $uploaded_tmp_name;

	if (phase27_mutation_enabled('EFFECT_INPUT')) {
		$source = tempnam(sys_get_temp_dir(), 'p27-substitute-');
		if (! is_string($source) || false === file_put_contents($source, 'substituted effect bytes')) {
			return new WP_Error('phase27_effect_setup', 'Could not prepare the mutated effect input.', array('status' => 500));
		}
	}

	$destination = tempnam(sys_get_temp_dir(), 'p27-effect-');
	if (! is_string($destination) || ! copy($source, $destination)) {
		return new WP_Error('phase27_effect', 'The capturing file effect failed.', array('status' => 500));
	}

	$digest = hash_file('sha256', $destination);
	unlink($destination);
	if ($source !== $uploaded_tmp_name) {
		unlink($source);
	}

	return is_string($digest)
		? $digest
		: new WP_Error('phase27_effect_digest', 'The effect output could not be fingerprinted.', array('status' => 500));
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'phase27/v1',
			'/preflight-upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'phase27_permission',
				'callback'            => static function (WP_REST_Request $request) {
					global $wpdb;

					$binding = phase27_require_binding();
					$digest  = (string) $request->get_param('digest');
					if (is_wp_error($binding)) {
						return $binding;
					}
					if (1 !== preg_match('/^[a-f0-9]{64}$/', $digest)) {
						return new WP_Error('phase27_digest', 'Invalid digest.', array('status' => 400));
					}

					$id = bin2hex(random_bytes(32));
					$wpdb->insert(
						phase27_table(),
						array(
							'id'           => $id,
							'user_id'      => get_current_user_id(),
							'session_hash' => hash('sha256', wp_get_session_token()),
							'binding_hash' => hash('sha256', $binding),
							'digest'       => $digest,
							'state'        => 'prepared',
							'created_at'   => time(),
						),
						array('%s', '%d', '%s', '%s', '%s', '%s', '%d')
					);

					return new WP_REST_Response(array('id' => $id, 'digest' => $digest), 201);
				},
			)
		);

		register_rest_route(
			'phase27/v1',
			'/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'phase27_permission',
				'callback'            => static function (WP_REST_Request $request) {
					global $wpdb;

					$id     = (string) $request->get_param('intent');
					$intent = phase27_intent_for_request($id);
					if (is_wp_error($intent)) {
						return $intent;
					}
					$factor = phase27_verify_factor((string) $request->get_param('password'));
					if (is_wp_error($factor)) {
						return $factor;
					}

					$changed = $wpdb->query(
						$wpdb->prepare(
							'UPDATE ' . phase27_table() . " SET state = 'approved' WHERE id = %s AND state = 'prepared'",
							$id
						)
					);

					return 1 === $changed
						? new WP_REST_Response(array('status' => 'approved'), 200)
						: new WP_Error('phase27_state', 'Intent is not prepared.', array('status' => 409));
				},
			)
		);

		register_rest_route(
			'phase27/v1',
			'/evidence',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'phase27_permission',
				'callback'            => static function (): WP_REST_Response {
					return new WP_REST_Response(
						array(
							'sink_count'   => (int) get_option(PHASE27_SINK_OPTION, 0),
							'sink_digests' => array_values((array) get_option(PHASE27_SINK_DIGESTS_OPTION, array())),
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'phase27/v1',
			'/effect-upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'phase27_permission',
				'callback'            => static function (WP_REST_Request $request) {
					global $wpdb;

					$id     = (string) $request->get_param('intent');
					$intent = phase27_intent_for_request($id);
					$file   = $_FILES['package'] ?? null;
					if (is_wp_error($intent)) {
						return $intent;
					}
					if (
						! is_array($file) ||
						UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) ||
						! is_uploaded_file((string) ($file['tmp_name'] ?? ''))
					) {
						return new WP_Error('phase27_upload', 'One successful upload is required.', array('status' => 400));
					}

					$digest = hash_file('sha256', (string) $file['tmp_name']);
					if (! is_string($digest) || ! hash_equals((string) $intent->digest, $digest)) {
						return new WP_Error('phase27_digest', 'Uploaded bytes differ from approval.', array('status' => 409));
					}

					$changed = $wpdb->query(
						$wpdb->prepare(
							'UPDATE ' . phase27_table() . " SET state = 'consumed' WHERE id = %s AND state = 'approved'",
							$id
						)
					);
					if (phase27_mutation_enabled('ATOMIC_CONSUME')) {
						$changed = 1;
					}
					if (1 !== $changed) {
						return new WP_Error('phase27_consumed', 'Approval already consumed.', array('status' => 409));
					}

					$effect_digest = phase27_capture_upload_effect((string) $file['tmp_name']);
					if (is_wp_error($effect_digest)) {
						return $effect_digest;
					}

					if (! phase27_mutation_enabled('EFFECT_RECORDED')) {
						update_option(PHASE27_SINK_OPTION, (int) get_option(PHASE27_SINK_OPTION, 0) + 1, false);
						$digests   = (array) get_option(PHASE27_SINK_DIGESTS_OPTION, array());
						$digests[] = $effect_digest;
						update_option(PHASE27_SINK_DIGESTS_OPTION, $digests, false);
					}
					return new WP_REST_Response(array('digest' => $digest, 'effect_digest' => $effect_digest, 'status' => 'consumed'), 200);
				},
			)
		);
	}
);
