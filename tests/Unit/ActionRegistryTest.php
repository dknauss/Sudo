<?php
/**
 * Tests for Action_Registry.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Action_Registry;
use WP_Sudo\Tests\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

/**
 * @covers \WP_Sudo\Action_Registry
 */
class ActionRegistryTest extends TestCase {

	/**
	 * Test that rules() returns a non-empty array.
	 */
	public function test_rules_returns_array(): void {
		Functions\when( '__' )->returnArg();

		$rules = Action_Registry::rules();

		$this->assertIsArray( $rules );
		$this->assertNotEmpty( $rules );
	}

	/**
	 * Test every rule has required keys.
	 */
	public function test_every_rule_has_required_keys(): void {
		Functions\when( '__' )->returnArg();

		$required_keys = array( 'id', 'label', 'category' );

		foreach ( Action_Registry::rules() as $index => $rule ) {
			foreach ( $required_keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$rule,
					sprintf( 'Rule at index %d is missing key "%s".', $index, $key )
				);
			}
		}
	}

	/**
	 * Test every rule ID is unique.
	 */
	public function test_rule_ids_are_unique(): void {
		Functions\when( '__' )->returnArg();

		$ids = array_column( Action_Registry::rules(), 'id' );

		$this->assertCount(
			count( $ids ),
			array_unique( $ids ),
			'Duplicate rule IDs detected.'
		);
	}

	/**
	 * Test every rule has at least one surface defined (admin, ajax, or rest).
	 */
	public function test_every_rule_has_at_least_one_surface(): void {
		Functions\when( '__' )->returnArg();

		foreach ( Action_Registry::rules() as $rule ) {
			$has_surface = ! empty( $rule['admin'] )
				|| ! empty( $rule['ajax'] )
				|| ! empty( $rule['rest'] );

			$this->assertTrue(
				$has_surface,
				sprintf( 'Rule "%s" has no surface (admin, ajax, or rest) defined.', $rule['id'] )
			);
		}
	}

	/**
	 * Test get_rules() applies the wp_sudo_gated_actions filter.
	 */
	public function test_get_rules_applies_filter(): void {
		Functions\when( '__' )->returnArg();

		$custom_rule = array(
			'id'       => 'custom.test',
			'label'    => 'Test action',
			'category' => 'custom',
			'admin'    => array(
				'pagenow' => 'test.php',
				'actions' => array( 'test' ),
				'method'  => 'GET',
			),
		);

		Filters\expectApplied( 'wp_sudo_gated_actions' )
			->once()
			->andReturnUsing(
				function ( $rules ) use ( $custom_rule ) {
					$rules[] = $custom_rule;
					return $rules;
				}
			);

		$rules = Action_Registry::get_rules();
		$ids   = array_column( $rules, 'id' );

		$this->assertContains( 'custom.test', $ids );
	}

	/**
	 * Test get_rules() falls back to built-in rules on non-array filter output.
	 */
	public function test_get_rules_falls_back_to_builtin_rules_for_non_array_filter_output(): void {
		Functions\when( '__' )->returnArg();
		$builtin_rules = Action_Registry::rules();

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wp_sudo_gated_actions' !== $hook ) {
					return $value;
				}

				return 'not-an-array';
			}
		);

		$rules = Action_Registry::get_rules();

		$this->assertSame( count( $builtin_rules ), count( $rules ) );
		$this->assertContains( 'plugin.activate', array_column( $rules, 'id' ) );
	}

	/**
	 * Test get_rules() drops filtered rules missing required metadata.
	 */
	public function test_get_rules_drops_filtered_rules_missing_required_metadata(): void {
		Functions\when( '__' )->returnArg();
		$builtin_count = count( Action_Registry::rules() );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wp_sudo_gated_actions' !== $hook ) {
					return $value;
				}

				$value[] = array(
					'label'    => 'Missing ID',
					'category' => 'custom',
					'admin'    => array(
						'pagenow' => 'plugins.php',
						'actions' => array( 'activate' ),
						'method'  => 'GET',
					),
				);

				$value[] = array(
					'id'       => 'custom.invalid_label',
					'label'    => array( 'not', 'scalar' ),
					'category' => 'custom',
					'admin'    => array(
						'pagenow' => 'plugins.php',
						'actions' => array( 'activate' ),
						'method'  => 'GET',
					),
				);

				$value[] = array(
					'id'       => 'custom.invalid_category',
					'label'    => 'Invalid category type',
					'category' => array( 'not', 'scalar' ),
					'admin'    => array(
						'pagenow' => 'plugins.php',
						'actions' => array( 'activate' ),
						'method'  => 'GET',
					),
				);

				return $value;
			}
		);

		$rules = Action_Registry::get_rules();
		$ids   = array_column( $rules, 'id' );

		$this->assertSame( $builtin_count, count( $rules ) );
		$this->assertNotContains( 'custom.invalid_label', $ids );
		$this->assertNotContains( 'custom.invalid_category', $ids );
	}

	/**
	 * Test get_rules() drops filtered rules with invalid surface shapes.
	 */
	public function test_get_rules_drops_filtered_rules_with_invalid_surface_shapes(): void {
		Functions\when( '__' )->returnArg();
		$builtin_count = count( Action_Registry::rules() );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wp_sudo_gated_actions' !== $hook ) {
					return $value;
				}

				$value[] = array(
					'id'       => 'custom.bad_admin_surface',
					'label'    => 'Bad admin surface',
					'category' => 'custom',
					'admin'    => 'plugins.php',
				);

				$value[] = array(
					'id'       => 'custom.bad_ajax_surface',
					'label'    => 'Bad ajax surface',
					'category' => 'custom',
					'ajax'     => 123,
				);

				$value[] = array(
					'id'       => 'custom.bad_rest_surface',
					'label'    => 'Bad rest surface',
					'category' => 'custom',
					'rest'     => '#^/wp/v2/plugins$#',
				);

				return $value;
			}
		);

		$rules = Action_Registry::get_rules();
		$ids   = array_column( $rules, 'id' );

		$this->assertSame( $builtin_count, count( $rules ) );
		$this->assertNotContains( 'custom.bad_admin_surface', $ids );
		$this->assertNotContains( 'custom.bad_ajax_surface', $ids );
		$this->assertNotContains( 'custom.bad_rest_surface', $ids );
	}

	/**
	 * Test get_rules() keeps valid custom rules while dropping invalid entries.
	 */
	public function test_get_rules_preserves_valid_custom_rules_in_mixed_filtered_set(): void {
		Functions\when( '__' )->returnArg();
		$builtin_count = count( Action_Registry::rules() );

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wp_sudo_gated_actions' !== $hook ) {
					return $value;
				}

				$value[] = array(
					'id'       => 'custom.invalid_missing_label',
					'category' => 'custom',
					'admin'    => array(
						'pagenow' => 'plugins.php',
						'actions' => array( 'activate' ),
						'method'  => 'GET',
					),
				);

				$value[] = array(
					'id'       => 'custom.valid',
					'label'    => 'Valid custom action',
					'category' => 'custom',
					'admin'    => array(
						'pagenow' => 'plugins.php',
						'actions' => array( 'activate' ),
						'method'  => 'GET',
					),
				);

				return $value;
			}
		);

		$rules = Action_Registry::get_rules();
		$ids   = array_column( $rules, 'id' );

		$this->assertSame( $builtin_count + 1, count( $rules ) );
		$this->assertContains( 'custom.valid', $ids );
		$this->assertNotContains( 'custom.invalid_missing_label', $ids );
	}

	/**
	 * Test get_categories() returns unique categories.
	 */
	public function test_get_categories_returns_unique_list(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$categories = Action_Registry::get_categories();

		$this->assertIsArray( $categories );
		$this->assertContains( 'plugins', $categories );
		$this->assertContains( 'themes', $categories );
		$this->assertContains( 'users', $categories );
		$this->assertContains( 'editors', $categories );
		$this->assertContains( 'options', $categories );
		$this->assertContains( 'updates', $categories );
		$this->assertContains( 'tools', $categories );
		$this->assertCount( count( $categories ), array_unique( $categories ) );
	}

	/**
	 * Test get_rules_by_category() filters correctly.
	 */
	public function test_get_rules_by_category_filters_correctly(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$plugin_rules = Action_Registry::get_rules_by_category( 'plugins' );

		$this->assertNotEmpty( $plugin_rules );

		foreach ( $plugin_rules as $rule ) {
			$this->assertSame( 'plugins', $rule['category'] );
		}
	}

	/**
	 * Test find() returns a rule by ID.
	 */
	public function test_find_returns_rule_by_id(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'plugin.activate' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'plugin.activate', $rule['id'] );
	}

	/**
	 * Test find() returns null for unknown ID.
	 */
	public function test_find_returns_null_for_unknown_id(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertNull( Action_Registry::find( 'nonexistent.rule' ) );
	}

	/**
	 * Test critical_option_names() returns expected options.
	 */
	public function test_critical_option_names(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$options = Action_Registry::critical_option_names();

		$this->assertContains( 'siteurl', $options );
		$this->assertContains( 'home', $options );
		$this->assertContains( 'admin_email', $options );
		$this->assertContains( 'default_role', $options );
		$this->assertContains( 'users_can_register', $options );
	}

	// -----------------------------------------------------------------
	// User promote callback (user.promote)
	// -----------------------------------------------------------------

	/**
	 * Test user.promote callback matches changeit + new_role flow.
	 */
	public function test_user_promote_callback_matches_changeit_flow(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$rule = Action_Registry::find( 'user.promote' );

		$this->assertNotNull( $rule );
		$this->assertNotNull( $rule['admin']['callback'] );

		$_REQUEST['action']   = '-1';
		$_REQUEST['changeit'] = 'Change';
		$_REQUEST['new_role'] = 'editor';

		$this->assertTrue( call_user_func( $rule['admin']['callback'] ) );

		unset( $_REQUEST['action'], $_REQUEST['changeit'], $_REQUEST['new_role'] );
	}

	/**
	 * Test user.promote callback matches direct promote action.
	 */
	public function test_user_promote_callback_matches_direct_promote(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$rule = Action_Registry::find( 'user.promote' );

		$_REQUEST['action'] = 'promote';

		$this->assertTrue( call_user_func( $rule['admin']['callback'] ) );

		unset( $_REQUEST['action'] );
	}

	/**
	 * Test user.promote callback rejects bare -1 action.
	 */
	public function test_user_promote_callback_rejects_bare_negative_one(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$rule = Action_Registry::find( 'user.promote' );

		$_REQUEST['action'] = '-1';

		$this->assertFalse( call_user_func( $rule['admin']['callback'] ) );

		unset( $_REQUEST['action'] );
	}

	// -----------------------------------------------------------------
	// User promote profile callback (user.promote_profile)
	// -----------------------------------------------------------------

	/**
	 * Test user.promote_profile rule exists and targets user-edit.php.
	 */
	public function test_user_promote_profile_rule_exists(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'user.promote_profile' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'users', $rule['category'] );
		$this->assertSame( 'user-edit.php', $rule['admin']['pagenow'] );
		$this->assertSame( 'POST', $rule['admin']['method'] );
	}

	/**
	 * Test user.promote_profile callback matches when role is posted.
	 */
	public function test_user_promote_profile_callback_matches_with_role(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'user.promote_profile' );

		$this->assertNotNull( $rule );
		$this->assertNotNull( $rule['admin']['callback'] );

		$_POST['role'] = 'editor';

		$this->assertTrue( call_user_func( $rule['admin']['callback'] ) );

		unset( $_POST['role'] );
	}

	/**
	 * Test user.promote_profile callback rejects when no role is posted.
	 */
	public function test_user_promote_profile_callback_rejects_without_role(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'user.promote_profile' );

		$this->assertNotNull( $rule );

		// No $_POST['role'] set.
		$this->assertFalse( call_user_func( $rule['admin']['callback'] ) );
	}

	/**
	 * Test user.promote_profile callback rejects when role is empty string.
	 */
	public function test_user_promote_profile_callback_rejects_empty_role(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'user.promote_profile' );

		$this->assertNotNull( $rule );

		$_POST['role'] = '';
		$this->assertFalse( call_user_func( $rule['admin']['callback'] ) );
		unset( $_POST['role'] );
	}

	// -----------------------------------------------------------------
	// Self-protection rule (options.wp_sudo)
	// -----------------------------------------------------------------

	/**
	 * Test that a rule exists to gate WP Sudo's own settings.
	 */
	public function test_wp_sudo_settings_rule_exists(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'options.wp_sudo' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'options', $rule['category'] );
	}

	/**
	 * Test that the self-protection callback matches the WP Sudo settings page.
	 */
	public function test_wp_sudo_settings_callback_matches_settings_page(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'options.wp_sudo' );

		$this->assertNotNull( $rule );
		$this->assertNotNull( $rule['admin']['callback'] );

		$_POST['option_page'] = 'wp-sudo-settings';
		$this->assertTrue( call_user_func( $rule['admin']['callback'] ) );
		unset( $_POST['option_page'] );
	}

	/**
	 * Test that the self-protection callback rejects other option pages.
	 */
	public function test_wp_sudo_settings_callback_rejects_other_pages(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'options.wp_sudo' );

		$this->assertNotNull( $rule );

		$_POST['option_page'] = 'general';
		$this->assertFalse( call_user_func( $rule['admin']['callback'] ) );
		unset( $_POST['option_page'] );
	}

	// -----------------------------------------------------------------
	// User create rule (user.create)
	// -----------------------------------------------------------------

	/**
	 * Test user.create rule exists and targets user-new.php.
	 */
	public function test_user_create_rule_exists(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'user.create' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'users', $rule['category'] );
		$this->assertSame( 'user-new.php', $rule['admin']['pagenow'] );
		$this->assertSame( 'POST', $rule['admin']['method'] );
	}

	/**
	 * Test user.create has no admin callback (gates all creation unconditionally).
	 */
	public function test_user_create_has_no_admin_callback(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'user.create' );

		$this->assertNotNull( $rule );
		$this->assertArrayNotHasKey( 'callback', $rule['admin'] );
	}

	/**
	 * Test user.create has no REST callback (gates all creation unconditionally).
	 */
	public function test_user_create_has_no_rest_callback(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'user.create' );

		$this->assertNotNull( $rule );
		$this->assertArrayNotHasKey( 'callback', $rule['rest'] );
	}

	// -----------------------------------------------------------------
	// Application password rule (auth.app_password)
	// -----------------------------------------------------------------

	/**
	 * Test auth.app_password rule exists and belongs to users category.
	 */
	public function test_app_password_rule_exists(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'auth.app_password' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'users', $rule['category'] );
		$this->assertSame( 'authorize-application.php', $rule['admin']['pagenow'] );
	}

	/**
	 * Test auth.app_password callback matches when approve is posted.
	 */
	public function test_app_password_callback_matches_approve(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'auth.app_password' );

		$this->assertNotNull( $rule );
		$this->assertNotNull( $rule['admin']['callback'] );

		$_POST['approve'] = '1';
		$this->assertTrue( call_user_func( $rule['admin']['callback'] ) );
		unset( $_POST['approve'] );
	}

	/**
	 * Test auth.app_password callback rejects when approve is not posted.
	 */
	public function test_app_password_callback_rejects_without_approve(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'auth.app_password' );

		$this->assertNotNull( $rule );

		// No $_POST['approve'] — user is rejecting the request.
		$this->assertFalse( call_user_func( $rule['admin']['callback'] ) );
	}

	// -----------------------------------------------------------------
	// Core update rule (core.update)
	// -----------------------------------------------------------------

	/**
	 * Test core.update rule exists and belongs to updates category.
	 */
	public function test_core_update_rule_exists(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'core.update' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'updates', $rule['category'] );
		$this->assertSame( 'update-core.php', $rule['admin']['pagenow'] );
		$this->assertSame( 'POST', $rule['admin']['method'] );
	}

	// -----------------------------------------------------------------
	// Export rule (tools.export)
	// -----------------------------------------------------------------

	/**
	 * Test tools.export rule exists and belongs to tools category.
	 */
	public function test_export_rule_exists(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'tools.export' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'tools', $rule['category'] );
		$this->assertSame( 'export.php', $rule['admin']['pagenow'] );
		$this->assertSame( 'GET', $rule['admin']['method'] );
	}

	/**
	 * Test tools.export callback matches when download param is present.
	 */
	public function test_export_callback_matches_with_download(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'tools.export' );

		$this->assertNotNull( $rule );
		$this->assertNotNull( $rule['admin']['callback'] );

		$_GET['download'] = 'true';
		$this->assertTrue( call_user_func( $rule['admin']['callback'] ) );
		unset( $_GET['download'] );
	}

	/**
	 * Test tools.export callback rejects when download param is absent.
	 */
	public function test_export_callback_rejects_without_download(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rule = Action_Registry::find( 'tools.export' );

		$this->assertNotNull( $rule );

		// No $_GET['download'] — just viewing the export settings page.
		$this->assertFalse( call_user_func( $rule['admin']['callback'] ) );
	}

	// -----------------------------------------------------------------
	// Multisite: network rules
	// -----------------------------------------------------------------

	/**
	 * Test network rules are registered on multisite.
	 */
	public function test_network_rules_registered_on_multisite(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );

		$rule = Action_Registry::find( 'network.theme_enable' );
		$this->assertNotNull( $rule );

		$rule = Action_Registry::find( 'network.site_delete' );
		$this->assertNotNull( $rule );

		$rule = Action_Registry::find( 'network.super_admin' );
		$this->assertNotNull( $rule );

		$rule = Action_Registry::find( 'network.settings' );
		$this->assertNotNull( $rule );
	}

	/**
	 * Test network rules are not registered on single-site.
	 */
	public function test_network_rules_not_registered_on_single_site(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );

		$this->assertNull( Action_Registry::find( 'network.theme_enable' ) );
		$this->assertNull( Action_Registry::find( 'network.site_delete' ) );
		$this->assertNull( Action_Registry::find( 'network.super_admin' ) );
		$this->assertNull( Action_Registry::find( 'network.settings' ) );
	}

	/**
	 * Test network theme enable callback requires network admin context.
	 */
	public function test_network_theme_enable_callback_requires_network_admin(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_network_admin' )->justReturn( false );

		$rule = Action_Registry::find( 'network.theme_enable' );
		$this->assertNotNull( $rule );
		$this->assertNotNull( $rule['admin']['callback'] );

		// Should return false when not in network admin.
		$this->assertFalse( call_user_func( $rule['admin']['callback'] ) );
	}

	/**
	 * Test categories include 'sites' on multisite.
	 */
	public function test_categories_include_sites_on_multisite(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );

		$categories = Action_Registry::get_categories();
		$this->assertContains( 'sites', $categories );
	}

	/**
	 * Test network super admin callback checks POST param.
	 */
	public function test_network_super_admin_callback_checks_post_param(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_network_admin' )->justReturn( true );

		$rule = Action_Registry::find( 'network.super_admin' );
		$this->assertNotNull( $rule );

		$_POST['super_admin'] = '1';
		$this->assertTrue( call_user_func( $rule['admin']['callback'] ) );
		unset( $_POST['super_admin'] );
	}

	// -----------------------------------------------------------------
	// Upload rules
	// -----------------------------------------------------------------

	/**
	 * Find a rule by ID from the registry.
	 *
	 * @param string $id Rule ID.
	 * @return array<string, mixed>|null
	 */
	private function find_rule( string $id ): ?array {
		foreach ( Action_Registry::rules() as $rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Test that the plugin.upload rule exists in the registry.
	 */
	public function test_plugin_upload_rule_exists(): void {
		Functions\when( '__' )->returnArg();

		$rules = Action_Registry::rules();
		$ids   = array_column( $rules, 'id' );

		$this->assertContains( 'plugin.upload', $ids, 'plugin.upload rule must exist in Action_Registry' );
	}

	/**
	 * Test that plugin.upload targets update.php with action=upload-plugin via POST.
	 */
	public function test_plugin_upload_rule_matches_update_php_post(): void {
		Functions\when( '__' )->returnArg();

		$rule = $this->find_rule( 'plugin.upload' );

		$this->assertNotNull( $rule, 'plugin.upload rule not found' );
		$this->assertSame( 'update.php', $rule['admin']['pagenow'] ?? null );
		$this->assertContains( 'upload-plugin', $rule['admin']['actions'] ?? array() );
		$this->assertSame( 'POST', $rule['admin']['method'] ?? null, 'Upload must be POST-only, not ANY' );
	}

	/**
	 * Test that plugin.upload has no AJAX surface (file uploads are not AJAX in WP core).
	 */
	public function test_plugin_upload_rule_has_no_ajax_surface(): void {
		Functions\when( '__' )->returnArg();

		$rule = $this->find_rule( 'plugin.upload' );

		$this->assertNotNull( $rule, 'plugin.upload rule not found' );
		$this->assertNull( $rule['ajax'], 'plugin.upload must have null ajax surface' );
	}

	/**
	 * Test that plugin.upload has no REST surface (handled by existing plugin.install REST rule).
	 */
	public function test_plugin_upload_rule_has_no_rest_surface(): void {
		Functions\when( '__' )->returnArg();

		$rule = $this->find_rule( 'plugin.upload' );

		$this->assertNotNull( $rule, 'plugin.upload rule not found' );
		$this->assertNull( $rule['rest'], 'plugin.upload must have null rest surface' );
	}

	/**
	 * Test that the theme.upload rule exists in the registry.
	 */
	public function test_theme_upload_rule_exists(): void {
		Functions\when( '__' )->returnArg();

		$rules = Action_Registry::rules();
		$ids   = array_column( $rules, 'id' );

		$this->assertContains( 'theme.upload', $ids, 'theme.upload rule must exist in Action_Registry' );
	}

	/**
	 * Test that theme.upload targets update.php with action=upload-theme via POST.
	 */
	public function test_theme_upload_rule_matches_update_php_post(): void {
		Functions\when( '__' )->returnArg();

		$rule = $this->find_rule( 'theme.upload' );

		$this->assertNotNull( $rule, 'theme.upload rule not found' );
		$this->assertSame( 'update.php', $rule['admin']['pagenow'] ?? null );
		$this->assertContains( 'upload-theme', $rule['admin']['actions'] ?? array() );
		$this->assertSame( 'POST', $rule['admin']['method'] ?? null, 'Upload must be POST-only, not ANY' );
	}

	/**
	 * Test that theme.upload has no AJAX surface.
	 */
	public function test_theme_upload_rule_has_no_ajax_surface(): void {
		Functions\when( '__' )->returnArg();

		$rule = $this->find_rule( 'theme.upload' );

		$this->assertNotNull( $rule, 'theme.upload rule not found' );
		$this->assertNull( $rule['ajax'], 'theme.upload must have null ajax surface' );
	}

	/**
	 * Test that theme.upload has no REST surface.
	 */
	public function test_theme_upload_rule_has_no_rest_surface(): void {
		Functions\when( '__' )->returnArg();

		$rule = $this->find_rule( 'theme.upload' );

		$this->assertNotNull( $rule, 'theme.upload rule not found' );
		$this->assertNull( $rule['rest'], 'theme.upload must have null rest surface' );
	}

	/**
	 * Test that upload rules belong to the correct categories.
	 */
	public function test_upload_rules_have_correct_categories(): void {
		Functions\when( '__' )->returnArg();

		$plugin_upload = $this->find_rule( 'plugin.upload' );
		$theme_upload  = $this->find_rule( 'theme.upload' );

		$this->assertNotNull( $plugin_upload );
		$this->assertNotNull( $theme_upload );
		$this->assertSame( 'plugins', $plugin_upload['category'] ?? null );
		$this->assertSame( 'themes', $theme_upload['category'] ?? null );
	}
}
