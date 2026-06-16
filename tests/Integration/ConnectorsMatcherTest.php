<?php
/**
 * Integration tests for the two-tier Connectors matcher (CONN-01, CONN-02).
 *
 * These tests assert behavior that requires the WP 7.0 GA Connectors API:
 * core must register an api_key connector whose setting_name does NOT match
 * the Tier-2 regex ^connectors_[a-z0-9_]+_api_key$, and WP_Connector_Registry
 * must honor an explicitly provided setting_name. Each test gates on the
 * precise precondition it needs and skips (does not fail) where the build
 * lacks it.
 *
 * Version skew note: WP 7.0-RC1 shipped wp_get_connectors() but did NOT
 * register the Akismet connector and force-normalized every api_key
 * setting_name to connectors_ai_{id}_api_key, so a bare
 * function_exists('wp_get_connectors') check is insufficient — it would let
 * these tests false-fail on RC1. The guards below therefore probe the actual
 * registry behavior, not just API presence.
 *
 * Verified core source (WordPress 7.0 GA, verified 2026-06-15 against
 * downloads.wordpress.org/release/wordpress-7.0.zip and a live wp-env 7.0 GA
 * container): wp-includes/connectors.php _wp_connectors_init() registers
 * 'akismet' (type 'spam_filtering') with method='api_key',
 * setting_name='wordpress_api_key'; wp-includes/class-wp-connector-registry.php
 * register() stores an explicitly provided setting_name verbatim and only
 * generates connectors_{type}_{id}_api_key when none is given.
 *
 * @covers \WP_Sudo\Action_Registry::is_connector_api_key_setting_name
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Action_Registry;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

/**
 * Tests for the registry-aware connector credential matcher.
 */
class ConnectorsMatcherTest extends TestCase {

	/**
	 * Gate instance for calling intercept_rest() / match_request().
	 *
	 * @var Gate
	 */
	private Gate $gate;

	public function set_up(): void {
		parent::set_up();
		$this->gate = new Gate( new Sudo_Session(), new Request_Stash() );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Whether core registers $setting_name as an api_key connector setting.
	 *
	 * Probed per-test (not in set_up_before_class) so the result is immune to
	 * any cross-class ordering that could mutate the registry singleton.
	 *
	 * @param string $setting_name The connector setting_name to look for.
	 * @return bool True if a registered api_key connector uses $setting_name.
	 */
	private function connector_api_key_setting_registered( string $setting_name ): bool {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return false;
		}

		foreach ( wp_get_connectors() as $connector ) {
			if (
				isset( $connector['authentication']['method'], $connector['authentication']['setting_name'] )
				&& 'api_key' === $connector['authentication']['method']
				&& $setting_name === $connector['authentication']['setting_name']
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Skip unless core registers Akismet's 'wordpress_api_key' connector.
	 *
	 * This is the exact fixture the CONN-01 tests assert against: a core api_key
	 * connector whose setting_name does NOT match the Tier-2 regex. Absent on
	 * WP 7.0-RC1 and earlier. Gating CONN-01 on this specific key (rather than a
	 * generic "some non-regex connector") keeps the skip honest if a future core
	 * build ever drops the Akismet connector.
	 */
	private function require_wordpress_api_key_connector(): void {
		if ( ! $this->connector_api_key_setting_registered( 'wordpress_api_key' ) ) {
			$this->markTestSkipped(
				'Core does not register an api_key connector with setting_name ' .
				"'wordpress_api_key' (Akismet) — requires WordPress 7.0 GA. Absent on " .
				'WP 7.0-RC1 and earlier; this test is expected to pass on the 7.0 GA lane.'
			);
		}
	}

	/**
	 * Skip unless WP_Connector_Registry::register() honors an explicit setting_name.
	 *
	 * CONN-02 registers a connector with its own non-regex setting_name and
	 * asserts it is gated. On WP 7.0-RC1 register() force-normalized every
	 * api_key setting_name to connectors_ai_{id}_api_key, so a custom name could
	 * never round-trip and the test would false-fail. Probe with a throwaway
	 * connector (registered and immediately unregistered) to detect the GA
	 * behavior directly, independent of the Akismet connector.
	 */
	private function require_registry_honors_setting_name(): void {
		if ( ! class_exists( '\WP_Connector_Registry' ) || ! function_exists( 'wp_get_connectors' ) ) {
			$this->markTestSkipped( 'WP_Connector_Registry is unavailable — requires WordPress 7.0+.' );
		}

		$registry = \WP_Connector_Registry::get_instance();
		if ( null === $registry ) {
			$this->markTestSkipped( 'WP_Connector_Registry::get_instance() returned null — requires WordPress 7.0+.' );
		}

		$probe_id      = 'wp-sudo-probe-' . wp_generate_uuid4();
		$probe_setting = 'wp_sudo_probe_explicit_key';
		$registered    = $registry->register(
			$probe_id,
			array(
				'name'           => 'WP Sudo Probe',
				'type'           => 'wp_sudo_probe',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => $probe_setting,
				),
			)
		);

		$honored = is_array( $registered )
			&& ( $registered['authentication']['setting_name'] ?? null ) === $probe_setting;

		if ( is_array( $registered ) ) {
			$registry->unregister( $probe_id );
			Action_Registry::reset_cache();
		}

		if ( ! $honored ) {
			$this->markTestSkipped(
				'WP_Connector_Registry::register() does not honor an explicit setting_name ' .
				'(force-normalized on WP 7.0-RC1) — this test is expected to pass on the 7.0 GA lane.'
			);
		}
	}

	// ── CONN-01 — Akismet wordpress_api_key is gated via registry tier ────

	/**
	 * CONN-01: POST /wp/v2/settings writing wordpress_api_key (Akismet's
	 * api_key setting_name on WP 7.0 GA) is gated as
	 * connectors.update_credentials.
	 *
	 * This key does NOT match the regex ^connectors_[a-z0-9_]+_api_key$ and is a
	 * live false-negative without the registry tier — the registry tier must
	 * catch it. See the file docblock for the verified 7.0 GA core source.
	 */
	public function test_conn01_wordpress_api_key_gated_via_registry_tier(): void {
		$this->require_wordpress_api_key_connector();

		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Reset the connector cache so we get a fresh registry read.
		Action_Registry::reset_cache();

		$request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'wordpress_api_key' => 'abc123' ) );

		$result = $this->gate->intercept_rest( null, array(), $request );

		$this->assertWPError( $result, 'wordpress_api_key write must be gated.' );
		$this->assertSame(
			'sudo_required',
			$result->get_error_code(),
			'Expected sudo_required error code for gated connector credential write.'
		);
		$this->assertSame(
			403,
			$result->get_error_data()['status'] ?? null,
			'Expected 403 status.'
		);

		Action_Registry::reset_cache();
	}

