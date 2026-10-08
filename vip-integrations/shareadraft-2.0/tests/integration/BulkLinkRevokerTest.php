<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The bulk-revoke sweeps: break-glass revoke-all, revoke-by-creator, and the
 * user-lifecycle hooks that drive offboarding.
 *
 * @covers \Automattic\ShareADraft\BulkLinkRevoker
 */
class BulkLinkRevokerTest extends WP_UnitTestCase {
	private const JOBS_OPTION = 'shareadraft_bulk_revoke_jobs';

	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;
	private BulkLinkRevoker $revoker;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);
		$this->revoker    = new BulkLinkRevoker( $this->service );
	}

	public function tear_down(): void {
		BulkLinkRevoker::unschedule();
		delete_option( self::JOBS_OPTION );
		remove_all_actions( BulkLinkRevoker::REVOKED_USER_ACTION );
		parent::tear_down();
	}

	public function test_revoke_all_revokes_every_live_link_across_posts(): void {
		$first  = $this->draft();
		$second = $this->draft();

		$this->service->mint( $first, HOUR_IN_SECONDS, null, 1 );
		$this->service->mint( $second, HOUR_IN_SECONDS, null, 2 );

		static::assertSame( 2, $this->revoker->revoke_all() );
		static::assertFalse( $this->revoker->has_pending_work() );

		foreach ( [ $first, $second ] as $post_id ) {
			foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
				static::assertTrue( $link->is_revoked() );
			}
		}
	}

	public function test_revoke_by_creator_only_touches_that_users_links(): void {
		$post_id = $this->draft();

		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 8 );

		static::assertSame( 1, $this->revoker->revoke_by_creator( 7 ) );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertSame( 7 === $link->created_by(), $link->is_revoked() );
		}
	}

	public function test_revoking_by_creator_fires_the_event_action(): void {
		$post_id = $this->draft();
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );

		$received = null;
		add_action(
			BulkLinkRevoker::REVOKED_USER_ACTION,
			static function ( int $user_id, int $count, int $actor ) use ( &$received ): void {
				$received = [ $user_id, $count, $actor ];
			},
			10,
			3
		);

		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $actor );

		$this->revoker->revoke_by_creator( 7 );

		static::assertSame( [ 7, 1, $actor ], $received );
	}

	public function test_deleting_a_user_revokes_the_links_they_created(): void {
		$this->revoker->register();

		$creator = self::factory()->user->create( [ 'role' => 'editor' ] );
		$post_id = $this->draft();
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, $creator );

		wp_delete_user( $creator );

		$links = $this->repository->all_for_post( $post_id );
		static::assertCount( 1, $links );
		static::assertTrue( $links[0]->is_revoked() );
	}

	/**
	 * The documented extension point: customers wire their own hooks (role
	 * change, multisite removal) to this action.
	 */
	public function test_the_revoke_user_links_action_is_a_public_entry_point(): void {
		$this->revoker->register();

		$post_id = $this->draft();
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 42 );

		do_action( BulkLinkRevoker::REVOKE_USER_ACTION, 42 );

		static::assertTrue( $this->repository->all_for_post( $post_id )[0]->is_revoked() );
	}

	/**
	 * A site with more link-carrying posts than one batch continues on cron
	 * rather than finishing (or timing out) in a single run, and the completion
	 * event carries the total across every batch.
	 */
	public function test_an_overflowing_sweep_continues_on_cron(): void {
		// One more post than the batch size of 100.
		$post_ids = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$post_id    = $this->draft();
			$post_ids[] = $post_id;
			$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		}

		// An object, not a by-reference array: static analysis cannot see a
		// closure write back through a reference.
		$received = new \ArrayObject();
		add_action(
			BulkLinkRevoker::REVOKED_USER_ACTION,
			static function ( int $user_id, int $count ) use ( $received ): void {
				$received->exchangeArray( [ $user_id, $count ] );
			},
			10,
			2
		);

		static::assertSame( 100, $this->revoker->revoke_by_creator( 7 ) );
		static::assertTrue( $this->revoker->has_pending_work() );
		static::assertNotFalse( wp_next_scheduled( BulkLinkRevoker::HOOK ) );
		// Not fired until the sweep actually finishes.
		static::assertCount( 0, $received );

		static::assertSame( 1, $this->revoker->run() );
		static::assertFalse( $this->revoker->has_pending_work() );
		static::assertSame( [ 7, 101 ], $received->getArrayCopy() );

		foreach ( $post_ids as $post_id ) {
			static::assertTrue( $this->repository->all_for_post( $post_id )[0]->is_revoked() );
		}
	}

	/**
	 * Overlapping offboarding: a second user is deleted while the first user's
	 * sweep is mid-batch. The first sweep must not write its stale copy of the
	 * queue back over the second, and its completion event fires exactly once.
	 */
	public function test_a_sweep_queued_during_another_batch_is_not_dropped(): void {
		$post_id = $this->draft();
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 8 );

		$completed = new \ArrayObject();
		add_action(
			BulkLinkRevoker::REVOKED_USER_ACTION,
			static function ( int $user_id ) use ( $completed ): void {
				$completed->append( $user_id );
			}
		);

		$revoker   = $this->revoker;
		$interrupt = static function () use ( $revoker, &$interrupt ): void {
			remove_action( 'updated_post_meta', $interrupt );
			$revoker->revoke_by_creator( 8 );
		};
		add_action( 'updated_post_meta', $interrupt );

		$this->revoker->revoke_by_creator( 7 );

		static::assertTrue( $this->revoker->has_pending_work() );
		static::assertNotFalse( wp_next_scheduled( BulkLinkRevoker::HOOK ) );

		$this->revoker->run();

		static::assertFalse( $this->revoker->has_pending_work() );
		static::assertSame( [ 7, 8 ], $completed->getArrayCopy() );

		foreach ( $this->repository->all_for_post( $post_id ) as $link ) {
			static::assertTrue( $link->is_revoked() );
		}
	}

	/**
	 * Deactivating mid-sweep leaves the queue in place, and reactivating re-arms
	 * the continuation so the posts it had not reached are swept.
	 */
	public function test_reactivating_resumes_a_sweep_deactivation_interrupted(): void {
		$post_ids = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$post_id    = $this->draft();
			$post_ids[] = $post_id;
			$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );
		}

		$this->revoker->revoke_by_creator( 7 );

		BulkLinkRevoker::unschedule();

		static::assertFalse( wp_next_scheduled( BulkLinkRevoker::HOOK ) );
		static::assertTrue( $this->revoker->has_pending_work() );

		BulkLinkRevoker::reschedule();

		static::assertNotFalse( wp_next_scheduled( BulkLinkRevoker::HOOK ) );

		$this->revoker->run();

		foreach ( $post_ids as $post_id ) {
			static::assertTrue( $this->repository->all_for_post( $post_id )[0]->is_revoked() );
		}
	}

	public function test_reactivating_with_no_pending_work_schedules_nothing(): void {
		BulkLinkRevoker::reschedule();

		static::assertFalse( wp_next_scheduled( BulkLinkRevoker::HOOK ) );
	}

	/**
	 * A queued entry that has lost its `creator` key must not be read as a null
	 * creator, which means "revoke every link on the site".
	 */
	public function test_a_queued_entry_without_a_creator_is_not_read_as_revoke_all(): void {
		$post_id = $this->draft();
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 7 );

		update_option( self::JOBS_OPTION, [
			[
				'id'     => 'corrupt',
				'cursor' => 0,
			],
		], false );

		static::assertFalse( $this->revoker->has_pending_work() );
		static::assertSame( 0, $this->revoker->run() );
		static::assertFalse( $this->repository->all_for_post( $post_id )[0]->is_revoked() );
	}

	public function test_running_with_no_pending_work_is_a_no_op(): void {
		static::assertSame( 0, $this->revoker->run() );
	}

	private function draft(): int {
		return self::factory()->post->create( [ 'post_status' => 'draft' ] );
	}
}
