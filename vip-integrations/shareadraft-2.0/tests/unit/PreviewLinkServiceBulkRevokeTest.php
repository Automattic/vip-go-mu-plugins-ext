<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests;

use Automattic\ShareADraft\AccessPolicy;
use Automattic\ShareADraft\PreviewLinkService;
use Automattic\ShareADraft\Tests\Support\FrozenClock;
use Automattic\ShareADraft\Tests\Support\InMemoryTokenRepository;
use PHPUnit\Framework\TestCase;

/**
 * The per-post bulk revokes the sweep drives, and the creator filter behind the
 * admin table.
 *
 * @covers \Automattic\ShareADraft\PreviewLinkService
 */
final class PreviewLinkServiceBulkRevokeTest extends TestCase {
	private const NOW = 1000;

	private InMemoryTokenRepository $repository;
	private PreviewLinkService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new InMemoryTokenRepository();
		$this->service    = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new FrozenClock( self::NOW )
		);
	}

	public function test_revoke_all_for_post_revokes_every_live_link(): void {
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->mint( 10, 3600, null, 2 );

		self::assertSame( 2, $this->service->revoke_all_for_post( 10 ) );

		foreach ( $this->repository->all_for_post( 10 ) as $link ) {
			self::assertTrue( $link->is_revoked() );
			self::assertSame( self::NOW, $link->revoked_at() );
		}
	}

	public function test_revoke_all_for_post_skips_already_revoked_links(): void {
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->revoke( 10, $this->repository->all_for_post( 10 )[0]->token_hash() );

		self::assertSame( 0, $this->service->revoke_all_for_post( 10 ) );
	}

	public function test_revoke_active_links_for_post_leaves_dead_links_untouched(): void {
		$this->service->mint( 10, 3600, null, 1 ); // Live.
		$this->service->mint( 10, 0, null, 1 );    // Already expired at NOW.
		$this->service->mint( 10, 3600, null, 1 ); // Revoked below.
		$this->service->revoke( 10, $this->repository->all_for_post( 10 )[2]->token_hash() );

		self::assertSame( 1, $this->service->revoke_active_links_for_post( 10 ) );

		// The expired link keeps telling a returning visitor "expired", not
		// "revoked" — dead links are left exactly as they died.
		self::assertFalse( $this->repository->all_for_post( 10 )[1]->is_revoked() );
	}

	public function test_revoke_by_creator_leaves_other_creators_links_alone(): void {
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->mint( 10, 3600, null, 2 );

		self::assertSame( 1, $this->service->revoke_for_post_by_creator( 10, 2 ) );

		foreach ( $this->repository->all_for_post( 10 ) as $link ) {
			self::assertSame( 2 === $link->created_by(), $link->is_revoked() );
		}
	}

	public function test_listing_can_be_filtered_by_creator(): void {
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->mint( 10, 3600, null, 2 );
		$this->service->mint( 20, 3600, null, 2 );

		self::assertSame( 2, $this->service->count_links( 2 ) );

		foreach ( $this->service->page_of_links( 0, 10, 2 ) as $link ) {
			self::assertSame( 2, $link->created_by() );
		}
	}
}
