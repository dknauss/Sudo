<?php
/**
 * Tests for the wp_sudo_build_challenge_url() helper.
 *
 * @package WP_Sudo\Tests\Unit
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * Class ChallengeUrlFunctionsTest
 *
 * Covers wp_sudo_build_challenge_url(), the shared helper that owns the
 * rawurlencode(return_url) contract for every challenge-URL builder.
 *
 * @covers ::wp_sudo_build_challenge_url
 */
class ChallengeUrlFunctionsTest extends TestCase {

	public function test_encodes_return_url_containing_nested_query_string(): void {
		$this->stub_faithful_add_query_arg();

		$url = wp_sudo_build_challenge_url(
			'https://example.com/wp-admin/admin.php',
			'https://example.com/wp-admin/options-general.php?page=wp-sudo-settings&tab=access',
			array( 'page' => 'wp-sudo-challenge' )
		);

		$parts = parse_url( $url );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertArrayHasKey( 'return_url', $get );
		$this->assertStringContainsString( 'tab=access', $get['return_url'] );
	}

	public function test_omits_return_url_arg_when_empty(): void {
		$this->stub_faithful_add_query_arg();

		$url = wp_sudo_build_challenge_url(
			'https://example.com/wp-admin/admin.php',
			'',
			array( 'page' => 'wp-sudo-challenge' )
		);

		$parts = parse_url( $url );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertArrayNotHasKey( 'return_url', $get );
		$this->assertSame( 'wp-sudo-challenge', $get['page'] );
	}

	public function test_passes_extra_args_through_unmodified(): void {
		Functions\expect( 'add_query_arg' )
			->once()
			->with(
				array(
					'page'      => 'wp-sudo-challenge',
					'stash_key' => 'abc123',
					'return_url' => rawurlencode( 'https://example.com/wp-admin/plugins.php?foo=1' ),
				),
				'https://example.com/wp-admin/admin.php'
			)
			->andReturn( 'stubbed-url' );

		$result = wp_sudo_build_challenge_url(
			'https://example.com/wp-admin/admin.php',
			'https://example.com/wp-admin/plugins.php?foo=1',
			array(
				'page'      => 'wp-sudo-challenge',
				'stash_key' => 'abc123',
			)
		);

		$this->assertSame( 'stubbed-url', $result );
	}

	/**
	 * Round-trip edge case: a return_url containing a literal '+' must
	 * survive rawurlencode() → $_GET's single implicit decode without
	 * being mangled into a space (the historical urlencode()/+ pitfall).
	 */
	public function test_round_trip_preserves_literal_plus_character(): void {
		$this->stub_faithful_add_query_arg();

		$original = 'https://example.com/wp-admin/edit.php?s=a+b&paged=2';

		$url = wp_sudo_build_challenge_url(
			'https://example.com/wp-admin/admin.php',
			$original,
			array( 'page' => 'wp-sudo-challenge' )
		);

		$parts = parse_url( $url );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertSame( $original, $get['return_url'] );
	}

	/**
	 * Round-trip edge case: a return_url containing a pre-encoded %XX
	 * sequence must decode to exactly one original value — proving the
	 * helper applies rawurlencode() exactly once (double-encoding would
	 * leave a literal "%25" in the decoded value).
	 */
	public function test_round_trip_preserves_pre_encoded_percent_sequence(): void {
		$this->stub_faithful_add_query_arg();

		$original = 'https://example.com/wp-admin/edit.php?s=100%25+done&paged=2';

		$url = wp_sudo_build_challenge_url(
			'https://example.com/wp-admin/admin.php',
			$original,
			array( 'page' => 'wp-sudo-challenge' )
		);

		$parts = parse_url( $url );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertSame( $original, $get['return_url'] );
	}

	/**
	 * Round-trip edge case: a return_url containing a multibyte character
	 * must survive the encode/decode cycle unchanged.
	 */
	public function test_round_trip_preserves_multibyte_character(): void {
		$this->stub_faithful_add_query_arg();

		$original = 'https://example.com/wp-admin/edit.php?s=caf%C3%A9&paged=2';

		$url = wp_sudo_build_challenge_url(
			'https://example.com/wp-admin/admin.php',
			$original,
			array( 'page' => 'wp-sudo-challenge' )
		);

		$parts = parse_url( $url );
		parse_str( $parts['query'] ?? '', $get );

		$this->assertSame( $original, $get['return_url'] );
	}
}
