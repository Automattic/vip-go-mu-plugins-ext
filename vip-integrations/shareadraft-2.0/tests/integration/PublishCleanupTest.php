<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * @covers \Automattic\ShareADraft\PublishCleanup
 */
class PublishCleanupTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);
		( new PublishCleanup( $this->service ) )->register();
	}

	public function test_publishing_a_post_discards_its_preview_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, 5, 1 );

		static::assertCount( 2, $this->repository->all_for_post( $post_id ) );

		wp_publish_post( $post_id );

		static::assertCount( 0, $this->repository->all_for_post( $post_id ) );
	}

	public function test_trashing_a_post_discards_its_preview_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// Binning a draft reads as retracting it. `trash` is a non-public status,
		// so without cleanup the gate would keep unlocking it for link holders.
		wp_trash_post( $post_id );

		static::assertCount( 0, $this->repository->all_for_post( $post_id ) );
	}

	public function test_making_a_post_private_discards_its_preview_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// Private restricts the post to logged-in staff; a link must not
		// reopen it to anonymous visitors.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'private',
			]
		);

		static::assertCount( 0, $this->repository->all_for_post( $post_id ) );
	}

	public function test_a_pending_review_post_keeps_its_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// Still work in progress, and sharing it for review is the whole point.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'pending',
			]
		);

		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}

	public function test_a_draft_saved_as_draft_keeps_its_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Edited while still a draft',
			]
		);

		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}
}
