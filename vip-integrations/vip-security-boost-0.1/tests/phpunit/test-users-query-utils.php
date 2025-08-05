<?php

use Automattic\VIP\Security\Utils\Users_Query_Utils;

/**
 * Tests for Automattic\VIP\Security\Utils\Users_Query_Utils
 */
class UsersQueryUtilsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		
		// Ensure we have a clean cache state
		wp_cache_flush();
	}

	public function tearDown(): void {
		// Clean up any test data
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * Test that our utility method works correctly for site-specific queries
	 */
	public function test_site_specific_query_with_capability_filtering() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create a site and multiple users with different roles
		$site_id = $this->factory->blog->create();

		// Create multiple users per role to test counting accuracy
		$admin_user1  = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$admin_user2  = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$editor_user1 = $this->factory->user->create( [ 'role' => 'editor' ] );
		$editor_user2 = $this->factory->user->create( [ 'role' => 'editor' ] );

		// Add users to the site
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_id );
		add_user_to_blog( $site_id, $admin_user1, 'administrator' );
		add_user_to_blog( $site_id, $admin_user2, 'administrator' );
		add_user_to_blog( $site_id, $editor_user1, 'editor' );
		add_user_to_blog( $site_id, $editor_user2, 'editor' );
		restore_current_blog();

		// Test site-specific query with single capability filtering
		$site_count = Users_Query_Utils::query_users_with_capability_filtering(
			[ 'capability__in' => [ 'manage_options' ] ],
			$site_id,
			true // count only
		);

		$this->assertEquals( 2, $site_count, 'Site-specific query should return exactly 2 users with manage_options capability' );

		// Test site-specific query with multiple capabilities
		$site_multi_cap_count = Users_Query_Utils::query_users_with_capability_filtering(
			[ 'capability__in' => [ 'manage_options', 'edit_posts' ] ],
			$site_id,
			true // count only
		);

		$this->assertEquals( 4, $site_multi_cap_count, 'Site-specific query should return exactly 4 users with manage_options OR edit_posts capabilities' );

		// Test site-specific query with role filtering
		$site_role_count = Users_Query_Utils::query_users_with_capability_filtering(
			[ 'role__in' => [ 'administrator' ] ],
			$site_id,
			true // count only
		);

		$this->assertEquals( 2, $site_role_count, 'Site-specific query should return exactly 2 users with administrator role' );

		// Test getting user IDs instead of count
		$site_user_ids = Users_Query_Utils::query_users_with_capability_filtering(
			[ 'capability__in' => [ 'manage_options' ] ],
			$site_id,
			false // return user IDs
		);

		$this->assertCount( 2, $site_user_ids, 'Should return array with exactly 2 user IDs' );
		$this->assertContains( $admin_user1, $site_user_ids, 'Should contain first admin user ID' );
		$this->assertContains( $admin_user2, $site_user_ids, 'Should contain second admin user ID' );

		// Clean up
		wp_delete_user( $admin_user1 );
		wp_delete_user( $admin_user2 );
		wp_delete_user( $editor_user1 );
		wp_delete_user( $editor_user2 );
	}

	/**
	 * Test that WordPress core WP_User_Query fails with network-wide capability filtering
	 * and that our utility method succeeds
	 */
	public function test_network_wide_capability_filtering_core_vs_utility() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create a site and multiple users with different roles
		$site_id = $this->factory->blog->create();

		// Create multiple users per role to test counting accuracy
		$admin_user1  = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$admin_user2  = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$editor_user1 = $this->factory->user->create( [ 'role' => 'editor' ] );
		$editor_user2 = $this->factory->user->create( [ 'role' => 'editor' ] );

		// Add users to the site
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_id );
		add_user_to_blog( $site_id, $admin_user1, 'administrator' );
		add_user_to_blog( $site_id, $admin_user2, 'administrator' );
		add_user_to_blog( $site_id, $editor_user1, 'editor' );
		add_user_to_blog( $site_id, $editor_user2, 'editor' );
		restore_current_blog();

		// Test 1: WordPress core WP_User_Query with blog_id=0 (should fail to filter properly)
		$core_query   = new \WP_User_Query([
			'blog_id'        => 0, // network-wide
			'capability__in' => [ 'manage_options' ],
			'fields'         => 'ID',
		]);
		$core_results = $core_query->get_results();

		// WordPress core ignores capability filtering with blog_id=0
		// This assertion demonstrates the core issue
		$this->assertGreaterThan( 2, count( $core_results ),
		'WordPress core WP_User_Query with blog_id=0 ignores capability filtering and returns too many users' );

		// Test 2: method with network-wide query (should work correctly)
		$utility_user_ids = Users_Query_Utils::query_users_with_capability_filtering(
			[
				'capability__in' => [ 'manage_options' ],
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding super admin (user_id=1)
				'exclude'        => [ 1 ],
			],
			0, // network-wide
			false // return user IDs
		);

		// should properly filter by capabilities
		$this->assertContains( $admin_user1, $utility_user_ids, 'should include the first admin user' );
		$this->assertContains( $admin_user2, $utility_user_ids, 'should include the second admin user' );
		$this->assertNotContains( $editor_user1, $utility_user_ids, 'should not include the first editor user' );
		$this->assertNotContains( $editor_user2, $utility_user_ids, 'should not include the second editor user' );

		// Test 3: Compare counts - our utility should return fewer users than core
		$utility_count = Users_Query_Utils::query_users_with_capability_filtering(
			[
				'capability__in' => [ 'manage_options' ],
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding super admin (user_id=1)
				'exclude'        => [ 1 ],
			],
			0, // network-wide
			true // count only
		);

		$this->assertLessThan( count( $core_results ), $utility_count,
		'should return fewer users than WordPress core (which ignores filtering)' );
		$this->assertEquals( count( $utility_user_ids ), $utility_count,
		'Count should match array length from our utility' );
		$this->assertEquals( 2, $utility_count, 'should return exactly 2 admin users' );

		// Clean up
		wp_delete_user( $admin_user1 );
		wp_delete_user( $admin_user2 );
		wp_delete_user( $editor_user1 );
		wp_delete_user( $editor_user2 );
	}

	/**
	 * Test network-wide role filtering
	 */
	public function test_network_wide_role_filtering() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create multiple sites with different users
		$site1_id = $this->factory->blog->create();
		$site2_id = $this->factory->blog->create();

		// Create multiple users per role to test counting accuracy
		$admin_user1     = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$admin_user2     = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$editor_user1    = $this->factory->user->create( [ 'role' => 'editor' ] );
		$editor_user2    = $this->factory->user->create( [ 'role' => 'editor' ] );
		$subscriber_user = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		// Add admins to site 1
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site1_id );
		add_user_to_blog( $site1_id, $admin_user1, 'administrator' );
		add_user_to_blog( $site1_id, $admin_user2, 'administrator' );
		restore_current_blog();

		// Add editors and subscriber to site 2
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site2_id );
		add_user_to_blog( $site2_id, $editor_user1, 'editor' );
		add_user_to_blog( $site2_id, $editor_user2, 'editor' );
		add_user_to_blog( $site2_id, $subscriber_user, 'subscriber' );
		restore_current_blog();

		// Test network-wide role filtering for administrators
		$admin_count = Users_Query_Utils::query_users_with_capability_filtering(
			[
				'role__in' => [ 'administrator' ],
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding super admin (user_id=1)
				'exclude'  => [ 1 ],
			],
			0, // network-wide
			true // count only
		);

		$this->assertEquals( 2, $admin_count, 'Should find exactly 2 admin users across the network' );

		// Test network-wide role filtering for editors
		$editor_count = Users_Query_Utils::query_users_with_capability_filtering(
			[
				'role__in' => [ 'editor' ],
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding super admin (user_id=1)
				'exclude'  => [ 1 ],
			],
			0, // network-wide
			true // count only
		);

		$this->assertEquals( 2, $editor_count, 'Should find exactly 2 editor users across the network' );

		// Test multiple roles
		$admin_editor_count = Users_Query_Utils::query_users_with_capability_filtering(
			[
				'role__in' => [ 'administrator', 'editor' ],
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding super admin (user_id=1)
				'exclude'  => [ 1 ],
			],
			0, // network-wide
			true // count only
		);

		$this->assertEquals( 4, $admin_editor_count, 'Should find exactly 4 users (2 admins + 2 editors) across the network' );

		// Test multiple capabilities
		$multi_cap_count = Users_Query_Utils::query_users_with_capability_filtering(
			[
				'capability__in' => [ 'manage_options', 'edit_posts' ],
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding super admin (user_id=1)
				'exclude'        => [ 1 ],
			],
			0, // network-wide
			true // count only
		);

		$this->assertEquals( 4, $multi_cap_count, 'Should find exactly 4 users with manage_options OR edit_posts capabilities' );

		// Clean up
		wp_delete_user( $admin_user1 );
		wp_delete_user( $admin_user2 );
		wp_delete_user( $editor_user1 );
		wp_delete_user( $editor_user2 );
		wp_delete_user( $subscriber_user );
	}

	/**
	 * Test that the utility method handles additional query arguments correctly
	 */
	public function test_utility_with_additional_query_args() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create users with different registration dates
		$old_admin = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		
		$new_admin = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
		]);

		// Test with date_query to filter by registration date
		$old_admin_count = Users_Query_Utils::query_users_with_capability_filtering([
			'capability__in' => [ 'manage_options' ],
			'date_query'     => [
				[
					'column' => 'user_registered',
					'before' => '50 days ago',
				],
			],
		], 0, true);
		
		$this->assertGreaterThanOrEqual( 1, $old_admin_count, 'Should find old admin users' );

		// Test with exclude parameter
		$excluded_count = Users_Query_Utils::query_users_with_capability_filtering([
			'capability__in' => [ 'manage_options' ],
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Testing exclude functionality in controlled test environment
			'exclude'        => [ $old_admin ],
		], 0, true);
		
		// Should find fewer users when excluding the old admin
		$total_count = Users_Query_Utils::query_users_with_capability_filtering([
			'capability__in' => [ 'manage_options' ],
		], 0, true);
		
		$this->assertLessThan( $total_count, $excluded_count, 'Excluding users should reduce the count' );

		// Clean up
		wp_delete_user( $old_admin );
		wp_delete_user( $new_admin );
	}

	/**
	 * Test single site behavior (non-multisite)
	 */
	public function test_single_site_behavior() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'This test is for single site installations only' );
		}

		$admin_user  = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$editor_user = $this->factory->user->create( [ 'role' => 'editor' ] );

		// Test that blog_id=0 falls back to current site behavior
		$count = Users_Query_Utils::query_users_with_capability_filtering(
			[ 'capability__in' => [ 'manage_options' ] ],
			0, // should fall back to current site
			true // count only
		);
		
		$this->assertGreaterThanOrEqual( 1, $count, 'Should find admin users on single site' );

		// Clean up
		wp_delete_user( $admin_user );
		wp_delete_user( $editor_user );
	}

	/**
	 * Test that duplicate capability/role conditions are properly deduplicated
	 */
	public function test_capability_role_deduplication() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		$admin_user = $this->factory->user->create( [ 'role' => 'administrator' ] );

		// Test with overlapping capabilities and roles
		// 'manage_options' capability maps to 'administrator' role, so this should be deduplicated
		$count_with_overlap = Users_Query_Utils::query_users_with_capability_filtering([
			'capability__in' => [ 'manage_options' ],
			'role__in'       => [ 'administrator' ], // This should be deduplicated since admin has manage_options
		], 0, true);

		// Test with just capability
		$count_capability_only = Users_Query_Utils::query_users_with_capability_filtering([
			'capability__in' => [ 'manage_options' ],
		], 0, true);

		// Test with just role
		$count_role_only = Users_Query_Utils::query_users_with_capability_filtering([
			'role__in' => [ 'administrator' ],
		], 0, true);

		// All three queries should return the same count since they're targeting the same users
		// This verifies that deduplication doesn't affect the results
		$this->assertEquals( $count_capability_only, $count_with_overlap,
		'Overlapping capability and role should return same count as capability alone (deduplication working)' );
		$this->assertEquals( $count_role_only, $count_with_overlap,
		'Overlapping capability and role should return same count as role alone (deduplication working)' );

		// Test with multiple capabilities that map to the same role
		$count_multiple_caps = Users_Query_Utils::query_users_with_capability_filtering([
			'capability__in' => [ 'manage_options', 'edit_users', 'delete_users' ], // All map to administrator
		], 0, true);

		$this->assertEquals( $count_role_only, $count_multiple_caps,
		'Multiple capabilities mapping to same role should return same count as role alone' );

		// Clean up
		wp_delete_user( $admin_user );
	}
}
