<?php

use Automattic\VIP\Security\InactiveUsers\Inactive_Users;
use WP_UnitTestCase;

class InactiveUsersTest extends WP_UnitTestCase {
	private $user_id;
	
	public function setUp(): void {
		parent::setUp();
		
		// Create a test user with elevated capabilities and an old registration date
		$this->user_id = $this->factory->user->create([
			'role'            => 'editor',
			'user_registered' => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
		]);
		
		if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
			define('VIP_SECURITY_BOOST_CONFIGS', [
				'inactive_users' => [
					'mode'                           => 'BLOCK',
					'considered_inactive_after_days' => 90,
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
}
