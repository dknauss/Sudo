<?php
/**
 * Tests for the inline-capture alert demo companion.
 *
 * The demo lives at bin/demo/wp-sudo-alert-inline-demo.php. It consumes the
 * critical-alert bridge's additive `wp_sudo_critical_alert_dispatched` action,
 * buffers each composed alert, and renders it as an admin notice — the concrete
 * realization of the bridge's documented "capture for inline display where
 * outbound network is unavailable" use case (e.g. WordPress Playground).
 *
 * @package WP_Sudo
 */

namespace WP_Sudo\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Sudo\Tests\TestCase;

/**
 * @coversNothing Procedural demo file.
 */
class AlertInlineDemoTest extends TestCase {

	/**
	 * In-memory transient store backing the demo buffer.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Install baseline stubs the demo depends on, then load it.
	 *
	 * @param array<string, mixed> $overrides 'can' => bool for current_user_can.
	 * @return void
	 */
	private function boot( array $overrides = array() ): void {
		$this->transients = array();

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( (bool) ( $overrides['can'] ?? true ) );
		Functions\when( 'esc_html' )->alias(
			static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES )
		);

		$store = &$this->transients;
		Functions\when( 'get_transient' )->alias(
			static function ( string $k ) use ( &$store ) {
				return $store[ $k ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $k, $v ) use ( &$store ): bool {
				$store[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $k ) use ( &$store ): bool {
				unset( $store[ $k ] );
				return true;
			}
		);

		include __DIR__ . '/../../bin/demo/wp-sudo-alert-inline-demo.php';
	}

	public function test_registers_capture_and_render_hooks(): void {
		$this->transients = array();
		$hooks            = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): bool {
				$hooks[] = $hook;
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		include __DIR__ . '/../../bin/demo/wp-sudo-alert-inline-demo.php';

		$this->assertContains( 'wp_sudo_critical_alert_dispatched', $hooks );
		$this->assertContains( 'admin_notices', $hooks );
	}

	public function test_capture_appends_event_to_buffer(): void {
		$this->boot();

		wp_sudo_alert_inline_demo_capture(
			array( 'subject' => 'Reauthentication lockout', 'body' => 'User #5 locked out', 'scope' => 'site' )
		);

		$buffer = $this->transients[ WP_SUDO_ALERT_INLINE_DEMO_KEY ] ?? array();
		$this->assertCount( 1, $buffer );
		$this->assertSame( 'Reauthentication lockout', $buffer[0]['subject'] );
		$this->assertSame( 'User #5 locked out', $buffer[0]['body'] );
	}

	public function test_capture_trims_buffer_to_cap_keeping_newest(): void {
		$this->boot();

		$total = WP_SUDO_ALERT_INLINE_DEMO_CAP + 2;
		for ( $i = 1; $i <= $total; $i++ ) {
			wp_sudo_alert_inline_demo_capture(
				array( 'subject' => 'Event ' . $i, 'body' => 'b', 'scope' => 'site' )
			);
		}

		$buffer = $this->transients[ WP_SUDO_ALERT_INLINE_DEMO_KEY ] ?? array();
		$this->assertCount( WP_SUDO_ALERT_INLINE_DEMO_CAP, $buffer );
		// FIFO: the two oldest were dropped, so the first surviving entry is #3.
		$this->assertSame( 'Event 3', $buffer[0]['subject'] );
		$this->assertSame( 'Event ' . $total, $buffer[ WP_SUDO_ALERT_INLINE_DEMO_CAP - 1 ]['subject'] );
	}

	public function test_render_escapes_output_and_drains_buffer(): void {
		$this->boot();
		$this->transients[ WP_SUDO_ALERT_INLINE_DEMO_KEY ] = array(
			array(
				'subject' => 'Reauthentication lockout',
				'body'    => 'IP <script>alert(1)</script> from 203.0.113.9',
				'scope'   => 'site',
				'time'    => '2026-07-06T00:00:00+00:00',
			),
		);

		ob_start();
		wp_sudo_alert_inline_demo_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Reauthentication lockout', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		// Show-once: the buffer is drained after rendering.
		$this->assertArrayNotHasKey( WP_SUDO_ALERT_INLINE_DEMO_KEY, $this->transients );
	}

	public function test_render_noop_without_manage_capability(): void {
		$this->boot( array( 'can' => false ) );
		$this->transients[ WP_SUDO_ALERT_INLINE_DEMO_KEY ] = array(
			array( 'subject' => 's', 'body' => 'b', 'scope' => 'site', 'time' => 't' ),
		);

		ob_start();
		wp_sudo_alert_inline_demo_render();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
		// Buffer is untouched for a user who cannot see it.
		$this->assertArrayHasKey( WP_SUDO_ALERT_INLINE_DEMO_KEY, $this->transients );
	}

	public function test_render_noop_when_buffer_empty(): void {
		$this->boot();

		ob_start();
		wp_sudo_alert_inline_demo_render();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}
}
