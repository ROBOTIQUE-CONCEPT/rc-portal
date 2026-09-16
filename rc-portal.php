<?php
/**
 * Plugin Name: RC Portal
 * Description: Robotique Concept business runtime and embedded business module host for the my site.
 * Version: 0.3.0-alpha9
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author: Robotique Concept
 * Text Domain: rc-portal
 * Network: false
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RC_PORTAL_VERSION', '0.3.0-alpha9');
define('RC_PORTAL_MIN_CORE_VERSION', '0.6.0-alpha10');
define('RC_PORTAL_UI_API_VERSION', 1);
define('RC_PORTAL_FILE', __FILE__);
define('RC_PORTAL_DIR', __DIR__ . '/');

require_once RC_PORTAL_DIR . 'src/Autoloader.php';

\RC\Portal\Autoloader::register();

register_activation_hook(
    __FILE__,
    static function (bool $network_wide): void {
        if ($network_wide) {
            deactivate_plugins(plugin_basename(__FILE__), true, true);
            wp_die(
                esc_html__('RC Portal doit être activé uniquement sur le site applicatif, jamais sur l’ensemble du réseau.', 'rc-portal'),
                esc_html__('Activation de RC Portal bloquée', 'rc-portal'),
                ['back_link' => true]
            );
        }

        if (! function_exists('rc_core') || ! defined('RC_CORE_VERSION')) {
            wp_die(
                esc_html__('RC Core doit être actif avant l’activation de RC Portal.', 'rc-portal'),
                esc_html__('RC Core manquant', 'rc-portal'),
                ['back_link' => true]
            );
        }

        if (version_compare((string) RC_CORE_VERSION, RC_PORTAL_MIN_CORE_VERSION, '<')) {
            wp_die(
                esc_html(
                    sprintf(
                        /* translators: 1: required Core version, 2: installed Core version. */
                        __('RC Portal nécessite RC Core %1$s ou une version plus récente. Version installée : %2$s.', 'rc-portal'),
                        RC_PORTAL_MIN_CORE_VERSION,
                        (string) RC_CORE_VERSION
                    )
                ),
                esc_html__('Version de RC Core incompatible', 'rc-portal'),
                ['back_link' => true]
            );
        }

        \RC\Portal\Plugin::activate();
    }
);

register_deactivation_hook(__FILE__, [\RC\Portal\Plugin::class, 'deactivate']);

add_action(
    'plugins_loaded',
    static function (): void {
        \RC\Portal\Plugin::instance()->boot();
    },
    20
);

if (! function_exists('rc_portal')) {
    /**
     * Returns the RC Portal application instance.
     */
    function rc_portal(): \RC\Portal\Plugin
    {
        return \RC\Portal\Plugin::instance();
    }
}
