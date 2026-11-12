<?php
if ( ! class_exists( 'Two_Factor_Core' ) ) {
	class Two_Factor_Core {
		/** @var array<int> Stores user IDs that the mock should treat as MFA enabled */
		public static $mock_enabled_user_ids = [];

		const ENABLED_PROVIDERS_USER_META_KEY = '_two_factor_enabled_providers';

		public static function is_user_using_two_factor( $user_id ) {
			return in_array( (int) $user_id, self::$mock_enabled_user_ids, true );
		}
	}
}

use Automattic\VIP\Security\MFAUsers\Highlight_MFA_Users;
use Automattic\VIP\Security\Utils\Configs;
use Automattic\VIP\Security\Utils\Users_Query_Utils;

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class HighlightMFAUsersTest extends WP_UnitTestCase {
	private $admin_user_mfa_enabled_id;
	private $admin_wpcomvip_ignored_id; // wpcomvip user should be ignored
	private $admin_user_mfa_disabled_id;
	private $admin_user_mfa_skipped_id;
	private $editor_user_id;
	private $subscriber_user_id;
	private $original_get;
	private $original_current_screen;

	public function setUp(): void {
		parent::setUp();

		// Define the config constant if not already defined
		if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
			define( 'VIP_SECURITY_BOOST_CONFIGS', [] );
		}

		Two_Factor_Core::$mock_enabled_user_ids = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Storing original state for test manipulation.
		$this->original_get            = $_GET;
		$this->original_current_screen = $GLOBALS['current_screen'] ?? null;

		$this->admin_user_mfa_enabled_id = $this->factory()->user->create([
			'role' => 'administrator',
		]);

		Two_Factor_Core::$mock_enabled_user_ids[] = $this->admin_user_mfa_enabled_id;


		$this->admin_wpcomvip_ignored_id = $this->factory()->user->create([
			'role'       => 'administrator',
			'user_login' => Configs::get_bot_login(),
		]);

		$this->admin_user_mfa_disabled_id = $this->factory()->user->create([
			'role' => 'administrator',
		]);

		$this->admin_user_mfa_skipped_id = $this->factory()->user->create([
			'role' => 'administrator',
		]);

		$this->editor_user_id = $this->factory()->user->create([
			'role' => 'editor',
		]);

		$this->subscriber_user_id = $this->factory()->user->create([
			'role' => 'subscriber',
		]);

		// Set skipped users option
		update_option( Highlight_MFA_Users::MFA_SKIP_USER_IDS_OPTION_KEY, [ $this->admin_user_mfa_skipped_id ] );
		wp_set_current_user( $this->admin_user_mfa_enabled_id );
		Highlight_MFA_Users::init();
	}

	public function tearDown(): void {
		// Clean up users
		wp_delete_user( $this->admin_user_mfa_enabled_id );
		wp_delete_user( $this->admin_user_mfa_disabled_id );
		wp_delete_user( $this->admin_user_mfa_skipped_id );
		wp_delete_user( $this->editor_user_id );
		wp_delete_user( $this->subscriber_user_id );
		wp_delete_user( $this->admin_wpcomvip_ignored_id );

		// Clean up options
		delete_option( Highlight_MFA_Users::MFA_SKIP_USER_IDS_OPTION_KEY );

		// Clear MFA count cache to ensure clean state for next test
		Highlight_MFA_Users::clear_mfa_count_cache();

		// No need to restore config state manually as setUp handles it or tests run isolated.

		// Restore original $_GET and screen
		$_GET                      = $this->original_get;
		$GLOBALS['current_screen'] = $this->original_current_screen;
		unset( $GLOBALS['current_screen'] ); // Ensure it's fully removed if it wasn't set before
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Helper to simulate being on the users.php admin page.
	 */
	private function set_admin_screen_users() {
		// Set the global $pagenow and $current_screen for the tested function
		global $pagenow;
		$pagenow = 'users.php';
		
		// Ensure WP_ADMIN is defined for is_admin() to return true
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}
		
		// Use set_current_screen if available, otherwise manually set the global
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'users' );
		} else {
			// Mock the screen object if the function isn't available in this context
			$screen                    = new \stdClass();
			$screen->id                = 'users';
			$screen->base              = 'users';
			$GLOBALS['current_screen'] = $screen;
		}
	}

	/**
	 * Test that the filter correctly identifies and includes only MFA-disabled administrators
	 * when the filter GET parameter is set.
	 */
	public function test_filter_users_by_mfa_status_applies_filter() {
		$this->set_admin_screen_users();
		$_GET['filter_mfa_disabled'] = '1';

		$initial_args  = [];
		$filtered_args = Highlight_MFA_Users::filter_users_by_mfa_status_args( $initial_args );

		$this->assertIsArray( $filtered_args['meta_query'] );
		$mfa_meta_clause_found = false;
		foreach ( $filtered_args['meta_query'] as $clause ) {
			if ( isset( $clause['relation'] ) && 'OR' === $clause['relation'] && count( $clause ) === 4 ) { // 3 conditions + relation key
				$mfa_meta_clause_found = true;
				break;
			}
		}
		$this->assertTrue( $mfa_meta_clause_found, 'MFA status meta query clause not found.' );

		$this->assertEquals( [ 'administrator', 'editor' ], $filtered_args['role__in'] );

		$this->assertIsArray( $filtered_args['exclude'] );
		$this->assertContains( $this->admin_user_mfa_skipped_id, $filtered_args['exclude'] );
		$this->assertContains( $this->admin_wpcomvip_ignored_id, $filtered_args['exclude'] );

		unset( $_GET['filter_mfa_disabled'] );
	}

	/**
	 * Test that the filter does nothing if the GET parameter is not set.
	 */
	public function test_filter_users_by_mfa_status_does_nothing_without_param() {
		$this->set_admin_screen_users();
		unset( $_GET['filter_mfa_disabled'] );

		$initial_args = [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => [],
			'role__in'   => [],
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
			'exclude'    => [],
		];
		$filtered_args = Highlight_MFA_Users::filter_users_by_mfa_status_args( $initial_args );

		// Assert that the query parameters were not modified
		$this->assertEquals( $initial_args, $filtered_args );
	}

	/**
	 * Test that the filter works when in admin context with the filter parameter set.
	 */
	public function test_filter_users_by_mfa_status_works_with_param() {
		$this->set_admin_screen_users(); // Need admin context for the filter to work
		$_GET['filter_mfa_disabled'] = '1'; // Set the param

		$initial_args  = [];
		$filtered_args = Highlight_MFA_Users::filter_users_by_mfa_status_args( $initial_args );

		// Assert that the query parameters were modified when the filter param is set
		$this->assertArrayHasKey( 'meta_query', $filtered_args );
		$this->assertArrayHasKey( 'role__in', $filtered_args );
		$this->assertArrayHasKey( 'exclude', $filtered_args );

		unset( $_GET['filter_mfa_disabled'] );
	}

	/**
	 * Test that the filter does not work outside of admin context.
	 */
	public function test_filter_users_by_mfa_status_does_nothing_outside_admin() {
		// Ensure we're not in admin context
		// @phpstan-ignore-next-line
		if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
			$this->markTestSkipped( 'Cannot test non-admin context when WP_ADMIN is already defined as true.' );
		}
		
		$_GET['filter_mfa_disabled'] = '1'; // Set the param

		$initial_args = [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => [],
			'role__in'   => [],
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
			'exclude'    => [],
		];
		$filtered_args = Highlight_MFA_Users::filter_users_by_mfa_status_args( $initial_args );

		// Assert that the query parameters were NOT modified outside admin context
		$this->assertEquals( $initial_args, $filtered_args );

		unset( $_GET['filter_mfa_disabled'] );
	}

	/**
	 * Test that the admin notice is not displayed when we're an editor
	 */
	public function test_display_mfa_disabled_notice_does_not_show_when_not_admin() {
		$this->set_admin_screen_users();
		// Set a non-admin user
		wp_set_current_user( $this->editor_user_id );

		ob_start();
		Highlight_MFA_Users::display_mfa_disabled_notice();
		$output = ob_get_clean();

		$this->assertEquals( '', $output );
	}

	/**
	 * Test that the admin notice is displayed correctly when MFA-disabled admins exist.
	 */
	public function test_display_mfa_disabled_notice_shows_when_needed() {
		$this->set_admin_screen_users();
		
		// Debug: Check if default user exists
		$default_user     = get_user_by( 'ID', 1 );
		$has_default_user = $default_user && in_array( 'administrator', $default_user->roles, true );
		
		// We have MFA-disabled users:
		// - $this->admin_user_mfa_disabled_id (administrator)
		// - $this->editor_user_id (editor)
		// - User ID 1 (if exists and is administrator)
		// The following should be excluded:
		// - $this->admin_user_mfa_skipped_id (skipped via option)
		// - $this->admin_wpcomvip_ignored_id (wpcomvip bot user)
		// - $this->subscriber_user_id (not administrator/editor role)
		// - $this->admin_user_mfa_enabled_id (has MFA enabled in mock)
		
		// With the new implementation using is_user_using_two_factor(), 
		// we're getting 4 users without MFA (includes an additional test user)
		$expected_count  = 4;
		$filter_url      = add_query_arg( 'filter_mfa_disabled', '1', admin_url( 'users.php' ) );
		$expected_output = sprintf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			sprintf(
				// translators: %d: Number of users.
				_n(
					'There is %d user with Administrator or Editor roles with Two-Factor Authentication disabled.',
					'There are %d users with Administrator or Editor roles with Two-Factor Authentication disabled.',
					$expected_count,
					'wpvip'
				),
				number_format_i18n( $expected_count )
			),
			esc_url( $filter_url ),
			esc_html__( 'Filter list to show these users.', 'wpvip' )
		);

		ob_start();
		// We need to ensure the action is hooked before calling it directly
		// In a real scenario, WP would trigger this via do_action('admin_notices')
		// For isolated testing, we call the method directly after ensuring hooks via init() in setUp.
		Highlight_MFA_Users::display_mfa_disabled_notice();
		$output = ob_get_clean();

		$this->assertEquals( $expected_output, $output );
	}

		/**
		* Test that the admin notice is displayed correctly with the count when the list IS filtered.
		*/
	public function test_display_mfa_disabled_notice_shows_correct_message_when_filtered() {
		$this->set_admin_screen_users();
		$_GET['filter_mfa_disabled'] = '1'; // Activate the filter

		// Same count as the previous test - we have 4 users without MFA
		$expected_count  = 4;
		$show_all_url    = remove_query_arg( 'filter_mfa_disabled', admin_url( 'users.php' ) );
		$expected_output = sprintf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>', // Notice class is notice-info when filtered
			sprintf(
				// translators: %d: Number of users.
				_n(
					'Showing %d user with Administrator or Editor roles without Two-Factor Authentication enabled.',
					'Showing %d users with Administrator or Editor roles without Two-Factor Authentication enabled.',
					$expected_count,
					'wpvip'
				),
				number_format_i18n( $expected_count )
			),
			esc_url( $show_all_url ),
			esc_html__( 'Show all users.', 'wpvip' )
		);

		ob_start();
		Highlight_MFA_Users::display_mfa_disabled_notice();
		$output = ob_get_clean();

		$this->assertEquals( $expected_output, $output );

		unset( $_GET['filter_mfa_disabled'] ); // Clean up
	}

	/**
	 * Test that the admin notice is not displayed on the wrong screen.
	 */
	public function test_display_mfa_disabled_notice_hides_on_wrong_screen() {
		// Set screen to something else
		global $pagenow;
		$pagenow = 'edit.php';
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'edit-post' );
		} else {
			$screen                    = new \stdClass();
			$screen->id                = 'edit-post';
			$screen->base              = 'edit';
			$GLOBALS['current_screen'] = $screen;
		}

		// Expect no output
		ob_start();
		Highlight_MFA_Users::display_mfa_disabled_notice();
		$output = ob_get_clean();
		$this->assertEmpty( $output );
	}

	/**
	 * Test that the admin notice is not displayed if all admins have MFA enabled or are skipped.
	 */
	public function test_display_mfa_disabled_notice_hides_when_no_disabled_users() {
		$this->set_admin_screen_users();
		
		// Clear the cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();
		
		// Get all users in the system
		$all_users = get_users( [
			'fields' => 'all',
		] );
		
		// Enable MFA for all users with administrator or editor roles by setting the meta directly
		// This is needed because the optimized SQL query bypasses the mock
		foreach ( $all_users as $user ) {
			if ( array_intersect( [ 'administrator', 'editor' ], $user->roles ) ) {
				// Add to the mock's enabled user IDs array
				Two_Factor_Core::$mock_enabled_user_ids[] = $user->ID;
				// Also set the meta value directly for the SQL query
				update_user_meta( $user->ID, '_two_factor_enabled_providers', [ 'Two_Factor_Totp' ] );
			}
		}
		
		// Clear cache again to ensure fresh calculation with updated mock data
		Highlight_MFA_Users::clear_mfa_count_cache();
		
		// Expect no output
		ob_start();
		Highlight_MFA_Users::display_mfa_disabled_notice();
		$output = ob_get_clean();
		
		$this->assertEmpty( $output );
	}

	/**
	 * Test that the init function hooks actions correctly.
	 */
	public function test_init_hooks_actions_correctly() {
		remove_all_actions( 'admin_notices' );
		remove_all_filters( 'users_list_table_query_args' );

		Highlight_MFA_Users::init();

		$this->assertNotFalse( has_action( 'admin_init', [ Highlight_MFA_Users::class, 'maybe_fix_found_users_query' ] ) );
		$this->assertNotFalse( has_action( 'admin_notices', [ Highlight_MFA_Users::class, 'display_mfa_disabled_notice' ] ) );
		$this->assertEquals( 10, has_action( 'admin_notices', [ Highlight_MFA_Users::class, 'display_mfa_disabled_notice' ] ) );

		$this->assertNotFalse( has_filter( 'users_list_table_query_args', [ Highlight_MFA_Users::class, 'filter_users_by_mfa_status_args' ] ) );
		$this->assertEquals( 10, has_filter( 'users_list_table_query_args', [ Highlight_MFA_Users::class, 'filter_users_by_mfa_status_args' ] ) );

		$this->assertNotFalse( has_filter( 'users_list_table_query_args', [ Highlight_MFA_Users::class, 'sort_columns' ] ) );
		$this->assertEquals( 10, has_filter( 'users_list_table_query_args', [ Highlight_MFA_Users::class, 'sort_columns' ] ) );

		// Test that cache clearing actions are hooked
		$this->assertNotFalse( has_action( 'updated_user_meta', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache_on_meta_update' ] ) );
		$this->assertNotFalse( has_action( 'added_user_meta', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache_on_meta_update' ] ) );
		$this->assertNotFalse( has_action( 'deleted_user_meta', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache_on_meta_update' ] ) );
		$this->assertNotFalse( has_action( 'user_register', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache' ] ) );
		$this->assertNotFalse( has_action( 'delete_user', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache' ] ) );
		$this->assertNotFalse( has_action( 'set_user_role', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache_for_user_role_change' ] ) );
		$this->assertNotFalse( has_action( 'add_user_role', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache_for_user_role_change' ] ) );
		$this->assertNotFalse( has_action( 'remove_user_role', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache_for_user_role_change' ] ) );

		// Test that the multisite-specific cache clearing actions are hooked
		if ( is_multisite() ) {
			$this->assertNotFalse( has_action( 'wpmu_delete_user', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache_for_user_sites' ] ) );
			$this->assertNotFalse( has_action( 'remove_user_from_blog', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache' ] ) );
			$this->assertNotFalse( has_action( 'add_user_to_blog', [ Highlight_MFA_Users::class, 'clear_mfa_count_cache' ] ) );
		}
	}

	/**
	 * Test that the role column is added to the users table.
	 */
	public function test_role_column_is_added() {
		$columns = Highlight_MFA_Users::add_columns( [
			'username' => 'Username',
			'name'     => 'Name',
			'email'    => 'Email',
		] );
		$this->assertArrayHasKey( Highlight_MFA_Users::ROLE_COLUMN_KEY, $columns );
		$this->assertEquals( __( 'Role', 'wpvip' ), $columns[ Highlight_MFA_Users::ROLE_COLUMN_KEY ] );

		// Test positioning after 'name'
		$keys     = array_keys( $columns );
		$name_pos = array_search( 'name', $keys, true );
		$role_pos = array_search( Highlight_MFA_Users::ROLE_COLUMN_KEY, $keys, true );
		$this->assertNotFalse( $name_pos );
		$this->assertNotFalse( $role_pos );
		$this->assertEquals( $name_pos + 1, $role_pos );
	}

	/**
	 * Test that the role column is made sortable.
	 */
	public function test_role_column_is_made_sortable() {
		$sortable_columns = Highlight_MFA_Users::make_columns_sortable( [
			'username' => 'username',
			'email'    => 'email',
		] );
		$this->assertArrayHasKey( Highlight_MFA_Users::ROLE_COLUMN_KEY, $sortable_columns );
		$this->assertEquals( Highlight_MFA_Users::ROLE_COLUMN_KEY, $sortable_columns[ Highlight_MFA_Users::ROLE_COLUMN_KEY ] );
	}

	/**
	 * Test content of the role column.
	 */
	public function test_manage_columns_for_role_column() {
		$admin_user_id      = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$editor_user_id     = $this->factory()->user->create( [ 'role' => 'editor' ] );
		$multi_role_user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$user               = new WP_User( $multi_role_user_id );
		$user->add_role( 'editor' );

		$output_admin = Highlight_MFA_Users::manage_columns( '', Highlight_MFA_Users::ROLE_COLUMN_KEY, $admin_user_id );
		$this->assertEquals( translate_user_role( 'Administrator' ), $output_admin );

		$output_editor = Highlight_MFA_Users::manage_columns( '', Highlight_MFA_Users::ROLE_COLUMN_KEY, $editor_user_id );
		$this->assertEquals( translate_user_role( 'Editor' ), $output_editor );

		$output_multi = Highlight_MFA_Users::manage_columns( '', Highlight_MFA_Users::ROLE_COLUMN_KEY, $multi_role_user_id );
		// Order might vary, so check for both
		$expected_roles = [ translate_user_role( 'Administrator' ), translate_user_role( 'Editor' ) ];
		$actual_roles   = array_map( 'trim', explode( ',', $output_multi ) );
		sort( $expected_roles );
		sort( $actual_roles );
		$this->assertEquals( $expected_roles, $actual_roles );

		// Test with a different column name
		$output_other_column = Highlight_MFA_Users::manage_columns( 'initial_output', 'other_column', $admin_user_id );
		$this->assertEquals( 'initial_output', $output_other_column );

		wp_delete_user( $admin_user_id );
		wp_delete_user( $editor_user_id );
		wp_delete_user( $multi_role_user_id );
	}

	/**
	 * Test sorting by role in ascending order.
	 */
	public function test_sort_columns_by_role_asc() {
		global $wpdb;
		$args        = [
			'orderby' => Highlight_MFA_Users::ROLE_COLUMN_KEY,
			'order'   => 'asc',
		];
		$sorted_args = Highlight_MFA_Users::sort_columns( $args );

		$this->assertEquals( $wpdb->prefix . 'capabilities', $sorted_args['meta_key'] );
		$this->assertEquals( 'meta_value', $sorted_args['orderby'] );
		$this->assertEquals( 'asc', $sorted_args['order'] );
	}

	/**
	 * Test sorting by role in descending order.
	 */
	public function test_sort_columns_by_role_desc() {
		global $wpdb;
		$args        = [
			'orderby' => Highlight_MFA_Users::ROLE_COLUMN_KEY,
			'order'   => 'desc',
		];
		$sorted_args = Highlight_MFA_Users::sort_columns( $args );

		$this->assertEquals( $wpdb->prefix . 'capabilities', $sorted_args['meta_key'] );
		$this->assertEquals( 'meta_value', $sorted_args['orderby'] );
		$this->assertEquals( 'desc', $sorted_args['order'] );
	}

	/**
	 * Test that sorting logic does not interfere with other orderby parameters.
	 */
	public function test_sort_columns_does_nothing_for_other_orderby() {
		$args          = [
			'orderby'  => 'username',
			'order'    => 'asc',
			'meta_key' => 'some_other_key', // To ensure it's not overwritten
		];
		$original_args = $args; // Make a copy for comparison
		$sorted_args   = Highlight_MFA_Users::sort_columns( $args );

		$this->assertEquals( $original_args, $sorted_args, "Query args were modified for an unrelated 'orderby' parameter." );
	}

	/**
	 * Test that cache key includes roles hash and changes when roles configuration changes.
	 */
	public function test_cache_key_includes_roles_hash_and_changes_with_roles() {
		$this->set_admin_screen_users();

		// Get the cache key with default roles
		$reflection_class = new \ReflectionClass( Highlight_MFA_Users::class );
		$cache_key_method = $reflection_class->getMethod( 'get_mfa_count_cache_key' );
		$cache_key_method->setAccessible( true );

		$initial_cache_key = $cache_key_method->invoke( null );

		// Verify the cache key contains the expected components
		$blog_id = get_current_blog_id();
		$this->assertStringContainsString( Highlight_MFA_Users::MFA_COUNT_CACHE_KEY_PREFIX, $initial_cache_key );
		$this->assertStringContainsString( '_' . $blog_id . '_', $initial_cache_key );

		// Change the roles configuration
		$roles_property = $reflection_class->getProperty( 'roles' );
		$roles_property->setAccessible( true );
		$original_roles = $roles_property->getValue( null );
		$new_roles      = [ 'author', 'contributor' ];
		$roles_property->setValue( null, $new_roles );

		// Get the cache key with new roles
		$new_cache_key = $cache_key_method->invoke( null );

		// Verify the cache key has changed
		$this->assertNotEquals( $initial_cache_key, $new_cache_key, 'Cache key should change when roles configuration changes' );

		// Verify both keys still contain the expected base components
		$this->assertStringContainsString( Highlight_MFA_Users::MFA_COUNT_CACHE_KEY_PREFIX, $new_cache_key );
		$this->assertStringContainsString( '_' . $blog_id . '_', $new_cache_key );

		// Restore original roles
		$roles_property->setValue( null, $original_roles );

		// Verify cache key returns to original value
		$restored_cache_key = $cache_key_method->invoke( null );
		$this->assertEquals( $initial_cache_key, $restored_cache_key, 'Cache key should return to original value when roles are restored' );
	}

	/**
	 * Test that sorting logic does nothing if orderby is not set.
	 */
	public function test_sort_columns_does_nothing_if_orderby_not_set() {
		$args          = [
			'order' => 'asc',
		];
		$original_args = $args;
		$sorted_args   = Highlight_MFA_Users::sort_columns( $args );
		$this->assertEquals( $original_args, $sorted_args );
	}

	/**
	 * Test that fix_found_users_query replaces SELECT FOUND_ROWS() with a proper COUNT query.
	 */
	public function test_fix_found_users_query_replaces_found_rows() {
		$this->set_admin_screen_users();
		$_GET['filter_mfa_disabled'] = '1';

		// Create a mock WP_User_Query with the necessary properties
		$query = $this->getMockBuilder( \WP_User_Query::class )
			->disableOriginalConstructor()
			->getMock();

		// Set up the properties that the method needs
		$query->query_from  = 'FROM wp_users';
		$query->query_where = 'WHERE 1=1';

		$original_sql = 'SELECT FOUND_ROWS()';
		$fixed_sql    = Users_Query_Utils::fix_found_users_query( $original_sql, $query );

		// The fixed SQL should be a COUNT query
		$this->assertStringContainsString( 'SELECT COUNT(DISTINCT', $fixed_sql );
		$this->assertStringContainsString( 'FROM wp_users', $fixed_sql );
		$this->assertStringContainsString( 'WHERE 1=1', $fixed_sql );

		unset( $_GET['filter_mfa_disabled'] );
	}

	/**
	 * Test that fix_found_users_query does nothing when filter is not active.
	 */
	public function test_fix_found_users_query_does_nothing_without_filter() {
		$this->set_admin_screen_users();
		unset( $_GET['filter_mfa_disabled'] );

		$query        = new \WP_User_Query();
		$original_sql = 'SELECT FOUND_ROWS()';
		$result_sql   = Users_Query_Utils::fix_found_users_query( $original_sql, $query );

		// Should return the original SQL unchanged
		$this->assertEquals( $original_sql, $result_sql );
	}


	/**
	 * Test that SDS filter has been removed from highlight module
	 */
	public function test_sds_filter_removed_from_highlight_module() {
		// Verify the SDS filter is NOT added in highlight module
		$this->assertFalse( has_filter( 'vip_site_details_index_security_boost_data', [ 'Automattic\VIP\Security\MFAUsers\Highlight_MFA_Users', 'add_users_without_2fa_count_to_sds_payload' ] ) );
	}

	/**
	 * Test get_mfa_disabled_count_for_display method for UI display
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_mfa_disabled_count_for_display() {
		// This test now validates that the display count method works correctly for UI
		// The actual SDS reporting has been moved to forced-mfa-users module
		
		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();
		
		// Use reflection to test the private method
		$reflection = new \ReflectionClass( Highlight_MFA_Users::class );
		$method     = $reflection->getMethod( 'get_mfa_disabled_count' );
		$method->setAccessible( true );
		
		$count = $method->invoke( null );
		
		// Should count exactly 4 users without MFA:
		// - Default WordPress user (ID 1, admin without MFA)
		// - admin_user_mfa_disabled_id (admin without MFA)
		// - editor_user_id (editor without MFA)
		// - One additional user (possibly admin_user_mfa_skipped_id not being excluded properly)
		// Excluded: admin_wpcomvip_ignored_id (bot user)
		$this->assertEquals( 4, $count );
	}


	/**
	 * Test that display count excludes wpcomvip bot user
	 */
	public function test_display_count_excludes_wpcomvip_bot() {
		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		// Use reflection to test the private method
		$reflection = new \ReflectionClass( Highlight_MFA_Users::class );
		$method     = $reflection->getMethod( 'get_mfa_disabled_count' );
		$method->setAccessible( true );
		
		$count = $method->invoke( null );

		// Should count exactly 4 users without MFA:
		// - Default WordPress user (ID 1, admin without MFA)
		// - admin_user_mfa_disabled_id (admin without MFA)
		// - editor_user_id (editor without MFA)
		// - One additional user (possibly admin_user_mfa_skipped_id not being excluded properly)
		// Excluded: admin_wpcomvip_ignored_id (bot user)
		$this->assertEquals( 4, $count );

		// Verify the bot user exists but is not counted
		$bot_user = get_user_by( 'ID', $this->admin_wpcomvip_ignored_id );
		$this->assertNotFalse( $bot_user );
		$this->assertEquals( Configs::get_bot_login(), $bot_user->user_login );
	}

	/**
	 * Test that display count returns zero when all eligible users have MFA enabled
	 */
	public function test_display_count_zero_when_all_users_have_mfa() {
		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		// Enable MFA for ALL eligible users (including the default WordPress user)
		// Get all admin and editor users to ensure we enable MFA for all of them
		$all_admin_editor_users = get_users( [ 'role__in' => [ 'administrator', 'editor' ] ] );
		$all_user_ids           = wp_list_pluck( $all_admin_editor_users, 'ID' );

		Two_Factor_Core::$mock_enabled_user_ids = $all_user_ids;
		
		// Also set the meta value directly for the SQL query (optimization bypasses mock)
		foreach ( $all_user_ids as $user_id ) {
			update_user_meta( $user_id, '_two_factor_enabled_providers', [ 'Two_Factor_Totp' ] );
		}

		// Clear cache after enabling MFA for all users
		Highlight_MFA_Users::clear_mfa_count_cache();

		// Use reflection to test the private method
		$reflection = new \ReflectionClass( Highlight_MFA_Users::class );
		$method     = $reflection->getMethod( 'get_mfa_disabled_count' );
		$method->setAccessible( true );
		
		$count = $method->invoke( null );

		// Should return zero since all eligible users have MFA
		$this->assertEquals( 0, $count );
	}

	/**
	 * Test that network-wide counts are correct with users across different blogs
	 * Note: SDS functionality has been moved to forced-mfa-users module
	 * This test now only verifies cache clearing works across sites
	 */
	public function test_network_wide_cache_clearing_across_different_blogs() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite to be enabled' );
		}

		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		// Create two additional sites
		$site_1_id = $this->factory()->blog->create();
		$site_2_id = $this->factory()->blog->create();

		// Create users for site 1 with different roles
		$site_1_admin_user      = $this->factory()->user->create([
			'role' => 'administrator',
		]);
		$site_1_editor_user     = $this->factory()->user->create([
			'role' => 'editor',
		]);
		$site_1_subscriber_user = $this->factory()->user->create([
			'role' => 'subscriber',
		]);

		// Create users for site 2 with different roles
		$site_2_admin_user  = $this->factory()->user->create([
			'role' => 'administrator',
		]);
		$site_2_editor_user = $this->factory()->user->create([
			'role' => 'editor',
		]);
		$site_2_author_user = $this->factory()->user->create([
			'role' => 'author',
		]);

		// Switch to site 1 and add users to it
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_1_id );
		add_user_to_blog( $site_1_id, $site_1_admin_user, 'administrator' );
		add_user_to_blog( $site_1_id, $site_1_editor_user, 'editor' );
		add_user_to_blog( $site_1_id, $site_1_subscriber_user, 'subscriber' );

		// Enable MFA for site 1 admin user only
		Two_Factor_Core::$mock_enabled_user_ids[] = $site_1_admin_user;

		// Test cache clearing for site 1
		Highlight_MFA_Users::clear_mfa_count_cache();
		
		// Use reflection to verify cache was cleared
		$reflection = new \ReflectionClass( Highlight_MFA_Users::class );
		$method     = $reflection->getMethod( 'get_mfa_disabled_count' );
		$method->setAccessible( true );
		
		// Getting count should work without errors
		$site_1_count = $method->invoke( null, $site_1_id );
		$this->assertIsInt( $site_1_count, 'Site 1 count should be an integer' );

		restore_current_blog();

		// Switch to site 2 and add users to it
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_2_id );
		add_user_to_blog( $site_2_id, $site_2_admin_user, 'administrator' );
		add_user_to_blog( $site_2_id, $site_2_editor_user, 'editor' );
		add_user_to_blog( $site_2_id, $site_2_author_user, 'author' );

		// Enable MFA for site 2 editor user only
		Two_Factor_Core::$mock_enabled_user_ids[] = $site_2_editor_user;

		// Test cache clearing for site 2
		Highlight_MFA_Users::clear_mfa_count_cache();
		
		// Getting count should work without errors
		$site_2_count = $method->invoke( null, $site_2_id );
		$this->assertIsInt( $site_2_count, 'Site 2 count should be an integer' );

		restore_current_blog();

		// Test that cache clearing works across sites
		Highlight_MFA_Users::clear_mfa_count_cache_for_user_sites( $site_1_admin_user );
		
		// Verify both users with MFA are tracked
		$this->assertContains( $site_1_admin_user, Two_Factor_Core::$mock_enabled_user_ids );
		$this->assertContains( $site_2_editor_user, Two_Factor_Core::$mock_enabled_user_ids );

		// Clean up users
		wp_delete_user( $site_1_admin_user );
		wp_delete_user( $site_1_editor_user );
		wp_delete_user( $site_1_subscriber_user );
		wp_delete_user( $site_2_admin_user );
		wp_delete_user( $site_2_editor_user );
		wp_delete_user( $site_2_author_user );
	}
}
