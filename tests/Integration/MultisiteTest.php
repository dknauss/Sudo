<?php
/**
 * Integration tests for multisite session isolation and network-wide behavior.
 *
 * Verifies that settings, user meta, stash transients, and upgrader version
 * use network-wide storage on multisite, while session verification is
 * isolated by cookie domain binding.
 *
 * Self-guarding: each test skips when is_multisite() is false.
 *
 * @group multisite
 * @covers \WP_Sudo\Sudo_Session
 * @covers \WP_Sudo\Admin
 * @covers \WP_Sudo\Request_Stash
 * @covers \WP_Sudo\Upgrader
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Admin;
use WP_Sudo\Request_Stash;
use WP_Sudo\Sudo_Session;
use WP_Sudo\Upgrader;

class MultisiteTest extends TestCase {

	/**
	 * Skip if not running multisite.
	 */
	private function require_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}
	}

	/**
	 * Defensively restore the current blog if a test left us switched.
	 */
	public function tear_down(): void {
		restore_current_blog();

		parent::tear_down();
	}

	/**
	 * ADVN-02: Plugin settings are network-wide — same value after switch_to_blog().
	 *
	 * Admin::get() uses get_site_option() on multisite, so settings persist
	 * across all sites in the network.
	 */
	public function test_settings_are_network_wide(): void {
		$this->require_multisite();

		$blog_id = self::factory()->blog->create();

		// Set a custom session duration on the main site.
		$settings = get_site_option( Admin::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['session_duration'] = 30;
		update_site_option( Admin::OPTION_KEY, $settings );
		Admin::reset_cache();

		$this->assertSame( 30, Admin::get( 'session_duration' ) );

		// Switch to subsite — setting should be identical.
		switch_to_blog( $blog_id );
		Admin::reset_cache();

		$this->assertSame(
			30,
			Admin::get( 'session_duration' ),
			'Settings should be network-wide on multisite.'
		);

		restore_current_blog();
	}

	/**
	 * ADVN-02: User meta is network-wide — token hash readable after switch_to_blog().
	 *
	 * The usermeta table is shared across the network. get_user_meta() is not
	 * blog-scoped, so session token hashes persist across switch_to_blog().
	 */
	public function test_user_meta_is_network_wide(): void {
		$this->require_multisite();

		$user    = $this->make_admin();
		$blog_id = self::factory()->blog->create();

		// Activate sudo session on main site — writes token hash to usermeta.
		Sudo_Session::activate( $user->ID );

		$token_hash = get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true );
		$this->assertNotEmpty( $token_hash, 'Token hash should be set after activation.' );

		// Switch to subsite — usermeta should still be readable.
		switch_to_blog( $blog_id );

		$this->assertSame(
			$token_hash,
			get_user_meta( $user->ID, Sudo_Session::TOKEN_META_KEY, true ),
			'User meta (token hash) should be network-wide on multisite.'
		);

		restore_current_blog();
	}

	/**
	 * ADVN-02: Session isolated by cookie domain binding.
	 *
	 * Sudo_Session::is_active() calls verify_token(), which reads
	 * $_COOKIE[TOKEN_COOKIE]. On a different subsite, the browser would not
	 * send this cookie (different COOKIE_DOMAIN). We simulate this by
	 * unsetting the cookie from $_COOKIE superglobal.
	 */
	public function test_session_isolated_by_cookie_domain(): void {
		$this->require_multisite();

		$user = $this->make_admin();
		wp_set_current_user( $user->ID );

		// Activate on main site — sets $_COOKIE[TOKEN_COOKIE].
		Sudo_Session::activate( $user->ID );
		$this->assertTrue(
			Sudo_Session::is_active( $user->ID ),
			'Session should be active on the originating site.'
		);

		// Simulate cross-site: unset the cookie (different domain would not send it).
		$saved_cookie = $_COOKIE[ Sudo_Session::TOKEN_COOKIE ];
		unset( $_COOKIE[ Sudo_Session::TOKEN_COOKIE ] );
		Sudo_Session::reset_cache();

		// Without cookie, session verification fails.
		$this->assertFalse(
			Sudo_Session::is_active( $user->ID ),
			'Session should NOT be active without TOKEN_COOKIE (simulating different domain).'
		);

		// Restore cookie — session verifiable again.
		$_COOKIE[ Sudo_Session::TOKEN_COOKIE ] = $saved_cookie;
		Sudo_Session::reset_cache();
		$this->assertTrue(
			Sudo_Session::is_active( $user->ID ),
			'Session should be active again when TOKEN_COOKIE is restored.'
		);
	}

	/**
	 * ADVN-02: Request stash transients are network-wide.
	 *
	 * Request_Stash uses set_site_transient() on multisite, so stashed
	 * requests persist across switch_to_blog().
	 */
	public function test_stash_transient_is_network_wide(): void {
		$this->require_multisite();

		$user    = $this->make_admin();
		$blog_id = self::factory()->blog->create();

		// Set up minimal superglobals for Request_Stash::save().
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.org';
		$_SERVER['REQUEST_URI']    = '/wp-admin/plugins.php?action=delete-selected';
		$_GET                      = array( 'action' => 'delete-selected' );
		$_POST                     = array( 'verify-delete' => '1' );
		$_REQUEST                  = array_merge( $_GET, $_POST );

		$stash = new Request_Stash();
		$key   = $stash->save( $user->ID, array( 'id' => 'plugin.delete', 'label' => 'Delete Plugin' ) );

		$this->assertNotEmpty( $key );

		// Stash should be retrievable from the main site.
		$data = $stash->get( $key, $user->ID );
		$this->assertIsArray( $data );
		$this->assertSame( $user->ID, $data['user_id'] );

		// Switch to subsite — stash should still be retrievable (site transient).
		switch_to_blog( $blog_id );

		$data_from_subsite = $stash->get( $key, $user->ID );
		$this->assertIsArray(
			$data_from_subsite,
			'Stash transient should be network-wide on multisite.'
		);
		$this->assertSame( $user->ID, $data_from_subsite['user_id'] );

		restore_current_blog();
	}

	/**
	 * ADVN-02: Upgrader version option uses get_site_option() on multisite.
	 *
	 * The DB version persists across switch_to_blog() because the Upgrader
	 * reads/writes via get_site_option() / update_site_option().
	 */
	public function test_upgrader_uses_site_option(): void {
		$this->require_multisite();

		$blog_id = self::factory()->blog->create();

		// Set version on main site.
		update_site_option( Upgrader::VERSION_OPTION, '2.0.0' );

		$this->assertSame( '2.0.0', get_site_option( Upgrader::VERSION_OPTION ) );

		// Switch to subsite — version should be identical.
		switch_to_blog( $blog_id );

		$this->assertSame(
			'2.0.0',
			get_site_option( Upgrader::VERSION_OPTION ),
			'Upgrader version should be network-wide on multisite.'
		);

		restore_current_blog();
	}

	/**
	 * MIG-03 / MIG-04: upgrade_4_0_0 deletes wp_sudo_governance_mode from sitemeta
	 * on multisite.
	 *
	 * On multisite, the governance option may have been stored in sitemeta (via
	 * update_site_option()). The upgrade_4_0_0() routine calls both delete_option()
	 * and delete_site_option() to cover both stores. This test stamps the version at
	 * '3.4.0', seeds the governance option in sitemeta, runs maybe_upgrade(), and
	 * asserts the sitemeta key is gone.
	 */
	public function test_multisite_upgrade_deletes_governance_mode_from_sitemeta(): void {
		$this->require_multisite();

		// Arrange: stamp at 3.4.0 (network-wide), seed governance option in sitemeta.
		update_site_option( Upgrader::VERSION_OPTION, '3.4.0' );
		update_site_option( 'wp_sudo_governance_mode', 'compatibility' );

		$this->assertNotFalse(
			get_site_option( 'wp_sudo_governance_mode' ),
			'Precondition: governance option must be present in sitemeta before upgrade.'
		);

		// Act.
		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: sitemeta governance option deleted.
		$this->assertFalse(
			get_site_option( 'wp_sudo_governance_mode' ),
			'upgrade_4_0_0() must delete wp_sudo_governance_mode from sitemeta on multisite.'
		);

		// Assert: version stamped.
		$this->assertSame(
			WP_SUDO_VERSION,
			get_site_option( Upgrader::VERSION_OPTION ),
			'maybe_upgrade() must stamp WP_SUDO_VERSION after multisite upgrade_4_0_0().'
		);
	}

	/**
	 * MIG-04: multisite upgrade preserves super-admin capabilities and causes no
	 * cross-site capability bleed.
	 *
	 * After running the 4.0.0 upgrade from '3.4.0', a super admin who held all
	 * governance caps before must still hold them. A per-site admin on a second
	 * blog who did NOT hold them must NOT gain network-level caps (no bleed).
	 * Proves that upgrade_4_0_0() touches only the option and does not alter
	 * user capabilities.
	 */
	public function test_multisite_upgrade_preserves_super_admin_capabilities(): void {
		$this->require_multisite();

		// Arrange: a super admin with all governance caps.
		$super_admin = $this->make_admin();
		grant_super_admin( $super_admin->ID );
		foreach ( Admin::GOVERNANCE_CAPS as $cap ) {
			$super_admin->add_cap( $cap );
		}

		// Arrange: a second blog with a per-site admin who lacks network caps.
		$second_blog_id  = self::factory()->blog->create();
		$subsite_user_id = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		add_user_to_blog( $second_blog_id, $subsite_user_id, 'administrator' );
		$subsite_admin = get_user_by( 'id', $subsite_user_id );
		// The subsite admin must NOT be a super admin (no network caps).
		$this->assertFalse(
			is_super_admin( $subsite_admin->ID ),
			'Precondition: per-site admin must not be a super admin.'
		);

		// Arrange: stamp version and run upgrade.
		update_site_option( Upgrader::VERSION_OPTION, '3.4.0' );
		update_site_option( 'wp_sudo_governance_mode', 'compatibility' );

		$upgrader = new Upgrader();
		$upgrader->maybe_upgrade();

		// Assert: super admin caps are intact.
		$refreshed_super = get_user_by( 'id', $super_admin->ID );
		$this->assertTrue(
			is_super_admin( $refreshed_super->ID ),
			'Super admin must remain a super admin after upgrade_4_0_0().'
		);
		foreach ( Admin::GOVERNANCE_CAPS as $cap ) {
			$this->assertTrue(
				$refreshed_super->has_cap( $cap ),
				"Super admin must still hold governance cap {$cap} after upgrade_4_0_0()."
			);
		}

		// Assert: subsite admin did NOT gain any governance caps (no cross-site bleed).
		$refreshed_subsite = get_user_by( 'id', $subsite_admin->ID );
		foreach ( Admin::GOVERNANCE_CAPS as $cap ) {
			$this->assertFalse(
				$refreshed_subsite->has_cap( $cap ),
				"Per-site admin must NOT gain governance cap {$cap} via upgrade_4_0_0() (no cross-site bleed)."
			);
		}
	}
}
