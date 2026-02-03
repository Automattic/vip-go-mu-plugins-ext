<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_CLI;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;
use Automattic\VIP\Salesforce\Agentforce\Utils\Logger;

class Ingestion_CLI_Test extends WP_UnitTestCase {

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

	public function setUp(): void {
		parent::setUp();
		Logger::disable();
		$this->captured_requests = [];

		// Set up config for API calls via cache priming.
		$this->prime_configs_cache(
			[
				'ingestion_api_instance_url' => 'https://test.salesforce.com',
				'ingestion_api_token'        => 'test-token',
				'ingestion_api_source_name'  => 'test-source',
				'ingestion_api_object_name'  => 'test-object',
			]
		);

		// Mock HTTP requests to return success and capture them.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Only mock requests to our test Salesforce instance.
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

	public function tearDown(): void {
		parent::tearDown();
		Logger::enable();
		Configs::flush_cache();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
		$this->captured_requests = [];
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
	// delete_record_id_from_api Tests
	// =========================================================================

	public function test_delete_record_id_from_api_returns_success(): void {
		$record_id = '101_1_123';

		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $record_id, $result->record_id );
		$this->assertNotEmpty( $result->timestamp );
	}

	public function test_delete_record_id_from_api_accepts_any_format(): void {
		// Even invalid formats should be accepted - it's the API's job to reject them.
		$record_id = 'invalid_format';

		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $record_id, $result->record_id );
	}

	public function test_delete_from_api_uses_delete_record_id_from_api(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$result = Ingestion::delete_from_api( $post );

		$this->assertTrue( $result->success );
		$this->assertNotNull( $result->record_id );
		// Record ID should contain the post ID.
		$this->assertStringContainsString( (string) $post->ID, $result->record_id );
	}

	public function test_delete_record_id_from_api_includes_response(): void {
		$record_id = '101_1_123';

		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertNotNull( $result->response );
		$this->assertIsArray( $result->response );
	}

	// =========================================================================
	// Record ID Format Tests
	// =========================================================================

	public function test_record_id_format_contains_site_blog_post(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$result = Ingestion::delete_from_api( $post );

		// Format should be site_id_blog_id_post_id.
		$parts = explode( '_', $result->record_id );
		$this->assertCount( 3, $parts, 'Record ID should have 3 parts separated by underscores.' );

		// VIP_GO_APP_ID is defined as 101 in test setup.
		$expected_site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$this->assertSame( $expected_site_id, $parts[0], 'First part should be site_id.' );

		$this->assertSame( (string) get_current_blog_id(), $parts[1], 'Second part should be blog_id.' );
		$this->assertSame( (string) $post->ID, $parts[2], 'Third part should be post_id.' );
	}

	public function test_record_id_uses_correct_site_id(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$result = Ingestion::delete_from_api( $post );

		// Site ID is VIP_GO_APP_ID if defined, otherwise '0'.
		$expected_site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$this->assertStringStartsWith( $expected_site_id . '_', $result->record_id );
	}

	// =========================================================================
	// CLI Record ID Generation Tests (simulating CLI behavior)
	// =========================================================================

	public function test_cli_record_id_generation_with_default_blog_id(): void {
		$post_id = '123';
		$blog_id = (string) get_current_blog_id();
		$site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';

		$expected_record_id = $site_id . '_' . $blog_id . '_' . $post_id;

		// This simulates what the CLI command does.
		$result = Ingestion::delete_record_id_from_api( $expected_record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $expected_record_id, $result->record_id );
	}

	public function test_cli_record_id_generation_with_explicit_blog_id(): void {
		$post_id = '456';
		$blog_id = '2'; // Explicit blog ID, different from current.
		$site_id = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';

		$expected_record_id = $site_id . '_' . $blog_id . '_' . $post_id;

		$result = Ingestion::delete_record_id_from_api( $expected_record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $expected_record_id, $result->record_id );
		$this->assertStringContainsString( '_2_', $result->record_id );
	}

	public function test_cli_can_delete_non_existent_post(): void {
		// Post ID that doesn't exist in WordPress.
		$non_existent_post_id = '999999';
		$blog_id              = (string) get_current_blog_id();
		$site_id              = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';

		$record_id = $site_id . '_' . $blog_id . '_' . $non_existent_post_id;

		// This should succeed - the API doesn't care if the post exists in WP.
		$result = Ingestion::delete_record_id_from_api( $record_id );

		$this->assertTrue( $result->success );
		$this->assertSame( $record_id, $result->record_id );
	}

