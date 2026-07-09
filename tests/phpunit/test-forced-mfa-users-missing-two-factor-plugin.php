<?php

use Automattic\VIP\Security\MFAUsers\Forced_MFA_Users;

/**
 * Tests for Forced_MFA_Users when Two Factor plugin (Two_Factor_Core) is missing.
 */
class Test_Forced_MFA_Users_Missing_Two_Factor_Plugin extends WP_UnitTestCase {

	/**
	 * Ensure maybe_enforce_two_factor does not error when Two_Factor_Core is missing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_enforce_two_factor_no_two_factor_plugin_no_error() {
		// Configure enforcement so that, if the plugin existed, it would try to enforce.
		if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
			define( 'VIP_SECURITY_BOOST_CONFIGS', [
				'module_configs' => [
					'forced-mfa-users' => [ 'roles' => 'administrator' ],
				],
			] );
		}

		// Call the method; it should early-return and not throw exceptions.
		Forced_MFA_Users::maybe_enforce_two_factor();

		// Since plugin is missing, our internal filter shouldn't be enforced
		$this->assertFalse( apply_filters( 'wpcom_vip_internal_is_two_factor_forced', false ) );
	}

	/**
	 * Ensure add_custom_enforced_capabilities_to_sds works when Two_Factor plugin is missing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_add_custom_enforced_capabilities_to_sds_without_two_factor_plugin() {
		// No additional filters present
		$this->assertFalse( has_filter( Forced_MFA_Users::ADDITIONAL_CAPABILITIES_FILTER_NAME ) );
		$this->assertFalse( has_filter( Forced_MFA_Users::ADDITIONAL_ROLES_FILTER_NAME ) );

		$payload = [ 'foo' => 'bar' ];
		$result  = Forced_MFA_Users::add_custom_enforced_capabilities_to_sds( $payload );

		$this->assertSame( 'false', $result['custom_capabilities'] );
		$this->assertSame( 'false', $result['custom_roles'] );

		// Add filters and ensure normalization still works without the plugin
		add_filter( Forced_MFA_Users::ADDITIONAL_CAPABILITIES_FILTER_NAME, static function () {
			return [ 'manage_options', 'edit_posts' ];
		} );
		add_filter( Forced_MFA_Users::ADDITIONAL_ROLES_FILTER_NAME, static function () {
			return [ 'administrator', 'subscriber' ];
		} );

		Forced_MFA_Users::add_custom_enforced_capabilities_to_sds( [ 'baz' => 'qux' ] );
		// we only want to ensure there are no errors, no extra check is needed.
	}


	/**
	 * Ensure has_two_factor_plugin returns false when the plugin is missing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_has_two_factor_plugins_is_false() {
		$this->assertFalse( Forced_MFA_Users::has_two_factor_plugin() );
	}
	/**
	 * Ensure hooks added in register_plugin_loaded_hooks are NOT added when Two_Factor_Core is missing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_plugin_loaded_hooks_not_added_when_two_factor_missing() {
		if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
			define( 'VIP_SECURITY_BOOST_CONFIGS', [
				'module_configs' => [
					'forced-mfa-users' => [ 'roles' => 'administrator' ],
				],
			] );
		}
		$class_name = get_class( new Forced_MFA_Users() );

		Forced_MFA_Users::init();

		// The SDS reporting hook that depends on Two_Factor_Core should not be present
		$this->assertFalse( has_filter( 'vip_site_details_index_security_boost_data', [ $class_name, 'add_users_without_2fa_count_to_sds_payload' ] ) );

		// User-related cache clearing hooks should also not be present
		$this->assertFalse( has_action( 'user_register', [ $class_name, 'clear_mfa_count_cache' ] ) );
		$this->assertFalse( has_action( 'delete_user', [ $class_name, 'clear_mfa_count_cache' ] ) );
		$this->assertFalse( has_action( 'updated_user_meta', [ $class_name, 'clear_mfa_count_cache_on_meta_update' ] ) );
		$this->assertFalse( has_action( 'added_user_meta', [ $class_name, 'clear_mfa_count_cache_on_meta_update' ] ) );
		$this->assertFalse( has_action( 'deleted_user_meta', [ $class_name, 'clear_mfa_count_cache_on_meta_update' ] ) );
		$this->assertFalse( has_action( 'set_user_role', [ $class_name, 'clear_mfa_count_cache_for_user_role_change' ] ) );

		// Multisite-specific hooks for user deletion/removal
		if ( is_multisite() ) {
			$this->assertFalse( has_action( 'wpmu_delete_user', [ $class_name, 'clear_mfa_count_cache_for_user_sites' ] ) );
			$this->assertFalse( has_action( 'remove_user_from_blog', [ $class_name, 'clear_mfa_count_cache' ] ) );
			$this->assertFalse( has_action( 'add_user_to_blog', [ $class_name, 'clear_mfa_count_cache' ] ) );
		}
	}
}
