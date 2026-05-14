<?php
/**
 * Plugin Name: PayTR Virtual POS WooCommerce - iFrame API
 * Plugin URI: https://wordpress.org/plugins/paytr-sanal-pos-woocommerce-iframe-api/
 * Description: The infrastructure required to receive payments through WooCommerce with your PayTR membership.
 * Version: 3.1.2
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

// Core sınıfları yükle
require_once plugin_dir_path(__FILE__) . 'includes/PaytrCoreClass.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-paytr-log-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-paytr-error-solutions.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-paytr-log-viewer.php';

// Ödeme gateway'lerini yükle
function woocommerce_paytr_payment_gateway() {
    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

    require_once plugin_dir_path(__FILE__) . 'includes/class-paytr-payment-gateway-iframe.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-paytr-payment-gateway-eft.php';

    function add_custom_gateway_class($methods) {
        $methods[] = 'Paytr_Payment_Gateway';
        $methods[] = 'Paytr_Payment_Gateway_Eft';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_custom_gateway_class');

    // Blocks desteği (mevcut kod)
    add_action( 'woocommerce_blocks_loaded', function (){
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }
        require_once plugin_dir_path(__FILE__) . 'class-block.php';
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

// Log görüntüleme AJAX işleyicisi
function paytr_view_logs_ajax() {
    $log_viewer = new PaytrLogViewer();
    $log_viewer->display_logs_page();
}

add_action('wp_ajax_paytr_view_logs', 'paytr_view_logs_ajax');

function paytr_register_payment_attempts_meta_box() {
    add_meta_box(
        'paytr_payment_attempts',
        'PayTR Odeme Denemeleri',
        'paytr_render_payment_attempts_meta_box',
        array('shop_order', 'woocommerce_page_wc-orders'),
        'side',
        'default'
    );
}

add_action('add_meta_boxes', 'paytr_register_payment_attempts_meta_box');

function paytr_get_admin_order_from_request() {
    $order_id = 0;

    if (isset($_GET['post'])) {
        $order_id = absint($_GET['post']);
    } elseif (isset($_GET['id'])) {
        $order_id = absint($_GET['id']);
    }

    return $order_id ? wc_get_order($order_id) : false;
}

function paytr_render_refund_installment_notice_script() {
    if (!function_exists('get_current_screen') || !function_exists('wc_get_order')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, array('shop_order', 'woocommerce_page_wc-orders'), true)) {
        return;
    }

    $order = paytr_get_admin_order_from_request();
    if (!$order || $order->get_payment_method() !== 'paytr_payment_gateway') {
        return;
    }

    $notice = 'Vade farkı PayTR sistemi tarafından otomatik olarak iade tutarına eklenmektedir. Vade farkı iadesi iFrame ile sağlanamaz. Vade farkını el ile iade etmeniz gerekmektedir.';
    ?>
    <script>
        (function() {
            var noticeText = <?php echo wp_json_encode($notice); ?>;

            function insertPaytrRefundNotice() {
                var containers = document.querySelectorAll('.wc-order-refund-items');

                containers.forEach(function(container) {
                    if (container.querySelector('.paytr-refund-installment-notice')) {
                        return;
                    }

                    var notice = document.createElement('div');
                    notice.className = 'notice notice-warning paytr-refund-installment-notice';
                    notice.style.margin = '12px 0';
                    notice.innerHTML = '<p><strong>PayTR vade farkı iadesi:</strong> ' + noticeText + '</p>';

                    var target = container.querySelector('.refund-actions') || container.firstChild;
                    if (target) {
                        container.insertBefore(notice, target);
                    } else {
                        container.appendChild(notice);
                    }
                });
            }

            document.addEventListener('click', function(event) {
                if (event.target.closest && event.target.closest('.refund-items')) {
                    setTimeout(insertPaytrRefundNotice, 100);
                }
            });

            if (document.body) {
                new MutationObserver(insertPaytrRefundNotice).observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }

            insertPaytrRefundNotice();
        })();
    </script>
    <?php
}

add_action('admin_footer', 'paytr_render_refund_installment_notice_script');

function paytr_render_payment_attempts_meta_box($post) {
    $order = $post instanceof WC_Order ? $post : wc_get_order($post->ID);

    if (!$order) {
        echo '<p>Siparis bulunamadi.</p>';
        return;
    }

    $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());
    $test_mode_enabled = isset($settings['test']) && $settings['test'] === 'yes';
    $active_order_id = $order->get_meta('paytr_order_id');
    $attempts = $order->get_meta('paytr_payment_attempts');

    if (!is_array($attempts)) {
        $attempts = array();
    }

    echo '<p><strong>Aktif Mod:</strong> ' . ($test_mode_enabled ? 'Test' : 'Canli') . '</p>';

    if ($active_order_id) {
        echo '<p><strong>Aktif PayTR Order ID:</strong><br><code>' . esc_html($active_order_id) . '</code></p>';
    }

    if (empty($attempts)) {
        echo '<p>Bu siparis icin kayitli PayTR odeme denemesi yok.</p>';
        return;
    }

    echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
    echo '<thead><tr><th style="text-align:left;border-bottom:1px solid #ddd;">Tarih</th><th style="text-align:left;border-bottom:1px solid #ddd;">Durum</th></tr></thead><tbody>';

    foreach (array_reverse($attempts) as $attempt) {
        $merchant_oid = isset($attempt['merchant_oid']) ? $attempt['merchant_oid'] : '';
        $status = isset($attempt['status']) ? $attempt['status'] : '';
        $created_at = isset($attempt['created_at']) ? $attempt['created_at'] : '';
        $amount = isset($attempt['amount']) ? (float) $attempt['amount'] / 100 : 0;
        $currency = isset($attempt['currency']) ? $attempt['currency'] : $order->get_currency();
        $mode = !empty($attempt['test_mode']) ? 'Test' : 'Canli';
        $reason = isset($attempt['reason']) ? $attempt['reason'] : '';
        $http_code = isset($attempt['http_code']) ? intval($attempt['http_code']) : 0;
        $callback_status = isset($attempt['callback_status']) ? $attempt['callback_status'] : '';
        $callback_at = isset($attempt['callback_at']) ? $attempt['callback_at'] : '';

        echo '<tr><td style="padding:8px 0;border-bottom:1px solid #eee;" colspan="2">';
        echo '<strong>' . esc_html($created_at) . '</strong> - ' . esc_html($status) . '<br>';
        echo '<code style="word-break:break-all;">' . esc_html($merchant_oid) . '</code><br>';
        echo 'Tutar: ' . esc_html(number_format($amount, 2, '.', '')) . ' ' . esc_html($currency) . ' | Mod: ' . esc_html($mode);

        if ($http_code) {
            echo ' | HTTP: ' . esc_html($http_code);
        }

        if ($reason) {
            echo '<br>Hata: ' . esc_html($reason);
        }

        if ($callback_status) {
            echo '<br>Callback: ' . esc_html($callback_status);
            if ($callback_at) {
                echo ' (' . esc_html($callback_at) . ')';
            }
        }

        echo '</td></tr>';
    }

    echo '</tbody></table>';
}
