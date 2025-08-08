<?php
/**
 * BD Settings Manager Class
 * Handles export/import functionality for plugin settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Settings_Manager {
    
    /**
     * Core instance
     */
    private $core;
    
    /**
     * Settings keys to export/import
     */
    private $settings_keys = array(
        'bd_product_feed_update_frequency',
        'bd_product_feed_include_categories',
        'bd_product_feed_exclude_categories',
        'bd_product_feed_product_status',
        'bd_product_feed_stock_status',
        'bd_product_feed_currency_conversion',
        'bd_product_feed_target_currencies',
        'bd_product_feed_feed_title',
        'bd_product_feed_feed_description',
        'bd_product_feed_email_notifications',
        'bd_product_feed_notification_email',
        'bd_product_feed_multilingual_enabled',
        'bd_product_feed_target_languages',
    );
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->core = new BD_Product_Feed_Core();
        
        // Add AJAX handlers
        add_action('wp_ajax_bd_export_settings', array($this, 'ajax_export_settings'));
        add_action('wp_ajax_bd_import_settings', array($this, 'ajax_import_settings'));
    }
    
    /**
     * Export all plugin settings
     */
    public function export_settings() {
        $settings = array();
        
        // Get all plugin settings
        foreach ($this->settings_keys as $key) {
            $value = get_option($key, null);
            if ($value !== null) {
                // Remove the prefix for cleaner export
                $clean_key = str_replace('bd_product_feed_', '', $key);
                $settings[$clean_key] = $value;
            }
        }
        
        // Add metadata
        $export_data = array(
            'plugin' => 'BD Product Feed',
            'version' => BD_PRODUCT_FEED_VERSION,
            'export_date' => current_time('mysql'),
            'export_timestamp' => time(),
            'site_url' => home_url(),
            'settings' => $settings
        );
        
        return $export_data;
    }
    
    /**
     * Import plugin settings
     */
    public function import_settings($import_data, $options = array()) {
        $results = array(
            'success' => false,
            'message' => '',
            'imported_count' => 0,
            'skipped_count' => 0,
            'errors' => array()
        );
        
        // Validate import data
        $validation = $this->validate_import_data($import_data);
        if (!$validation['valid']) {
            $results['message'] = $validation['message'];
            return $results;
        }
        
        $settings = $import_data['settings'];
        $overwrite = isset($options['overwrite']) ? $options['overwrite'] : false;
        $backup_created = false;
        
        // Create backup if requested
        if (isset($options['create_backup']) && $options['create_backup']) {
            $backup_result = $this->create_settings_backup();
            if ($backup_result['success']) {
                $backup_created = true;
            }
        }
        
        // Import settings
        foreach ($settings as $key => $value) {
            $option_key = 'bd_product_feed_' . $key;
            
            // Check if setting exists and overwrite is disabled
            if (!$overwrite && get_option($option_key) !== false) {
                $results['skipped_count']++;
                continue;
            }
            
            // Validate setting value
            $validated_value = $this->validate_setting_value($key, $value);
            if ($validated_value === false) {
                $results['errors'][] = sprintf(__('Ugyldig verdi for innstilling: %s', 'bd-product-feed'), $key);
                continue;
            }
            
            // Update setting
            update_option($option_key, $validated_value);
            $results['imported_count']++;
        }
        
        // Set success status
        if ($results['imported_count'] > 0) {
            $results['success'] = true;
            $results['message'] = sprintf(
                __('%d innstillinger importert, %d hoppet over', 'bd-product-feed'),
                $results['imported_count'],
                $results['skipped_count']
            );
            
            if ($backup_created) {
                $results['message'] .= '. ' . __('Sikkerhetskopi opprettet', 'bd-product-feed');
            }
            
            // Log import
            BD_Product_Feed_Core::log('info', sprintf(
                'Settings imported: %d imported, %d skipped',
                $results['imported_count'],
                $results['skipped_count']
            ));
        } else {
            $results['message'] = __('Ingen innstillinger ble importert', 'bd-product-feed');
        }
        
        return $results;
    }
    
    /**
     * Validate import data
     */
    private function validate_import_data($data) {
        $result = array('valid' => false, 'message' => '');
        
        // Check if data is array
        if (!is_array($data)) {
            $result['message'] = __('Ugyldig importdata format', 'bd-product-feed');
            return $result;
        }
        
        // Check required fields
        if (!isset($data['plugin']) || $data['plugin'] !== 'BD Product Feed') {
            $result['message'] = __('Importfilen er ikke fra BD Product Feed', 'bd-product-feed');
            return $result;
        }
        
        if (!isset($data['settings']) || !is_array($data['settings'])) {
            $result['message'] = __('Ingen innstillinger funnet i importfilen', 'bd-product-feed');
            return $result;
        }
        
        // Check version compatibility
        if (isset($data['version'])) {
            $import_version = $data['version'];
            $current_version = BD_PRODUCT_FEED_VERSION;
            
            if (version_compare($import_version, $current_version, '>')) {
                $result['message'] = sprintf(
                    __('Importfilen er fra en nyere versjon (%s) enn installert versjon (%s)', 'bd-product-feed'),
                    $import_version,
                    $current_version
                );
                return $result;
            }
        }
        
        $result['valid'] = true;
        return $result;
    }
    
    /**
     * Validate setting value
     */
    private function validate_setting_value($key, $value) {
        switch ($key) {
            case 'update_frequency':
                $valid_frequencies = array('hourly', 'twicedaily', 'daily', 'weekly');
                return in_array($value, $valid_frequencies) ? $value : false;
                
            case 'include_categories':
            case 'exclude_categories':
            case 'target_currencies':
            case 'target_languages':
                return is_array($value) ? array_map('intval', $value) : array();
                
            case 'product_status':
            case 'stock_status':
                $valid_statuses = array('publish', 'private', 'draft', 'instock', 'outofstock', 'onbackorder');
                if (is_array($value)) {
                    return array_intersect($value, $valid_statuses);
                }
                return array();
                
            case 'currency_conversion':
            case 'email_notifications':
            case 'multilingual_enabled':
                return (bool) $value;
                
            case 'feed_title':
            case 'feed_description':
            case 'notification_email':
                return sanitize_text_field($value);
                
            default:
                return $value;
        }
    }
    
    /**
     * Create settings backup
     */
    public function create_settings_backup() {
        $backup_data = $this->export_settings();
        $backup_data['backup_type'] = 'automatic';
        
        // Create backups directory
        $backups_dir = BD_PRODUCT_FEED_PATH . 'backups';
        if (!file_exists($backups_dir)) {
            wp_mkdir_p($backups_dir);
        }
        
        // Generate backup filename
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "bd-product-feed-backup-{$timestamp}.json";
        $filepath = $backups_dir . '/' . $filename;
        
        // Save backup
        $json_data = wp_json_encode($backup_data, JSON_PRETTY_PRINT);
        $result = file_put_contents($filepath, $json_data);
        
        if ($result !== false) {
            // Clean old backups (keep last 10)
            $this->cleanup_old_backups($backups_dir, 10);
            
            return array(
                'success' => true,
                'message' => __('Sikkerhetskopi opprettet', 'bd-product-feed'),
                'filename' => $filename,
                'filepath' => $filepath
            );
        } else {
            return array(
                'success' => false,
                'message' => __('Kunne ikke opprette sikkerhetskopi', 'bd-product-feed')
            );
        }
    }
    
    /**
     * Get available backups
     */
    public function get_available_backups() {
        $backups_dir = BD_PRODUCT_FEED_PATH . 'backups';
        $backups = array();
        
        if (!file_exists($backups_dir)) {
            return $backups;
        }
        
        $files = glob($backups_dir . '/bd-product-feed-backup-*.json');
        
        foreach ($files as $file) {
            $filename = basename($file);
            $filesize = filesize($file);
            $modified = filemtime($file);
            
            // Try to read backup metadata
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            $backups[] = array(
                'filename' => $filename,
                'filepath' => $file,
                'size' => $filesize,
                'size_formatted' => size_format($filesize),
                'modified' => $modified,
                'modified_formatted' => date('Y-m-d H:i:s', $modified),
                'version' => isset($data['version']) ? $data['version'] : 'Unknown',
                'settings_count' => isset($data['settings']) ? count($data['settings']) : 0
            );
        }
        
        // Sort by modification time (newest first)
        usort($backups, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $backups;
    }
    
    /**
     * Restore from backup
     */
    public function restore_from_backup($backup_filename, $options = array()) {
        $backups_dir = BD_PRODUCT_FEED_PATH . 'backups';
        $filepath = $backups_dir . '/' . $backup_filename;
        
        if (!file_exists($filepath)) {
            return array(
                'success' => false,
                'message' => __('Sikkerhetskopi ikke funnet', 'bd-product-feed')
            );
        }
        
        // Read backup file
        $content = file_get_contents($filepath);
        $backup_data = json_decode($content, true);
        
        if (!$backup_data) {
            return array(
                'success' => false,
                'message' => __('Kunne ikke lese sikkerhetskopi', 'bd-product-feed')
            );
        }
        
        // Import settings from backup
        return $this->import_settings($backup_data, $options);
    }
    
    /**
     * Delete backup
     */
    public function delete_backup($backup_filename) {
        $backups_dir = BD_PRODUCT_FEED_PATH . 'backups';
        $filepath = $backups_dir . '/' . $backup_filename;
        
        if (!file_exists($filepath)) {
            return array(
                'success' => false,
                'message' => __('Sikkerhetskopi ikke funnet', 'bd-product-feed')
            );
        }
        
        if (unlink($filepath)) {
            return array(
                'success' => true,
                'message' => __('Sikkerhetskopi slettet', 'bd-product-feed')
            );
        } else {
            return array(
                'success' => false,
                'message' => __('Kunne ikke slette sikkerhetskopi', 'bd-product-feed')
            );
        }
    }
    
    /**
     * Cleanup old backups
     */
    private function cleanup_old_backups($backups_dir, $keep_count = 10) {
        $files = glob($backups_dir . '/bd-product-feed-backup-*.json');
        
        if (count($files) <= $keep_count) {
            return;
        }
        
        // Sort by modification time
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Delete old backups
        $files_to_delete = array_slice($files, $keep_count);
        foreach ($files_to_delete as $file) {
            unlink($file);
        }
    }
    
    /**
     * AJAX handler for export settings
     */
    public function ajax_export_settings() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Ingen tilgang', 'bd-product-feed'), 403);
        }
        
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bd_product_feed_nonce')) {
            wp_die(__('Sikkerhetsfeil', 'bd-product-feed'), 403);
        }
        
        // Export settings
        $export_data = $this->export_settings();
        
        // Set headers for download
        $filename = 'bd-product-feed-settings-' . date('Y-m-d') . '.json';
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        
        echo wp_json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * AJAX handler for import settings
     */
    public function ajax_import_settings() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Ingen tilgang', 'bd-product-feed'));
        }
        
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bd_product_feed_nonce')) {
            wp_send_json_error(__('Sikkerhetsfeil', 'bd-product-feed'));
        }
        
        // Check if file was uploaded
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(__('Ingen fil lastet opp', 'bd-product-feed'));
        }
        
        // Read uploaded file
        $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $import_data = json_decode($file_content, true);
        
        if (!$import_data) {
            wp_send_json_error(__('Ugyldig JSON-fil', 'bd-product-feed'));
        }
        
        // Import options
        $options = array(
            'overwrite' => isset($_POST['overwrite']) && $_POST['overwrite'] === '1',
            'create_backup' => isset($_POST['create_backup']) && $_POST['create_backup'] === '1'
        );
        
        // Import settings
        $result = $this->import_settings($import_data, $options);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Reset all settings to defaults
     */
    public function reset_to_defaults($create_backup = true) {
        // Create backup first
        if ($create_backup) {
            $backup_result = $this->create_settings_backup();
        }
        
        // Delete all plugin settings
        foreach ($this->settings_keys as $key) {
            delete_option($key);
        }
        
        // Set default values (same as in activation)
        $default_options = array(
            'bd_product_feed_update_frequency' => 'daily',
            'bd_product_feed_include_categories' => array(),
            'bd_product_feed_exclude_categories' => array(),
            'bd_product_feed_product_status' => array('publish'),
            'bd_product_feed_stock_status' => array('instock'),
            'bd_product_feed_currency_conversion' => false,
            'bd_product_feed_target_currencies' => array('EUR', 'USD'),
            'bd_product_feed_feed_title' => get_bloginfo('name') . ' Product Feed',
            'bd_product_feed_feed_description' => __('Produktfeed for Google Merchant Center', 'bd-product-feed'),
            'bd_product_feed_email_notifications' => true,
            'bd_product_feed_notification_email' => get_option('admin_email'),
            'bd_product_feed_multilingual_enabled' => false,
            'bd_product_feed_target_languages' => array(),
        );
        
        foreach ($default_options as $key => $value) {
            update_option($key, $value);
        }
        
        return array(
            'success' => true,
            'message' => __('Innstillinger tilbakestilt til standardverdier', 'bd-product-feed'),
            'backup_created' => $create_backup && isset($backup_result) && $backup_result['success']
        );
    }
}