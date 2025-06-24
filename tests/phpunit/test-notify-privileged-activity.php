<?php
use Automattic\VIP\Security\PrivilegedActivityNotifier\Notify_Privileged_Activity;
use Automattic\VIP\Security\Email\Email;

class TestNotifyPrivilegedActivity extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		Email::reset_last_call_args_for_test();
	}

	protected function tearDown(): void {
		parent::tearDown();
		delete_option( 'admin_email' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_adds_action() {
		Notify_Privileged_Activity::init();
		if ( is_multisite() ) {
			$this->assertNotFalse( has_action( 'grant_super_admin', [ Notify_Privileged_Activity::class, 'notify_user_granted_super_admin' ] ), 'Action grant_super_admin was not added.' );
			$this->assertNotFalse( has_action( 'user_register', [ Notify_Privileged_Activity::class, 'notify_admin_user_creation' ] ), 'Action user_register was not added.' );
		} else {
			$this->assertNotFalse( has_action( 'user_register', [ Notify_Privileged_Activity::class, 'notify_admin_user_creation' ] ), 'Action user_register was not added.' );
			$this->assertFalse( has_action( 'grant_super_admin', [ Notify_Privileged_Activity::class, 'notify_user_granted_super_admin' ] ), 'Action grant_super_admin was added.' );
		}
		$this->assertNotFalse( has_action( 'set_user_role', [ Notify_Privileged_Activity::class, 'notify_user_promoted_to_admin' ] ), 'Action set_user_role was not added.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_invalid_user_id() {
		Notify_Privileged_Activity::notify_admin_user_creation( 99999 );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for invalid user ID.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_user_not_administrator() {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Notify_Privileged_Activity::notify_admin_user_creation( $user_id );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for non-admin user.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_admin_user_creation_admin_email_empty() {
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
		$user_data       = self::factory()->user->create_and_get([
			'role'       => 'administrator',
			'user_login' => 'newadmin',
			'user_email' => 'newadmin@example.com',
		]);
		$user_id         = $user_data->ID;
		$admin_email_val = 'admin@example.com';
		update_option( 'admin_email', $admin_email_val );
		$site_name = get_bloginfo( 'name' );

		$expected_subject       = sprintf( '[%s] New Administrator Added', $site_name );
		$expected_template_data = [
			'user_login'  => $user_data->user_login,
			'user_email'  => $user_data->user_email,
			'user_role'   => 'Administrator',
			'admin_url'   => admin_url(),
			'email_title' => 'New Administrator Added',
		];
		if ( is_multisite() ) {
			$expected_template_data['network_admin_url'] = network_admin_url();
		}

		Notify_Privileged_Activity::notify_admin_user_creation( $user_id );

		$this->assertNotNull( Email::$last_call_args_for_test, 'Email::send was not called.' );
		$this->assertEquals( $user_id, Email::$last_call_args_for_test['user_id'] );
		$this->assertEquals( $admin_email_val, Email::$last_call_args_for_test['email_address'] );
		$this->assertEquals( $expected_subject, Email::$last_call_args_for_test['subject'] );
		$this->assertEquals( 'privileged-user-created', Email::$last_call_args_for_test['template_id'] );
		$this->assertEquals( $expected_template_data, Email::$last_call_args_for_test['template_data'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_user_promoted_to_admin_invalid_user_id() {
		Notify_Privileged_Activity::notify_user_promoted_to_admin( 99999, 'administrator', [ 'editor' ] );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for invalid user ID.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_user_promoted_to_admin_not_promoted_to_admin() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Notify_Privileged_Activity::notify_user_promoted_to_admin( $user_id, 'editor', [ 'subscriber' ] );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for non-admin promotion.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_user_promoted_to_admin_already_admin() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Notify_Privileged_Activity::notify_user_promoted_to_admin( $user_id, 'administrator', [ 'administrator', 'editor' ] );
		$this->assertNull( Email::$last_call_args_for_test, 'Email::send was called unexpectedly for user who was already an admin.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_user_promoted_to_admin_sends_email_successfully() {
		$user_data       = self::factory()->user->create_and_get( [
			'role'       => 'editor',
			'user_login' => 'promotedadmin',
			'user_email' => 'promotedadmin@example.com',
		] );
		$user_id         = $user_data->ID;
		$admin_email_val = 'admin@example.com';
		update_option( 'admin_email', $admin_email_val );
		$site_name = get_bloginfo( 'name' );

		$expected_subject       = sprintf( '[%s] User Promoted to Administrator', $site_name );
		$expected_template_data = [
			'user_login'  => $user_data->user_login,
			'user_email'  => $user_data->user_email,
			'user_role'   => 'Administrator',
			'admin_url'   => admin_url(),
			'email_title' => 'User Promoted to Administrator',
		];
		if ( is_multisite() ) {
			$expected_template_data['network_admin_url'] = network_admin_url();
		}

		Notify_Privileged_Activity::notify_user_promoted_to_admin( $user_id, 'administrator', [ 'editor' ] );

		$this->assertNotNull( Email::$last_call_args_for_test, 'Email::send was not called.' );
		$this->assertEquals( $user_id, Email::$last_call_args_for_test['user_id'] );
		$this->assertEquals( $admin_email_val, Email::$last_call_args_for_test['email_address'] );
		$this->assertEquals( $expected_subject, Email::$last_call_args_for_test['subject'] );
		$this->assertEquals( 'privileged-user-promoted', Email::$last_call_args_for_test['template_id'] );
		$this->assertEquals( $expected_template_data, Email::$last_call_args_for_test['template_data'] );
	}


	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notify_user_granted_super_admin_sends_email_successfully() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required for this test.' );
		}
		$user_data       = self::factory()->user->create_and_get( [
			'role'       => 'editor',
			'user_login' => 'promotedadmin',
			'user_email' => 'promotedadmin@example.com',
		] );
		$user_id         = $user_data->ID;
		$admin_email_val = 'admin@example.com';
		update_option( 'admin_email', $admin_email_val );
		$site_name = get_network()->site_name;

		$expected_subject       = sprintf( '[%s] User Granted Super Admin Privileges', $site_name );
		$expected_template_data = [
			'user_login'        => $user_data->user_login,
			'user_email'        => $user_data->user_email,
			'user_role'         => 'Super Administrator',
			'admin_url'         => admin_url(),
			'network_admin_url' => network_admin_url(),
			'email_title'       => 'User Granted Super Admin Privileges',
		];

		Notify_Privileged_Activity::notify_user_granted_super_admin( $user_id );
		$this->assertEquals( $user_id, Email::$last_call_args_for_test['user_id'] );
		$this->assertEquals( $admin_email_val, Email::$last_call_args_for_test['email_address'] );
		$this->assertEquals( $expected_subject, Email::$last_call_args_for_test['subject'] );
		$this->assertEquals( 'privileged-super-admin-granted', Email::$last_call_args_for_test['template_id'] );
		$this->assertEquals( $expected_template_data, Email::$last_call_args_for_test['template_data'] );
	}
}
