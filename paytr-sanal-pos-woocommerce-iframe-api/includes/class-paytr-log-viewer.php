<?php

class PaytrLogViewer {
    private $log_manager;
    private $error_solutions;

    public function __construct() {
        $this->log_manager = new PaytrLogManager();
        $this->error_solutions = new PaytrErrorSolutions();
    }

    public function display_logs_page() {
        // Güvenlik kontrolü
        if (!wp_verify_nonce($_GET['nonce'], 'paytr_view_logs') || !current_user_can('manage_woocommerce')) {
            wp_die('Güvenlik hatası!');
        }

        // Temizleme işlemleri
        $cleanup_message = '';
        if (isset($_GET['cleanup_old'])) {
            $deleted_count = $this->log_manager->cleanup_old_logs();
            $cleanup_message = '<div class="notice notice-success"><p>' . $deleted_count . ' eski log dosyası başarıyla silindi.</p></div>';
        }

        if (isset($_GET['cleanup_all'])) {
            $deleted_count = $this->log_manager->cleanup_all_logs();
            $cleanup_message = '<div class="notice notice-success"><p>Tüm log dosyaları başarıyla silindi. Toplam: ' . $deleted_count . ' dosya</p></div>';
        }

        // Seçili tarihi temizleme işlemi
        if (isset($_GET['cleanup_selected'])) {
            $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
            $result = $this->log_manager->delete_log_by_date($selected_date);
            if ($result) {
                $cleanup_message = '<div class="notice notice-success"><p>Seçilen tarih ('.$selected_date.') log dosyası başarıyla silindi.</p></div>';
                // Log dosyası silindiği için seçili tarih verisini sıfırla
                $selected_log_data = null;
            } else {
                $cleanup_message = '<div class="notice notice-error"><p>Seçilen tarih ('.$selected_date.') log dosyası silinemedi veya dosya bulunamadı.</p></div>';
            }
        }

        // Parametreleri al
        $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        // Geçerli per_page değerleri
        $per_page_options = array(25, 50, 100, 200);
        if (!in_array($per_page, $per_page_options)) {
            $per_page = 25;
        }

        $log_files = $this->log_manager->get_log_files($this->log_manager->get_max_log_days());

        // Seçilen tarihin loglarını al (eğer silinmediyse)
        $selected_log_data = null;
        $selected_date_files = $this->log_manager->get_log_files_by_date($selected_date);
        
        if (!empty($selected_date_files) && !isset($_GET['cleanup_selected'])) {
            $selected_log_data = array(
                'file' => $selected_date_files[0],
                'files' => $selected_date_files,
                'size' => $this->format_filesize($this->get_total_filesize($selected_date_files)),
                'exists' => true
            );
        }

        // Log kayıtlarını parse et ve sayfala
        $all_entries = array();
        $total_entries = 0;
        $paginated_entries = array();
        
        if ($selected_log_data) {
            $content = $this->read_recent_log_content($selected_log_data['files'], 200);
            $all_entries = $this->parse_log_entries($content);
            $total_entries = count($all_entries);
            
            // Sayfalama
            $offset = ($current_page - 1) * $per_page;
            $paginated_entries = array_slice($all_entries, $offset, $per_page);
        }

        // Toplam sayfa sayısı
        $total_pages = ceil($total_entries / $per_page);
        $solutions = $this->error_solutions->get_solutions();
        $retention_days = $this->log_manager->get_max_log_days();
        $summary_stats = $this->get_log_summary_stats($all_entries);

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>PayTR Hata Günlükleri</title>
            <meta charset="UTF-8">
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 20px; 
                    background: #f1f1f1; 
                }
                .log-container { 
                    background: white; 
                    padding: 20px; 
                    border-radius: 5px; 
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
                }
                .cleanup-actions {
                    background: #147EC2;
                    padding: 15px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                    border: 1px solid #ffeaa7;
                }
                .cleanup-buttons {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .cleanup-btn {
                    background: #fd7e14;
                    color: white;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                    font-size: 14px;
                    transition: background 0.3s;
                }
                .cleanup-btn:hover {
                    background: #e8590c;
                    color: white;
                }
                .cleanup-btn.danger {
                    background: #dc3545;
                }
                .cleanup-btn.danger:hover {
                    background: #c82333;
                }
                .cleanup-btn.warning {
                    background: #ffc107;
                    color: #212529;
                }
                .cleanup-btn.warning:hover {
                    background: #e0a800;
                    color: #212529;
                }
                .cleanup-info {
                    font-size: 12px;
                    color: #f1f1f1;
                    margin-top: 8px;
                }
                .notice {
                    padding: 12px;
                    margin: 15px 0;
                    border-radius: 4px;
                    border-left: 4px solid;
                }
                .notice-success {
                    background: #d4edda;
                    border-color: #28a745;
                    color: #155724;
                }
                .notice-error {
                    background: #f8d7da;
                    border-color: #dc3545;
                    color: #721c24;
                }
                .filters-container {
                    background: #f8f8f8;
                    padding: 15px;
                    border-radius: 5px;
                    margin-bottom: 20px;
                    border: 1px solid #e0e0e0;
                }
                .filter-group {
                    display: flex;
                    gap: 15px;
                    align-items: center;
                    flex-wrap: wrap;
                }
                .filter-item {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                }
                .filter-item label {
                    font-size: 12px;
                    font-weight: bold;
                    color: #555;
                }
                .filter-select, .filter-input {
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    background: white;
                    min-width: 120px;
                }
                .filter-button {
                    background: #0073aa;
                    color: white;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    cursor: pointer;
                    transition: background 0.3s;
                    align-self: flex-end;
                }
                .filter-button:hover {
                    background: #005a87;
                }
                .selected-date-actions {
                    background: #e7f3ff;
                    padding: 12px 15px;
                    border-radius: 4px;
                    margin-bottom: 15px;
                    border-left: 4px solid #0073aa;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .selected-date-info {
                    font-size: 14px;
                    color: #333;
                }
                .delete-selected-btn {
                    background: #dc3545;
                    color: white;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 3px;
                    cursor: pointer;
                    text-decoration: none;
                    font-size: 12px;
                    transition: background 0.3s;
                }
                .delete-selected-btn:hover {
                    background: #c82333;
                }
                .stats-container {
                    background: #e7f3ff;
                    padding: 10px 15px;
                    border-radius: 4px;
                    margin-bottom: 15px;
                    border-left: 4px solid #0073aa;
                }
                .stats-info {
                    font-size: 14px;
                    color: #333;
                    display: flex;
                    gap: 15px;
                    flex-wrap: wrap;
                }
                .stat-item {
                    display: flex;
                    align-items: center;
                    gap: 5px;
                }
                .stat-badge {
                    background: #0073aa;
                    color: white;
                    padding: 2px 8px;
                    border-radius: 10px;
                    font-size: 12px;
                    font-weight: bold;
                }
                .pagination {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin: 20px 0;
                    padding: 15px;
                    background: #f8f8f8;
                    border-radius: 5px;
                }
                .pagination-info {
                    font-size: 14px;
                    color: #666;
                }
                .pagination-links {
                    display: flex;
                    gap: 5px;
                }
                .page-numbers {
                    display: flex;
                    gap: 5px;
                }
                .pagination a, .pagination span {
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 3px;
                    text-decoration: none;
                    color: #0073aa;
                    font-size: 14px;
                }
                .pagination a:hover {
                    background: #0073aa;
                    color: white;
                    border-color: #0073aa;
                }
                .pagination .current {
                    background: #0073aa;
                    color: white;
                    border-color: #0073aa;
                }
                .pagination .disabled {
                    color: #ccc;
                    cursor: not-allowed;
                }
                .log-file { 
                    margin-bottom: 30px; 
                    border: 1px solid #ddd; 
                    padding: 0;
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 3px 10px rgba(0,0,0,0.1);
                    background: white;
                }
                .log-date { 
                    background: #0073aa; 
                    color: white; 
                    padding: 15px 20px; 
                    margin: 0;
                    font-weight: bold; 
                    display: flex; 
                    justify-content: space-between; 
                    align-items: center; 
                    font-size: 16px;
                }
                .log-content { 
                    padding: 0;
                }
                .log-entry {
                    background: #fafafa;
                    margin: 0;
                    padding: 15px;
                    border-bottom: 1px solid #eaeaea;
                    position: relative;
                }
                .log-entry:last-child {
                    border-bottom: none;
                }
                .log-entry:nth-child(even) {
                    background: #f8f8f8;
                }
                .log-entry:hover {
                    background: #f0f7ff;
                }
                .log-message {
                    font-family: monospace; 
                    white-space: pre-wrap;
                    margin: 0;
                    color: #333;
                    line-height: 1.5;
                    font-size: 13px;
                }
                .log-details {
                    background: #e9f7fe;
                    padding: 10px;
                    border-radius: 4px;
                    margin-top: 8px;
                    font-size: 12px;
                    color: #555;
                    border-left: 3px solid #0073aa;
                }
                .no-logs { 
                    text-align: center; 
                    padding: 40px; 
                    color: #666; 
                    background: #f9f9f9;
                    border-radius: 5px;
                    margin: 20px 0;
                }
                .solution-btn { 
                    background: #46b450; 
                    color: white; 
                    border: none; 
                    padding: 6px 12px; 
                    margin-top: 8px;
                    border-radius: 3px; 
                    cursor: pointer; 
                    font-size: 11px;
                    transition: all 0.3s;
                    display: inline-block;
                }
                .solution-btn:hover { 
                    background: #3a9540;
                    transform: translateY(-1px);
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                }
                .file-size {
                    font-size: 12px;
                    color: #fff;
                    background: rgba(255,255,255,0.2);
                    padding: 4px 8px;
                    border-radius: 12px;
                    font-weight: normal;
                }
                .log-entry-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    margin-bottom: 5px;
                }
                .log-timestamp {
                    font-size: 11px;
                    color: #888;
                    background: #e9e9e9;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-weight: bold;
                }
                .log-order-info {
                    font-size: 11px;
                    color: #666;
                    margin-top: 5px;
                }
                .log-order-info span {
                    background: #e9e9e9;
                    padding: 1px 4px;
                    border-radius: 2px;
                    margin-right: 5px;
                }

