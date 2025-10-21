<?php

use Automattic\VIP\Security\Utils\Configs;

class ClassConfigsTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
			define('VIP_SECURITY_BOOST_CONFIGS', [
				'module_configs' => '{"test_module":{"key":"value"}}',
			]);
		}
		// Polyfill config functions if missing.
		if ( ! function_exists( '\Automattic\VIP\Security\Utils\get_all_module_configs' ) ) {
			function get_all_module_configs() {
				return apply_filters( 'vip_security_utils_get_all_module_configs', [] );
			}
		}
		if ( ! function_exists( '\Automattic\VIP\Security\Utils\parse_module_configs' ) ) {
			function parse_module_configs( $configs ) {
				return apply_filters( 'vip_security_utils_parse_module_configs', $configs );
			}
		}
		$this->resetConfigsCache();
	}

	protected function tearDown(): void {
		remove_all_filters( 'vip_security_utils_get_all_module_configs' );
		remove_all_filters( 'vip_security_utils_parse_module_configs' );
		$this->resetConfigsCache();
		parent::tearDown();
	}

	private function resetConfigsCache(): void {
		$reflection = new \ReflectionClass( Configs::class );
		$property   = $reflection->getProperty( 'cached_module_configs' );
		$property->setAccessible( true );
		$property->setValue( null );
	}

	public function test_returns_config_for_existing_module(): void {
		add_filter('vip_security_utils_get_all_module_configs', fn() => [
			'test_module' => [ 'key' => 'value' ],
		]);
		add_filter( 'vip_security_utils_parse_module_configs', fn( $configs ) => $configs, 10, 1 );

		$this->assertEquals(
			[ 'key' => 'value' ],
			Configs::get_module_configs( 'test_module' )
		);
	}

	public function test_returns_empty_for_nonexistent_module(): void {
		add_filter( 'vip_security_utils_get_all_module_configs', fn() => [] );
		add_filter( 'vip_security_utils_parse_module_configs', fn( $configs ) => $configs, 10, 1 );

		$this->assertEquals( [], Configs::get_module_configs( 'missing_module' ) );
	}

	public function test_returns_empty_when_parse_returns_non_array(): void {
		add_filter( 'vip_security_utils_get_all_module_configs', fn() => [ 'foo' => 'bar' ] );
		add_filter( 'vip_security_utils_parse_module_configs', fn() => false, 10, 1 );

		$this->assertEquals( [], Configs::get_module_configs( 'foo' ) );
	}

	public function test_returns_empty_if_module_config_not_array(): void {
		add_filter( 'vip_security_utils_get_all_module_configs', fn() => [ 'bad' => 'string_value' ] );
		add_filter( 'vip_security_utils_parse_module_configs', fn() => [ 'bad' => 'string_value' ], 10, 1 );

		$this->assertEquals( [], Configs::get_module_configs( 'bad' ) );
	}
}
