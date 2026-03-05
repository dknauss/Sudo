<?php
/**
 * Integration tests for the wp_sudo_gated_actions filter.
 *
 * Verifies that custom rules added via the filter are respected by
 * Gate::match_request() in a real WordPress environment — confirming
 * the filter is applied before the cache is built and is read on every
 * subsequent call until Action_Registry::reset_cache() is called.
 *
 * @covers \WP_Sudo\Action_Registry::get_rules
 * @covers \WP_Sudo\Gate::match_request
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Action_Registry;
use WP_Sudo\Gate;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;

class ActionRegistryTest extends TestCase {

	/**
	 * Gate instance under test.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Filter callback registered during the current test (for removal in tear_down).
	 *
	 * @var callable|null
	 */
	private $filter_callback = null;

	public function set_up(): void {
		parent::set_up();

		$this->gate = new Gate( new Sudo_Session(), new Request_Stash() );
	}

	public function tear_down(): void {
		if ( null !== $this->filter_callback ) {
			remove_filter( 'wp_sudo_gated_actions', $this->filter_callback );
			$this->filter_callback = null;
		}

		Action_Registry::reset_cache();

		parent::tear_down();
	}

	// ─────────────────────────────────────────────────────────────────────
	// Filter: custom admin rule
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A custom admin rule added via wp_sudo_gated_actions is matched by
	 * match_request('admin') against a simulated admin request.
	 */
	public function test_custom_admin_rule_added_via_filter_is_matched(): void {
		$this->filter_callback = static function ( array $rules ): array {
			$rules[] = array(
				'id'       => 'custom.admin-action',
				'label'    => 'Custom Admin Action',
				'category' => 'custom',
				'admin'    => array(
					'pagenow' => 'custom-page.php',
					'actions' => array( 'custom-action' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => null,
			);
			return $rules;
		};

		add_filter( 'wp_sudo_gated_actions', $this->filter_callback );
		Action_Registry::reset_cache();

		// Simulate a matching admin request.
		$this->simulate_admin_request( 'custom-page.php', 'custom-action', 'POST' );

		$matched = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $matched, 'Custom admin rule should be matched.' );
		$this->assertSame( 'custom.admin-action', $matched['id'] ?? null, 'Matched rule ID should be custom.admin-action.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Filter: custom AJAX rule
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A custom AJAX rule added via wp_sudo_gated_actions is matched by
	 * match_request('ajax') against a simulated AJAX request.
	 */
	public function test_custom_ajax_rule_added_via_filter_is_matched(): void {
		$this->filter_callback = static function ( array $rules ): array {
			$rules[] = array(
				'id'       => 'custom.ajax-action',
				'label'    => 'Custom AJAX Action',
				'category' => 'custom',
				'admin'    => null,
				'ajax'     => array(
					'actions' => array( 'my_custom_ajax_handler' ),
				),
				'rest'     => null,
			);
			return $rules;
		};

		add_filter( 'wp_sudo_gated_actions', $this->filter_callback );
		Action_Registry::reset_cache();

		// Simulate an AJAX request with the custom action.
		$_REQUEST['action'] = 'my_custom_ajax_handler';

		$matched = $this->gate->match_request( 'ajax' );

		$this->assertNotNull( $matched, 'Custom AJAX rule should be matched.' );
		$this->assertSame( 'custom.ajax-action', $matched['id'] ?? null, 'Matched rule ID should be custom.ajax-action.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Filter: remove built-in rule
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * The wp_sudo_gated_actions filter can remove a built-in rule so that
	 * match_request() no longer matches that request.
	 */
	public function test_filter_can_remove_builtin_rule(): void {
		$this->filter_callback = static function ( array $rules ): array {
			return array_values(
				array_filter(
					$rules,
					static function ( array $rule ): bool {
						return ( $rule['id'] ?? '' ) !== 'plugin.activate';
					}
				)
			);
		};

		add_filter( 'wp_sudo_gated_actions', $this->filter_callback );
		Action_Registry::reset_cache();

		// Simulate a request that would normally match plugin.activate.
		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$matched = $this->gate->match_request( 'admin' );

		$this->assertNull( $matched, 'plugin.activate rule should be absent after filter removes it.' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Malformed custom rules: fail-closed behavior
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Mixed valid/invalid custom rules preserve the valid custom match.
	 */
	public function test_mixed_valid_and_invalid_custom_rules_preserve_valid_match(): void {
		$this->filter_callback = static function ( array $rules ): array {
			$rules[] = array(
				'id'       => 'custom.invalid-surface',
				'label'    => 'Invalid surface',
				'category' => 'custom',
				'admin'    => 'plugins.php',
			);

			$rules[] = array(
				'id'       => 'custom.valid-admin',
				'label'    => 'Valid admin custom rule',
				'category' => 'custom',
				'admin'    => array(
					'pagenow' => 'custom-safe.php',
					'actions' => array( 'safe-action' ),
					'method'  => 'POST',
				),
				'ajax'     => null,
				'rest'     => null,
			);

			return $rules;
		};

		add_filter( 'wp_sudo_gated_actions', $this->filter_callback );
		Action_Registry::reset_cache();

		$this->simulate_admin_request( 'custom-safe.php', 'safe-action', 'POST' );

		$matched = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $matched, 'Valid custom rule should still match when malformed rules are present.' );
		$this->assertSame( 'custom.valid-admin', $matched['id'] ?? null );
	}

	/**
	 * Malformed custom surface shapes do not break built-in rule matching.
	 */
	public function test_malformed_custom_surface_shapes_do_not_break_builtin_matching(): void {
		$this->filter_callback = static function ( array $rules ): array {
			return array_merge(
				array(
					array(
						'id'       => 'custom.bad-admin',
						'label'    => 'Bad admin surface',
						'category' => 'custom',
						'admin'    => 'not-an-array',
					),
					array(
						'id'       => 'custom.bad-ajax',
						'label'    => 'Bad ajax surface',
						'category' => 'custom',
						'ajax'     => 123,
					),
					array(
						'id'       => 'custom.bad-rest',
						'label'    => 'Bad rest surface',
						'category' => 'custom',
						'rest'     => '#^/wp/v2/plugins$#',
					),
				),
				$rules
			);
		};

		add_filter( 'wp_sudo_gated_actions', $this->filter_callback );
		Action_Registry::reset_cache();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$matched = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $matched, 'Built-in plugin.activate should still match with malformed custom rules present.' );
		$this->assertSame( 'plugin.activate', $matched['id'] ?? null );
	}

	/**
	 * Non-array filter payloads safely fall back to built-in rules.
	 */
	public function test_non_array_filter_payload_falls_back_to_builtin_rules(): void {
		$this->filter_callback = static function () {
			return 'invalid-payload';
		};

		add_filter( 'wp_sudo_gated_actions', $this->filter_callback );
		Action_Registry::reset_cache();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$matched = $this->gate->match_request( 'admin' );

		$this->assertNotNull( $matched, 'Built-in rules should remain available when filter payload is non-array.' );
		$this->assertSame( 'plugin.activate', $matched['id'] ?? null );
	}
}
