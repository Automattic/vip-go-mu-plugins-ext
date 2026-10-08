<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use Automattic\ShareADraft\Plugin;
use WP_UnitTestCase;

/**
 * @covers \Automattic\ShareADraft\Plugin
*/
class PluginTest extends WP_UnitTestCase {
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// get_current_screen() and set_current_screen() live in wp-admin, which
		// the test bootstrap does not load.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	public function set_up(): void {
		parent::set_up();

		set_current_screen( PreviewLinksAdminPage::SCREEN_ID );
	}

	public function tear_down(): void {
		remove_all_filters( 'shareadraft_is_vip_platform' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_register_wires_hooks(): void {
		$plugin = Plugin::get_instance();

		static::assertEquals( 10, has_action( 'init', [ $plugin, 'init' ] ) );

		// The composition root wires these with injected instances, so assert a
		// callback is present rather than a specific static callable.
		static::assertNotFalse( has_action( 'rest_api_init' ) );
		static::assertNotFalse( has_action( 'posts_results' ) );
		static::assertNotFalse( has_action( 'enqueue_block_editor_assets' ) );
		static::assertNotFalse( has_filter( 'site_status_tests' ) );
	}

	/**
	 * Nothing of ours belongs in the front end of somebody's site, and nothing
	 * of ours interrupts an admin screen it does not own.
	 */
	public function test_it_renders_nothing_outside_its_own_screens(): void {
		// @phpstan-ignore function.impossibleType (A regression guard: the hook was removed on purpose.)
		static::assertFalse( method_exists( Plugin::class, 'wp_footer' ) );
		// @phpstan-ignore function.impossibleType (A regression guard: the notice was removed on purpose.)
		static::assertFalse( method_exists( Plugin::class, 'render_config_notice' ) );
	}
}
