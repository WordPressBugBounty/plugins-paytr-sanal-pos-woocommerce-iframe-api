<?php

class PaytrCoreClass {
    public $paytr_installment;
    public $paytr_installment_list;
    public $paytr_lang;
    protected $category_full = array();
    protected $category_installment = array();
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
        $body = wp_remote_retrieve_body($result);
        $response = json_decode($body, 1);
        if ($response['status'] == 'success') {
            $token = $response['token'];
            $order->update_meta_data( 'paytr_order_id', $merchant['merchant_oid'] );
            $order->update_status('wc-pending');
            $order->save();
        } else {
            wp_die("PAYTR IFRAME failed. reason:" . $response['reason']);
        }

        wp_enqueue_script('script', PAYTRSPI_PLUGIN_URL_2 . '/assets/js/payTRiframeResizer.js', false, '2.0', true);

        echo '<iframe src="https://www.paytr.com/odeme/'.($iframe ? 'guvenli' : 'api').'/'.$token.'" id="paytriframe" frameborder="0" style="width: 100%;"></iframe>
            <script type="text/javascript">
                setInterval(function () {
                    iFrameResize({}, "#paytriframe");
                }, 1000);
            </script>';
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

        $merchant_oid = $order->get_meta('paytr_order_id');

        if (!$merchant_oid) {
            return new WP_Error('paytr_refund_error', __('PayTR Order number not found.', 'paytr-payment-gateway'));
        }

        if ($order->get_status() !== 'completed') {
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

        $wpCurlArgs = array(
            'method' => 'POST',
            'body' => $post_data,
            'httpversion' => '1.0',
            'sslverify' => true,
            'timeout' => 90,
        );

        $result = wp_remote_post('https://www.paytr.com/odeme/iade', $wpCurlArgs);

        $body = wp_remote_retrieve_body($result);
        $response = json_decode($body, 1);

        if (sanitize_text_field($response['status']) == 'success') {

            // Note Start
            $note = __('PAYTR NOTIFICATION - Refund', 'paytr-payment-gateway') . "\n";
            $note .= __('Status', 'paytr-payment-gateway') . ': ' . $response['status'] . "\n";
            $note .= __('PayTR Order ID', 'paytr-payment-gateway') . ': <a href="https://www.paytr.com/magaza/satislar?merchant_oid=' . $merchant_oid . '" target="_blank">' . $merchant_oid . '</a>' . "\n";
            $note .= __('Refund Amount', 'paytr-payment-gateway') . ': ' . wc_price($response['return_amount'], array('currency' => $order->get_currency())) . "\n";

            if ($reason != '') {
                $note .= 'Reason of Refund : ' . $reason;
            }

            $order->add_order_note($note);

            return true;
        } else {
            $note = $response['status'] . ' - ' . $response['err_no'] . ' - ' . $response['err_msg'];

            return new WP_Error('paytr_refund_error', __('An error occurred when refunded. Reason;' . "\n" . $note, 'paytr-payment-gateway'));
        }
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
