<?php

use Automattic\VIP\Security\Utils\Logger;
use Automattic\VIP\Security\Utils\Testable_Logger;

/**
 * Tests for Automattic\VIP\Security\Utils\Logger
 */
class TestLogger extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Testable_Logger::clear_entries();
	}

	public function tearDown(): void {
		Testable_Logger::clear_entries();
		parent::tearDown();
	}

	/**
	 * Ensure that a warning is logged when a user is logged in.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_warning_logged_when_user_logged_in() {
		// Create and set current user.
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Call the method that should attach the set_current_user hook.
		Logger::warning_log_if_user_logged_in( 'test-feature', 'User logged in test', [ 'key' => 'value' ] );

		// Trigger the hook by setting current user.
		wp_set_current_user( $user_id );

		$entries = Testable_Logger::get_entries();

		$this->assertNotEmpty( $entries, 'Expected at least one log entry.' );
		$this->assertEquals( 'warning', $entries[0]['severity'] );
		$this->assertEquals( 'test-feature', $entries[0]['feature'] );
		$this->assertEquals( 'User logged in test', $entries[0]['message'] );
	}

	/**
	 * Ensure that no warning is logged when no user is logged in.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_warning_logged_when_user_not_logged_in() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		Logger::warning_log_if_user_logged_in( 'test-feature', 'No user logged in', [] );

		// Trigger the hook (user 0 is not logged in).
		wp_set_current_user( 0 );

		$entries = Testable_Logger::get_entries();

		$this->assertEmpty( $entries, 'No log entries should be present when user is not logged in.' );
	}
}
