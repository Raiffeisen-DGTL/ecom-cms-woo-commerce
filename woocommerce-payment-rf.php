<?php
/**
 * Plugin Name: Woocommerce Raiffeisen payment gateway
 * Plugin URI:
 * Description: Raiffeisen payment API integration for Woocommerce
 * Version:     0.1
 * Author:      me
 * Author URI:
 * License:     MIT
 * License URI: https://www.gnu.org/licenses/mit.html
 * Text Domain: woocommerce_payment_rf
 * Domain Path: /languages
 * Depends:     WooCommerce
 *
 * @package woocommerce-payment-rf
 */

defined('ABSPATH') || exit;

// On Woocommerce ready.
add_action('woocommerce_init', function () {

    // Require plugin function's.
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    /**
     * Plugin data.
     *
     * @var array $data
     */
    $data = get_plugin_data(__FILE__, true, true);


    if (!defined('CLIENT_NAME')) {
        define('CLIENT_NAME', 'WordPress Woocomerce');
    }


    if (!defined('CLIENT_VERSION')) {
        define('CLIENT_VERSION', $data['Version']);
    }

    if (!class_exists('RF\Api\Client')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Client.php';
    }

    if (!class_exists('RF\Api\ClientException')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ClientException.php';
    }

    if (!class_exists('RF\Payment\Gateway')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-gateway.php';
    }

    // Ready for use or noticie broken installation.
    if (class_exists('RF\Payment\Gateway')) {

        // Add AJAX handlers
        add_action('wp_ajax_woocommerce_payment_rf_sync', ['RF\Payment\Gateway', 'ajax_sync']);

        // Add menu links.
        add_action('woocommerce_payment_rf_menu', ['RF\Payment\Gateway', 'set_menu']);
        add_action('woocommerce_payment_rf_menu_sync', ['RF\Payment\Gateway', 'set_menu_sync']);

        do_action('woocommerce_payment_rf_menu');
        do_action('woocommerce_payment_rf_menu_sync');

        // Add gateway.
        add_filter('woocommerce_payment_gateways', 'rf_add_gateway_class');
        function rf_add_gateway_class($methods)
        {
            $methods[] = 'RF\Payment\Gateway';
            return $methods;
        }
    } else {
        add_action('admin_notices', function () use ($data) {
            printf(
                'Ошибка, плагин установле неправильно',
                esc_html($data['Name']),
                esc_url($data['PluginURI'] . '#manual-installation')
            );
        });
    }
});
