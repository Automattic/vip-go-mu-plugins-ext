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
		// we're getting 3 users without MFA
		$expected_count  = 3;
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

		// Same count as the previous test - we have 3 users without MFA
		$expected_count  = 3;
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
		
		// Enable MFA for all users with administrator or editor roles using the mock
		foreach ( $all_users as $user ) {
			if ( array_intersect( [ 'administrator', 'editor' ], $user->roles ) ) {
				// Add to the mock's enabled user IDs array
				Two_Factor_Core::$mock_enabled_user_ids[] = $user->ID;
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
	 * Test that the admin notice (filtered view) displays correctly for custom high-privilege roles.
	 */
	public function test_display_mfa_disabled_notice_shows_correct_filtered_message_for_custom_roles() {
		$this->set_admin_screen_users();
		$_GET['filter_mfa_disabled'] = '1'; // Activate the filter

		// Set custom roles using reflection
		$reflection_class = new \ReflectionClass( Highlight_MFA_Users::class );
		$roles_property   = $reflection_class->getProperty( 'roles' );
		$roles_property->setAccessible( true );
		$custom_roles = [ 'author', 'contributor' ];
		$roles_property->setValue( null, $custom_roles );

		// Create users with custom roles
		$author_user_id      = $this->factory()->user->create( [ 'role' => 'author' ] );
		$contributor_user_id = $this->factory()->user->create( [ 'role' => 'contributor' ] );

		// Existing users (admin_user_mfa_disabled_id, editor_user_id, user ID 1) should not be counted
		// as 'administrator' and 'editor' are not in $custom_roles for this test.

		$expected_count  = 2; // The author and contributor created above.
		$show_all_url    = remove_query_arg( 'filter_mfa_disabled', admin_url( 'users.php' ) );
		$expected_output = sprintf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			sprintf(
				// translators: %d: Number of users.
				_n(
					'Showing %d user with high-privileges without Two-Factor Authentication enabled.',
					'Showing %d users with high-privileges without Two-Factor Authentication enabled.',
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
	 * Test that the admin notice displays correctly for custom high-privilege roles.
	 */
	public function test_display_mfa_disabled_notice_shows_for_custom_roles() {
		$this->set_admin_screen_users();

		// Set custom roles using reflection
		$reflection_class = new \ReflectionClass( Highlight_MFA_Users::class );
		$roles_property   = $reflection_class->getProperty( 'roles' );
		$roles_property->setAccessible( true );
		// Highlight_MFA_Users::init() in setUp already set roles to default.
		// We are overriding them here for this specific test.
		$custom_roles = [ 'author', 'contributor' ];
		$roles_property->setValue( null, $custom_roles );

		// Create users with custom roles
		$author_user_id = $this->factory()->user->create( [ 'role' => 'author' ] );
		// Ensure this user is MFA disabled (default for mock unless added to Two_Factor_Core::$mock_enabled_user_ids)

		$contributor_user_id = $this->factory()->user->create( [ 'role' => 'contributor' ] );
		// Ensure this user is MFA disabled

		// Existing users (admin_user_mfa_disabled_id, editor_user_id, user ID 1) should not be counted
		// as 'administrator' and 'editor' are not in $custom_roles for this test.

		$expected_count  = 2; // The author and contributor created above.
		$filter_url      = add_query_arg( 'filter_mfa_disabled', '1', admin_url( 'users.php' ) );
		$expected_output = sprintf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			sprintf(
				// translators: %d: Number of users.
				_n(
					'There is %d user with high-privileges with Two-Factor Authentication disabled.',
					'There are %d users with high-privileges with Two-Factor Authentication disabled.',
					$expected_count,
					'wpvip'
				),
				number_format_i18n( $expected_count )
			),
			esc_url( $filter_url ),
			esc_html__( 'Filter list to show these users.', 'wpvip' )
		);

		ob_start();
		Highlight_MFA_Users::display_mfa_disabled_notice();
		$output = ob_get_clean();

		$this->assertEquals( $expected_output, $output );

		// No need to explicitly clean up $author_user_id, $contributor_user_id as WP_UnitTestCase handles factory users.
		// No need to restore Highlight_MFA_Users::$roles as the next test's setUp() will call init() again.
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
	 * Test that the vip_site_details_index_security_boost_data filter is added and works correctly
	 */
	public function test_vip_site_details_index_security_boost_data_filter_is_added() {
		// Verify the filter is added during init
		$this->assertNotFalse( has_filter( 'vip_site_details_index_security_boost_data', [ 'Automattic\VIP\Security\MFAUsers\Highlight_MFA_Users', 'add_users_without_2fa_count_to_sds_payload' ] ) );

		// Test that the filter works by applying it
		$initial_data  = [ 'some_key' => 'some_value' ];
		$filtered_data = apply_filters( 'vip_site_details_index_security_boost_data', $initial_data );

		// Should preserve existing data
		$this->assertEquals( 'some_value', $filtered_data['some_key'] );
		// Should add MFA count data
		$this->assertArrayHasKey( 'users_without_2fa_count', $filtered_data );
		// Should add network-wide MFA count data in multisite
		if ( is_multisite() ) {
			$this->assertArrayHasKey( 'users_without_2fa_count_all_blogs', $filtered_data );
		}
		$this->assertIsInt( $filtered_data['users_without_2fa_count'] );
	}

	/**
	 * Test add_users_without_2fa_count_to_sds_payload method directly
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_add_users_without_2fa_count_to_sds_payload() {
		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		$initial_data = [ 'existing_key' => 'existing_value' ];
		$result_data  = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( $initial_data );

		// Should preserve existing data
		$this->assertEquals( 'existing_value', $result_data['existing_key'] );

		// Should add users_without_2fa_count
		$this->assertArrayHasKey( 'users_without_2fa_count', $result_data );
		$this->assertIsInt( $result_data['users_without_2fa_count'] );

		// Should add network-wide MFA count data in multisite
		if ( is_multisite() ) {
			$this->assertArrayHasKey( 'users_without_2fa_count_all_blogs', $result_data );
			$this->assertIsInt( $result_data['users_without_2fa_count_all_blogs'] );
		}

		// Should count exactly 3 users without MFA:
		// - admin_user_mfa_disabled_id (admin without MFA)
		// - editor_user_id (editor without MFA)
		// - Default WordPress user (ID 1, admin without MFA)
		// Excluded: admin_user_mfa_skipped_id (skipped), admin_wpcomvip_ignored_id (bot user)
		$this->assertEquals( 3, $result_data['users_without_2fa_count'] );

		// Should count exactly 3 users without MFA across the network
		if ( is_multisite() ) {
			$this->assertEquals( 3, $result_data['users_without_2fa_count_all_blogs'] );
		}
	}

	/**
	 * Test that SDS payload respects skipped user IDs
	 */
	public function test_sds_payload_respects_skipped_user_ids() {
		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		// First, remove the skipped user from skip list to get baseline
		delete_option( Highlight_MFA_Users::MFA_SKIP_USER_IDS_OPTION_KEY );
		Highlight_MFA_Users::clear_mfa_count_cache();

		$result_without_skip = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( [] );
		$count_without_skip  = $result_without_skip['users_without_2fa_count'];

		// Now add the user back to skip list
		update_option( Highlight_MFA_Users::MFA_SKIP_USER_IDS_OPTION_KEY, [ $this->admin_user_mfa_skipped_id ] );
		Highlight_MFA_Users::clear_mfa_count_cache();

		$result_with_skip = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( [] );
		$count_with_skip  = $result_with_skip['users_without_2fa_count'];

		// Should be 1 less with skip list (the skipped admin should be excluded)
		$this->assertEquals( $count_without_skip - 1, $count_with_skip );

		// Restore original skip list for other tests
		update_option( Highlight_MFA_Users::MFA_SKIP_USER_IDS_OPTION_KEY, [ $this->admin_user_mfa_skipped_id ] );
	}

	/**
	 * Test that SDS payload excludes wpcomvip bot user
	 */
	public function test_sds_payload_excludes_wpcomvip_bot() {
		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		// Get the current count (should exclude the bot user)
		$result_data = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( [] );

		// Should count exactly 3 users without MFA:
		// - admin_user_mfa_disabled_id (admin without MFA)
		// - editor_user_id (editor without MFA)
		// - Default WordPress user (ID 1, admin without MFA)
		// Excluded: admin_user_mfa_skipped_id (skipped), admin_wpcomvip_ignored_id (bot user)
		$this->assertEquals( 3, $result_data['users_without_2fa_count'] );

		// Verify the bot user exists but is not counted
		$bot_user = get_user_by( 'ID', $this->admin_wpcomvip_ignored_id );
		$this->assertNotFalse( $bot_user );
		$this->assertEquals( Configs::get_bot_login(), $bot_user->user_login );
	}

	/**
	 * Test that SDS payload returns zero when all eligible users have MFA enabled
	 */
	public function test_sds_payload_zero_when_all_users_have_mfa() {
		// Clear cache to ensure fresh calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		// Enable MFA for ALL eligible users (including the default WordPress user)
		// Get all admin and editor users to ensure we enable MFA for all of them
		$all_admin_editor_users = get_users( [ 'role__in' => [ 'administrator', 'editor' ] ] );
		$all_user_ids           = wp_list_pluck( $all_admin_editor_users, 'ID' );

		Two_Factor_Core::$mock_enabled_user_ids = $all_user_ids;

		// Clear cache after enabling MFA for all users
		Highlight_MFA_Users::clear_mfa_count_cache();

		$initial_data = [];
		$result_data  = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( $initial_data );

		// Should return zero since all eligible users have MFA
		$this->assertEquals( 0, $result_data['users_without_2fa_count'] );
	}

	/**
	 * Test that network-wide counts are correct with users across different blogs
	 */
	public function test_network_wide_counts_across_different_blogs() {
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

		// Test site 1 count - should count 1 user without MFA (editor)
		// Subscriber should be excluded as it's not in the target roles
		Highlight_MFA_Users::clear_mfa_count_cache();
		$site_1_result = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( [] );
		$this->assertEquals( 1, $site_1_result['users_without_2fa_count'], 'Site 1 should count 1 user without MFA (editor only)' );

		restore_current_blog();

		// Switch to site 2 and add users to it
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
		switch_to_blog( $site_2_id );
		add_user_to_blog( $site_2_id, $site_2_admin_user, 'administrator' );
		add_user_to_blog( $site_2_id, $site_2_editor_user, 'editor' );
		add_user_to_blog( $site_2_id, $site_2_author_user, 'author' );

		// Enable MFA for site 2 editor user only
		Two_Factor_Core::$mock_enabled_user_ids[] = $site_2_editor_user;

		// Test site 2 count - should count 1 user without MFA (admin)
		// Author should be excluded as it's not in the target roles (administrator, editor)
		Highlight_MFA_Users::clear_mfa_count_cache();
		$site_2_result = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( [] );
		$this->assertEquals( 1, $site_2_result['users_without_2fa_count'], 'Site 2 should count 1 user without MFA (admin only)' );

		restore_current_blog();

		// Clear cache to ensure fresh network-wide calculation
		Highlight_MFA_Users::clear_mfa_count_cache();

		// Test network-wide count - should count users without MFA across all sites
		// Expected:
		// - Original test users: admin_user_mfa_disabled_id (admin), editor_user_id (editor), default user ID 1 (admin) = 3
		// - Site 1: site_1_editor_user (editor without MFA) = 1
		// - Site 2: site_2_admin_user (admin without MFA) = 1
		// Total: 5 users without MFA across the network
		$network_result = Highlight_MFA_Users::add_users_without_2fa_count_to_sds_payload( [] );
		$this->assertEquals( 5, $network_result['users_without_2fa_count_all_blogs'], 'Network-wide should count 5 users without MFA across all sites' );

		// Verify that users with MFA enabled are not counted
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
