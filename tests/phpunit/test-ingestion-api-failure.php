<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Failure;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

/**
 * Test class for API failure scenarios using pre_http_request filter to mock failures.
 */
class Ingestion_Api_Failure_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Ingestion::init();

		// Mock the config so API URL is set.
		add_filter(
			'vip_agentforce_config',
			function ( $config ) {
				return array_merge(
					$config,
					[
						'ingestion_api_instance_url' => 'https://test.salesforce.com',
						'ingestion_api_token'        => 'test-token',
						'ingestion_api_source_name'  => 'test_source',
						'ingestion_api_object_name'  => 'test_object',
					]
				);
			}
		);
	}

	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'vip_agentforce_should_ingest_post' );
		remove_all_filters( 'vip_agentforce_transform_post' );
		remove_all_filters( 'vip_agentforce_config' );
		remove_all_filters( 'pre_http_request' );
		remove_all_actions( 'vip_agentforce_post_ingestion_failed' );
	}

	/**
	 * Set up ingestion filters that return true and provide a valid record.
	 */
	private function setup_ingestion_filters(): void {
		add_filter( 'vip_agentforce_should_ingest_post', '__return_true' );
		add_filter(
			'vip_agentforce_transform_post',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Filter callback signature.
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
	}

	/**
	 * Mock HTTP requests to fail.
	 */
	private function mock_http_failure(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'test.salesforce.com' ) !== false ) {
					return new \WP_Error( 'http_error', 'Connection failed' );
				}
				return $preempt;
			},
			10,
			3
		);
	}

	public function test_failure_action_fires_on_api_error(): void {
		// Set up failure listener FIRST.
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

		// Set up HTTP mock to fail.
		$this->mock_http_failure();

		// Set up ingestion filters.
		$this->setup_ingestion_filters();

		// Create post - this triggers save_post which triggers ingestion.
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$this->assertTrue( $action_fired, 'Action should fire when API fails.' );
		$this->assertInstanceOf( Ingestion_Failure::class, $received_failure );
		$this->assertSame( Ingestion_Failure::CODE_API_ERROR, $received_failure->failure_code );
		$this->assertFalse( $received_failure->is_transform_failure() );
		$this->assertTrue( $received_failure->is_api_error() );
		$this->assertSame( $post->ID, $received_failure->post->ID );
		$this->assertInstanceOf( WP_Error::class, $received_failure->error );
		$this->assertSame( 'vip_agentforce_api_error', $received_failure->error->get_error_code() );
	}
}