                /* Modal/Popup Styles */
                .solution-modal {
                    display: none;
                    position: fixed;
                    z-index: 1000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0,0,0,0.5);
                    animation: fadeIn 0.3s;
                }
                .modal-content {
                    background-color: #fff;
                    margin: 10% auto;
                    padding: 0;
                    border-radius: 8px;
                    width: 80%;
                    max-width: 600px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
                    animation: slideIn 0.3s;
                }
                .modal-header {
                    background: #0073aa;
                    color: white;
                    padding: 15px 20px;
                    border-radius: 8px 8px 0 0;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .modal-header h3 {
                    margin: 0;
                    font-size: 18px;
                }
                .close-modal {
                    color: white;
                    font-size: 24px;
                    font-weight: bold;
                    cursor: pointer;
                    background: none;
                    border: none;
                    padding: 0;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .close-modal:hover {
                    opacity: 0.8;
                }
                .modal-body {
                    padding: 20px;
                    line-height: 1.6;
                }
                .solution-title {
                    color: #0073aa;
                    font-weight: bold;
                    margin-bottom: 10px;
                    font-size: 16px;
                    border-bottom: 2px solid #f0f0f0;
                    padding-bottom: 8px;
                }
                .solution-text {
                    color: #333;
                    font-size: 14px;
                }

                /* Animations */
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes slideIn {
                    from { 
                        opacity: 0;
                        transform: translateY(-50px);
                    }
                    to { 
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                /* Responsive */
                @media (max-width: 768px) {
                    .modal-content {
                        width: 95%;
                        margin: 5% auto;
                    }
                    body {
                        margin: 10px;
                    }
                    .log-date {
                        flex-direction: column;
                        align-items: flex-start;
                        gap: 10px;
                    }
                    .log-entry-header {
                        flex-direction: column;
                        gap: 5px;
                    }
                    .filter-group {
                        flex-direction: column;
                        align-items: stretch;
                    }
                    .filter-item {
                        width: 100%;
                    }
                    .pagination {
                        flex-direction: column;
                        gap: 10px;
                        text-align: center;
                    }
                    .stats-info {
                        flex-direction: column;
                        gap: 8px;
                    }
                    .cleanup-buttons {
                        flex-direction: column;
                    }
                    .selected-date-actions {
                        flex-direction: column;
                        gap: 10px;
                        align-items: flex-start;
                    }
                }
                body {
                    margin: 0;
                    background: #f6f7f9;
                    color: #1d2327;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                }
                .log-container {
                    max-width: 1180px;
                    margin: 0 auto;
                    padding: 28px;
                    background: transparent;
                    box-shadow: none;
                }
                .paytr-page-header {
                    display: flex;
                    justify-content: space-between;
                    gap: 20px;
                    align-items: flex-start;
                    margin-bottom: 18px;
                }
                .log-container > h1:first-child {
                    display: none;
                }
                .paytr-page-header h1 {
                    margin: 0 0 6px;
                    font-size: 28px;
                    line-height: 1.2;
                    color: #111827;
                }
                .paytr-page-subtitle {
                    margin: 0;
                    color: #646970;
                    font-size: 14px;
                }
                .paytr-retention-pill {
                    display: inline-flex;
                    align-items: center;
                    padding: 7px 11px;
                    border-radius: 999px;
                    background: #eef6ff;
                    color: #0969a8;
                    border: 1px solid #cde7ff;
                    font-size: 12px;
                    font-weight: 700;
                    white-space: nowrap;
                }
                .paytr-summary-grid {
                    display: grid;
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                    gap: 12px;
                    margin: 18px 0;
                }
                .paytr-summary-card {
                    background: #fff;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    padding: 16px;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
                }
                .paytr-summary-label {
                    display: block;
                    color: #646970;
                    font-size: 12px;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: .04em;
                }
                .paytr-summary-value {
                    display: block;
                    margin-top: 8px;
                    font-size: 26px;
                    font-weight: 750;
                    color: #111827;
                }
                .cleanup-actions, .filters-container, .stats-container, .selected-date-actions, .pagination, .log-file {
                    background: #fff;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
                }
                .cleanup-actions {
                    padding: 16px;
                    color: #1d2327;
                    border-left: 4px solid #2271b1;
                }
                .cleanup-actions h3 {
                    margin: 0 0 12px;
                    color: #111827;
                }
                .cleanup-info {
                    color: #646970;
                }
                .cleanup-btn, .filter-button, .delete-selected-btn, .solution-btn {
                    border-radius: 6px;
                    font-weight: 700;
                    box-shadow: none;
                    transform: none;
                }
                .cleanup-btn {
                    background: #f6f7f9;
                    color: #1d2327;
                    border: 1px solid #dcdcde;
                }
                .cleanup-btn:hover {
                    background: #eef6ff;
                    color: #0969a8;
                    border-color: #9ecff5;
                }
                .cleanup-btn.danger, .delete-selected-btn {
                    background: #fff1f1;
                    color: #b42318;
                    border: 1px solid #ffd1d1;
                }
                .cleanup-btn.danger:hover, .delete-selected-btn:hover {
                    background: #ffdede;
                    color: #8a1f17;
                }
                .filters-container {
                    padding: 16px;
                }
                .filter-item label {
                    color: #3c434a;
                }
                .filter-select, .filter-input {
                    border-radius: 6px;
                    border-color: #c3c4c7;
                    min-height: 36px;
                }
                .filter-button {
                    background: #2271b1;
                    min-height: 36px;
                }
                .filter-button:hover {
                    background: #135e96;
                }
                .stats-container, .selected-date-actions {
                    padding: 14px 16px;
                    border-left: 4px solid #72aee6;
                }
                .stat-badge {
                    border-radius: 999px;
                    background: #f0f6fc;
                    color: #0969a8;
                }
                .log-file {
                    overflow: hidden;
                }
                .log-date {
                    background: #111827;
                    color: #fff;
                    padding: 16px 18px;
                }
                .log-entry {
                    padding: 18px;
                    background: #fff;
                    border-bottom: 1px solid #eef0f2;
                }
                .log-entry:hover {
                    background: #fbfcfd;
                }
                .log-entry.is-success {
                    border-left: 4px solid #00a32a;
                }
                .log-entry.is-error {
                    border-left: 4px solid #d63638;
                }
                .log-entry.is-warning {
                    border-left: 4px solid #dba617;
                }
                .paytr-status-badge {
                    display: inline-flex;
                    align-items: center;
                    padding: 4px 9px;
                    border-radius: 999px;
                    font-size: 11px;
                    font-weight: 800;
                    text-transform: uppercase;
                    letter-spacing: .04em;
                    margin-right: 8px;
                }
                .paytr-status-badge.success {
                    background: #edfaef;
                    color: #008a20;
                }
                .paytr-status-badge.error {
                    background: #fcf0f1;
                    color: #b42318;
                }
                .paytr-status-badge.warning {
                    background: #fff8e5;
                    color: #8a5a00;
                }
                .log-timestamp, .log-order-info span {
                    background: #f6f7f9;
                    border: 1px solid #e5e7eb;
                    border-radius: 999px;
                    color: #3c434a;
                    padding: 4px 8px;
                }
                .log-message {
                    margin-top: 10px;
                    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
                    font-size: 13px;
                    color: #111827;
                }
                .log-details {
                    margin-top: 12px;
                    background: #f8fafc;
                    border: 1px solid #e5e7eb;
                    border-left: 4px solid #72aee6;
                    color: #334155;
                    max-height: 260px;
                    overflow: auto;
                }
                .solution-btn {
                    margin-top: 12px;
                    background: #2271b1;
                    color: #fff;
                    border: 1px solid #2271b1;
                    padding: 8px 12px;
                    font-size: 12px;
                }
                .solution-btn:hover {
                    background: #135e96;
                    color: #fff;
                    box-shadow: none;
                    transform: none;
                }
                .modal-content {
                    border-radius: 8px;
                    overflow: hidden;
                }
                .modal-header {
                    background: #111827;
                }
                @media (max-width: 900px) {
                    .paytr-summary-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                    }
                    .paytr-page-header {
                        flex-direction: column;
                    }
                }
                @media (max-width: 640px) {
                    .log-container {
                        padding: 16px;
                    }
                    .paytr-summary-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
        </head>
        <body>
            <div class="log-container">
                <h1>PayTR Hata Günlükleri</h1>
                
                <!-- Temizleme İşlemleri -->
                <div class="paytr-page-header">
                    <div>
                        <h1>PayTR Hata Günlükleri</h1>
                        <p class="paytr-page-subtitle">Ödeme, callback ve iade akışlarında oluşan kayıtarı tek ekrandan inceleyin.</p>
                    </div>
                    <span class="paytr-retention-pill"><?php echo esc_html($retention_days); ?> gun saklama</span>
                </div>

                <div class="paytr-summary-grid">
                    <div class="paytr-summary-card">
                        <span class="paytr-summary-label">Toplam Kayit</span>
                        <span class="paytr-summary-value"><?php echo number_format($total_entries); ?></span>
                    </div>
                    <div class="paytr-summary-card">
                        <span class="paytr-summary-label">Hata</span>
                        <span class="paytr-summary-value"><?php echo number_format($summary_stats['error']); ?></span>
                    </div>
                    <div class="paytr-summary-card">
                        <span class="paytr-summary-label">Başarılı</span>
                        <span class="paytr-summary-value"><?php echo number_format($summary_stats['success']); ?></span>
                    </div>
                    <div class="paytr-summary-card">
                        <span class="paytr-summary-label">Dosya Boyutu</span>
                        <span class="paytr-summary-value"><?php echo $selected_log_data ? esc_html($selected_log_data['size']) : '0 B'; ?></span>
                    </div>
                </div>

                <div class="cleanup-actions">
                    <h3>Log Yönetimi</h3>
                    <div class="cleanup-buttons">
                        <a href="<?php echo $this->build_pagination_url(1, array('cleanup_old' => 1)); ?>" 
                           class="cleanup-btn" 
                           onclick="return confirm('<?php echo esc_js($retention_days); ?> gunden eski tum log dosyalari silinecek. Emin misiniz?')">
                            Eski Logları Temizle
                        </a>
                        <a href="<?php echo $this->build_pagination_url(1, array('cleanup_all' => 1)); ?>" 
                           class="cleanup-btn danger" 
                           onclick="return confirm('TÜM log dosyaları silinecek. Bu işlem geri alınamaz! Emin misiniz?')">
                            Tüm Logları Temizle
                        </a>
                    </div>
                    <div class="cleanup-info">
                        <strong>Otomatik Temizleme:</strong> Log dosyalari secili ayara gore <?php echo esc_html($retention_days); ?> gun saklanir. Performans icin bu ekranda son 200 kayit gosterilir.
                    </div>
                </div>

                <?php if ($cleanup_message): ?>
                    <?php echo $cleanup_message; ?>
                <?php endif; ?>
                
                <!-- Filtreleme Formu -->
                <div class="filters-container">
                    <form method="get" action="<?php echo admin_url('admin-ajax.php'); ?>" id="logFilterForm">
                        <input type="hidden" name="action" value="paytr_view_logs">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr($_GET['nonce']); ?>">
                        
                        <div class="filter-group">
                            <div class="filter-item">
                                <label for="date">Tarih Seçin:</label>
                                <input type="date" 
                                       id="date" 
                                       name="date" 
                                       class="filter-input" 
                                       value="<?php echo esc_attr($selected_date); ?>"
                                       max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="filter-item">
                                <label for="per_page">Sayfa Başına:</label>
                                <select id="per_page" name="per_page" class="filter-select">
                                    <?php foreach ($per_page_options as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php selected($per_page, $option); ?>>
                                            <?php echo $option; ?> kayıt
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="filter-button">Filtrele</button>
                        </div>
                    </form>
                </div>

                <!-- Seçili Tarih İşlemleri -->
                <?php if ($selected_log_data): ?>
                    <div class="selected-date-actions">
                        <div class="selected-date-info">
                            <strong>Şu an görüntülenen:</strong> <?php echo $this->format_date_display($selected_date); ?> 
                            - <em><?php echo $selected_log_data['size']; ?> boyutunda</em>
                        </div>
                        <a href="<?php echo $this->build_pagination_url(1, array('cleanup_selected' => 1, 'date' => $selected_date)); ?>" 
                           class="delete-selected-btn" 
                           onclick="return confirm('<?php echo $selected_date; ?> tarihli log dosyası silinecek. Bu işlem geri alınamaz! Emin misiniz?')">
                            Bu Tarihin Logunu Sil
                        </a>
                    </div>
                <?php endif; ?>

                <!-- İstatistikler -->
                <?php if ($selected_log_data): ?>
                    <div class="stats-container">
                        <div class="stats-info">
                            <div class="stat-item">
                                <strong>Seçilen Tarih:</strong> 
                                <span class="stat-badge"><?php echo $this->format_date_display($selected_date); ?></span>
                            </div>
                            <div class="stat-item">
                                <strong>Toplam Kayıt:</strong> 
                                <span class="stat-badge"><?php echo number_format($total_entries); ?></span>
                            </div>
                            <div class="stat-item">
                                <strong>Dosya Boyutu:</strong> 
                                <span class="stat-badge"><?php echo $selected_log_data['size']; ?></span>
                            </div>
                            <div class="stat-item">
                                <strong>Gösterilen:</strong> 
                                <span class="stat-badge">
                                    <?php 
                                    $start = ($current_page - 1) * $per_page + 1;
                                    $end = min($current_page * $per_page, $total_entries);
                                    echo $start . ' - ' . $end;
                                    ?>
                                </span>
                            </div>
                            <div class="stat-item">
                                <strong>Log Saklama:</strong> 
                                <span class="stat-badge"><?php echo $this->log_manager->get_max_log_days(); ?> gün</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Sayfalama -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Sayfa <?php echo $current_page; ?> / <?php echo $total_pages; ?> 
                            (Toplam <?php echo number_format($total_entries); ?> kayıt)
                        </div>
                        <div class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a href="<?php echo $this->build_pagination_url(1); ?>">&laquo; İlk</a>
                                <a href="<?php echo $this->build_pagination_url($current_page - 1); ?>">&lsaquo; Önceki</a>
                            <?php else: ?>
                                <span class="disabled">&laquo; İlk</span>
                                <span class="disabled">&lsaquo; Önceki</span>
                            <?php endif; ?>

                            <div class="page-numbers">
                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                    if ($i == $current_page): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo $this->build_pagination_url($i); ?>"><?php echo $i; ?></a>
                                    <?php endif;
                                endfor;
                                ?>
                            </div>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="<?php echo $this->build_pagination_url($current_page + 1); ?>">Sonraki &rsaquo;</a>
                                <a href="<?php echo $this->build_pagination_url($total_pages); ?>">Son &raquo;</a>
                            <?php else: ?>
                                <span class="disabled">Sonraki &rsaquo;</span>
                                <span class="disabled">Son &raquo;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Log İçeriği -->
                <?php if (!$selected_log_data): ?>
                    <div class="no-logs">
                        <h3><?php echo $this->format_date_display($selected_date); ?> tarihine ait log bulunamadı.</h3>
                        <p>Log dosyaları sadece son <?php echo $this->log_manager->get_max_log_days(); ?> gün saklanmaktadır.</p>
                    </div>
                <?php elseif (empty($paginated_entries)): ?>
                    <div class="no-logs">
                        <h3>Seçilen tarihte log kaydı bulunmamaktadır.</h3>
                        <p>Bu tarihte herhangi bir hata oluşmamış olabilir.</p>
                    </div>
                <?php else: ?>
                    <div class="log-file">
                        <div class="log-date">
                            <span><?php echo $this->format_date_display($selected_date); ?></span>
                            <span class="file-size"><?php echo $selected_log_data['size']; ?></span>
                        </div>
                        <div class="log-content">
                            <?php foreach ($paginated_entries as $entry): ?>
                                <?php
                                    try {
                                        $entry_severity = $this->get_entry_severity($entry);
                                ?>
                                <div class="log-entry is-<?php echo esc_attr($entry_severity); ?>">
                                    <div class="log-entry-header">
                                        <span>
                                            <span class="paytr-status-badge <?php echo esc_attr($entry_severity); ?>"><?php echo esc_html($this->get_entry_severity_label($entry_severity)); ?></span>
                                            <span class="log-timestamp"><?php echo esc_html(isset($entry['timestamp']) ? $entry['timestamp'] : ''); ?></span>
                                        </span>
                                        <?php if (!empty($entry['order_id'])): ?>
                                            <span class="log-order-info">
                                                <?php if (!empty($entry['transaction_id'])): ?>
                                                    <span>İşlem ID: <?php echo $entry['transaction_id']; ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($entry['order_id'])): ?>
                                                    <span>Sipariş ID: <?php echo $entry['order_id']; ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="log-message">
                                        <?php echo esc_html(isset($entry['message']) ? $entry['message'] : ''); ?>
                                    </div>
                                    <?php if (!empty($entry['details'])): ?>
                                        <div class="log-details">
                                            <?php echo esc_html($entry['details']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$this->is_success_entry($entry)): ?>
                                    <?php foreach ($solutions as $error_key => $solution_data): ?>
                                        <?php if (strpos((isset($entry['message']) ? $entry['message'] : '') . (isset($entry['details']) ? $entry['details'] : ''), $error_key) !== false): ?>
                                            <button class="solution-btn" 
                                                    onclick="showSolution('<?php echo esc_js($solution_data['title']); ?>', '<?php echo esc_js($solution_data['solution']); ?>')">
                                                Çözüm Önerisi
                                            </button>
                                            <?php break; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php
                                    } catch (Throwable $e) {
                                        error_log('PayTR Log Goruntuleme Hata: ' . $e->getMessage());
                                ?>
                                    <div class="log-entry is-error">
                                        <div class="log-entry-header">
                                            <span>
                                                <span class="paytr-status-badge error">Hata</span>
                                                <span class="log-timestamp"><?php echo esc_html(isset($entry['timestamp']) ? $entry['timestamp'] : date('Y-m-d H:i:s')); ?></span>
                                            </span>
                                        </div>
                                        <div class="log-message">
                                            Bu log kaydi beklenmeyen format nedeniyle tam olarak gosterilemedi. Ham kayit dosyasini kontrol edin.
                                        </div>
                                    </div>
                                <?php } ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Alt Sayfalama -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Sayfa <?php echo $current_page; ?> / <?php echo $total_pages; ?> 
                            (Toplam <?php echo number_format($total_entries); ?> kayıt)
                        </div>
                        <div class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a href="<?php echo $this->build_pagination_url(1); ?>">&laquo; İlk</a>
                                <a href="<?php echo $this->build_pagination_url($current_page - 1); ?>">&lsaquo; Önceki</a>
                            <?php else: ?>
                                <span class="disabled">&laquo; İlk</span>
                                <span class="disabled">&lsaquo; Önceki</span>
                            <?php endif; ?>

                            <div class="page-numbers">
                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                    if ($i == $current_page): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo $this->build_pagination_url($i); ?>"><?php echo $i; ?></a>
                                    <?php endif;
                                endfor;
                                ?>
                            </div>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="<?php echo $this->build_pagination_url($current_page + 1); ?>">Sonraki &rsaquo;</a>
                                <a href="<?php echo $this->build_pagination_url($total_pages); ?>">Son &raquo;</a>
                            <?php else: ?>
                                <span class="disabled">Sonraki &rsaquo;</span>
                                <span class="disabled">Son &raquo;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Solution Modal/Popup -->
            <div id="solutionModal" class="solution-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Çözüm Önerisi</h3>
                        <button class="close-modal" onclick="closeSolution()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="solution-title" id="solutionTitle"></div>
                        <div class="solution-text" id="solutionText"></div>
                    </div>
                </div>
            </div>

            <script>
                function showSolution(title, text) {
                    document.getElementById('solutionTitle').textContent = title;
                    document.getElementById('solutionText').textContent = text;
                    document.getElementById('solutionModal').style.display = 'block';
                    
                    // ESC tuşu ile kapatma
                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            closeSolution();
                        }
                    });
                }

                function closeSolution() {
                    document.getElementById('solutionModal').style.display = 'none';
                }

                // Modal dışına tıklayarak kapatma
                document.addEventListener('click', function(event) {
                    const modal = document.getElementById('solutionModal');
                    if (event.target === modal) {
                        closeSolution();
                    }
                });

                // Sayfa yüklendiğinde ESC tuşu dinleyicisini ekle
                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelectorAll('.cleanup-info').forEach(function(item) {
                        item.innerHTML = '<strong>Otomatik Temizleme:</strong> Log dosyalari secili ayara gore <?php echo esc_js($retention_days); ?> gun saklanir. Performans icin bu ekranda son 200 kayit gosterilir.';
                    });

                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            closeSolution();
                        }
                    });

                    // Form değişikliklerinde otomatik submit
                    document.getElementById('per_page').addEventListener('change', function() {
                        document.getElementById('logFilterForm').submit();
                    });
                });
            </script>
        </body>
        </html>
        <?php
        wp_die();
    }

    /**
     * Sayfalama URL'si oluşturur
     */
    private function build_pagination_url($page, $additional_params = array()) {
        $params = array(
            'action' => 'paytr_view_logs',
            'nonce' => $_GET['nonce'],
            'date' => isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'),
            'per_page' => isset($_GET['per_page']) ? intval($_GET['per_page']) : 25,
            'paged' => $page
        );
        
        $params = array_merge($params, $additional_params);
        
        return admin_url('admin-ajax.php') . '?' . http_build_query($params);
    }

    private function get_log_summary_stats($entries) {
        $stats = array(
            'success' => 0,
            'error' => 0,
            'warning' => 0,
        );

        foreach ($entries as $entry) {
            $severity = $this->get_entry_severity($entry);
            if (isset($stats[$severity])) {
                $stats[$severity]++;
            }
        }

        return $stats;
    }

    private function get_entry_severity($entry) {
        $text = strtolower(remove_accents($entry['message'] . ' ' . $entry['details']));

        if (strpos($text, 'hata') !== false || strpos($text, 'error') !== false || strpos($text, 'failed') !== false || strpos($text, '005') !== false || strpos($text, 'gonderilemedi') !== false || strpos($text, 'okunamadi') !== false) {
            return 'error';
        }

        if (strpos($text, 'basarili') !== false || strpos($text, '"status":"success"') !== false || strpos($text, ' status: success') !== false) {
            return 'success';
        }

        return 'warning';
    }

    private function get_entry_severity_label($severity) {
        $labels = array(
            'success' => 'Basarili',
            'error' => 'Hata',
            'warning' => 'Uyari',
        );

        return isset($labels[$severity]) ? $labels[$severity] : 'Kayit';
    }

    private function is_success_entry($entry) {
        return $this->get_entry_severity($entry) === 'success';
    }

    private function read_recent_log_content($files, $entry_limit = 200) {
        $files = is_array($files) ? $files : array($files);
        $content = '';

        foreach ($files as $file) {
            if (!file_exists($file) || !is_readable($file)) {
                continue;
            }

            $file_content = file_get_contents($file);
            if ($file_content !== false) {
                $content .= $file_content;
            }
        }

        if ($content === false || trim($content) === '') {
            return '';
        }

        $entries = preg_split('/-{50}\s*/', $content, -1, PREG_SPLIT_NO_EMPTY);
        if (count($entries) <= $entry_limit) {
            return $content;
        }

        $entries = array_slice($entries, -1 * $entry_limit);

        return implode("--------------------------------------------------\n", $entries) . "--------------------------------------------------\n";
    }

    /**
     * Log içeriğini ayrıştırır ve her bir girişi düzenler
     */
    private function parse_log_entries($content) {
        $entries = [];
        $lines = explode("\n", $content);
        $current_entry = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            // Yeni log girişi başlangıcı ([timestamp] formatı)
            if (preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
                // Önceki girişi kaydet
                if (!empty($current_entry)) {
                    $entries[] = $current_entry;
                }
                
                // Yeni girişi başlat
                $current_entry = [
                    'timestamp' => $matches[1],
                    'message' => '',
                    'details' => '',
                    'order_id' => null,
                    'transaction_id' => null
                ];
                
                // Order ID ve Transaction ID'yi çıkar
                if (preg_match('/\[(?:Sipariş|Siparis) ID: ([^\]]+)\]/', $line, $order_matches)) {
                    $current_entry['order_id'] = $order_matches[1];
                }
                if (preg_match('/\[(?:İşlem|Islem) ID: ([^\]]+)\]/', $line, $transaction_matches)) {
                    $current_entry['transaction_id'] = $transaction_matches[1];
                }
                
                // Ana mesajı çıkar
                $message = preg_replace('/^\[[^\]]+\]\s*/', '', $line);
                $message = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $message);
                $current_entry['message'] = $message;
                
            } elseif (strpos($line, 'DETAYLAR:') === 0) {
                // Detaylar bölümü
                $current_entry['details'] = substr($line, 9); // "DETAYLAR: " kısmını atla
            } elseif (strpos($line, '--------------------------------------------------') === false) {
                // Normal mesaj satırı
                if (!empty($current_entry['details'])) {
                    $current_entry['details'] .= "\n" . $line;
                } elseif (!empty($current_entry['message'])) {
                    $current_entry['message'] .= "\n" . $line;
                }
            }
        }
        
        // Son girişi kaydet
        if (!empty($current_entry)) {
            $entries[] = $current_entry;
        }
        
        return $entries;
    }

    /**
     * Tarih görüntüsünü formatlar
     */
    private function format_date_display($date) {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        if ($date === $today) {
            return 'Bugün (' . $date . ')';
        } elseif ($date === $yesterday) {
            return 'Dün (' . $date . ')';
        } else {
            $day_name = date('l', strtotime($date));
            $turkish_days = [
                'Monday' => 'Pazartesi',
                'Tuesday' => 'Salı',
                'Wednesday' => 'Çarşamba',
                'Thursday' => 'Perşembe',
                'Friday' => 'Cuma',
                'Saturday' => 'Cumartesi',
                'Sunday' => 'Pazar'
            ];
            $turkish_day = $turkish_days[$day_name] ?? $day_name;
            return $turkish_day . ' (' . $date . ')';
        }
    }

    /**
     * Dosya boyutunu formatlar
     */
    private function format_filesize($bytes) {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
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



