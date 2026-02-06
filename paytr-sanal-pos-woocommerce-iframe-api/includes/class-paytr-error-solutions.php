<?php

class PaytrErrorSolutions {
    

    public static function get_user_friendly_error($reason) {
        $error_mapping = array(
            'user_phone' => 'Telefon numarası geçersiz veya eksik. Lütfen geçerli bir telefon numarası girin.',
          	'paytr_token' => 'Ödeme isteği sırasında hatalı token iletildi',
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
}