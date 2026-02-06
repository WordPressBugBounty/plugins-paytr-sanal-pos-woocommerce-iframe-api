<?php

class PaytrLogManager {
    private $log_dir;
    private $max_log_days = 7; 

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/paytr-logs';
        $this->ensure_log_directory_exists();
        $this->cleanup_old_logs(); // Otomatik temizleme
    }

    private function ensure_log_directory_exists() {
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
    }

    public function log_error($message, $order_id = null, $transaction_id = null, $details = array()) {
        try {
            $log_file = $this->log_dir . '/gunluk-error-' . date('Y-m-d') . '.log';
            
            $log_message = '[' . date('Y-m-d H:i:s') . ']';
            
            if ($transaction_id) {
                $log_message .= ' [İşlem ID: ' . $transaction_id . ']';
            }
            
            if ($order_id) {
                $log_message .= ' [Sipariş ID: ' . $order_id . ']';
            }
            
            $log_message .= ' ' . $message . "\n";
            
            if (!empty($details)) {
                $log_message .= 'DETAYLAR: ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            
            $log_message .= "--------------------------------------------------\n";
            
            file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
            
            return true;
            
        } catch (Exception $e) {
            error_log('PayTR Log Hatası: ' . $e->getMessage());
            return false;
        }
    }

    public function get_log_files($days = 7) {
        $log_files = array();
        $available_dates = array();
        
        // Mevcut log dosyalarını tara
        if (file_exists($this->log_dir)) {
            $files = scandir($this->log_dir);
            foreach ($files as $file) {
                if (preg_match('/gunluk-error-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                    $file_date = $matches[1];
                    $file_path = $this->log_dir . '/' . $file;
                    
                    // Sadece geçerli ve okunabilir dosyaları ekle
                    if (file_exists($file_path) && is_readable($file_path)) {
                        $available_dates[] = $file_date;
                    }
                }
            }
            
            // Tarihlere göre sırala (yeniden eskiye)
            rsort($available_dates);
            
            // İstenen gün sayısı kadarını al
            $available_dates = array_slice($available_dates, 0, $days);
            
            foreach ($available_dates as $date) {
                $log_file = $this->log_dir . '/gunluk-error-' . $date . '.log';
                if (file_exists($log_file)) {
                    $log_files[$date] = array(
                        'file' => $log_file,
                        'size' => $this->format_filesize(filesize($log_file))
                    );
                }
            }
        }
        
        return $log_files;
    }
  
  public function delete_log_by_date($date) {
    try {
        $log_file = $this->log_dir . '/gunluk-error-' . $date . '.log';
        
        if (file_exists($log_file)) {
            if (unlink($log_file)) {
                error_log('PayTR Log Silme: ' . $date . ' tarihli log dosyası silindi.');
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log('PayTR Log Silme Hatası: ' . $e->getMessage());
        return false;
    }
}

    /**
     * Eski log dosyalarını temizler
     */
    public function cleanup_old_logs() {
        try {
            if (!file_exists($this->log_dir)) {
                return 0;
            }

            $deleted_count = 0;
            $cutoff_date = date('Y-m-d', strtotime('-' . $this->max_log_days . ' days'));
            $cutoff_timestamp = strtotime($cutoff_date);

            $files = scandir($this->log_dir);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                
                // Sadece log dosyalarını işle
                if (preg_match('/gunluk-error-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                    $file_date = $matches[1];
                    $file_timestamp = strtotime($file_date);
                    $file_path = $this->log_dir . '/' . $file;
                    
                    // Eski dosyaları sil
                    if ($file_timestamp < $cutoff_timestamp) {
                        if (unlink($file_path)) {
                            $deleted_count++;
                        }
                    }
                }
            }

            // Temizleme işlemini loglayalım (opsiyonel)
            if ($deleted_count > 0) {
                error_log('PayTR Log Temizleme: ' . $deleted_count . ' eski log dosyası silindi.');
            }

            return $deleted_count;
            
        } catch (Exception $e) {
            error_log('PayTR Log Temizleme Hatası: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Manuel olarak tüm logları temizleme (admin panelinden kullanılabilir)
     */
    public function cleanup_all_logs() {
        try {
            if (!file_exists($this->log_dir)) {
                return 0;
            }

            $deleted_count = 0;
            $files = scandir($this->log_dir);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                
                if (preg_match('/gunluk-error-(\d{4}-\d{2}-\d{2})\.log$/', $file)) {
                    $file_path = $this->log_dir . '/' . $file;
                    if (unlink($file_path)) {
                        $deleted_count++;
                    }
                }
            }

            return $deleted_count;
            
        } catch (Exception $e) {
            error_log('PayTR Tüm Logları Temizleme Hatası: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log dosyasının içeriğini getir
     */
    public function get_log_content($date) {
        $log_file = $this->log_dir . '/gunluk-error-' . $date . '.log';
        
        if (file_exists($log_file) && is_readable($log_file)) {
            return file_get_contents($log_file);
        }
        
        return false;
    }

    /**
     * Belirli bir tarihin log dosyasının var olup olmadığını kontrol et
     */
    public function log_exists($date) {
        $log_file = $this->log_dir . '/gunluk-error-' . $date . '.log';
        return file_exists($log_file) && is_readable($log_file);
    }

    public function get_log_dir() {
        return $this->log_dir;
    }

    /**
     * Maksimum log saklama süresini değiştirmek için
     */
    public function set_max_log_days($days) {
        $this->max_log_days = max(1, intval($days)); // En az 1 gün
    }

    /**
     * Mevcut maksimum log saklama süresini getir
     */
    public function get_max_log_days() {
        return $this->max_log_days;
    }

    private function format_filesize($bytes) {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
}