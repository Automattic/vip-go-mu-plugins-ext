<?php

use Automattic\VIP\Security\MFAUsers\Forced_MFA_Users;

class Test_Forced_MFA_Users extends WP_UnitTestCase {
	public function tearDown(): void {
		// Remove actions/filters added by the class
		remove_action( 'set_current_user', [ Forced_MFA_Users::class, 'filter_user_capabilities' ] );

		// Reset the static capability property using reflection
		if ( class_exists( Forced_MFA_Users::class ) ) {
			try {
				$reflection = new ReflectionClass( Forced_MFA_Users::class );
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_adds_action_when_config_defined() {
		$this->assertFalse( has_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] ) );
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => 'manage_options' ],
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
		// Expect the warning from get_all_module_configs() when the constant is missing
		$this->expectWarning();
		$this->expectWarningMessageMatches( '/VIP_SECURITY_BOOST_CONFIGS is not defined/' );

		// Constant is NOT defined in this separate process
		$this->assertFalse( defined( 'VIP_SECURITY_BOOST_CONFIGS' ) );
		Forced_MFA_Users::init();
		$this->assertFalse( has_action( 'set_current_user', [ Forced_MFA_Users::class, 'maybe_enforce_two_factor' ] ) );
	}

	/**
	 * Helper to set up user and call the filter method.
	 * Assumes the calling test method has already set up the constant and called init().
	 */
	private function setup_user_and_filter( $user_role_or_caps ) {
		$user_id = self::factory()->user->create( [ 'role' => is_string( $user_role_or_caps ) ? $user_role_or_caps : 'subscriber' ] );
		if ( is_array( $user_role_or_caps ) ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user ) { // Check if user creation was successful
				foreach ( $user_role_or_caps as $cap ) {
					if ( is_string( $cap ) && ! empty( $cap ) ) { // Add check for valid caps
						$user->add_cap( $cap );
					}
				}
			} else {
				$this->fail( 'Failed to create user for testing.' );
			}
		}
		wp_set_current_user( $user_id );

		// Manually trigger the action hook's callback for testing isolation
		Forced_MFA_Users::maybe_enforce_two_factor();
	}


	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_no_capability_set() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => [] ],
			],
		] );
		Forced_MFA_Users::init(); // Run init to set the static property

		$this->setup_user_and_filter( 'administrator' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when no capability is set.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_single_cap_user_has_cap() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => 'manage_options' ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'administrator' ); // Admins have manage_options
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has the single required capability.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_single_cap_user_lacks_cap() {
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => 'manage_options' ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'subscriber' ); // Subscribers lack manage_options
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when user lacks the single required capability.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_array_cap_user_has_one_cap() {
		$caps = [ 'edit_posts', 'manage_options' ];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => $caps ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'editor' ); // Editors have edit_posts but not manage_options
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true when user has one of the required capabilities.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_array_cap_user_lacks_all_caps() {
		$caps = [ 'manage_options', 'promote_users' ];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => $caps ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'editor' ); // Editors lack both capabilities
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true when user lacks all required capabilities.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_empty_cap_array() {
		$caps = [];
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => $caps ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'administrator' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true with an empty capability array.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_invalid_cap_types_in_array_user_has_valid_cap() {
		$caps = [ 'edit_posts', null, '', 5 ]; // Mix of valid and invalid
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => $caps ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'editor' ); // Has edit_posts
		$this->assertTrue( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should be true if user has at least one valid capability in a mixed array.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_invalid_cap_types_in_array_only_invalid() {
		$caps = [ null, '', 5 ]; // Only invalid types
		define( 'VIP_SECURITY_BOOST_CONFIGS', [
			'module_configs' => [
				'forced-mfa-users' => [ 'capabilities' => $caps ],
			],
		] );
		Forced_MFA_Users::init();

		$this->setup_user_and_filter( 'administrator' );
		$this->assertFalse( apply_filters( 'wpcom_vip_is_two_factor_forced', false ), 'Filter should not be true if only invalid capability types are provided.' );
	}
} 
