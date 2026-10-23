<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use MockPHPMailer;
use WP_Post;
use WP_Query;
use WP_UnitTestCase;

/**
 * End-to-end enforcement: mint a real token, then prove the gate unlocks the
 * draft for a request carrying it, counts distinct human viewers against a cap,
 * and leaves it locked otherwise.
 *
 * @covers \Automattic\ShareADraft\PreviewGate
 */
class PreviewGateTest extends WP_UnitTestCase {
	private PreviewLinkService $service;
	private PostMetaTokenRepository $repository;
	private string $request_uri;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new PostMetaTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);

		// A human, not a crawler, so visits count.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Human)';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved verbatim only to restore it in tear_down().
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		unset( $_GET[ PreviewGate::TOKEN_QUERY_VAR ], $_GET['shareadraft-postpass'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_SEC_PURPOSE'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_COOKIE                   = [];
		$_POST                     = [];
		$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
		$_SERVER['REQUEST_URI']    = $this->request_uri;
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_a_valid_token_unlocks_the_draft(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
	}

	public function test_a_wrong_token_leaves_the_draft_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 'draft', $this->visit( $post_id, Token::from_string( 'not-the-token' ) ) );
	}

	public function test_no_token_leaves_the_draft_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$posts = ( new PreviewGate( $this->service, new RecipientVerifier() ) )
			->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		static::assertSame( 'draft', self::first_status( $posts ) );
	}

	public function test_a_leftover_link_does_not_unlock_a_private_post(): void {
		// Minted straight on the service, as a link made before the post went
		// private would have been, so cleanup never saw it.
		$post_id = self::factory()->post->create( [ 'post_status' => 'private' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		static::assertSame( 'private', $this->visit( $post_id, $token ) );
	}

	public function test_non_preview_requests_are_untouched(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		$query             = new WP_Query();
		$query->is_preview = false;
		$posts             = ( new PreviewGate( $this->service, new RecipientVerifier() ) )
			->unlock_valid_previews( [ get_post( $post_id ) ], $query );

		static::assertSame( 'draft', self::first_status( $posts ) );
	}

	public function test_a_capped_link_exhausts_after_distinct_viewers(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );

		// First human: allowed and counted.
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );

		// A second, distinct human (fresh cookie jar): the cap is spent.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
	}

	public function test_the_same_viewer_may_revisit_a_one_use_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );

		// Same browser both times: the server-issued slot ID is kept, so the
		// return visit is fine and does not claim a second slot.
		static::assertSame( 'publish', $this->visit( $post_id, $token, false ) );
		static::assertSame( 'publish', $this->visit( $post_id, $token, false ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_an_array_shaped_cookie_is_ignored_rather_than_fatal(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 5, 1 );

		// A visitor controls their own cookie names, and one ending in `[]` makes
		// PHP hand back an array where a string is expected.
		$_COOKIE = [ 'shareadraft_viewer_' . substr( $token->hash(), 0, 20 ) => [ 'not', 'a', 'string' ] ];

		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_the_slot_cookie_value_is_not_derivable_from_the_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 2, 1 );

		$this->visit( $post_id, $token, true );

		$issued = $this->issued_slot( $token );

		static::assertNotSame( '', $issued, 'A slot ID should have been issued.' );
		static::assertNotSame( '1', $issued );
		static::assertStringNotContainsString( $issued, $token->value() );
		static::assertStringNotContainsString( $issued, $token->hash() );
		static::assertMatchesRegularExpression( '/^[a-f0-9]{96}$/', $issued, 'A slot is a nonce and its signature.' );
	}

	public function test_a_tampered_or_borrowed_slot_does_not_bypass_a_spent_cap(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$other   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// A genuine slot on another link, and a genuine slot on this one.
		$this->visit( $post_id, $other, true );
		$borrowed = $this->issued_slot( $other );
		$this->visit( $post_id, $token, true );
		$issued = $this->issued_slot( $token );
		$cookie = 'shareadraft_viewer_' . substr( $token->hash(), 0, 20 );

		$forgeries = [
			'another link\'s slot'     => $borrowed,
			'a re-signed nonce'        => str_repeat( '0', 32 ) . substr( $issued, 32 ),
			'a flipped signature'      => substr( $issued, 0, -1 ) . ( '0' === substr( $issued, -1 ) ? '1' : '0' ),
			'an unsigned pre-2.0 slot' => str_repeat( 'a', 32 ),
		];

		foreach ( $forgeries as $label => $forged ) {
			$_COOKIE = [ $cookie => $forged ];
			static::assertSame( 'draft', $this->visit( $post_id, $token ), "A spent cap must not open for {$label}." );
		}

		static::assertSame( 1, $this->repository->find( $post_id, $token )?->use_count() );
	}

	public function test_a_view_never_rewrites_the_links_own_row(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$before  = get_post_meta( $post_id, PostMetaTokenRepository::META_KEY, false );

		// Only the separate uses row moves, so a revoke never races a viewer.
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );

		static::assertSame( $before, get_post_meta( $post_id, PostMetaTokenRepository::META_KEY, false ) );
		static::assertSame( 2, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_a_bot_gets_no_content_and_spends_no_slot(): void {
		$post_id                    = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token                      = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'Slackbot-LinkExpanding 1.0';

		// Exempting unfurlers from the cap by user agent alone would hand the
		// bypass to anyone who can set a header. Instead they get a stub: the
		// draft stays locked, and no slot is spent.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_spoofing_a_bot_user_agent_wins_nothing(): void {
		$post_id                    = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token                      = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (definitely-a-crawler-bot)';

		// The point of the stub: claiming to be a crawler costs you the content
		// instead of buying you an uncounted view.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
	}

	public function test_a_missing_user_agent_is_treated_as_automated(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		unset( $_SERVER['HTTP_USER_AGENT'] );

		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_a_head_request_gets_no_content_and_spends_no_slot(): void {
		$post_id                   = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token                     = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$_SERVER['REQUEST_METHOD'] = 'HEAD';

		// A link checker with a browser-like user agent: core would drop the
		// body anyway, so a slot spent here is a slot the reviewer never gets.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_a_prefetch_spends_no_slot_and_is_refused_with_a_non_2xx(): void {
		$post_id                     = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token                       = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );
		$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;prerender';

		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );

		// A 200 stub would be shown in place of the draft when the reviewer
		// navigates; a non-2xx makes the browser discard it and fetch afresh.
		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionCode( 503 );
		$gate->maybe_render_notice();
	}

	public function test_a_bot_is_shown_a_stub_with_no_draft_details(): void {
		$post_id                    = self::factory()->post->create( [
			'post_status' => 'draft',
			'post_title'  => 'Confidential Launch Plan',
		] );
		$token                      = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$_SERVER['HTTP_USER_AGENT'] = 'Slackbot-LinkExpanding 1.0';

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/private preview link/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_forged_viewer_cookie_does_not_bypass_a_spent_cap(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1 );

		// One genuine viewer spends the only slot.
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );

		// A second viewer forges the marker. Everything here is derivable from
		// the shared URL, which is exactly what the old scheme got wrong.
		$_COOKIE = [
			'shareadraft_viewer_' . substr( $token->hash(), 0, 20 ) => substr( hash( 'sha256', $token->value() ), 0, 32 ),
		];

		static::assertSame( 'draft', $this->visit( $post_id, $token ) );
		static::assertSame(
			1,
			$this->repository->all_for_post( $post_id )[0]->use_count(),
			'A forged cookie must not silently suppress the count either.'
		);
	}

	public function test_an_unissued_but_well_formed_cookie_still_claims_a_slot(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 5, 1 );

		$_COOKIE = [
			'shareadraft_viewer_' . substr( $token->hash(), 0, 20 ) => str_repeat( 'a', 96 ),
		];

		// A cookie the server never signed makes this a new viewer, not a free one.
		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
		static::assertSame( 1, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_an_ip_restricted_link_unlocks_only_from_an_allowed_address(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [ '203.0.113.0/24' ] );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
	}

	public function test_a_blocked_ip_sees_a_plain_not_found_not_an_explanation(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [ '203.0.113.0/24' ] );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		// Denied like an unknown token: no notice page that would confirm the
		// draft (or the link) exists to someone outside the allowlist.
		$gate = $this->denied_main_query( $post_id, $token );

		// No notice page, and no wp_die(), which would have thrown.
		$this->expectOutputString( '' );
		$gate->maybe_render_notice();
	}

	public function test_a_blocked_ip_spends_no_slot(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1, [ '203.0.113.0/24' ] );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_the_client_ip_filter_overrides_remote_addr(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [ '203.0.113.0/24' ] );

		// A host behind its own proxy supplies the trusted address by filter.
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		add_filter( 'shareadraft_client_ip', static fn (): string => '203.0.113.7' );

		static::assertSame( 'publish', $this->visit( $post_id, $token, true ) );
	}

	public function test_an_expired_link_shows_a_friendly_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();
		// Save a link that expired an hour ago, without waiting for the clock.
		$this->repository->save(
			new PreviewLink( $post_id, $token->hash(), time() - HOUR_IN_SECONDS, null, 1, time() - 2 * HOUR_IN_SECONDS )
		);

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/expired/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_revoked_link_shows_a_friendly_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->revoke( $post_id, $this->repository->all_for_post( $post_id )[0]->token_hash() );

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/revoked/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_filter_can_collapse_the_reason_to_a_generic_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();
		$this->repository->save(
			new PreviewLink( $post_id, $token->hash(), time() - HOUR_IN_SECONDS, null, 1, time() - 2 * HOUR_IN_SECONDS )
		);

		// An operator who prefers not to name the reason turns disclosure off.
		add_filter( 'shareadraft_disclose_denial_reason', '__return_false' );

		$gate = $this->denied_main_query( $post_id, $token );

		// The specific "expired" wording is withheld in favour of the generic one.
		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/no longer available/i' );
		$gate->maybe_render_notice();
	}

	public function test_the_filter_receives_the_reason_so_it_can_hide_only_some(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );
		$this->service->revoke( $post_id, $this->repository->all_for_post( $post_id )[0]->token_hash() );

		// Reveal every reason except revocation, which the callback singles out
		// using the reason passed alongside the default.
		add_filter(
			'shareadraft_disclose_denial_reason',
			static fn ( bool $disclose, string $reason ): bool => AccessDecision::REASON_REVOKED !== $reason,
			10,
			2
		);

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessageMatches( '/no longer available/i' );
		$gate->maybe_render_notice();
	}

	public function test_an_unknown_token_shows_no_notice(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// A garbage token must 404 like a missing post, not reveal the draft exists.
		$gate = $this->denied_main_query( $post_id, Token::from_string( 'not-a-real-token' ) );

		// No notice page, and no wp_die(), which would have thrown.
		$this->expectOutputString( '' );
		$gate->maybe_render_notice();
	}

	public function test_an_editor_is_not_blocked_by_a_dead_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = Token::generate();
		$this->repository->save(
			new PreviewLink( $post_id, $token->hash(), time() - HOUR_IN_SECONDS, null, 1, time() - 2 * HOUR_IN_SECONDS )
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// An editor can view the draft directly, so the expired link must not
		// intercept them with the notice.
		$gate = $this->denied_main_query( $post_id, $token );

		// No notice page, and no wp_die(), which would have thrown.
		$this->expectOutputString( '' );
		$gate->maybe_render_notice();
	}

	public function test_a_recipient_bound_link_stays_locked_for_an_unverified_visitor(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
	}

	public function test_an_unverified_visitor_spends_no_slot_on_a_capped_recipient_bound_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, 1, 1, [], [ 'legal@example.com' ] );

		// An email security scanner opening the link with a browser's user
		// agent: the docs promise it cannot use up the reviewer's only slot.
		static::assertSame( 'draft', $this->visit( $post_id, $token, true ) );
		static::assertSame( 0, $this->repository->all_for_post( $post_id )[0]->use_count() );
	}

	public function test_an_unverified_visitor_is_offered_the_verification_form(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$gate = $this->denied_main_query( $post_id, $token );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionCode( 403 );
		$this->expectExceptionMessageMatches( '/named reviewers/i' );
		$gate->maybe_render_notice();
	}

	public function test_a_verified_recipient_cookie_unlocks_the_draft(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$_COOKIE = [];
		( new RecipientVerifier() )->remember_verified( $token, 'legal@example.com' );

		static::assertSame( 'publish', $this->visit( $post_id, $token ) );
	}

	public function test_a_forged_verification_cookie_stays_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		// Everything below is derivable from the shared URL except the HMAC,
		// which is what actually keeps strangers out.
		$_COOKIE = [
			'shareadraft_recipient_' . substr( $token->hash(), 0, 20 ) => ( time() + DAY_IN_SECONDS ) . '.' . rtrim( strtr( base64_encode( 'legal@example.com' ), '+/', '-_' ), '=' ) . '.' . str_repeat( 'a', 64 ),
		];

		static::assertSame( 'draft', $this->visit( $post_id, $token ) );
	}

	public function test_a_verified_email_no_longer_on_the_list_stays_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		// A genuine verification for an address the author never listed on
		// *this* link (e.g. minted for a sibling link, or the list changed).
		$_COOKIE = [];
		( new RecipientVerifier() )->remember_verified( $token, 'stranger@example.com' );

		static::assertSame( 'draft', $this->visit( $post_id, $token ) );
	}

	public function test_a_wrong_code_offers_a_new_code_instead_of_a_reload(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$this->post_verification( $post_id, $token, 'request-code', 'legal@example.com' );
		$this->finish_request();

		$page = $this->post_verification( $post_id, $token, 'verify-code', 'legal@example.com', '000000' );

		// Reloading this page would re-post the wrong code and spend a guess.
		static::assertStringNotContainsString( 'reload', $page );
		static::assertStringContainsString( 'value="request-code"', $page );
		static::assertStringContainsString( 'Use a different email address', $page );
		static::assertStringNotContainsString( 'id="_wpnonce"', $page, 'Two forms must not share an ID.' );

		$this->post_verification( $post_id, $token, 'request-code', 'legal@example.com' );
		$this->finish_request();

		$mailer = tests_retrieve_phpmailer_instance();
		static::assertInstanceOf( MockPHPMailer::class, $mailer );
		static::assertCount( 2, $mailer->mock_sent );
		static::assertSame( 1, preg_match( '/\b([0-9]{6})\b/', $mailer->mock_sent[1]['body'], $matches ) );
		static::assertTrue( ( new RecipientVerifier() )->verify_code( $token, 'legal@example.com', $matches[1] ) );
	}

	public function test_the_first_code_page_offers_a_new_code_and_a_way_back(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$page = $this->post_verification( $post_id, $token, 'request-code', 'legal@example.com' );

		// The "it never arrived" path: no error yet, but a typo is visible and
		// both ways out are there.
		static::assertStringContainsString( '<strong>legal@example.com</strong>', $page );
		static::assertStringContainsString( 'value="request-code"', $page );
		static::assertMatchesRegularExpression( '/<a href="[^"]*' . preg_quote( $token->value(), '/' ) . '[^"]*">Use a different email address/', $page );
	}

	public function test_the_code_page_does_not_reveal_whether_an_address_is_listed(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$listed   = $this->post_verification( $post_id, $token, 'request-code', 'legal@example.com' );
		$unlisted = $this->post_verification( $post_id, $token, 'request-code', 'stranger@example.com' );

		static::assertSame( $listed, str_replace( 'stranger@example.com', 'legal@example.com', $unlisted ) );
	}

	public function test_a_bad_nonce_shows_the_email_form_and_sends_no_mail(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$page = $this->post_verification( $post_id, $token, 'request-code', 'legal@example.com', '', 'forged' );
		$this->finish_request();

		// Back to step one: the email form, not the code form.
		static::assertStringContainsString( 'type="email" name="shareadraft-email"', $page );
		static::assertStringNotContainsString( 'name="shareadraft-code"', $page );
		static::assertCount( 0, $this->sent_mail() );
	}

	public function test_an_unlisted_address_is_sent_no_code(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$this->post_verification( $post_id, $token, 'request-code', 'stranger@example.com' );
		$this->finish_request();

		static::assertCount( 0, $this->sent_mail() );
	}

	public function test_the_right_code_remembers_the_reviewer_and_redirects_back(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$this->post_verification( $post_id, $token, 'request-code', 'legal@example.com' );
		$this->finish_request();
		static::assertSame( 1, preg_match( '/\b([0-9]{6})\b/', $this->sent_mail()[0], $matches ) );

		$redirect = null;
		$throw    = static function ( string $location, int $status ) use ( &$redirect ): never {
			$redirect = [ $location, $status ];
			throw new \RuntimeException( 'Redirected.' );
		};
		// @phpstan-ignore return.missing (Throwing is the point: it stops the request before the exit that follows the redirect.)
		add_filter( 'wp_redirect', $throw, 10, 2 );

		try {
			$this->post_verification( $post_id, $token, 'verify-code', 'legal@example.com', $matches[1] );
		} catch ( \RuntimeException $e ) {
			// Expected: the redirect is where the request ends.
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $throw, 10 );
		}

		static::assertNotNull( $redirect, 'A correct code redirects.' );
		static::assertSame( 303, $redirect[1] );
		static::assertStringContainsString( $token->value(), $redirect[0] );
		static::assertSame( 'legal@example.com', ( new RecipientVerifier() )->verified_email( $token ) );
	}

	public function test_a_new_code_is_not_sent_once_the_guesses_are_spent(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'legal@example.com' ] );

		$this->post_verification( $post_id, $token, 'request-code', 'legal@example.com' );
		$this->finish_request();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->post_verification( $post_id, $token, 'verify-code', 'legal@example.com', '000000' );
		}

		// The button stays (hiding it would reveal the address is listed), but
		// a code that could not be redeemed is not worth sending.
		$this->post_verification( $post_id, $token, 'request-code', 'legal@example.com' );
		$this->finish_request();

		$mailer = tests_retrieve_phpmailer_instance();
		static::assertInstanceOf( MockPHPMailer::class, $mailer );
		static::assertCount( 1, $mailer->mock_sent );
	}

	public function test_a_password_form_sends_a_token_holder_back_to_the_preview(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'draft',
				'post_password' => 'secret',
			]
		);
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$form = $this->password_form( $post_id, $token );

		$expected = get_preview_post_link(
			$post_id,
			[
				PreviewGate::TOKEN_QUERY_VAR => $token->value(),
				'shareadraft-postpass'       => '1',
			]
		);
		static::assertIsString( $expected );
		static::assertStringContainsString( 'name="redirect_to" value="' . esc_attr( $expected ) . '"', $form );
		static::assertStringNotContainsString( 'role="alert"', $form );
	}

	public function test_a_rejected_password_is_announced(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'draft',
				'post_password' => 'secret',
			]
		);
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// What core's postpass handler leaves behind after any attempt.
		$_GET['shareadraft-postpass']           = '1';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = 'hash-of-the-wrong-password';

		$form = $this->password_form( $post_id, $token );

		static::assertStringContainsString( 'role="alert"', $form );
		static::assertStringContainsString( 'aria-describedby="error-pwbox-' . $post_id . '"', $form );
		static::assertStringContainsString( 'password-form-error', $form );
	}

	public function test_a_password_form_for_a_locked_draft_is_untouched(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'draft',
				'post_password' => 'secret',
			]
		);
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$core = get_the_password_form( $post_id );

		static::assertSame( $core, $this->password_form( $post_id, Token::from_string( 'not-the-token' ) ) );
	}

	public function test_page_break_links_keep_the_token(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'draft',
				'post_content' => 'Page one<!--nextpage-->Page two',
			]
		);
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$gate = $this->denied_main_query( $post_id, $token );
		add_filter( 'preview_post_link', [ $gate, 'keep_token_in_preview_links' ], 10, 2 );
		// How core's _wp_link_page() builds the link to page two of a draft.
		$url = get_preview_post_link( $post_id, [], add_query_arg( 'page', 2, (string) get_permalink( $post_id ) ) );
		remove_filter( 'preview_post_link', [ $gate, 'keep_token_in_preview_links' ], 10 );

		static::assertIsString( $url );
		static::assertStringContainsString( 'page=2', $url );
		static::assertStringContainsString( PreviewGate::TOKEN_QUERY_VAR . '=' . $token->value(), $url );
	}

	public function test_an_unlocked_draft_reads_as_a_draft_once_the_query_has_let_it_through(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_name'   => '',
			]
		);
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();
		clean_post_cache( $post_id );

		$gate  = new PreviewGate( $this->service, new RecipientVerifier() );
		$posts = $gate->restore_unlocked_statuses( $gate->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() ) );

		static::assertSame( 'draft', self::first_status( $posts ) );

		// Read as published, a slugless draft's permalink is the home page, so
		// page-break links pointed at /2/ rather than at the draft.
		static::assertIsArray( $posts );
		static::assertInstanceOf( WP_Post::class, $posts[0] );
		static::assertStringContainsString( 'p=' . $post_id, (string) get_permalink( $posts[0] ) );
	}

	public function test_a_real_preview_request_serves_the_draft_without_caching_it_as_published(): void {
		wp_set_current_user( 0 );

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$token   = $this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		// Start uncached, so whatever the cache holds afterwards was written
		// during this request.
		clean_post_cache( $post_id );

		// Through the plugin's own registered gate, in core's real filter order.
		$url = add_query_arg(
			[
				'p'                          => $post_id,
				'preview'                    => 'true',
				PreviewGate::TOKEN_QUERY_VAR => $token->value(),
			],
			home_url( '/' )
		);
		$this->go_to( $url );

		// Served to a logged-out visitor, yet still a draft to everything after.
		$served = get_queried_object();
		static::assertInstanceOf( WP_Post::class, $served );
		static::assertSame( $post_id, $served->ID );
		static::assertSame( 'draft', $served->post_status );
		static::assertSame( 'draft', get_post_status( $post_id ) );

		$cached = wp_cache_get( (string) $post_id, 'posts' );
		static::assertIsObject( $cached );
		static::assertSame( 'draft', get_object_vars( $cached )['post_status'] ?? null );
	}

	public function test_preview_links_for_a_locked_draft_carry_no_token(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$gate = $this->denied_main_query( $post_id, Token::from_string( 'not-the-token' ) );
		add_filter( 'preview_post_link', [ $gate, 'keep_token_in_preview_links' ], 10, 2 );
		$url = get_preview_post_link( $post_id );
		remove_filter( 'preview_post_link', [ $gate, 'keep_token_in_preview_links' ], 10 );

		static::assertIsString( $url );
		static::assertStringNotContainsString( PreviewGate::TOKEN_QUERY_VAR, $url );
	}

	/**
	 * Visit the draft with a token, then render its password form through the gate.
	 */
	private function password_form( int $post_id, Token $token ): string {
		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();
		clean_post_cache( $post_id );

		$gate = new PreviewGate( $this->service, new RecipientVerifier() );
		$gate->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		add_filter( 'the_password_form', [ $gate, 'keep_token_in_password_form' ], 10, 2 );
		$form = get_the_password_form( $post_id );
		remove_filter( 'the_password_form', [ $gate, 'keep_token_in_password_form' ], 10 );

		return $form;
	}

	/**
	 * Run the gate over a main-query preview and hand back the gate so the caller
	 * can assert on the notice it would render.
	 */
	private function denied_main_query( int $post_id, Token $token ): PreviewGate {
		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();
		clean_post_cache( $post_id );

		$gate = new PreviewGate( $this->service, new RecipientVerifier() );
		$gate->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		return $gate;
	}

	/**
	 * End the simulated request: run the deferred code send, then drop it so
	 * the next simulated request does not send it again.
	 */
	/**
	 * The bodies of the mail sent so far in this test.
	 *
	 * @return list<string>
	 */
	private function sent_mail(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		static::assertInstanceOf( MockPHPMailer::class, $mailer );

		return array_column( $mailer->mock_sent, 'body' );
	}

	private function finish_request(): void {
		do_action( 'shutdown' );
		remove_all_actions( 'shutdown' );
	}

	/**
	 * Post one step of the email-verification interstitial, as the visitor's
	 * browser would, and return the page the gate renders in reply.
	 */
	private function post_verification( int $post_id, Token $token, string $action, string $email, string $code = '', ?string $nonce = null ): string {
		// Core's own shutdown work (flushing output buffers) has no place in
		// a test; only the gate's deferred send should run in finish_request().
		remove_all_actions( 'shutdown' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = add_query_arg( PreviewGate::TOKEN_QUERY_VAR, $token->value(), '/?p=' . $post_id );
		$_POST                     = [
			'_wpnonce'                  => $nonce ?? wp_create_nonce( 'shareadraft_verify' ),
			'shareadraft-verify-action' => $action,
			'shareadraft-email'         => $email,
			'shareadraft-code'          => $code,
		];

		try {
			$this->denied_main_query( $post_id, $token )->maybe_render_notice();
		} catch ( \WPDieException $page ) {
			static::assertSame( 403, $page->getCode(), 'Each verification step withholds the draft.' );
			return $page->getMessage();
		}

		static::fail( 'The gate rendered no page.' );
	}

	/**
	 * Simulate one request against the gate and return the post's resulting status.
	 *
	 * @param bool $fresh_browser Clear the cookie jar first, i.e. a new visitor.
	 */
	/**
	 * The slot cookie the gate last handed this browser for a link, or ''.
	 */
	private function issued_slot( Token $token ): string {
		$cookie = 'shareadraft_viewer_' . substr( $token->hash(), 0, 20 );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading back the value the gate itself just set, in a test.
		return isset( $_COOKIE[ $cookie ] ) && is_string( $_COOKIE[ $cookie ] ) ? $_COOKIE[ $cookie ] : '';
	}

	private function visit( int $post_id, Token $token, bool $fresh_browser = false ): string {
		if ( $fresh_browser ) {
			$_COOKIE = [];
		}

		$_GET[ PreviewGate::TOKEN_QUERY_VAR ] = $token->value();

		// The gate mutates the in-memory WP_Post; reload from cache-cleared state
		// so each simulated request starts from the real (draft) status.
		clean_post_cache( $post_id );

		$posts = ( new PreviewGate( $this->service, new RecipientVerifier() ) )
			->unlock_valid_previews( [ get_post( $post_id ) ], $this->preview_query() );

		return self::first_status( $posts );
	}

	/**
	 * The status of the first post the_posts filter handed back.
	 *
	 * @param mixed $posts The filter's return value.
	 */
	private static function first_status( $posts ): string {
		static::assertIsArray( $posts );

		$first = $posts[0] ?? null;
		static::assertInstanceOf( WP_Post::class, $first );

		return $first->post_status;
	}

	private function preview_query(): WP_Query {
		$query             = new WP_Query();
		$query->is_preview = true;

		return $query;
	}
}
