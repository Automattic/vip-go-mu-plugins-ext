<?php
if ( ! class_exists( 'Two_Factor_Core' ) ) {
	class Two_Factor_Core {
		/** @var array<int> Stores user IDs that the mock should treat as MFA enabled */
		public static $mock_enabled_user_ids = [];

		public static function is_user_using_two_factor( $user_id ) {
			return in_array( (int) $user_id, self::$mock_enabled_user_ids, true );
		}
	}
}

use Automattic\VIP\Security\MFAUsers\Highlight_MFA_Users;
use WP_User_Query;

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
			'user_login' => 'wpcomvip',
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

		// No need to restore config state manually as setUp handles it or tests run isolated.

		// Restore original $_GET and screen
		$_GET                      = $this->original_get;
		$GLOBALS['current_screen'] = $this->original_current_screen;
		unset( $GLOBALS['current_screen'] ); // Ensure it's fully removed if it wasn't set before

		parent::tearDown();
	}

	/**
	 * Helper to simulate being on the users.php admin page.
	 */
	private function set_admin_screen_users() {
		// Set the global $pagenow and $current_screen for the tested function
		global $pagenow;
		$pagenow = 'users.php';
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

		$query = new \WP_User_Query();
		Highlight_MFA_Users::filter_users_by_mfa_status( $query );

		$meta_query     = $query->get( 'meta_query' );
		$roles_in_query = $query->get( 'role__in' );
		$exclude_query  = $query->get( 'exclude' );

		$this->assertIsArray( $meta_query );
		$mfa_meta_clause_found = false;
		foreach ( $meta_query as $clause ) {
			if ( isset( $clause['relation'] ) && 'OR' === $clause['relation'] && count( $clause ) === 4 ) { // 3 conditions + relation key
				$mfa_meta_clause_found = true;
				break;
			}
		}
		$this->assertTrue( $mfa_meta_clause_found, 'MFA status meta query clause not found.' );

		$this->assertEquals( [ 'administrator', 'editor' ], $roles_in_query );

		$this->assertIsArray( $exclude_query );
		$this->assertContains( $this->admin_user_mfa_skipped_id, $exclude_query );
		$this->assertContains( $this->admin_wpcomvip_ignored_id, $exclude_query );

		unset( $_GET['filter_mfa_disabled'] );
	}

	/**
	 * Test that the filter does nothing if the GET parameter is not set.
	 */
	public function test_filter_users_by_mfa_status_does_nothing_without_param() {
		$this->set_admin_screen_users();
		unset( $_GET['filter_mfa_disabled'] );

		$query                        = new \WP_User_Query();
		$original_meta_query          = $query->get( 'meta_query' );
		$original_capability_in_query = $query->get( 'capability__in' );
		$original_exclude_query       = $query->get( 'exclude' );

		Highlight_MFA_Users::filter_users_by_mfa_status( $query );

		// Assert that the query parameters were not modified
		$this->assertEquals( $original_meta_query, $query->get( 'meta_query' ) );
		$this->assertEquals( $original_capability_in_query, $query->get( 'capability__in' ) );
		$this->assertEquals( $original_exclude_query, $query->get( 'exclude' ) );
	}

	/**
	 * Test that the filter does nothing if not on the users.php page.
	 */
	public function test_filter_users_by_mfa_status_does_nothing_on_wrong_page() {
		global $pagenow;
		$pagenow = 'edit.php';
		// Use set_current_screen if available, otherwise manually set the global
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'edit-post' );
		} else {
			// Mock the screen object if the function isn't available in this context
			$screen                    = new \stdClass();
			$screen->id                = 'edit-post';
			$screen->base              = 'edit';
			$GLOBALS['current_screen'] = $screen;
		}
		$_GET['filter_mfa_disabled'] = '1'; // Set the param

		$query                        = new \WP_User_Query();
		$original_meta_query          = $query->get( 'meta_query' );
		$original_capability_in_query = $query->get( 'capability__in' );
		$original_exclude_query       = $query->get( 'exclude' );

		Highlight_MFA_Users::filter_users_by_mfa_status( $query );

		// Assert that the query parameters were not modified
		$this->assertEquals( $original_meta_query, $query->get( 'meta_query' ) );
		$this->assertEquals( $original_capability_in_query, $query->get( 'capability__in' ) );
		$this->assertEquals( $original_exclude_query, $query->get( 'exclude' ) );

		unset( $_GET['filter_mfa_disabled'] );
	}

	/**
	 * Test that the admin notice is displayed correctly when MFA-disabled admins exist.
	 */
	public function test_display_mfa_disabled_notice_shows_when_needed() {
		$this->set_admin_screen_users();
		// We have one MFA-disabled admin ($this->admin_user_mfa_disabled_id) and one editor ($this->editor_user_id) and the default superadmin with ID 1
		// The skipped admin ($this->admin_user_mfa_skipped_id) should be ignored by the notice logic.
		// The subscriber ($this->subscriber_user_id) should not be included in the notice.
		// The notice logic uses Two_Factor_Core::is_user_using_two_factor which we've mocked.
		// Only admin_user_mfa_disabled_id should be counted (editor doesn't have administrator role)
		$expected_count  = 3;
		$filter_url      = add_query_arg( 'filter_mfa_disabled', '1', admin_url( 'users.php' ) );
		$expected_output = sprintf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			sprintf(
				// translators: %d: Number of users.
				_n(
					'There is %d user with Two-Factor Authentication disabled.',
					'There are %d users with Two-Factor Authentication disabled.',
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

		// We have one MFA-disabled admin ($this->admin_user_mfa_disabled_id) and one editor ($this->editor_user_id), plus the default superadmin with ID 1
		$expected_count  = 3;
		$show_all_url    = remove_query_arg( 'filter_mfa_disabled', admin_url( 'users.php' ) );
		$expected_output = sprintf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>', // Notice class is notice-info when filtered
			sprintf(
				// translators: %d: Number of users.
				_n(
					'Showing %d user without Two-Factor Authentication enabled.',
					'Showing %d users without Two-Factor Authentication enabled.',
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
		// Temporarily tell the mock that the MFA-disabled user is enabled for this test
		Two_Factor_Core::$mock_enabled_user_ids[] = $this->admin_user_mfa_disabled_id;
		Two_Factor_Core::$mock_enabled_user_ids[] = $this->editor_user_id;
		Two_Factor_Core::$mock_enabled_user_ids[] = 1; // Also ensure User ID 1 is treated as MFA enabled

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
		remove_all_actions( 'pre_get_users' );

		Highlight_MFA_Users::init();

		$this->assertNotFalse( has_action( 'admin_notices', [ Highlight_MFA_Users::class, 'display_mfa_disabled_notice' ] ) );
		$this->assertEquals( 10, has_action( 'admin_notices', [ Highlight_MFA_Users::class, 'display_mfa_disabled_notice' ] ) );

		$this->assertNotFalse( has_action( 'pre_get_users', [ Highlight_MFA_Users::class, 'filter_users_by_mfa_status' ] ) );
		$this->assertEquals( 10, has_action( 'pre_get_users', [ Highlight_MFA_Users::class, 'filter_users_by_mfa_status' ] ) );
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
}
