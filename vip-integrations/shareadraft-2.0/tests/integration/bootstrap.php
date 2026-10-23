<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// The VIP Telemetry API ships with the platform's MU plugins and is absent
// in bare PHPUnit runs; load a test double so the helper can be asserted.
require_once __DIR__ . '/stubs/class-vip-telemetry.php';

$_tests_dir = (string) getenv( 'WP_TESTS_DIR' );

if ( '' === $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI
	throw new Exception( "Could not find {$_tests_dir}/includes/functions.php" ); // NOSONAR
}

if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', 'empty' );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

if ( ! defined( 'VIP_SHAREADRAFT_CONFIG' ) ) {
	// Mirror the VIP platform: runtime config is defined before the plugin loads.
	define( 'VIP_SHAREADRAFT_CONFIG', require __DIR__ . '/../../fixtures/config-valid.php' );
}

function _manually_load_plugin(): void {
	require_once __DIR__ . '/../../shareadraft.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require_once $_tests_dir . '/includes/bootstrap.php';

/** @var string $wp_version */
global $wp_version;
echo 'WP Version: ', esc_html( $wp_version ), PHP_EOL;
