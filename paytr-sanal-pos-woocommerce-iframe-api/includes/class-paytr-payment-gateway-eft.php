<?php

class Paytr_Payment_Gateway_Eft extends WC_Payment_Gateway {
    private PaytrCoreClass $core;

    public function __construct() {
        $this->id                   = 'paytr_payment_gateway_eft';
        $this->has_fields = true;
        $this->method_title         = __('PayTR Virtual POS WooCommerce - EFT API', 'paytr-sanal-pos-woocommerce-eft-api');
        $this->method_description   = __('Accept payments through Paytr Payment Gateway', 'paytr-sanal-pos-woocommerce-eft-api');
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
        add_action('woocommerce_receipt_' . $this->id, array($this, 'paytr_receipt_page'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'              => array(
                'title'   => __( 'Enable/Disable', 'paytr-sanal-pos-woocommerce-eft-api' ),
                'label'   => __( 'Enable PayTR Virtual POS iFrame API - EFT', 'paytr-sanal-pos-woocommerce-eft-api' ),
                'type'    => 'checkbox',
                'default' => 'no',
            ),
            'test' => array(
                'title' => __('Test Mode', 'paytr-sanal-pos-woocommerce-eft-api'),
                'label' => __('Test Mode', 'paytr-sanal-pos-woocommerce-eft-api'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title'                => array(
                'title'       => __( 'Title', 'paytr-sanal-pos-woocommerce-eft-api'),
                'type'        => 'text',
                'description' => __( 'The title your customers will see during checkout.', 'paytr-sanal-pos-woocommerce-eft-api' ),
                'default'     => __( 'Kredi \ Banka Kartı (PayTR)', 'paytr-sanal-pos-woocommerce-eft-api'),
                'desc_tip'    => true,
                'required'    => true,
            ),
            'description'          => array(
                'title'       => __( 'Description', 'paytr-sanal-pos-woocommerce-eft-api'),
                'type'        => 'textarea',
                'description' => __( 'The description your customers will see during checkout.', 'paytr-sanal-pos-woocommerce-eft-api' ),
                'desc_tip'    => false
            ),
            'logo'                 => array(
                'title'   => __( 'Logo', 'paytr-sanal-pos-woocommerce-eft-api' ),
                'label'   => __( 'Enable/Disable', 'paytr-sanal-pos-woocommerce-eft-api' ),
                'type'    => 'checkbox',
                'default' => 'no',
            ),
        );
    }

    public function paytr_receipt_page($order)
    {
        $this->core->receiptPage(
            $order, $this->settings, false);
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true),
        );
    }
}
