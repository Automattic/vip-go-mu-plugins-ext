<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use MockPHPMailer;
use WP_UnitTestCase;

/**
 * The emailed-code round trip: request a code, redeem it once, and prove the
 * guessing and re-request budgets hold.
 *
 * @covers \Automattic\ShareADraft\RecipientVerifier
 */
class RecipientVerifierTest extends WP_UnitTestCase {
	private const EMAIL = 'legal@example.com';

	private RecipientVerifier $verifier;
	private Token $token;
	private bool $ext_object_cache;

	public function set_up(): void {
		parent::set_up();

		$this->ext_object_cache = (bool) wp_using_ext_object_cache();

		$this->verifier = new RecipientVerifier();
		$this->token    = Token::generate();

		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		$_COOKIE = [];
		wp_using_ext_object_cache( $this->ext_object_cache );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_a_sent_code_verifies_once_and_only_once(): void {
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );

		$code = $this->sent_code();

		static::assertTrue( $this->verifier->verify_code( $this->token, self::EMAIL, $code ) );
		static::assertFalse(
			$this->verifier->verify_code( $this->token, self::EMAIL, $code ),
			'A redeemed code is void; replaying it must fail.'
		);
	}

	public function test_a_wrong_code_fails_without_voiding_the_right_one(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		static::assertFalse( $this->verifier->verify_code( $this->token, self::EMAIL, '000000' ) );
		static::assertTrue( $this->verifier->verify_code( $this->token, self::EMAIL, $this->sent_code() ) );
	}

	public function test_a_failed_send_keeps_the_earlier_code(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );
		$code = $this->sent_code();

		add_filter( 'pre_wp_mail', '__return_false' );

