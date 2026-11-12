<?php

/**
 * Guards the built email templates against rendering bugs that only appear in
 * Outlook for Windows, which we cannot exercise in CI.
 *
 * Outlook for Windows renders through the Word engine, which treats a
 * `line-height` declared in px as Word's *exact* line spacing and clips
 * anything that overflows the line box. Browsers and webmail let the glyphs
 * overflow instead, so a line-height smaller than its font-size looks fine
 * everywhere except Outlook, where the text is sheared off. See ARC-1823.
 */
class EmailTemplatesTest extends WP_UnitTestCase {
	private function get_templates(): array {
		$templates = glob( dirname( __DIR__, 2 ) . '/email/templates/*.html' );
		$this->assertNotEmpty( $templates, 'No built email templates were found.' );

		return $templates;
	}

	/**
	 * Extracts every inline style attribute value from a template.
	 */
	private function get_inline_styles( string $html ): array {
		preg_match_all( '/style="([^"]*)"/i', $html, $matches );

		return $matches[1];
	}

	/**
	 * A px line-height must be at least as large as the font-size it applies
	 * to, or Outlook for Windows clips the text.
	 */
	public function test_px_line_height_is_never_smaller_than_font_size() {
		foreach ( $this->get_templates() as $template ) {
			$html = file_get_contents( $template ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$name = basename( $template );

			// Without this, an unreadable file would coerce to '' and the test
			// would pass vacuously rather than reporting the read failure.
			$this->assertIsString( $html, sprintf( 'Could not read %s.', $name ) );

			foreach ( $this->get_inline_styles( $html ) as $style ) {
				$has_font_size   = preg_match( '/font-size:\s*([\d.]+)px/i', $style, $font_size_match );
				$has_line_height = preg_match( '/line-height:\s*([\d.]+)px/i', $style, $line_height_match );

				// Only comparable when both are declared in px on the same element.
				if ( ! $has_font_size || ! $has_line_height ) {
					continue;
				}

				$font_size   = (float) $font_size_match[1];
				$line_height = (float) $line_height_match[1];

				$this->assertGreaterThanOrEqual(
					$font_size,
					$line_height,
					sprintf(
						'%s declares line-height:%spx on font-size:%spx. Outlook for Windows clips text when the line box is shorter than the font. Style: %s',
						$name,
						$line_height_match[1],
						$font_size_match[1],
						$style
					)
				);
			}
		}
	}
}
