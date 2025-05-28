<?php

use Automattic\VIP\Security\SessionControl\Session_Control;

use PHPUnit\Framework\Error\Warning;

class SessionControlTest extends WP_UnitTestCase {
	private $user_id;

	public function setUp(): void {
		parent::setUp();
		// Create a test user
		$this->user_id = self::factory()->user->create([
			'role' => 'editor',
		]);
	}

	public function tearDown(): void {
		wp_delete_user( $this->user_id );
		parent::tearDown();
	}

	/**
	 * Test that the module doesn't modify expiration when set to default
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_default_expiration_not_modified() {
		// Define test configuration with default expiration
		define('VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'session-control' => [
					'expiration_days' => Session_Control::DEFAULT_VALUE,
				],
			],
		]);

		// Initialize the module
		Session_Control::init();

		// Default WordPress expiration is 2 days (172800 seconds) without "remember me"
		$default_expiration = 2 * DAY_IN_SECONDS;

		// Test without "remember me"
		$result = Session_Control::set_auth_cookie_expiration( $default_expiration, $this->user_id, false );
		$this->assertEquals( $default_expiration, $result, 'Default expiration should not be modified when "remember me" is not checked' );

		// Default WordPress expiration is 14 days (1209600 seconds) with "remember me"
		$default_remember_expiration = 14 * DAY_IN_SECONDS;

		// Test with "remember me"
		$result = Session_Control::set_auth_cookie_expiration( $default_remember_expiration, $this->user_id, true );
		$this->assertEquals( $default_remember_expiration, $result, 'Default expiration should not be modified when set to "default"' );
	}

	/**
	 * Test that the module modifies expiration when set to a valid number of days
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_custom_expiration_applied_with_remember_me() {
		// Define test configuration with custom expiration
		$test_days = 7;
		define('VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'session-control' => [
					'expiration_days' => $test_days,
				],
			],
		]);

		// Initialize the module
		Session_Control::init();

		// Default WordPress expiration is 2 days (172800 seconds) without "remember me"
		$default_expiration = 2 * DAY_IN_SECONDS;

		// Test without "remember me" - should not be modified
		$result = Session_Control::set_auth_cookie_expiration( $default_expiration, $this->user_id, false );
		$this->assertEquals( $default_expiration, $result, 'Expiration should not be modified when "remember me" is not checked' );

		// Default WordPress expiration is 14 days (1209600 seconds) with "remember me"
		$default_remember_expiration = 14 * DAY_IN_SECONDS;

		// Test with "remember me" - should be modified to our custom value
		$result = Session_Control::set_auth_cookie_expiration( $default_remember_expiration, $this->user_id, true );

		$expected = $test_days * DAY_IN_SECONDS;

		$this->assertEquals( $expected, $result, 'Custom expiration should be applied when "remember me" is checked' );
	}


	/**
	 * Test that the module validates expiration days and falls back to default if invalid
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_expiration_days_validation_too_many_days() {
		// Test with a value below the minimum (31 day)
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'session-control' => [
					'expiration_days' => 31,
				],
			],
		]);

		$this->validate_invalid_expiration_days();
	}

	/**
	 * Test that the module validates expiration days and falls back to default if invalid
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_expiration_days_validation_too_few_days() {
		// Test with a value below the minimum (1 day)
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'session-control' => [
					'expiration_days' => 0,
				],
			],
		]);

		$this->validate_invalid_expiration_days();
	}


	/**
	 * Test that the module validates expiration days and falls back to default if invalid
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_expiration_days_validation_invalid_value() {
		// Test with a value below the minimum (1 day)
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'session-control' => [
					'expiration_days' => 'invalid',
				],
			],
		]);

		$this->validate_invalid_expiration_days();
	}

	/**
	 * Helper method to validate that invalid expiration days are handled correctly
	 */
	private function validate_invalid_expiration_days() {
		$this->expectException( Warning::class );
		// Initialize the module
		Session_Control::init();

		// Default WordPress expiration
		$default_expiration = 14 * DAY_IN_SECONDS;

		// Test with "remember me" - should not be modified due to invalid config
		$result = Session_Control::set_auth_cookie_expiration( $default_expiration, $this->user_id, true );
		$this->assertEquals( $default_expiration, $result, 'Expiration should not be modified when config is invalid' );
	}
}
