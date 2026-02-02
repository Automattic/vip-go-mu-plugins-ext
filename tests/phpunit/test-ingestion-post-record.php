<?php

use Automattic\VIP\Salesforce\Agentforce\Ingestion\Ingestion_Post_Record;

class Ingestion_Post_Record_Test extends WP_UnitTestCase {

	/**
	 * @param array<string, mixed> $overrides Override values for the record.
	 */
	private function create_record( array $overrides = array() ) {
		$defaults = [
			'site_id'                 => '123',
			'blog_id'                 => '1',
			'post_id'                 => '456',
			'site_id_blog_id'         => '123_1',
			'site_id_blog_id_post_id' => '123_1_456',
			'published'               => true,
			'last_published_at'       => '2025-11-25T12:00:00+00:00',
			'last_modified_at'        => '2025-11-26T09:30:00+00:00',
			'title'                   => 'Hello World',
			'content'                 => 'This is the content.',
			'excerpt'                 => 'This is the excerpt.',
			'categories'              => 'Technology, Testing',
			'tags'                    => 'api, test',
			'author'                  => 'Test User',
			'url'                     => 'https://example.com/hello-world',
			'post_type'               => 'post',
			'post_status'             => 'publish',
		];

		return new Ingestion_Post_Record( array_merge( $defaults, $overrides ) );
	}

	public function test_constructor_sets_all_properties() {
		$record = $this->create_record();

		$this->assertSame( '123_1_456', $record->site_id_blog_id_post_id );
		$this->assertSame( '123', $record->site_id );
		$this->assertSame( '123_1', $record->site_id_blog_id );
		$this->assertSame( '456', $record->post_id );
		$this->assertSame( '1', $record->blog_id );
		$this->assertTrue( $record->published );
		$this->assertSame( '2025-11-25T12:00:00+00:00', $record->last_published_at );
		$this->assertSame( '2025-11-26T09:30:00+00:00', $record->last_modified_at );
		$this->assertSame( 'Hello World', $record->title );
		$this->assertSame( 'This is the content.', $record->content );
		$this->assertSame( 'This is the excerpt.', $record->excerpt );
		$this->assertSame( 'Technology, Testing', $record->categories );
		$this->assertSame( 'api, test', $record->tags );
		$this->assertSame( 'Test User', $record->author );
		$this->assertSame( 'https://example.com/hello-world', $record->url );
		$this->assertSame( 'post', $record->post_type );
		$this->assertSame( 'publish', $record->post_status );
	}

	public function test_to_array_returns_correct_structure() {
		$record = $this->create_record();
		$array  = $record->to_array();

		$this->assertSame(
			[
				'site_id_blog_id_post_id' => '123_1_456',
				'site_id'                 => '123',
				'site_id_blog_id'         => '123_1',
				'post_id'                 => '456',
				'blog_id'                 => '1',
				'published'               => true,
				'last_published_at'       => '2025-11-25T12:00:00+00:00',
				'last_modified_at'        => '2025-11-26T09:30:00+00:00',
				'title'                   => 'Hello World',
				'content'                 => 'This is the content.',
				'excerpt'                 => 'This is the excerpt.',
				'categories'              => 'Technology, Testing',
				'tags'                    => 'api, test',
				'author'                  => 'Test User',
				'url'                     => 'https://example.com/hello-world',
				'post_type'               => 'post',
				'post_status'             => 'publish',
			],
			$array
		);
	}

	public function test_to_array_is_json_serializable() {
		$record = $this->create_record( [ 'published' => false ] );

		$json = wp_json_encode( $record->to_array() );

		$this->assertIsString( $json );
		$this->assertNotFalse( $json );

		$decoded = json_decode( $json, true );
		$this->assertSame( '123_1_456', $decoded['site_id_blog_id_post_id'] );
		$this->assertFalse( $decoded['published'] );
	}
}
