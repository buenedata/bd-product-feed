<?php
/**
 * BD Product Feed Core Class
 * Handles core functionality and coordination between components
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Product_Feed_Core {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_options();
        $this->init_logger();
        
        // Hook into WordPress
        add_action('wp_ajax_bd_generate_feed', array($this, 'ajax_generate_feed'));
        add_action('wp_ajax_bd_test_feed', array($this, 'ajax_test_feed'));
        add_action('wp_ajax_bd_validate_feed', array($this, 'ajax_validate_feed'));
    }
    
    /**
     * Load plugin options
     */
    private function load_options() {
        $this->options = array(
            'update_frequency' => get_option('bd_product_feed_update_frequency', 'daily'),
            'include_categories' => get_option('bd_product_feed_include_categories', array()),
            'exclude_categories' => get_option('bd_product_feed_exclude_categories', array()),
            'product_status' => get_option('bd_product_feed_product_status', array('publish')),
            'stock_status' => get_option('bd_product_feed_stock_status', array('instock')),
            'currency_conversion' => get_option('bd_product_feed_currency_conversion', false),
            'target_currencies' => get_option('bd_product_feed_target_currencies', array('EUR', 'USD')),
            'feed_title' => get_option('bd_product_feed_feed_title', get_bloginfo('name') . ' Product Feed'),
            'feed_description' => get_option('bd_product_feed_feed_description', __('Produktfeed for Google Merchant Center', 'bd-product-feed')),
            'email_notifications' => get_option('bd_product_feed_email_notifications', true),
            'notification_email' => get_option('bd_product_feed_notification_email', get_option('admin_email')),
            'feed_key' => get_option('bd_product_feed_key', ''),
        );
    }
    
    /**
     * Initialize logger
     */
    private function init_logger() {
        $this->logger = new WP_Error();
    }
    
    /**
     * Get plugin options
     */
    public function get_options() {
        return $this->options;
    }
    
    /**
     * Get specific option
     */
    public function get_option($key, $default = null) {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }
    
    /**
     * Update option
     */
    public function update_option($key, $value) {
        $this->options[$key] = $value;
        update_option('bd_product_feed_' . $key, $value);
    }
    
    /**
     * Log message
     */
    public function log($message, $type = 'info') {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$type}] {$message}";
        
        // Write to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BD Product Feed: " . $log_entry);
        }
        
        // Store in database for admin viewing
        $logs = get_option('bd_product_feed_logs', array());
        $logs[] = array(
            'timestamp' => $timestamp,
            'type' => $type,
            'message' => $message
        );
        
        // Keep only last 100 log entries
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        update_option('bd_product_feed_logs', $logs);
    }
    
    /**
     * Get logs
     */
    public function get_logs($limit = 50) {
        $logs = get_option('bd_product_feed_logs', array());
        return array_slice($logs, -$limit);
    }
    
    /**
     * Clear logs
     */
    public function clear_logs() {
        delete_option('bd_product_feed_logs');
    }
    
    /**
     * Generate feed manually
     */
    public function generate_feed() {
        try {
            $this->log('Starting manual feed generation', 'info');
            
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                throw new Exception(__('WooCommerce er ikke aktiv', 'bd-product-feed'));
            }
            
            // Get feed generator instance
            $feed_generator = new BD_Feed_Generator();
            
            // Generate the feed
            $result = $feed_generator->generate();
            
            if ($result['success']) {
                $this->log('Feed generated successfully: ' . $result['product_count'] . ' products', 'success');
                
                // Send notification email if enabled
                if ($this->get_option('email_notifications')) {
                    $this->send_notification_email('success', $result);
                }
                
                return array(
                    'success' => true,
                    'message' => sprintf(
                        __('Feed generert med %d produkter', 'bd-product-feed'),
                        $result['product_count']
                    ),
                    'data' => $result
                );
            } else {
                throw new Exception($result['message']);
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->log('Feed generation failed: ' . $error_message, 'error');
            
            // Send error notification email if enabled
            if ($this->get_option('email_notifications')) {
                $this->send_notification_email('error', array('message' => $error_message));
            }
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * Test feed generation
     */
    public function test_feed() {
        try {
            $this->log('Starting feed test', 'info');
            
            // Get feed generator instance
            $feed_generator = new BD_Feed_Generator();
            
            // Generate test feed with limited products
            $result = $feed_generator->generate_test(10);
            
            if ($result['success']) {
                $this->log('Feed test completed successfully', 'success');
                return array(
                    'success' => true,
                    'message' => __('Feed test fullført', 'bd-product-feed'),
                    'data' => $result
                );
            } else {
                throw new Exception($result['message']);
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->log('Feed test failed: ' . $error_message, 'error');
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * Validate existing feed
     */
    public function validate_feed() {
        try {
            $this->log('Starting feed validation', 'info');
            
            // Get feed validator instance
            $feed_validator = new BD_Feed_Validator();
            
            // Validate the feed
            $result = $feed_validator->validate();
            
            if ($result['valid']) {
                $this->log('Feed validation passed', 'success');
                return array(
                    'success' => true,
                    'message' => __('Feed validering bestått', 'bd-product-feed'),
                    'data' => $result
                );
            } else {
                $this->log('Feed validation failed: ' . implode(', ', $result['errors']), 'warning');
                return array(
                    'success' => false,
                    'message' => __('Feed validering feilet', 'bd-product-feed'),
                    'data' => $result
                );
            }
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->log('Feed validation error: ' . $error_message, 'error');
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * Send notification email
     */
    private function send_notification_email($type, $data) {
        $email = $this->get_option('notification_email');
        if (empty($email)) {
            return;
        }
        
        $site_name = get_bloginfo('name');
        
        if ($type === 'success') {
            $subject = sprintf(__('[%s] Product Feed generert', 'bd-product-feed'), $site_name);
            $message = sprintf(
                __("Hei,\n\nProduct Feed har blitt generert med %d produkter.\n\nFeed URL: %s\n\nMed vennlig hilsen,\nBD Product Feed", 'bd-product-feed'),
                $data['product_count'],
                bd_get_product_feed_url()
            );
        } else {
            $subject = sprintf(__('[%s] Product Feed feil', 'bd-product-feed'), $site_name);
            $message = sprintf(
                __("Hei,\n\nDet oppstod en feil under generering av Product Feed:\n\n%s\n\nVennligst sjekk innstillingene og prøv igjen.\n\nMed vennlig hilsen,\nBD Product Feed", 'bd-product-feed'),
                $data['message']
            );
        }
        
        wp_mail($email, $subject, $message);
    }
    
    /**
     * Get feed statistics
     */
    public function get_feed_stats() {
        $feed_file = BD_PRODUCT_FEED_PATH . 'feeds/product-feed.xml';
        $stats = array(
            'exists' => file_exists($feed_file),
            'last_modified' => false,
            'file_size' => 0,
            'product_count' => 0,
            'feed_url' => bd_get_product_feed_url()
        );
        
        if ($stats['exists']) {
            $stats['last_modified'] = filemtime($feed_file);
            $stats['file_size'] = filesize($feed_file);
            
            // Count products in feed
            if (function_exists('simplexml_load_file')) {
                $xml = simplexml_load_file($feed_file);
                if ($xml && isset($xml->channel->item)) {
                    $stats['product_count'] = count($xml->channel->item);
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * AJAX handler for generating feed
     */
    public function ajax_generate_feed() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bd_product_feed_nonce')) {
            wp_die(__('Sikkerhetsfeil', 'bd-product-feed'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Ingen tilgang', 'bd-product-feed'));
        }
        
        $result = $this->generate_feed();
        wp_send_json($result);
    }
    
    /**
     * AJAX handler for testing feed
     */
    public function ajax_test_feed() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bd_product_feed_nonce')) {
            wp_die(__('Sikkerhetsfeil', 'bd-product-feed'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Ingen tilgang', 'bd-product-feed'));
        }
        
        $result = $this->test_feed();
        wp_send_json($result);
    }
    
    /**
     * AJAX handler for validating feed
     */
    public function ajax_validate_feed() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bd_product_feed_nonce')) {
            wp_die(__('Sikkerhetsfeil', 'bd-product-feed'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Ingen tilgang', 'bd-product-feed'));
        }
        
        $result = $this->validate_feed();
        wp_send_json($result);
    }
}