		static::assertFalse( $this->verifier->send_code( $this->token, self::EMAIL ) );
		static::assertTrue(
			$this->verifier->verify_code( $this->token, self::EMAIL, $code ),
			'A code that never left must not replace one already in the inbox.'
		);
	}

	/**
	 * @dataProvider data_counter_stores
	 */
	public function test_guessing_is_bounded( bool $object_cache ): void {
		wp_using_ext_object_cache( $object_cache );
		$this->verifier->send_code( $this->token, self::EMAIL );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			static::assertFalse( $this->verifier->verify_code( $this->token, self::EMAIL, 'wrong' . $attempt ) );
		}

		static::assertFalse(
			$this->verifier->verify_code( $this->token, self::EMAIL, $this->sent_code() ),
			'Once the attempt budget is spent, even the right code is refused.'
		);
	}

	/**
	 * @dataProvider data_counter_stores
	 */
	public function test_a_new_code_does_not_bring_new_guesses( bool $object_cache ): void {
		wp_using_ext_object_cache( $object_cache );
		$this->verifier->send_code( $this->token, self::EMAIL );

		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$this->verifier->verify_code( $this->token, self::EMAIL, 'wrong' . $attempt );
		}

		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );
		static::assertFalse( $this->verifier->verify_code( $this->token, self::EMAIL, 'wrong' ) );

		static::assertFalse(
			$this->verifier->verify_code( $this->token, self::EMAIL, $this->sent_code() ),
			'Guesses are counted per address across codes, so re-requesting cannot reset the budget.'
		);
	}

	/**
	 * @dataProvider data_counter_stores
	 */
	public function test_no_code_is_sent_once_the_guesses_are_spent( bool $object_cache ): void {
		wp_using_ext_object_cache( $object_cache );
		$this->verifier->send_code( $this->token, self::EMAIL );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$this->verifier->verify_code( $this->token, self::EMAIL, 'wrong' . $attempt );
		}

		static::assertFalse(
			$this->verifier->send_code( $this->token, self::EMAIL ),
			'A code that could not be redeemed is not worth emailing.'
		);
		static::assertCount( 1, self::mailer()->mock_sent );
	}

	/**
	 * @dataProvider data_counter_stores
	 */
	public function test_code_requests_are_rate_limited_per_address( bool $object_cache ): void {
		wp_using_ext_object_cache( $object_cache );
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );
		static::assertTrue( $this->verifier->send_code( $this->token, self::EMAIL ) );

		static::assertFalse(
			$this->verifier->send_code( $this->token, self::EMAIL ),
			'A fourth request inside the window sends nothing.'
		);
	}

	public function test_a_code_is_scoped_to_its_address(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		static::assertFalse(
			$this->verifier->verify_code( $this->token, 'other@example.com', $this->sent_code() ),
			'A code emailed to one address proves nothing about another.'
		);
	}

	public function test_a_queued_code_is_sent_only_after_the_response(): void {
		$this->verifier->queue_code( $this->token, self::EMAIL );

		// Nothing may go out while the visitor could still be timing the
		// response: the whole point of queueing is that a listed and an
		// unlisted address answer the form in the same time.
		static::assertSame( [], self::mailer()->mock_sent );

		// Core's own shutdown hook would close PHPUnit's output buffer too.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
		do_action( 'shutdown' );

		static::assertCount( 1, self::mailer()->mock_sent );
		static::assertTrue(
			$this->verifier->verify_code( $this->token, self::EMAIL, $this->sent_code() ),
			'The deferred send produces a redeemable challenge.'
		);
	}

	public function test_the_email_names_the_site_and_warns_against_sharing(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		$body = self::sent_email()->body;

		// The two checks a reviewer can hold a phishing imitation against.
		static::assertStringContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $body );
		static::assertStringContainsString( 'Never share it', $body );
	}

	public function test_the_email_never_contains_the_preview_url(): void {
		$this->verifier->send_code( $this->token, self::EMAIL );

		$email = self::sent_email();

		// The email is only a code: if it carried the link too, a forwarded or
		// scanned email would hand over both factors at once.
		static::assertStringNotContainsString( $this->token->value(), $email->body );
		static::assertStringNotContainsString( $this->token->value(), $email->subject );
	}

	public function test_the_verification_cookie_round_trips(): void {
		$_COOKIE = [];
		$this->verifier->remember_verified( $this->token, 'Legal@Example.com' );

		static::assertSame(
			'legal@example.com',
			$this->verifier->verified_email( $this->token ),
			'The proven address comes back lowercased.'
		);
	}

	public function test_a_verification_is_scoped_to_its_link(): void {
		$_COOKIE = [];
		$this->verifier->remember_verified( $this->token, self::EMAIL );

		static::assertNull(
			$this->verifier->verified_email( Token::generate() ),
			'Proving an address for one link says nothing about another.'
		);
	}

	public function test_a_tampered_cookie_reads_as_unverified(): void {
		$_COOKIE = [];
		$this->verifier->remember_verified( $this->token, self::EMAIL );

		$name = 'shareadraft_recipient_' . substr( $this->token->hash(), 0, 20 );

		// Swap the proven address for another and keep the rest: the HMAC no
		// longer covers the bytes presented, so the cookie is worthless.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Reading back a value the test itself just set.
		$cookie = $_COOKIE[ $name ] ?? null;
		static::assertIsString( $cookie );

		$parts    = explode( '.', $cookie );
		$parts[1] = rtrim( strtr( base64_encode( 'attacker@example.com' ), '+/', '-_' ), '=' );

		$_COOKIE[ $name ] = implode( '.', $parts );

		static::assertNull( $this->verifier->verified_email( $this->token ) );
	}

	/**
	 * The counters are atomic in a persistent object cache and fall back to
	 * transients without one; both must enforce the same caps. The core
	 * object cache stands in for the persistent one within a single request.
	 *
	 * @return array<string, array{bool}>
	 */
	public function data_counter_stores(): array {
		return [
			'transients'   => [ false ],
			'object cache' => [ true ],
		];
	}

	/**
	 * The code from the most recently sent email, as a reviewer would read it.
	 */
	private function sent_code(): string {
		$body = self::sent_email( count( self::mailer()->mock_sent ) - 1 )->body;

		static::assertSame( 1, preg_match( '/\b([0-9]{6})\b/', $body, $matches ), 'The email carries a six-digit code.' );

		return $matches[1];
	}

	private static function mailer(): MockPHPMailer {
		$mailer = tests_retrieve_phpmailer_instance();
		static::assertInstanceOf( MockPHPMailer::class, $mailer );

		return $mailer;
	}

	/**
	 * @return object{subject: string, body: string}
	 */
	private static function sent_email( int $index = 0 ): object {
		$email = self::mailer()->get_sent( $index );
		static::assertNotFalse( $email, 'An email was sent.' );

		return $email;
	}
}
