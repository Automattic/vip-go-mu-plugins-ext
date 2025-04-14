<?php
namespace Automattic\VIP\Security\Utils;

/**
 * Get the module configs for a given module name.
 *
 * Attempts to retrieve configuration for a specific security module.
 * Configuration is expected to be stored within the VIP_SECURITY_BOOST_CONFIGS constant.
 * Handles cases where the configuration might be stored as a JSON string.
 *
 * @param string $module_name The name of the module to get the configs for.
 * @return array The module configs. Returns an empty array if configs are not found,
 * not defined, or if JSON parsing fails.
 */
function get_module_configs( $module_name ) {
    if ( ! defined( 'VIP_SECURITY_BOOST_CONFIGS' ) ) {
        trigger_error( 'VIP_SECURITY_BOOST_CONFIGS is not defined.', E_USER_WARNING );
        return [];
    }

    $configs = constant( 'VIP_SECURITY_BOOST_CONFIGS' );

    if ( ! is_array( $configs ) || ! isset( $configs[ 'module_configs' ] ) ) {
        return [];
    }

    $module_configs = $configs[ 'module_configs' ];
    $current_module_config = [];

    if ( is_array( $module_configs ) && isset( $module_configs[ $module_name ] ) ) {
        $current_module_config = $module_configs[ $module_name ];

        if ( is_string( $current_module_config ) ) {
            $decoded_config = json_decode( $current_module_config, true );

            if ( is_null( $decoded_config ) && json_last_error() !== JSON_ERROR_NONE ) {
                trigger_error(
                    'Failed to decode JSON configuration for module: ' . $module_name . '. Error (' . json_last_error() . '): ' . json_last_error_msg(),
                    E_USER_WARNING
                );
                return [];
            }
            $current_module_config = $decoded_config;
        }
    }

    if ( ! is_array( $current_module_config ) ) {
        trigger_error(
            'Module configuration for ' . $module_name . ' resolved to a non-array type after processing. Returning empty array.',
            E_USER_NOTICE
        );
        return [];
    }

    return $current_module_config;
}
