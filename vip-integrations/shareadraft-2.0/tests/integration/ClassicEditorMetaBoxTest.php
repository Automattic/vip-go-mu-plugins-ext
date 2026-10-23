<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_Post;
use WP_UnitTestCase;

/**
 * The classic edit screen's "Share a Draft" meta box: offered on the same posts
 * the block-editor panel is, kept out of the block editor, and loading the
 * classic controls only when it actually renders.
 *
 * @covers \Automattic\ShareADraft\ClassicEditorMetaBox
 * @covers \Automattic\ShareADraft\EditorAssets
 */
class ClassicEditorMetaBoxTest extends WP_UnitTestCase {
	private const HANDLE = 'shareadraft-classic';

	private ClassicEditorMetaBox $box;

	public function set_up(): void {
		parent::set_up();

		set_current_screen( 'post' );
		$this->box = new ClassicEditorMetaBox( new EditorAssets() );
	}

	public function tear_down(): void {
		// remove_meta_box() would leave a `false` that blocks re-adding it.
		$GLOBALS['wp_meta_boxes'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting test state.

		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
		wp_dequeue_style( 'wp-components' );
		set_current_screen( 'front' );
		unregister_post_type( 'sad_internal' );
		parent::tear_down();
	}

	public function test_it_hooks_the_meta_box_registration(): void {
		$this->box->register();

		static::assertSame( 10, has_action( 'add_meta_boxes', [ $this->box, 'add' ] ) );
	}

	/**
	 * @dataProvider shareable_statuses
	 */
	public function test_a_draft_gets_a_back_compat_box_in_the_side_column( string $status ): void {
		$this->box->add( 'post', $this->post( 'post', $status ) );

		$box = $this->registered_box();

		static::assertIsArray( $box );
		static::assertSame( [ $this->box, 'render' ], $box['callback'] );
		// Without the flag the block editor would show it beside its own panel.
		static::assertSame( [ '__back_compat_meta_box' => true ], $box['args'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function shareable_statuses(): array {
		return [
			'draft'   => [ 'draft' ],
			'pending' => [ 'pending' ],
			'future'  => [ 'future' ],
		];
	}

	/**
	 * @dataProvider terminal_statuses
	 */
	public function test_a_post_past_sharing_gets_no_box( string $status ): void {
		$this->box->add( 'post', $this->post( 'post', $status ) );

		static::assertNull( $this->registered_box() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function terminal_statuses(): array {
		return [
			'publish' => [ 'publish' ],
			'private' => [ 'private' ],
			'trash'   => [ 'trash' ],
		];
	}

	public function test_a_post_type_with_no_front_end_view_gets_no_box(): void {
		register_post_type( 'sad_internal', [ 'public' => false ] );

		$this->box->add( 'sad_internal', $this->post( 'sad_internal', 'draft' ) );

		static::assertNull( $this->registered_box() );
	}

	public function test_a_non_post_screen_is_ignored(): void {
		// The comment screen fires the same hook with a WP_Comment.
		$this->box->add( 'comment', self::factory()->comment->create_and_get() );

		static::assertNull( $this->registered_box() );
	}

	public function test_rendering_mounts_the_controls_for_the_post(): void {
		$post = $this->post( 'post', 'draft' );

		ob_start();
		$this->box->render( $post );
		$html = (string) ob_get_clean();

		static::assertSame(
			sprintf( '<div id="shareadraft-classic" data-post-id="%d"></div>', $post->ID ),
			$html
		);
		static::assertTrue( wp_script_is( self::HANDLE ) );
		// The classic screen has no block-editor styles for the modals to lean on.
		static::assertTrue( wp_style_is( 'wp-components' ) );
	}

	public function test_the_classic_script_gets_the_same_settings_and_translations(): void {
		( new EditorAssets( true, true ) )->enqueue_classic();

		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset  = require dirname( __DIR__, 2 ) . '/build/classic.asset.php';
		$script = wp_scripts()->registered[ self::HANDLE ];

		static::assertSame( $asset['dependencies'], $script->deps );
		static::assertSame( 'shareadraft', $script->textdomain );

		/** @var mixed $before */
		$before = wp_scripts()->get_data( self::HANDLE, 'before' );
		static::assertIsArray( $before );
		$js = implode( '', array_filter( $before, 'is_string' ) );
		static::assertStringContainsString( '"hasCentralIpRanges":true', $js );
		static::assertStringContainsString( '"linksDisabled":true', $js );
	}

	private function post( string $post_type, string $status ): WP_Post {
		$args = [
			'post_type'   => $post_type,
			'post_status' => $status,
		];

		if ( 'future' === $status ) {
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}

		$post = self::factory()->post->create_and_get( $args );
		static::assertInstanceOf( WP_Post::class, $post );

		return $post;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function registered_box(): ?array {
		/** @var array<string, array<string, array<string, array<string, array<string, mixed>>>>> $wp_meta_boxes */
		$wp_meta_boxes = $GLOBALS['wp_meta_boxes'] ?? [];

		return $wp_meta_boxes['post']['side']['default']['shareadraft'] ?? null;
	}
}
