<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests;

use Automattic\ShareADraft\PreviewLink;
use Automattic\ShareADraft\Token;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Automattic\ShareADraft\PreviewLink
 */
final class PreviewLinkTest extends TestCase {
	public function test_issue_stores_the_token_hash_not_the_plaintext(): void {
		$token = Token::from_string( 'secret-value' );

		$link = PreviewLink::issue( 13, $token, 2000, null, 1, 1000 );

		self::assertSame( $token->hash(), $link->token_hash() );
		self::assertStringNotContainsString( 'secret-value', $link->token_hash() );
	}

	public function test_matches_only_the_issuing_token(): void {
		$link = PreviewLink::issue( 13, Token::from_string( 'right' ), 2000, null, 1, 1000 );

		self::assertTrue( $link->matches( Token::from_string( 'right' ) ) );
		self::assertFalse( $link->matches( Token::from_string( 'wrong' ) ) );
	}

	public function test_expiry_is_inclusive_of_the_expiry_second(): void {
		$link = PreviewLink::issue( 13, Token::generate(), 2000, null, 1, 1000 );

		self::assertFalse( $link->is_expired( 1999 ) );
		self::assertTrue( $link->is_expired( 2000 ), 'A link is expired at its expiry second.' );
		self::assertTrue( $link->is_expired( 2001 ) );
	}

	public function test_a_fresh_link_is_neither_exhausted_nor_revoked(): void {
		$link = PreviewLink::issue( 13, Token::generate(), 2000, 5, 1, 1000 );

		self::assertSame( 0, $link->use_count() );
		self::assertFalse( $link->is_exhausted() );
		self::assertFalse( $link->is_revoked() );
	}

	public function test_the_creator_is_known_only_when_a_user_was_recorded(): void {
		$by_user = new PreviewLink( 13, 'hash', 2000, null, 7, 1000 );
		$by_none = new PreviewLink( 13, 'hash', 2000, null, 0, 1000 );

		self::assertTrue( $by_user->has_known_creator() );
		self::assertFalse( $by_none->has_known_creator() );
	}

	public function test_ip_restriction_reflects_the_links_own_ranges(): void {
		$restricted = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, 0, null, '', [ '203.0.113.0/24' ] );
		$open       = new PreviewLink( 13, 'hash', 2000, null, 1, 1000 );

		self::assertTrue( $restricted->has_ip_restriction() );
		self::assertFalse( $open->has_ip_restriction() );
	}

	public function test_a_link_is_identified_by_its_hash_or_hint_but_never_blank(): void {
		$link = new PreviewLink( 13, 'full-hash', 2000, null, 1, 1000, 0, null, 'ab3f' );

		self::assertTrue( $link->is_identified_by( 'full-hash' ) );
		self::assertTrue( $link->is_identified_by( 'ab3f' ) );
		self::assertFalse( $link->is_identified_by( 'b3f' ), 'A partial hint must not match.' );
		self::assertFalse( $link->is_identified_by( '' ), 'A blank identifier must never match.' );
	}

	public function test_an_unlimited_link_is_never_exhausted(): void {
		$link = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, 9999 );

		self::assertFalse( $link->is_exhausted() );
	}

	public function test_a_link_is_exhausted_once_slots_reach_the_cap(): void {
		$at_cap = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 5 );
		$below  = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 4 );

		self::assertTrue( $at_cap->is_exhausted() );
		self::assertFalse( $below->is_exhausted() );
	}

	public function test_spending_a_slot_counts_on_a_copy(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 2 );

		$next = $link->with_use();

		self::assertSame( 2, $link->use_count(), 'Original is unchanged.' );
		self::assertSame( 3, $next->use_count() );
	}

	public function test_dead_since_reports_when_a_link_stopped_working(): void {
		$live    = new PreviewLink( 13, 'hash', 2000, null, 1, 1000 );
		$expired = new PreviewLink( 13, 'hash', 2000, null, 1, 1000 );
		$revoked = new PreviewLink( 13, 'hash', 9000, null, 1, 1000, 0, 1500 );

		self::assertNull( $live->dead_since( 1999 ) );
		self::assertSame( 2000, $expired->dead_since( 2500 ) );
		self::assertSame( 1500, $revoked->dead_since( 2500 ) );
	}

	public function test_issue_captures_the_token_tail_as_a_hint(): void {
		$link = PreviewLink::issue( 13, Token::from_string( 'abcdefgh0000wxyz' ), 2000, null, 1, 1000 );

		self::assertSame( 'wxyz', $link->token_hint() );
	}

	public function test_revoking_stamps_a_copy(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 2 );

		$revoked = $link->with_revoked( 1500 );

		self::assertFalse( $link->is_revoked(), 'Original is unchanged.' );
		self::assertTrue( $revoked->is_revoked() );
		self::assertSame( 1500, $revoked->revoked_at() );
		self::assertSame( 2, $revoked->use_count(), 'Other fields are preserved.' );
	}

	public function test_recipient_matching_is_case_insensitive(): void {
		$link = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, 0, null, '', [], [ 'legal@example.com' ] );

		self::assertTrue( $link->is_recipient( 'legal@example.com' ) );
		self::assertTrue( $link->is_recipient( 'Legal@Example.COM' ) );
		self::assertFalse( $link->is_recipient( 'stranger@example.com' ) );
		self::assertFalse( $link->is_recipient( '' ), 'An empty address never matches.' );
	}

	public function test_a_bearer_link_has_no_recipients(): void {
		$link = PreviewLink::issue( 13, Token::generate(), 2000, null, 1, 1000 );

		self::assertSame( [], $link->recipients() );
		self::assertFalse( $link->is_recipient( 'anyone@example.com' ) );
	}

	public function test_copies_preserve_recipients(): void {
		$link = new PreviewLink( 13, 'hash', 2000, 5, 1, 1000, 0, null, '', [], [ 'legal@example.com' ] );

		self::assertSame( [ 'legal@example.com' ], $link->with_use()->recipients() );
		self::assertSame( [ 'legal@example.com' ], $link->with_revoked( 1500 )->recipients() );
	}

	public function test_forgetting_one_of_several_recipients_keeps_the_link_live(): void {
		$link = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, 0, null, '', [], [ 'bob@example.com', 'amy@example.com' ] );

		$forgotten = $link->without_recipient( 'BOB@example.com', 1500 );

		self::assertSame( [ 'amy@example.com' ], $forgotten->recipients() );
		self::assertFalse( $forgotten->is_revoked() );
	}

	public function test_forgetting_the_last_recipient_revokes_rather_than_opening_the_link(): void {
		$link = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, 0, null, '', [], [ 'bob@example.com' ] );

		$forgotten = $link->without_recipient( 'bob@example.com', 1500 );

		self::assertSame( [], $forgotten->recipients() );
		self::assertSame( 1500, $forgotten->revoked_at(), 'An empty list means a bearer link; erasure must not widen access.' );
	}

	public function test_forgetting_the_last_recipient_keeps_an_earlier_revocation(): void {
		$link = new PreviewLink( 13, 'hash', 2000, null, 1, 1000, 0, 1200, '', [], [ 'bob@example.com' ] );

		self::assertSame( 1200, $link->without_recipient( 'bob@example.com', 1500 )->revoked_at() );
	}

	public function test_forgetting_a_stranger_leaves_the_link_untouched(): void {
		$bearer = new PreviewLink( 13, 'hash', 2000, null, 1, 1000 );

		self::assertSame( $bearer, $bearer->without_recipient( 'bob@example.com', 1500 ) );
	}
}
