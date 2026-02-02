<?php

use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class ClassConfigsTest extends WP_UnitTestCase {
	public function tearDown(): void {
		Configs::flush_cache();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref  = new ReflectionClass( Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );
	}

	public function test_is_js_sdk_activated_defaults_to_false_when_missing(): void {
		$this->prime_configs_cache( [] );
		$this->assertFalse( Configs::is_js_sdk_activated() );
	}

	public function test_is_js_sdk_activated_true_when_truthy(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => true ] );
		$this->assertTrue( Configs::is_js_sdk_activated() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => 'true' ] );
		$this->assertTrue( Configs::is_js_sdk_activated() );
	}

	public function test_is_js_sdk_activated_false_when_falsey(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => false ] );
		$this->assertFalse( Configs::is_js_sdk_activated() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_activated' => '0' ] );
		$this->assertFalse( Configs::is_js_sdk_activated() );
	}

	public function test_get_js_sdk_url_returns_empty_when_missing(): void {
		$this->prime_configs_cache( [] );
		$this->assertSame( '', Configs::get_js_sdk_url() );
	}

	public function test_get_js_sdk_url_returns_string_when_present(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => 'https://example.local' ] );
		$this->assertSame( 'https://example.local', Configs::get_js_sdk_url() );
	}

	public function test_get_js_sdk_url_returns_empty_when_non_string(): void {
		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => [ 'https://example.local' ] ] );
		$this->assertSame( '', Configs::get_js_sdk_url() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => 123 ] );
		$this->assertSame( '', Configs::get_js_sdk_url() );

		$this->prime_configs_cache( [ 'agentforce_js_sdk_url' => null ] );
		$this->assertSame( '', Configs::get_js_sdk_url() );
	}
}
