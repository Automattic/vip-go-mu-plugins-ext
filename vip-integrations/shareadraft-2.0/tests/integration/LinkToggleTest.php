<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_Post;
use WP_Query;
use WP_UnitTestCase;

/**
 * The site-wide enable/disable switch: a reversible pause the gate honours,
 * without touching any link's own state.
 *
 * @covers \Automattic\ShareADraft\LinkToggle
 */
class LinkToggleTest extends WP_UnitTestCase {
	private PreviewLinkService $service;
	private LinkToggle $toggle;

	public function set_up(): void {
		parent::set_up();

		$this->service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy(),
			new SystemClock()
		);
		$this->toggle  = new LinkToggle();

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Human)';
	}

	public function tear_down(): void {
		unset( $_GET[ PreviewGate::TOKEN_QUERY_VAR ], $_SERVER['HTTP_USER_AGENT'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->toggle->enable();
		parent::tear_down();
	}

	public function test_starts_enabled(): void {
		static::assertFalse( $this->toggle->is_disabled() );
		static::assertNull( $this->toggle->disabled_at() );
	}

	public function test_disabling_records_when_and_by_whom(): void {
		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $actor );

		$before = time();
		$this->toggle->disable();

		static::assertTrue( $this->toggle->is_disabled() );
		static::assertSame( $actor, $this->toggle->disabled_by() );
		static::assertGreaterThanOrEqual( $before, $this->toggle->disabled_at() );
	}

	/**
	 * Re-flipping an already-off switch must not overwrite who first flipped it
	 * — those are the facts an investigation wants.
	 */
	public function test_disabling_twice_keeps_the_original_actor(): void {
		$first = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $first );
		$this->toggle->disable();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->toggle->disable();

		static::assertSame( $first, $this->toggle->disabled_by() );
	}

	public function test_a_corrupt_option_reads_as_enabled(): void {
		update_option( 'shareadraft_disabled', 'yes please' );

		static::assertFalse( $this->toggle->is_disabled() );
	}

	public function test_the_gate_pauses_and_resumes_valid_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 'publish', $this->visit( $post_id, $token ) );

		$this->toggle->disable();
		static::assertSame( 'draft', $this->visit( $post_id, $token ) );

		// Nothing was revoked: the link's own state is untouched, so it works
		// again the moment the switch is back on.
		$this->toggle->enable();
		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
	}

	public function test_a_live_link_is_told_links_are_paused(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->toggle->disable();

		$gate = $this->paused_gate( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionCode( 410 );
		$this->expectExceptionMessageMatches( '/temporarily disabled/i' );
		$gate->maybe_render_notice();
	}

	public function test_an_unknown_token_still_shows_no_notice_while_paused(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->toggle->disable();

		// Pausing must not turn a made-up token into proof the draft exists.
		$gate = $this->paused_gate( $post_id, Token::from_string( 'not-a-real-token' ) );

		$this->expectOutputString( '' );
		$gate->maybe_render_notice();
	}

	public function test_a_blocked_ip_still_shows_no_notice_while_paused(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [ '203.0.113.0/24' ] );
		$this->toggle->disable();

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$gate                   = $this->paused_gate( $post_id, $token );

		$this->expectOutputString( '' );
		$gate->maybe_render_notice();
	}

	public function test_a_dead_link_says_why_rather_than_that_links_are_paused(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->revoke( $post_id, $token->hash() );
		$this->toggle->disable();

		// It will not work once links are back on, so "try again later" would
		// send the reviewer on a pointless errand.
		$gate = $this->paused_gate( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/revoked/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_recipient_bound_link_is_not_offered_verification_while_paused(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );
		$this->toggle->disable();

		// Verifying would email a code for a link that cannot open yet.
		$gate = $this->paused_gate( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/temporarily disabled/i' );
		$gate->maybe_render_notice();
	}

	private function paused_gate( int $post_id, Token $token ): PreviewGate {
		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		clean_post_cache( $post_id );

		$query             = new WP_Query();
		$query->is_preview = true;

		$gate = new PreviewGate( $this->service );
		$gate->unlock_valid_previews( [ get_post( $post_id ) ], $query );

		return $gate;
	}

	private function visit( int $post_id, Token $token ): string {
		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		clean_post_cache( $post_id );

		$query             = new WP_Query();
		$query->is_preview = true;

		$posts = ( new PreviewGate( $this->service ) )
			->unlock_valid_previews( [ get_post( $post_id ) ], $query );

		static::assertIsArray( $posts );

		$first = $posts[0] ?? null;
		static::assertInstanceOf( WP_Post::class, $first );

		return $first->post_status;
	}
}
