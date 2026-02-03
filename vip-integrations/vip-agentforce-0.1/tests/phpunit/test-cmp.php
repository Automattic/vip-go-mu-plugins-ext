<?php

use Automattic\VIP\Salesforce\Agentforce\Cmp\Agentforce;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Assets;
use Automattic\VIP\Salesforce\Agentforce\Cmp\Settings_Page;
use Automattic\VIP\Salesforce\Agentforce\Constants;
use Automattic\VIP\Salesforce\Agentforce\Utils\Configs;

class Cmp_Tests extends WP_UnitTestCase {

	/**
	 * Dequeue and deregister consent scripts to keep tests isolated.
	 *
	 * @param string $handle Script handle.
	 */
	private function reset_consent_script( string $handle ): void {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}

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

	public function tearDown(): void {
		delete_option( 'vip_agentforce_consent_type' );
		delete_option( 'vip_agentforce_onetrust_group_id' );
		delete_option( 'vip_agentforce_cookiebot_category' );
		delete_option( 'vip_agentforce_iubenda_category' );
		delete_option( 'vip_agentforce_alignment' );
		delete_option( 'vip_agentforce_custom_css' );
		delete_option( 'vip_agentforce_enable_oplog' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );
		$this->reset_consent_script( 'vip-af-cookiebot-consent' );
		$this->reset_consent_script( 'vip-af-onetrust-consent' );
		$this->reset_consent_script( 'vip-af-iubenda-consent' );
		$this->reset_consent_script( 'vip-af-custom-consent' );

		Configs::flush_cache();

		parent::tearDown();
	}

	public function test_consent_script_not_enqueued_when_sdk_disabled(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => false,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertFalse(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should not enqueue if SDK is not activated.'
		);
	}

	public function test_cookieyes_script_enqueued_with_localized_sdk_url(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieYes' );

		$this->reset_consent_script( 'vip-af-cookieyes-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$this->assertTrue(
			wp_script_is( 'vip-af-cookieyes-consent', 'enqueued' ),
			'Consent script should enqueue when SDK is activated.'
		);

		$localized_data = wp_scripts()->get_data( 'vip-af-cookieyes-consent', 'data' );
		// WP Version 6.9 changed how the data is returned.
		if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
			$this->assertStringContainsString( '"sdkUrl":"https:\/\/example.local"', $localized_data );
		} else {
			$this->assertStringContainsString( '"sdkUrl":"https://example.local"', $localized_data );
		}
	}

	public function test_supported_cmp_asset_files_are_present(): void {
		$integration_path = dirname( VIP_AGENTFORCE_FILE );

		foreach ( Constants::SUPPORTED_CMPS as $cmp ) {

			$consent_script_filename_no_ext = 'cmp' . strtolower( $cmp );
			$asset_file                     = $integration_path . '/assets/build/js/' . $consent_script_filename_no_ext . '.asset.php';

			$this->assertFileExists(
				$asset_file,
				sprintf( 'Missing consent asset PHP file for CMP "%s": %s', $cmp, $asset_file )
			);
			$this->assertTrue(
				is_readable( $asset_file ),
				sprintf( 'Consent asset PHP file is not readable for CMP "%s": %s', $cmp, $asset_file )
			);

			$library_asset_file = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

			$this->assertIsArray( $library_asset_file, sprintf( 'Consent asset PHP file did not return an array for CMP "%s".', $cmp ) );
			$this->assertArrayHasKey( 'dependencies', $library_asset_file );
			$this->assertIsArray( $library_asset_file['dependencies'] );
			$this->assertArrayHasKey( 'version', $library_asset_file );
		}
	}

	public function test_onetrust_localization_uses_default_group(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'OneTrust' );
		delete_option( 'vip_agentforce_onetrust_group_id' );

		$this->reset_consent_script( 'vip-af-onetrust-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-onetrust-consent', 'data' );
		$this->assertStringContainsString( '"groupId":"' . Constants::DEFAULT_ONETRUST_GROUP_ID . '"', $localized_data );
	}

	public function test_cookiebot_localization_uses_default_category(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'CookieBot' );
		delete_option( 'vip_agentforce_cookiebot_category' );

		$this->reset_consent_script( 'vip-af-cookiebot-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-cookiebot-consent', 'data' );
		$this->assertStringContainsString( '"cookiebotCategory":"' . Constants::DEFAULT_COOKIEBOT_CATEGORY . '"', $localized_data );
	}

	public function test_iubenda_localization_uses_default_purpose_id(): void {
		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		update_option( 'vip_agentforce_consent_type', 'iubenda' );
		delete_option( 'vip_agentforce_iubenda_category' );

		$this->reset_consent_script( 'vip-af-iubenda-consent' );

		Assets::get_instance()->enqueue_consent_scripts();

		$localized_data = wp_scripts()->get_data( 'vip-af-iubenda-consent', 'data' );
		$this->assertStringContainsString( '"iubendaPurposeId":"' . Constants::DEFAULT_IUBENDA_PURPOSE_ID . '"', $localized_data );
	}

	public function test_sanitize_consent_type_returns_value_for_supported_cmp(): void {
		$settings = Settings_Page::get_instance();

		$this->assertSame( 'CookieBot', $settings->sanitize_consent_type( 'CookieBot' ) );
	}

	public function test_sanitize_consent_type_falls_back_to_default_for_invalid_value(): void {
		$settings = Settings_Page::get_instance();

		$this->assertSame( Constants::DEFAULT_CMP, $settings->sanitize_consent_type( 'InvalidCMP' ) );
		$this->assertSame( Constants::DEFAULT_CMP, $settings->sanitize_consent_type( 'onetrust' ) );
	}

	public function test_sdk_activation_status_is_readonly_and_reflects_config(): void {
		$settings = Settings_Page::get_instance();

		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => false,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		ob_start();
		$settings->render_enable_sdk_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="agentforce-sdk-activation-status"', $output );
		$this->assertStringContainsString( 'data-status="inactive"', $output );
		$this->assertStringNotContainsString( '<input', $output );
	}

	public function test_sdk_url_is_readonly_and_reflects_config(): void {
		$settings = Settings_Page::get_instance();

		$this->prime_configs_cache(
			[
				'agentforce_js_sdk_activated' => true,
				'agentforce_js_sdk_url'       => 'https://example.local',
			]
		);

		ob_start();
		$settings->render_sdk_url_field();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="agentforce-sdk-url"', $output );
		$this->assertStringContainsString( 'data-url="https://example.local"', $output );
		$this->assertStringNotContainsString( 'name="agentforce_salesforce_sdk_url"', $output );
	}

	public function test_render_custom_css_includes_alignment_and_sanitizes_css(): void {
		update_option( 'vip_agentforce_alignment', 'bottom-left' );
		update_option( 'vip_agentforce_custom_css', 'body { color: red; }' );

		ob_start();
		Agentforce::get_instance()->render_custom_css();
		$output = ob_get_clean();

		$this->assertStringContainsString( '.embedded-messaging > .embeddedMessagingFrame { left: 10px }', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringContainsString( 'style id="agentforce-custom-css">body { color: red; }</style>', $output );
	}

	public function test_validation_returns_old_values_on_invalid_input(): void {
		$settings = Settings_Page::get_instance();

		update_option( 'vip_agentforce_iubenda_category', '3' );

		$this->assertSame(
			'3',
			$settings->validate_iubenda_category( '0' )
		);
	}
}
