<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use Automattic\VIP\Telemetry\Telemetry as VIP_Telemetry;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * Exercises the abilities the plugin registers through its real composition
 * root: retrieving them from the registry runs the plugin's own registration
 * hooks, so these tests prove the wired ability, not a stand-in.
 *
 * @covers \Automattic\ShareADraft\PreviewAbilities
 */
class PreviewAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			static::markTestSkipped( 'The Abilities API is unavailable on this WordPress version.' );
		}
	}

	public function test_the_category_is_registered(): void {
		// Touch the registry first so the plugin's init hooks run.
		wp_get_ability( PreviewAbilities::CREATE_LINK );

		static::assertTrue( wp_has_ability_category( PreviewAbilities::CATEGORY ) );
	}

	public function test_the_create_link_ability_is_registered_and_public(): void {
		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );

		static::assertInstanceOf( WP_Ability::class, $ability );
		static::assertSame( PreviewAbilities::CATEGORY, $ability->get_category() );

		// `public` (WP 7.1+) drives MCP and AI Client exposure; `show_in_rest` is
		// set explicitly so REST exposure also works on WP 6.9/7.0, where `public`
		// does not seed it.
		static::assertTrue( $ability->get_meta_item( 'public' ) );
		$meta = $ability->get_meta();
		static::assertTrue( $meta['show_in_rest'] );
	}

	public function test_the_input_schema_advertises_the_accepted_expirations(): void {
		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$schema     = $ability->get_input_schema();
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
		$expiration = is_array( $properties['expiration'] ?? null ) ? $properties['expiration'] : [];

		// The ability offers exactly the lifetimes the REST endpoint accepts, so
		// a client cannot mint a link the REST path would reject.
		static::assertSame(
			PreviewRestController::allowed_expirations(),
			$expiration['enum'] ?? null
		);
	}

	public function test_an_editor_can_execute_the_ability_to_mint_a_link(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute(
			[
				'post_id'    => $post_id,
				'expiration' => 8 * HOUR_IN_SECONDS,
			]
		);

		static::assertIsArray( $result );

		$url = $result['url'] ?? null;
		static::assertIsString( $url );
		static::assertStringContainsString( 'preview=true', $url );
		static::assertStringContainsString( PreviewGate::TOKEN_QUERY_VAR . '=', $url );
		static::assertGreaterThan( time(), $result['expires_at'] ?? null );
	}

	public function test_executing_the_ability_tags_telemetry_with_the_ability_channel(): void {
		VIP_Telemetry::reset();

		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );
		$ability->execute(
			[
				'post_id'    => $post_id,
				'expiration' => HOUR_IN_SECONDS,
			]
		);

		static::assertCount( 1, VIP_Telemetry::$events );
		static::assertSame( 'ability', VIP_Telemetry::$events[0]['properties']['channel'] );
	}

	public function test_a_user_without_edit_rights_is_denied(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ability = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute(
			[
				'post_id'    => $post_id,
				'expiration' => HOUR_IN_SECONDS,
			]
		);

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_the_list_ability_is_registered_readonly_and_public(): void {
		$ability = wp_get_ability( PreviewAbilities::LIST_LINKS );

		static::assertInstanceOf( WP_Ability::class, $ability );
		static::assertSame( PreviewAbilities::CATEGORY, $ability->get_category() );
		static::assertTrue( $ability->get_meta_item( 'public' ) );

		$meta = $ability->get_meta();
		static::assertTrue( $meta['show_in_rest'] );
		// Read-only so MCP clients can run it without a confirmation prompt.
		static::assertTrue( self::annotation( $ability, 'readonly' ) );
	}

	public function test_an_editor_lists_a_posts_live_links(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		$list   = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $create );
		static::assertInstanceOf( WP_Ability::class, $list );

		$create->execute(
			[
				'post_id'    => $post_id,
				'expiration' => HOUR_IN_SECONDS,
				'max_uses'   => 5,
			]
		);

		$result = $list->execute( [ 'post_id' => $post_id ] );

		static::assertIsArray( $result );
		static::assertCount( 1, $result );

		$link = $result[0] ?? null;
		static::assertIsArray( $link );
		static::assertSame( 5, $link['max_uses'] ?? null );
		static::assertArrayHasKey( 'token_hint', $link );
		// A hint, never the shareable URL or the token itself.
		static::assertArrayNotHasKey( 'url', $link );
	}

	public function test_listing_is_denied_without_edit_rights(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ability = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( [ 'post_id' => $post_id ] );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_an_editor_lists_every_link_on_the_site(): void {
		$first  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$second = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		$list   = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $create );
		static::assertInstanceOf( WP_Ability::class, $list );

		$create->execute( [ 'post_id' => $first ] );
		$create->execute( [ 'post_id' => $second ] );

		$result = $list->execute( [] );

		static::assertIsArray( $result );
		static::assertCount( 2, $result );
		// Each row names its post, since the caller did not.
		static::assertEqualsCanonicalizing( [ $first, $second ], array_column( $result, 'post_id' ) );
	}

	/**
	 * The site-wide gate is edit_others_posts, which a post type with its own
	 * capabilities falls outside: an editor cannot edit a draft of this type,
	 * so the listing leaves its links out.
	 */
	public function test_the_site_wide_listing_leaves_out_posts_the_caller_cannot_edit(): void {
		register_post_type(
			'sad_product',
			[
				'public'          => true,
				'capability_type' => 'product',
				'map_meta_cap'    => true,
			]
		);

		$hidden  = self::factory()->post->create(
			[
				'post_type'   => 'sad_product',
				'post_status' => 'draft',
				'post_author' => self::factory()->user->create( [ 'role' => 'administrator' ] ),
			]
		);
		$visible = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$service = new PreviewLinkService( new PostMetaTokenRepository(), new AccessPolicy(), new SystemClock() );
		$service->mint( $hidden, HOUR_IN_SECONDS, null, 1 );
		$service->mint( $visible, HOUR_IN_SECONDS, null, 1 );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$list = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $list );

		$result = $list->execute( [] );

		unregister_post_type( 'sad_product' );

		static::assertIsArray( $result );
		static::assertSame( [ $visible ], array_column( $result, 'post_id' ) );
	}

	public function test_the_site_wide_listing_is_denied_without_edit_others_posts(): void {
		// An author can list their own post's links but not the whole site's.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$ability = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( [] );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_the_revoke_ability_is_registered_destructive_and_public(): void {
		$ability = wp_get_ability( PreviewAbilities::REVOKE_LINK );

		static::assertInstanceOf( WP_Ability::class, $ability );
		static::assertSame( PreviewAbilities::CATEGORY, $ability->get_category() );
		static::assertTrue( $ability->get_meta_item( 'public' ) );

		$meta = $ability->get_meta();
		static::assertTrue( $meta['show_in_rest'] );
		// Destructive, so MCP clients confirm before killing a working link.
		static::assertTrue( self::annotation( $ability, 'destructive' ) );
	}

	public function test_an_editor_revokes_a_link_by_its_token_hint(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		$list   = wp_get_ability( PreviewAbilities::LIST_LINKS );
		$revoke = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $create );
		static::assertInstanceOf( WP_Ability::class, $list );
		static::assertInstanceOf( WP_Ability::class, $revoke );

		$create->execute( [ 'post_id' => $post_id ] );
		$links = $list->execute( [ 'post_id' => $post_id ] );
		static::assertIsArray( $links );

		$link = $links[0] ?? null;
		static::assertIsArray( $link );

		$result = $revoke->execute(
			[
				'post_id' => $post_id,
				'link'    => $link['token_hint'] ?? null,
			]
		);

		static::assertSame(
			[
				'revoked' => 1,
				'pending' => false,
			],
			$result
		);
		// The listing only shows live links, so the revoked one is gone.
		static::assertSame( [], $list->execute( [ 'post_id' => $post_id ] ) );
	}

	public function test_revoking_all_kills_every_live_link_on_the_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		$revoke = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $create );
		static::assertInstanceOf( WP_Ability::class, $revoke );

		$create->execute( [ 'post_id' => $post_id ] );
		$create->execute( [ 'post_id' => $post_id ] );

		static::assertSame(
			[
				'revoked' => 2,
				'pending' => false,
			],
			$revoke->execute(
				[
					'post_id' => $post_id,
					'all'     => true,
				]
			)
		);
	}

	public function test_revoking_needs_a_target_and_rejects_conflicting_ones(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$ability = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$missing = $ability->execute( [ 'post_id' => $post_id ] );
		static::assertInstanceOf( WP_Error::class, $missing );
		static::assertSame( 'shareadraft_revoke_missing_target', $missing->get_error_code() );

		$conflicting = $ability->execute(
			[
				'post_id' => $post_id,
				'link'    => 'ab3f',
				'all'     => true,
			]
		);
		static::assertInstanceOf( WP_Error::class, $conflicting );
		static::assertSame( 'shareadraft_revoke_conflicting_input', $conflicting->get_error_code() );
	}

	public function test_an_editor_revokes_every_link_one_creator_made(): void {
		$leaver  = self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => $leaver,
			]
		);

		wp_set_current_user( $leaver );
		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $create );
		$create->execute( [ 'post_id' => $post_id ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$create->execute( [ 'post_id' => $post_id ] );

		$revoke = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $revoke );

		$result = $revoke->execute( [ 'created_by' => $leaver ] );

		// Only the leaver's link went; the editor's own survives.
		static::assertSame(
			[
				'revoked' => 1,
				'pending' => false,
			],
			$result
		);

		$list = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $list );
		$links = $list->execute( [ 'post_id' => $post_id ] );
		static::assertIsArray( $links );
		static::assertCount( 1, $links );
	}

	public function test_an_administrator_revokes_every_link_on_the_site(): void {
		$first  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$second = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		$revoke = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $create );
		static::assertInstanceOf( WP_Ability::class, $revoke );

		$create->execute( [ 'post_id' => $first ] );
		$create->execute( [ 'post_id' => $second ] );

		static::assertSame(
			[
				'revoked' => 2,
				'pending' => false,
			],
			$revoke->execute( [ 'all' => true ] )
		);
	}

	public function test_the_site_wide_revoke_scopes_are_gated_above_edit_post(): void {
		// An author may manage their own post's links, but neither sweep.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$ability = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$creator_sweep = $ability->execute( [ 'created_by' => 123 ] );
		static::assertInstanceOf( WP_Error::class, $creator_sweep );
		static::assertSame( 'ability_invalid_permissions', $creator_sweep->get_error_code() );

		// Even an editor cannot pull the break-glass switch.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$everything = $ability->execute( [ 'all' => true ] );
		static::assertInstanceOf( WP_Error::class, $everything );
		static::assertSame( 'ability_invalid_permissions', $everything->get_error_code() );
	}

	public function test_a_post_id_cannot_smuggle_a_creator_sweep_past_edit_post(): void {
		$victim = self::factory()->user->create( [ 'role' => 'author' ] );
		$their  = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => $victim,
			]
		);

		wp_set_current_user( $victim );
		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $create );
		$create->execute( [ 'post_id' => $their ] );

		// An author who owns a different post pairs it with the victim's id.
		// revoke_link() acts on created_by regardless of post_id, so the gate
		// must too: edit_post on the author's own post cannot authorise a
		// site-wide sweep of someone else's links.
		$attacker = self::factory()->user->create( [ 'role' => 'author' ] );
		$mine     = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => $attacker,
			]
		);
		wp_set_current_user( $attacker );

		$revoke = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $revoke );

		$result = $revoke->execute(
			[
				'post_id'    => $mine,
				'created_by' => $victim,
			]
		);
		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The victim's link is untouched.
		wp_set_current_user( $victim );
		$list = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $list );
		$links = $list->execute( [ 'post_id' => $their ] );
		static::assertIsArray( $links );
		static::assertCount( 1, $links );
	}

	public function test_revoking_is_denied_without_edit_rights(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ability = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( [
			'post_id' => $post_id,
			'all'     => true,
		] );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_an_administrator_prunes_dead_links_immediately(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		$revoke = wp_get_ability( PreviewAbilities::REVOKE_LINK );
		$prune  = wp_get_ability( PreviewAbilities::PRUNE_LINKS );
		static::assertInstanceOf( WP_Ability::class, $create );
		static::assertInstanceOf( WP_Ability::class, $revoke );
		static::assertInstanceOf( WP_Ability::class, $prune );

		$create->execute( [ 'post_id' => $post_id ] );
		$revoke->execute( [
			'post_id' => $post_id,
			'all'     => true,
		] );

		// Grace 0 skips the retention period the sweep normally honours. The
		// sweep only deletes links dead strictly before its cutoff, so a link
		// revoked within the same second is not yet prunable — let it pass.
		sleep( 1 );

		static::assertSame(
			[
				'pruned'  => 1,
				'pending' => false,
			],
			$prune->execute( [ 'grace' => 0 ] )
		);
	}

	public function test_pruning_is_denied_below_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$ability = wp_get_ability( PreviewAbilities::PRUNE_LINKS );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( [] );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_an_administrator_pauses_and_restores_links_site_wide(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$ability = wp_get_ability( PreviewAbilities::SET_ENABLED );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$disabled = $ability->execute( [ 'enabled' => false ] );

		static::assertIsArray( $disabled );
		static::assertFalse( $disabled['enabled'] );
		// Who flipped the switch is recorded, for incident response.
		static::assertSame( $admin_id, $disabled['disabled_by'] );
		static::assertTrue( ( new LinkToggle() )->is_disabled() );

		$enabled = $ability->execute( [ 'enabled' => true ] );

		static::assertSame(
			[
				'enabled'     => true,
				'disabled_at' => null,
				'disabled_by' => null,
			],
			$enabled
		);
		static::assertFalse( ( new LinkToggle() )->is_disabled() );
	}

	public function test_the_switch_is_denied_below_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$ability = wp_get_ability( PreviewAbilities::SET_ENABLED );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( [ 'enabled' => false ] );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_the_site_wide_listing_filters_by_creator(): void {
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => $author,
			]
		);

		wp_set_current_user( $author );
		$create = wp_get_ability( PreviewAbilities::CREATE_LINK );
		static::assertInstanceOf( WP_Ability::class, $create );
		$create->execute( [ 'post_id' => $post_id ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$create->execute( [ 'post_id' => $post_id ] );

		$list = wp_get_ability( PreviewAbilities::LIST_LINKS );
		static::assertInstanceOf( WP_Ability::class, $list );

		$links = $list->execute( [ 'created_by' => $author ] );
		static::assertIsArray( $links );
		static::assertCount( 1, $links );

		// The filter belongs to the site-wide listing, as in the admin table.
		$conflicting = $list->execute(
			[
				'post_id'    => $post_id,
				'created_by' => $author,
			]
		);
		static::assertInstanceOf( WP_Error::class, $conflicting );
		static::assertSame( 'shareadraft_list_conflicting_input', $conflicting->get_error_code() );
	}

	public function test_an_author_reads_the_switch_state_without_flipping_it(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$ability = wp_get_ability( PreviewAbilities::GET_STATUS );
		static::assertInstanceOf( WP_Ability::class, $ability );

		static::assertTrue( self::annotation( $ability, 'readonly' ) );

		static::assertSame(
			[
				'enabled'     => true,
				'disabled_at' => null,
				'disabled_by' => null,
			],
			$ability->execute( [] )
		);
	}

	public function test_the_status_read_reports_who_paused_links(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$set = wp_get_ability( PreviewAbilities::SET_ENABLED );
		static::assertInstanceOf( WP_Ability::class, $set );
		$set->execute( [ 'enabled' => false ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$status = wp_get_ability( PreviewAbilities::GET_STATUS );
		static::assertInstanceOf( WP_Ability::class, $status );

		$result = $status->execute( [] );

		static::assertIsArray( $result );
		static::assertFalse( $result['enabled'] );
		static::assertSame( $admin_id, $result['disabled_by'] );
	}

	public function test_the_status_read_is_denied_without_edit_posts(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ability = wp_get_ability( PreviewAbilities::GET_STATUS );
		static::assertInstanceOf( WP_Ability::class, $ability );

		$result = $ability->execute( [] );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * One of an ability's MCP annotations.
	 *
	 * @return mixed
	 */
	private static function annotation( WP_Ability $ability, string $name ) {
		$annotations = $ability->get_meta()['annotations'] ?? null;
		static::assertIsArray( $annotations );

		return $annotations[ $name ] ?? null;
	}
}
