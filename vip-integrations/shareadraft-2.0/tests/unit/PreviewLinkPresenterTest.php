<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests;

use Automattic\ShareADraft\PreviewLink;
use Automattic\ShareADraft\PreviewLinkPresenter;
use Automattic\ShareADraft\Token;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Automattic\ShareADraft\PreviewLinkPresenter
 */
final class PreviewLinkPresenterTest extends TestCase {
	private const NOW = 1000;

	public function test_presents_a_live_link_with_the_expected_shape(): void {
		$link = PreviewLink::issue( 7, Token::from_string( 'abcd1234' ), self::NOW + 100, 5, 1, self::NOW - 50, [ '203.0.113.0/24' ], [ 'legal@example.com' ] );

		$presented = PreviewLinkPresenter::present_live_links( [ $link ], self::NOW );

		self::assertCount( 1, $presented );
		self::assertSame(
			[ 'id', 'token_hint', 'created_at', 'expires_at', 'max_uses', 'use_count', 'exhausted', 'allowed_ips', 'recipients' ],
			array_keys( $presented[0] )
		);
		self::assertSame( $link->token_hash(), $presented[0]['id'] );
		self::assertSame( '1234', $presented[0]['token_hint'] );
		self::assertSame( self::NOW - 50, $presented[0]['created_at'] );
		self::assertSame( self::NOW + 100, $presented[0]['expires_at'] );
		self::assertSame( 5, $presented[0]['max_uses'] );
		self::assertSame( 0, $presented[0]['use_count'] );
		self::assertFalse( $presented[0]['exhausted'] );
		self::assertSame( [ '203.0.113.0/24' ], $presented[0]['allowed_ips'] );
		self::assertSame( [ 'legal@example.com' ], $presented[0]['recipients'] );
	}

	public function test_excludes_expired_and_revoked_links(): void {
		$live    = PreviewLink::issue( 7, Token::from_string( 'live' ), self::NOW + 100, null, 1, self::NOW );
		$expired = PreviewLink::issue( 7, Token::from_string( 'oldx' ), self::NOW - 1, null, 1, self::NOW - 500 );
		$revoked = PreviewLink::issue( 7, Token::from_string( 'gone' ), self::NOW + 100, null, 1, self::NOW )
			->with_revoked( self::NOW - 1 );

		$presented = PreviewLinkPresenter::present_live_links( [ $live, $expired, $revoked ], self::NOW );

		self::assertCount( 1, $presented );
		self::assertSame( $live->token_hash(), $presented[0]['id'] );
	}

	public function test_never_exposes_the_token_plaintext(): void {
		$link = PreviewLink::issue( 7, Token::from_string( 'super-secret-token' ), self::NOW + 100, null, 1, self::NOW );

		$presented = PreviewLinkPresenter::present_live_links( [ $link ], self::NOW );

		// The only string fields are the hash and a short hint; neither is the
		// token itself.
		self::assertStringNotContainsString( 'super-secret-token', $presented[0]['id'] );
		self::assertNotSame( 'super-secret-token', $presented[0]['token_hint'] );
	}

	public function test_no_links_yields_an_empty_list(): void {
		self::assertSame( [], PreviewLinkPresenter::present_live_links( [], self::NOW ) );
	}
}
