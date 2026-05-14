<?php

class PaytrCoreClass {
    public $paytr_installment;
    public $paytr_installment_list;
    public $paytr_lang;
    protected $category_full = array();
    protected $category_installment = array();
    private $log_manager;

    public function __construct() {
        $this->log_manager = new PaytrLogManager();
    }

    public function log_error($message, $order_id = null, $transaction_id = null, $details = array()) {
        return $this->log_manager->log_error($message, $order_id, $transaction_id, $details);
    }
    public function receiptPage($order, $settings, $iframe = true)
    {
        $config = get_option('woocommerce_paytr_payment_gateway_settings');
        $merchant = array();
        $this->categoryParserProd();
        ;
        // Get Order
        $order = wc_get_order( $order );
        $country = sanitize_text_field($order->get_billing_country());
        $get_country = sanitize_text_field(WC()->countries->get_states($country)[sanitize_text_field($order->get_billing_state())]);
        $merchant['merchant_oid'] = time() . 'PAYTRWOO' . $order->get_id();
        $merchant['user_ip'] = $this->GetIP();
        $merchant['test_mode'] = $settings['test'] === 'yes' ? 1 : 0;
        $merchant['email'] = sanitize_email(substr($order->get_billing_email(), 0, 100));
        $merchant['payment_amount'] = $order->get_total() * 100;
        $merchant['user_name'] = sanitize_text_field(substr($order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), 0, 60));
        $merchant['user_address'] = substr($order->get_billing_address_1() . ' ' . $order->get_billing_address_2() . ' ' . $order->get_billing_city() . ' ' . $get_country . ' ' . $order->get_billing_postcode(), 0, 300);
        $merchant['user_phone'] = sanitize_text_field(substr($order->get_billing_phone(), 0, 20));
        if (isset($settings['iframe_theme']) && $settings['iframe_theme'] === 'yes') {
            $iframe_v2_dark = 1;
        } else {
            $iframe_v2_dark = 0;
        }
        // Basket
        $user_basket = array();
        $item_loop = 0;

        if (sizeof($order->get_items()) > 0) {
            $installment = array();

            foreach ($order->get_items() as $item) {
                if ($item['qty']) {
                    $item_loop++;

                    $product = $item->get_product();

                    $item_name = $item['name'];

                    // WC_Order_Item_Meta is deprecated since WooCommerce version 3.1.0
                    if (defined('WOOCOMMERCE_VERSION') && version_compare(WOOCOMMERCE_VERSION, '3.1.0', '>=')) {
                        $item_name .= wc_display_item_meta($item, array(
                            'before' => '',
                            'after' => '',
                            'separator' => ' | ',
                            'echo' => false,
                            'autop' => false
                        ));
                    } else {
                        $item_meta = new WC_Order_Item_Meta($item['item_meta']);
                        if ($meta = $item_meta->display(true, true)) {
                            $item_name .= ' ( ' . $meta . ' )';
                        }
                    }

                    $item_total_inc_tax = $order->get_item_subtotal($item, true);
                    $sku = '';

                    if ($product->get_sku()) {
                        $sku = '[STK:' . $product->get_sku() . ']';
                    }

                    $user_basket[] = array(
                        str_replace(':', ' = ', $sku) . ' ' . $item_name,
                        $item_total_inc_tax,
                        $item['qty'],
                    );

                    if ($this->paytr_installment == 13) {
                        $this->category_installment = $this->paytr_installment_list;
                        $categorys = get_the_terms($item['product_id'], 'product_cat');

                        foreach ($categorys as $cat) {
                            if (array_key_exists($cat->term_id, $this->paytr_installment_list)) {
                                $installment[$cat->term_id] = $this->paytr_installment_list[$cat->term_id];
                            } else {
                                $installment[$cat->term_id] = $this->catSearchProd($cat->term_id);
                            }
                        }
                    }
                }
            }
        }

        if($iframe)
        {
            // Category Based
            if ($this->paytr_installment != 13) {
                $merchant['max_installment'] = in_array($settings['paytr_installment'], range(0, 12)) ? $settings['paytr_installment'] : 0;
            } else {
                $installment = count(array_diff($installment, array(0))) > 0 ? min(array_diff($installment, array(0))) : 0;
                $merchant['max_installment'] = $installment ? $installment : 0;
            }
            $merchant['no_installment'] = ($merchant['max_installment'] == 1) ? 1 : 0;
        }
        $merchant['debug_on'] = 1;
        $merchant['currency'] = strtoupper(get_woocommerce_currency());
        $merchant['user_basket'] = base64_encode(json_encode($user_basket));

