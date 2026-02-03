<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Default_Transformer;
use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

class Default_Transformer_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Default_Transformer::init();
	}

	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'vip_agentforce_transform_post' );
	}

	public function test_filter_is_registered(): void {
		$this->assertEquals( 10, has_filter( 'vip_agentforce_transform_post', [ Default_Transformer::class, 'transform' ] ) );
	}

	public function test_transforms_post_to_record(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_title'   => 'Test Post',
				'post_content' => 'Test content here.',
				'post_excerpt' => 'Test excerpt.',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertInstanceOf( Ingestion_Post_Record::class, $record );
		$this->assertSame( 'Test Post', $record->title );
		$this->assertSame( 'Test content here.', $record->content );
		$this->assertSame( 'Test excerpt.', $record->excerpt );
		$this->assertSame( 'publish', $record->post_status );
		$this->assertSame( 'post', $record->post_type );
		$this->assertTrue( $record->published );
	}

	public function test_returns_existing_record_if_already_transformed(): void {
		$post            = $this->factory()->post->create_and_get();
		$existing_record = new Ingestion_Post_Record(
			[
				'site_id'                 => '999',
				'blog_id'                 => '1',
				'post_id'                 => '123',
				'site_id_blog_id'         => '999_1',
				'site_id_blog_id_post_id' => 'custom_id',
				'published'               => true,
				'last_published_at'       => '2025-01-01T00:00:00+00:00',
				'last_modified_at'        => '2025-01-01T00:00:00+00:00',
				'title'                   => 'Custom Title',
				'content'                 => 'Custom content',
				'excerpt'                 => 'Custom excerpt',
				'categories'              => 'Custom Cat',
				'tags'                    => 'custom-tag',
				'author'                  => 'Custom Author',
				'url'                     => 'https://custom.example.com',
				'post_type'               => 'custom-type',
				'post_status'             => 'publish',
			]
		);

		$result = Default_Transformer::transform( $existing_record, $post );

		$this->assertSame( $existing_record, $result );
		$this->assertSame( 'Custom Title', $result->title );
	}

	public function test_composite_ids_are_built_correctly(): void {
		$post = $this->factory()->post->create_and_get();

		$record = Default_Transformer::transform( null, $post );

		$expected_site_id  = defined( 'VIP_GO_APP_ID' ) ? (string) VIP_GO_APP_ID : '0';
		$expected_blog_id  = (string) get_current_blog_id();
		$expected_post_id  = (string) $post->ID;
		$expected_compound = $expected_site_id . '_' . $expected_blog_id . '_' . $expected_post_id;

		$this->assertSame( $expected_site_id, $record->site_id );
		$this->assertSame( $expected_blog_id, $record->blog_id );
		$this->assertSame( $expected_post_id, $record->post_id );
		$this->assertSame( $expected_site_id . '_' . $expected_blog_id, $record->site_id_blog_id );
		$this->assertSame( $expected_compound, $record->site_id_blog_id_post_id );
	}

	public function test_dates_are_formatted_as_iso8601(): void {
		$post_id = $this->factory()->post->create(
			[
				'post_date_gmt'     => '2025-11-25 12:30:45',
				'post_modified_gmt' => '2025-11-26 09:15:30',
			]
		);
		// Manually update post_modified_gmt since factory may override it.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup only.
		$wpdb->update(
			$wpdb->posts,
			[ 'post_modified_gmt' => '2025-11-26 09:15:30' ],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( '2025-11-25T12:30:45+00:00', $record->last_published_at );
		$this->assertSame( '2025-11-26T09:15:30+00:00', $record->last_modified_at );
	}

	public function test_categories_are_comma_separated(): void {
		$cat1 = $this->factory()->category->create( [ 'name' => 'Technology' ] );
		$cat2 = $this->factory()->category->create( [ 'name' => 'Testing' ] );
		$post = $this->factory()->post->create_and_get();
		wp_set_post_categories( $post->ID, [ $cat1, $cat2 ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertStringContainsString( 'Technology', $record->categories );
		$this->assertStringContainsString( 'Testing', $record->categories );
		$this->assertStringContainsString( ', ', $record->categories );
	}

	public function test_tags_are_comma_separated(): void {
		$post = $this->factory()->post->create_and_get();
		wp_set_post_tags( $post->ID, [ 'api', 'test', 'data-cloud' ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertStringContainsString( 'api', $record->tags );
		$this->assertStringContainsString( 'test', $record->tags );
		$this->assertStringContainsString( 'data-cloud', $record->tags );
		$this->assertStringContainsString( ', ', $record->tags );
	}

	public function test_no_custom_categories_returns_uncategorized(): void {
		// WordPress assigns "Uncategorized" by default.
		$post = $this->factory()->post->create_and_get();

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'Uncategorized', $record->categories );
	}

	public function test_empty_tags_returns_empty_string(): void {
		$post = $this->factory()->post->create_and_get();

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( '', $record->tags );
	}

	public function test_author_name_is_resolved(): void {
		$user_id = $this->factory()->user->create(
			[
				'display_name' => 'John Doe',
			]
		);
		$post    = $this->factory()->post->create_and_get( [ 'post_author' => $user_id ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( 'John Doe', $record->author );
	}

	public function test_invalid_author_returns_empty_string(): void {
		$post              = $this->factory()->post->create_and_get();
		$post->post_author = 99999;

		$record = Default_Transformer::transform( null, $post );

		$this->assertSame( '', $record->author );
	}

	public function test_permalink_is_set(): void {
		$post = $this->factory()->post->create_and_get(
			[
				'post_name'   => 'test-post-slug',
				'post_status' => 'publish',
			]
		);

		$record = Default_Transformer::transform( null, $post );

		$this->assertNotEmpty( $record->url );
		// Test uses plain permalinks by default, so URL contains ?p=ID.
		$this->assertStringContainsString( '?p=' . $post->ID, $record->url );
	}

	public function test_draft_post_has_published_false(): void {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );

		$record = Default_Transformer::transform( null, $post );

		$this->assertFalse( $record->published );
		$this->assertSame( 'draft', $record->post_status );
	}
}
