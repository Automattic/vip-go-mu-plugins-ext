<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_Screen;
use WP_UnitTestCase;

/**
 * The contextual help sidebar, which points somewhere different depending on
 * whether the site is VIP-hosted.
 *
 * @covers \Automattic\ShareADraft\PreviewLinksAdminPage
 */
class PreviewLinksAdminPageHelpTest extends WP_UnitTestCase {
	private PreviewLinksAdminPage $page;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Screen options and contextual help live in wp-admin, which the test
		// bootstrap does not load.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	public function set_up(): void {
		parent::set_up();

		$service = new PreviewLinkService( new PostMetaTokenRepository(), new AccessPolicy(), new SystemClock() );

		$this->page = new PreviewLinksAdminPage( $service, new SystemClock(), new BulkLinkRevoker( $service ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'shareadraft_is_vip_platform' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_it_links_to_vip_support_on_vip(): void {
		add_filter( 'shareadraft_is_vip_platform', '__return_true' );

		$sidebar = $this->sidebar();

		static::assertStringContainsString( 'docs.wpvip.com', $sidebar );
		static::assertStringContainsString( 'support@wpvip.com', $sidebar );
	}

	/**
	 * VIP support cannot help a self-hosted site, so pointing one at it would
	 * send people to a desk that has no way to answer them.
	 */
	public function test_it_links_to_the_plugin_support_forum_off_vip(): void {
		add_filter( 'shareadraft_is_vip_platform', '__return_false' );

		$sidebar = $this->sidebar();

		static::assertStringNotContainsString( 'wpvip.com', $sidebar );
		static::assertStringContainsString( 'wordpress.org/support/plugin/shareadraft', $sidebar );
	}

	public function test_the_help_tabs_are_added_regardless_of_platform(): void {
		add_filter( 'shareadraft_is_vip_platform', '__return_false' );

		$this->configure_screen();
		$screen = get_current_screen();

		static::assertInstanceOf( WP_Screen::class, $screen );
		static::assertNotEmpty( $screen->get_help_tabs() );
	}

	public function test_the_help_explains_the_pause_toggle_in_its_own_tab(): void {
		static::assertStringContainsString( 'reversible pause', $this->all_help_content() );
	}

	public function test_restriction_help_follows_the_feature_switches(): void {
		$help = $this->all_help_content();
		static::assertStringContainsString( 'Reviewers shows', $help );
		static::assertStringContainsString( 'IP ranges shows', $help );
	}

	/**
	 * Without central ranges a link's own ranges restrict it; with them, they
	 * widen it. The help has to say whichever is true on this site.
	 */
	public function test_ip_range_help_follows_whether_central_ranges_are_set(): void {
		static::assertStringNotContainsString( 'VIP Dashboard', $this->all_help_content() );

		$service    = new PreviewLinkService( new PostMetaTokenRepository(), new AccessPolicy(), new SystemClock() );
		$this->page = new PreviewLinksAdminPage( $service, new SystemClock(), new BulkLinkRevoker( $service ), null, [ '203.0.113.0/24' ] );

		static::assertStringContainsString( 'open from further places too', $this->all_help_content() );
	}

	/**
	 * A switched-off restriction has no column, so a help paragraph describing
	 * one would send readers hunting for something that is not on the screen.
	 */
	public function test_disabled_restriction_columns_are_not_explained(): void {
		add_filter( 'shareadraft_recipients_enabled', '__return_false' );
		add_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' );

		try {
			$help = $this->all_help_content();
		} finally {
			remove_filter( 'shareadraft_recipients_enabled', '__return_false' );
			remove_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' );
		}

		static::assertStringNotContainsString( 'Reviewers shows', $help );
		static::assertStringNotContainsString( 'IP ranges shows', $help );
	}

	private function all_help_content(): string {
		$this->configure_screen();
		$screen = get_current_screen();

		static::assertInstanceOf( WP_Screen::class, $screen );

		$content = '';

		foreach ( (array) $screen->get_help_tabs() as $tab ) {
			$content .= is_array( $tab ) && isset( $tab['content'] ) && is_string( $tab['content'] ) ? $tab['content'] : '';
		}

		return $content;
	}

	private function sidebar(): string {
		$this->configure_screen();
		$screen = get_current_screen();

		static::assertInstanceOf( WP_Screen::class, $screen );

		return $screen->get_help_sidebar();
	}

	private function configure_screen(): void {
		set_current_screen( 'toplevel_page_' . PreviewLinksAdminPage::SLUG );
		$this->page->configure_screen();
	}
}
