<?php
/**
 * Dead-code usage provider for WP_List_Table columns.
 *
 * @package Automattic\ShareADraft
 */

declare(strict_types = 1);

namespace Automattic\ShareADraft\PHPStan;

use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * WP_List_Table renders each column by calling `column_{$name}()` when a
 * subclass defines one, a call the dead-code detector cannot see.
 */
final class WpListTableUsageProvider extends ReflectionBasedMemberUsageProvider {

	public function shouldMarkMethodAsUsed( ReflectionMethod $method ): ?VirtualUsageData {
		if ( str_starts_with( $method->getName(), 'column_' ) && $method->getDeclaringClass()->isSubclassOf( 'WP_List_Table' ) ) {
			return VirtualUsageData::withNote( 'Called by WP_List_Table::single_row_columns()' );
		}

		return null;
	}
}
