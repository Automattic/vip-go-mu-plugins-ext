<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The Site Health test that reports whether the cleanup sweep is running.
 *
 * @covers \Automattic\ShareADraft\SiteHealth
 */
class SiteHealthTest extends WP_UnitTestCase {
	/**
	 * The option {@see LinkGarbageCollector} stamps on each run. Named here as a
	 * literal on purpose: Site Health reads it across a class boundary, so the
	 * name is part of the contract and a rename should fail a test.
	 */
	private const LAST_RUN_OPTION = 'shareadraft_gc_last_run';

	private SiteHealth $health;

	public function set_up(): void {
		parent::set_up();

		// The plugin schedules the sweep when it loads, so the test suite starts
		// with one already on the books. Clear it so each case sets up the cron
		// state it actually means to assert against.
		LinkGarbageCollector::unschedule();

		$this->health = new SiteHealth( new SystemClock() );
	}

	public function tear_down(): void {
		LinkGarbageCollector::unschedule();
		parent::tear_down();
	}

	public function test_it_registers_a_direct_test(): void {
		static::assertArrayHasKey(
			'shareadraft_link_cleanup',
			$this->direct_tests( [
				'direct' => [],
				'async'  => [],
			] )
		);
	}

	/**
	 * The filter is public, so another plugin can hand it anything.
	 */
	public function test_it_tolerates_a_mangled_tests_array(): void {
		static::assertSame( 'not-an-array', $this->health->add_test( 'not-an-array' ) );
		static::assertArrayHasKey( 'shareadraft_link_cleanup', $this->direct_tests( [] ) );
	}

	/**
	 * @param mixed $tests
	 * @return array<string, mixed>
	 */
	private function direct_tests( $tests ): array {
		/** @var mixed $filtered */
		$filtered = $this->health->add_test( $tests );

		static::assertIsArray( $filtered );
		static::assertArrayHasKey( 'direct', $filtered );

		/** @var mixed $direct */
		$direct = $filtered['direct'];
		static::assertIsArray( $direct );

		/** @var array<string, mixed> $direct */
		return $direct;
	}

	public function test_an_unscheduled_sweep_is_flagged(): void {
		$result = $this->health->run_test();

		static::assertSame( 'recommended', $result['status'] );
		static::assertStringContainsString( 'not being cleaned up', $result['label'] );
	}

	public function test_a_scheduled_sweep_that_has_never_run_is_fine(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', LinkGarbageCollector::HOOK );

		$result = $this->health->run_test();

		static::assertSame( 'good', $result['status'] );
	}

	public function test_a_recently_run_sweep_is_fine(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', LinkGarbageCollector::HOOK );
		update_option( self::LAST_RUN_OPTION, time() - HOUR_IN_SECONDS, false );

		$result = $this->health->run_test();

		static::assertSame( 'good', $result['status'] );
		static::assertStringContainsString( 'last ran', $result['description'] );
	}

	/**
	 * The strongest signal that cron is not firing: the event is scheduled, and
	 * its due time has come and gone.
	 */
	public function test_an_overdue_sweep_is_flagged(): void {
		wp_schedule_event( time() - 3 * DAY_IN_SECONDS, 'daily', LinkGarbageCollector::HOOK );

		$result = $this->health->run_test();

		static::assertSame( 'recommended', $result['status'] );
		static::assertStringContainsString( 'running late', $result['label'] );
	}

	/**
	 * A quiet site runs its daily cron late. That is normal, not broken.
	 */
	public function test_a_slightly_late_sweep_is_tolerated(): void {
		wp_schedule_event( time() - HOUR_IN_SECONDS, 'daily', LinkGarbageCollector::HOOK );

		static::assertSame( 'good', $this->health->run_test()['status'] );
	}

	public function test_a_stalled_sweep_is_flagged_even_when_the_next_run_looks_soon(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', LinkGarbageCollector::HOOK );
		update_option( self::LAST_RUN_OPTION, time() - 10 * DAY_IN_SECONDS, false );

		$result = $this->health->run_test();

		static::assertSame( 'recommended', $result['status'] );
	}

	/**
	 * Site Health renders the description as HTML, so it must be escaped here.
	 */
	public function test_the_description_is_escaped_html(): void {
		$result = $this->health->run_test();

		static::assertStringStartsWith( '<p>', $result['description'] );
		static::assertSame( 'shareadraft_link_cleanup', $result['test'] );
	}
}
