<?php

use Automattic\VIP\Security\Data_Sync\Data_Sync;
use Automattic\VIP\Security\Constants;

/**
 * Test suite for the Data_Sync module. Focuses on the helper methods that expose
 * Two-Factor Authentication (2FA) enforcement status and the logic that augments
 * the Site Details Service (SDS) payload.
 *
 * These tests were originally part of the Forced_MFA_Users suite but now live
 * here because the functionality has been moved into the dedicated Data_Sync
 * module.
 */
class Test_Data_Sync extends WP_UnitTestCase {

	/**
	 * Ensure a clean environment for every test case.
	 */
	public function setUp(): void {
		parent::setUp();

		// Tell the two-factor plugin we're running in a test context.
		add_filter( 'wpcom_vip_is_two_factor_local_testing', '__return_true' );

		// Remove external filters that might interfere with the detection logic.
		// Clear any pre-existing filters entirely to get a true default state.
		remove_all_filters( 'wpcom_vip_is_two_factor_forced' );
		remove_all_filters( 'wpcom_vip_enable_two_factor' );
	}

	public function tearDown(): void {
		// Clean up any filters added in individual tests.
		remove_all_filters( 'wpcom_vip_is_two_factor_forced' );
		remove_all_filters( 'wpcom_vip_enable_two_factor' );

		parent::tearDown();
	}

	/**
	 * Test default status when no relevant filters are present.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_two_factor_enforcement_status_defaults() {
		$expected = [
			'two_factor_status' => [
				'is_enforced_globally'         => false,
				'is_not_enforced_globally'     => false,
				'has_two_factor_forced_filter' => false,
				'is_entirely_disabled'         => false,
				'has_enable_two_factor_filter' => false,
			],
		];

		$this->assertSame( $expected, Data_Sync::add_two_factor_enforcement_status_to_sds_payload( [] ) );
	}

	/**
	 * Test status when filters enforce two-factor globally.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_two_factor_enforcement_status_enforced_globally() {
		add_filter( 'wpcom_vip_is_two_factor_forced', '__return_true' );

		$status = Data_Sync::add_two_factor_enforcement_status_to_sds_payload( [] );

		$this->assertTrue( $status['two_factor_status']['is_enforced_globally'] );
		$this->assertTrue( $status['two_factor_status']['has_two_factor_forced_filter'] );
	}

	/**
	 * Test status when filters disable two-factor globally.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_two_factor_enforcement_status_disabled_globally() {
		add_filter( 'wpcom_vip_is_two_factor_forced', '__return_false' );

		$status = Data_Sync::add_two_factor_enforcement_status_to_sds_payload( [] );

		$this->assertTrue( $status['two_factor_status']['is_not_enforced_globally'] );
		$this->assertTrue( $status['two_factor_status']['has_two_factor_forced_filter'] );
	}

	/**
	 * Test that two-factor status is added to SDS payload correctly.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_add_two_factor_enforcement_status_to_sds_payload() {
		// Data_Sync hooks itself up in its init() call at the bottom of the file,
		// but WordPress test bootstrap may not have fired that yet in isolated
		// processes, so we ensure it here.
		\Automattic\VIP\Security\Data_Sync\Data_Sync::init();

		$this->assertEquals( 10, has_filter( 'vip_site_details_index_security_boost_data', [ Data_Sync::class, 'add_two_factor_enforcement_status_to_sds_payload' ] ) );

		$site_details = apply_filters( 'vip_site_details_index_data', [] );
		$expected     = Data_Sync::add_two_factor_enforcement_status_to_sds_payload( [] );

		$this->assertArrayHasKey( Constants::SDS_DATA_KEY, $site_details );
		$this->assertSame( $expected, $site_details[ Constants::SDS_DATA_KEY ] );
	}

	// check that it adds a vip_site_details_index_data filter and it adds the vip_security_boost key
	public function test_add_security_boost_extended_data() {
		// Data_Sync hooks itself up in its init() call at the bottom of the file,
		// but WordPress test bootstrap may not have fired that yet in isolated
		// processes, so we ensure it here.
		\Automattic\VIP\Security\Data_Sync\Data_Sync::init();

		$this->assertEquals( 10, has_filter( 'vip_site_details_index_data', [ Data_Sync::class, 'add_security_boost_extended_data' ] ) );

		$site_details = apply_filters( 'vip_site_details_index_data', [] );
		$expected     = Data_Sync::add_security_boost_extended_data( [] );

		$this->assertArrayHasKey( Constants::SDS_DATA_KEY, $site_details );
		$this->assertSame( $expected, $site_details );
	}
}
