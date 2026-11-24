<?php

class PaytrLogManager {
    private $store;
    private $max_log_days = 7;

    public function __construct() {
        require_once __DIR__ . '/class-paytr-log-store.php';
        $this->store = new PaytrLogStore();
        $this->max_log_days = $this->get_configured_max_log_days();
        $this->cleanup_old_logs();
    }

    public function log_error($message, $order_id = null, $transaction_id = null, $details = array()) {
        try {
            if (!$this->should_log($message)) {
                return true;
            }

            $log_message = '[' . date('Y-m-d H:i:s') . ']';

            if ($transaction_id) {
                $log_message .= ' [Islem ID: ' . $transaction_id . ']';
            }

            if ($order_id) {
                $log_message .= ' [Siparis ID: ' . $order_id . ']';
            }

            $log_message .= ' ' . $message . "\n";

            $details = $this->prepare_details_for_logging($details);

            if (!empty($details)) {
                $log_message .= 'DETAYLAR: ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }

            $log_message .= "--------------------------------------------------\n";

            if (!$this->store->append(date('Y-m-d'), $log_message)) {
                return false;
            }
            $this->maybe_send_repeated_error_notification($message, $order_id, $transaction_id, $details);

            return true;
        } catch (Exception $e) {
            error_log('PayTR log kaydi yazilamadi.');
            return false;
        }
    }

    // REVIEW WP-08: Panel ayni arayuzu kullanir; dosya yollarinin yerine DB gun anahtarlari doner.
    public function get_log_files($days = 7) {
        $logs = array();
        foreach ($this->store->dates($days) as $date) {
            $key = 'paytr-db:' . $date;
            $logs[$date] = array('file' => $key, 'files' => array($key),
                'size' => $this->format_filesize($this->store->size($date)));
        }
        return $logs;
    }

    public function get_log_files_by_date($date) {
        return $this->store->size($date) > 0 ? array('paytr-db:' . $date) : array();
    }

    public function delete_log_by_date($date) {
        return $this->store->delete_date($date) > 0;
    }

    public function cleanup_old_logs() {
        return $this->store->cleanup(date('Y-m-d', strtotime('-' . $this->max_log_days . ' days')));
    }

    public function cleanup_all_logs() {
        return $this->store->cleanup();
    }

    public function get_log_content($date) {
        return $this->store->read($date);
    }

    public function read_log_file($key, $limit = null) {
        return is_string($key) && strpos($key, 'paytr-db:') === 0
            ? $this->store->read(substr($key, 9), $limit) : false;
    }

    public function get_log_file_size($key) {
        return is_string($key) && strpos($key, 'paytr-db:') === 0
            ? $this->store->size(substr($key, 9)) : 0;
    }

    public function log_exists($date) {
        return $this->store->size($date) > 0;
    }

    public function get_log_dir() {
        return ''; // Artik dosya tabanli bir log dizini yok.
    }

    public function set_max_log_days($days) {
        $this->max_log_days = max(1, intval($days));
    }

    public function get_max_log_days() {
        return $this->max_log_days;
    }

    private function get_configured_max_log_days() {
        $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());
        $days = isset($settings['paytr_log_retention_days']) ? intval($settings['paytr_log_retention_days']) : $this->max_log_days;

        return max(1, $days);
    }

    private function should_log($message) {
        $level = $this->get_log_level();

        if ($level === 'off') {
            return false;
        }

        if ($level === 'all') {
            return true;
        }

        if ($level === 'refunds' && $this->is_refund_log($message)) {
            return true;
        }

        return $this->is_notifiable_error($message);
    }

    private function get_log_level() {
        $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());
        $level = isset($settings['paytr_log_level']) ? sanitize_text_field($settings['paytr_log_level']) : 'errors';
        $allowed_levels = array('off', 'errors', 'refunds', 'all');

