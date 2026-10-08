<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests;

use Automattic\ShareADraft\Token;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Automattic\ShareADraft\Token
 */
final class TokenTest extends TestCase {
	public function test_generated_tokens_are_64_hex_characters(): void {
		$value = Token::generate()->value();

		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $value );
	}

	public function test_generated_tokens_are_unique(): void {
		self::assertNotSame( Token::generate()->value(), Token::generate()->value() );
	}

	public function test_hash_is_stable_for_the_same_value(): void {
		$token = Token::from_string( 'abc123' );

		self::assertSame( $token->hash(), Token::from_string( 'abc123' )->hash() );
	}

	public function test_hash_differs_from_the_plaintext(): void {
		$token = Token::from_string( 'abc123' );

		self::assertNotSame( $token->value(), $token->hash() );
	}

	public function test_different_values_hash_differently(): void {
		self::assertNotSame(
			Token::from_string( 'one' )->hash(),
			Token::from_string( 'two' )->hash()
		);
	}
}
