<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests\Support;

use Automattic\ShareADraft\Clock;

/**
 * A clock stuck at a fixed instant, so expiry rules can be tested deterministically.
 */
final class FrozenClock implements Clock {
	private int $now;

	public function __construct( int $now ) {
		$this->now = $now;
	}

	public function now(): int {
		return $this->now;
	}

	/**
	 * Move the clock forward (or back, with a negative delta).
	 */
	public function advance( int $seconds ): void {
		$this->now += $seconds;
	}
}
