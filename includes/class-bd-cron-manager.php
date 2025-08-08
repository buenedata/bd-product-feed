<?php
/**
 * BD Cron Manager Class
 * Handles scheduled feed updates and cron job management
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Cron_Manager {
    
    /**
     * Cron hook name
     */
    const CRON_HOOK = 'bd_product_feed_update';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into WordPress cron system
        add_action(self::CRON_HOOK, array($this, 'execute_scheduled_update'));
        
        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
        
        // Hook into plugin deactivation to clear cron jobs
        register_deactivation_hook(BD_PRODUCT_FEED_FILE, array($this, 'clear_scheduled_updates'));
    }
    
    /**
     * Add custom cron intervals
     */
    public function add_custom_cron_intervals($schedules) {
        // Every 15 minutes
        $schedules['bd_every_15_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Hver 15. minutt', 'bd-product-feed')
        );
        
        // Every 30 minutes
        $schedules['bd_every_30_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Hver 30. minutt', 'bd-product-feed')
        );
        
        // Every 2 hours
        $schedules['bd_every_2_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS,
            'display' => __('Hver 2. time', 'bd-product-feed')
        );
        
        // Every 6 hours
        $schedules['bd_every_6_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Hver 6. time', 'bd-product-feed')
        );
        
        // Every 12 hours
        $schedules['bd_every_12_hours'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Hver 12. time', 'bd-product-feed')
        );
        
        return $schedules;
    }
    
    /**
     * Schedule feed update
     */
    public function schedule_feed_update($frequency = null) {
        // Clear existing scheduled updates
        $this->clear_scheduled_updates();
        
        if ($frequency === null) {
            $frequency = get_option('bd_product_feed_update_frequency', 'daily');
        }
        
        // Don't schedule if frequency is manual
        if ($frequency === 'manual') {
            return true;
        }
        
        // Map frequency to WordPress cron intervals
        $interval = $this->map_frequency_to_interval($frequency);
        
        if (!$interval) {
            error_log('BD Product Feed: Invalid cron frequency: ' . $frequency);
            return false;
        }
        
        // Schedule the event
        $scheduled = wp_schedule_event(time(), $interval, self::CRON_HOOK);
        
        if ($scheduled === false) {
            error_log('BD Product Feed: Failed to schedule cron job');
            return false;
        }
        
        // Log successful scheduling
        $core = new BD_Product_Feed_Core();
        $core->log("Cron job scheduled with frequency: {$frequency}", 'info');
        
        return true;
    }
    
    /**
     * Clear scheduled updates
     */
    public function clear_scheduled_updates() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        
        // Clear all instances of the hook (in case there are multiple)
        wp_clear_scheduled_hook(self::CRON_HOOK);
        
        return true;
    }
    
    /**
     * Execute scheduled update
     */
    public function execute_scheduled_update() {
        try {
            // Log start of scheduled update
            $core = new BD_Product_Feed_Core();
            $core->log('Starting scheduled feed update', 'info');
            
            // Check if we should skip this update (e.g., if site is in maintenance mode)
            if ($this->should_skip_update()) {
                $core->log('Skipping scheduled update due to conditions', 'info');
                return;
            }
            
            // Generate the feed
            $result = $core->generate_feed();
            
            if ($result['success']) {
                $core->log('Scheduled feed update completed successfully: ' . $result['product_count'] . ' products', 'success');
                
                // Update last successful run time
                update_option('bd_product_feed_last_cron_success', current_time('timestamp'));
                
                // Reset failure count
                delete_option('bd_product_feed_cron_failure_count');
                
            } else {
                throw new Exception($result['message']);
            }
            
        } catch (Exception $e) {
            $this->handle_cron_failure($e->getMessage());
        }
    }
    
    /**
     * Handle cron job failure
     */
    private function handle_cron_failure($error_message) {
        $core = new BD_Product_Feed_Core();
        $core->log('Scheduled feed update failed: ' . $error_message, 'error');
        
        // Increment failure count
        $failure_count = get_option('bd_product_feed_cron_failure_count', 0);
        $failure_count++;
        update_option('bd_product_feed_cron_failure_count', $failure_count);
        
        // Update last failure time
        update_option('bd_product_feed_last_cron_failure', current_time('timestamp'));
        
        // If we have too many consecutive failures, send notification
        if ($failure_count >= 3) {
            $this->send_failure_notification($error_message, $failure_count);
        }
        
        // Implement exponential backoff for retries
        $this->schedule_retry($failure_count);
    }
    
    /**
     * Schedule retry with exponential backoff
     */
    private function schedule_retry($failure_count) {
        // Calculate delay: 2^failure_count minutes, max 60 minutes
        $delay_minutes = min(pow(2, $failure_count), 60);
        $retry_time = time() + ($delay_minutes * MINUTE_IN_SECONDS);
        
        // Schedule single retry
        wp_schedule_single_event($retry_time, self::CRON_HOOK);
        
        $core = new BD_Product_Feed_Core();
        $core->log("Retry scheduled in {$delay_minutes} minutes", 'info');
    }
    
    /**
     * Send failure notification email
     */
    private function send_failure_notification($error_message, $failure_count) {
        $email_notifications = get_option('bd_product_feed_email_notifications', true);
        if (!$email_notifications) {
            return;
        }
        
        $notification_email = get_option('bd_product_feed_notification_email', get_option('admin_email'));
        if (empty($notification_email)) {
            return;
        }
        
        $site_name = get_bloginfo('name');
        $subject = sprintf(__('[%s] Product Feed - Gjentatte feil', 'bd-product-feed'), $site_name);
        
        $message = sprintf(
            __("Hei,\n\nProduct Feed har feilet %d ganger på rad.\n\nSiste feilmelding:\n%s\n\nVennligst sjekk innstillingene og server-loggen for mer informasjon.\n\nAdmin URL: %s\n\nMed vennlig hilsen,\nBD Product Feed", 'bd-product-feed'),
            $failure_count,
            $error_message,
            admin_url('admin.php?page=bd-product-feed')
        );
        
        wp_mail($notification_email, $subject, $message);
    }
    
    /**
     * Check if update should be skipped
     */
    private function should_skip_update() {
        // Skip if WordPress is in maintenance mode
        if (file_exists(ABSPATH . '.maintenance')) {
            return true;
        }
        
        // Skip if WooCommerce is not active
        if (!class_exists('WooCommerce')) {
            return true;
        }
        
        // Skip if plugin is disabled
        if (get_option('bd_product_feed_disable_cron', false)) {
            return true;
        }
        
        // Allow custom conditions via filter
        return apply_filters('bd_product_feed_skip_cron_update', false);
    }
    
    /**
     * Map frequency setting to WordPress cron interval
     */
    private function map_frequency_to_interval($frequency) {
        $intervals = array(
            'every_15_minutes' => 'bd_every_15_minutes',
            'every_30_minutes' => 'bd_every_30_minutes',
            'hourly' => 'hourly',
            'every_2_hours' => 'bd_every_2_hours',
            'every_6_hours' => 'bd_every_6_hours',
            'every_12_hours' => 'bd_every_12_hours',
            'daily' => 'daily',
            'weekly' => 'weekly',
        );
        
        return isset($intervals[$frequency]) ? $intervals[$frequency] : false;
    }
    
    /**
     * Get available update frequencies
     */
    public function get_available_frequencies() {
        return array(
            'manual' => __('Manuell', 'bd-product-feed'),
            'every_15_minutes' => __('Hver 15. minutt', 'bd-product-feed'),
            'every_30_minutes' => __('Hver 30. minutt', 'bd-product-feed'),
            'hourly' => __('Hver time', 'bd-product-feed'),
            'every_2_hours' => __('Hver 2. time', 'bd-product-feed'),
            'every_6_hours' => __('Hver 6. time', 'bd-product-feed'),
            'every_12_hours' => __('Hver 12. time', 'bd-product-feed'),
            'daily' => __('Daglig', 'bd-product-feed'),
            'weekly' => __('Ukentlig', 'bd-product-feed'),
        );
    }
    
    /**
     * Get cron status information
     */
    public function get_cron_status() {
        $status = array(
            'is_scheduled' => false,
            'next_run' => false,
            'frequency' => get_option('bd_product_feed_update_frequency', 'daily'),
            'last_success' => get_option('bd_product_feed_last_cron_success', false),
            'last_failure' => get_option('bd_product_feed_last_cron_failure', false),
            'failure_count' => get_option('bd_product_feed_cron_failure_count', 0),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        );
        
        // Check if cron is scheduled
        $next_scheduled = wp_next_scheduled(self::CRON_HOOK);
        if ($next_scheduled) {
            $status['is_scheduled'] = true;
            $status['next_run'] = $next_scheduled;
        }
        
        return $status;
    }
    
    /**
     * Test cron functionality
     */
    public function test_cron() {
        // Schedule a test event for 1 minute from now
        $test_hook = 'bd_product_feed_cron_test';
        $test_time = time() + 60;
        
        // Clear any existing test events
        wp_clear_scheduled_hook($test_hook);
        
        // Schedule test event
        $scheduled = wp_schedule_single_event($test_time, $test_hook);
        
        if ($scheduled === false) {
            return array(
                'success' => false,
                'message' => __('Kunne ikke planlegge test-hendelse', 'bd-product-feed')
            );
        }
        
        // Check if it was actually scheduled
        $next_test = wp_next_scheduled($test_hook);
        
        if (!$next_test) {
            return array(
                'success' => false,
                'message' => __('Test-hendelse ble ikke planlagt', 'bd-product-feed')
            );
        }
        
        // Clean up test event
        wp_unschedule_event($next_test, $test_hook);
        
        return array(
            'success' => true,
            'message' => __('Cron-test vellykket', 'bd-product-feed'),
            'scheduled_time' => $next_test
        );
    }
    
    /**
     * Force run scheduled update (for testing)
     */
    public function force_run() {
        $this->execute_scheduled_update();
    }
    
    /**
     * Get cron logs
     */
    public function get_cron_logs($limit = 20) {
        $core = new BD_Product_Feed_Core();
        $all_logs = $core->get_logs(100);
        
        // Filter for cron-related logs
        $cron_logs = array();
        foreach ($all_logs as $log) {
            if (strpos($log['message'], 'scheduled') !== false || 
                strpos($log['message'], 'cron') !== false ||
                strpos($log['message'], 'Retry') !== false) {
                $cron_logs[] = $log;
            }
        }
        
        return array_slice($cron_logs, -$limit);
    }
    
    /**
     * Update cron frequency
     */
    public function update_frequency($new_frequency) {
        $old_frequency = get_option('bd_product_feed_update_frequency', 'daily');
        
        // Update the option
        update_option('bd_product_feed_update_frequency', $new_frequency);
        
        // Reschedule with new frequency
        $result = $this->schedule_feed_update($new_frequency);
        
        if ($result) {
            $core = new BD_Product_Feed_Core();
            $core->log("Cron frequency updated from {$old_frequency} to {$new_frequency}", 'info');
        }
        
        return $result;
    }
    
    /**
     * Get next scheduled run in human readable format
     */
    public function get_next_run_human() {
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        
        if (!$next_run) {
            return __('Ikke planlagt', 'bd-product-feed');
        }
        
        $time_diff = $next_run - time();
        
        if ($time_diff < 0) {
            return __('Forsinket', 'bd-product-feed');
        }
        
        return human_time_diff(time(), $next_run);
    }
}