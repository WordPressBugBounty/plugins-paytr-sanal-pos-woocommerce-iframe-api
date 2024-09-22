<?php
/**
 * Plugin Name: PayTR Virtual POS WooCommerce - iFrame API
 * Plugin URI: https://wordpress.org/plugins/paytr-sanal-pos-woocommerce-iframe-api/
 * Description: The infrastructure required to receive payments through WooCommerce with your PayTR membership.
 * Version: 3.0.3
 * Author: PayTR Ödeme ve Elektronik Para Kuruluşu A.Ş.
 * Author URI: http://www.paytr.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: paytr-sanal-pos-woocommerce-iframe-api
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
};

define('PAYTRSPI_PLUGIN_URL_2', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));

require_once plugin_dir_path(__FILE__) . 'includes/PaytrCoreClass.php';

function woocommerce_paytr_payment_gateway()
{
    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

    require_once plugin_dir_path(__FILE__) . 'includes/class-paytr-payment-gateway-iframe.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-paytr-payment-gateway-eft.php';

    function add_custom_gateway_class($methods) {
        $methods[] = 'Paytr_Payment_Gateway';
        $methods[] = 'Paytr_Payment_Gateway_Eft';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_custom_gateway_class');

    add_action( 'woocommerce_blocks_loaded', function (){
        // Check if the required class exists
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }

        // Include Blocks Checkout class
        require_once plugin_dir_path(__FILE__) . 'class-block.php';

        // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new Paytr_Gateway_Blocks );
            }
        );
    });

    add_action('before_woocommerce_init', function() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil'))
        {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    });
}

add_action('plugins_loaded', 'woocommerce_paytr_payment_gateway', 0);
