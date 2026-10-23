<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The cron sweep that stops dead links accumulating as postmeta forever.
 *
 * @covers \Automattic\ShareADraft\LinkGarbageCollector
 */
class LinkGarbageCollectorTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;
	private LinkGarbageCollector $collector;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);
		$this->collector  = new LinkGarbageCollector( $this->service );
	}

	public function tear_down(): void {
		LinkGarbageCollector::unschedule();
		remove_all_filters( 'shareadraft_dead_link_grace_period' );
		$this->set_config( null );
		parent::tear_down();
	}

	public function test_it_deletes_links_dead_beyond_the_grace_period(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - 90 * DAY_IN_SECONDS );

		static::assertSame( 1, $this->collector->run() );
		static::assertCount( 0, $this->repository->all_for_post( $post_id ) );
	}

	public function test_it_keeps_a_recently_expired_link_so_the_gate_can_explain_it(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - HOUR_IN_SECONDS );

		static::assertSame( 0, $this->collector->run() );
		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}

	public function test_it_keeps_live_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 0, $this->collector->run() );
		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}

	public function test_it_deletes_a_long_revoked_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();

		$this->repository->save(
			new PreviewLink(
				$post_id,
				$token->hash(),
				time() + YEAR_IN_SECONDS,
				null,
				1,
				time() - 200 * DAY_IN_SECONDS,
				0,
				time() - 90 * DAY_IN_SECONDS
			)
		);

		static::assertSame( 1, $this->collector->run() );
	}

	public function test_the_platform_config_can_set_the_grace_period(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - 2 * HOUR_IN_SECONDS );

		$this->set_config( new Config( [ 'dead_link_grace_period' => HOUR_IN_SECONDS ] ) );

		static::assertSame( 1, $this->collector->run() );
	}

	/**
	 * A config value that would delete links the moment they expire is treated as
	 * nonsense and ignored, rather than quietly costing the gate its explanation.
	 *
	 * @dataProvider provide_unusable_grace_periods
	 * @param mixed $value
	 */
	public function test_an_unusable_configured_grace_period_falls_back( $value ): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - HOUR_IN_SECONDS );

		$this->set_config( new Config( [ 'dead_link_grace_period' => $value ] ) );

		static::assertSame( 0, $this->collector->run() );
		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_unusable_grace_periods(): array {
		return [
			// What a field left blank in the VIP Dashboard arrives as; see
			// fixtures/config-incomplete.php.
			'blank'    => [ '' ],
			'zero'     => [ 0 ],
			'negative' => [ -1 ],
			'string'   => [ 'soon' ],
			'array'    => [ [] ],
		];
	}

	/**
	 * The half-configured state the fixture describes, end to end.
	 */
	public function test_the_incomplete_fixture_falls_back_to_the_default_grace(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - 20 * DAY_IN_SECONDS );

		$this->set_config( new Config( require __DIR__ . '/../../fixtures/config-incomplete.php' ) );

		// 20 days dead is inside the 21-day default, so the link survives.
		static::assertSame( 0, $this->collector->run() );
		static::assertCount( 1, $this->repository->all_for_post( $post_id ) );
	}

	/**
	 * The filter still has the last word over whatever the platform injected.
	 */
	public function test_the_filter_overrides_the_platform_config(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - 2 * HOUR_IN_SECONDS );

		$this->set_config( new Config( [ 'dead_link_grace_period' => YEAR_IN_SECONDS ] ) );
		add_filter( 'shareadraft_dead_link_grace_period', static fn (): int => HOUR_IN_SECONDS );

		static::assertSame( 1, $this->collector->run() );
	}

	public function test_the_grace_period_is_filterable(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->save_link( $post_id, time() - 2 * HOUR_IN_SECONDS );

		add_filter( 'shareadraft_dead_link_grace_period', static fn (): int => HOUR_IN_SECONDS );

		static::assertSame( 1, $this->collector->run() );
	}

	public function test_it_sweeps_across_posts(): void {
		$first  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$second = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$this->save_link( $first, time() - 90 * DAY_IN_SECONDS );
		$this->save_link( $second, time() - 90 * DAY_IN_SECONDS );

		static::assertSame( 2, $this->collector->run() );
	}

	public function test_registering_schedules_a_daily_sweep(): void {
		$this->collector->register();

		static::assertNotFalse( wp_next_scheduled( LinkGarbageCollector::HOOK ) );
		static::assertSame( 'daily', wp_get_schedule( LinkGarbageCollector::HOOK ) );
	}

	/**
	 * The daily event is registered throughout, because during a real cron run
	 * core and Cron Control have already rescheduled it before calling run().
	 */
	public function test_a_full_batch_queues_a_follow_up_run(): void {
		$this->collector->register();
		$this->seed_posts_with_links( 100 );

		$this->collector->run();

		static::assertSame( 1, $this->near_term_sweeps() );
	}

	public function test_a_full_batch_queues_only_one_follow_up_run(): void {
		$this->collector->register();
		$this->seed_posts_with_links( 200 );

		$this->collector->run();
		$this->collector->run();

		static::assertSame( 1, $this->near_term_sweeps() );
	}

	public function test_a_partial_batch_waits_for_the_daily_run(): void {
		$this->collector->register();
		$this->seed_posts_with_links( 99 );

		$this->collector->run();

		static::assertSame( 0, $this->near_term_sweeps() );
	}

	public function test_unscheduling_clears_the_event(): void {
		$this->collector->register();
		LinkGarbageCollector::unschedule();

		static::assertFalse( wp_next_scheduled( LinkGarbageCollector::HOOK ) );
		static::assertNull( LinkGarbageCollector::last_run() );
	}

	/**
	 * Site Health reads this to tell "the sweep is scheduled" apart from "the
	 * sweep is actually running".
	 */
	public function test_a_run_is_stamped_even_when_there_is_nothing_to_sweep(): void {
		static::assertNull( LinkGarbageCollector::last_run() );

		$before = time();
		static::assertSame( 0, $this->collector->run() );

		$last_run = LinkGarbageCollector::last_run();
		static::assertNotNull( $last_run );
		static::assertGreaterThanOrEqual( $before, $last_run );
	}

	/**
	 * A batch that fails every time never advances the cursor, so the sweep is
	 * stuck. The marker must not claim otherwise, or Site Health stays green.
	 */
	public function test_a_run_that_fails_mid_batch_is_not_stamped(): void {
		$this->save_link( self::factory()->post->create( [ 'post_status' => 'draft' ] ), time() - 90 * DAY_IN_SECONDS );
		add_action(
			'delete_post_meta',
			static function (): void {
				throw new \RuntimeException( 'Batch failed.' );
			}
		);

		try {
			$this->collector->run();
			static::fail( 'The run should have thrown.' );
		} catch ( \RuntimeException $e ) {
			static::assertNull( LinkGarbageCollector::last_run() );
		}
	}

	public function test_a_budgeted_sweep_stops_and_reports_the_rest_as_pending(): void {
		$this->seed_posts_with_links( 101 );

		static::assertSame(
			[
				'pruned'  => 100,
				'pending' => true,
			],
			$this->collector->sweep_all( 0, 1 )
		);
		static::assertSame(
			[
				'pruned'  => 1,
				'pending' => false,
			],
			$this->collector->sweep_all( 0, 1 )
		);
	}

	/**
	 * Swap the Config singleton so a test can mimic a platform-injected value.
	 */
	private function set_config( ?Config $config ): void {
		$property = new \ReflectionProperty( Config::class, 'instance' );
		$property->setValue( null, $config );
	}

	private function seed_posts_with_links( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->save_link( self::factory()->post->create( [ 'post_status' => 'draft' ] ), time() - HOUR_IN_SECONDS );
		}
	}

	/**
	 * Sweeps due within the next few minutes, as opposed to the daily one.
	 */
	private function near_term_sweeps(): int {
		$count = 0;

		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp <= time() + 5 * MINUTE_IN_SECONDS && isset( $hooks[ LinkGarbageCollector::HOOK ] ) ) {
				$count += count( $hooks[ LinkGarbageCollector::HOOK ] );
			}
		}

		return $count;
	}

	/**
	 * Persist a link that expired at the given moment.
	 */
	private function save_link( int $post_id, int $expires_at ): void {
		$this->repository->save(
			new PreviewLink(
				$post_id,
				Token::generate()->hash(),
				$expires_at,
				null,
				1,
				$expires_at - HOUR_IN_SECONDS
			)
		);
	}
}
