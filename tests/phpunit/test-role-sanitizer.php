<?php

use Automattic\VIP\Security\Utils\Role_Sanitizer;

class RoleSanitizerTest extends WP_UnitTestCase {

	/**
	 * Name of the roles option for the current site.
	 *
	 * @var string
	 */
	private $roles_option_name;

	/**
	 * Original option value backup.
	 *
	 * @var array|null
	 */
	private $original_roles_option;

	/**
	 * Original $wp_roles instance backup.
	 *
	 * @var \WP_Roles|null
	 */
	private $original_wp_roles;

	public function setUp(): void {
		global $wpdb, $wp_roles;

		parent::setUp();

		$blog_id                 = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$this->roles_option_name = is_multisite()
			? $wpdb->get_blog_prefix( $blog_id ) . 'user_roles'
			: $wpdb->prefix . 'user_roles';

		$this->original_roles_option = get_option( $this->roles_option_name );
		$this->original_wp_roles     = $wp_roles instanceof WP_Roles ? $wp_roles : null;

		wp_cache_flush();
	}

	public function tearDown(): void {
		global $wp_roles;

		Role_Sanitizer::unregister_role_sanitizers();

		if ( null !== $this->original_roles_option ) {
			update_option( $this->roles_option_name, $this->original_roles_option );
		} else {
			delete_option( $this->roles_option_name );
		}

		if ( $this->original_wp_roles instanceof WP_Roles ) {
			$wp_roles = $this->original_wp_roles;
		} else {
			$wp_roles = null;
		}

		wp_cache_flush();

		parent::tearDown();
	}

	public function test_maybe_register_role_sanitizers_registers_filters_when_missing_names() {
		global $wp_roles;

		$corrupted_roles = $this->original_roles_option;
		if ( ! is_array( $corrupted_roles ) ) {
			$corrupted_roles = [];
		}
		if ( isset( $corrupted_roles['administrator']['name'] ) ) {
			unset( $corrupted_roles['administrator']['name'] );
		}

		update_option( $this->roles_option_name, $corrupted_roles );
		$wp_roles = null;

		Role_Sanitizer::maybe_register_role_sanitizers();

		$this->assertNotFalse(
			has_action( 'switch_blog', [ Role_Sanitizer::class, 'register_role_option_filter' ] ),
			'Role sanitizers should hook into switch_blog when repair is needed.'
		);
		$this->assertNotFalse(
			has_filter( "option_{$this->roles_option_name}", [ Role_Sanitizer::class, 'repair_roles_array' ] ),
			'Role sanitizers should register the option filter when names are missing.'
		);
	}

	public function test_maybe_register_role_sanitizers_skips_when_roles_are_valid() {
		Role_Sanitizer::maybe_register_role_sanitizers();

		$this->assertFalse(
			has_filter( "option_{$this->roles_option_name}", [ Role_Sanitizer::class, 'repair_roles_array' ] ),
			'Role sanitizers should not register filters when role metadata is valid.'
		);
		$this->assertFalse(
			has_action( 'switch_blog', [ Role_Sanitizer::class, 'register_role_option_filter' ] ),
			'Role sanitizers should not hook switch_blog when repair is not needed.'
		);
	}

	public function test_ensure_roles_have_names_fills_missing_values() {
		global $wp_roles;

		$wp_roles                              = new WP_Roles();
		$wp_roles->roles['subscriber']['name'] = '';

		// Expect a warning to be triggered when repairing the role name
		$this->expectWarning();
		$this->expectWarningMessage( 'Repaired missing name for role &#039;subscriber&#039; to &#039;Subscriber&#039;. Please review your user roles db data as it might be corrupted.' );

		Role_Sanitizer::ensure_roles_have_names();

		$this->assertNotEmpty(
			$wp_roles->roles['subscriber']['name'],
			'Role sanitizers should populate missing role names.'
		);
		$this->assertNotEmpty(
			$wp_roles->role_names['subscriber'],
			'Role sanitizers should rebuild the role names index after repair.'
		);
	}

	public function test_repair_roles_array_preserves_existing_role_information() {
		$roles = [
			'administrator' => [
				'name'         => '',
				'capabilities' => [
					'manage_options' => true,
				],
			],
			'editor'        => [
				'name'         => 'Editor',
				'capabilities' => [
					'edit_pages' => true,
				],
			],
		];

		// Expect a warning to be triggered when repairing the administrator role name
		$this->expectWarning();
		$this->expectWarningMessage( 'Repaired missing name for role &#039;administrator&#039; to &#039;Administrator&#039;. Please review your user roles db data as it might be corrupted.' );

		$repaired_roles = Role_Sanitizer::repair_roles_array( $roles );

		$this->assertNotEmpty(
			$repaired_roles['administrator']['name'],
			'Administrator role should receive a fallback name.'
		);
		$this->assertSame(
			'Editor',
			$repaired_roles['editor']['name'],
			'Repaired roles should not alter valid role names.'
		);
		$this->assertSame(
			$roles['editor']['capabilities'],
			$repaired_roles['editor']['capabilities'],
			'Role sanitizer must preserve existing capabilities.'
		);
	}
}
