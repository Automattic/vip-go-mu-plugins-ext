<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The revoke actions behind the site-wide admin table: a nonce-checked row
 * action and a nonce-checked bulk action, both writing revocation to the link.
 *
 * @covers \Automattic\ShareADraft\PreviewLinksAdminPage
 */
class PreviewLinksAdminPageTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;
	private PreviewLinksAdminPage $page;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService( $this->repository, new AccessPolicy(), new SystemClock() );
		$this->page       = new PreviewLinksAdminPage( $this->service, new SystemClock(), new BulkLinkRevoker( $this->service ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	public function tear_down(): void {
		unset(
			$_GET['action'],
			$_GET['post'],
			$_GET['token'],
			$_GET['creator'],
			$_GET['shareadraft_revoked'],
			$_GET['shareadraft_pending'],
			$_GET['_wpnonce'],
			$_REQUEST['action'],
			$_REQUEST['paged'],
			$_REQUEST['_wpnonce'],
			$_POST['action'],
			$_POST['links'],
			$_POST['shareadraft_enabled'],
			$_POST['shareadraft_all'],
			$_POST['_wpnonce']
		);

		unregister_post_type( 'sad_product' );
		set_current_screen( 'front' );
		BulkLinkRevoker::unschedule();
		delete_option( 'shareadraft_bulk_revoke_jobs' );
		wp_dequeue_script( 'shareadraft-admin' );
		wp_deregister_script( 'shareadraft-admin' );

		parent::tear_down();
	}

	public function test_the_script_is_enqueued_with_its_built_dependencies(): void {
		$this->page->enqueue_assets( PreviewLinksAdminPage::SCREEN_ID );

		static::assertTrue( wp_script_is( 'shareadraft-admin' ) );

		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset  = require dirname( __DIR__, 2 ) . '/build/admin.asset.php';
		$script = wp_scripts()->registered['shareadraft-admin'];

		static::assertSame( $asset['dependencies'], $script->deps );
		static::assertSame( $asset['version'], $script->ver );
	}

	public function test_the_script_stays_off_other_screens(): void {
		$this->page->enqueue_assets( 'edit.php' );

		static::assertFalse( wp_script_is( 'shareadraft-admin' ) );
	}

	public function test_an_ordinary_view_carries_no_revoke(): void {
		static::assertNull( $this->page->process_request() );
	}

	/**
	 * The per-row column shows only each link's own ranges, so the central
	 * baseline has to be stated once above the table — otherwise a table full
	 * of dashes reads as "no IP restrictions" on a site where the Dashboard
	 * restricts every link.
	 */
	public function test_central_ip_ranges_are_stated_above_the_table(): void {
		$page = new PreviewLinksAdminPage( $this->service, new SystemClock(), new BulkLinkRevoker( $this->service ), null, [ '203.0.113.0/24', '2001:db8::/32' ] );

		$output = $this->rendered( $page );

		static::assertStringContainsString( 'VIP Dashboard', $output );
		static::assertStringContainsString( '203.0.113.0/24', $output );
		static::assertStringContainsString( '2001:db8::/32', $output );
	}

	public function test_no_central_line_renders_without_configured_ranges(): void {
		static::assertStringNotContainsString( 'VIP Dashboard', $this->rendered( $this->page ) );
	}

	/**
	 * A revoke that changed nothing (the links were already revoked, or none
	 * were ticked) must not wear the green of success.
	 */
	public function test_a_revoke_of_nothing_renders_a_warning_not_a_success(): void {
		$_GET['shareadraft_revoked'] = '0';

		$output = $this->rendered( $this->page );

		static::assertStringContainsString( 'notice-warning', $output );
		static::assertStringNotContainsString( 'notice-success', $output );
	}

	public function test_a_revoke_of_some_links_renders_a_success(): void {
		$_GET['shareadraft_revoked'] = '2';

		$output = $this->rendered( $this->page );

		static::assertStringContainsString( 'notice-success', $output );
		static::assertStringContainsString( '2 preview links revoked.', $output );
		static::assertStringNotContainsString( 'notice-warning', $output );
	}

	/**
	 * A sweep that handed everything to cron is still working, so a zero count
	 * alongside the pending flag stays a success.
	 */
	public function test_a_revoke_of_nothing_yet_with_work_pending_renders_a_success(): void {
		$_GET['shareadraft_revoked'] = '0';
		$_GET['shareadraft_pending'] = '1';

		$output = $this->rendered( $this->page );

		static::assertStringContainsString( 'notice-success', $output );
		static::assertStringContainsString( 'being revoked in the background', $output );
	}

	/**
	 * The Dashboard field is free text, so one typo on a single-range site
	 * would otherwise lift the restriction with nothing to say so.
	 */
	public function test_ignored_central_ranges_are_named_in_a_warning(): void {
		$page = new PreviewLinksAdminPage( $this->service, new SystemClock(), new BulkLinkRevoker( $this->service ), null, [], [ '203.0.113.*' ] );

		$output = $this->rendered( $page );

		static::assertStringContainsString( 'notice-warning', $output );
		static::assertStringContainsString( '<code>203.0.113.*</code>', $output );
		static::assertStringContainsString( 'No site-wide IP restriction applies', $output );
	}

	public function test_ignored_ranges_alongside_valid_ones_say_only_the_valid_ones_apply(): void {
		$page = new PreviewLinksAdminPage( $this->service, new SystemClock(), new BulkLinkRevoker( $this->service ), null, [ '203.0.113.0/24' ], [ '198.51.100.*' ] );

		$output = $this->rendered( $page );

		static::assertStringContainsString( '<code>198.51.100.*</code>', $output );
		static::assertStringContainsString( 'Only the valid ranges below apply.', $output );
		static::assertStringNotContainsString( 'No site-wide IP restriction applies', $output );
	}

	public function test_no_warning_renders_when_every_central_range_is_valid(): void {
		$page = new PreviewLinksAdminPage( $this->service, new SystemClock(), new BulkLinkRevoker( $this->service ), null, [ '203.0.113.0/24' ] );

		static::assertStringNotContainsString( 'not valid IP addresses', $this->rendered( $page ) );
	}

	private function rendered( PreviewLinksAdminPage $page ): string {
		// The list table needs the admin screen machinery the test bootstrap
		// does not load by default.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( PreviewLinksAdminPage::SCREEN_ID );

		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	public function test_a_row_action_revokes_one_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, get_current_user_id() );
		$hash = $this->repository->all_for_post( $post_id )[0]->token_hash();

		$nonce                = wp_create_nonce( 'shareadraft_revoke_' . $post_id . '_' . $hash );
		$_GET['action']       = 'revoke';
		$_GET['post']         = (string) $post_id;
		$_GET['token']        = $hash;
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		static::assertSame( 1, $this->page->process_request() );

		$link = $this->repository->find_by_hash( $post_id, $hash );
		static::assertNotNull( $link );
		static::assertTrue( $link->is_revoked() );
	}

	/**
	 * Revoking from page 3 of one creator's links must land back there, not
	 * on page 1 of the whole site's list.
	 */
	public function test_the_view_args_carry_the_creator_filter_and_page(): void {
		static::assertSame( [], PreviewLinksAdminPage::view_args() );

		$_GET['creator']   = '7';
		$_REQUEST['paged'] = '3';

		static::assertSame(
			[
				'creator' => 7,
				'paged'   => 3,
			],
			PreviewLinksAdminPage::view_args()
		);

		$_REQUEST['paged'] = '1';

		static::assertSame( [ 'creator' => 7 ], PreviewLinksAdminPage::view_args() );
	}

	public function test_a_row_revoke_link_keeps_the_creator_filter(): void {
		$creator = get_current_user_id();
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, $creator );

		$_GET['creator'] = (string) $creator;

		static::assertMatchesRegularExpression( '/action=revoke[^"]*creator=' . $creator . '/', $this->rendered( $this->page ) );
	}

	/**
	 * A bookmarked filter for someone with no links must not claim the whole
	 * site has none.
	 */
	public function test_an_empty_creator_filter_does_not_claim_the_site_has_no_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );

		$_GET['creator'] = '8';

		$output = $this->rendered( $this->page );

		static::assertStringContainsString( 'This user has not created any preview links.', $output );
		static::assertStringNotContainsString( 'No preview links have been created yet.', $output );
	}

	public function test_a_bulk_action_revokes_every_selected_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$selected = [];
		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			$selected[] = $post_id . ':' . $link->token_hash();
		}

		$nonce                = wp_create_nonce( 'bulk-' . PreviewLinksListTable::PLURAL );
		$_REQUEST['action']   = 'revoke';
		$_POST['action']      = 'revoke';
		$_POST['links']       = $selected;
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		static::assertSame( 2, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertTrue( $link->is_revoked() );
		}
	}

	/**
	 * A post type with its own capabilities, which the screen's
	 * `edit_others_posts` gate does not reach: an editor holds that capability
	 * but not `edit_others_products`, so cannot edit a draft of this type.
	 */
	private function post_the_editor_cannot_edit(): int {
		register_post_type(
			'sad_product',
			[
				'labels'          => [
					'name'          => 'Products',
					'singular_name' => 'Product',
				],
				'public'          => true,
				'capability_type' => 'product',
				'map_meta_cap'    => true,
			]
		);

		return self::factory()->post->create(
			[
				'post_type'   => 'sad_product',
				'post_status' => 'draft',
				'post_title'  => 'Unreleased widget',
				'post_author' => self::factory()->user->create( [ 'role' => 'administrator' ] ),
			]
		);
	}

	/**
	 * Redacted rather than filtered, so the paging still counts the row: the
	 * link is visible as existing, but not whose draft it is or who reviews it.
	 */
	public function test_a_row_on_a_post_the_viewer_cannot_edit_is_redacted(): void {
		$hidden  = $this->post_the_editor_cannot_edit();
		$visible = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_title'  => 'Ordinary draft',
			]
		);
		$this->service->mint( $hidden, HOUR_IN_SECONDS, null, 1, [ '203.0.113.7' ], [ 'hidden@example.com' ] );
		$this->service->mint( $visible, HOUR_IN_SECONDS, null, 1, [], [ 'visible@example.com' ] );
		$hash = $this->repository->all_for_post( $hidden )[0]->token_hash();

		$output = $this->rendered( $this->page );

		static::assertStringContainsString( 'Ordinary draft', $output );
		static::assertStringContainsString( 'visible@example.com', $output );
		static::assertStringContainsString( '(You cannot edit this Product)', $output );
		static::assertStringNotContainsString( 'Unreleased widget', $output );
		static::assertStringNotContainsString( 'hidden@example.com', $output );
		static::assertStringNotContainsString( '203.0.113.7', $output );
		static::assertStringNotContainsString( $hash, $output );
	}

	public function test_a_row_revoke_on_a_post_the_viewer_cannot_edit_is_refused(): void {
		$post_id = $this->post_the_editor_cannot_edit();
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$hash = $this->repository->all_for_post( $post_id )[0]->token_hash();

		$nonce                = wp_create_nonce( 'shareadraft_revoke_' . $post_id . '_' . $hash );
		$_GET['action']       = 'revoke';
		$_GET['post']         = (string) $post_id;
		$_GET['token']        = $hash;
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		static::assertSame( 0, $this->page->process_request() );
		static::assertFalse( $this->repository->all_for_post( $post_id )[0]->is_revoked() );
	}

	/**
	 * The bulk nonce covers every row, so a hand-built selection must not
	 * reach a post the viewer cannot edit.
	 */
	public function test_a_bulk_revoke_skips_posts_the_viewer_cannot_edit(): void {
		$hidden  = $this->post_the_editor_cannot_edit();
		$visible = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $hidden, HOUR_IN_SECONDS, null, 1 );
		$this->service->mint( $visible, HOUR_IN_SECONDS, null, 1 );

		$this->submit_bulk_revoke( false );
		$_POST['links'] = [
			$hidden . ':' . $this->repository->all_for_post( $hidden )[0]->token_hash(),
			$visible . ':' . $this->repository->all_for_post( $visible )[0]->token_hash(),
		];

		static::assertSame( 1, $this->page->process_request() );
		static::assertFalse( $this->repository->all_for_post( $hidden )[0]->is_revoked() );
		static::assertTrue( $this->repository->all_for_post( $visible )[0]->is_revoked() );
	}

	/**
	 * Offboarding is not narrowed to what the viewer can edit: leaving some of
	 * a leaver's links working would be worse than over-revoking.
	 */
	public function test_a_creator_sweep_reaches_posts_the_viewer_cannot_edit(): void {
		$post_id = $this->post_the_editor_cannot_edit();
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );

		$this->submit_bulk_revoke( true );
		$_GET['creator'] = '7';

		static::assertSame( 1, $this->page->process_request() );
		static::assertTrue( $this->repository->all_for_post( $post_id )[0]->is_revoked() );
	}

	/**
	 * Select-all-across-pages on a creator-filtered view sweeps everything
	 * that person created, site-wide, within the table's own capability.
	 */
	public function test_select_all_on_a_creator_filter_revokes_everything_they_created(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 8 );

		$this->submit_bulk_revoke( true );
		$_GET['creator'] = '7';

		static::assertSame( 1, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertSame( 7 === $link->created_by(), $link->is_revoked() );
		}
	}

	public function test_unfiltered_select_all_revokes_every_link_for_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 8 );

		$this->submit_bulk_revoke( true );

		static::assertSame( 2, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertTrue( $link->is_revoked() );
		}
	}

	/**
	 * Only the exact sentinel the banner sets upgrades a bulk revoke; any other
	 * value revokes just the ticked rows.
	 */
	public function test_a_select_all_value_other_than_one_revokes_only_the_selection(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 8 );
		$selected = $this->repository->all_for_post( $post_id )[0];

		$this->submit_bulk_revoke( false );
		$_POST['shareadraft_all'] = '0';
		$_POST['links']           = [ $post_id . ':' . $selected->token_hash() ];

		static::assertSame( 1, $this->page->process_request() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertSame( $link->token_hash() === $selected->token_hash(), $link->is_revoked() );
		}
	}

	/**
	 * Unfiltered select-all is the break-glass "revoke everything", whose
	 * blast radius exceeds the table's own gate: editor is not enough.
	 */
	public function test_an_editor_cannot_select_all_links_site_wide(): void {
		$this->submit_bulk_revoke( true );

		$this->expectException( \WPDieException::class );

		$this->page->process_request();
	}

	/**
	 * Simulate the table's bulk-revoke submission, optionally upgraded by the
	 * "select all across pages" offer.
	 */
	private function submit_bulk_revoke( bool $select_all ): void {
		$nonce                = wp_create_nonce( 'bulk-' . PreviewLinksListTable::PLURAL );
		$_REQUEST['action']   = 'revoke';
		$_POST['action']      = 'revoke';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		if ( $select_all ) {
			$_POST['shareadraft_all'] = '1';
		}
	}

	public function test_an_administrator_can_disable_and_enable_links(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$toggle = new LinkToggle();

		// The slider submits the new state: no checkbox means "disabled".
		$this->submit_toggle( false );
		static::assertSame( 'disabled', $this->page->process_toggle() );
		static::assertTrue( $toggle->is_disabled() );

		$this->submit_toggle( true );
		static::assertSame( 'enabled', $this->page->process_toggle() );
		static::assertFalse( $toggle->is_disabled() );
	}

	public function test_an_ordinary_view_carries_no_toggle(): void {
		static::assertNull( $this->page->process_toggle() );
	}

	/**
	 * The switch silently stops every link on the site working, so an editor's
	 * capability is not enough to flip it.
	 */
	public function test_an_editor_cannot_disable_links(): void {
		$this->submit_toggle( false );

		$this->expectException( \WPDieException::class );

		$this->page->process_toggle();
	}

	/**
	 * Simulate the toggle slider's form submission asking for the given state.
	 */
	private function submit_toggle( bool $enabled ): void {
		$nonce                = wp_create_nonce( 'shareadraft_toggle_links' );
		$_POST['action']      = 'toggle_links';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		if ( $enabled ) {
			$_POST['shareadraft_enabled'] = '1';
		} else {
			unset( $_POST['shareadraft_enabled'] );
		}
	}
}
