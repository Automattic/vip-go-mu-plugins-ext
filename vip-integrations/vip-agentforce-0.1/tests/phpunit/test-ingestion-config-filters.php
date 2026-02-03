<?php
/**
 * Tests for Ingestion_Config_Filters class.
 *
 * @package vip-agentforce
 */

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Config_Filters;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_Config_Filters_Test extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests for verification.
	 *
	 * @var array<int, array{url: string, method: string, body: string}>
	 */
	private array $captured_requests = [];

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		Configs::flush_cache();
	}

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
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
		$prop->setValue( null, Configs::normalize_config( $config ) );
	}

	// =========================================================================
	// Tests for Configs::should_sync_all_posts()
	// =========================================================================

	public function test_should_sync_all_posts_returns_false_when_not_configured(): void {
		$this->prime_configs_cache( [] );

		$this->assertFalse( Configs::should_sync_all_posts() );
	}

	public function test_should_sync_all_posts_returns_true_when_enabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => true ] );

		$this->assertTrue( Configs::should_sync_all_posts() );
	}

	public function test_should_sync_all_posts_returns_false_when_disabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => false ] );

		$this->assertFalse( Configs::should_sync_all_posts() );
	}

	public function test_should_sync_all_posts_returns_false_for_invalid_value(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => 'invalid' ] );

		$this->assertFalse( Configs::should_sync_all_posts() );
	}

	// =========================================================================
	// Tests for Configs::get_ingestion_categories()
	// =========================================================================

	public function test_get_ingestion_categories_returns_empty_array_when_not_configured(): void {
		$this->prime_configs_cache( [] );

		$this->assertSame( [], Configs::get_ingestion_categories() );
	}

	public function test_get_ingestion_categories_returns_categories_array(): void {
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'News', 'Blog' ] ] );

		$this->assertSame( [ 'News', 'Blog' ], Configs::get_ingestion_categories() );
	}

	public function test_get_ingestion_categories_returns_empty_for_non_array(): void {
		$this->prime_configs_cache( [ 'ingestion_api_categories' => 'not-an-array' ] );

		$this->assertSame( [], Configs::get_ingestion_categories() );
	}

	public function test_get_ingestion_categories_filters_out_invalid_values(): void {
		$this->prime_configs_cache(
			[
				'ingestion_api_categories' => [
					'News',
					null,
					'',
					[ 'nested' ],
					'Blog',
				],
			]
		);

		$this->assertSame( [ 'News', 'Blog' ], Configs::get_ingestion_categories() );
	}

	// =========================================================================
	// Tests for Ingestion_Config_Filters::init() with sync_all_posts
	// =========================================================================

	public function test_init_registers_return_true_filter_when_sync_all_posts_enabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => true ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	public function test_init_does_not_register_filter_when_sync_all_posts_disabled(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => false ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertFalse( Ingestion::should_ingest_post( $post ) );
	}

	// =========================================================================
	// Tests for Ingestion_Config_Filters::init() with categories
	// =========================================================================

	public function test_init_registers_categories_filter_when_configured(): void {
		$category = wp_insert_term( 'News', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'News' ] ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $category['term_id'] ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	public function test_categories_filter_rejects_post_without_matching_category(): void {
		$category       = wp_insert_term( 'News', 'category' );
		$other_category = wp_insert_term( 'Sports', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'News' ] ] );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $other_category['term_id'] ] );

		$this->assertFalse( Ingestion::should_ingest_post( $post ) );
	}

	public function test_categories_filter_matches_any_configured_category(): void {
		$category1 = wp_insert_term( 'News', 'category' );
		$category2 = wp_insert_term( 'Blog', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'News', 'Blog' ] ] );

		Ingestion_Config_Filters::init();

		// Post with second category.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $category2['term_id'] ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	public function test_categories_filter_returns_false_for_post_without_categories(): void {
		wp_insert_term( 'News', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'News' ] ] );

		Ingestion_Config_Filters::init();

		// Create post without setting categories (gets default "Uncategorized").
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( Ingestion::should_ingest_post( $post ) );
	}

	// =========================================================================
	// Tests for sync_all_posts taking precedence over categories
	// =========================================================================

	public function test_sync_all_posts_takes_precedence_over_categories(): void {
		wp_insert_term( 'Sports', 'category' );
		$this->prime_configs_cache(
			[
				'ingestion_api_sync_all_posts' => true,
				'ingestion_api_categories'     => [ 'news' ], // This should be ignored.
			]
		);

		Ingestion_Config_Filters::init();

		// Post with category not in the list should still be ingested.
		$sports_cat = get_term_by( 'slug', 'sports', 'category' );
		$post       = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $sports_cat->term_id ] );

		$this->assertTrue( Ingestion::should_ingest_post( $post ) );
	}

	// =========================================================================
	// Tests for fail-close behavior
	// =========================================================================

	public function test_categories_filter_respects_prior_rejection(): void {
		$category = wp_insert_term( 'News', 'category' );
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'News' ] ] );

		// Add a filter that returns false first.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_false', 5 );

		Ingestion_Config_Filters::init();

		// Post WITH matching category should NOT be ingested due to prior rejection.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post->ID, [ $category['term_id'] ] );

		$this->assertFalse( Ingestion::should_ingest_post( $post ), 'Fail-close: prior rejection should be respected.' );
	}

	public function test_categories_filter_does_not_blindly_trust_prior_approval(): void {
		$this->prime_configs_cache( [ 'ingestion_api_categories' => [ 'News' ] ] );

		// Add a filter that returns true first.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true', 5 );

		Ingestion_Config_Filters::init();

		// Post without matching category should NOT be ingested despite prior approval.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( Ingestion::should_ingest_post( $post ), 'Fail-close: prior approval should not bypass category check.' );
	}

	public function test_sync_all_posts_respects_prior_rejection(): void {
		$this->prime_configs_cache( [ 'ingestion_api_sync_all_posts' => true ] );

		// Add a filter that returns false first.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_false', 5 );

		Ingestion_Config_Filters::init();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertFalse( Ingestion::should_ingest_post( $post ), 'Fail-close: prior rejection should be respected even with sync_all_posts.' );
	}

	// =========================================================================
	// Helper methods for end-to-end tests
	// =========================================================================

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
	 * Set up full ingestion pipeline with config and transform filter.
	 *
	 * @param array<string, mixed> $config Config to prime.
	 */
	private function setup_full_ingestion_pipeline( array $config ): void {
		// Add API credentials to config.
		$config = array_merge(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			],
			$config
		);

		$this->prime_configs_cache( $config );

		// Register the save_post hook.
		Ingestion::init();

		// Register config-based filters.
		Ingestion_Config_Filters::init();

		// Set up transform filter (required for ingestion to proceed).
		add_filter(
			'vip_agentforce_transform_post',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
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

		// Mock HTTP to capture requests.
		$this->mock_http_success();
	}

	// =========================================================================
	// Tests: sync_all_posts triggers actual ingestion
	// =========================================================================

	public function test_sync_all_posts_registers_filter_and_triggers_ingestion(): void {
		$this->setup_full_ingestion_pipeline( [ 'ingestion_api_sync_all_posts' => true ] );

		// Verify filter was registered.
		$this->assertNotFalse( has_filter( 'vip_agentforce_should_ingest_post' ), 'Filter should be registered when sync_all_posts is enabled.' );

		// Create and publish a post - should trigger ingestion via save_post hook.
		$this->factory()->post->create_and_get(
			[
				'post_status' => 'publish',
				'post_title'  => 'Test Post',
			]
		);

		// Verify API call was made.
		$requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $requests, 'Exactly 1 ingestion API call should be made when sync_all_posts is enabled.' );
	}

	public function test_sync_all_posts_disabled_does_not_register_filter(): void {
		$this->setup_full_ingestion_pipeline( [ 'ingestion_api_sync_all_posts' => false ] );

		// Verify no should_ingest filter was registered (only transform filter from setup).
		$this->assertFalse( has_filter( 'vip_agentforce_should_ingest_post' ), 'No should_ingest filter should be registered when sync_all_posts is disabled.' );

		// Create and publish a post.
		$this->factory()->post->create_and_get(
			[
				'post_status' => 'publish',
				'post_title'  => 'Test Post',
			]
		);

		// Verify no API call was made.
		$requests = $this->get_ingestion_requests();
		$this->assertCount( 0, $requests, 'No ingestion should happen when sync_all_posts is disabled and no other filter.' );
	}

	// =========================================================================
	// Tests: category filter triggers actual ingestion
	// =========================================================================

	public function test_category_filter_registers_and_triggers_ingestion_for_matching_post(): void {
		$category = wp_insert_term( 'News', 'category' );
		$this->setup_full_ingestion_pipeline( [ 'ingestion_api_categories' => [ 'News' ] ] );

		// Verify filter was registered.
		$this->assertNotFalse( has_filter( 'vip_agentforce_should_ingest_post' ), 'Filter should be registered when categories are configured.' );

		// Create post with matching category.
		$this->factory()->post->create_and_get(
			[
				'post_status'   => 'publish',
				'post_title'    => 'News Article',
				'post_category' => [ $category['term_id'] ],
			]
		);

		// Verify API call was made.
		$requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $requests, 'Post with matching category should be ingested.' );
	}

	public function test_category_filter_does_not_ingest_non_matching_post(): void {
		wp_insert_term( 'News', 'category' );
		$sports = wp_insert_term( 'Sports', 'category' );
		$this->setup_full_ingestion_pipeline( [ 'ingestion_api_categories' => [ 'News' ] ] );

		// Create post with non-matching category.
		$this->factory()->post->create_and_get(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Sports Article',
				'post_category' => [ $sports['term_id'] ],
			]
		);

		// Verify no API call was made.
		$requests = $this->get_ingestion_requests();
		$this->assertCount( 0, $requests, 'Post without matching category should not be ingested.' );
	}

	public function test_category_filter_triggers_when_category_added_to_existing_post(): void {
		$category = wp_insert_term( 'News', 'category' );
		$this->setup_full_ingestion_pipeline( [ 'ingestion_api_categories' => [ 'News' ] ] );

		// Create post without matching category (won't be ingested).
		$post = $this->factory()->post->create_and_get(
			[
				'post_status' => 'publish',
				'post_title'  => 'Generic Article',
			]
		);

		// No ingestion yet.
		$this->assertCount( 0, $this->get_ingestion_requests(), 'Post without category should not be ingested initially.' );

		// Now add the matching category and update.
		wp_set_post_categories( $post->ID, [ $category['term_id'] ] );
		wp_update_post( [ 'ID' => $post->ID ] );

		// Should now be ingested.
		$requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $requests, 'Post should be ingested after adding matching category.' );
	}

	// =========================================================================
	// Tests: sync_all_posts precedence
	// =========================================================================

	public function test_sync_all_posts_ingests_post_regardless_of_category(): void {
		wp_insert_term( 'News', 'category' );
		$sports = wp_insert_term( 'Sports', 'category' );
		$this->setup_full_ingestion_pipeline(
			[
				'ingestion_api_sync_all_posts' => true,
				'ingestion_api_categories'     => [ 'news' ],
			]
		);

		// Create post with non-matching category.
		$this->factory()->post->create_and_get(
			[
				'post_status'   => 'publish',
				'post_title'    => 'Sports Article',
				'post_category' => [ $sports['term_id'] ],
			]
		);

		// Should still be ingested due to sync_all_posts.
		$requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $requests, 'sync_all_posts should ingest post regardless of category filter.' );
	}
}
