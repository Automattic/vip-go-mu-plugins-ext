<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use Automattic\VIP\Telemetry\Telemetry as VIP_Telemetry;
use Spy_REST_Server;
use WP_REST_Request;
use WP_REST_Server;
use WP_Test_REST_TestCase;

/**
 * @covers \Automattic\ShareADraft\PreviewRestController
 */
class PreviewRestControllerTest extends WP_Test_REST_TestCase {
	private const ROUTE = '/' . PreviewRestController::NAMESPACE . PreviewRestController::ROUTE;

	public function setUp(): void {
		parent::setUp();

		$this->register_routes();
	}

	/**
	 * Register the routes on a fresh server. Their schema reads the filters at
	 * this moment, so a test that changes a filter the schema depends on calls
	 * this again.
	 *
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	private function register_routes(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$service = new PreviewLinkService(
			new PostMetaTokenRepository(),
			new AccessPolicy(),
			new SystemClock()
		);
		( new PreviewRestController( $service, new PreviewLinkMinter( $service ) ) )->register_routes();
	}

	/**
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	public function tearDown(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	public function test_the_route_is_registered(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		static::assertArrayHasKey( self::ROUTE, $wp_rest_server->get_routes() );
	}

	public function test_an_editor_mints_a_link_carrying_a_token(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS );

		static::assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();

		$url = $data['url'] ?? null;
		static::assertIsString( $url );
		static::assertStringContainsString( 'preview=true', $url );
		static::assertStringContainsString( PreviewGate::TOKEN_QUERY_VAR . '=', $url );
		static::assertGreaterThan( time(), $data['expires_at'] ?? null );
	}

	public function test_minting_records_a_tracks_event(): void {
		VIP_Telemetry::reset();

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 200, $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, 5 )->get_status() );

		static::assertCount( 1, VIP_Telemetry::$events );
		$event = VIP_Telemetry::$events[0];

		// Source token + event name resolve to `shareadraft_link_created`.
		static::assertSame( 'shareadraft_', $event['prefix'] );
		static::assertSame( 'link_created', $event['event'] );

		// Usage metadata only — never the token, content, or PII.
		static::assertSame( 8 * HOUR_IN_SECONDS, $event['properties']['expiration'] );
		static::assertTrue( $event['properties']['is_capped'] );
		static::assertSame( 5, $event['properties']['max_uses'] );
		static::assertSame( 'rest', $event['properties']['channel'], 'A REST-minted link is tagged with the rest channel.' );
		static::assertArrayNotHasKey( 'url', $event['properties'] );
		static::assertArrayNotHasKey( 'token', $event['properties'] );
	}

	public function test_minting_an_uncapped_link_reports_a_clean_integer(): void {
		VIP_Telemetry::reset();

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// Only a site with no ceiling can mint an uncapped link.
		add_filter( 'shareadraft_max_uses_limit', '__return_null' );
		$status = $this->create_link( $post_id, HOUR_IN_SECONDS )->get_status();
		remove_filter( 'shareadraft_max_uses_limit', '__return_null' );

		static::assertSame( 200, $status );
		static::assertCount( 1, VIP_Telemetry::$events );
		$properties = VIP_Telemetry::$events[0]['properties'];

		// No cap: is_capped is false and max_uses is 0, never a null.
		static::assertFalse( $properties['is_capped'] );
		static::assertSame( 0, $properties['max_uses'] );
	}

	/**
	 * Either of a link's two rows failing to write must fail the whole create:
	 * no URL, no event, and nothing left behind on the post.
	 *
	 * @dataProvider data_link_meta_keys
	 */
	public function test_a_link_that_could_not_be_saved_is_reported_and_leaves_nothing_behind( string $vetoed_key ): void {
		VIP_Telemetry::reset();

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// Another plugin vetoing the insert, which add_post_meta() reports
		// exactly as it would a database error.
		add_filter(
			'add_post_metadata',
			static fn ( ?bool $check, int $object_id, string $meta_key ): ?bool => $vetoed_key === $meta_key ? false : $check,
			10,
			3
		);

		$response = $this->create_link( $post_id, HOUR_IN_SECONDS, 5 );

		static::assertSame( 500, $response->get_status() );
		static::assertSame( 'shareadraft_link_not_saved', ( (array) $response->get_data() )['code'] );
		static::assertSame( [], get_post_meta( $post_id, PostMetaTokenRepository::META_KEY, false ) );
		static::assertSame( [], get_post_meta( $post_id, PostMetaTokenRepository::USES_META_KEY, false ) );
		static::assertCount( 0, VIP_Telemetry::$events );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data_link_meta_keys(): array {
		return [
			'token row' => [ PostMetaTokenRepository::META_KEY ],
			'uses row'  => [ PostMetaTokenRepository::USES_META_KEY ],
		];
	}

	public function test_a_user_without_edit_rights_is_forbidden(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		static::assertSame( 403, $this->create_link( $post_id, HOUR_IN_SECONDS )->get_status() );
	}

	public function test_an_unlisted_expiration_is_rejected(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// 42 seconds is not one of the offered options.
		static::assertSame( 400, $this->create_link( $post_id, 42 )->get_status() );
	}

	public function test_a_post_type_with_no_front_end_view_is_refused(): void {
		register_post_type( 'sad_internal', [
			'public'  => false,
			'show_ui' => true,
		] );

		try {
			$post_id = self::factory()->post->create( [
				'post_type'   => 'sad_internal',
				'post_status' => 'draft',
			] );
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

			$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS );
		} finally {
			unregister_post_type( 'sad_internal' );
		}

		static::assertSame( 400, $response->get_status() );
		static::assertSame( 'shareadraft_post_type_not_viewable', ( (array) $response->get_data() )['code'] );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_a_private_post_is_refused(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS );

		static::assertSame( 400, $response->get_status() );
		static::assertSame( 'shareadraft_post_not_shareable', ( (array) $response->get_data() )['code'] );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_a_capped_link_is_accepted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 200, $this->create_link( $post_id, HOUR_IN_SECONDS, 5 )->get_status() );
	}

	public function test_a_zero_use_cap_is_rejected(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 400, $this->create_link( $post_id, HOUR_IN_SECONDS, 0 )->get_status() );
	}

	public function test_a_link_can_carry_an_ip_allowlist(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, HOUR_IN_SECONDS, null, [ '203.0.113.0/24', '2001:db8::/32' ] );

		static::assertSame( 200, $response->get_status() );
		static::assertSame(
			[ '203.0.113.0/24', '2001:db8::/32' ],
			( new PostMetaTokenRepository() )->all_for_post( $post_id )[0]->allowed_ips()
		);
	}

	public function test_an_invalid_ip_range_is_rejected_not_silently_dropped(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, HOUR_IN_SECONDS, null, [ '203.0.113.0/24', 'office' ] );

		// The author must be told, or they would believe a restriction exists.
		static::assertSame( 400, $response->get_status() );
		static::assertCount( 0, ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_listing_includes_the_ip_allowlist(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->create_link( $post_id, HOUR_IN_SECONDS, null, [ '203.0.113.0/24' ] );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );
		$link = self::first_link( rest_do_request( $request ) );

		static::assertSame( [ '203.0.113.0/24' ], $link['allowed_ips'] ?? null );
	}

	public function test_listing_returns_live_links_only(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->create_link( $post_id, HOUR_IN_SECONDS, 5 );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );
		$response = rest_do_request( $request );

		static::assertSame( 200, $response->get_status() );
		static::assertCount( 1, (array) $response->get_data() );

		$link = self::first_link( $response );
		static::assertSame( 5, $link['max_uses'] ?? null );
		static::assertSame( 0, $link['use_count'] ?? null );
		static::assertArrayHasKey( 'id', $link );

		$hint = $link['token_hint'] ?? null;
		static::assertIsString( $hint );
		static::assertSame( 4, strlen( $hint ), 'A 4-char token hint identifies the link.' );
	}

	public function test_expiration_options_are_filterable(): void {
		$callback = static fn (): array => [
			[
				'seconds' => 123,
				'label'   => 'Custom',
			],
		];
		add_filter( 'shareadraft_expiration_options', $callback );
		$options  = PreviewRestController::expiration_options();
		remove_filter( 'shareadraft_expiration_options', $callback );

		static::assertSame( 123, $options[0]['seconds'] );
	}

	public function test_default_expiration_is_filterable(): void {
		$callback = static fn (): int => DAY_IN_SECONDS;
		add_filter( 'shareadraft_default_expiration', $callback );
		$default  = PreviewRestController::default_expiration();
		remove_filter( 'shareadraft_default_expiration', $callback );

		static::assertSame( DAY_IN_SECONDS, $default );
	}

	public function test_default_expiration_not_offered_falls_back_to_first_option(): void {
		$callback = static fn (): array => [
			[
				'seconds' => DAY_IN_SECONDS,
				'label'   => '24 hours',
			],
			[
				'seconds' => WEEK_IN_SECONDS,
				'label'   => '7 days',
			],
		];
		add_filter( 'shareadraft_expiration_options', $callback );
		$default  = PreviewRestController::default_expiration();
		remove_filter( 'shareadraft_expiration_options', $callback );

		static::assertSame( DAY_IN_SECONDS, $default, 'The built-in 8-hour default is not offered, so the first option is used.' );
	}

	public function test_filtered_default_not_offered_falls_back_to_first_option(): void {
		$callback = static fn (): int => 42;
		add_filter( 'shareadraft_default_expiration', $callback );
		$default  = PreviewRestController::default_expiration();
		remove_filter( 'shareadraft_default_expiration', $callback );

		static::assertSame( HOUR_IN_SECONDS, $default );
	}

	public function test_malformed_expiration_options_fall_back_to_built_in_set(): void {
		$callback = static fn (): array => [ HOUR_IN_SECONDS, DAY_IN_SECONDS ];
		add_filter( 'shareadraft_expiration_options', $callback );
		$allowed  = PreviewRestController::allowed_expirations();
		remove_filter( 'shareadraft_expiration_options', $callback );

		static::assertSame( [ HOUR_IN_SECONDS, 8 * HOUR_IN_SECONDS, DAY_IN_SECONDS, WEEK_IN_SECONDS ], $allowed );
	}

	public function test_invalid_expiration_options_are_dropped(): void {
		$callback = static fn (): array => [
			'first' => [
				'seconds' => '3600',
				'label'   => 'Numeric string',
			],
			[
				'seconds' => 0,
				'label'   => 'Zero',
			],
			[
				'seconds' => -60,
				'label'   => 'Negative',
			],
			[
				'seconds' => '1.5',
				'label'   => 'Fraction',
			],
			[
				'seconds' => true,
				'label'   => 'Boolean',
			],
			[
				'seconds' => DAY_IN_SECONDS,
			],
			[
				'seconds' => DAY_IN_SECONDS,
				'label'   => 24,
			],
			[
				'seconds' => WEEK_IN_SECONDS,
				'label'   => '7 days',
			],
		];
		add_filter( 'shareadraft_expiration_options', $callback );
		$options  = PreviewRestController::expiration_options();
		remove_filter( 'shareadraft_expiration_options', $callback );

		static::assertSame(
			[
				[
					'seconds' => HOUR_IN_SECONDS,
					'label'   => 'Numeric string',
				],
				[
					'seconds' => WEEK_IN_SECONDS,
					'label'   => '7 days',
				],
			],
			$options,
			'Only valid options survive, as a list (so it JSON-encodes as an array) with int seconds.'
		);
	}

	public function test_a_link_minted_without_a_cap_gets_the_ceiling(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		static::assertSame( 200, $this->create_link( $post_id, HOUR_IN_SECONDS )->get_status() );

		$links = ( new PostMetaTokenRepository() )->all_for_post( $post_id );
		static::assertSame( PreviewRestController::MAX_USES_LIMIT, $links[0]->max_uses() );
	}

	public function test_a_cap_above_the_filtered_ceiling_is_refused(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$callback = static fn (): int => 5;
		add_filter( 'shareadraft_max_uses_limit', $callback );
		$refused  = $this->create_link( $post_id, HOUR_IN_SECONDS, 6 );
		$allowed  = $this->create_link( $post_id, HOUR_IN_SECONDS, 5 );
		remove_filter( 'shareadraft_max_uses_limit', $callback );

		static::assertSame( 400, $refused->get_status() );
		static::assertSame( 'shareadraft_invalid_max_uses', ( (array) $refused->get_data() )['code'] );
		static::assertSame( 200, $allowed->get_status() );
	}

	public function test_without_a_ceiling_any_cap_is_accepted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		add_filter( 'shareadraft_max_uses_limit', '__return_null' );
		// The schema's maximum is fixed when routes register, so register
		// them again under the lifted ceiling.
		$this->register_routes();
		$response = $this->create_link( $post_id, HOUR_IN_SECONDS, PreviewRestController::MAX_USES_LIMIT + 1 );
		remove_filter( 'shareadraft_max_uses_limit', '__return_null' );

		static::assertSame( 200, $response->get_status() );
	}

	/**
	 * @dataProvider data_unusable_max_uses_limits
	 */
	public function test_an_unusable_ceiling_falls_back_to_the_built_in_one( mixed $limit ): void {
		$callback = static fn (): mixed => $limit;
		add_filter( 'shareadraft_max_uses_limit', $callback );
		$resolved = PreviewRestController::max_uses_limit();
		remove_filter( 'shareadraft_max_uses_limit', $callback );

		static::assertSame( PreviewRestController::MAX_USES_LIMIT, $resolved );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function data_unusable_max_uses_limits(): array {
		return [
			'zero'     => [ 0 ],
			'negative' => [ -5 ],
			'fraction' => [ '1.5' ],
			'text'     => [ 'lots' ],
			'boolean'  => [ true ],
		];
	}

	public function test_changing_the_ceiling_leaves_existing_links_alone(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		add_filter( 'shareadraft_max_uses_limit', '__return_null' );
		$this->create_link( $post_id, HOUR_IN_SECONDS );
		remove_filter( 'shareadraft_max_uses_limit', '__return_null' );

		$callback = static fn (): int => 5;
		add_filter( 'shareadraft_max_uses_limit', $callback );
		$links    = ( new PostMetaTokenRepository() )->all_for_post( $post_id );
		remove_filter( 'shareadraft_max_uses_limit', $callback );

		static::assertCount( 1, $links );
		static::assertNull( $links[0]->max_uses(), 'A link minted unlimited stays unlimited under a new ceiling.' );
	}

	public function test_a_link_can_be_revoked_and_then_denied(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->create_link( $post_id, HOUR_IN_SECONDS );

		$id = ( new PostMetaTokenRepository() )->all_for_post( $post_id )[0]->token_hash();

		$revoke = new WP_REST_Request( 'DELETE', self::ROUTE . '/' . $id );
		$revoke->set_query_params( [ 'post_id' => $post_id ] );
		static::assertSame( 200, rest_do_request( $revoke )->get_status() );

		// It no longer appears in the live list.
		$list = new WP_REST_Request( 'GET', self::ROUTE );
		$list->set_query_params( [ 'post_id' => $post_id ] );
		static::assertCount( 0, (array) rest_do_request( $list )->get_data() );
	}

	public function test_revoking_an_unknown_link_is_a_404(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'DELETE', self::ROUTE . '/' . str_repeat( 'a', 64 ) );
		$request->set_query_params( [ 'post_id' => $post_id ] );

		static::assertSame( 404, rest_do_request( $request )->get_status() );
	}

	public function test_listing_is_forbidden_without_edit_rights(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( [ 'post_id' => $post_id ] );

		static::assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * @param list<string> $allowed_ips
	 * @param list<string> $recipients
	 */
	private function create_link( int $post_id, int $expiration, ?int $max_uses = null, array $allowed_ips = [], array $recipients = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params(
			[
				'post_id'     => $post_id,
				'expiration'  => $expiration,
				'max_uses'    => $max_uses,
				'allowed_ips' => $allowed_ips,
				'recipients'  => $recipients,
			]
		);

		return rest_do_request( $request );
	}

	public function test_recipients_are_normalised_and_persisted(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [], [ ' Legal@Example.COM ', 'agency@example.org' ] );

		static::assertSame( 200, $response->get_status() );
		static::assertSame(
			[ 'legal@example.com', 'agency@example.org' ],
			( new PostMetaTokenRepository() )->all_for_post( $post_id )[0]->recipients()
		);
	}

	public function test_an_invalid_recipient_is_rejected_not_dropped(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [], [ 'not-an-email' ] );

		static::assertSame( 400, $response->get_status() );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_recipients_are_refused_while_the_feature_is_disabled(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		add_filter( 'shareadraft_recipients_enabled', '__return_false' );

		try {
			// Even with the argument gone from the schema, a hand-built request
			// can still smuggle the parameter in; the minter must say no.
			$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [], [ 'legal@example.com' ] );
		} finally {
			remove_filter( 'shareadraft_recipients_enabled', '__return_false' );
		}

		static::assertSame( 400, $response->get_status() );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	public function test_ip_ranges_are_refused_while_the_feature_is_disabled(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		add_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' );

		try {
			$response = $this->create_link( $post_id, 8 * HOUR_IN_SECONDS, null, [ '203.0.113.0/24' ] );
		} finally {
			remove_filter( 'shareadraft_ip_allowlist_enabled', '__return_false' );
		}

		static::assertSame( 400, $response->get_status() );
		static::assertSame( [], ( new PostMetaTokenRepository() )->all_for_post( $post_id ) );
	}

	/**
	 * The first link in a listing response.
	 *
	 * @return array<mixed>
	 */
	private static function first_link( \WP_REST_Response $response ): array {
		$link = ( (array) $response->get_data() )[0] ?? null;
		static::assertIsArray( $link );

		return $link;
	}
}
