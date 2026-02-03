<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Failure;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_Test extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests for verification.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured_requests = [];

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
		Ingestion::init();

		// Prime configs cache for API calls.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
		remove_all_actions( 'vip_agentforce_post_ingestion_failed' );
		remove_all_filters( 'pre_http_request' );
		Configs::flush_cache();
		$this->captured_requests = [];
	}

	/**
	 * Prime Configs cache for deterministic tests.
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
	 * Get ingestion (POST) requests from captured HTTP calls.
	 *
	 * @return array<int, array{url: string, method: string, body: string}>
	 */
	private function get_ingestion_requests(): array {
		return array_values(
			array_filter(
				$this->captured_requests,
				fn( $req ) => 'POST' === $req['method']
			)
		);
	}

	/**
	 * Clear captured requests (useful between test phases).
	 */
	private function clear_captured_requests(): void {
		$this->captured_requests = [];
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

	public function test_save_post_hook_is_registered(): void {
		$this->assertEquals( 10, has_action( 'save_post', [ Ingestion::class, 'handle_save_post' ] ) );
	}

	public function test_returns_false_when_no_filter_registered(): void {
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$post   = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result, 'Should return false when no filter is registered (safety default).' );
	}

	public function test_published_post_returns_true_when_filter_opts_in(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		$result = Ingestion::should_ingest_post( $post );

		$this->assertTrue( $result );
	}

	/**
	 * Data provider for non-published post statuses.
	 *
	 * @return array<string, array{status: string, post_date?: string}>
	 */
	public function non_published_statuses_provider(): array {
		return [
			'draft'      => [ 'status' => 'draft' ],
			'pending'    => [ 'status' => 'pending' ],
			'private'    => [ 'status' => 'private' ],
			'trash'      => [ 'status' => 'trash' ],
			'auto-draft' => [ 'status' => 'auto-draft' ],
			'future'     => [
				'status'    => 'future',
				'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
			],
		];
	}

	/**
	 * @dataProvider non_published_statuses_provider
	 */
	public function test_should_not_ingest_if_not_published( string $status, ?string $post_date = null ): void {
		$args = [ 'post_status' => $status ];
		if ( $post_date ) {
			$args['post_date'] = $post_date;
		}

		$post   = $this->factory()->post->create_and_get( $args );
		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result, "Post with status '{$status}' should not be ingested." );
	}

	public function test_filter_can_block_published_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result );
	}

	public function test_filter_cannot_override_draft_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );

		// Even if filter returns true, draft posts should still return false.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );

		$result = Ingestion::should_ingest_post( $post );

		$this->assertFalse( $result );
	}

	public function test_filter_receives_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		/** @var \WP_Post|null $received_post */
		$received_post = null;

		add_filter(
			'vip_agentforce_should_ingest_post',
			function ( $should_ingest, $filter_post ) use ( &$received_post ) {
				$received_post = $filter_post;
				return true;
			},
			10,
			2
		);

		Ingestion::should_ingest_post( $post );

		$this->assertNotNull( $received_post );
		$this->assertInstanceOf( WP_Post::class, $received_post );
		$this->assertEquals( $post->ID, $received_post->ID );
	}

	public function test_filter_not_called_for_non_published_posts(): void {
		$post          = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$filter_called = false;

		add_filter(
			'vip_agentforce_should_ingest_post',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Testing if filter is called, not its value.
			function ( $should_ingest ) use ( &$filter_called ) {
				$filter_called = true;
				return true;
			}
		);

		Ingestion::should_ingest_post( $post );

		$this->assertFalse( $filter_called );
	}

	public function test_transform_post_returns_null_without_filter(): void {
		remove_all_filters( 'vip_agentforce_transform_post' );

		$post   = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$result = Ingestion::transform_post( $post );

		$this->assertNull( $result );
	}

	public function test_transform_post_returns_record_with_valid_filter(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter(
			'vip_agentforce_transform_post',
			function ( $record, $filter_post ) {
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

		$result = Ingestion::transform_post( $post );

		$this->assertInstanceOf( Ingestion_Post_Record::class, $result );
		$this->assertSame( (string) $post->ID, $result->post_id );
	}

	public function test_transform_post_returns_null_when_filter_returns_wrong_type(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		add_filter(
			'vip_agentforce_transform_post',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Testing wrong return type.
			function ( $record, $filter_post ) {
				return [ 'not' => 'a record object' ];
			},
			10,
			2
		);

		$result = Ingestion::transform_post( $post );

		$this->assertNull( $result );
	}

	public function test_transform_post_filter_receives_post(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_title'  => 'Test Title for Filter',
				'post_status' => 'publish',
			]
		);
		/** @var \WP_Post|null $received_post */
		$received_post = null;

		add_filter(
			'vip_agentforce_transform_post',
			function ( $record, $filter_post ) use ( &$received_post ) {
				$received_post = $filter_post;
				return new Ingestion_Post_Record(
					[
						'site_id'                 => '1',
						'blog_id'                 => '1',
						'post_id'                 => '1',
						'site_id_blog_id'         => '1_1',
						'site_id_blog_id_post_id' => '1_1_1',
						'published'               => true,
						'last_published_at'       => '2025-01-01T00:00:00+00:00',
						'last_modified_at'        => '2025-01-01T00:00:00+00:00',
						'title'                   => 'Title',
						'content'                 => 'Content',
						'excerpt'                 => 'Excerpt',
						'categories'              => '',
						'tags'                    => '',
						'author'                  => '',
						'url'                     => 'https://example.com',
						'post_type'               => 'post',
						'post_status'             => 'publish',
					]
				);
			},
			10,
			2
		);

		Ingestion::transform_post( $post );

		$this->assertNotNull( $received_post );
		$this->assertInstanceOf( WP_Post::class, $received_post );
		$this->assertEquals( $post->ID, $received_post->ID );
		$this->assertSame( 'Test Title for Filter', $received_post->post_title );
	}

	// =========================================================================
	// Tests for vip_agentforce_post_ingestion_failed action
	// =========================================================================

	public function test_failure_action_does_not_fire_on_filter_rejection(): void {
		// Filter opts out of ingestion - this is NOT a failure.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_false' );

		$action_fired = false;

		add_action(
			'vip_agentforce_post_ingestion_failed',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		// Create post - save_post hook fires, filter rejects, no failure.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( $action_fired, 'Action should NOT fire when filter rejects post (skip is not a failure).' );
	}

	public function test_failure_action_does_not_fire_on_no_filter_registered(): void {
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$action_fired = false;

		add_action(
			'vip_agentforce_post_ingestion_failed',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		// Create post - save_post hook fires, no filter registered, no failure.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( $action_fired, 'Action should NOT fire when no filter is registered (skip is not a failure).' );
	}

	public function test_failure_action_fires_on_transform_failure(): void {
		// Filter opts in but transform returns null.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter( 'vip_agentforce_transform_post', '__return_null' );

		$action_fired = false;
		/** @var Ingestion_Failure|null $received_failure */
		$received_failure = null;

		add_action(
			'vip_agentforce_post_ingestion_failed',
			function ( $failure ) use ( &$action_fired, &$received_failure ) {
				$action_fired     = true;
				$received_failure = $failure;
			}
		);

		// Create post - save_post hook fires, transform fails, failure action fires.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertTrue( $action_fired, 'Action should fire when transform fails.' );
		$this->assertInstanceOf( Ingestion_Failure::class, $received_failure );
		$this->assertSame( Ingestion_Failure::CODE_TRANSFORM_FAILED, $received_failure->failure_code );
		$this->assertTrue( $received_failure->is_transform_failure() );
		$this->assertFalse( $received_failure->is_api_error() );
		$this->assertSame( $post->ID, $received_failure->post->ID );
		$this->assertInstanceOf( WP_Error::class, $received_failure->error );
		$this->assertSame( 'vip_agentforce_transform_failed', $received_failure->error->get_error_code() );
	}

	public function test_failure_action_does_not_fire_on_success(): void {
		// Mock HTTP to return success.
		$this->mock_http_success();

		// Set up ingestion filters.
		$this->setup_ingestion_filters();

		$action_fired = false;

		add_action(
			'vip_agentforce_post_ingestion_failed',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		// Create post - save_post hook fires, ingestion succeeds, no failure.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( $action_fired, 'Action should NOT fire on successful ingestion.' );

		// Verify exactly 1 API call was made (no duplicates).
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $ingestion_requests, 'Exactly 1 ingestion API call should be made.' );
	}

	public function test_updating_published_post_makes_exactly_one_api_call(): void {
		// Mock HTTP to return success.
		$this->mock_http_success();

		// Set up ingestion filters.
		$this->setup_ingestion_filters();

		// Create post.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Verify initial ingestion.
		$this->assertCount( 1, $this->get_ingestion_requests(), 'Initial publish should make exactly 1 API call.' );

		// Clear requests.
		$this->clear_captured_requests();

		// Update the post (stays published).
		wp_update_post( [
			'ID'         => $post->ID,
			'post_title' => 'Updated Title',
		] );

		// Verify exactly 1 API call for update (no duplicates).
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $ingestion_requests, 'Updating published post should make exactly 1 API call.' );
	}

	public function test_updating_post_does_not_ingest_revision(): void {
		$this->mock_http_success();
		$this->setup_ingestion_filters();

		// Create and publish a post.
		$post_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );

		// Clear requests from initial creation.
		$this->clear_captured_requests();

		// Update the post - WordPress creates a revision internally.
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Updated content triggers revision',
			]
		);

		// Verify a revision was actually created (guards against disabled revisions).
		$revisions = wp_get_post_revisions( $post_id );
		$this->assertNotEmpty( $revisions, 'Test requires revisions to be enabled - a revision should have been created' );

		// Should have exactly 1 ingestion call (for the post), not 2 (post + revision).
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $ingestion_requests, 'Updating a post should trigger exactly one ingestion call, not one for the revision too' );
	}

	public function test_failure_error_contains_post_id(): void {
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter( 'vip_agentforce_transform_post', '__return_null' );

		/** @var Ingestion_Failure|null $received_failure */
		$received_failure = null;

		add_action(
			'vip_agentforce_post_ingestion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		// Create post - triggers failure.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertInstanceOf( Ingestion_Failure::class, $received_failure );
		$error_data = $received_failure->error->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertArrayHasKey( 'post_id', $error_data );
		$this->assertSame( $post->ID, $error_data['post_id'] );
	}

	public function test_failure_to_array(): void {
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter( 'vip_agentforce_transform_post', '__return_null' );

		/** @var Ingestion_Failure|null $received_failure */
		$received_failure = null;

		add_action(
			'vip_agentforce_post_ingestion_failed',
			function ( $failure ) use ( &$received_failure ) {
				$received_failure = $failure;
			}
		);

		// Create post - triggers failure.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertInstanceOf( Ingestion_Failure::class, $received_failure );
		$array = $received_failure->to_array();
		$this->assertSame( Ingestion_Failure::CODE_TRANSFORM_FAILED, $array['failure_code'] );
		$this->assertSame( $post->ID, $array['post_id'] );
		$this->assertSame( 'vip_agentforce_transform_failed', $array['error_code'] );
		$this->assertNotEmpty( $array['error_message'] );
	}
}
