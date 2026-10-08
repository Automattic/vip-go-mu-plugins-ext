<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * Deleting the plugin leaves nothing of it behind.
 *
 * @coversNothing
 */
class UninstallTest extends WP_UnitTestCase {
	public function test_uninstall_removes_links_settings_and_jobs(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$user_id = self::factory()->user->create();

		add_post_meta( $post_id, PostMetaTokenRepository::META_KEY, [ 'recipients' => [ 'reviewer@example.com' ] ] );
		add_post_meta( $post_id, PostMetaTokenRepository::USES_META_KEY, [ 'uses' => 1 ] );
		update_option( 'shareadraft_disabled', [ 'disabled' => true ] );
		update_option( 'ShareADraft_options', [ 1 => [] ] );
		update_option( 'shareadraft_bulk_revoke_jobs', [
			[
				'id'      => 'a',
				'creator' => 7,
			],
		] );
		update_user_meta( $user_id, 'shareadraft_links_per_page', 50 );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, LinkGarbageCollector::HOOK );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, BulkLinkRevoker::HOOK );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'shareadraft/shareadraft.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		static::assertSame( [], get_post_meta( $post_id, PostMetaTokenRepository::META_KEY, false ) );
		static::assertSame( [], get_post_meta( $post_id, PostMetaTokenRepository::USES_META_KEY, false ) );
		static::assertFalse( get_option( 'shareadraft_disabled' ) );
		static::assertFalse( get_option( 'ShareADraft_options' ) );
		static::assertFalse( get_option( 'shareadraft_bulk_revoke_jobs' ) );
		static::assertSame( '', get_user_meta( $user_id, 'shareadraft_links_per_page', true ) );
		static::assertFalse( wp_next_scheduled( LinkGarbageCollector::HOOK ) );
		static::assertFalse( wp_next_scheduled( BulkLinkRevoker::HOOK ) );
	}
}