	// =========================================================================
	// Ingestion_CLI::sync() Tests
	// =========================================================================

	/**
	 * Run CLI sync command and capture output.
	 *
	 * @return string Captured CLI output.
	 */
	private function run_cli_sync(): string {
		$cli = new Ingestion_CLI();
		ob_start();
		$cli->sync();
		return ob_get_clean();
	}

	public function test_cli_sync_errors_when_no_filter_registered(): void {
		// Ensure no filter is registered.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );

		$this->run_cli_sync();

		// Should not make any API calls when filter is missing.
		$this->assertCount( 0, $this->get_ingestion_requests() );
	}

	public function test_cli_sync_ingests_posts(): void {
		$this->setup_ingestion_filters();

		// Create posts.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		$this->run_cli_sync();

		// Should have 2 API calls (one for each post).
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 2, $ingestion_requests, 'Both posts should be synced via CLI.' );
	}

	public function test_cli_sync_ingests_correct_count(): void {
		$this->setup_ingestion_filters();

		// Create 3 posts.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		$this->run_cli_sync();

		// Should have 3 API calls.
		$this->assertCount( 3, $this->get_ingestion_requests() );
	}

	public function test_cli_sync_respects_should_ingest_filter(): void {
		$this->setup_ingestion_filters();

		// Create posts.
		$post1 = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		// Replace filter to only allow post1.
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		add_filter(
			'vip_agentforce_should_ingest_post',
			fn( $should_ingest, $post ) => $post->ID === $post1->ID,
			10,
			2
		);

		$this->run_cli_sync();

		// Should have 1 API call (only post1).
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $ingestion_requests, 'Only post1 should be synced.' );
	}

	public function test_cli_sync_only_processes_published_posts(): void {
		$this->setup_ingestion_filters();

		// Create posts with different statuses.
		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$this->factory()->post->create_and_get( [ 'post_status' => 'pending' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		$this->run_cli_sync();

		// Only the published post should be synced.
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 1, $ingestion_requests, 'Only published post should be synced.' );
	}

	public function test_cli_sync_does_not_trigger_save_post_hook(): void {
		$this->setup_ingestion_filters();

		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		// Track if save_post is called during sync.
		$save_post_called = false;
		add_action(
			'save_post',
			function () use ( &$save_post_called ) {
				$save_post_called = true;
			}
		);

		$this->run_cli_sync();

		// save_post should NOT have been called.
		$this->assertFalse( $save_post_called, 'Sync should NOT trigger save_post hook.' );

		// But the API should have been called.
		$this->assertCount( 1, $this->get_ingestion_requests() );
	}

	public function test_cli_sync_sets_ingestion_attempted_meta(): void {
		$this->setup_ingestion_filters();

		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear the meta that might have been set during post creation.
		delete_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED );

		// Verify meta is not set.
		$this->assertEmpty( get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true ) );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		$this->run_cli_sync();

		// Meta should now be set.
		$meta_value = get_post_meta( $post->ID, Ingestion::META_KEY_INGESTION_ATTEMPTED, true );
		$this->assertNotSame( '', $meta_value, 'Ingestion meta should be set after sync.' );
		$this->assertGreaterThan( 0, (int) $meta_value, 'Ingestion meta should be a positive timestamp.' );
	}

	public function test_cli_sync_handles_transform_failure(): void {
		// Set up filter that allows ingestion but transform returns null.
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter( 'vip_agentforce_transform_post', '__return_null' );

		$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		// Clear any requests from post creation.
		$this->captured_requests = [];

		$this->run_cli_sync();

		// Transform failed, so no API call should be made.
		$this->assertCount( 0, $this->get_ingestion_requests() );
	}

	public function test_cli_sync_processes_multiple_posts_in_batch(): void {
		$this->setup_ingestion_filters();

		// Create 5 posts.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		}

		// Clear any requests from post creation.
		$this->captured_requests = [];

		$this->run_cli_sync();

		// Should have 5 API calls.
		$ingestion_requests = $this->get_ingestion_requests();
		$this->assertCount( 5, $ingestion_requests, 'All 5 posts should be synced.' );
	}
}
