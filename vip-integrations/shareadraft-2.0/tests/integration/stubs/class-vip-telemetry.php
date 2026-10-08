<?php
/**
 * Test double for the VIP Telemetry API. In real environments this class is
 * shipped by the platform's MU plugins; unit tests load this stub instead so
 * the helper's forwarding behavior can be asserted.
 */

namespace Automattic\VIP\Telemetry;

class Telemetry {
	/** @var list<array{prefix: string, event: string, properties: array<string, mixed>}> */
	public static $events = [];

	private string $prefix;

	/**
	 * Forget recorded events. A method rather than a bare assignment in each
	 * test, so static analysis does not carry "empty" past the code under test.
	 */
	public static function reset(): void {
		self::$events = [];
	}

	/**
	 * @param array<string, mixed> $global_properties
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature mirrors the real VIP Telemetry API.
	public function __construct( string $prefix = '', array $global_properties = [] ) {
		$this->prefix = $prefix;
	}

	/**
	 * @param array<string, mixed> $properties
	 */
	public function record_event( string $event_name, array $properties = [] ): bool {
		self::$events[] = [
			'prefix'     => $this->prefix,
			'event'      => $event_name,
			'properties' => $properties,
		];

		return true;
	}
}
