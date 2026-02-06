<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
};

class PaytrCheckoutCallbackIframe {
	public static function callback_iframe( $post )
    {
		// Get PayTR Options
		$options = get_option( 'woocommerce_paytr_payment_gateway_settings' );

		$hash = base64_encode( hash_hmac( 'sha256', sanitize_text_field( $post['merchant_oid'] ) . $options['paytr_merchant_salt'] . sanitize_text_field( $post['status'] ) . sanitize_text_field( $post['total_amount'] ), $options['paytr_merchant_key'], true ) );

		if ( $hash != sanitize_text_field( $post['hash'] ) ) {
		            $core = new PaytrCoreClass();
        $error_details = array(
            'istek_verisi' => $post,
            'hesaplanan_hash' => $hash,
            'gelen_hash' => sanitize_text_field($post['hash']),
            'kullanici_ip' => $_SERVER['REMOTE_ADDR'],
            'tarih' => date('Y-m-d H:i:s')
        );
        
        $core->log_error("PAYTR callback hash doğrulama hatası", null, sanitize_text_field($post['merchant_oid']), $error_details);
        
        die( 'PAYTR notification failed: bad hash' );
		}

		$order_id = explode( 'PAYTRWOO', sanitize_text_field( $post['merchant_oid'] ) );
		$order    = wc_get_order( $order_id[1] );
		if ( $order->get_status() == 'pending' or $order->get_status() == 'failed' ) {
			if ( sanitize_text_field( $post['status'] ) == 'success' ) {
				// Reduce Stock Levels
				wc_reduce_stock_levels( $order_id[1] );

				$total_amount    = round( sanitize_text_field( $post['total_amount'] ) / 100, 2 );
				$payment_amount  = round( sanitize_text_field( $post['payment_amount'] ) / 100, 2 );
				$installment_dif = $total_amount - $payment_amount;

				// Note Start
				$note = __( 'PAYTR NOTIFICATION - Payment Accepted', 'paytr-payment-gateway' ) . "\n";
				$note .= __( 'Total Paid', 'paytr-payment-gateway' ) . ': ' . sanitize_text_field( wc_price( $total_amount, array( 'currency' => $order->get_currency() ) ) ) . "\n";
				$note .= __( 'Paid', 'paytr-payment-gateway' ) . ': ' . sanitize_text_field( wc_price( $payment_amount, array( 'currency' => $order->get_currency() ) ) ) . "\n";

				if ( $installment_dif > 0 ) {
    if ( $options['paytr_ins_difference'] == 'yes' ) {
        $installment_fee = new WC_Order_Item_Fee();
        $installment_fee->set_name( __( 'Vade Farkı', 'paytr-payment-gateway' ) );
        
        // vergi dahil rakamsa, içinden KDV'yi çıkarıp net tutarı (matrahı) buluyoruz.
        // Formül: Brüt / 1.20
        $net_amount = (float) $installment_dif / 1.20;

        $installment_fee->set_tax_status( 'taxable' );
        $installment_fee->set_tax_class( '' ); // Standart %20 oranını kullanır
        
        // WooCommerce'e vergi hariç (net) tutarı gönderiyoruz
        $installment_fee->set_total( $net_amount ); 
        
        $order->add_item( $installment_fee );

        // false parametresi veya boş bırakmak, mevcut fiyatların vergi dahil/hariç ayarına göre hesaplanmasını sağlar
        $order->calculate_totals( true ); 
        $order->save();
    }

    $note .= 'Vade Farkı: ' . wc_price( $installment_dif, array( 'currency' => $order->get_currency() ) ) . "\n";
}

				if ( array_key_exists( 'installment_count', $post ) ) {
					$note .= __( 'Installment Count', 'paytr-payment-gateway' ) . ': ' . ( sanitize_text_field( $post['installment_count'] ) == 1 ? __( 'One Shot', 'paytr-payment-gateway' ) : sanitize_text_field( $post['installment_count'] ) ) . "\n";
				}

				$note .= __( 'PayTR Order ID', 'paytr-payment-gateway' ) . ': <a href="https://www.paytr.com/magaza/islemler?merchant_oid=' . sanitize_text_field( $post['merchant_oid'] ) . '" target="_blank">' . sanitize_text_field( $post['merchant_oid'] ) . '</a>';
				// Note End
				$order->add_order_note( nl2br( $note ) );
				$order->update_status( $options['paytr_order_status'] );
                $order->save();
			} else {
				// Note Start
				$note = __( 'PAYTR NOTIFICATION - Payment Failed', 'paytr-payment-gateway' ) . "\n";
				$note .= __( 'Error', 'paytr-payment-gateway' ) . ': ' . sanitize_text_field( $post['failed_reason_code'] ) . ' - ' . sanitize_text_field( $post['failed_reason_msg'] ) . "\n";
				$note .= __( 'PayTR Order ID', 'paytr-payment-gateway' ) . ': <a href="https://www.paytr.com/magaza/islemler?merchant_oid=' . sanitize_text_field( $post['merchant_oid'] ) . '" target="_blank">' . sanitize_text_field( $post['merchant_oid'] ) . '</a>';
				$order->add_order_note( nl2br( $note ) );
				$order->update_status( 'failed' );
                $order->save();
			}
		}

		echo 'OK';
		exit;
	}
}
