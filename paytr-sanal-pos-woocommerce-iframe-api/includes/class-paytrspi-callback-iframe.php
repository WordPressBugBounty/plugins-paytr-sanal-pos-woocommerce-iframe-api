<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
};

class PaytrCheckoutCallbackIframe {
	public static function callback_iframe( $post ) {
        $result = self::process_callback($post);
        status_header($result['status']);
        echo $result['body'];
        exit;
    }

    public static function process_callback($post) {
        global $wpdb;
        $locked = false;
        $transaction_started = false;
        $lock_name = '';
        try {
            $options = get_option('woocommerce_paytr_payment_gateway_settings', array());
            foreach (array('merchant_oid', 'status', 'total_amount', 'hash') as $field) {
                if (!is_array($post) || !isset($post[$field]) || !is_string($post[$field])) {
                    throw new InvalidArgumentException('invalid fields');
                }
            }
            if (empty($options['paytr_merchant_key']) || empty($options['paytr_merchant_salt'])) {
                throw new RuntimeException('missing configuration');
            }
            if (!preg_match('/^[A-Za-z0-9]+PAYTRWOO([1-9][0-9]*)$/D', $post['merchant_oid'], $matches)
                || !in_array($post['status'], array('success', 'failed'), true)
                || self::minor_amount($post['total_amount']) === null) {
                throw new InvalidArgumentException('invalid notification');
            }
            $hash = base64_encode(hash_hmac('sha256', $post['merchant_oid'] . $options['paytr_merchant_salt'] . $post['status'] . $post['total_amount'], $options['paytr_merchant_key'], true));
            if (!hash_equals($hash, $post['hash'])) {
                throw new InvalidArgumentException('bad hash');
            }
            // A connection-owned database lock serializes callbacks for this order.
            $lock_name = 'paytr_callback_' . get_current_blog_id() . '_' . $matches[1];
            $locked = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)) === '1';
            if (!$locked) {
                throw new RuntimeException('callback already processing');
            }
            $order = wc_get_order($matches[1]);
            if (!$order || !in_array($order->get_payment_method(), array('paytr_payment_gateway', 'paytr_payment_gateway_eft'), true)) {
                throw new InvalidArgumentException('order not found');
            }
            $oid = $post['merchant_oid'];
            $contexts = $order->get_meta('_paytr_payment_contexts');
            $context = is_array($contexts) && isset($contexts[$oid]) ? $contexts[$oid] : null;
            $attempts = $order->get_meta('paytr_payment_attempts');
            if (!$context && is_array($attempts)) {
                foreach ($attempts as $attempt) {
                    if (isset($attempt['merchant_oid'], $attempt['amount'], $attempt['currency'], $attempt['status']) && $attempt['merchant_oid'] === $oid && $attempt['status'] === 'success') {
                        $context = $attempt;
                        break;
                    }
                }
            }
            $pending_oid = (string) $order->get_meta('paytr_pending_order_id');
            $paid_oid = (string) $order->get_meta('paytr_order_id');
            // Older installations have no context: successful payments are still
            // verified against the order and the authenticated status service.
            if (!$context && $oid !== $pending_oid && $oid !== $paid_oid
                && (!empty($contexts) || !empty($attempts) || $pending_oid !== '' || $post['status'] !== 'success')) {
                throw new InvalidArgumentException('merchant_oid mismatch');
            }
            $results = $order->get_meta('_paytr_callback_results');
            $results = is_array($results) ? $results : array();
            if (($paid_oid !== '' && $order->get_meta('_paytr_payment_processed'))
                || (isset($results[$oid]) && $results[$oid] === $post['status'])) {
                return array('status' => 200, 'body' => 'OK');
            }
            if (!in_array($order->get_status(), array('pending', 'failed'), true)) {
                return array('status' => 200, 'body' => 'OK');
            }
            if ($post['status'] === 'failed' && $pending_oid !== '' && $pending_oid !== $oid) {
                return array('status' => 200, 'body' => 'OK');
            }
            if ($post['status'] === 'success') {
                $verified = self::query_payment($options, $oid);
                $expected = $context ? self::minor_amount((string) $context['amount']) : self::decimal_amount($order->get_total());
                $expected_currency = self::currency($context ? $context['currency'] : $order->get_currency());
                if ($expected === null || $expected <= 0 || $verified['amount'] !== $expected
                    || $verified['total'] !== self::minor_amount($post['total_amount'])
                    || $verified['total'] < $expected
                    || $verified['currency'] !== $expected_currency
                    || $verified['currency'] !== self::currency($order->get_currency())
                    || self::decimal_amount($order->get_total()) !== $expected) {
                    throw new InvalidArgumentException('payment mismatch');
                }
                $settings = get_option('woocommerce_' . $order->get_payment_method() . '_settings', array());
                $expected_test = $context ? (int) $context['test_mode'] : (isset($settings['test']) && $settings['test'] === 'yes' ? 1 : 0);
                if ($verified['test_mode'] !== $expected_test
                    || (isset($post['payment_amount']) && self::minor_amount($post['payment_amount']) !== $expected)
                    || (isset($post['currency']) && (!is_string($post['currency']) || self::currency($post['currency']) !== $expected_currency))) {
                    throw new InvalidArgumentException('payment mode or callback mismatch');
                }
                $post['payment_amount'] = (string) $verified['amount'];
                $post['currency'] = $verified['currency'];
                $post['installment_count'] = (string) $verified['installments'];
            } else {
                foreach (array('failed_reason_code', 'failed_reason_msg') as $field) {
                    $post[$field] = isset($post[$field]) && is_scalar($post[$field]) ? sanitize_text_field($post[$field]) : '';
                }
            }
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException('transaction could not start');
            }
            $transaction_started = true;
            self::apply_payment($order, $post, $options);
            $results[$oid] = $post['status'];
            $order->update_meta_data('_paytr_callback_results', $results);
            if ($post['status'] === 'success') {
                $order->update_meta_data('_paytr_payment_processed', 1);
            }
            $order->save();
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('transaction could not commit');
            }
            $transaction_started = false;
            return array('status' => 200, 'body' => 'OK');
        } catch (InvalidArgumentException $e) {
            return array('status' => 400, 'body' => 'PAYTR notification rejected');
        } catch (Exception $e) {
            return array('status' => 503, 'body' => 'PAYTR notification could not be processed');
        } finally {
            if ($transaction_started) {
                $wpdb->query('ROLLBACK');
            }
            if ($locked) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
        }
    }

    private static function minor_amount($value) {
        return is_scalar($value) && preg_match('/^[0-9]{1,15}$/D', (string) $value) ? (int) $value : null;
    }

    private static function decimal_amount($value) {
        if (!is_scalar($value) || !preg_match('/^([0-9]{1,13})(?:[.,]([0-9]{1,2}))?$/D', (string) $value, $matches)) {
            return null;
        }
        return (int) $matches[1] * 100 + (isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0);
    }

    private static function currency($value) {
        $value = strtoupper((string) $value);
        return $value === 'TRY' ? 'TL' : $value;
    }

    private static function query_payment($options, $oid) {
        $token = base64_encode(hash_hmac('sha256', $options['paytr_merchant_id'] . $oid . $options['paytr_merchant_salt'], $options['paytr_merchant_key'], true));
        $response = wp_remote_post('https://www.paytr.com/odeme/durum-sorgu', array(
            'body' => array('merchant_id' => $options['paytr_merchant_id'], 'merchant_oid' => $oid, 'paytr_token' => $token),
            'timeout' => 20, 'sslverify' => true,
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new RuntimeException('status service unavailable');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'success') {
            throw new RuntimeException('payment not confirmed');
        }
        $amount = self::decimal_amount(isset($data['payment_amount']) ? $data['payment_amount'] : null);
        $total = self::decimal_amount(isset($data['payment_total']) ? $data['payment_total'] : null);
        if ($amount === null || $total === null || !isset($data['currency'], $data['test_mode'])
            || !is_string($data['currency']) || !is_scalar($data['test_mode']) || !in_array((string) $data['test_mode'], array('0', '1'), true)) {
            throw new RuntimeException('invalid status response');
        }
        return array('amount' => $amount, 'total' => $total, 'currency' => self::currency($data['currency']),
            'test_mode' => (int) $data['test_mode'], 'installments' => isset($data['taksit']) && is_scalar($data['taksit']) ? max(1, (int) $data['taksit']) : 1);
    }

    private static function apply_payment($order, $post, $options) {
		$order_id = array('', $order->get_id());
		self::update_payment_attempt_from_callback( $order, $post );
		if ( $order->get_status() == 'pending' or $order->get_status() == 'failed' ) {
			if ( sanitize_text_field( $post['status'] ) == 'success' ) {
				$order->update_meta_data( 'paytr_order_id', sanitize_text_field( $post['merchant_oid'] ) );
				
				wc_reduce_stock_levels( $order_id[1] );

				$total_amount    = round( sanitize_text_field( $post['total_amount'] ) / 100, 2 );
				$payment_amount  = round( sanitize_text_field( $post['payment_amount'] ) / 100, 2 );
				$installment_dif = $total_amount - $payment_amount;

				
				$note = __( 'PAYTR NOTIFICATION - Payment Accepted', 'paytr-payment-gateway' ) . "\n";
				$note .= __( 'Total Paid', 'paytr-payment-gateway' ) . ': ' . sanitize_text_field( wc_price( $total_amount, array( 'currency' => $order->get_currency() ) ) ) . "\n";
				$note .= __( 'Paid', 'paytr-payment-gateway' ) . ': ' . sanitize_text_field( wc_price( $payment_amount, array( 'currency' => $order->get_currency() ) ) ) . "\n";

				if ( $installment_dif > 0 ) {
    if ( $options['paytr_ins_difference'] == 'yes' ) {
        $installment_fee = new WC_Order_Item_Fee();
        $installment_fee->set_name( __( 'Vade Farkı', 'paytr-payment-gateway' ) );

        
        if ( wc_tax_enabled() ) {
            
            $net_amount = (float) $installment_dif / 1.20;

            $installment_fee->set_tax_status( 'taxable' );
            $installment_fee->set_tax_class( '' ); 
            
            
            $installment_fee->set_total( $net_amount ); 
        } else {
            $installment_fee->set_tax_status( 'none' );
            $installment_fee->set_total( $installment_dif );
        }
        
        $order->add_item( $installment_fee );

        
        $order->calculate_totals( true ); 
        $order->save();
    }

    $note .= 'Vade Farkı: ' . wc_price( $installment_dif, array( 'currency' => $order->get_currency() ) ) . "\n";
}

				if ( array_key_exists( 'installment_count', $post ) ) {
					$note .= __( 'Installment Count', 'paytr-payment-gateway' ) . ': ' . ( sanitize_text_field( $post['installment_count'] ) == 1 ? __( 'One Shot', 'paytr-payment-gateway' ) : sanitize_text_field( $post['installment_count'] ) ) . "\n";
				}

				$note .= __( 'PayTR Order ID', 'paytr-payment-gateway' ) . ': <a href="https://www.paytr.com/magaza/islemler?merchant_oid=' . sanitize_text_field( $post['merchant_oid'] ) . '" target="_blank">' . sanitize_text_field( $post['merchant_oid'] ) . '</a>';
				
				$order->add_order_note( nl2br( $note ) );
				$order->update_status( $options['paytr_order_status'] );
                $order->save();
			} else {
				
				$note = __( 'PAYTR NOTIFICATION - Payment Failed', 'paytr-payment-gateway' ) . "\n";
				$note .= __( 'Error', 'paytr-payment-gateway' ) . ': ' . sanitize_text_field( $post['failed_reason_code'] ) . ' - ' . sanitize_text_field( $post['failed_reason_msg'] ) . "\n";
				$note .= __( 'PayTR Order ID', 'paytr-payment-gateway' ) . ': <a href="https://www.paytr.com/magaza/islemler?merchant_oid=' . sanitize_text_field( $post['merchant_oid'] ) . '" target="_blank">' . sanitize_text_field( $post['merchant_oid'] ) . '</a>';
				$order->add_order_note( nl2br( $note ) );
				$order->update_status( 'failed' );
                $order->save();
			}
		}

		return;
	}

	private static function update_payment_attempt_from_callback( $order, $post ) {
		if ( ! $order ) {
			return;
		}

		$attempts = $order->get_meta( 'paytr_payment_attempts' );
		if ( ! is_array( $attempts ) ) {
			return;
		}

		$merchant_oid = sanitize_text_field( $post['merchant_oid'] );
		foreach ( $attempts as $key => $attempt ) {
			if ( isset( $attempt['merchant_oid'] ) && $attempt['merchant_oid'] === $merchant_oid ) {
				$attempts[$key]['callback_at'] = date( 'Y-m-d H:i:s' );
				$attempts[$key]['callback_status'] = isset( $post['status'] ) ? sanitize_text_field( $post['status'] ) : '';
				$attempts[$key]['payment_type'] = isset( $post['payment_type'] ) ? sanitize_text_field( $post['payment_type'] ) : '';
				$attempts[$key]['total_amount'] = isset( $post['total_amount'] ) ? sanitize_text_field( $post['total_amount'] ) : '';
				$attempts[$key]['payment_amount'] = isset( $post['payment_amount'] ) ? sanitize_text_field( $post['payment_amount'] ) : '';
			}
		}

		$order->update_meta_data( 'paytr_payment_attempts', $attempts );
		$order->save();
	}
}
