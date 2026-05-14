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
        add_action('admin_notices', array($this, 'display_test_mode_notice'));
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
            'error_logs' => array(
            'title' => __('Hata Geçmişi', 'paytr-sanal-pos-woocommerce-iframe-api'),
            'type' => 'title',
            'description' => $this->get_logs_viewer_html(),
        ),
            'mode_status' => array(
                'title' => __('Canli/Test Modu', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'title',
                'description' => $this->get_mode_warning_html(),
            ),

			'iframe_theme' => array(
    		'title' => __('Dark Mode', 'paytr-sanal-pos-woocommerce-iframe-api'),
    		'label' => __('Enable Dark Theme', 'paytr-sanal-pos-woocommerce-iframe-api'),
    		'type' => 'checkbox',
    		'default' => 'no',
            'desc_tip' => true,
    		'description' => __('Enable dark theme for payment page', 'paytr-sanal-pos-woocommerce-iframe-api')
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
            'paytr_log_retention_days' => array(
                'title' => __('Log Saklama Suresi', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'default' => '7',
                'description' => __('PayTR hata gunluklerinin otomatik temizleme suresini belirler.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'options' => array(
                    '7' => __('7 gun', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '14' => __('14 gun', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '30' => __('30 gun', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '60' => __('60 gun', 'paytr-sanal-pos-woocommerce-iframe-api'),
                ),
            ),
            'paytr_log_level' => array(
                'title' => __('Log Seviyesi', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'default' => 'errors',
                'description' => __('Yuksek trafikli magazalarda varsayilan olarak sadece hatalarin loglanmasi onerilir.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'options' => array(
                    'off' => __('Kapali', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    'errors' => __('Sadece hatalar', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    'refunds' => __('Hatalar + iadeler', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    'all' => __('Tum PayTR olaylari', 'paytr-sanal-pos-woocommerce-iframe-api'),
                ),
            ),
            'paytr_log_max_file_size_mb' => array(
                'title' => __('Gunluk Log Dosyasi Limiti', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'default' => '5',
                'description' => __('Gunluk log dosyasi bu limite ulasinca ayni gun icin yeni dosyada olusturulmaya devam eder.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'options' => array(
                    '1' => __('1 MB', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '5' => __('5 MB', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '10' => __('10 MB', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '25' => __('25 MB', 'paytr-sanal-pos-woocommerce-iframe-api'),
                ),
            ),
            'paytr_error_email_alerts' => array(
                'title' => __('Hata E-posta Bildirimi', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'default' => 'yes',
                'description' => __('Ayni hata 30 dakika icinde 3 kez kaydedilirse WordPress admin e-posta adresine bildirim gonderilir.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'options' => array(
                    'yes' => __('Aktif', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    'no' => __('Kapali', 'paytr-sanal-pos-woocommerce-iframe-api'),
                ),
            ),
            'paytr_error_email_hourly_limit' => array(
                'title' => __('Saatlik E-posta Limiti', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'type' => 'select',
                'default' => '5',
                'description' => __('Hata e-posta bildirimi aktifken bir saat icinde en fazla kac bildirim gonderilecegini belirler.', 'paytr-sanal-pos-woocommerce-iframe-api'),
                'options' => array(
                    '1' => __('Saatte en fazla 1 e-posta', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '2' => __('Saatte en fazla 2 e-posta', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '3' => __('Saatte en fazla 3 e-posta', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '4' => __('Saatte en fazla 4 e-posta', 'paytr-sanal-pos-woocommerce-iframe-api'),
                    '5' => __('Saatte en fazla 5 e-posta', 'paytr-sanal-pos-woocommerce-iframe-api'),
                ),
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
            'logo'  => array(
                'title'   => __( 'Logo', 'paytr-sanal-pos-woocommerce-iframe-api' ),
                'label'   => __( 'Enable/Disable', 'paytr-sanal-pos-woocommerce-iframe-api' ),
                'type'    => 'checkbox',
                'default' => 'yes',
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
private function get_logs_viewer_html() {
        $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());
        $retention_days = isset($settings['paytr_log_retention_days']) ? intval($settings['paytr_log_retention_days']) : 7;

        return '<div>
            <a href="' . admin_url('admin-ajax.php') . '?action=paytr_view_logs&nonce=' . wp_create_nonce('paytr_view_logs') . '" target="_blank" class="button button-secondary">' . __('Hata Gecmisini Goruntule', 'paytr-sanal-pos-woocommerce-iframe-api') . '</a>
            <p class="description">' . sprintf(__('Son %d gunun hata loglarini goruntulemek icin tiklayin.', 'paytr-sanal-pos-woocommerce-iframe-api'), $retention_days) . '</p>
        </div>';

        ob_start();
        $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());
        $retention_days = isset($settings['paytr_log_retention_days']) ? intval($settings['paytr_log_retention_days']) : 7;
        ?>
        <div>
            <a href="<?php echo admin_url('admin-ajax.php'); ?>?action=paytr_view_logs&nonce=<?php echo wp_create_nonce('paytr_view_logs'); ?>" target="_blank" class="button button-secondary">
                <?php _e('Hata Geçmişini Görüntüle', 'paytr-sanal-pos-woocommerce-iframe-api'); ?>
            </a>
            <p class="description"><?php printf(esc_html__('Son %d gunun hata loglarini goruntulemek icin tiklayin.', 'paytr-sanal-pos-woocommerce-iframe-api'), $retention_days); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_mode_warning_html() {
        $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());
        $test_mode_enabled = isset($settings['test']) && $settings['test'] === 'yes';

        if ($test_mode_enabled) {
            return '<div style="padding:10px 12px;background:#fff3cd;border-left:4px solid #dba617;color:#664d03;"><strong>Test modu aktif.</strong> Gercek kart tahsilati icin canli moda gecmeden once bu ayari kapatin.</div>';
        }

        return '<div style="padding:10px 12px;background:#d1e7dd;border-left:4px solid #198754;color:#0f5132;"><strong>Canli mod aktif.</strong> Islemler gercek odeme akisi uzerinden calisir.</div>';
    }

    public function display_test_mode_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'woocommerce_page_wc-settings') {
            return;
        }

        if (!isset($_GET['section']) || sanitize_text_field($_GET['section']) !== $this->id) {
            return;
        }

        if ($this->get_option('test') !== 'yes') {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>PayTR test modu aktif.</strong> Canli tahsilat almadan once test modunu kapattiginizdan emin olun.</p></div>';
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
