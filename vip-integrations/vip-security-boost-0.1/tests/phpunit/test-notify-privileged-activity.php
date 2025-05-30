<?php
namespace Automattic\VIP\Security\PrivilegedActivityNotifier\Tests;

use WP_UnitTestCase;
use Automattic\VIP\Security\PrivilegedActivityNotifier\Notify_Privileged_Activity;
use Automattic\VIP\Security\Email\Email;

class TestNotifyPrivilegedActivity extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		if ( class_exists( Email::class ) && property_exists( Email::class, 'last_call_args_for_test' ) ) {
			Email::$last_call_args_for_test = null;
		}
	}

	protected function tearDown(): void {
		parent::tearDown();
		if ( class_exists( Email::class ) && property_exists( Email::class, 'last_call_args_for_test' ) ) {
			Email::$last_call_args_for_test = null;
		}
		delete_option( 'admin_email' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_adds_action() {
		Notify_Privileged_Activity::init();
		$this->assertNotFalse( has_action( 'user_register', [ Notify_Privileged_Activity::class, 'notify_admin_user_creation' ] ), 'Action user_register was not added.' );
	}

	private function check_email_spy_property_exists() {
		if ( ! ( class_exists( Email::class ) && property_exists( Email::class, 'last_call_args_for_test' ) ) ) {
			$this->markTestSkipped(
				'Email class does not have $last_call_args_for_test property for spying on Email::send().'
			);
			return false;
		}
		return true;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_invalid_user_id() {
		if ( ! $this->check_email_spy_property_exists() ) {
			return;
		}
		Notify_Privileged_Activity::notify_admin_user_creation( 99999 );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for invalid user ID.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_user_not_administrator() {
		if ( ! $this->check_email_spy_property_exists() ) {
			return;
		}
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Notify_Privileged_Activity::notify_admin_user_creation( $user_id );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for non-admin user.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_admin_email_empty() {
		if ( ! $this->check_email_spy_property_exists() ) {
			return;
		}
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_option( 'admin_email', '' );

		Notify_Privileged_Activity::notify_admin_user_creation( $user_id );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly when admin_email is empty.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_admin_email_invalid() {
		if ( ! $this->check_email_spy_property_exists() ) {
			return;
		}
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_option( 'admin_email', 'not-an-email' );

		Notify_Privileged_Activity::notify_admin_user_creation( $user_id );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for invalid admin_email.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_sends_email_successfully() {
		if ( ! $this->check_email_spy_property_exists() ) {
			return;
		}

		$user_data       = self::factory()->user->create_and_get([
			'role'       => 'administrator',
			'user_login' => 'newadmin',
			'user_email' => 'newadmin@example.com',
		]);
		$user_id         = $user_data->ID;
		$admin_email_val = 'admin@example.com';
		update_option( 'admin_email', $admin_email_val );
		$site_name = get_bloginfo( 'name' );

		$expected_subject       = sprintf( '[%s] New Administrator User Created', $site_name );
		$expected_template_data = [
			'user_login' => $user_data->user_login,
			'user_email' => $user_data->user_email,
			'user_role'  => 'Administrator',
		];
		
		Notify_Privileged_Activity::notify_admin_user_creation( $user_id );

		$this->assertNotNull( Email::$last_call_args_for_test, 'Email::send was not called.' );
		if ( Email::$last_call_args_for_test ) {
			$this->assertEquals( $user_id, Email::$last_call_args_for_test['user_id'] );
			$this->assertEquals( $admin_email_val, Email::$last_call_args_for_test['email_address'] );
			$this->assertEquals( $expected_subject, Email::$last_call_args_for_test['subject'] );
			$this->assertEquals( 'privileged-user-created', Email::$last_call_args_for_test['template_id'] );
			$this->assertEquals( $expected_template_data, Email::$last_call_args_for_test['template_data'] );
		}
	}
} 
