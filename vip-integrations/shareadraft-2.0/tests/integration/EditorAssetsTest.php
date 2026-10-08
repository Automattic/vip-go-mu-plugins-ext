<?php
declare(strict_types = 1);

namespace Automattic\ShareADraft;

use WP_UnitTestCase;

/**
 * The enqueue of the block-editor script and, above all, the inline
 * `window.shareADraft` settings object it hands the editor. That object is
 * the contract src/index.js consumes, so its shape is pinned here.
 *
 * @covers \Automattic\ShareADraft\EditorAssets
 */
class EditorAssetsTest extends WP_UnitTestCase {
	/**
	 * The script handle. Named as a literal on purpose: src/index.js is enqueued
	 * and translated under this handle, so a rename should fail a test.
	 */
	private const HANDLE = 'shareadraft-editor';

	public function tear_down(): void {
		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
		parent::tear_down();
	}

	public function test_it_hooks_the_block_editor_enqueue(): void {
		$assets = new EditorAssets();
		$assets->register();

		static::assertSame( 10, has_action( 'enqueue_block_editor_assets', [ $assets, 'enqueue' ] ) );
	}

	public function test_the_script_is_enqueued_with_its_built_dependencies(): void {
		( new EditorAssets() )->enqueue();

		static::assertTrue( wp_script_is( self::HANDLE ) );

		/** @var array{dependencies: list<string>, version: string} $asset */
		$asset  = require dirname( __DIR__, 2 ) . '/build/index.asset.php';
		$script = wp_scripts()->registered[ self::HANDLE ];

		static::assertSame( $asset['dependencies'], $script->deps );
		static::assertSame( $asset['version'], $script->ver );
		static::assertSame( 'shareadraft', $script->textdomain );
	}

	public function test_the_inline_settings_carry_the_endpoint_contract(): void {
		( new EditorAssets() )->enqueue();

		$data = $this->inline_settings();

		static::assertSame( PreviewRestController::default_expiration(), $data['defaultExpiration'] );
		static::assertSame( PreviewRestController::max_uses_limit(), $data['maxUsesLimit'] );
		static::assertSame( PreviewRestController::expiration_options(), $data['expirationOptions'] );
	}

	/**
	 * The two flags are what the modals phrase their warnings around, so both
	 * ends of each must round-trip.
	 */
	public function test_the_flags_default_to_off(): void {
		( new EditorAssets() )->enqueue();

		$data = $this->inline_settings();

		static::assertFalse( $data['hasCentralIpRanges'] );
		static::assertFalse( $data['linksDisabled'] );
	}

	public function test_the_flags_reach_the_editor_when_set(): void {
		( new EditorAssets( true, true ) )->enqueue();

		$data = $this->inline_settings();

		static::assertTrue( $data['hasCentralIpRanges'] );
		static::assertTrue( $data['linksDisabled'] );
	}

	/**
	 * Decode the `window.shareADraft` object from the script's before-data.
	 *
	 * @return array<string, mixed>
	 */
	private function inline_settings(): array {
		/** @var mixed $before */
		$before = wp_scripts()->get_data( self::HANDLE, 'before' );

		static::assertIsArray( $before );

		$js = implode( '', array_filter( $before, 'is_string' ) );

		static::assertSame( 1, preg_match( '/window\.shareADraft = (?<json>.*);/s', $js, $matches ) );

		/** @var mixed $data */
		$data = json_decode( $matches['json'], true );

		static::assertIsArray( $data );

		/** @var array<string, mixed> $data */
		return $data;
	}
}
