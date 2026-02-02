<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Deletion_Failure;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class Ingestion_Deletion_Test extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests for verification.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured_requests = [];

	/**
	 * Prime Configs cache for deterministic tests without mutating VIP_AGENTFORCE_CONFIGS.
	 *
	 * @param array<string, mixed> $config
	 */
	private function prime_configs_cache( array $config ): void {
		$ref  = new ReflectionClass( Configs::class );
		$prop = $ref->getProperty( 'cached_config' );
		$prop->setAccessible( true );
		$prop->setValue( null, $config );
	}

	/**
	 * Mock HTTP requests to return success (202) and capture request details.
	 */
	private function mock_http_success(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) !== false ) {
					$this->captured_requests[] = [
						'url'    => $url,
						'method' => $args['method'] ?? 'GET',
						'body'   => $args['body'] ?? '',
					];
					return [
						'response' => [
							'code'    => 202,
							'message' => 'Accepted',
						],
						'body'     => '',
					];
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Mock HTTP requests to return failure.
	 */
	private function mock_http_failure(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) !== false ) {
					$this->captured_requests[] = [
						'url'    => $url,
						'method' => $args['method'] ?? 'GET',
						'body'   => $args['body'] ?? '',
					];
					return new \WP_Error( 'http_error', 'Simulated API failure' );
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Get deletion requests from captured HTTP calls.
	 *
	 * @return array<int, array{url: string, method: string, body: string}>
	 */
	private function get_deletion_requests(): array {
		return array_filter(
			$this->captured_requests,
			fn( $req ) => 'DELETE' === $req['method']
		);
	}

	/**
	 * Clear captured requests (useful between test phases).
	 */
	private function clear_captured_requests(): void {
		$this->captured_requests = [];
	}

	public function setUp(): void {
		parent::setUp();
		Ingestion::init();

		// Set up config for API calls via cache priming.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);

		// Default to success - tests can override with mock_http_failure().
		$this->mock_http_success();
	}

	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
		remove_all_actions( 'vip_agentforce_post_deletion_failed' );
		Configs::flush_cache();
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Helper to set up a post that was "previously ingested" (has tracking meta).
	 *
	 * @param \WP_Post $post The post to mark as ingested.
	 */
	private function mark_post_as_ingested( \WP_Post $post ): void {
		update_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, time() );
	}

	/**
	 * Helper to set up filters for a valid ingestion.
	 */
	private function setup_ingestion_filters(): void {
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter(
			'vip_agentforce_transform_post',
			function ( $record, $post ) {
				return new Ingestion_Post_Record(
					[
						'site_id'                 => '1',
						'blog_id'                 => '1',
						'post_id'                 => (string) $post->ID,
						'site_id_blog_id'         => '1_1',
						'site_id_blog_id_post_id' => '1_1_' . $post->ID,
						'published'               => true,
						'last_published_at'       => '2025-01-01T00:00:00+00:00',
						'last_modified_at'        => '2025-01-01T00:00:00+00:00',
						'title'                   => $post->post_title,
						'content'                 => $post->post_content,
						'excerpt'                 => $post->post_excerpt,
						'categories'              => '',
						'tags'                    => '',
						'author'                  => '',
						'url'                     => 'https://example.com',
						'post_type'               => $post->post_type,
						'post_status'             => $post->post_status,
					]
				);
			},
			10,
			2
		);
	}

	// =========================================================================
	// Hook Registration Tests
	// =========================================================================

	public function test_before_delete_post_hook_is_registered(): void {
		$this->assertEquals( 10, has_action( 'before_delete_post', [ Ingestion::class, 'handle_before_delete_post' ] ) );
	}

	// =========================================================================
	// Post Meta Tracking Tests
	// =========================================================================

	public function test_ingestion_sets_meta_on_post(): void {
		// Set up filters BEFORE creating post.
		$this->setup_ingestion_filters();

		// Create post - save_post hook fires, ingestion happens automatically.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Verify meta was set.
		$meta = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		$this->assertNotEmpty( $meta, 'Ingestion should set tracking meta on post.' );
	}

	public function test_successful_deletion_clears_meta(): void {
		// Set up filters and create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Verify meta exists before deletion.
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Unpublish using WordPress function - triggers transition_post_status.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Verify meta was cleared.
		$meta = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		// @phpstan-ignore method.impossibleType (get_post_meta returns mixed, assertion is valid at runtime)
		$this->assertEmpty( $meta, 'Successful deletion should clear tracking meta.' );
	}

	public function test_meta_key_constant_is_defined(): void {
		$this->assertSame( 'vip_agentforce_ingestion_attempted', Ingestion::META_KEY_INGESTION_ATTEMPTED );
	}

	// =========================================================================
	// Unpublishing Tests (transition_post_status)
	// =========================================================================

	/**
	 * Data provider for unpublish transitions that should trigger deletion.
	 *
	 * @return array<string, array{new_status: string}>
	 */
	public function unpublish_statuses_provider(): array {
		return [
			'publish_to_draft'   => [ 'new_status' => 'draft' ],
			'publish_to_pending' => [ 'new_status' => 'pending' ],
			'publish_to_private' => [ 'new_status' => 'private' ],
			'publish_to_trash'   => [ 'new_status' => 'trash' ],
		];
	}

	/**
	 * @dataProvider unpublish_statuses_provider
	 */
	public function test_unpublish_triggers_deletion_when_meta_exists( string $new_status ): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion, so we only see deletion requests.
		$this->clear_captured_requests();

		// Change status using WordPress function - triggers transition_post_status.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => $new_status,
		] );

		// Verify deletion API was called with DELETE method.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, "Deletion API should be called for publish -> {$new_status}." );

		// Meta should be cleared on successful deletion.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ), 'Meta should be cleared after successful deletion.' );
	}

	/**
	 * Data provider for transitions that should NOT trigger deletion.
	 *
	 * Only includes transitions where the target status is 'publish', since:
	 * - Publishing a post should add to Salesforce, not delete
	 * - Other transitions (draft->trash, pending->draft, etc.) with ingestion meta
	 *   are invalid scenarios in production - you can only ingest published posts
	 *
	 * @return array<string, array{old_status: string, new_status: string}>
	 */
	public function non_deletion_transitions_provider(): array {
		return [
			'draft_to_publish' => [
				'old_status' => 'draft',
				'new_status' => 'publish',
			],
		];
	}

	/**
	 * @dataProvider non_deletion_transitions_provider
	 */
	public function test_non_publish_transitions_do_not_trigger_deletion( string $old_status, string $new_status ): void {
		// Create post with old_status.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => $old_status ] );
		$this->mark_post_as_ingested( $post );

		// Set up ingestion filters so post passes filter - we're only testing that
		// the transition itself doesn't trigger deletion, not filter rejection.
		$this->setup_ingestion_filters();

		// Clear any captured requests.
		$this->clear_captured_requests();

		// Change status using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => $new_status,
		] );

		// Verify no DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertEmpty( $deletion_requests, "No deletion should occur for {$old_status} -> {$new_status}." );
	}

	// =========================================================================
	// Post Meta-Based Deletion Tests
	// =========================================================================

	public function test_unpublish_without_meta_does_not_delete(): void {
		// Create post WITHOUT ingestion filters - no meta will be set.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear any captured requests.
		$this->clear_captured_requests();

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Verify no DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertEmpty( $deletion_requests, 'No deletion should occur when post has no ingestion meta.' );
	}

	public function test_unpublish_with_meta_triggers_deletion(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called when post has ingestion meta.' );

		// Meta should be cleared on successful deletion.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ), 'Meta should be cleared after successful deletion.' );
	}

	public function test_deletion_works_even_when_filter_changed(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Filter now returns false (developer changed it), but post was previously ingested.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Verify DELETE API was called based on meta, not current filter state.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion should occur based on meta, not current filter state.' );

		// Meta should be cleared on successful deletion.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ), 'Meta should be cleared after successful deletion.' );
	}

	// =========================================================================
	// Permanent Deletion Tests (before_delete_post)
	// =========================================================================

	public function test_delete_published_post_with_meta_triggers_deletion(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post    = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post_id = $post->ID;
		$this->assertNotEmpty( get_post_meta( $post_id, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Permanently delete using WordPress function - triggers before_delete_post.
		wp_delete_post( $post_id, true );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called for published post with meta.' );
	}

	public function test_delete_published_post_without_meta_does_not_delete(): void {
		// Create post WITHOUT ingestion filters - no meta.
		$post    = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$post_id = $post->ID;
		$this->assertEmpty( get_post_meta( $post_id, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear any captured requests.
		$this->clear_captured_requests();

		// Permanently delete using WordPress function.
		wp_delete_post( $post_id, true );

		// Verify no DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertEmpty( $deletion_requests, 'No deletion should occur for published post without meta.' );
	}

	public function test_delete_draft_post_does_not_trigger_deletion(): void {
		// Create draft post with meta (simulating it was ingested in the past when published).
		$post    = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$post_id = $post->ID;
		$this->mark_post_as_ingested( $post ); // Even with meta, drafts shouldn't trigger deletion.

		// Clear any captured requests.
		$this->clear_captured_requests();

		// Permanently delete using WordPress function.
		wp_delete_post( $post_id, true );

		// Verify no DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertEmpty( $deletion_requests, 'No deletion should occur for draft post.' );
	}

	// =========================================================================
	// Failure Action Tests
	// =========================================================================

	public function test_deletion_failure_action_fires_on_api_error(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Use HTTP failure mock.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http_failure();

		$action_fired = false;
		/** @var Deletion_Failure|null $received_failure */
		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$action_fired, &$received_failure ) {
				$action_fired     = true;
				$received_failure = $failure;
			}
		);

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		$this->assertTrue( $action_fired, 'Failure action should fire on API error.' );
		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$this->assertSame( Deletion_Failure::CODE_DELETE_API_ERROR, $received_failure->failure_code );
		$this->assertTrue( $received_failure->is_delete_api_error() );
	}

	public function test_deletion_failure_contains_record_id(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Use HTTP failure mock.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http_failure();

		/** @var Deletion_Failure|null $received_failure */
		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$this->assertNotEmpty( $received_failure->record_id );
		// Record ID format: site_id_blog_id_post_id.
		$this->assertStringContainsString( (string) $post->ID, $received_failure->record_id );
	}

	public function test_deletion_failure_contains_post(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Use HTTP failure mock.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http_failure();

		/** @var Deletion_Failure|null $received_failure */
		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$this->assertInstanceOf( WP_Post::class, $received_failure->post );
		$this->assertSame( $post->ID, $received_failure->post->ID );
	}

	public function test_deletion_failure_to_array(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Use HTTP failure mock.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http_failure();

		/** @var Deletion_Failure|null $received_failure */
		$received_failure = null;

		add_action(
			'vip_agentforce_post_deletion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		$this->assertInstanceOf( Deletion_Failure::class, $received_failure );
		$array = $received_failure->to_array();
		$this->assertSame( Deletion_Failure::CODE_DELETE_API_ERROR, $array['failure_code'] );
		$this->assertSame( $post->ID, $array['post_id'] );
		$this->assertNotEmpty( $array['record_id'] );
		$this->assertSame( 'vip_agentforce_delete_api_error', $array['error_code'] );
		$this->assertNotEmpty( $array['error_message'] );
	}

	public function test_meta_not_cleared_on_deletion_failure(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Use HTTP failure mock.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http_failure();

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Meta should NOT be cleared on failure - we still need to track that the post is in Salesforce.
		$meta = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		// @phpstan-ignore method.alreadyNarrowedType (get_post_meta returns mixed, assertion is valid at runtime)
		$this->assertNotEmpty( $meta, 'Meta should NOT be cleared when deletion fails.' );
	}

	// =========================================================================
	// Success Tests (no failure action)
	// =========================================================================

	public function test_deletion_failure_action_does_not_fire_on_success(): void {
		// Create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// HTTP mock returns success by default.
		$action_fired = false;
		add_action(
			'vip_agentforce_post_deletion_failed',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		// Unpublish using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called.' );

		// Failure action should NOT fire on success.
		$this->assertFalse( $action_fired, 'Failure action should NOT fire on successful deletion.' );

		// Meta should be cleared on successful deletion.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ), 'Meta should be cleared after successful deletion.' );
	}

	// =========================================================================
	// Full Flow Tests (ingest then delete)
	// =========================================================================

	public function test_full_flow_publish_ingest_then_unpublish_deletes(): void {
		// Set up filters BEFORE creating post.
		$this->setup_ingestion_filters();

		// Create post - save_post hook fires, ingestion happens automatically.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Verify the post was marked as ingested.
		$meta = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		$this->assertNotEmpty( $meta, 'Post should be marked as ingested after creation.' );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Unpublish using WordPress function - triggers transition_post_status.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called when unpublishing.' );

		// Verify the meta was cleared (deletion was successful).
		$meta_after = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		$this->assertEmpty( $meta_after, 'Meta should be cleared after unpublishing (deletion successful).' );
	}

	public function test_full_flow_publish_ingest_then_trash_deletes(): void {
		// Set up filters BEFORE creating post.
		$this->setup_ingestion_filters();

		// Create post - save_post hook fires, ingestion happens automatically.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Verify ingested.
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Trash the post using WordPress function.
		wp_trash_post( $post->ID );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called when trashing.' );

		// Verify deleted from Salesforce.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );
	}

	// =========================================================================
	// No Filters Configured = No Deletion (incomplete setup safeguard)
	// =========================================================================

	public function test_no_deletion_when_no_filters_configured(): void {
		// Create ingested post with filters active.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Now remove all filters and clear config to simulate "no setup" state.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
				// No sync_all_posts, no categories - incomplete setup.
			]
		);

		// Update the post - should NOT trigger deletion because setup is incomplete.
		wp_update_post( [
			'ID'         => $post->ID,
			'post_title' => 'Updated Title',
		] );

		// Verify no DELETE API was called - incomplete setup means no destructive action.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertEmpty( $deletion_requests, 'No deletion should occur when no ingestion filters are configured (incomplete setup).' );

		// Meta should still exist since we didn't delete.
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ), 'Meta should be preserved when deletion is skipped.' );
	}

	public function test_deletion_occurs_when_filters_registered_but_post_no_longer_matches(): void {
		// Create ingested post with filters active.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Replace with a filter that rejects. has_filter() will return true.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		// Update the post - SHOULD trigger deletion because filters are registered.
		wp_update_post( [
			'ID'         => $post->ID,
			'post_title' => 'Updated Title',
		] );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion should occur when filters are registered and post no longer matches.' );
	}

	// =========================================================================
	// Filter Rejection Deletion Tests (handle_save_post)
	// =========================================================================

	public function test_handle_save_post_deletes_when_filter_rejects_previously_ingested_post(): void {
		// Set up filters and create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Verify ingested.
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Change filter to reject the post.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Update the post (simulates user editing and saving) - triggers save_post.
		wp_update_post( [
			'ID'         => $post->ID,
			'post_title' => 'Updated Title',
		] );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called when filter rejects previously ingested post.' );

		// Verify the post was deleted from Salesforce (meta cleared).
		$meta_after = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		// @phpstan-ignore method.impossibleType (get_post_meta returns mixed, assertion is valid at runtime)
		$this->assertEmpty( $meta_after, 'Meta should be cleared after deletion.' );
	}

	public function test_handle_save_post_does_not_delete_when_filter_rejects_never_ingested_post(): void {
		// Create post WITHOUT ingestion filters - no meta.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Filter rejects the post.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		// Clear any captured requests.
		$this->clear_captured_requests();

		// Update the post - triggers save_post.
		wp_update_post( [
			'ID'         => $post->ID,
			'post_title' => 'Updated Title',
		] );

		// No deletion should be attempted for a post that was never ingested.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertEmpty( $deletion_requests, 'No deletion should occur for post that was never ingested.' );
	}

	public function test_handle_save_post_deletes_when_post_no_longer_published(): void {
		// Set up filters and create ingested post.
		$this->setup_ingestion_filters();
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Verify ingested.
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Change post to draft status using WordPress function.
		wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => 'draft',
		] );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called when status changes to non-published.' );

		// Verify deleted from Salesforce.
		$this->assertEmpty(
			get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ),
			'Meta should be cleared after deletion.'
		);
	}

	public function test_handle_save_post_category_change_triggers_deletion(): void {
		// Create a category first.
		$cat_id = $this->factory()->category->create( [ 'name' => 'Ingestible Category' ] );

		// Filter that only ingests posts in specific category.
		add_filter(
			'vip_agentforce_should_ingest_post',
			function ( $_should_ingest, $filter_post ) use ( $cat_id ) {
				return has_category( $cat_id, $filter_post );
			},
			10,
			2
		);
		add_filter(
			'vip_agentforce_transform_post',
			function ( $_record, $filter_post ) {
				return new Ingestion_Post_Record(
					[
						'site_id'                 => '1',
						'blog_id'                 => '1',
						'post_id'                 => (string) $filter_post->ID,
						'site_id_blog_id'         => '1_1',
						'site_id_blog_id_post_id' => '1_1_' . $filter_post->ID,
						'published'               => true,
						'last_published_at'       => '2025-01-01T00:00:00+00:00',
						'last_modified_at'        => '2025-01-01T00:00:00+00:00',
						'title'                   => $filter_post->post_title,
						'content'                 => $filter_post->post_content,
						'excerpt'                 => $filter_post->post_excerpt,
						'categories'              => '',
						'tags'                    => '',
						'author'                  => '',
						'url'                     => 'https://example.com',
						'post_type'               => $filter_post->post_type,
						'post_status'             => $filter_post->post_status,
					]
				);
			},
			10,
			2
		);

		// Create post with the category - should be ingested.
		$post = $this->factory()->post->create_and_get( [
			'post_status'   => 'publish',
			'post_category' => [ $cat_id ],
		] );
		$this->assertNotEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear captured requests from ingestion.
		$this->clear_captured_requests();

		// Remove the category from the post using WordPress function.
		wp_set_post_categories( $post->ID, [] );

		// Trigger save_post by updating the post.
		wp_update_post( [
			'ID'         => $post->ID,
			'post_title' => 'Updated Title',
		] );

		// Verify DELETE API was called.
		$deletion_requests = $this->get_deletion_requests();
		$this->assertCount( 1, $deletion_requests, 'Deletion API should be called when category is removed and filter no longer matches.' );

		// Post should be deleted from Salesforce (meta cleared).
		$this->assertEmpty(
			get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ),
			'Meta should be cleared after deletion.'
		);
	}
}
