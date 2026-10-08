<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests;

use Automattic\ShareADraft\AccessDecision;
use Automattic\ShareADraft\AccessPolicy;
use Automattic\ShareADraft\PreviewLinkService;
use Automattic\ShareADraft\Token;
use Automattic\ShareADraft\Tests\Support\FrozenClock;
use Automattic\ShareADraft\Tests\Support\InMemoryTokenRepository;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips through the application service against an in-memory repository and
 * a frozen clock: mint a link, then prove who may and may not use it and when.
 *
 * @covers \Automattic\ShareADraft\PreviewLinkService
 */
final class PreviewLinkServiceTest extends TestCase {
	private const NOW     = 1000;
	private const POST_ID = 13;

	private InMemoryTokenRepository $repository;
	private FrozenClock $clock;
	private PreviewLinkService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new InMemoryTokenRepository();
		$this->clock      = new FrozenClock( self::NOW );
		$this->service    = new PreviewLinkService( $this->repository, new AccessPolicy(), $this->clock );
	}

	public function test_a_minted_token_authorizes_its_post(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
	}

	public function test_the_minted_secret_is_not_persisted_in_plaintext(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		$stored = $this->repository->all_for_post( self::POST_ID );

		self::assertCount( 1, $stored );
		self::assertNotSame( $token->value(), $stored[0]->token_hash() );
		self::assertSame( $token->hash(), $stored[0]->token_hash() );
	}

	public function test_a_link_that_could_not_be_saved_is_never_handed_out(): void {
		$this->repository->fail_saves();

		$this->expectException( \RuntimeException::class );

		$this->service->mint( self::POST_ID, 3600, null, 1 );
	}

	public function test_an_unknown_token_is_denied(): void {
		$this->service->mint( self::POST_ID, 3600, null, 1 );

		$decision = $this->service->authorize( self::POST_ID, Token::from_string( 'not-the-token' ) );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_NOT_FOUND, $decision->reason() );
	}

	public function test_minted_ip_ranges_are_persisted_and_enforced(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1, [ '203.0.113.0/24' ] );

		$stored = $this->repository->all_for_post( self::POST_ID );
		self::assertSame( [ '203.0.113.0/24' ], $stored[0]->allowed_ips() );

		self::assertTrue( $this->service->authorize( self::POST_ID, $token, false, '203.0.113.7' )->is_allowed() );

		$decision = $this->service->authorize( self::POST_ID, $token, false, '198.51.100.7' );
		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_IP_BLOCKED, $decision->reason() );
	}

	public function test_minted_recipients_are_persisted_and_enforced(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1, [], [ 'legal@example.com' ] );

		$stored = $this->repository->all_for_post( self::POST_ID );
		self::assertSame( [ 'legal@example.com' ], $stored[0]->recipients() );

		$unverified = $this->service->authorize( self::POST_ID, $token );
		self::assertFalse( $unverified->is_allowed() );
		self::assertSame( AccessDecision::REASON_EMAIL_UNVERIFIED, $unverified->reason() );

		self::assertTrue(
			$this->service->authorize( self::POST_ID, $token, false, null, 'legal@example.com' )->is_allowed()
		);
	}

	public function test_a_slot_cannot_be_claimed_without_a_verified_recipient(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 5, 1, [], [ 'legal@example.com' ] );

		self::assertFalse( $this->service->claim_slot( self::POST_ID, $token ) );
		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token, null, 'legal@example.com' ) );
	}

	public function test_is_recipient_only_answers_for_live_links(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1, [], [ 'legal@example.com' ] );

		self::assertTrue( $this->service->is_recipient( self::POST_ID, $token, 'legal@example.com' ) );
		self::assertFalse( $this->service->is_recipient( self::POST_ID, $token, 'stranger@example.com' ) );

		// Once the link dies there is nothing left to verify for, so no code
		// should ever be sent on its behalf.
		$this->clock->advance( 3600 );
		self::assertFalse( $this->service->is_recipient( self::POST_ID, $token, 'legal@example.com' ) );
	}

	public function test_a_slot_cannot_be_claimed_from_a_blocked_ip(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 5, 1, [ '203.0.113.0/24' ] );

		self::assertFalse( $this->service->claim_slot( self::POST_ID, $token, '198.51.100.7' ) );
		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token, '203.0.113.7' ) );
	}

	public function test_a_token_does_not_authorize_a_different_post(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		self::assertFalse( $this->service->authorize( 99, $token )->is_allowed() );
	}

	public function test_a_link_stops_working_once_its_ttl_elapses(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );

		$this->clock->advance( 3600 );

		$decision = $this->service->authorize( self::POST_ID, $token );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_EXPIRED, $decision->reason() );
	}

	public function test_authorize_does_not_consume_the_link(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 1, 1 );

		// Two decisions in the same instant: a pure query must not burn the link.
		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
	}

	public function test_claiming_slots_exhausts_a_capped_link(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 2, 1 );

		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token ) );
		self::assertTrue( $this->service->authorize( self::POST_ID, $token )->is_allowed() );

		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token ) );
		$decision = $this->service->authorize( self::POST_ID, $token );

		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_EXHAUSTED, $decision->reason() );
	}

	public function test_a_slot_holder_still_gets_in_after_exhaustion(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 1, 1 );

		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token ) );
		self::assertFalse( $this->service->authorize( self::POST_ID, $token )->is_allowed() );
		self::assertTrue( $this->service->authorize( self::POST_ID, $token, true )->is_allowed() );
	}

	public function test_a_slot_cannot_be_claimed_once_the_cap_is_spent(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 1, 1 );

		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token ) );
		self::assertFalse( $this->service->claim_slot( self::POST_ID, $token ) );
	}

	public function test_a_slot_cannot_be_claimed_on_a_revoked_link(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );
		$this->service->revoke( self::POST_ID, $this->repository->all_for_post( self::POST_ID )[0]->token_hash() );

		self::assertFalse( $this->service->claim_slot( self::POST_ID, $token ) );
	}

	public function test_a_concurrent_claim_cannot_overspend_the_cap(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 1, 1 );

		// Simulate a competing request taking the only slot in the window between
		// this claim reading the link and writing it back. The write must lose,
		// and the retry must then find the link exhausted rather than overwrite.
		$this->repository->on_next_add_use(
			function () use ( $token ): void {
				$link = $this->repository->find( self::POST_ID, $token );
				self::assertNotNull( $link );
				$this->repository->add_use( $link );
			}
		);

		self::assertFalse( $this->service->claim_slot( self::POST_ID, $token ) );
		self::assertSame( 1, $this->repository->all_for_post( self::POST_ID )[0]->use_count() );
	}

	public function test_a_claim_that_loses_a_race_retries_when_a_slot_remains(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 5, 1 );

		$this->repository->on_next_add_use(
			function () use ( $token ): void {
				$link = $this->repository->find( self::POST_ID, $token );
				self::assertNotNull( $link );
				$this->repository->add_use( $link );
			}
		);

		// Losing one race is not a denial while the cap has room left.
		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token ) );
		self::assertSame( 2, $this->repository->all_for_post( self::POST_ID )[0]->use_count() );
	}

	public function test_a_capped_claim_that_loses_every_race_is_denied(): void {
		$token = $this->service->mint( self::POST_ID, 3600, 100, 1 );
		$this->compete_on_every_claim( $token );

		// Room is left, but the claim never lands, so letting the visitor in
		// would be an uncounted view of a capped link.
		self::assertFalse( $this->service->claim_slot( self::POST_ID, $token ) );
	}

	public function test_an_uncapped_claim_that_loses_every_race_is_still_admitted(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );
		$this->compete_on_every_claim( $token );

		// No cap means no last slot to protect, so a busy link must not turn
		// visitors away; only the count misses them.
		self::assertTrue( $this->service->claim_slot( self::POST_ID, $token ) );
	}

	public function test_pruning_removes_only_links_dead_beyond_the_grace_period(): void {
		$live = $this->service->mint( self::POST_ID, 3600, null, 1 );
		$this->service->mint( self::POST_ID, 10, null, 1 );

		// Long enough that the short link died well outside a one-hour grace.
		$this->clock->advance( 2 * 3600 );

		self::assertSame( 1, $this->service->prune_dead( self::POST_ID, 3600 ) );

		$remaining = $this->repository->all_for_post( self::POST_ID );
		self::assertCount( 1, $remaining );
		self::assertTrue( $remaining[0]->matches( $live ) );
	}

	public function test_pruning_keeps_a_link_that_died_inside_the_grace_period(): void {
		$this->service->mint( self::POST_ID, 10, null, 1 );
		$this->clock->advance( 20 );

		self::assertSame( 0, $this->service->prune_dead( self::POST_ID, 86400 ) );
		self::assertCount( 1, $this->repository->all_for_post( self::POST_ID ) );
	}

	public function test_revoking_a_link_denies_it(): void {
		$token = $this->service->mint( self::POST_ID, 3600, null, 1 );
		$hash  = $this->repository->all_for_post( self::POST_ID )[0]->token_hash();

		self::assertTrue( $this->service->revoke( self::POST_ID, $hash ) );

		$decision = $this->service->authorize( self::POST_ID, $token );
		self::assertFalse( $decision->is_allowed() );
		self::assertSame( AccessDecision::REASON_REVOKED, $decision->reason() );
	}

	public function test_revoking_is_reported_false_when_nothing_matches(): void {
		self::assertFalse( $this->service->revoke( self::POST_ID, str_repeat( 'a', 64 ) ) );
	}

	public function test_revoking_an_already_revoked_link_is_reported_false(): void {
		$this->service->mint( self::POST_ID, 3600, null, 1 );
		$hash = $this->repository->all_for_post( self::POST_ID )[0]->token_hash();

		self::assertTrue( $this->service->revoke( self::POST_ID, $hash ) );
		self::assertFalse( $this->service->revoke( self::POST_ID, $hash ) );
	}

	public function test_minting_keeps_expired_links(): void {
		// An expired link is deliberately retained so the gate can still explain
		// it. Cleanup happens on publish, not on every mint.
		$this->service->mint( self::POST_ID, 10, null, 1 );
		$this->clock->advance( 20 );

		$this->service->mint( self::POST_ID, 3600, null, 1 );

		self::assertCount( 2, $this->repository->all_for_post( self::POST_ID ) );
	}

	public function test_discard_all_forgets_every_link(): void {
		$this->service->mint( self::POST_ID, 3600, null, 1 );
		$this->service->mint( self::POST_ID, 3600, null, 1 );

		$this->service->discard_all( self::POST_ID );

		self::assertCount( 0, $this->repository->all_for_post( self::POST_ID ) );
	}

	/**
	 * Land a competing claim just before every claim this test makes, so each
	 * compare-and-swap loses.
	 */
	private function compete_on_every_claim( Token $token ): void {
		$compete = function () use ( $token, &$compete ): void {
			$link = $this->repository->find( self::POST_ID, $token );
			self::assertNotNull( $link );
			$this->repository->add_use( $link );
			$this->repository->on_next_add_use( $compete );
		};

		$this->repository->on_next_add_use( $compete );
	}
}
