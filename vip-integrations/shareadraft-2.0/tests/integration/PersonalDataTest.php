<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * Reviewer emails in core's Export/Erase Personal Data tools, driven through the
 * callbacks core calls.
 *
 * @covers \Automattic\ShareADraft\PersonalData
 */
class PersonalDataTest extends WP_UnitTestCase {
	private PostMetaTokenRepository $repository;
	private PreviewLinkService $service;
	private PersonalData $personal_data;

	public function set_up(): void {
		parent::set_up();

		$this->repository    = new PostMetaTokenRepository();
		$this->service       = new PreviewLinkService(
			$this->repository,
			new AccessPolicy(),
			new SystemClock()
		);
		$this->personal_data = new PersonalData( $this->service );
	}

	public function test_the_running_plugin_registers_with_core_privacy_tools(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );

		static::assertIsArray( $exporters );
		static::assertIsArray( $erasers );
		static::assertArrayHasKey( 'shareadraft', $exporters );
		static::assertArrayHasKey( 'shareadraft', $erasers );
	}

	public function test_export_lists_only_the_links_bound_to_the_address(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_title'  => 'Quarterly results',
			]
		);
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'bob@example.com', 'amy@example.com' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'amy@example.com' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$export = $this->personal_data->export( 'Bob@Example.com', 1 );

		static::assertTrue( $export['done'] );
		static::assertCount( 1, $export['data'] );
		static::assertSame( 'shareadraft-preview-links', $export['data'][0]['group_id'] );
		static::assertSame(
			[ 'Post', 'Link', 'Created', 'Expires', 'Revoked' ],
			array_column( $export['data'][0]['data'], 'name' )
		);
		static::assertSame( 'Quarterly results', $export['data'][0]['data'][0]['value'] );
		static::assertSame( 'No', $export['data'][0]['data'][4]['value'] );
	}

	public function test_erase_forgets_the_address_without_opening_any_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'bob@example.com', 'amy@example.com' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'bob@example.com' ] );
		$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1 );

		$result = $this->personal_data->erase( 'bob@example.com', 1 );

		static::assertTrue( $result['items_removed'] );
		static::assertFalse( $result['items_retained'] );
		static::assertTrue( $result['done'] );

		[ $shared, $sole, $bearer ] = $this->repository->all_for_post( $post_id );

		static::assertSame( [ 'amy@example.com' ], $shared->recipients() );
		static::assertFalse( $shared->is_revoked(), 'Amy keeps her access.' );
		static::assertSame( [], $sole->recipients() );
		static::assertTrue( $sole->is_revoked(), 'A link left with no reviewers must not become a bearer link.' );
		static::assertFalse( $bearer->is_revoked(), 'Links that never named Bob are untouched.' );

		static::assertSame( [], $this->personal_data->export( 'bob@example.com', 1 )['data'] );
	}

	public function test_erasing_an_unknown_address_reports_nothing_removed(): void {
		$result = $this->personal_data->erase( 'nobody@example.com', 1 );

		static::assertFalse( $result['items_removed'] );
		static::assertFalse( $result['items_retained'] );
		static::assertTrue( $result['done'] );
	}

	public function test_a_large_site_is_paged_across_requests(): void {
		for ( $i = 0; $i < 101; $i++ ) {
			$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
			$this->service->mint( $post_id, HOUR_IN_SECONDS, null, 1, [], [ 'bob@example.com' ] );
		}

		static::assertFalse( $this->personal_data->export( 'bob@example.com', 1 )['done'] );
		static::assertCount( 1, $this->personal_data->export( 'bob@example.com', 2 )['data'] );

		// Core asks for page 2 next; the eraser reads the first remaining page
		// regardless, since erased posts drop out of the match.
		static::assertFalse( $this->personal_data->erase( 'bob@example.com', 1 )['done'] );
		static::assertTrue( $this->personal_data->erase( 'bob@example.com', 2 )['done'] );
		static::assertSame( [], $this->service->post_ids_with_recipient( 'bob@example.com', 0, 10 ) );
	}
}