	/**
	 * CONN-01 alt: match_request() returns the connectors.update_credentials
	 * rule for wordpress_api_key, not null.
	 */
	public function test_conn01_match_request_identifies_connectors_update_credentials(): void {
		$this->require_wordpress_api_key_connector();

		Action_Registry::reset_cache();

		$request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( 'wordpress_api_key' => 'abc123' ) );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull( $rule, 'wordpress_api_key must match a rule via the registry tier.' );
		$this->assertSame(
			'connectors.update_credentials',
			$rule['id'],
			'Rule ID must be connectors.update_credentials.'
		);

		Action_Registry::reset_cache();
	}

	// ── CONN-02 — Custom connector with api_key setting_name is auto-gated ─

	/**
	 * CONN-02: A custom connector registered with method=api_key and a
	 * non-regex setting_name is auto-gated via the registry tier.
	 *
	 * Registers a connector directly on the live WP_Connector_Registry
	 * instance (the registry is populated at init@15 and remains accessible
	 * during tests). Uses a unique ID to avoid collisions.
	 *
	 * Verified (WP 7.0 GA, see file docblock): WP_Connector_Registry::register()
	 * stores connectors in the singleton and honors an explicit setting_name;
	 * wp_get_connectors() reads from it.
	 */
	public function test_conn02_custom_api_key_connector_is_auto_gated(): void {
		$this->require_registry_honors_setting_name();

		// Use a unique ID to avoid collision with any existing connector.
		$connector_id = 'wp-sudo-test-' . wp_generate_uuid4();
		$setting_name = 'my_custom_plugin_secret_key';

		// Register directly on the live registry instance.
		$registry = \WP_Connector_Registry::get_instance();
		$this->assertNotNull( $registry, 'WP_Connector_Registry::get_instance() must return a registry on WP 7.0.' );

		$registered = $registry->register(
			$connector_id,
			array(
				'name'           => 'WP Sudo Test Connector',
				'description'    => 'Ephemeral connector for CONN-02 integration test.',
				'type'           => 'custom_test',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => $setting_name,
				),
			)
		);
		$this->assertNotNull( $registered, "Registering connector '{$connector_id}' must succeed." );

		// Reset the matcher cache so it re-reads the now-updated registry.
		Action_Registry::reset_cache();

		$request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( $setting_name => 'secret-value' ) );

		$rule = $this->gate->match_request( 'rest', $request );

		$this->assertNotNull(
			$rule,
			"Custom connector setting '{$setting_name}' must be gated via the registry tier."
		);
		$this->assertSame(
			'connectors.update_credentials',
			$rule['id'],
			'Custom connector credential must match the connectors.update_credentials rule.'
		);

		// Verify the registry-tier read also prevents the benign form from matching.
		$benign_request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$benign_request->set_body_params( array( 'blogname' => 'My Site' ) );

		$benign_rule = $this->gate->match_request( 'rest', $benign_request );
		if ( null !== $benign_rule ) {
			$this->assertNotSame(
				'connectors.update_credentials',
				$benign_rule['id'],
				"Benign setting 'blogname' must not match connectors.update_credentials."
			);
		} else {
			$this->assertNull( $benign_rule );
		}

		// Unregister to avoid polluting subsequent tests.
		$registry->unregister( $connector_id );
		Action_Registry::reset_cache();
	}
}
