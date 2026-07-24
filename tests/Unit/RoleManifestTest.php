<?php
/**
 * Tests for Role_Manifest — the file-backed trusted-principal manifest reader.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use WP_Sudo\Role_Manifest;
use WP_Sudo\Tests\TestCase;

/**
 * @covers \WP_Sudo\Role_Manifest
 */
class RoleManifestTest extends TestCase {

	/**
	 * Absolute paths of temp fixtures to clean up.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();
		parent::tearDown();
	}

	/**
	 * Write JSON to a temp file and track it for cleanup.
	 */
	private function fixture( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'wpsudo-manifest-' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;
		return $path;
	}

	private function valid_manifest_json(): string {
		return (string) json_encode(
			array(
				'manifest_version' => 1,
				'generated'        => '2026-07-17T00:00:00Z',
				'sites'            => array(
					'1' => array(
						'administrators' => array( 1, 7 ),
						'governance'     => array( 1 ),
					),
				),
				'network'          => array( 'super_admins' => array( 1 ) ),
				'privileged_roles' => array( 'administrator' => 'sha256:abc' ),
			)
		);
	}

	// ---- parse() ----

	public function test_parse_valid_returns_normalized_structure(): void {
		$m = Role_Manifest::parse( $this->valid_manifest_json() );

		$this->assertIsArray( $m );
		$this->assertSame( 1, $m['manifest_version'] );
		$this->assertSame( array( 1, 7 ), $m['sites'][1]['administrators'] );
		$this->assertSame( array( 1 ), $m['sites'][1]['governance'] );
		$this->assertSame( array( 1 ), $m['network']['super_admins'] );
		$this->assertSame( 'sha256:abc', $m['privileged_roles']['administrator'] );
	}

	public function test_parse_malformed_json_returns_null(): void {
		$this->assertNull( Role_Manifest::parse( '{ not: valid json,,, ' ) );
	}

	public function test_parse_non_array_top_level_returns_null(): void {
		$this->assertNull( Role_Manifest::parse( '42' ) );
		$this->assertNull( Role_Manifest::parse( '"a string"' ) );
		$this->assertNull( Role_Manifest::parse( 'null' ) );
	}

	public function test_parse_unsupported_version_returns_null(): void {
		$json = (string) json_encode(
			array(
				'manifest_version' => 2,
				'sites'            => array(),
			) 
		);
		$this->assertNull( Role_Manifest::parse( $json ) );
	}

	public function test_parse_missing_version_returns_null(): void {
		$json = (string) json_encode( array( 'sites' => array() ) );
		$this->assertNull( Role_Manifest::parse( $json ) );
	}

	public function test_parse_missing_sections_default_to_empty(): void {
		$json = (string) json_encode( array( 'manifest_version' => 1 ) );
		$m    = Role_Manifest::parse( $json );

		$this->assertIsArray( $m );
		$this->assertSame( array(), $m['sites'] );
		$this->assertSame( array(), $m['network']['super_admins'] );
		$this->assertSame( array(), $m['privileged_roles'] );
	}

	public function test_parse_coerces_principal_ids_to_int(): void {
		$json = (string) json_encode(
			array(
				'manifest_version' => 1,
				'sites'            => array( '1' => array( 'administrators' => array( '7', '3' ) ) ),
			)
		);
		$m    = Role_Manifest::parse( $json );

		$this->assertIsArray( $m );
		// Coerced to int and sorted ascending.
		$this->assertSame( array( 3, 7 ), $m['sites'][1]['administrators'] );
		$this->assertContainsOnly( 'int', $m['sites'][1]['administrators'] );
	}

	// ---- load() ----

	public function test_load_reads_and_parses_fixture_file(): void {
		$path = $this->fixture( $this->valid_manifest_json() );
		$m    = Role_Manifest::load( $path );

		$this->assertIsArray( $m );
		$this->assertSame( array( 1, 7 ), $m['sites'][1]['administrators'] );
	}

	public function test_load_nonexistent_file_returns_null(): void {
		$this->assertNull( Role_Manifest::load( '/no/such/wp-sudo-manifest-xyz.json' ) );
	}

	public function test_load_malformed_file_returns_null_not_fatal(): void {
		$path = $this->fixture( '{ broken json ' );
		$this->assertNull( Role_Manifest::load( $path ) );
	}

	public function test_load_null_path_when_constant_undefined_returns_null(): void {
		// WP_SUDO_ROLE_MANIFEST is not defined in the unit env → inert.
		$this->assertFalse( defined( 'WP_SUDO_ROLE_MANIFEST' ), 'guard: constant must be undefined for this test.' );
		$this->assertNull( Role_Manifest::load() );
	}

	public function test_is_enabled_false_when_constant_undefined(): void {
		$this->assertFalse( defined( 'WP_SUDO_ROLE_MANIFEST' ), 'guard: constant must be undefined for this test.' );
		$this->assertFalse( Role_Manifest::is_enabled() );
		$this->assertNull( Role_Manifest::configured_path() );
	}

	// ---- build_document() / write() ----

	public function test_build_document_wraps_state_with_version_and_timestamp(): void {
		$state = array(
			'sites'            => array( 1 => array( 'administrators' => array( 1 ), 'governance' => array( 1 ) ) ),
			'network'          => array( 'super_admins' => array() ),
			'privileged_roles' => array( 'administrator' => 'sha256:abc' ),
		);

		$doc = Role_Manifest::build_document( $state, '2026-07-17T00:00:00Z' );

		$this->assertSame( 1, $doc['manifest_version'] );
		$this->assertSame( '2026-07-17T00:00:00Z', $doc['generated'] );
		$this->assertSame( $state['sites'], $doc['sites'] );
		$this->assertSame( $state['network'], $doc['network'] );
		$this->assertSame( $state['privileged_roles'], $doc['privileged_roles'] );
	}

	public function test_write_then_parse_round_trips(): void {
		$state = array(
			'sites'            => array( 1 => array( 'administrators' => array( 3, 7 ), 'governance' => array( 3 ) ) ),
			'network'          => array( 'super_admins' => array() ),
			'privileged_roles' => array( 'administrator' => 'sha256:def' ),
		);
		// wp_json_encode is a WordPress runtime function, absent in the unit env.
		\Brain\Monkey\Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$doc  = Role_Manifest::build_document( $state, '2026-07-17T00:00:00Z' );
		$path = tempnam( sys_get_temp_dir(), 'wpsudo-manifest-write-' );
		$this->temp_files[] = $path;

		$this->assertTrue( Role_Manifest::write( $path, $doc ) );

		$loaded = Role_Manifest::load( $path );
		$this->assertIsArray( $loaded );
		$this->assertSame( array( 3, 7 ), $loaded['sites'][1]['administrators'] );
		$this->assertSame( 'sha256:def', $loaded['privileged_roles']['administrator'] );
	}
}
