<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The cross-post listing queries that back the site-wide admin table.
 *
 * @covers \Automattic\ShareADraft\PostMetaTokenRepository
 */
class PostMetaTokenRepositoryTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
	}

	public function test_counts_links_across_every_post(): void {
		$first  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$second = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $first, 'aaaa' );
		$this->save_link( $first, 'bbbb' );
		$this->save_link( $second, 'cccc' );

		static::assertSame( 3, $this->repository->count_links() );
	}

	public function test_pages_links_newest_first(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		// Saved oldest to newest; the table shows newest first (meta_id descending).
		$this->save_link( $post, 'aaaa' );
		$this->save_link( $post, 'bbbb' );
		$this->save_link( $post, 'cccc' );

		$first_page = $this->repository->page_of_links( 0, 2 );

		static::assertCount( 2, $first_page );
		static::assertSame( 'cccc', $first_page[0]->token_hint() );
		static::assertSame( 'bbbb', $first_page[1]->token_hint() );

		$second_page = $this->repository->page_of_links( 2, 2 );

		static::assertCount( 1, $second_page );
		static::assertSame( 'aaaa', $second_page[0]->token_hint() );
	}

	public function test_hydrates_the_stored_fields(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->repository->save(
			new PreviewLink( $post, 'a-hash', 2000, 5, 7, 1000, 0, null, 'ab12' )
		);

		$links = $this->repository->page_of_links( 0, 10 );

		static::assertCount( 1, $links );
		static::assertSame( $post, $links[0]->post_id() );
		static::assertSame( 'a-hash', $links[0]->token_hash() );
		static::assertSame( 5, $links[0]->max_uses() );
		static::assertSame( 7, $links[0]->created_by() );
		static::assertSame( 'ab12', $links[0]->token_hint() );
	}

	public function test_round_trips_the_ip_allowlist(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->repository->save(
			new PreviewLink( $post, 'a-hash', 2000, null, 1, 1000, 0, null, 'ab12', [ '203.0.113.0/24', '2001:db8::/32' ] )
		);

		static::assertSame(
			[ '203.0.113.0/24', '2001:db8::/32' ],
			$this->repository->all_for_post( $post )[0]->allowed_ips()
		);
	}

	public function test_a_row_without_the_optional_keys_is_still_revocable(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		// An unrestricted row has no allowed_ips or recipients key at all. The
		// revoke write is a compare-and-swap against the exact stored array, so
		// the rebuilt link must serialise back to the same bytes or the revoke
		// silently no-ops.
		add_post_meta(
			$post,
			PostMetaTokenRepository::META_KEY,
			[
				'version'    => 3,
				'token_hash' => 'legacy-hash',
				'expires_at' => time() + HOUR_IN_SECONDS,
				'max_uses'   => null,
				'created_by' => 1,
				'created_at' => time() - HOUR_IN_SECONDS,
				'revoked_at' => null,
				'token_hint' => 'ab12',
			]
		);

		$link = $this->repository->find_by_hash( $post, 'legacy-hash' );
		static::assertNotNull( $link );
		static::assertSame( [], $link->allowed_ips() );

		$this->repository->revoke( $link, time() );

		$reloaded = $this->repository->find_by_hash( $post, 'legacy-hash' );
		static::assertNotNull( $reloaded );
		static::assertTrue( $reloaded->is_revoked() );
	}

	public function test_a_pre_release_row_reads_its_inline_count(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->add_pre_release_row( $post, 'v2-hash', [ 'viewers' => [ 'a', 'b' ] ] );
		$this->add_pre_release_row(
			$post,
			'v1-hash',
			[
				'version'   => 1,
				'use_count' => 4,
			]
		);

		static::assertSame( 2, $this->repository->find_by_hash( $post, 'v2-hash' )?->use_count() );
		static::assertSame( 4, $this->repository->find_by_hash( $post, 'v1-hash' )?->use_count() );
	}

	public function test_the_first_claim_on_a_pre_release_row_moves_its_count_out(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->add_pre_release_row( $post, 'v2-hash', [ 'viewers' => [ 'a', 'b' ] ] );
		$stale = $this->repository->find_by_hash( $post, 'v2-hash' );
		static::assertNotNull( $stale );

		static::assertTrue( $this->repository->add_use( $stale ) );

		// A concurrent claim from the same pre-release read loses, rather than
		// adding a second uses row.
		static::assertFalse( $this->repository->add_use( $stale ) );

		$settings = get_post_meta( $post, PostMetaTokenRepository::META_KEY, true );
		static::assertIsArray( $settings );
		static::assertArrayNotHasKey( 'viewers', $settings );
		static::assertSame( 3, $settings['version'] ?? null );
		static::assertCount( 1, (array) get_post_meta( $post, PostMetaTokenRepository::USES_META_KEY, false ) );

		$moved = $this->repository->find_by_hash( $post, 'v2-hash' );
		static::assertNotNull( $moved );
		static::assertSame( 3, $moved->use_count() );

		// From here it is an ordinary link.
		static::assertTrue( $this->repository->add_use( $moved ) );
		static::assertSame( 4, $this->repository->find_by_hash( $post, 'v2-hash' )?->use_count() );
	}

	public function test_a_pre_release_row_is_revocable_and_keeps_its_count(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->add_pre_release_row( $post, 'v2-hash', [ 'viewers' => [ 'a', 'b' ] ] );
		$link = $this->repository->find_by_hash( $post, 'v2-hash' );
		static::assertNotNull( $link );

		static::assertTrue( $this->repository->revoke( $link, 1234 ) );

		$reloaded = $this->repository->find_by_hash( $post, 'v2-hash' );
		static::assertNotNull( $reloaded );
		static::assertSame( 1234, $reloaded->revoked_at() );
		static::assertSame( 2, $reloaded->use_count() );
	}

	public function test_a_dead_pre_release_row_is_garbage_collected(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->add_pre_release_row(
			$post,
			'v2-hash',
			[
				'viewers'    => [ 'a' ],
				'expires_at' => 1000,
			]
		);

		static::assertSame( 1, $this->repository->delete_dead_for_post( $post, 2000, 3000 ) );
		static::assertSame( [], $this->repository->all_for_post( $post ) );
	}

	public function test_a_claim_waits_for_a_links_uses_row_rather_than_creating_one(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		// A current-shape row whose uses row is not written yet, as in the
		// moment between the two writes that create or move one.
		add_post_meta(
			$post,
			PostMetaTokenRepository::META_KEY,
			[
				'version'    => 3,
				'token_hash' => 'new-hash',
				'expires_at' => time() + HOUR_IN_SECONDS,
				'max_uses'   => null,
				'created_by' => 1,
				'created_at' => time(),
				'revoked_at' => null,
				'token_hint' => 'ab12',
			]
		);

		$link = $this->repository->find_by_hash( $post, 'new-hash' );
		static::assertNotNull( $link );

		static::assertFalse( $this->repository->add_use( $link ) );
		static::assertSame( [], get_post_meta( $post, PostMetaTokenRepository::USES_META_KEY, false ) );
	}

	public function test_revokes_every_live_link_on_a_post(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa' );
		$this->save_link( $post, 'bbbb' );

		static::assertSame( 2, $this->repository->revoke_all_for_post( $post, 1234, PHP_INT_MAX ) );

		foreach ( $this->repository->all_for_post( $post ) as $link ) {
			static::assertSame( 1234, $link->revoked_at() );
		}

		// Idempotent: already-revoked links are not counted again.
		static::assertSame( 0, $this->repository->revoke_all_for_post( $post, 5678, PHP_INT_MAX ) );
	}

	public function test_a_viewer_claiming_mid_revoke_does_not_stop_the_revoke(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa' );
		$link = $this->repository->all_for_post( $post )[0];

		// A viewer claims a slot between the revoke's read and its write. The
		// claim only writes the uses row, so the revoke's single write still
		// matches, and neither write clobbers the other.
		$raced = false;
		add_filter(
			'update_post_metadata',
			function ( $check, $object_id, $meta_key ) use ( &$raced, $link ) {
				if ( ! $raced && PostMetaTokenRepository::META_KEY === $meta_key ) {
					$raced = true;
					static::assertTrue( $this->repository->add_use( $link ) );
				}

				return $check;
			},
			10,
			3
		);

		static::assertTrue( $this->repository->revoke( $link, 1234 ) );

		$reloaded = $this->repository->find_by_hash( $post, $link->token_hash() );
		static::assertNotNull( $reloaded );
		static::assertSame( 1234, $reloaded->revoked_at() );
		static::assertSame( 1, $reloaded->use_count() );
	}

	public function test_a_use_is_counted_against_its_own_link(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa' );
		$this->save_link( $post, 'bbbb' );
		[ $first, $second ] = $this->repository->all_for_post( $post );

		static::assertTrue( $this->repository->add_use( $first ) );

		// A second claim from the same stale read loses the compare-and-swap.
		static::assertFalse( $this->repository->add_use( $first ) );

		$reloaded = $this->repository->all_for_post( $post );
		static::assertSame( 1, $reloaded[0]->use_count() );
		static::assertSame( 0, $reloaded[1]->use_count() );
		static::assertSame( $second->token_hash(), $reloaded[1]->token_hash() );
	}

	public function test_a_claim_that_loses_to_another_process_retries_against_its_write(): void {
		$post  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token = $this->save_capped_link( $post, 3 );

		// Another process spends a slot after this request cached the post's meta.
		$this->overwrite_behind_the_cache( $post, PostMetaTokenRepository::USES_META_KEY, $this->uses_row( $token, 1 ) );

		static::assertTrue( $this->service()->claim_slot( $post, $token ) );
		static::assertSame( 2, $this->repository->find( $post, $token )?->use_count() );
	}

	public function test_a_claim_that_loses_the_last_slot_to_another_process_is_denied(): void {
		$post  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token = $this->save_capped_link( $post, 1 );

		$this->overwrite_behind_the_cache( $post, PostMetaTokenRepository::USES_META_KEY, $this->uses_row( $token, 1 ) );

		static::assertFalse( $this->service()->claim_slot( $post, $token ) );

		// Read the stored count, not this request's cache: the cap must hold.
		wp_cache_delete( (string) $post, 'post_meta' );
		static::assertSame( 1, $this->repository->find( $post, $token )?->use_count() );
	}

	public function test_a_revoke_that_loses_to_another_process_retries_against_its_write(): void {
		$post  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token = $this->save_capped_link( $post, 3 );
		$link  = $this->repository->find( $post, $token );
		static::assertNotNull( $link );

		// Another process changes the row (keeping its hash) after this read.
		$row = get_post_meta( $post, PostMetaTokenRepository::META_KEY, true );
		static::assertIsArray( $row );

		$row['expires_at'] = 9999999999;
		$this->overwrite_behind_the_cache( $post, PostMetaTokenRepository::META_KEY, $row );

		static::assertTrue( $this->repository->revoke( $link, 1234 ) );

		$reloaded = $this->repository->find( $post, $token );
		static::assertNotNull( $reloaded );
		static::assertSame( 1234, $reloaded->revoked_at() );
		static::assertSame( 9999999999, $reloaded->expires_at() );
	}

	public function test_listing_carries_each_links_use_count(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa' );
		$this->repository->add_use( $this->repository->all_for_post( $post )[0] );

		static::assertSame( 1, $this->repository->page_of_links( 0, 10 )[0]->use_count() );
	}

	/**
	 * Every caller of a page loads each row's post (a capability check, a
	 * title), so the page primes the posts and their meta up front; on a cold
	 * cache that is two queries per page rather than one per post.
	 */
	public function test_a_page_primes_its_posts_and_their_meta(): void {
		/** @var \wpdb $wpdb */
		global $wpdb;

		$posts = [
			self::factory()->post->create( [ 'post_status' => 'draft' ] ),
			self::factory()->post->create( [ 'post_status' => 'draft' ] ),
			self::factory()->post->create( [ 'post_status' => 'draft' ] ),
		];

		foreach ( $posts as $post ) {
			$this->save_link( $post, 'aaaa' );
		}

		wp_cache_flush();
		$this->repository->page_of_links( 0, 10 );
		$queries = $wpdb->num_queries;

		foreach ( $posts as $post ) {
			get_post( $post );
			get_post_meta( $post );
		}

		static::assertSame( $queries, $wpdb->num_queries );
	}

	public function test_deleting_links_removes_their_uses_rows(): void {
		$dead = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$gone = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->repository->save( new PreviewLink( $dead, 'dead-hash', 1000, null, 1, 500, 0, null, 'dddd' ) );
		$this->save_link( $dead, 'live' );
		$this->save_link( $gone, 'gggg' );

		static::assertSame( 1, $this->repository->delete_dead_for_post( $dead, 2000, 3000 ) );
		$this->repository->delete_all_for_post( $gone );

		$remaining = get_post_meta( $dead, PostMetaTokenRepository::USES_META_KEY, false );
		static::assertIsArray( $remaining );
		static::assertCount( 1, $remaining );
		static::assertSame( [], get_post_meta( $gone, PostMetaTokenRepository::USES_META_KEY, false ) );
	}

	public function test_revokes_only_one_creators_links_on_a_post(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa', 7 );
		$this->save_link( $post, 'bbbb', 8 );

		static::assertSame( 1, $this->repository->revoke_by_creator_for_post( $post, 7, 1234, PHP_INT_MAX ) );

		foreach ( $this->repository->all_for_post( $post ) as $link ) {
			static::assertSame( 7 === $link->created_by(), $link->is_revoked() );
		}
	}

	/**
	 * The creator filter matches against the serialised row in SQL, so prove it
	 * against real postmeta — including that user 1 does not match user 11.
	 */
	public function test_filters_the_listing_by_creator(): void {
		$post = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $post, 'aaaa', 1 );
		$this->save_link( $post, 'bbbb', 11 );
		$this->save_link( $post, 'cccc', 11 );

		static::assertSame( 1, $this->repository->count_links( 1 ) );
		static::assertSame( 2, $this->repository->count_links( 11 ) );
		static::assertSame( 3, $this->repository->count_links() );

		$links = $this->repository->page_of_links( 0, 10, 11 );

		static::assertCount( 2, $links );
		foreach ( $links as $link ) {
			static::assertSame( 11, $link->created_by() );
		}
	}

	public function test_is_empty_without_links(): void {
		static::assertSame( 0, $this->repository->count_links() );
		static::assertSame( [], $this->repository->page_of_links( 0, 10 ) );
	}

	public function test_recipients_round_trip_and_survive_a_revoke(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();

		$this->repository->save(
			PreviewLink::issue( $post_id, $token, time() + HOUR_IN_SECONDS, null, 1, time(), [], [ 'legal@example.com' ] )
		);

		$stored = $this->repository->find( $post_id, $token );
		static::assertNotNull( $stored );
		static::assertSame( [ 'legal@example.com' ], $stored->recipients() );

		// The revoke write is a compare-and-swap on the serialised row, so the
		// recipients key has to round-trip byte-for-byte for it to match.
		$this->repository->revoke( $stored, time() );

		$revoked = $this->repository->find( $post_id, $token );
		static::assertNotNull( $revoked );
		static::assertTrue( $revoked->is_revoked() );
		static::assertSame( [ 'legal@example.com' ], $revoked->recipients() );
	}

	/**
	 * Store a link the way a pre-release build did: version 2 by default, with
	 * no uses row. Callers supply the inline count (`viewers` or `use_count`).
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function add_pre_release_row( int $post_id, string $token_hash, array $overrides ): void {
		add_post_meta(
			$post_id,
			PostMetaTokenRepository::META_KEY,
			array_merge(
				[
					'version'    => 2,
					'token_hash' => $token_hash,
					'expires_at' => time() + HOUR_IN_SECONDS,
					'max_uses'   => 5,
					'created_by' => 1,
					'created_at' => time() - HOUR_IN_SECONDS,
					'revoked_at' => null,
					'token_hint' => 'ab12',
				],
				$overrides
			)
		);
	}

	private function service(): PreviewLinkService {
		return new PreviewLinkService( $this->repository, new AccessPolicy(), new SystemClock() );
	}

	/**
	 * @return array{token_hash: string, uses: int}
	 */
	private function uses_row( Token $token, int $uses ): array {
		return [
			'token_hash' => $token->hash(),
			'uses'       => $uses,
		];
	}

	private function save_capped_link( int $post_id, int $max_uses ): Token {
		$token = Token::generate();

		$this->repository->save( PreviewLink::issue( $post_id, $token, time() + HOUR_IN_SECONDS, $max_uses, 1, time() ) );

		return $token;
	}

	/**
	 * Rewrite a post's only row for this key straight in the database, as
	 * another process would, leaving this request's (freshly primed) meta cache
	 * holding the old value.
	 *
	 * @param array<mixed> $row
	 */
	private function overwrite_behind_the_cache( int $post_id, string $meta_key, array $row ): void {
		global $wpdb;
		static::assertInstanceOf( \wpdb::class, $wpdb );

		get_post_meta( $post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The point is to bypass the cache.
		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => maybe_serialize( $row ) ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A write, not a query.
			[
				'post_id'  => $post_id,
				'meta_key' => $meta_key,
			]
		);
	}

	private function save_link( int $post_id, string $hint, int $created_by = 1 ): void {
		$this->repository->save(
			new PreviewLink(
				$post_id,
				Token::generate()->hash(),
				time() + HOUR_IN_SECONDS,
				null,
				$created_by,
				time(),
				0,
				null,
				$hint
			)
		);
	}
}
