<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use Automattic\VIP\Telemetry\Telemetry as VIP_Telemetry;
use WP_UnitTestCase;

/**
 * @covers \Automattic\ShareADraft\Telemetry
 */
class TelemetryTest extends WP_UnitTestCase {
	public function test_record_event_forwards_to_the_vip_client(): void {
		VIP_Telemetry::reset();

		Telemetry::get_instance()->record_event( 'unit_test_event', [ 'foo' => 'bar' ] );

		static::assertCount( 1, VIP_Telemetry::$events );
		static::assertSame( Telemetry::EVENT_PREFIX, VIP_Telemetry::$events[0]['prefix'] );
		static::assertSame( 'unit_test_event', VIP_Telemetry::$events[0]['event'] );
		static::assertSame( [ 'foo' => 'bar' ], VIP_Telemetry::$events[0]['properties'] );
	}

	public function test_recording_is_skipped_when_the_environment_opts_out(): void {
		VIP_Telemetry::reset();

		// The local dev-env (where the E2E suite runs) opts out via this filter,
		// so no synthetic events reach production Tracks.
		add_filter( 'shareadraft_record_telemetry', '__return_false' );
		Telemetry::get_instance()->record_event( 'unit_test_event', [ 'foo' => 'bar' ] );
		remove_filter( 'shareadraft_record_telemetry', '__return_false' );

		static::assertCount( 0, VIP_Telemetry::$events );
	}

	public function test_the_source_prefix_is_a_registered_tracks_source(): void {
		// The Tracks source (the token before the first underscore) must be a
		// single lowercase word that Tracks is registered to accept, or events
		// are silently discarded. Guard the exact value against regressions.
		// @phpstan-ignore staticMethod.alreadyNarrowedType (Pins the exact value against a rename.)
		static::assertSame( 'shareadraft_', Telemetry::EVENT_PREFIX );

		$source = strtok( Telemetry::EVENT_PREFIX, '_' );
		static::assertSame( 'shareadraft', $source );
		static::assertMatchesRegularExpression( '/^[a-z]+$/', $source, 'The Tracks source must be a single lowercase word with no underscores.' );
	}

	public function test_singleton(): void {
		static::assertSame( Telemetry::get_instance(), Telemetry::get_instance() );
	}

	public function test_global_properties_always_carry_the_plugin_version(): void {
		$properties = self::global_properties();

		static::assertSame( VIP_SHAREADRAFT_VERSION, $properties['plugin_version'] );

		// Off-platform (no VIP_GO_APP_ID) the app id is simply omitted rather
		// than sent as a null or zero.
		if ( ! defined( 'VIP_GO_APP_ID' ) ) {
			static::assertArrayNotHasKey( 'vip_app_id', $properties );
		}
	}

	/**
	 * Invoke the private factory that builds the client's global properties.
	 *
	 * @return array<string, mixed>
	 */
	private static function global_properties(): array {
		// Reflection ignores visibility on PHP 8.1+, so no setAccessible() call
		// (which is a no-op since 8.1 and deprecated in 8.5).
		$method = new \ReflectionMethod( Telemetry::class, 'global_properties' );

		/** @var array<string, mixed> $properties */
		$properties = $method->invoke( null );

		return $properties;
	}
}
