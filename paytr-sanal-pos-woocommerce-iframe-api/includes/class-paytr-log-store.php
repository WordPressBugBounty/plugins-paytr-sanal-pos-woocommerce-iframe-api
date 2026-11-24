<?php
if (!defined('ABSPATH')) {
    exit;
}

// REVIEW WP-08: Loglar HTTP ile sunulabilecek dosyalara degil, siteye ait DB tablosuna yazilir.
class PaytrLogStore {
    private $table;
    private $ready = false;
    private $migration_failed = false;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'paytr_logs';
        if (get_option('paytr_log_schema_version') !== '1') {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
            $charset = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE {$this->table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                log_date date NOT NULL,
                entry_key varchar(64) NOT NULL,
                content longtext NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY entry_key (entry_key),
                KEY log_date_id (log_date,id)
            ) {$charset};");
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->table)));
            if ($exists === $this->table) {
                update_option('paytr_log_schema_version', '1', false);
            }
        }
        $this->ready = get_option('paytr_log_schema_version') === '1';
        // Her istekte kalan eski dosyalari tekrar dene; basarili aktarimdan once silme.
        $this->migrate_legacy_logs();
        if (!$this->ready || $this->migration_failed) {
            add_action('admin_notices', array($this, 'storage_notice'));
        }
    }

    public function storage_notice() {
        if (current_user_can('manage_woocommerce')) {
            echo '<div class="notice notice-error"><p>' . esc_html(
                'PayTR: Log veritabani hazirlanamadi veya eski herkese acik log dosyalari kaldirilamadi. Log guvenligi gecisi tamamlanmadi; dosya/veritabani izinlerini kontrol edin.'
            ) . '</p></div>';
        }
    }

    public function append($date, $content, $key = null) {
        global $wpdb;
        if (!$this->ready || !$this->valid_date($date)) {
            return false; // DB hatasinda public dosyaya veya PHP error loguna hassas veri yazma.
        }
        $key = $key === null ? hash('sha256', wp_generate_uuid4()) : $key;
        $previous = $wpdb->suppress_errors();
        try {
            return $wpdb->insert($this->table, array('log_date' => $date, 'entry_key' => $key, 'content' => $content), array('%s', '%s', '%s')) !== false;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    public function dates($limit) {
        global $wpdb;
        if (!$this->ready) {
            return array();
        }
        return (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT log_date FROM {$this->table} ORDER BY log_date DESC LIMIT %d", max(1, (int) $limit)));
    }

    public function size($date) {
        global $wpdb;
        if (!$this->ready || !$this->valid_date($date)) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(OCTET_LENGTH(content)) FROM {$this->table} WHERE log_date = %s", $date));
    }

    public function read($date, $limit = null) {
        global $wpdb;
        if (!$this->ready || !$this->valid_date($date)) {
            return false;
        }
        $sql = $wpdb->prepare("SELECT content FROM {$this->table} WHERE log_date = %s ORDER BY id DESC", $date);
        if ($limit !== null) {
            $sql .= $wpdb->prepare(' LIMIT %d', max(1, (int) $limit));
        }
        $entries = (array) $wpdb->get_col($sql);
        return $entries ? implode('', array_reverse($entries)) : false;
    }

    public function delete_date($date) {
        global $wpdb;
        return $this->ready && $this->valid_date($date)
            ? $wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE log_date = %s", $date)) : false;
    }

    public function cleanup($before = null) {
        global $wpdb;
        if (!$this->ready) {
            return false;
        }
        if ($before !== null && !$this->valid_date($before)) {
            return false;
        }
        return $wpdb->query($before === null ? "DELETE FROM {$this->table}"
            : $wpdb->prepare("DELETE FROM {$this->table} WHERE log_date < %s", $before));
    }

    private function valid_date($date) {
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    // REVIEW WP-09: Eski public log DB'ye eksiksiz aktarilip okunarak dogrulaninca silinir.
    private function migrate_legacy_logs() {
        global $wpdb;
        $uploads = wp_upload_dir();
        $directory = $uploads['basedir'] . '/paytr-logs';
        if (!is_dir($directory)) {
            return;
        }
        if (is_link($directory)) {
            $this->migration_failed = true;
            return;
        }
        $files = @scandir($directory);
        if ($files === false) {
            $this->migration_failed = true;
            return;
        }
        foreach ($files as $file) {
            if (!preg_match('/^gunluk-error-(\d{4}-\d{2}-\d{2})(?:-\d+)?\.log$/D', $file, $match)) {
                continue;
            }
            $path = $directory . '/' . $file;
            if (!$this->ready || is_link($path) || !is_file($path)) {
                $this->migration_failed = true;
                continue;
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                $this->migration_failed = true;
                continue;
            }
            $key = hash('sha256', $path . "\0" . $content);
            $saved = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$this->table} WHERE entry_key = %s", $key));
            if ($saved === null) {
                $this->append($match[1], $content, $key);
                $saved = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$this->table} WHERE entry_key = %s", $key));
            }
            // Kayit yazilamazsa veya dosya aktarim sirasinda degisirse kaynak korunur ve uyari verilir.
            if ($saved !== $content || @file_get_contents($path) !== $content || !@unlink($path)) {
                if (file_exists($path)) {
                    $this->migration_failed = true;
                }
            }
        }
    }
}
