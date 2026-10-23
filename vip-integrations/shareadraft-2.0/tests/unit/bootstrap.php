<?php
/**
 * Bootstrap for the fast domain unit suite.
 *
 * Deliberately minimal: it loads Composer's autoloader (which classmaps the
 * domain classes in inc/) plus the hand-written test doubles. No WordPress test
 * library is required, which is the whole point of keeping the domain free of
 * WordPress dependencies.
 */

declare(strict_types = 1);

require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/support/class-frozen-clock.php';
require_once __DIR__ . '/support/class-in-memory-token-repository.php';