        if($iframe) {
            $hash_str = $config['paytr_merchant_id'] . $merchant['user_ip'] . $merchant['merchant_oid'] . $merchant['email'] . $merchant['payment_amount'] . $merchant['user_basket'] . $merchant['no_installment'] . $merchant['max_installment'] . $merchant['currency'] . $merchant['test_mode'];
            $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $config['paytr_merchant_salt'], $config['paytr_merchant_key'], true));

            $post_data = array(
                'merchant_id' => $settings['paytr_merchant_id'],
                'user_ip' => $merchant['user_ip'],
                'test_mode' => $merchant['test_mode'],
                'merchant_oid' => $merchant['merchant_oid'],
                'email' => $merchant['email'],
                'payment_amount' => $merchant['payment_amount'],
                'paytr_token' => $paytr_token,
                'user_basket' => $merchant['user_basket'],
                'debug_on' => $merchant['debug_on'],
                'no_installment' => $merchant['no_installment'],
                'max_installment' => $merchant['max_installment'],
                'user_name' => $merchant['user_name'],
                'user_address' => $merchant['user_address'],
                'user_phone' => $merchant['user_phone'],
                'currency' => $merchant['currency'],
                'merchant_fail_url' => wc_get_cart_url(),
		        'iframe_v2_dark' => $iframe_v2_dark,
            );
            $post_data['merchant_ok_url'] = $order->get_checkout_order_received_url();
            if ($this->paytr_lang == 0) {
                $lang_arr = array(
                    'tr',
                    'tr-tr',
                    'tr_tr',
                    'turkish',
                    'turk',
                    'türkçe',
                    'turkce',
                    'try',
                    'trl',
                    'tl'
                );
                $post_data['lang'] = (in_array(strtolower(get_locale()), $lang_arr) ? 'tr' : 'en');
            } else {
                $post_data['lang'] = ($this->paytr_lang == 1 ? 'tr' : 'en');
            }
        } else {
            $hash_str = $config['paytr_merchant_id'] . $merchant['user_ip'] . $merchant['merchant_oid'] . $merchant['email'] . $merchant['payment_amount'] . 'eft' . $merchant['test_mode'];
            $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . $config['paytr_merchant_salt'], $config['paytr_merchant_key'], true));

            $post_data = array(
                'merchant_id' => $config['paytr_merchant_id'],
                'user_ip' => $merchant['user_ip'],
                'merchant_oid' => $merchant['merchant_oid'],
                'email' => $merchant['email'],
                'payment_amount' => $merchant['payment_amount'],
                'payment_type'=> 'eft',
                'paytr_token' => $paytr_token,
                'debug_on' => $merchant['debug_on'],
                'timeout_limit'=> '30',
                'test_mode' => $merchant['test_mode'],
            );
        }
        $wpCurlArgs = array(
            'method' => 'POST',
            'body' => $post_data,
            'httpversion' => '1.0',
            'sslverify' => true,
            'timeout' => 90,
        );
        $result = wp_remote_post('https://www.paytr.com/odeme/api/get-token', $wpCurlArgs);

        if (is_wp_error($result)) {
            $this->record_payment_attempt($order, array(
                'merchant_oid' => $merchant['merchant_oid'],
                'amount' => $merchant['payment_amount'],
                'currency' => $merchant['currency'],
                'test_mode' => $merchant['test_mode'],
                'status' => 'request_error',
                'reason' => $result->get_error_message(),
                'http_code' => 0,
            ));

            $this->log_error('PAYTR odeme token istegi gonderilemedi - Sebep: ' . $result->get_error_message(), $order->get_id(), $merchant['merchant_oid'], array(
                'kullanici_ip' => $merchant['user_ip'],
                'wp_hata_kodu' => $result->get_error_code(),
                'wp_hata_mesaji' => $result->get_error_message(),
                'tarih' => date('Y-m-d H:i:s')
            ));

            wp_die("
                <div style='padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; max-width: 600px; margin: 20px auto;'>
                    <h3 style='color: #721c24; margin-top: 0;'>Odeme Islemi Hatasi</h3>
                    <p><strong>Hata:</strong> PayTR odeme servisine ulasilamadi.</p>
                    <p><strong>Cozum Onerisi:</strong> Lutfen daha sonra tekrar deneyin veya magaza yoneticisi ile iletisime gecin.</p>
                    <button onclick='window.history.back()' style='background: #0073aa; color: white; border: none; padding: 10px 20px; border-radius: 3px; cursor: pointer;'>Geri Don</button>
                </div>
            ");
        }

        $body = wp_remote_retrieve_body($result);
        $response = json_decode($body, 1);

        if (!is_array($response) || !isset($response['status'])) {
            $this->record_payment_attempt($order, array(
                'merchant_oid' => $merchant['merchant_oid'],
                'amount' => $merchant['payment_amount'],
                'currency' => $merchant['currency'],
                'test_mode' => $merchant['test_mode'],
                'status' => 'invalid_response',
                'reason' => substr($body, 0, 200),
                'http_code' => wp_remote_retrieve_response_code($result),
            ));

            $this->log_error('PAYTR odeme token cevabi okunamadi', $order->get_id(), $merchant['merchant_oid'], array(
                'kullanici_ip' => $merchant['user_ip'],
                'http_kodu' => wp_remote_retrieve_response_code($result),
                'ham_cevap' => $body,
                'tarih' => date('Y-m-d H:i:s')
            ));

            wp_die("
                <div style='padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; max-width: 600px; margin: 20px auto;'>
                    <h3 style='color: #721c24; margin-top: 0;'>Odeme Islemi Hatasi</h3>
                    <p><strong>Hata:</strong> PayTR odeme cevabi okunamadi.</p>
                    <p><strong>Cozum Onerisi:</strong> Lutfen daha sonra tekrar deneyin veya magaza yoneticisi ile iletisime gecin.</p>
                    <button onclick='window.history.back()' style='background: #0073aa; color: white; border: none; padding: 10px 20px; border-radius: 3px; cursor: pointer;'>Geri Don</button>
                </div>
            ");
        }

        $this->record_payment_attempt($order, array(
            'merchant_oid' => $merchant['merchant_oid'],
            'amount' => $merchant['payment_amount'],
            'currency' => $merchant['currency'],
            'test_mode' => $merchant['test_mode'],
            'status' => isset($response['status']) ? $response['status'] : 'invalid_response',
            'reason' => isset($response['reason']) ? $response['reason'] : '',
            'http_code' => wp_remote_retrieve_response_code($result),
        ));

        if ($response['status'] == 'success' && !empty($response['token'])) {
            $token = $response['token'];
            $order->update_meta_data( 'paytr_pending_order_id', $merchant['merchant_oid'] );
            $order->update_status('wc-pending');
            $order->save();
        } else {
        $paytr_reason = isset($response['reason']) ? $response['reason'] : 'PayTR token cevabinda token bulunamadi.';
        $response['reason'] = $paytr_reason;
           
        $error_details = array(
            'kullanici_ip' => $merchant['user_ip'],
            'tarih' => date('Y-m-d H:i:s'),
            'paytr_cevabi' => $response,
            'order_details' => array(
                'user_name' => $merchant['user_name'],
                'user_phone' => $merchant['user_phone'],
                'user_email' => $merchant['email'],
                'user_address' => $merchant['user_address']
            )
        );
        
        $error_message = "İlgili işlem hata detayı - Sebep: " . $response['reason'];
        $error_message = isset($paytr_reason) ? "PAYTR odeme token hatasi - Sebep: " . $paytr_reason : $error_message;
        $this->log_error($error_message, $order->get_id(), $merchant['merchant_oid'], $error_details);
        
        // Kullanıcı dostu hata mesajı
        $user_friendly_message = $this->get_user_friendly_error($paytr_reason);
        
        wp_die("
            <div style='padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px; max-width: 600px; margin: 20px auto;'>
                <h3 style='color: #721c24; margin-top: 0;'>Ödeme İşlemi Hatası</h3>
                <p><strong>Hata:</strong> " . $user_friendly_message . "</p>
                <p><strong>Çözüm Önerisi:</strong> Lütfen ödeme sayfasına dönerek bilgilerinizi kontrol edin veya mağaza yöneticisi ile iletişime geçin.</p>
                <button onclick='window.history.back()' style='background: #0073aa; color: white; border: none; padding: 10px 20px; border-radius: 3px; cursor: pointer;'>Geri Dön</button>
            </div>
        ");
    
        }
      

        wp_enqueue_script('script', PAYTRSPI_PLUGIN_URL_2 . '/assets/js/payTRiframeResizer.js', false, '2.0', true);

        echo '<iframe src="https://www.paytr.com/odeme/'.($iframe ? 'guvenli' : 'api').'/'.$token.'" id="paytriframe" frameborder="0" style="width: 100%;"></iframe>
            <script type="text/javascript">
                setInterval(function () {
                    iFrameResize({}, "#paytriframe");
                }, 1000);
            </script>';
    }
private function get_user_friendly_error($reason) {
    $error_mapping = array(
        'user_phone' => 'Telefon numarası geçersiz veya eksik. Lütfen geçerli bir telefon numarası girin.',
        'user_email' => 'E-posta adresi geçersiz veya eksik. Lütfen geçerli bir e-posta adresi girin.',
        'user_name' => 'İsim ve soyisim bilgisi eksik. Lütfen tam adınızı girin.',
        'user_address' => 'Adres bilgisi eksik. Lütfen tam adresinizi girin.',
        'payment_amount' => 'Ödeme tutarı geçersiz. Lütfen sepetinizi kontrol edin.',
        'merchant_oid' => 'Sipariş numarası oluşturulamadı. Lütfen tekrar deneyin.'
    );
    
    foreach ($error_mapping as $key => $friendly_message) {
        if (strpos($reason, $key) !== false) {
            return $friendly_message;
        }
    }
    
    return 'Ödeme işlemi sırasında bir hata oluştu: ' . $reason;
}

    private function record_payment_attempt($order, $attempt) {
        if (!$order) {
            return;
        }

        $attempts = $order->get_meta('paytr_payment_attempts');
        if (!is_array($attempts)) {
            $attempts = array();
        }

        $attempts[] = array(
            'created_at' => date('Y-m-d H:i:s'),
            'merchant_oid' => isset($attempt['merchant_oid']) ? sanitize_text_field($attempt['merchant_oid']) : '',
            'amount' => isset($attempt['amount']) ? sanitize_text_field($attempt['amount']) : '',
            'currency' => isset($attempt['currency']) ? sanitize_text_field($attempt['currency']) : '',
            'test_mode' => isset($attempt['test_mode']) ? intval($attempt['test_mode']) : 0,
            'status' => isset($attempt['status']) ? sanitize_text_field($attempt['status']) : '',
            'reason' => isset($attempt['reason']) ? sanitize_text_field($attempt['reason']) : '',
            'http_code' => isset($attempt['http_code']) ? intval($attempt['http_code']) : 0,
        );

        $attempts = array_slice($attempts, -20);
        $order->update_meta_data('paytr_payment_attempts', $attempts);
        $order->save();
    }

    public function processRefundPaytr($order_id, $amount = null, $reason = '')
    {

        $amount = sanitize_text_field($amount);
        $reason = sanitize_text_field($reason);

        if (is_null($amount) or $amount <= 0) {
            return new WP_Error('paytr_refund_error', __('The amount is empty or less than 0.', 'paytr-payment-gateway'));
        }

        $options = get_option('woocommerce_paytr_payment_gateway_settings');

        $order = new WC_Order( $order_id );

        $merchant_oid = $this->get_refund_merchant_oid($order);

        if (!$merchant_oid) {
            return new WP_Error('paytr_refund_error', __('PayTR Order number not found.', 'paytr-payment-gateway'));
        }

        if ($order->get_status() !== 'completed' && $order->get_status() !== 'processing' ) {
            return new WP_Error('paytr_refund_error', __('The notification process has not been completed yet.', 'paytr-payment-gateway'));
        }

        if ($order->get_status() === 'is_failed') {
            return new WP_Error('paytr_refund_error', __('Can not refund the failed orders.', 'paytr-payment-gateway'));
        }

        $paytr_token = base64_encode(hash_hmac('sha256', $options['paytr_merchant_id'] . $merchant_oid . $amount . $options['paytr_merchant_salt'], $options['paytr_merchant_key'], true));

        $post_data = array(
            'merchant_id' => $options['paytr_merchant_id'],
            'merchant_oid' => $merchant_oid,
            'return_amount' => $amount,
            'paytr_token' => $paytr_token
        );

        $safe_post_data = $post_data;
        unset($safe_post_data['paytr_token']);

        $wpCurlArgs = array(
            'method' => 'POST',
            'body' => $post_data,
            'httpversion' => '1.0',
            'sslverify' => true,
            'timeout' => 90,
        );

        $result = wp_remote_post('https://www.paytr.com/odeme/iade', $wpCurlArgs);

        if (is_wp_error($result)) {
            $refund_log_details = array(
                'siparis_durumu' => $order->get_status(),
                'iade_istegi' => $safe_post_data,
                'wp_hata_kodu' => $result->get_error_code(),
                'wp_hata_mesaji' => $result->get_error_message(),
                'tarih' => date('Y-m-d H:i:s')
            );

            $this->log_error('PAYTR iade istegi gonderilemedi - Sebep: ' . $result->get_error_message(), $order->get_id(), $merchant_oid, $refund_log_details);

            return new WP_Error('paytr_refund_error', __('An error occurred when refunded. Reason;' . "\n" . $result->get_error_message(), 'paytr-payment-gateway'));
        }

        $body = wp_remote_retrieve_body($result);
        $response = json_decode($body, 1);

        $refund_log_details = array(
            'siparis_durumu' => $order->get_status(),
            'iade_istegi' => $safe_post_data,
            'http_kodu' => wp_remote_retrieve_response_code($result),
            'paytr_cevabi' => $response,
            'ham_cevap' => $body,
            'tarih' => date('Y-m-d H:i:s')
        );

        if (!is_array($response) || !isset($response['status'])) {
            $this->log_error('PAYTR iade cevabi okunamadi', $order->get_id(), $merchant_oid, $refund_log_details);

            return new WP_Error('paytr_refund_error', __('An error occurred when refunded. Reason;' . "\n" . 'PayTR response could not be read.', 'paytr-payment-gateway'));
        }

        if (sanitize_text_field($response['status']) == 'success') {

            // Note Start
            $note = __('PAYTR NOTIFICATION - Refund', 'paytr-payment-gateway') . "\n";
            $note .= __('Status', 'paytr-payment-gateway') . ': ' . $response['status'] . "\n";
            $note .= __('PayTR Order ID', 'paytr-payment-gateway') . ': <a href="https://www.paytr.com/magaza/satislar?merchant_oid=' . $merchant_oid . '" target="_blank">' . $merchant_oid . '</a>' . "\n";
            $return_amount = isset($response['return_amount']) ? $response['return_amount'] : $amount;
            $note .= __('Refund Amount', 'paytr-payment-gateway') . ': ' . wc_price($return_amount, array('currency' => $order->get_currency())) . "\n";

            if ($reason != '') {
                $note .= 'Reason of Refund : ' . $reason;
            }

            $order->add_order_note($note);
            $this->log_error('PAYTR iade islemi basarili', $order->get_id(), $merchant_oid, $refund_log_details);

            return true;
        } else {
            $err_no = isset($response['err_no']) ? $response['err_no'] : 'unknown';
            $err_msg = isset($response['err_msg']) ? $response['err_msg'] : 'PayTR refund error message is empty.';
            $note = $response['status'] . ' - ' . $err_no . ' - ' . $err_msg;
            $this->log_error('PAYTR iade islemi hatasi - Sebep: ' . $note, $order->get_id(), $merchant_oid, $refund_log_details);

            return new WP_Error('paytr_refund_error', __('An error occurred when refunded. Reason;' . "\n" . $note, 'paytr-payment-gateway'));
        }
    }

    private function get_refund_merchant_oid($order) {
        $stored_merchant_oid = $order->get_meta('paytr_order_id');
        $confirmed_merchant_oid = $this->get_successful_payment_merchant_oid($order);

        if ($confirmed_merchant_oid && $confirmed_merchant_oid !== $stored_merchant_oid) {
            $order->update_meta_data('paytr_order_id', $confirmed_merchant_oid);
            $order->save();

            $this->log_error('PAYTR iade merchant_oid eslesmesi duzeltildi', $order->get_id(), $confirmed_merchant_oid, array(
                'eski_paytr_order_id' => $stored_merchant_oid,
                'dogru_paytr_order_id' => $confirmed_merchant_oid,
                'kaynak' => 'basarili_odeme_bildirimi',
                'tarih' => date('Y-m-d H:i:s')
            ));
        }

        return $confirmed_merchant_oid ? $confirmed_merchant_oid : $stored_merchant_oid;
    }

    private function get_successful_payment_merchant_oid($order) {
        $attempt_oid = $this->get_successful_payment_attempt_merchant_oid($order);
        if ($attempt_oid) {
            return $attempt_oid;
        }

        return $this->get_successful_payment_note_merchant_oid($order);
    }

    private function get_successful_payment_attempt_merchant_oid($order) {
        $attempts = $order->get_meta('paytr_payment_attempts');
        if (!is_array($attempts)) {
            return null;
        }

        foreach (array_reverse($attempts) as $attempt) {
            $callback_status = isset($attempt['callback_status']) ? $attempt['callback_status'] : '';
            $merchant_oid = isset($attempt['merchant_oid']) ? $attempt['merchant_oid'] : '';

            if ($callback_status === 'success' && $merchant_oid) {
                return sanitize_text_field($merchant_oid);
            }
        }

        return null;
    }

    private function get_successful_payment_note_merchant_oid($order) {
        if (!function_exists('wc_get_order_notes')) {
            return null;
        }

        $notes = wc_get_order_notes(array(
            'order_id' => $order->get_id(),
            'limit' => 50,
        ));

        foreach ($notes as $note) {
            $content = isset($note->content) ? wp_strip_all_tags($note->content) : '';

            if (stripos($content, 'PAYTR NOTIFICATION') === false || stripos($content, 'Payment Accepted') === false) {
                continue;
            }

            if (preg_match('/(\d+PAYTRWOO' . preg_quote((string) $order->get_id(), '/') . ')/', $content, $matches)) {
                return sanitize_text_field($matches[1]);
            }
        }

        return null;
    }

    public function categoryParserProd()
    {
        $all_cats = get_terms('product_cat', array());
        $cats = array();
        foreach ($all_cats as $cat) {
            $this->category_full[$cat->term_id] = $cat->parent;
        }
    }

    private function GetIP()
    {
        if (isset($_SERVER["HTTP_CLIENT_IP"])) {
            $ip = $_SERVER["HTTP_CLIENT_IP"];
        } elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }

        return $ip;
    }
    public function parentCategoryParser(&$cats = array(), &$cat_tree = array()): void
    {
        foreach ($cats as $key => $item) {
            if ($item['parent_id'] == $cat_tree['id']) {
                $cat_tree['parent'][$item['id']] = array('id' => $item['id'], 'name' => $item['name']);
                $this->parentCategoryParser($cats, $cat_tree['parent'][$item['id']]);
            }
        }
    }
    public function categoryParserClear($tree, $level = 0, $arr = array(), &$finish_him = array()): void
    {
        foreach ($tree as $id => $item) {
            if ($level == 0) {
                unset($arr);
                $arr = array();
                $arr[] = $item['name'];
            } elseif ($level == 1 or $level == 2) {
                if (count($arr) == ($level + 1)) {
                    $deleted = array_pop($arr);
                }
                $arr[] = $item['name'];
            }

            if ($level < 3) {
                $nav = null;
                foreach ($arr as $key => $val) {
                    $nav .= $val . ($level != 0 ? ' > ' : null);
                }

                $finish_him[$item['id']] = rtrim($nav, ' > ') . '<br>';

                if (!empty($item['parent'])) {
                    $this->categoryParserClear($item['parent'], $level + 1, $arr, $finish_him);
                }
            }
        }
    }
    public function categoryParser()
    {
        $all_cats = get_terms('product_cat', array());
        $cats = array();

        foreach ($all_cats as $cat) {
            $cats[] = array('id' => $cat->term_id, 'parent_id' => $cat->parent, 'name' => $cat->name);
        }

        $cat_tree = array();

        foreach ($cats as $key => $item) {
            if ($item['parent_id'] == 0) {
                $cat_tree[$item['id']] = array('id' => $item['id'], 'name' => $item['name']);
                $this->parentCategoryParser($cats, $cat_tree[$item['id']]);
            }
        }

        return $cat_tree;
    }

    public function catSearchProd($category_id = 0)
    {

        $return = false;

        if (!empty($this->category_full[$category_id]) and array_key_exists($this->category_full[$category_id], $this->category_installment)) {
            $return = $this->category_installment[$this->category_full[$category_id]];
        } else {
            foreach ($this->category_full as $id => $parent) {
                if ($category_id == $id) {
                    if ($parent == 0) {
                        $return = 0;
                    } elseif (array_key_exists($parent, $this->category_installment)) {
                        $return = $this->category_installment[$parent];
                    } else {
                        $return = $this->catSearchProd($parent);
                    }
                } else {
                    $return = 0;
                }
            }
        }
        return $return;
    }
}
