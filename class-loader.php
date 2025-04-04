<?php
namespace Automattic\VIP\Security;

class Loader {
	public static function init() {
        if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
            throw new \Exception( 'VIP_SECURITY_BOOST_CONFIGS is not defined.' );
        }

        $configs = constant( 'VIP_SECURITY_BOOST_CONFIGS' );
        $enabled_modules = $configs['enabled_modules'] ?? [];

        foreach ( $enabled_modules as $module ) {
            $load_path = __DIR__ . '/modules/' . $module . '/' . $module . '.php';

            if ( file_exists( $load_path ) ) {
                require_once $load_path;
            } else {
                trigger_error( 'Module not found: ' . $module, E_USER_WARNING );
            }
        }
    }
}

Loader::init();
