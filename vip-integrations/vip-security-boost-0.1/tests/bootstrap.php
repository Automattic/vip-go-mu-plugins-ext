<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/class-speedup-isolated-wp-tests.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

require_once __DIR__ . '/../mu-plugins/000-pre-vip-config/requires.php';

function _manually_load_plugin() {
	require_once __DIR__ . '/../mu-plugins/000-pre-vip-config/requires.php';
	require_once __DIR__ . '/../mu-plugins/lib/helpers/php-compat.php';
	require_once __DIR__ . '/../mu-plugins/000-vip-init.php';
	require_once __DIR__ . '/../mu-plugins/001-core.php';
	require_once __DIR__ . '/../mu-plugins/a8c-files.php';

	require_once __DIR__ . '/../mu-plugins/performance.php';

	require_once __DIR__ . '/../mu-plugins/security.php';

	require_once __DIR__ . '/../mu-plugins/schema.php';

	require_once __DIR__ . '/../mu-plugins/vip-jetpack/vip-jetpack.php';

	// Proxy lib
	// require_once __DIR__ . '/proxy-helpers.php'; // Needs to be included before ip-forward.php

	require_once __DIR__ . '/../mu-plugins/lib/proxy/ip-forward.php';
	require_once __DIR__ . '/../mu-plugins/lib/proxy/class-iputils.php';

	require_once __DIR__ . '/../mu-plugins/two-factor.php';

	require_once __DIR__ . '/../mu-plugins/vip-cache-manager.php';
	require_once __DIR__ . '/../mu-plugins/vip-mail.php';
	require_once __DIR__ . '/../mu-plugins/vip-rest-api.php';
	require_once __DIR__ . '/../mu-plugins/vip-plugins.php';

	require_once __DIR__ . '/../mu-plugins/wp-cli.php';

	require_once __DIR__ . '/../mu-plugins/z-client-mu-plugins.php';

}

/**
 * VIP Cache Manager can potentially pollute other tests,
 * So we explicitly unhook the init callback.
 *
 */
function _remove_init_hook_for_cache_manager() {
	remove_action( 'init', array( WPCOM_VIP_Cache_Manager::instance(), 'init' ) );
}

/**
 * Core functionality causes `WP_Block_Type_Registry::register was called <strong>incorrectly</strong>. Block type "core/legacy-widget" is already registered.
 *
 * Temporarily unhook it.
 *
 * @return void
 */
function _disable_core_legacy_widget_registration() {
	remove_action( 'init', 'register_block_core_legacy_widget', 20 );
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
tests_add_filter( 'muplugins_loaded', 'wpcom_enable_two_factor_plugin' );
tests_add_filter( 'muplugins_loaded', '_remove_init_hook_for_cache_manager' );
tests_add_filter( 'muplugins_loaded', '_disable_core_legacy_widget_registration' );

// Disable calls to wordpress.org to get translations
tests_add_filter( 'translations_api', function ( $res ) {
	if ( false === $res ) {
		$res = [ 'translations' => [] ];
	}

	return $res;
} );


require $_tests_dir . '/includes/bootstrap.php';