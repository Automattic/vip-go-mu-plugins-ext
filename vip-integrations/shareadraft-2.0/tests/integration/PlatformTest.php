<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * @covers \Automattic\ShareADraft\Platform
 */
class PlatformTest extends WP_UnitTestCase {
	public function tear_down(): void {
		remove_all_filters( 'shareadraft_is_vip_platform' );
		parent::tear_down();
	}

	public function test_it_is_not_vip_without_a_platform_constant(): void {
		if ( defined( 'VIP_GO_APP_ENVIRONMENT' ) || defined( 'WPCOM_IS_VIP_ENV' ) ) {
			static::markTestSkipped( 'A VIP platform constant is defined in this environment.' );
		}

		static::assertFalse( Platform::is_vip() );
	}

	public function test_the_verdict_is_filterable(): void {
		add_filter( 'shareadraft_is_vip_platform', '__return_true' );
		static::assertTrue( Platform::is_vip() );

		remove_all_filters( 'shareadraft_is_vip_platform' );

		add_filter( 'shareadraft_is_vip_platform', '__return_false' );
		static::assertFalse( Platform::is_vip() );
	}

	/**
	 * A filter returning something odd must still yield a boolean, since callers
	 * branch on it directly.
	 */
	public function test_a_non_boolean_filter_return_is_cast(): void {
		add_filter( 'shareadraft_is_vip_platform', static fn (): string => 'yes' );

		static::assertTrue( Platform::is_vip() );
	}
}
