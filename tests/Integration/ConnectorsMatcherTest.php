<?php
/**
 * Integration tests for the two-tier Connectors matcher (CONN-01, CONN-02).
 *
 * Requires a WP 7.0+ environment where wp_get_connectors() exists and the
 * Connectors registry is populated at init@15. Tests skip cleanly on older
 * WordPress lanes where the Connectors API is absent.
 *
 * Verified core source: wp-includes/connectors.php (_wp_connectors_init,
 * hooked at init@15 via default-filters.php) registers 'akismet' with
 * method='api_key', setting_name='wordpress_api_key' unconditionally.
 * Source: WordPress/wordpress-develop trunk, verified 2026-06-15.
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

	/**
	 * Whether the WP 7.0 Connectors API is available in this environment.
	 *
	 * @var bool
	 */
	private static bool $has_connectors_api;

	/**
	 * One-time check for the Connectors API availability.
	 *
	 * Called before the first test in the class. Sets the skip flag used by
	 * all tests that require the WP 7.0 Connectors registry.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$has_connectors_api = function_exists( 'wp_get_connectors' );
	}

	public function set_up(): void {
		parent::set_up();
		$this->gate = new Gate( new Sudo_Session(), new Request_Stash() );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Skip this test if the WP 7.0 Connectors API is absent.
	 *
	 * Call at the start of any test that requires wp_get_connectors().
	 */
	private function require_connectors_api(): void {
		if ( ! self::$has_connectors_api ) {
			$this->markTestSkipped(
				'wp_get_connectors() is not available — requires WordPress 7.0+. ' .
				'This test is expected to pass on the WP 7.0 integration lane.'
			);
		}
	}

	// ── CONN-01 — Akismet wordpress_api_key is gated via registry tier ────

	/**
	 * CONN-01: POST /wp/v2/settings writing wordpress_api_key (Akismet's
	 * api_key setting_name, registered unconditionally in core) is gated as
	 * connectors.update_credentials.
	 *
	 * This key does NOT match the regex ^connectors_[a-z0-9_]+_api_key$ and
	 * was a live false-negative on WP 7.0 before this fix. The registry tier
	 * must catch it.
	 *
	 * Verified: wp-includes/connectors.php _wp_connectors_init() registers
	 * 'akismet' with method='api_key', setting_name='wordpress_api_key'.
	 * Source: WordPress/wordpress-develop trunk, 2026-06-15.
	 */
	public function test_conn01_wordpress_api_key_gated_via_registry_tier(): void {
		$this->require_connectors_api();

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
		$this->require_connectors_api();

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
	 * Verified: WP_Connector_Registry::register() stores connectors in the
	 * singleton; wp_get_connectors() reads from it.
	 * Source: WordPress/wordpress-develop trunk class-wp-connector-registry.php, 2026-06-15.
	 */
	public function test_conn02_custom_api_key_connector_is_auto_gated(): void {
		$this->require_connectors_api();

		// Use a unique ID to avoid collision with any existing connector.
		$connector_id   = 'wp-sudo-test-' . wp_generate_uuid4();
		$setting_name   = 'my_custom_plugin_secret_key';

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
			"Custom connector credential must match the connectors.update_credentials rule."
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
