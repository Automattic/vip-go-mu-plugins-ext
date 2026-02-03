<?php

namespace Automattic\VIP\Salesforce\Agentforce\Utils;

use Automattic\VIP\Salesforce\Agentforce\Constants;

/**
 * Testable logger for testing purposes.
 * Inspired by https://github.com/Automattic/vip-go-mu-plugins/blob/develop/tests/logstash/class-testable-logger.php
 */
class Testable_Logger extends Logger {
	public static function clear_entries(): void {
		static::$logged_entries = [];
	}
	// @phpstan-ignore missingType.iterableValue
	public static function wp_debug_log( array $entry ): void {
		self::$logged_entries[] = $entry;
	}
	// @phpstan-ignore missingType.iterableValue
	public static function get_entries(): array {
		return static::$logged_entries;
	}

	public static function set_track_logs( bool $track_logs ): void {
		static::$track_logs = $track_logs;
	}
}