        return in_array($level, $allowed_levels, true) ? $level : 'errors';
    }

    private function is_refund_log($message) {
        $normalized_message = strtolower(remove_accents($message));

        return strpos($normalized_message, 'iade') !== false || strpos($normalized_message, 'refund') !== false;
    }

    private function prepare_details_for_logging($details) {
        if (empty($details)) {
            return $details;
        }

        unset($details['ham_cevap']);
        unset($details['paytr_token']);

        if (isset($details['paytr_cevabi']) && is_array($details['paytr_cevabi'])) {
            $allowed_response_keys = array('status', 'reason', 'err_no', 'err_msg', 'merchant_oid', 'return_amount', 'is_test', 'reference_no');
            $details['paytr_cevabi'] = array_intersect_key($details['paytr_cevabi'], array_flip($allowed_response_keys));
        }

        $details = $this->mask_customer_details($details);

        return $details;
    }

    private function mask_customer_details($details) {
        if (!isset($details['order_details']) || !is_array($details['order_details'])) {
            return $details;
        }

        $maskers = array(
            'user_name' => 'mask_name',
            'user_phone' => 'mask_phone',
            'user_email' => 'mask_email',
            'user_address' => 'mask_text',
        );

        foreach ($maskers as $field => $method) {
            if (isset($details['order_details'][$field])) {
                $details['order_details'][$field] = $this->$method($details['order_details'][$field]);
            }
        }

        return $details;
    }

    private function mask_name($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $value);
        foreach ($parts as $index => $part) {
            $parts[$index] = $this->mask_text($part);
        }

        return implode(' ', $parts);
    }

    private function mask_email($value) {
        $value = trim((string) $value);
        if ($value === '' || strpos($value, '@') === false) {
            return $this->mask_text($value);
        }

        list($local, $domain) = explode('@', $value, 2);

        return $this->mask_text($local) . '@' . $domain;
    }

    private function mask_phone($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($value, -4);
    }

    private function mask_text($value) {
        $value = (string) $value;
        $length = strlen($value);

        if ($length === 0) {
            return '';
        }

        return substr($value, 0, 1) . str_repeat('*', max(0, $length - 1));
    }

    private function maybe_send_repeated_error_notification($message, $order_id = null, $transaction_id = null, $details = array()) {
        $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());

        if (isset($settings['paytr_error_email_alerts']) && $settings['paytr_error_email_alerts'] !== 'yes') {
            return;
        }

        if (!$this->is_notifiable_error($message)) {
            return;
        }

        $error_key = hash('sha256', $this->normalize_error_message($message));
        $count_key = 'paytr_error_count_' . $error_key;
        $sent_key = 'paytr_error_sent_' . $error_key;
        $count = intval(get_transient($count_key)) + 1;

        set_transient($count_key, $count, 30 * MINUTE_IN_SECONDS);

        if ($count < 3 || get_transient($sent_key) || !$this->can_send_hourly_error_email()) {
            return;
        }

        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return;
        }

        $subject = 'PayTR tekrarlayan hata bildirimi';
        $body = "PayTR modulu ayni hata icin son 30 dakika icinde {$count} kayit olusturdu.\n\n";
        $body .= 'Hata: ' . $message . "\n";

        if ($order_id) {
            $body .= 'Siparis ID: ' . $order_id . "\n";
        }

        if ($transaction_id) {
            $body .= 'PayTR Islem ID: ' . $transaction_id . "\n";
        }

        if (!empty($details)) {
            $body .= "\nDetaylar:\n" . wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        wp_mail($admin_email, $subject, $body);
        $this->mark_hourly_error_email_sent();
        set_transient($sent_key, 1, HOUR_IN_SECONDS);
    }

    private function can_send_hourly_error_email() {
        return intval(get_transient('paytr_error_mail_hourly_count')) < $this->get_hourly_error_email_limit();
    }

    private function mark_hourly_error_email_sent() {
        $count = intval(get_transient('paytr_error_mail_hourly_count')) + 1;
        set_transient('paytr_error_mail_hourly_count', $count, HOUR_IN_SECONDS);
    }

    private function get_hourly_error_email_limit() {
        $settings = get_option('woocommerce_paytr_payment_gateway_settings', array());
        $limit = isset($settings['paytr_error_email_hourly_limit']) ? intval($settings['paytr_error_email_hourly_limit']) : 5;

        return max(1, min(5, $limit));
    }

    private function is_notifiable_error($message) {
        $normalized_message = strtolower(remove_accents($message));

        if (strpos($normalized_message, 'basarili') !== false) {
            return false;
        }

        $error_terms = array('hata', 'hatasi', 'error', 'failed', 'gonderilemedi', 'okunamadi', 'dogrulama');

        foreach ($error_terms as $term) {
            if (strpos($normalized_message, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalize_error_message($message) {
        $message = strtolower(remove_accents($message));
        $message = preg_replace('/\d+/', '#', $message);

        return trim($message);
    }

    private function format_filesize($bytes) {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    private function get_total_filesize($files) {
        $total_size = 0;

        foreach ($files as $file) {
            if (file_exists($file)) {
                $total_size += filesize($file);
            }
        }

        return $total_size;
    }
}
