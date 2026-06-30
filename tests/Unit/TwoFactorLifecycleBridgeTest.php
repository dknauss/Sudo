<?php
/**
 * Tests for the Two Factor lifecycle bridge.
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * @coversNothing Procedural bridge file.
 *
 * Note: `tests/bootstrap.php` unconditionally stubs `Two_Factor_Core`, so the
 * bridge's `class_exists( 'Two_Factor_Core' )` runtime guard always sees the
 * plugin as present here. The "Two Factor absent → bridge inert" branch is a
 * standard one-line guard (mirroring the other bridges) that cannot be
 * exercised in the unit suite without undefining a class; it is left to
 * real-environment verification.
 */
class TwoFactorLifecycleBridgeTest extends TestCase {

	/**
	 * Capture the wp_sudo_gated_actions filter the bridge registers, then
	 * require the bridge file under Brain\Monkey-aliased WordPress functions.
	 *
	 * @return callable The registered wp_sudo_gated_actions callback.
	 */
	private function capture_gated_actions_filter(): callable {
		$captured = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, callable $callback ) use ( &$captured ): bool {
				if ( 'wp_sudo_gated_actions' === $hook ) {
					$captured = $callback;
				}
				return true;
			}
		);

		require __DIR__ . '/../../bridges/wp-sudo-two-factor-lifecycle-bridge.php';

		$this->assertIsCallable( $captured, 'Bridge must register a wp_sudo_gated_actions filter.' );

		return $captured;
	}

	/**
	 * Find a rule by id in the filtered rule list.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 * @param string                           $id    Rule id.
	 * @return array<string, mixed>
	 */
	private function rule_by_id( array $rules, string $id ): array {
		foreach ( $rules as $rule ) {
			if ( ( $rule['id'] ?? '' ) === $id ) {
				return $rule;
			}
		}

		$this->fail( "Rule '{$id}' was not registered." );
	}

	/**
	 * Set supported provider keys for the Two_Factor_Core test stub.
	 *
	 * @param string[] $provider_keys Provider keys.
	 * @return void
	 */
	private function set_supported_provider_keys( array $provider_keys ): void {
		\Two_Factor_Core::$mock_supported_provider_keys       = $provider_keys;
		\Two_Factor_Core::$mock_can_update_two_factor_options = true;
	}

	/**
	 * Prepare the profile callback request context.
	 *
	 * @param string                     $pagenow      Current admin page.
	 * @param int                        $current_user Current user ID.
	 * @param array<string, mixed>       $post         Mock POST body.
	 * @param array<int, array<string,mixed>> $user_meta    Mock user meta by user ID.
	 * @return void
	 */
	private function prepare_profile_request( string $pagenow, int $current_user, array $post, array $user_meta ): void {
		$GLOBALS['pagenow'] = $pagenow;
		$_POST             = $post;
		$_REQUEST          = array( 'action' => $post['action'] ?? '' );

		Functions\when( 'get_current_user_id' )->justReturn( $current_user );
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, string $action ) {
				return 'valid-nonce' === $nonce && 'user_two_factor_options' === $action ? 1 : false;
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			static function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ): string {
				return is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( $user_meta ) {
				if ( isset( $user_meta[ $user_id ] ) && array_key_exists( $key, $user_meta[ $user_id ] ) ) {
					return $user_meta[ $user_id ][ $key ];
				}

				if ( '_two_factor_enabled_providers' === $key ) {
					return array();
				}

				return $single ? '' : array();
			}
		);
	}

	/**
	 * Return the profile lifecycle rule callback.
	 *
	 * @return callable
	 */
	private function profile_lifecycle_callback(): callable {
		$rules = ( $this->capture_gated_actions_filter() )( array() );
		$rule  = $this->rule_by_id( $rules, 'two_factor.profile_provider_lifecycle' );

		$this->assertIsArray( $rule['admin'] );
		$this->assertIsCallable( $rule['admin']['callback'] ?? null );

		return $rule['admin']['callback'];
	}

	/**
	 * Build a normal profile-save POST body for the callback tests.
	 *
	 * @param array<int|string, mixed>|null $enabled_providers Enabled-provider POST value.
	 * @param string|null                   $primary_provider  Primary provider POST value; null omits the field.
	 * @param array<string, mixed>          $extra             Extra POST fields.
	 * @return array<string, mixed>
	 */
	private function profile_post( ?array $enabled_providers, ?string $primary_provider, array $extra = array() ): array {
		$post = array_merge(
			array(
				'action'                         => 'update',
				'_nonce_user_two_factor_options' => 'valid-nonce',
			),
			$extra
		);

		if ( null !== $enabled_providers ) {
			$post['_two_factor_enabled_providers'] = $enabled_providers;
		}

		if ( null !== $primary_provider ) {
			$post['_two_factor_provider'] = $primary_provider;
		}

		return $post;
	}

	/**
	 * The bridge registers the two factor-management REST rules plus the
	 * classic profile-provider lifecycle rule. Each rule is well-formed for
	 * the Action Registry, and the REST matchers remain unchanged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_registers_well_formed_rules_when_two_factor_present(): void {
		\Brain\Monkey\setUp();

		$filter = $this->capture_gated_actions_filter();
		$rules  = $filter( array() );

		$ids = array_column( $rules, 'id' );
		$this->assertContains( 'two_factor.backup_codes_generate', $ids );
		$this->assertContains( 'two_factor.totp_manage', $ids );
		$this->assertContains( 'two_factor.profile_provider_lifecycle', $ids );
		$this->assertCount( 3, $rules );

		$rest_rules = array(
			$this->rule_by_id( $rules, 'two_factor.backup_codes_generate' ),
			$this->rule_by_id( $rules, 'two_factor.totp_manage' ),
		);

		foreach ( $rest_rules as $rule ) {
			$this->assertNotEmpty( $rule['id'] );
			$this->assertNotEmpty( $rule['label'] );
			$this->assertNotEmpty( $rule['category'] );
			$this->assertNull( $rule['admin'] );
			$this->assertNull( $rule['ajax'] );
			$this->assertIsArray( $rule['rest'] );
			$this->assertIsString( $rule['rest']['route'] );
			$this->assertIsArray( $rule['rest']['methods'] );
			$this->assertNotFalse(
				@preg_match( $rule['rest']['route'], '' ),
				"Route pattern for {$rule['id']} must be a valid regex."
			);
		}

		$profile = $this->rule_by_id( $rules, 'two_factor.profile_provider_lifecycle' );
		$this->assertNotEmpty( $profile['label'] );
		$this->assertSame( 'users', $profile['category'] );
		$this->assertSame( array( 'profile.php', 'user-edit.php' ), $profile['admin']['pagenow'] );
		$this->assertSame( array( 'update' ), $profile['admin']['actions'] );
		$this->assertSame( 'POST', $profile['admin']['method'] );
		$this->assertIsCallable( $profile['admin']['callback'] );
		$this->assertNull( $profile['ajax'] );
		$this->assertNull( $profile['rest'] );
		$this->assertSame( 'allowlist', $profile['stash']['post_mode'] );
		foreach ( array( 'user_id', '_nonce_user_two_factor_options', '_two_factor_enabled_providers', '_two_factor_provider' ) as $field ) {
			$this->assertContains( $field, $profile['stash']['post_fields'] );
		}
		foreach ( array( 'user_login', 'email', 'nickname', 'first_name', 'last_name', 'display_name', 'url', 'description', 'locale' ) as $field ) {
			$this->assertContains( $field, $profile['stash']['post_fields'], "Core profile field {$field} must survive challenge replay." );
		}

		\Brain\Monkey\tearDown();
	}

	/**
	 * The TOTP rule matches POST and DELETE on the exact /totp route only, and
	 * the backup-codes rule matches POST on generate-backup-codes only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_routes_and_methods_match_the_verified_two_factor_endpoints(): void {
		\Brain\Monkey\setUp();

		$rules = ( $this->capture_gated_actions_filter() )( array() );

		$totp = $this->rule_by_id( $rules, 'two_factor.totp_manage' );
		$this->assertSame( 1, preg_match( $totp['rest']['route'], '/two-factor/1.0/totp' ) );
		$this->assertSame( 0, preg_match( $totp['rest']['route'], '/two-factor/1.0/totp/extra' ) );
		$this->assertSame( array( 'POST', 'DELETE' ), $totp['rest']['methods'] );

		$backup = $this->rule_by_id( $rules, 'two_factor.backup_codes_generate' );
		$this->assertSame( 1, preg_match( $backup['rest']['route'], '/two-factor/1.0/generate-backup-codes' ) );
		$this->assertSame( array( 'POST' ), $backup['rest']['methods'] );
		// The backup-codes rule must NOT match the totp route or unrelated routes.
		$this->assertSame( 0, preg_match( $backup['rest']['route'], '/two-factor/1.0/totp' ) );
		$this->assertSame( 0, preg_match( $backup['rest']['route'], '/wp/v2/users/1' ) );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Unrelated or upstream-no-op profile saves must not be gated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_profile_callback_returns_false_for_absent_invalid_or_malformed_upstream_data(): void {
		\Brain\Monkey\setUp();

		$this->set_supported_provider_keys( array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );
		$callback = $this->profile_lifecycle_callback();
		$meta     = array(
			123 => array(
				'_two_factor_enabled_providers' => array( 'Two_Factor_Email' ),
				'_two_factor_provider'          => 'Two_Factor_Email',
				'_two_factor_totp_key'          => '',
			),
		);

		$this->prepare_profile_request( 'profile.php', 123, array( 'action' => 'update', 'display_name' => 'Only Profile' ), $meta );
		$this->assertFalse( $callback(), 'Unrelated profile saves without Two Factor fields must not be gated.' );

		$this->prepare_profile_request( 'profile.php', 123, $this->profile_post( null, 'Two_Factor_Email' ), $meta );
		$this->assertFalse( $callback(), 'Saves without enabled-provider array must not be gated.' );

		$post                                    = $this->profile_post( array(), 'Two_Factor_Email' );
		$post['_two_factor_enabled_providers']  = 'Two_Factor_Email';
		$this->prepare_profile_request( 'profile.php', 123, $post, $meta );
		$this->assertFalse( $callback(), 'Malformed non-array enabled-provider values must not be gated.' );

		$post                                    = $this->profile_post( array( 'Two_Factor_Email' ), 'Two_Factor_Email' );
		$post['_nonce_user_two_factor_options'] = 'invalid-nonce';
		$this->prepare_profile_request( 'profile.php', 123, $post, $meta );
		$this->assertFalse( $callback(), 'Invalid Two Factor nonces must fail open because upstream would not save.' );

		$this->prepare_profile_request(
			'user-edit.php',
			123,
			$this->profile_post( array( 'Two_Factor_Totp' ), 'Two_Factor_Totp', array( 'user_id' => '' ) ),
			$meta
		);
		$this->assertFalse( $callback(), 'user-edit.php saves without a valid target user must fail open.' );

		\Two_Factor_Core::$mock_can_update_two_factor_options = false;
		$this->prepare_profile_request(
			'user-edit.php',
			123,
			$this->profile_post( array( 'Two_Factor_Totp' ), 'Two_Factor_Totp', array( 'user_id' => '123' ) ),
			$meta
		);
		$this->assertFalse( $callback(), 'Requests upstream would not be allowed to write must not be gated.' );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Normalized no-op resubmissions must not be gated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_profile_callback_returns_false_for_normalized_noop_provider_resubmissions(): void {
		\Brain\Monkey\setUp();

		$this->set_supported_provider_keys( array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );
		$callback = $this->profile_lifecycle_callback();
		$meta     = array(
			123 => array(
				'_two_factor_enabled_providers' => array( 'Two_Factor_Email', 'Two_Factor_Totp', 'Unsupported_Current' ),
				'_two_factor_provider'          => 'Two_Factor_Totp',
				'_two_factor_totp_key'          => 'totp-secret',
			),
		);

		$this->prepare_profile_request(
			'profile.php',
			123,
			$this->profile_post(
				array( '', 'Unknown_Provider', 'Two_Factor_Totp', 'Two_Factor_Email', 'Two_Factor_Totp', array( 'nested' ) ),
				'Two_Factor_Totp',
				array( 'user_id' => '999' )
			),
			$meta
		);

		$this->assertFalse( $callback(), 'Dummy, unknown, duplicate, nested, and reordered provider values must normalize to a no-op.' );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Enabled-provider additions and removals must be gated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_profile_callback_returns_true_for_enabled_provider_additions_and_removals(): void {
		\Brain\Monkey\setUp();

		$this->set_supported_provider_keys( array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );
		$callback = $this->profile_lifecycle_callback();

		$this->prepare_profile_request(
			'profile.php',
			123,
			$this->profile_post( array( 'Two_Factor_Email', 'Two_Factor_Totp' ), 'Two_Factor_Email' ),
			array(
				123 => array(
					'_two_factor_enabled_providers' => array( 'Two_Factor_Email' ),
					'_two_factor_provider'          => 'Two_Factor_Email',
					'_two_factor_totp_key'          => 'totp-secret',
				),
			)
		);
		$this->assertTrue( $callback(), 'Adding a normalized enabled provider must be gated.' );

		$this->prepare_profile_request(
			'user-edit.php',
			123,
			$this->profile_post( array( 'Two_Factor_Email' ), 'Two_Factor_Email', array( 'user_id' => '456' ) ),
			array(
				456 => array(
					'_two_factor_enabled_providers' => array( 'Two_Factor_Email', 'Two_Factor_Totp' ),
					'_two_factor_provider'          => 'Two_Factor_Email',
					'_two_factor_totp_key'          => 'totp-secret',
				),
			)
		);
		$this->assertTrue( $callback(), 'Removing a normalized enabled provider must be gated.' );

		\Brain\Monkey\tearDown();
	}

	/**
	 * Primary-provider changes and deletions must be gated after normalization.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_profile_callback_returns_true_for_primary_provider_changes(): void {
		\Brain\Monkey\setUp();

		$this->set_supported_provider_keys( array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );
		$callback = $this->profile_lifecycle_callback();
		$meta     = array(
			123 => array(
				'_two_factor_enabled_providers' => array( 'Two_Factor_Email', 'Two_Factor_Totp' ),
				'_two_factor_provider'          => 'Two_Factor_Email',
				'_two_factor_totp_key'          => 'totp-secret',
			),
		);

		$this->prepare_profile_request( 'profile.php', 123, $this->profile_post( array( 'Two_Factor_Email', 'Two_Factor_Totp' ), 'Two_Factor_Totp' ), $meta );
		$this->assertTrue( $callback(), 'Changing the normalized primary provider must be gated.' );

		$this->prepare_profile_request( 'profile.php', 123, $this->profile_post( array( 'Two_Factor_Email', 'Two_Factor_Totp' ), '' ), $meta );
		$this->assertTrue( $callback(), 'Deleting the current primary provider via an empty primary value must be gated.' );

		$this->prepare_profile_request( 'profile.php', 123, $this->profile_post( array( 'Two_Factor_Email', 'Two_Factor_Totp' ), 'Unknown_Provider' ), $meta );
		$this->assertTrue( $callback(), 'Deleting the current primary provider via an unavailable primary value must be gated.' );

		\Brain\Monkey\tearDown();
	}

	/**
	 * TOTP-backed first enrollment and removal must be gated when a verified
	 * TOTP key already exists and the normalized provider lifecycle changes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_profile_callback_returns_true_for_totp_backed_lifecycle_changes(): void {
		\Brain\Monkey\setUp();

		$this->set_supported_provider_keys( array( 'Two_Factor_Email', 'Two_Factor_Totp' ) );
		$callback = $this->profile_lifecycle_callback();

		$this->prepare_profile_request(
			'profile.php',
			123,
			$this->profile_post( array( 'Two_Factor_Totp' ), 'Two_Factor_Totp' ),
			array(
				123 => array(
					'_two_factor_enabled_providers' => array(),
					'_two_factor_provider'          => '',
					'_two_factor_totp_key'          => 'existing-totp-secret',
				),
			)
		);
		$this->assertTrue( $callback(), 'First TOTP-backed enrollment with an existing TOTP key must be gated.' );

		$this->prepare_profile_request(
			'profile.php',
			123,
			$this->profile_post( array(), '' ),
			array(
				123 => array(
					'_two_factor_enabled_providers' => array( 'Two_Factor_Totp' ),
					'_two_factor_provider'          => 'Two_Factor_Totp',
					'_two_factor_totp_key'          => 'existing-totp-secret',
				),
			)
		);
		$this->assertTrue( $callback(), 'TOTP-backed removal with an existing TOTP key must be gated.' );

		\Brain\Monkey\tearDown();
	}
}
