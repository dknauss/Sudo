<?php
/**
 * Tests for the dev/CI persistent-option scanner (bin/scan-persistent-options.php).
 *
 * The scanner backs the "Persistent options" gate in bin/verify-metrics.sh: it
 * discovers the option names the plugin writes so the metric is asserted against
 * code rather than a self-confirming list. These fixtures lock the behaviors that
 * two review passes flagged — especially that same-named constants in different
 * classes must NOT collide (the false-green this replaces), and that anything the
 * scanner cannot resolve to a literal must fail closed.
 *
 * @package WP_Sudo\Tests\Unit
 */

declare( strict_types = 1 );

namespace WP_Sudo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Sudo\Dev\Persistent_Option_Scanner;

require_once dirname( __DIR__, 2 ) . '/bin/scan-persistent-options.php';

final class PersistentOptionScannerTest extends TestCase {

	/**
	 * @param array<string,string> $sources
	 * @return list<string>
	 */
	private function scan( array $sources ): array {
		return ( new Persistent_Option_Scanner() )->scan_sources( $sources );
	}

	public function test_literal_option_name_is_discovered(): void {
		$src = "<?php class A { function f() { update_option( 'wp_sudo_thing', 1 ); } }";
		$this->assertSame( array( 'wp_sudo_thing' ), $this->scan( array( 'a' => $src ) ) );
	}

	public function test_self_const_resolves_within_its_class(): void {
		$src = "<?php class Admin { const OPTION_KEY = 'wp_sudo_settings'; function f() { update_option( self::OPTION_KEY, array() ); } }";
		$this->assertSame( array( 'wp_sudo_settings' ), $this->scan( array( 'admin' => $src ) ) );
	}

	public function test_explicit_class_const_resolves_across_files(): void {
		$admin = "<?php class Admin { const OPTION_KEY = 'wp_sudo_settings'; }";
		$upg   = "<?php class Upgrader { function f() { update_option( Admin::OPTION_KEY, array() ); } }";
		$this->assertSame( array( 'wp_sudo_settings' ), $this->scan( array( 'admin' => $admin, 'upg' => $upg ) ) );
	}

	/**
	 * Regression for the constant-collision false-green: two classes each define
	 * OPTION_KEY with different values and write via self::OPTION_KEY. Both options
	 * must be discovered as distinct names, not collapsed onto the first.
	 */
	public function test_duplicate_constant_names_do_not_collide(): void {
		$a = "<?php class A { const OPTION_KEY = 'wp_sudo_settings'; function f() { update_option( self::OPTION_KEY, 1 ); } }";
		$b = "<?php class B { const OPTION_KEY = 'wp_sudo_new_option'; function g() { update_option( self::OPTION_KEY, 1 ); } }";
		$this->assertSame( array( 'wp_sudo_new_option', 'wp_sudo_settings' ), $this->scan( array( 'a' => $a, 'b' => $b ) ) );
	}

	public function test_site_option_write_is_discovered(): void {
		$src = "<?php class A { const K = 'wp_sudo_settings'; function f() { update_site_option( self::K, 1 ); } }";
		$this->assertSame( array( 'wp_sudo_settings' ), $this->scan( array( 'a' => $src ) ) );
	}

	public function test_non_wp_sudo_options_are_filtered_out(): void {
		$this->assertSame( array(), $this->scan( array( 'a' => "<?php update_option( 'some_core_option', 1 );" ) ) );
	}

	public function test_method_call_is_not_matched(): void {
		$src = "<?php class A { function f( \$o ) { \$o->update_option( 'wp_sudo_x', 1 ); } }";
		$this->assertSame( array(), $this->scan( array( 'a' => $src ) ) );
	}

	public function test_option_like_string_literal_is_not_matched(): void {
		$this->assertSame( array(), $this->scan( array( 'a' => "<?php add_filter( 'pre_update_option_wp_sudo_settings', 'cb' );" ) ) );
	}

	public function test_network_option_write_fails_closed(): void {
		$this->expectException( \RuntimeException::class );
		$this->scan( array( 'a' => "<?php update_network_option( 1, 'wp_sudo_x', 1 );" ) );
	}

	public function test_unresolvable_option_argument_fails_closed(): void {
		$this->expectException( \RuntimeException::class );
		$this->scan( array( 'a' => "<?php class A { function f( \$dynamic ) { update_option( \$dynamic, 1 ); } }" ) );
	}

	public function test_unknown_constant_fails_closed(): void {
		$this->expectException( \RuntimeException::class );
		$this->scan( array( 'a' => "<?php class A { function f() { update_option( self::MYSTERY, 1 ); } }" ) );
	}

	public function test_doc_option_names_parses_only_the_persistent_options_row(): void {
		$md = "# Metrics\n\n"
			. "| Persistent options | 3 | `wp_sudo_settings` (`Admin::OPTION_KEY`), `wp_sudo_db_version`, `wp_sudo_activated` (literal). |\n"
			. "| Other | 1 | `wp_sudo_ignored_elsewhere` |\n\n"
			. "Per-user meta `_wp_sudo_expires` etc. are written only for active users.\n";
		$this->assertSame(
			array( 'wp_sudo_activated', 'wp_sudo_db_version', 'wp_sudo_settings' ),
			( new Persistent_Option_Scanner() )->doc_option_names( $md )
		);
	}

	/**
	 * Regression for the "documented names never verified" false-green: a stale doc
	 * that reverts to the removed governance_mode option must parse to a DIFFERENT set
	 * than the code discovers, so the gate's doc-vs-code comparison fails.
	 */
	public function test_doc_option_names_surfaces_a_stale_documented_name(): void {
		$stale      = "| Persistent options | 3 | `wp_sudo_settings`, `wp_sudo_db_version`, `wp_sudo_governance_mode` |\n";
		$documented = ( new Persistent_Option_Scanner() )->doc_option_names( $stale );
		$this->assertSame( array( 'wp_sudo_db_version', 'wp_sudo_governance_mode', 'wp_sudo_settings' ), $documented );
		$this->assertNotSame(
			array( 'wp_sudo_activated', 'wp_sudo_db_version', 'wp_sudo_settings' ),
			$documented,
			'A stale documented name set must differ from the live option set so verify:metrics fails.'
		);
	}

	public function test_scans_the_real_production_tree_to_exactly_the_three_live_options(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/wp-sudo.php', $root . '/uninstall.php' );
		foreach ( array( 'includes', 'mu-plugin', 'bridges' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() && 'php' === $f->getExtension() ) {
					$files[] = $f->getPathname();
				}
			}
		}
		$this->assertSame(
			array( 'wp_sudo_activated', 'wp_sudo_db_version', 'wp_sudo_settings' ),
			( new Persistent_Option_Scanner() )->scan_files( $files )
		);
	}
}
