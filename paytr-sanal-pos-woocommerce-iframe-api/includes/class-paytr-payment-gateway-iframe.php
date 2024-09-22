<?php

class Paytr_Payment_Gateway extends WC_Payment_Gateway {
    public $paytr_installment_list;
    private PaytrCoreClass $core;

    public function __construct() {
        $this->id                   = 'paytr_payment_gateway';
        $this->has_fields           =  true;
        $this->method_title         = __('PayTR Virtual POS WooCommerce - iFrame API', 'paytr-sanal-pos-woocommerce-iframe-api');
        $this->method_description   = __('Accept payments through Paytr Payment Gateway', 'paytr-sanal-pos-woocommerce-iframe-api');
        $this->supports             = array(
            'products',
            'refunds',
        );
        $this->core                 = new PaytrCoreClass();
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled = $this->get_option( 'enabled' );
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'paytr_receipt_page'));
        add_action('woocommerce_api_wc_gateway_paytrcheckout', array($this, 'paytr_checkout_response'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(
            $this,
            'plugin_action_links'
        ));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        $get_pspi_options = get_option('woocommerce_paytr_payment_gateway_settings');

        if ($get_pspi_options != '' && $get_pspi_options['logo'] === 'yes') {
            add_action('wp_enqueue_scripts', array($this, 'add_paytr_payment_style'));
        }
    }

    public function plugin_action_links($links)
    {
        $plugin_links = array('<a href="admin.php?page=wc-settings&tab=checkout&section=paytr_payment_gateway">' . esc_html__('Settings', 'paytr-sanal-pos-woocommerce-iframe-api') . '</a>');

        return array_merge($plugin_links, $links);
    }

    public function plugin_row_meta($links, $file)
    {
        if (plugin_basename(__FILE__) === $file) {
            $row_meta = array(
                'support' => '<a href="' . esc_url(apply_filters('paytrspi_support_url', 'https://www.paytr.com/magaza/destek')) . '" target="_blank">' . __('Support', 'paytr-sanal-pos-woocommerce-iframe-api') . '</a>'
            );

            return array_merge($links, $row_meta);
        }

        return (array)$links;
    }

    public function add_paytr_payment_style()
    {
        wp_register_style('paytr-payment-gateway', PAYTRSPI_PLUGIN_URL_2 . '/assets/css/paytr-sanal-pos-iframe-style.css');
        wp_enqueue_style('paytr-payment-gateway');
    }

    function init_form_fields()
    {
        $this->form_fields = array(
            'callback' => array(
                'title' => __('Callback URL', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'title',
                'description' => sprintf(__('You must add the following callback url <strong>%s</strong> to your <a href="https://www.paytr.com/magaza/ayarlar" target="_blank">Callback URL Settings.</a>'), get_home_url() . '/index.php?wc-api=wc_gateway_paytrcheckout')
            ),
            'enabled' => array(
                'title' => __('Enable/Disable', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'label' => __('Enable PayTR Virtual POS iFrame API', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'test' => array(
                'title' => __('Test Mode', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'label' => __('Test Mode', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'text',
                'description' => __('The title your customers will see during checkout.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'default' => __('Kredi \ Banka Kartı (PayTR)'),
                'desc_tip' => true,
                'required' => true,
            ),
            'description' => array(
                'title' => __('Description', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'textarea',
                'description' => __('The description your customers will see during checkout.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'default' => __("Bu ödeme yöntemini seçtiğinizde Tüm Kredi Kartlarına taksit imkanı bulunmaktadır.", 'paytr-sanal-pos-woocommerce-iframe-api'),
                'desc_tip' => true
            ),
            'logo' => array(
                'title' => __('Logo', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'label' => __('Enable/Disable', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'paytr_merchant_id' => array(
                'title' => __('Merchant ID', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'text',
                'description' => __('You will find this value under the PayTR Merchant Panel > Information Tab.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'desc_tip' => true,
                'required' => true,
            ),
            'paytr_merchant_key' => array(
                'title' => __('Merchant Key', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'text',
                'description' => __('You will find this value under the PayTR Merchant Panel > Information Tab.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'desc_tip' => true,
                'required' => true,
            ),
            'paytr_merchant_salt' => array(
                'title' => __('Merchant Salt', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'text',
                'description' => __('You will find this value under the PayTR Merchant Panel > Information Tab.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'desc_tip' => true,
                'required' => true,
            ),
            'paytr_order_status' => array(
                'title' => __('Order Status', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'description' => __('Order status when payment is successful. Recommended processing.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'desc_tip' => true,
                'default' => 'wc-processing',
                'options' => wc_get_order_statuses(),

            ),
            'paytr_ins_difference' => array(
                'title' => __('Installment Difference', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'label' => __('Enable/Disable', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'checkbox',
                'description' => __('When payment completed with the installment then adds Installment Difference to the order as a fee and recalculates the order total.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'desc_tip' => true,
                'default' => 'no'
            ),
            'paytr_lang' => array(
                'title' => __('Language', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'default' => '0',
                'options' => array(
                    '0' => __('Automatic', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '1' => __('Turkish', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '2' => __('English', 'paytr-sanal-pos-woocommerce-iframe-api'),
                ),
            ),
            'paytr_installment' => array(
                'title' => __('Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'default' => '0',
                'options' => array(
                    '0' => __('All Installment Options', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '1' => __('One Shot (No Installment)', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '2' => __('Up to 2 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '3' => __('Up to 3 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '4' => __('Up to 4 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '5' => __('Up to 5 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '6' => __('Up to 6 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '7' => __('Up to 7 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '8' => __('Up to 8 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '9' => __('Up to 9 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '10' => __('Up to 10 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '11' => __('Up to 11 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '12' => __('Up to 12 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '13' => __('Category Based', 'paytr-sanal-pos-woocommerce-iframe-api'),
                ),

            ),
        );

        if ($this->get_option('paytr_installment') == 13) {
            $installment_arr = array(
                '0' => __('All Installment Options', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '1' => __('One Shot (No Installment)', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '2' => __('Up to 2 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '3' => __('Up to 3 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '4' => __('Up to 4 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '5' => __('Up to 5 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '6' => __('Up to 6 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '7' => __('Up to 7 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '8' => __('Up to 8 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '9' => __('Up to 9 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '10' => __('Up to 10 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '11' => __('Up to 11 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
                '12' => __('Up to 12 Installment', 'paytr-sanal-pos-woocommerce-iframe-api'),
            );

            $finish = array();
            $this->core->categoryParserClear($this->core->categoryParser(), 0, array(), $finish);

            foreach ($finish as $key => $item) {
                $this->form_fields['paytr_installment_cat_' . $key] = array(
                    'title' => __($item, 'paytr-sanal-pos-woocommerce-iframe-api'),
                    'type' => 'select',
                    'default' => '0',
                    'options' => $installment_arr,
                );

                $this->paytr_installment_list[$key] = ($this->get_option('paytr_installment_cat_' . $key) ? $this->get_option('paytr_installment_cat_' . $key) : 0);
            }
        }
    }

    public function paytr_receipt_page($order)
    {
        $this->core->receiptPage($order, $this->settings);
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }

    function paytr_checkout_response()
    {
        if (empty($_POST)) {
            die();
        }
        require_once plugin_dir_path(__FILE__) . '/class-paytrspi-callback-iframe.php';
        PaytrCheckoutCallbackIframe::callback_iframe($_POST);
    }

    function process_refund($order_id, $amount = null, $reason = '')
    {
        return $this->core->processRefundPaytr($order_id, $amount, $reason);
    }
}
