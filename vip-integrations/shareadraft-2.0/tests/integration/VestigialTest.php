<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft\Vestigial;

use WP_Query;
use WP_UnitTestCase;
use WP_User;
use WPDieException;

/**
 * Links made with Share a Draft 1.x, stored in the 1.x option shape.
 *
 * @covers ::\Automattic\ShareADraft\Vestigial\bootstrap
 * @covers ::\Automattic\ShareADraft\Vestigial\shares
 * @covers ::\Automattic\ShareADraft\Vestigial\save
 * @covers ::\Automattic\ShareADraft\Vestigial\unlock_shared_draft
 * @covers ::\Automattic\ShareADraft\Vestigial\restore_shared_draft
 * @covers ::\Automattic\ShareADraft\Vestigial\prune_expired
 * @covers ::\Automattic\ShareADraft\Vestigial\prune_orphans
 * @covers ::\Automattic\ShareADraft\Vestigial\register_page
 * @covers ::\Automattic\ShareADraft\Vestigial\delete_share
 */
class VestigialTest extends WP_UnitTestCase {
	private int $author;

	public function set_up(): void {
		parent::set_up();

		$this->author = self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	public function tear_down(): void {
		remove_filter( 'posts_results', __NAMESPACE__ . '\\unlock_shared_draft' );
		remove_filter( 'the_posts', __NAMESPACE__ . '\\restore_shared_draft' );
		shared_draft( null );
		if ( isset( $GLOBALS['submenu'] ) && is_array( $GLOBALS['submenu'] ) ) {
			unset( $GLOBALS['submenu']['edit.php'] );
		}
		parent::tear_down();
	}

	/**
	 * Store shares for the author exactly as 1.x did, including the empty entry
	 * it wrote for every other user who opened wp-admin.
	 *
	 * @param list<array{id: int, expires: int, key: string}> $shares Shares.
	 */
	private function store( array $shares ): void {
		update_option(
			OPTION,
			[
				1             => [],
				$this->author => [ 'shared' => $shares ],
			]
		);
	}

	private function hook_front_end(): void {
		add_filter( 'posts_results', __NAMESPACE__ . '\\unlock_shared_draft', 10, 2 );
		add_filter( 'the_posts', __NAMESPACE__ . '\\restore_shared_draft', 10, 2 );
	}

	private function draft(): int {
		return self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => $this->author,
			]
		);
	}

	public function test_nothing_is_hooked_when_1x_left_no_shares(): void {
		update_option(
			OPTION,
			[
				1 => [],
				2 => [ 'shared' => [] ],
			]
		);

		static::assertSame( [], shares() );

		bootstrap();

		static::assertFalse( has_action( 'admin_init', __NAMESPACE__ . '\\prune_expired' ) );
		static::assertFalse( has_filter( 'posts_results', __NAMESPACE__ . '\\unlock_shared_draft' ) );
	}

	public function test_malformed_entries_are_ignored(): void {
		update_option(
			OPTION,
			[
				$this->author     => [
					'shared' => [
						[ 'id' => 5 ],
						'not a share',
						[
							'id'      => '7',
							'expires' => '2000000000',
							'key'     => 'baba7_abc',
						],
					],
				],
				$this->author + 1 => 'junk',
			]
		);

		static::assertSame(
			[
				$this->author => [
					[
						'id'      => 7,
						'expires' => 2000000000,
						'key'     => 'baba7_abc',
					],
				],
			],
			shares()
		);
	}

	public function test_a_valid_link_shows_the_draft_to_a_logged_out_visitor(): void {
		$post_id = $this->draft();
		$this->store(
			[
				[
					'id'      => $post_id,
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_live',
				],
			]
		);
		$this->hook_front_end();

		$this->go_to( home_url( "/?p={$post_id}&shareadraft=baba_live" ) );

		static::assertTrue( is_single( $post_id ) );
		static::assertSame( [ $post_id ], wp_list_pluck( self::main_query_posts(), 'ID' ) );

		// The draft keeps its real status, so WordPress does not redirect the
		// visitor to a permalink the draft does not have yet.
		static::assertSame( 'draft', get_post_status( self::main_query_posts()[0] ?? null ) );
		static::assertNull( redirect_canonical( home_url( "/?p={$post_id}&shareadraft=baba_live" ), false ) );
	}

	/**
	 * @dataProvider data_links_that_do_not_open
	 */
	public function test_a_link_that_does_not_match_leaves_the_draft_hidden( string $key, int $expires_in ): void {
		$post_id = $this->draft();
		$this->store(
			[
				[
					'id'      => $post_id,
					'expires' => time() + $expires_in,
					'key'     => 'baba_live',
				],
			]
		);
		$this->hook_front_end();

		$this->go_to( home_url( "/?p={$post_id}&shareadraft={$key}" ) );

		static::assertSame( [], self::main_query_posts() );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public function data_links_that_do_not_open(): array {
		return [
			'expired'   => [ 'baba_live', -1 ],
			'wrong key' => [ 'baba_nope', HOUR_IN_SECONDS ],
		];
	}

	public function test_a_key_only_opens_its_own_post(): void {
		$shared = $this->draft();
		$other  = $this->draft();
		$this->store(
			[
				[
					'id'      => $shared,
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_live',
				],
			]
		);
		$this->hook_front_end();

		$this->go_to( home_url( "/?p={$other}&shareadraft=baba_live" ) );

		static::assertSame( [], self::main_query_posts() );
	}

	public function test_expired_shares_are_pruned_and_the_option_goes_with_the_last(): void {
		$post_id = $this->draft();
		$this->store(
			[
				[
					'id'      => $post_id,
					'expires' => time() - 1,
					'key'     => 'baba_old',
				],
			]
		);

		prune_expired();

		static::assertFalse( get_option( OPTION ) );
	}

	public function test_live_shares_survive_pruning(): void {
		$live = [
			'id'      => $this->draft(),
			'expires' => time() + HOUR_IN_SECONDS,
			'key'     => 'baba_live',
		];
		$this->store(
			[
				$live,
				[
					'id'      => $this->draft(),
					'expires' => time() - 1,
					'key'     => 'baba_old',
				],
			]
		);

		prune_expired();

		static::assertSame( [ $this->author => [ $live ] ], shares() );
	}

	public function test_orphaned_shares_are_pruned(): void {
		$gone = $this->draft();
		$this->store(
			[
				[
					'id'      => $gone,
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_gone',
				],
			]
		);
		wp_delete_post( $gone, true );

		prune_orphans();

		static::assertFalse( get_option( OPTION ) );
	}

	public function test_the_old_screen_is_registered_only_for_someone_with_a_live_link(): void {
		$this->store(
			[
				[
					'id'      => $this->draft(),
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_live',
				],
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		register_page();
		static::assertNotContains( PAGE, self::posts_submenu_slugs() );

		wp_set_current_user( $this->author );
		register_page();
		static::assertContains( PAGE, self::posts_submenu_slugs() );
	}

	public function test_deleting_a_link_removes_only_that_link(): void {
		$keep = [
			'id'      => $this->draft(),
			'expires' => time() + HOUR_IN_SECONDS,
			'key'     => 'baba_keep',
		];
		$this->store(
			[
				$keep,
				[
					'id'      => $this->draft(),
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_drop',
				],
			]
		);
		wp_set_current_user( $this->author );

		static::assertTrue( delete_share( 'baba_drop' ) );
		static::assertSame( [ $this->author => [ $keep ] ], shares() );
	}

	public function test_deleting_the_last_link_removes_the_option(): void {
		$this->store(
			[
				[
					'id'      => $this->draft(),
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_only',
				],
			]
		);
		wp_set_current_user( $this->author );

		delete_share( 'baba_only' );

		static::assertFalse( get_option( OPTION ) );
	}

	public function test_nobody_can_delete_another_users_link(): void {
		$this->store(
			[
				[
					'id'      => $this->draft(),
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_theirs',
				],
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		static::assertFalse( delete_share( 'baba_theirs' ) );
		static::assertCount( 1, shares()[ $this->author ] );
	}

	public function test_a_link_to_a_post_the_user_can_no_longer_edit_cannot_be_deleted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->store(
			[
				[
					'id'      => $post_id,
					'expires' => time() + HOUR_IN_SECONDS,
					'key'     => 'baba_locked',
				],
			]
		);
		$demoted = get_userdata( $this->author );
		static::assertInstanceOf( WP_User::class, $demoted );
		$demoted->set_role( 'subscriber' );
		wp_set_current_user( $this->author );

		$this->expectException( WPDieException::class );

		delete_share( 'baba_locked' );
	}

	/**
	 * @return array<int|\WP_Post>
	 */
	private static function main_query_posts(): array {
		$query = $GLOBALS['wp_query'] ?? null;
		static::assertInstanceOf( WP_Query::class, $query );

		return $query->posts ?? [];
	}

	/**
	 * @return array<mixed>
	 */
	private static function posts_submenu_slugs(): array {
		$submenu = $GLOBALS['submenu'] ?? [];
		static::assertIsArray( $submenu );

		$items = $submenu['edit.php'] ?? [];
		static::assertIsArray( $items );

		return wp_list_pluck( $items, 2 );
	}
}
