<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * @covers \Automattic\ShareADraft\Config
 */
class ConfigTest extends WP_UnitTestCase {
	private const FIXTURES_DIR = __DIR__ . '/../../fixtures';

	public function test_fully_configured_fixture(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-valid.php' );

		static::assertTrue( $config->is_available() );
		static::assertTrue( $config->is_ready() );
		static::assertSame( [], $config->missing_fields() );
		static::assertSame( 604800, $config->get( 'dead_link_grace_period' ) );
		static::assertSame( '', $config->get( 'ip_allowlist' ) );
	}

	/**
	 * The platform defines the constant to say "this integration is on". Nothing
	 * has to be in it: an empty array is a complete configuration, not a
	 * half-finished one, and every value it can carry has a default.
	 */
	public function test_minimal_fixture_is_ready_and_unset_keys_fall_back(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-minimal.php' );

		static::assertTrue( $config->is_available() );
		static::assertTrue( $config->is_ready() );
		static::assertSame( 'fallback', $config->get( 'dead_link_grace_period', 'fallback' ) );
		static::assertNull( $config->get( 'dead_link_grace_period' ) );
	}

	/**
	 * A field opened in the Dashboard but left blank still parses. Reading it
	 * back unchanged is the point: judging whether a value is usable belongs to
	 * whoever consumes it, not to Config.
	 */
	public function test_incomplete_fixture_is_available_and_reads_back_the_blank(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-incomplete.php' );

		static::assertTrue( $config->is_available() );
		static::assertTrue( $config->is_ready() );
		static::assertSame( '', $config->get( 'dead_link_grace_period' ) );
	}

	public function test_invalid_fixture_is_not_available(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-invalid.php' );

		static::assertFalse( $config->is_available() );
		static::assertFalse( $config->is_ready() );
		static::assertSame( 'default', $config->get( 'anything', 'default' ) );
	}

	public function test_undefined_constant_is_not_available(): void {
		$config = new Config( null );

		static::assertFalse( $config->is_available() );
		static::assertFalse( $config->is_ready() );
	}

	/**
	 * Values the platform may add later must survive the round trip, even though
	 * nothing declares or requires them yet.
	 */
	public function test_it_reads_values_it_does_not_declare(): void {
		$config = new Config( [ 'ip_allowlist' => [ '203.0.113.4' ] ] );

		static::assertTrue( $config->is_ready() );
		static::assertSame( [ '203.0.113.4' ], $config->get( 'ip_allowlist' ) );
	}

	public function test_singleton_reads_the_constant(): void {
		// bootstrap.php defines the constant from the valid fixture.
		static::assertTrue( Config::get_instance()->is_ready() );
		static::assertSame( Config::get_instance(), Config::get_instance() );
	}

	public function test_for_display_stringifies_values(): void {
		$config = new Config( [
			'endpoint' => 'https://api.vendor.example',
			'retries'  => 3,
			'flags'    => [ 'a', 'b' ],
		] );

		$display = $config->for_display();

		static::assertSame( 'https://api.vendor.example', $display['endpoint'] );
		static::assertSame( '3', $display['retries'] );
		static::assertSame( '["a","b"]', $display['flags'] );
	}
}
