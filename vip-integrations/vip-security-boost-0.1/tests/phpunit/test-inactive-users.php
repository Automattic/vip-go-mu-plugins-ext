<?php

use Automattic\VIP\Security\InactiveUsers\Inactive_Users;
use WP_UnitTestCase;

class InactiveUsersTest extends WP_UnitTestCase {
	private $user_id;
	private $elevated_roles = [ 'administrator' ];

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
		$result = Inactive_Users::record_activity( $this->user_id );

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
		$result = Inactive_Users::authenticate( $user );

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

		$result = Inactive_Users::authenticate( $new_user );

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
}
