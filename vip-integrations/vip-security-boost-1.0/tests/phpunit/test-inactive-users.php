<?php

use Automattic\VIP\Security\InactiveUsers\Inactive_Users;

class InactiveUsersTest extends WP_UnitTestCase {
	private $user_id;
	private $elevated_roles                 = [ 'administrator' ];
	private $considered_inactive_after_days = 90;

	public function setUp(): void {
		parent::setUp();

		// Create a test user with elevated roles and an old registration date
		$this->user_id = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);

		if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
			define('VIP_SECURITY_BOOST_CONFIGS', [
				'module_configs' => [
					'inactive-users' => [
						'mode'                           => 'BLOCK',
						'considered_inactive_after_days' => 90,
						'roles'                          => $this->elevated_roles,
					],
				],
			]);
		}
		// Let's assume the module has been activated today
		add_option( Inactive_Users::LAST_SEEN_RELEASE_DATE_TIMESTAMP_OPTION_KEY, time() );

		Inactive_Users::init();
	}

	public function tearDown(): void {
		wp_delete_user( $this->user_id );
		parent::tearDown();
	}

	/**
	 * Test that add_last_seen_column_head adds a 'Last seen' column to the columns array
	 */
	public function test_add_last_seen_column_head() {
		$initial_columns = [
			'username' => 'Username',
			'email'    => 'Email',
			'role'     => 'Role',
		];

		$result = Inactive_Users::add_last_seen_column_head( $initial_columns );

		$this->assertArrayHasKey( 'last_seen', $result );
		$this->assertEquals( __( 'Last seen', 'wpvip' ), $result['last_seen'] );
		$this->assertEquals( count( $initial_columns ) + 1, count( $result ) );
	}

	/**
	 * Test that record_activity updates the last seen timestamp
	 */
	public function test_record_activity() {
		wp_set_current_user( $this->user_id );

		Inactive_Users::record_activity();

		$last_seen = get_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, true );

		$this->assertNotEmpty( $last_seen );
		$this->assertTrue( is_numeric( $last_seen ) );
		$this->assertLessThanOrEqual( time(), $last_seen );
	}

	/**
	 * Test that a user is correctly identified as inactive
	 */
	public function test_is_considered_inactive() {
		// Set last seen to 91 days ago (beyond the 90-day threshold)
		$old_timestamp = strtotime( '-91 days' );
		update_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, $old_timestamp );

		$this->assertTrue( Inactive_Users::is_considered_inactive( $this->user_id ) );
	}

	/**
	 * Test that a recently active user is not considered inactive
	 */
	public function test_is_not_considered_inactive_for_recent_activity() {
		// Set last seen to yesterday
		$recent_timestamp = strtotime( '-1 day' );
		update_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, $recent_timestamp );

		$this->assertFalse( Inactive_Users::is_considered_inactive( $this->user_id ) );
	}

	/**
	 * Test that ignore_inactivity_check_for_user works correctly
	 */
	public function test_ignore_inactivity_check_for_user() {
		Inactive_Users::init(); // Re-initialize with test config

		// Set user as inactive
		update_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Ignore inactivity check
		Inactive_Users::ignore_inactivity_check_for_user( $this->user_id );

		$this->assertFalse( Inactive_Users::is_considered_inactive( $this->user_id ) );

		// Verify the ignore until timestamp was set
		$ignore_until = get_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY, true );
		$this->assertNotEmpty( $ignore_until );
		$this->assertTrue( $ignore_until > time() );
	}

	/**
	 * Test authentication blocking for inactive users
	 */
	public function test_authenticate_blocks_inactive_users() {
		// Set user as inactive
		update_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		$user   = new WP_User( $this->user_id );
		$result = Inactive_Users::maybe_block_inactive_user_on_authenticate( $user );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'inactive_account', $result->get_error_code() );
	}

	/**
	 * Test that authentication for users with non elevated roles are not blocked.
	 */
	public function test_authenticate_does_not_block_nonelevated_roles() {

		// Create a new user with a registration date that should be blocked if it has an elevated role. Starts with no role.
		$new_user_id = $this->factory->user->create([
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-91 days' ) ),
		]);

		update_user_meta( $new_user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Add all roles to the new user that are not currently elevated.
		global $wp_roles;
		$role_names = array_values( array_diff( array_keys( $wp_roles->roles ), $this->elevated_roles ) );
		$new_user   = new WP_User( $new_user_id );

		foreach ( $role_names as $role_key ) {
			$new_user->add_role( $role_key );
		}

		$result = Inactive_Users::maybe_block_inactive_user_on_authenticate( $new_user );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		wp_delete_user( $new_user_id );
	}

	/**
	 * Test that newly registered users are not considered inactive
	 */
	public function test_new_user_not_considered_inactive() {
		// Create a new user with a recent registration date
		$new_user_id = $this->factory->user->create([
			'role'            => 'editor',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		]);

		$this->assertFalse( Inactive_Users::is_considered_inactive( $new_user_id ) );

		wp_delete_user( $new_user_id );
	}

	/**
	 * Test that modify_users_list_table_items adds badges to inactive users
	 */
	public function test_modify_users_list_table_items_adds_badges() {
		// Create a mock WP_Users_List_Table
		global $wp_list_table;
		$wp_list_table = $this->getMockBuilder( 'WP_Users_List_Table' )
			->disableOriginalConstructor()
			->getMock();

		// Create test user objects
		$inactive_user             = new stdClass();
		$inactive_user->ID         = $this->user_id;
		$inactive_user->user_login = 'testuser';

		$active_user             = new stdClass();
		$active_user->ID         = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		]);
		$active_user->user_login = 'activeuser';

		// Set up the list table items
		$wp_list_table->items = [ $inactive_user, $active_user ];

		// Make the first user inactive
		update_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Call the method
		Inactive_Users::modify_users_list_table_items();

		// Check that the inactive user has a badge added
		$this->assertStringContainsString( 'inactive-user-badge', $wp_list_table->items[0]->user_login );
		$this->assertStringContainsString( 'testuser', $wp_list_table->items[0]->user_login );

		// Check that the active user doesn't have a badge
		$this->assertEquals( 'activeuser', $wp_list_table->items[1]->user_login );

		// Clean up
		wp_delete_user( $active_user->ID );
	}

	/**
	 * Test that modify_users_list_table_items shows correct badge text
	 */
	public function test_modify_users_list_table_items_badge_text() {
		// Create a mock WP_Users_List_Table
		global $wp_list_table;
		$wp_list_table = $this->getMockBuilder( 'WP_Users_List_Table' )
			->disableOriginalConstructor()
			->getMock();

		// Create test user object
		$inactive_user             = new stdClass();
		$inactive_user->ID         = $this->user_id;
		$inactive_user->user_login = 'testuser';

		$wp_list_table->items = [ $inactive_user ];

		// Make the user inactive
		update_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Test that a badge is added (the specific text depends on the current config)
		Inactive_Users::modify_users_list_table_items();
		$this->assertStringContainsString( 'inactive-user-badge', $wp_list_table->items[0]->user_login );
		$this->assertStringContainsString( 'testuser', $wp_list_table->items[0]->user_login );

		// Should contain either "Blocked: Inactivity" or "Inactive User"
		$login_text = $wp_list_table->items[0]->user_login;
		$this->assertTrue(
			strpos( $login_text, 'Blocked: Inactivity' ) !== false ||
			strpos( $login_text, 'Inactive User' ) !== false,
			'Badge should contain either "Blocked: Inactivity" or "Inactive User"'
		);
	}

	/**
	 * Test that modify_users_list_table_items handles empty or invalid list table
	 */
	public function test_modify_users_list_table_items_handles_invalid_input() {
		global $wp_list_table;

		// Test with no list table
		$wp_list_table = null;
		Inactive_Users::modify_users_list_table_items();
		$this->assertTrue( true ); // Should not crash

		// Test with wrong type of list table
		$wp_list_table = new stdClass();
		Inactive_Users::modify_users_list_table_items();
		$this->assertTrue( true ); // Should not crash

		// Test with empty items
		$wp_list_table        = $this->getMockBuilder( 'WP_Users_List_Table' )
			->disableOriginalConstructor()
			->getMock();
		$wp_list_table->items = [];
		Inactive_Users::modify_users_list_table_items();
		$this->assertTrue( true ); // Should not crash
	}

	/**
	 * Test that user with elevated capabilities is tracked
	 */
	public function test_user_with_elevated_capabilities_is_tracked() {
		// Create a new instance with capability configuration
		$inactive_users_class = new class() extends Inactive_Users {
			public static function reset_for_test() {
				self::$elevated_capabilities          = [ 'manage_options' ];
				self::$elevated_roles                 = [];
				self::$mode                           = 'BLOCK';
				self::$considered_inactive_after_days = 90;
			}

			public static function test_user_has_elevated_permissions( $user ) {
				return parent::user_has_elevated_permissions( $user );
			}
		};

		$inactive_users_class::reset_for_test();

		// Create user with manage_options capability
		$cap_user_id = $this->factory->user->create([
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		$cap_user    = new WP_User( $cap_user_id );
		$cap_user->add_cap( 'manage_options' );

		// User should have elevated permissions due to capability
		$this->assertTrue( $inactive_users_class::test_user_has_elevated_permissions( $cap_user ) );

		wp_delete_user( $cap_user_id );
	}

	/**
	 * Test that capabilities take priority over roles
	 */
	public function test_capabilities_take_priority_over_roles() {
		// Create a new instance with both capability and role configuration
		$inactive_users_class = new class() extends Inactive_Users {
			public static function reset_for_test() {
				self::$elevated_capabilities          = [ 'edit_posts' ];
				self::$elevated_roles                 = [ 'administrator' ];
				self::$mode                           = 'BLOCK';
				self::$considered_inactive_after_days = 90;
			}

			public static function test_user_has_elevated_permissions( $user ) {
				return parent::user_has_elevated_permissions( $user );
			}
		};

		$inactive_users_class::reset_for_test();

		// Create user with only the capability, not the role
		$cap_user_id = $this->factory->user->create([
			'role'            => 'subscriber',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		$cap_user    = new WP_User( $cap_user_id );
		$cap_user->add_cap( 'edit_posts' );

		// User should have elevated permissions due to capability even without admin role
		$this->assertTrue( $inactive_users_class::test_user_has_elevated_permissions( $cap_user ) );

		wp_delete_user( $cap_user_id );
	}

	/**
	 * Test backward compatibility - roles still work when no capabilities configured
	 */
	public function test_backward_compatibility_roles_work_without_capabilities() {
		// Create a new instance with only role configuration (no capabilities)
		$inactive_users_class = new class() extends Inactive_Users {
			public static function reset_for_test() {
				self::$elevated_capabilities          = [];
				self::$elevated_roles                 = [ 'editor' ];
				self::$mode                           = 'BLOCK';
				self::$considered_inactive_after_days = 90;
			}

			public static function test_user_has_elevated_permissions( $user ) {
				return parent::user_has_elevated_permissions( $user );
			}
		};

		$inactive_users_class::reset_for_test();

		// Create user with editor role
		$editor_user_id = $this->factory->user->create([
			'role'            => 'editor',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		$editor_user    = new WP_User( $editor_user_id );

		// User should have elevated permissions due to role
		$this->assertTrue( $inactive_users_class::test_user_has_elevated_permissions( $editor_user ) );

		// Create user without elevated role
		$subscriber_user_id = $this->factory->user->create([
			'role'            => 'subscriber',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		$subscriber_user    = new WP_User( $subscriber_user_id );

		// User should not have elevated permissions
		$this->assertFalse( $inactive_users_class::test_user_has_elevated_permissions( $subscriber_user ) );

		wp_delete_user( $editor_user_id );
		wp_delete_user( $subscriber_user_id );
	}

	/**
	 * Test that modify_users_list_table_items works with WP_MS_Users_List_Table for multisite
	 */
	public function test_modify_users_list_table_items_works_with_multisite_table() {
		// Create a mock WP_MS_Users_List_Table
		global $wp_list_table;
		$wp_list_table = $this->getMockBuilder( 'WP_MS_Users_List_Table' )
			->disableOriginalConstructor()
			->getMock();

		// Create test user object
		$inactive_user             = new stdClass();
		$inactive_user->ID         = $this->user_id;
		$inactive_user->user_login = 'testuser';

		$wp_list_table->items = [ $inactive_user ];

		// Make the user inactive
		update_user_meta( $this->user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Call the method
		Inactive_Users::modify_users_list_table_items();

		// Check that the inactive user has a badge added
		$this->assertStringContainsString( 'inactive-user-badge', $wp_list_table->items[0]->user_login );
		$this->assertStringContainsString( 'testuser', $wp_list_table->items[0]->user_login );
	}

	/**
	 * Test that add_username_badge_styles outputs CSS with correct classes and colors
	 */
	public function test_add_username_badge_styles_outputs_css() {
		ob_start();
		Inactive_Users::add_username_badge_styles();
		$output = ob_get_clean();

		$this->assertStringContainsString( '.inactive-user-badge', $output );
		$this->assertStringContainsString( '.inactive-user-badge--blocked', $output );
		$this->assertStringContainsString( '.inactive-user-badge--inactive', $output );
		$this->assertStringContainsString( 'background: #d63638', $output );
		$this->assertStringContainsString( 'background: #f0b849', $output );
	}

	/**
	 * Test capability filter
	 */
	public function test_capability_filter() {
		// Create a test to verify the filter works
		$test_capabilities = [ 'custom_capability', 'another_capability' ];

		add_filter( 'vip_security_boost_inactive_users_elevated_capabilities', function () use ( $test_capabilities ) {
			return $test_capabilities;
		});

		// Create instance to test filter application
		$inactive_users_class = new class() extends Inactive_Users {
			public static function get_test_capabilities() {
				$capabilities = self::$elevated_capabilities ?? [];
				return apply_filters( 'vip_security_boost_inactive_users_elevated_capabilities', $capabilities );
			}
		};

		$filtered_caps = $inactive_users_class::get_test_capabilities();
		$this->assertEquals( $test_capabilities, $filtered_caps );

		// Clean up
		remove_all_filters( 'vip_security_boost_inactive_users_elevated_capabilities' );
	}

	/**
	 * Test public method that uses get_last_seen_date_string indirectly.
	 */
	public function test_get_last_seen_date_string_handles_empty_timestamp_correctly() {
		// Example: Replace with a test for a public method that uses get_last_seen_date_string.
		$this->assertSame( 'Unknown', Inactive_Users::get_last_seen_date_string( 0 ) );
		$this->assertSame( 'Unknown', Inactive_Users::get_last_seen_date_string( null ) );
	}

	/**
	 * get_last_seen_date_string() returns unknown (date in the future).
	 */
	public function test_get_last_seen_date_string__handles_future_timestamp_correctly() {
		$fixed_now = 1_700_000_000;                     // 2023-11-14 22:13 UTC
		$two_hours = $fixed_now + 2 * HOUR_IN_SECONDS;  // 2 hours in the future

		$this->assertSame(
			'Unknown',
			Inactive_Users::get_last_seen_date_string( $two_hours, $fixed_now )
		);
	}

	/**
	 * get_last_seen_date_string() returns a relative phrase (< 1 day old).
	 */
	public function test_last_seen_uses_relative_time_for_recent_activity() {
		$fixed_now = 1_700_000_000;                     // 2023-11-14 22:13 UTC
		$two_hours = $fixed_now - 2 * HOUR_IN_SECONDS;  // 2 hours earlier

		$this->assertSame(
			'2 hours ago',
			Inactive_Users::get_last_seen_date_string( $two_hours, $fixed_now )
		);
	}

	/**
	 * get_last_seen_date_string() falls back to absolute date/time (≥ 30 days old).
	 */
	public function test_last_seen_uses_absolute_time_for_older_activity() {
		$fixed_now      = 1_700_000_000;
		$sixty_days_ago = $fixed_now - 60 * DAY_IN_SECONDS;

		// Use deterministic site formats and remember originals.
		$old_date_format = get_option( 'date_format' );
		$old_time_format = get_option( 'time_format' );
		update_option( 'date_format', 'j M Y' ); // "9 Dec 2023"
		update_option( 'time_format', 'H:i' );   // "05:33"

		$expected = sprintf(
			'%1$s at %2$s',
			date_i18n( get_option( 'date_format' ), $sixty_days_ago ),
			date_i18n( get_option( 'time_format' ), $sixty_days_ago )
		);

		$this->assertSame(
			$expected,
			Inactive_Users::get_last_seen_date_string( $sixty_days_ago, $fixed_now )
		);

		// Restore environment.
		update_option( 'date_format', $old_date_format );
		update_option( 'time_format', $old_time_format );
	}

	/**
	 * Test that a user is considered inactive if the fallback date is in the past
	 */
	public function test_is_considered_inactive_fallback_past_date() {
		// Create a new user with an old registration date
		$new_user_id = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '2025-01-01 00:00:00' ) ),
		]);

		delete_option( Inactive_Users::LAST_SEEN_RELEASE_DATE_TIMESTAMP_OPTION_KEY );
		delete_user_meta( $new_user_id, Inactive_Users::LAST_SEEN_META_KEY );
		delete_user_meta( $new_user_id, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		$this->assertTrue( Inactive_Users::is_considered_inactive( $new_user_id ) );
		wp_delete_user( $new_user_id );
	}

	/**
	 * Test that the vip_site_details_index_security_boost_data filter is added and works correctly
	 */
	public function test_vip_site_details_index_security_boost_data_filter_is_added() {
		// Verify the filter is added during init
		$this->assertNotFalse( has_filter( 'vip_site_details_index_security_boost_data', [ 'Automattic\VIP\Security\InactiveUsers\Inactive_Users', 'add_inactive_users_count_to_sds_payload' ] ) );

		// Test that the filter works by applying it
		$initial_data  = [ 'some_key' => 'some_value' ];
		$filtered_data = apply_filters( 'vip_site_details_index_security_boost_data', $initial_data );

		// Verify the filter was applied and our data was added
		$this->assertArrayHasKey( 'inactive_users_count', $filtered_data );
		$this->assertIsInt( $filtered_data['inactive_users_count'] );

		// Verify the network-wide count is added only in multisite
		if ( is_multisite() ) {
			$this->assertArrayHasKey( 'inactive_users_count_all_blogs', $filtered_data );
			$this->assertIsInt( $filtered_data['inactive_users_count_all_blogs'] );
		}

		// Verify original data is preserved
		$this->assertEquals( 'some_value', $filtered_data['some_key'] );
	}

	/**
	 * Test that add_inactive_users_count_to_sds_payload returns correct count for inactive users
	 */
	public function test_add_inactive_users_count_to_sds_payload_returns_correct_count() {
		// Create additional inactive users
		$inactive_user_1 = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		delete_user_meta( $inactive_user_1, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		$inactive_user_2 = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		delete_user_meta( $inactive_user_2, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Make them inactive by setting old last seen timestamps
		update_user_meta( $inactive_user_1, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		update_user_meta( $inactive_user_2, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		$result = Inactive_Users::add_inactive_users_count_to_sds_payload( [] );

		// Should have 3 inactive users (the ones we created)
		$this->assertEquals( 2, $result['inactive_users_count'] );

		// Clean up
		wp_delete_user( $inactive_user_1 );
		wp_delete_user( $inactive_user_2 );
	}

	/**
	 * Test that add_inactive_users_count_to_sds_payload returns correct counts for multisite networks
	 * Each site should have its own count of inactive users
	 */
	public function test_add_inactive_users_count_to_sds_payload_multisite_counts() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create two additional sites
		$site_1_id = $this->factory->blog->create();
		$site_2_id = $this->factory->blog->create();

		// Create users for site 1
		$site_1_user_1 = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		delete_user_meta( $site_1_user_1, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$site_1_user_2 = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		delete_user_meta( $site_1_user_2, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Create users for site 2
		$site_2_user_1 = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		delete_user_meta( $site_2_user_1, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$site_2_user_2 = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		delete_user_meta( $site_2_user_2, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$site_2_user_3 = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		delete_user_meta( $site_2_user_3, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Switch to site 1 and add users to it
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_1_id );
		add_user_to_blog( $site_1_id, $site_1_user_1, 'administrator' );
		delete_user_meta( $site_1_user_1, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		add_user_to_blog( $site_1_id, $site_1_user_2, 'administrator' );
		delete_user_meta( $site_1_user_2, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Make site 1 users inactive
		update_user_meta( $site_1_user_1, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		update_user_meta( $site_1_user_2, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Test site 1 count - role__in in WP_User_Query filters by current site roles,
		// so only users with administrator role on site 1 are counted
		$site_1_result = Inactive_Users::add_inactive_users_count_to_sds_payload( [] );
		$this->assertEquals( 2, $site_1_result['inactive_users_count'], 'Site 1 should count 2 inactive users with roles on this site' );

		restore_current_blog();

		// Switch to site 2 and add users to it
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_2_id );
		add_user_to_blog( $site_2_id, $site_2_user_1, 'administrator' );
		delete_user_meta( $site_2_user_1, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		add_user_to_blog( $site_2_id, $site_2_user_2, 'administrator' );
		delete_user_meta( $site_2_user_2, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		add_user_to_blog( $site_2_id, $site_2_user_3, 'administrator' );
		delete_user_meta( $site_2_user_3, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Make site 2 users inactive
		update_user_meta( $site_2_user_1, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		update_user_meta( $site_2_user_2, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		update_user_meta( $site_2_user_3, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Test site 2 count - only users with administrator role on site 2 are counted
		$site_2_result = Inactive_Users::add_inactive_users_count_to_sds_payload( [] );
		$this->assertEquals( 3, $site_2_result['inactive_users_count'], 'Site 2 should count 3 inactive users with roles on this site' );

		restore_current_blog();

		// Flush cache to ensure accurate counts
		Inactive_Users::flush_cache();

		// Test network-wide count - all users with administrator role are counted
		$network_result = Inactive_Users::add_inactive_users_count_to_sds_payload( [] );
		$this->assertEquals( 5, $network_result['inactive_users_count_all_blogs'], 'Network-wide should count 5 inactive users with roles on any site' );

		// Clean up users
		wp_delete_user( $site_1_user_1 );
		wp_delete_user( $site_1_user_2 );
		wp_delete_user( $site_2_user_1 );
		wp_delete_user( $site_2_user_2 );
		wp_delete_user( $site_2_user_3 );
	}

	/**
	 * Test the new Users_Query_Utils::query_users_with_capability_filtering method
	 */
	public function test_users_query_utils_capability_filtering() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create a site and users with different roles
		$site_id = $this->factory->blog->create();

		$admin_user  = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$editor_user = $this->factory->user->create( [ 'role' => 'editor' ] );

		// Add users to the site
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_id );
		add_user_to_blog( $site_id, $admin_user, 'administrator' );
		add_user_to_blog( $site_id, $editor_user, 'editor' );
		restore_current_blog();

		// Test 1: Site-specific query with capability filtering
		$site_count = \Automattic\VIP\Security\Utils\Users_Query_Utils::query_users_with_capability_filtering(
			[ 'capability__in' => [ 'manage_options' ] ],
			$site_id,
			true // count only
		);

		$this->assertEquals( 1, $site_count, 'Site-specific query should return 1 user with manage_options capability' );

		// Test our new utility method with capability filtering
		$network_user_ids = \Automattic\VIP\Security\Utils\Users_Query_Utils::query_users_with_capability_filtering(
			[ 'capability__in' => [ 'manage_options' ] ],
			0, // network-wide
			false // return user IDs
		);

		// Our utility should properly filter by capabilities
		$this->assertContains( $admin_user, $network_user_ids, 'Network-wide query should include our admin user' );
		$this->assertNotContains( $editor_user, $network_user_ids, 'Network-wide query should not include editor user' );

		// Test count as well
		$network_count = \Automattic\VIP\Security\Utils\Users_Query_Utils::query_users_with_capability_filtering(
			[ 'capability__in' => [ 'manage_options' ] ],
			0, // network-wide
			true // count only
		);

		// Should be at least 1 (our admin user), but may include other admin users from test setup
		$this->assertGreaterThanOrEqual( 1, $network_count, 'Network-wide count should include at least our admin user' );
		$this->assertEquals( count( $network_user_ids ), $network_count, 'Count should match array length' );

		// Clean up
		wp_delete_user( $admin_user );
		wp_delete_user( $editor_user );
	}

	/**
	 * Test that the integrated get_inactive_users_count method now works correctly with network-wide queries
	 */
	public function test_integrated_get_inactive_users_count_network_wide() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create a site and users with different roles
		$site_id = $this->factory->blog->create();

		$admin_user = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);

		$editor_user = $this->factory->user->create([
			'role'            => 'editor',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);

		// Add users to the site with their respective roles
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_id );
		add_user_to_blog( $site_id, $admin_user, 'administrator' );
		add_user_to_blog( $site_id, $editor_user, 'editor' );
		restore_current_blog();

		// Make all users inactive
		update_user_meta( $admin_user, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		update_user_meta( $editor_user, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );

		// Remove ignore flags
		delete_user_meta( $admin_user, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		delete_user_meta( $editor_user, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Flush cache to ensure fresh counts
		Inactive_Users::flush_cache();

		// Test the integrated method with network-wide query (blog_id=0)
		$network_count = Inactive_Users::get_inactive_users_count( 0 );

		// The integrated method should now properly filter by capabilities/roles
		// It should include admin users but not editor users (based on the default config)
		$this->assertGreaterThanOrEqual( 1, $network_count, 'Network-wide count should include at least our admin user' );

		// Test site-specific query still works
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_id );
		$site_count = Inactive_Users::get_inactive_users_count( $site_id );
		restore_current_blog();

		$this->assertEquals( 1, $site_count, 'Site-specific count should only include administrator users' );

		// Clean up
		wp_delete_user( $admin_user );
		wp_delete_user( $editor_user );
	}

	/**
	 * Test that only inactive are returned for the blocked filter
	 */
	public function test_only_inactive_and_release_users_are_returned_blocked_filter(): void {
		// Simulate a "blocked" user filter request with nonce
		$_GET['last_seen_filter']       = 'blocked';
		$_GET['last_seen_filter_nonce'] = wp_create_nonce( 'last_seen_filter' );

		$cutoff = strtotime( '-' . $this->considered_inactive_after_days . ' days' );

		// User #1: Inactive — last seen before cutoff, no ignore meta
		$u1 = $this->factory()->user->create([
			'user_registered' => gmdate( 'Y-m-d H:i:s', $cutoff - WEEK_IN_SECONDS ),
			'role'            => 'administrator',
		]);
		update_user_meta( $u1, Inactive_Users::LAST_SEEN_META_KEY, $cutoff - DAY_IN_SECONDS );
		delete_user_meta( $u1, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$this->assertTrue( Inactive_Users::is_considered_inactive( $u1 ) );

		// User #2: Active — last seen after cutoff
		$u2 = $this->factory()->user->create([
			'role' => 'administrator',
		]);
		update_user_meta( $u2, Inactive_Users::LAST_SEEN_META_KEY, $cutoff + DAY_IN_SECONDS );
		delete_user_meta( $u2, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$this->assertFalse( Inactive_Users::is_considered_inactive( $u2 ) );

		// User #3: Ignored — last seen before cutoff, but ignore-until is in the future
		$u3 = $this->factory()->user->create([
			'user_registered' => gmdate( 'Y-m-d H:i:s', $cutoff - DAY_IN_SECONDS ),
			'role'            => 'administrator',
		]);
		update_user_meta( $u3, Inactive_Users::LAST_SEEN_META_KEY, $cutoff - DAY_IN_SECONDS );
		update_user_meta( $u3, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY, time() + DAY_IN_SECONDS );
		$this->assertFalse( Inactive_Users::is_considered_inactive( $u3 ) );

		// User #4: Inactive — no last seen, registered before cutoff, release timestamp forced into past
		update_option( Inactive_Users::LAST_SEEN_RELEASE_DATE_TIMESTAMP_OPTION_KEY, 0 );
		$u4 = $this->factory()->user->create([
			'user_registered' => gmdate( 'Y-m-d H:i:s', $cutoff - DAY_IN_SECONDS ),
			'role'            => 'administrator',
		]);
		delete_user_meta( $u4, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$this->assertTrue( Inactive_Users::is_considered_inactive( $u4 ) );

		// Run user query using blocked filter args
		$vars  = Inactive_Users::last_seen_blocked_users_filter_query_args( [] );
		$q     = new WP_User_Query( $vars );
		$found = wp_list_pluck( $q->get_results(), 'ID' );

		// Clean up GET globals
		unset( $_GET['last_seen_filter'], $_GET['last_seen_filter_nonce'] );
		// Assert: only u1 and u4 should be returned
		$this->assertContains( $u1, $found );

		$this->assertContains( $u4, $found );
		$this->assertNotContains( $u2, $found );

		// exception to the rule: $u3 is being listed, although it's not blocked, because we don't use the ignore flag for querying
		$this->assertContains( $u3, $found );

		// Cleanup created users
		wp_delete_user( $u1 );
		wp_delete_user( $u2 );
		wp_delete_user( $u3 );
		wp_delete_user( $u4 );
	}

	/**
	 * Test multisite security: admin can only unblock users who are members of current site
	 */
	public function test_multisite_unblock_when_user_not_member_of_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create a user who will be an admin on the current site
		$network_user_id = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);

		// Make the user inactive
		update_user_meta( $network_user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		delete_user_meta( $network_user_id, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Create a second site
		$site_2_id = $this->factory->blog->create();

		// Remove the user from the current site and add them to site 2
		remove_user_from_blog( $network_user_id, get_current_blog_id() );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_2_id );
		add_user_to_blog( $site_2_id, $network_user_id, 'administrator' );
		restore_current_blog();

		// Create an admin user on the current site
		$current_site_admin = $this->factory->user->create([
			'role' => 'administrator',
		]);

		// Set the current user to the admin of the current site
		wp_set_current_user( $current_site_admin );

		// Verify the network user is not a member of the current site
		$this->assertFalse( is_user_member_of_blog( $network_user_id, get_current_blog_id() ), 'Network user should not be a member of the current site' );

		// Simulate the unblock action request
		$_GET['action']                = 'reset_last_seen';
		$_GET['user_id']               = $network_user_id;
		$_GET['reset_last_seen_nonce'] = wp_create_nonce( 'reset_last_seen_action' );

		// Call the unblock action method
		Inactive_Users::last_seen_unblock_action();

		// Verify that notice was displayed
		set_current_screen( 'users' );
		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'You do not have permission to unblock this user.', $output );

		// Clean up
		wp_delete_user( $network_user_id );
		wp_delete_user( $current_site_admin );
		unset( $_GET['action'], $_GET['user_id'], $_GET['reset_last_seen_nonce'] );
	}

	/**
	 * Test multisite security: admin can unblock users who ARE members of current site
	 */
	public function test_multisite_unblock_when_user_is_member_of_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Create a user who IS a member of the current site
		$site_user_id = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);

		// Make the user inactive
		update_user_meta( $site_user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		delete_user_meta( $site_user_id, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Set the current user to be a super admin (who can edit any user)
		$super_admin_id = $this->factory->user->create([
			'role' => 'administrator',
		]);
		grant_super_admin( $super_admin_id );
		wp_set_current_user( $super_admin_id );

		// Verify the user IS a member of the current site
		$this->assertTrue( is_user_member_of_blog( $site_user_id, get_current_blog_id() ) );

		// Verify the user is initially inactive
		$this->assertTrue( Inactive_Users::is_considered_inactive( $site_user_id ) );

		// Verify the current user has edit_user capability for this user
		$this->assertTrue( current_user_can( 'edit_user', $site_user_id ), 'Super admin should have edit_user capability for any user' );

		// Simulate the unblock action request
		$_GET['action']                = 'reset_last_seen';
		$_GET['user_id']               = $site_user_id;
		$_GET['reset_last_seen_nonce'] = wp_create_nonce( 'reset_last_seen_action' );

		// Mock wp_safe_redirect to prevent actual redirect during test
		add_filter( 'wp_redirect', function () {
			// Just return false to prevent actual redirect
			return false;
		});

		// Call the unblock action method
		Inactive_Users::last_seen_unblock_action();

		// Verify that the user was successfully unblocked (should no longer be considered inactive)
		$this->assertFalse( Inactive_Users::is_considered_inactive( $site_user_id ) );

		// Clean up
		wp_delete_user( $site_user_id );
		wp_delete_user( $super_admin_id );
		unset( $_GET['action'], $_GET['user_id'], $_GET['reset_last_seen_nonce'] );
	}

	/**
	 * Test that single-site unblock action works
	 */
	public function test_single_site_unblock_action() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'This test is for single-site installations only' );
		}

		// Create a user
		$user_id = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);

		// Make the user inactive
		update_user_meta( $user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		delete_user_meta( $user_id, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );

		// Create an admin user
		$admin_user_id = $this->factory->user->create([
			'role' => 'administrator',
		]);

		// Set the current user to the admin
		wp_set_current_user( $admin_user_id );

		// Verify the user is initially inactive
		$this->assertTrue( Inactive_Users::is_considered_inactive( $user_id ) );

		// Simulate the unblock action request
		$_GET['action']                = 'reset_last_seen';
		$_GET['user_id']               = $user_id;
		$_GET['reset_last_seen_nonce'] = wp_create_nonce( 'reset_last_seen_action' );

		// Mock wp_safe_redirect to prevent actual redirect during test
		add_filter( 'wp_redirect', function () {
			return false;
		});

		// Call the unblock action method
		Inactive_Users::last_seen_unblock_action();

		// Verify that the user was successfully unblocked (should no longer be considered inactive)
		$this->assertFalse( Inactive_Users::is_considered_inactive( $user_id ) );

		// Clean up
		wp_delete_user( $user_id );
		wp_delete_user( $admin_user_id );
		unset( $_GET['action'], $_GET['user_id'], $_GET['reset_last_seen_nonce'] );
	}

	/**
	 * Test XML-RPC authentication for inactive users returns proper error message
	 */
	public function test_xmlrpc_authentication_inactive_user_error_message() {
		// Create an inactive user
		$user_id = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		update_user_meta( $user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		delete_user_meta( $user_id, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$user = new WP_User( $user_id );

		// Test authentication with inactive user
		$result = Inactive_Users::maybe_block_inactive_user_on_authenticate( $user );
		if ( ! defined( 'XMLRPC_REQUEST' ) ) {
			define( 'XMLRPC_REQUEST', true );
		}
		// Verify that authentication returns an error
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'inactive_account', $result->get_error_code() );
		$this->assertEquals( '<strong>Error</strong>: Your account has been flagged as inactive. Please contact your site Administrator.', $result->get_error_message() );

		// check that if we pass a WP_Error to the filter, the xmlrpc_login_error filter is added
		remove_all_filters( 'xmlrpc_login_error' );
		Inactive_Users::maybe_block_inactive_user_on_app_password_auth( true, $user );
		$result = Inactive_Users::maybe_block_inactive_user_on_authenticate( new \WP_Error( 'random_error', 'random error' ) );

		$this->assertTrue( has_filter( 'xmlrpc_login_error' ) !== false );
		$error = apply_filters( 'xmlrpc_login_error', null );
		$this->assertInstanceOf( 'IXR_Error', $error );
		$this->assertEquals( 403, $error->code );
		$this->assertEquals( 'Your account has been flagged as inactive. Please contact your site Administrator.', $error->message );

		wp_delete_user( $user_id );
	}

	/**
	 * Test application password authentication for inactive users
	 */
	public function test_application_password_authentication_inactive_user() {
		// Create an inactive user
		$user_id = $this->factory->user->create([
			'role'            => 'administrator',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		update_user_meta( $user_id, Inactive_Users::LAST_SEEN_META_KEY, strtotime( '-91 days' ) );
		delete_user_meta( $user_id, Inactive_Users::LAST_SEEN_IGNORE_INACTIVITY_CHECK_UNTIL_META_KEY );
		$user = new WP_User( $user_id );

		// Test application password availability for inactive user
		$available = Inactive_Users::maybe_block_inactive_user_on_app_password_auth( true, $user );
		$this->assertFalse( $available );

		// Verify that the error was stored properly for REST API usage
		$reflection = new ReflectionClass( Inactive_Users::class );
		$property   = $reflection->getProperty( 'application_password_authentication_error' );
		$property->setAccessible( true );
		$error = $property->getValue();

		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertEquals( 'inactive_account', $error->get_error_code() );
		$this->assertEquals( 'Your account has been flagged as inactive. Please contact your site Administrator.', $error->get_error_message() );
		$this->assertEquals( 403, $error->get_error_data()['status'] );

		wp_delete_user( $user_id );
	}
}
