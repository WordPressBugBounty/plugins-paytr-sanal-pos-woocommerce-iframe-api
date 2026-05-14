<?php

class PaytrErrorSolutions {
    public function get_solutions() {
        return array(
            '005' => array(
                'title' => 'Iade hatasi 005',
                'solution' => 'PayTR iade servisi gonderilen merchant_oid icin basarili odeme bulamadi. WooCommerce siparisindeki paytr_order_id, PayTR panelindeki merchant_oid, aktif Merchant ID ve test/canli modu birebir karsilastirin. Durum Sorgu API success donuyor ama iade 005 donuyorsa PayTR iade servisi loglari incelenmelidir.'
            ),
            'merchant_oid' => array(
                'title' => 'Merchant OID eslesmesi',
                'solution' => 'Siparis numarasi ile merchant_oid ayni deger degildir. PayTR islemini ararken tam merchant_oid degerini kullanin. Ayni WooCommerce siparisinde birden fazla odeme denemesi varsa siparis detayindaki PayTR Odeme Denemeleri alanini kontrol edin.'
            ),
            'paytr_token' => array(
                'title' => 'Token dogrulama hatasi',
                'solution' => 'Merchant Key veya Merchant Salt hatali olabilir. PayTR panelindeki API bilgileri ile WooCommerce ayarlarindaki degerleri kopyala-yapistir kaynakli bosluklar dahil kontrol edin.'
            ),
            'callback hash' => array(
                'title' => 'Callback hash dogrulama hatasi',
                'solution' => 'Callback PayTRden geliyor olsa bile Merchant Key/Salt uyusmazsa hash dogrulama gecmez. Callback URLnin dogru magazaya bagli oldugunu ve WooCommerce ayarlarindaki API bilgilerinin guncel oldugunu kontrol edin.'
            ),
            'iade istegi gonderilemedi' => array(
                'title' => 'Iade istegi gonderilemedi',
                'solution' => 'WordPress sunucusu PayTR iade endpointine ulasamamis. Sunucuda cURL, SSL sertifika dogrulamasi, firewall ve dis baglanti izinleri kontrol edilmelidir.'
            ),
            'iade cevabi okunamadi' => array(
                'title' => 'PayTR cevabi okunamadi',
                'solution' => 'PayTRden beklenen JSON formatinda cevap alinamadi. Ham cevabi kontrol edin; HTML hata sayfasi, proxy cevabi veya gecici servis yaniti olabilir.'
            ),
            'user_phone' => array(
                'title' => 'Telefon bilgisi gecersiz',
                'solution' => 'Musteri telefon numarasi PayTR format beklentisini karsilamiyor olabilir. Bosluk, ulke kodu ve eksik hane durumlarini kontrol edin.'
            ),
            'user_email' => array(
                'title' => 'E-posta bilgisi gecersiz',
                'solution' => 'Musteri e-posta adresi bos veya gecersiz formatta olabilir. WooCommerce fatura e-posta alanini kontrol edin.'
            ),
            'payment_amount' => array(
                'title' => 'Odeme tutari gecersiz',
                'solution' => 'Siparis toplam tutari 0 veya beklenmeyen formatta olabilir. Kupon, vergi, kargo ve para birimi hesaplarini kontrol edin.'
            ),
        );
    }
    

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
