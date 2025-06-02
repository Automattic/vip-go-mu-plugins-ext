<?php

use WP_UnitTestCase;
use function Automattic\VIP\Security\Utils\get_module_configs;

class ConfigsTest extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		
		if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
			define('VIP_SECURITY_BOOST_CONFIGS', [
				'module_configs' => '{"inactive_users":{"mode":"BLOCK","considered_inactive_after_days":90}}',
			]);
		}
	}
	
	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that parsing module_configs string returns the correct array
	 */
	public function test_parsing_module_configs_string_returns_array() {
		$configs        = [
			'module_configs' => '{"inactive_users":{"mode":"BLOCK","considered_inactive_after_days":90}}',
		];
		$module_configs = get_module_configs( 'inactive_users', $configs );

		$this->assertIsArray( $module_configs );
		$this->assertEquals( 'BLOCK', $module_configs['mode'] );
		$this->assertEquals( 90, $module_configs['considered_inactive_after_days'] );
	}

	/**
	 * Test that parsing a broken json string returns an empty array
	 */
	public function test_parsing_broken_json_string_returns_empty_array() {
		$configs        = [
			'module_configs' => '{////}',
		];
		$module_configs = get_module_configs( 'inactive_users', $configs );

		$this->assertIsArray( $module_configs );
		$this->assertEquals( [], $module_configs );
	}

	/**
	 * Test that parsing module_configs string returns the correct array
	 */
	public function test_parsing_module_configs_array_returns_array() {
		$configs        = [
			'module_configs' => [
				'inactive_users' => [
					'mode'                           => 'BLOCK',
					'considered_inactive_after_days' => 90,
				],
			],
		];
		$module_configs = get_module_configs( 'inactive_users', $configs );

		$this->assertIsArray( $module_configs );
		$this->assertEquals( 'BLOCK', $module_configs['mode'] );
		$this->assertEquals( 90, $module_configs['considered_inactive_after_days'] );
	}
}
