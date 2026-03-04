<?php
/**
 * Integration tests for Request_Stash — real transient write/read/delete lifecycle.
 *
 * @covers \WP_Sudo\Request_Stash
 * @package WP_Sudo\Tests\Integration
 */

namespace WP_Sudo\Tests\Integration;

use WP_Sudo\Request_Stash;

class RequestStashTest extends TestCase {

	/**
	 * Read a stash transient using the correct API for the environment.
	 *
	 * Request_Stash uses set_site_transient() on multisite, set_transient()
	 * otherwise. Raw verification must use the matching getter.
	 *
	 * @param string $key Full transient key (including prefix).
	 * @return mixed Transient value or false.
	 */
	private function get_raw_transient( string $key ) {
		return is_multisite() ? get_site_transient( $key ) : get_transient( $key );
	}

	/**
	 * INTG-04: save() stores a transient via real set_transient().
	 */
	public function test_save_stores_transient(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertSame( 16, strlen( $key ), 'Stash key should be 16 characters.' );

		// Verify via raw transient API (multisite-aware).
		$raw = $this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key );
		$this->assertIsArray( $raw );
		$this->assertSame( $user->ID, $raw['user_id'] );
		$this->assertSame( 'plugin.activate', $raw['rule_id'] );
	}

	/**
	 * INTG-04: get() retrieves the stash for the correct user.
	 */
	public function test_get_retrieves_for_correct_user(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key  = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		$data = $stash->get( $key, $user->ID );

		$this->assertIsArray( $data );
		$this->assertSame( 'plugin.activate', $data['rule_id'] );
	}

	/**
	 * INTG-04: get() returns null for the wrong user.
	 */
	public function test_get_returns_null_for_wrong_user(): void {
		$stash  = new Request_Stash();
		$user_a = $this->make_admin();
		$user_b = $this->make_admin( 'other-password' );

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user_a->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertNull( $stash->get( $key, $user_b->ID ) );
	}

	/**
	 * INTG-04: delete() removes the transient.
	 */
	public function test_delete_removes_transient(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// Transient exists before delete.
		$this->assertIsArray( $this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key ) );

		$stash->delete( $key );

		// Transient gone after delete.
		$this->assertFalse( $this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key ) );
	}

	/**
	 * INTG-04: exists() returns true then false after delete.
	 */
	public function test_exists_true_then_false_after_delete(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$this->assertTrue( $stash->exists( $key, $user->ID ) );

		$stash->delete( $key );

		$this->assertFalse( $stash->exists( $key, $user->ID ) );
	}

	/**
	 * INTG-04: Stash preserves the full request structure including all 8 fields.
	 */
	public function test_stash_preserves_request_structure(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request(
			'plugins.php',
			'activate',
			'POST',
			array( 'plugin' => 'hello.php' ),
			array( '_wpnonce' => 'abc123' )
		);

		$key  = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		$data = $stash->get( $key, $user->ID );

		$this->assertSame( $user->ID, $data['user_id'] );
		$this->assertSame( 'plugin.activate', $data['rule_id'] );
		$this->assertSame( 'Activate plugin', $data['label'] );
		$this->assertSame( 'POST', $data['method'] );
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'get', $data );
		$this->assertArrayHasKey( 'post', $data );
		$this->assertArrayHasKey( 'created', $data );
		$this->assertEqualsWithDelta( time(), $data['created'], 2, 'Created timestamp should be within 2 seconds.' );
	}

	/**
	 * INTG-04: save() omits password fields but preserves non-sensitive fields.
	 */
	public function test_save_omits_passwords_from_stored_transient(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request(
			'user-new.php',
			'createuser',
			'POST',
			array(),
			array(
				'user_login' => 'newuser',
				'pass1'      => 'secret-password',
				'pass2'      => 'secret-password',
				'user_pass'  => 'also-secret',
				'role'       => 'subscriber',
				'_wpnonce'   => 'fakenonce123',
			)
		);

		$key = $stash->save( $user->ID, array( 'id' => 'user.create', 'label' => 'Create user' ) );

		// Read raw transient to verify stored content (not via get() which adds ownership check).
		$raw = $this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key );

		$this->assertIsArray( $raw );
		$this->assertArrayHasKey( 'user_login', $raw['post'], 'user_login must survive redaction' );
		$this->assertArrayHasKey( 'role', $raw['post'], 'role must survive redaction' );
		$this->assertArrayHasKey( '_wpnonce', $raw['post'], '_wpnonce must survive redaction' );
		$this->assertArrayNotHasKey( 'pass1', $raw['post'], 'pass1 must be omitted from stored transient' );
		$this->assertArrayNotHasKey( 'pass2', $raw['post'], 'pass2 must be omitted from stored transient' );
		$this->assertArrayNotHasKey( 'user_pass', $raw['post'], 'user_pass must be omitted from stored transient' );
	}

	/**
	 * INTG-04: save() preserves non-sensitive fields in raw transient storage.
	 */
	public function test_save_non_sensitive_fields_survive_redaction(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request(
			'options-general.php',
			'update',
			'POST',
			array(),
			array(
				'blogname'    => 'My Site',
				'admin_email' => 'admin@example.org',
				'secret'      => 'should-not-be-stored',
				'_wpnonce'    => 'nonce123',
			)
		);

		$key = $stash->save( $user->ID, array( 'id' => 'options.general', 'label' => 'Save settings' ) );
		$raw = $this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key );

		$this->assertArrayHasKey( 'blogname', $raw['post'], 'blogname must survive redaction' );
		$this->assertArrayHasKey( 'admin_email', $raw['post'], 'admin_email must survive redaction' );
		$this->assertArrayHasKey( '_wpnonce', $raw['post'], '_wpnonce must survive redaction' );
		$this->assertArrayNotHasKey( 'secret', $raw['post'], 'secret must be omitted from raw transient' );
	}

	/**
	 * INTG-04: save() records the stash key in user meta index.
	 */
	public function test_save_records_key_in_user_meta_index(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$index = get_user_meta( $user->ID, Request_Stash::STASH_INDEX_META_KEY, true );

		$this->assertIsArray( $index, 'Stash index must be an array' );
		$this->assertContains( $key, $index, 'Stash key must appear in user meta index after save' );
	}

	/**
	 * INTG-04: saving 6 stashes evicts the oldest; only 5 remain in index and as live transients.
	 */
	public function test_stash_cap_evicts_oldest(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		// Save MAX_STASH_PER_USER stashes.
		$keys = array();
		for ( $i = 0; $i < Request_Stash::MAX_STASH_PER_USER; $i++ ) {
			$keys[] = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		}

		// All 5 transients exist.
		foreach ( $keys as $key ) {
			$this->assertIsArray(
				$this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key ),
				"Transient for key {$key} must exist before cap is hit"
			);
		}

		// 6th save — should evict the first (oldest) key.
		$sixth_key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// First (oldest) transient must be gone.
		$this->assertFalse(
			$this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $keys[0] ),
			'Oldest stash transient must be evicted on 6th save'
		);

		// Index must have exactly 5 entries (keys[1]..keys[4] + sixth_key).
		$index = get_user_meta( $user->ID, Request_Stash::STASH_INDEX_META_KEY, true );
		$this->assertCount( 5, $index, 'Index must have exactly 5 entries after eviction' );
		$this->assertNotContains( $keys[0], $index, 'Evicted key must not be in index' );
		$this->assertContains( $sixth_key, $index, 'New key must be in index' );
	}

	/**
	 * INTG-04: delete() with user_id removes key from user meta index.
	 */
	public function test_delete_with_user_id_removes_from_index(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key_a = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );
		$key_b = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		// Both keys in index.
		$index_before = get_user_meta( $user->ID, Request_Stash::STASH_INDEX_META_KEY, true );
		$this->assertContains( $key_a, $index_before );
		$this->assertContains( $key_b, $index_before );

		// Delete with user_id — should clean up index.
		$stash->delete( $key_a, $user->ID );

		$index_after = get_user_meta( $user->ID, Request_Stash::STASH_INDEX_META_KEY, true );
		$this->assertNotContains( $key_a, $index_after, 'Deleted key must not be in index' );
		$this->assertContains( $key_b, $index_after, 'Other key must still be in index' );

		// Transient must also be gone.
		$this->assertFalse(
			$this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key_a ),
			'Transient must be deleted'
		);
	}

	/**
	 * INTG-04: delete() without user_id deletes transient but does not update index.
	 *
	 * This is the backward-compat path. The index will retain a stale key
	 * that self-corrects when enforce_stash_cap() next processes this user.
	 */
	public function test_delete_without_user_id_leaves_index_intact(): void {
		$stash = new Request_Stash();
		$user  = $this->make_admin();

		$this->simulate_admin_request( 'plugins.php', 'activate', 'GET' );

		$key = $stash->save( $user->ID, array( 'id' => 'plugin.activate', 'label' => 'Activate plugin' ) );

		$index_before = get_user_meta( $user->ID, Request_Stash::STASH_INDEX_META_KEY, true );
		$this->assertContains( $key, $index_before, 'Key must be in index before delete' );

		// Delete without user_id (backward compat).
		$stash->delete( $key );

		// Transient gone.
		$this->assertFalse(
			$this->get_raw_transient( Request_Stash::TRANSIENT_PREFIX . $key ),
			'Transient must be deleted even without user_id'
		);

		// Index still has the key (stale but acceptable — self-corrects on next enforce).
		$index_after = get_user_meta( $user->ID, Request_Stash::STASH_INDEX_META_KEY, true );
		$this->assertContains(
			$key,
			$index_after,
			'Index retains stale key when delete() called without user_id (expected behavior)'
		);
	}
}
