<?php

use Automattic\VIP\Security\MFAUsers\Forced_MFA_Users;
use Automattic\VIP\Security\Utils\Testable_Logger;

class Test_Forced_MFA_Users extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		add_action( 'wpcom_vip_is_two_factor_local_testing', '__return_true' ); // Tell the two-factor plugin we're in local testing
		// Loads the Two_Factor_Core class (required for the wpcom_vip_should_force_two_factor to work)
		require_once WPVIP_MU_PLUGIN_DIR . '/shared-plugins/two-factor/two-factor.php';
	}

	public function tearDown(): void {
		// Remove actions/filters added by the class
		remove_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] );

		// Reset the static properties using reflection
		if ( class_exists( Forced_MFA_Users::class ) ) {
			try {
				$reflection = new ReflectionClass( Forced_MFA_Users::class );
				if ( $reflection->hasProperty( 'roles' ) ) {
					$roles_prop = $reflection->getProperty( 'roles' );
					$roles_prop->setAccessible( true );
					$roles_prop->setValue( null, null ); // Reset to initial state
				}
				if ( $reflection->hasProperty( 'capabilities' ) ) {
					$capabilities_prop = $reflection->getProperty( 'capabilities' );
					$capabilities_prop->setAccessible( true );
					$capabilities_prop->setValue( null, null ); // Reset to initial state
				}
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			} catch ( ReflectionException $e ) {

			}
		}

		// Reset current user
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * This is a dependance for our project. While we should not break the site if this function is missing, we should at least warn if it is missing.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ensure_wpcom_vip_should_force_two_factor_exists() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => 'administrator' ],
			],
		] );
		$this->assertTrue( function_exists( 'wpcom_vip_should_force_two_factor' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_adds_action_when_config_defined() {
		$this->assertFalse( has_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] ) );
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => 'administrator' ],
			],
		] );

		Forced_MFA_Users::init();
		$this->assertNotFalse( has_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_does_not_add_action_when_config_not_defined() {
		// Constant is NOT defined in this separate process
		$this->assertFalse( defined( 'VIP_SECURITY_BOOST_CONFIGS' ) );
		Forced_MFA_Users::init();
		$this->assertFalse( has_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] ) );
	}

	/**
	 * Helper to set up user and call the filter method.
	 * Assumes the calling test method has already set up the constant and called init().
	 */
	private function setup_user_and_filter( $user_role_or_roles ) {
		$user_id = self::factory()->user->create( [ 'role' => is_string( $user_role_or_roles ) ? $user_role_or_roles : 'subscriber' ] );
		wp_set_current_user( $user_id );

		// Manually trigger the action hook's callback for testing isolation
		Forced_MFA_Users::maybe_enforce_two_factor();
	}


	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_no_role_set() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => [] ],
			],
		] );
		Forced_MFA_Users::init(); // Run init to set the static property

		$this->setup_user_and_filter( 'administrator' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when no role is set.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_single_role_user_has_role() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => 'administrator' ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'administrator' );
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has the single required role.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_single_role_user_lacks_role() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => 'administrator' ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'subscriber' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when user lacks the single required role.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_array_role_user_has_one_role() {
		$roles = [ 'administrator', 'editor' ];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => $roles ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'editor' );
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has one of the required roles.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_array_role_user_lacks_all_roles() {
		$roles = [ 'administrator', 'editor' ];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => $roles ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'author' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when user lacks all required roles.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_empty_role_array() {
		$roles = [];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => $roles ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'administrator' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true with an empty role array.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_invalid_role_types_in_array_user_has_valid_role() {
		$roles = [ 'editor', 123, [], new stdClass() ];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => $roles ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'editor' );
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has a valid role even if other array items are invalid.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_invalid_role_types_in_array_only_invalid() {
		$roles = [ 123, [], new stdClass() ];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'roles' => $roles ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'administrator' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when all roles in array are invalid types.' );
	}

	/**
	 * We want to test that under a normal condition, if a user has the required capability, the filter returns false because
	 * wpcom_vip_should_force_two_factor returns false.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_skip_user_if_wpcom_vip_should_force_two_factor_returns_false() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => 'edit_posts' ],
			],
		] );
		Forced_MFA_Users::init();
		add_filter( 'wpcom_vip_is_user_using_two_factor', '__return_true' ); // we're using this to change the behavior of the wpcom_vip_should_force_two_factor to return false.

		$this->setup_user_and_filter( 'administrator' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true if wpcom_vip_should_force_two_factor returns false even if the user has the required capability.' );
	}

	/**
	 * Test that capabilities take priority over roles
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_capabilities_priority() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 
					'capabilities' => 'manage_options',
					'roles'        => 'subscriber', // This should be ignored
				],
			],
		] );
		Forced_MFA_Users::init();

		// Create administrator user (has manage_options capability but not subscriber role)
		$this->setup_user_and_filter( 'administrator' );
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has the required capability.' );
	}

	/**
	 * Test single capability enforcement
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_single_capability() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => 'edit_posts' ],
			],
		] );
		Forced_MFA_Users::init();

		// Administrator has edit_posts capability
		$this->setup_user_and_filter( 'administrator' );
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has the single required capability.' );
	}

	/**
	 * Test user without required capability
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_user_lacks_capability() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => 'manage_options' ],
			],
		] );
		Forced_MFA_Users::init();

		// Subscriber doesn't have manage_options capability
		$this->setup_user_and_filter( 'subscriber' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when user lacks the required capability.' );
	}

	/**
	 * Test multiple capabilities - user has one
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_array_capabilities_user_has_one() {
		$capabilities = [ 'manage_options', 'edit_themes' ];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => $capabilities ],
			],
		] );
		Forced_MFA_Users::init();

		// Editor has edit_posts but not manage_options or edit_themes
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$user    = get_user_by( 'id', $user_id );
		// Grant manage_options capability to editor
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $user_id );
		
		Forced_MFA_Users::maybe_enforce_two_factor();
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has one of the required capabilities.' );
	}

	/**
	 * Test empty capabilities array falls back to roles
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_empty_capabilities_uses_roles() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 
					'capabilities' => [],
					'roles'        => 'administrator',
				],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'administrator' );
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should use roles when capabilities array is empty.' );
	}

	/**
	 * Test both capabilities and roles empty
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_does_not_add_action_when_both_capabilities_and_roles_empty() {
		$this->assertFalse( has_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] ) );
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 
					'capabilities' => [],
					'roles'        => [],
				],
			],
		] );

		Forced_MFA_Users::init();
		$this->assertFalse( has_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] ) );
	}
}
