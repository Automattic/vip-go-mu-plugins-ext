<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests;

use Automattic\ShareADraft\AccessPolicy;
use Automattic\ShareADraft\PreviewLink;
use Automattic\ShareADraft\PreviewLinkService;
use Automattic\ShareADraft\Tests\Support\FrozenClock;
use Automattic\ShareADraft\Tests\Support\InMemoryTokenRepository;
use PHPUnit\Framework\TestCase;

/**
 * The site-wide listing the admin table reads through the service.
 *
 * @covers \Automattic\ShareADraft\PreviewLinkService
 */
final class PreviewLinkServiceListingTest extends TestCase {
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

	public function test_counts_every_link_across_posts(): void {
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->mint( 10, 3600, 5, 2 );
		$this->service->mint( 20, 3600, null, 1 );

		self::assertSame( 3, $this->service->count_links() );
	}

	public function test_pages_through_the_links(): void {
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->mint( 20, 3600, null, 1 );

		self::assertCount( 2, $this->service->page_of_links( 0, 2 ) );
		self::assertCount( 1, $this->service->page_of_links( 2, 2 ) );
		self::assertSame( [], $this->service->page_of_links( 10, 2 ) );
	}

	public function test_all_links_walks_every_page(): void {
		// More links than one page (100), so the walk has to keep going.
		for ( $i = 0; $i < 150; $i++ ) {
			$this->service->mint( $i + 1, 3600, null, 1 );
		}

		self::assertCount( 150, $this->service->all_links() );
	}

	public function test_returns_preview_links_for_the_issuing_posts(): void {
		$this->service->mint( 10, 3600, null, 1 );
		$this->service->mint( 20, 3600, null, 1 );

		foreach ( $this->service->page_of_links( 0, 10 ) as $link ) {
			self::assertInstanceOf( PreviewLink::class, $link );
			self::assertContains( $link->post_id(), [ 10, 20 ] );
		}
	}
}
