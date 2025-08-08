<?php
/**
 * BD Analytics Class
 * Handles feed statistics and analytics tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Analytics {
    
    /**
     * Core instance
     */
    private $core;
    
    /**
     * Analytics table name
     */
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->core = new BD_Product_Feed_Core();
        $this->table_name = $wpdb->prefix . 'bd_product_feed_analytics';
        
        // Hook into WordPress
        add_action('init', array($this, 'maybe_create_analytics_table'));
        add_action('template_redirect', array($this, 'track_feed_access'));
    }
    
    /**
     * Create analytics table if it doesn't exist
     */
    public function maybe_create_analytics_table() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if (!$table_exists) {
            $this->create_analytics_table();
        }
    }
    
    /**
     * Create analytics table
     */
    private function create_analytics_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            ip_address varchar(45),
            user_agent text,
            referer text,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY timestamp (timestamp)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Log table creation
        BD_Product_Feed_Core::log('info', 'Analytics table created successfully');
    }
    
    /**
     * Track feed access
     */
    public function track_feed_access() {
        $feed_key = get_query_var('bd_feed_key');
        
        if (!empty($feed_key)) {
            $stored_key = get_option('bd_product_feed_key', '');
            
            if ($feed_key === $stored_key) {
                $this->log_event('feed_access', array(
                    'feed_key' => $feed_key,
                    'timestamp' => current_time('mysql'),
                    'success' => true
                ));
            } else {
                $this->log_event('feed_access_denied', array(
                    'feed_key' => $feed_key,
                    'timestamp' => current_time('mysql'),
                    'reason' => 'invalid_key'
                ));
            }
        }
    }
    
    /**
     * Log analytics event
     */
    public function log_event($event_type, $event_data = array()) {
        global $wpdb;
        
        // Get client information
        $ip_address = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        
        // Insert event
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'event_type' => $event_type,
                'event_data' => wp_json_encode($event_data),
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'timestamp' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            BD_Product_Feed_Core::log('error', 'Failed to log analytics event: ' . $wpdb->last_error);
        }
        
        // Clean old events periodically
        if (rand(1, 100) === 1) {
            $this->cleanup_old_events();
        }
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Clean up old analytics events
     */
    private function cleanup_old_events() {
        global $wpdb;
        
        // Keep events for 90 days
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-90 days'));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE timestamp < %s",
            $cutoff_date
        ));
        
        if ($deleted > 0) {
            BD_Product_Feed_Core::log('info', "Cleaned up {$deleted} old analytics events");
        }
    }
    
    /**
     * Get feed access statistics
     */
    public function get_feed_access_stats($days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Total accesses
        $total_accesses = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE event_type = 'feed_access' AND timestamp >= %s",
            $start_date
        ));
        
        // Failed accesses
        $failed_accesses = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE event_type = 'feed_access_denied' AND timestamp >= %s",
            $start_date
        ));
        
        // Unique IPs
        $unique_ips = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name} 
             WHERE event_type = 'feed_access' AND timestamp >= %s",
            $start_date
        ));
        
        // Daily breakdown
        $daily_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(timestamp) as date, 
                    COUNT(*) as accesses,
                    COUNT(DISTINCT ip_address) as unique_visitors
             FROM {$this->table_name} 
             WHERE event_type = 'feed_access' AND timestamp >= %s
             GROUP BY DATE(timestamp)
             ORDER BY date DESC",
            $start_date
        ));
        
        return array(
            'total_accesses' => (int) $total_accesses,
            'failed_accesses' => (int) $failed_accesses,
            'unique_visitors' => (int) $unique_ips,
            'success_rate' => $total_accesses > 0 ? round(($total_accesses / ($total_accesses + $failed_accesses)) * 100, 2) : 0,
            'daily_stats' => $daily_stats,
            'period_days' => $days
        );
    }
    
    /**
     * Get top referrers
     */
    public function get_top_referrers($days = 30, $limit = 10) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $referrers = $wpdb->get_results($wpdb->prepare(
            "SELECT referer, COUNT(*) as count
             FROM {$this->table_name} 
             WHERE event_type = 'feed_access' 
             AND timestamp >= %s 
             AND referer != '' 
             AND referer IS NOT NULL
             GROUP BY referer
             ORDER BY count DESC
             LIMIT %d",
            $start_date,
            $limit
        ));
        
        return $referrers;
    }
    
    /**
     * Get user agent statistics
     */
    public function get_user_agent_stats($days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $user_agents = $wpdb->get_results($wpdb->prepare(
            "SELECT user_agent, COUNT(*) as count
             FROM {$this->table_name} 
             WHERE event_type = 'feed_access' 
             AND timestamp >= %s 
             AND user_agent != '' 
             AND user_agent IS NOT NULL
             GROUP BY user_agent
             ORDER BY count DESC
             LIMIT 20",
            $start_date
        ));
        
        // Categorize user agents
        $categories = array(
            'bots' => 0,
            'browsers' => 0,
            'feed_readers' => 0,
            'unknown' => 0
        );
        
        $bot_patterns = array(
            'bot', 'crawler', 'spider', 'scraper', 'google', 'bing', 'yahoo',
            'facebook', 'twitter', 'linkedin', 'pinterest', 'whatsapp'
        );
        
        $browser_patterns = array(
            'chrome', 'firefox', 'safari', 'edge', 'opera', 'mozilla'
        );
        
        $feed_patterns = array(
            'feed', 'rss', 'atom', 'reader', 'aggregator'
        );
        
        foreach ($user_agents as $ua) {
            $agent_lower = strtolower($ua->user_agent);
            $categorized = false;
            
            foreach ($bot_patterns as $pattern) {
                if (strpos($agent_lower, $pattern) !== false) {
                    $categories['bots'] += $ua->count;
                    $categorized = true;
                    break;
                }
            }
            
            if (!$categorized) {
                foreach ($feed_patterns as $pattern) {
                    if (strpos($agent_lower, $pattern) !== false) {
                        $categories['feed_readers'] += $ua->count;
                        $categorized = true;
                        break;
                    }
                }
            }
            
            if (!$categorized) {
                foreach ($browser_patterns as $pattern) {
                    if (strpos($agent_lower, $pattern) !== false) {
                        $categories['browsers'] += $ua->count;
                        $categorized = true;
                        break;
                    }
                }
            }
            
            if (!$categorized) {
                $categories['unknown'] += $ua->count;
            }
        }
        
        return array(
            'categories' => $categories,
            'top_agents' => array_slice($user_agents, 0, 10)
        );
    }
    
    /**
     * Get hourly access pattern
     */
    public function get_hourly_pattern($days = 7) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $hourly_data = $wpdb->get_results($wpdb->prepare(
            "SELECT HOUR(timestamp) as hour, COUNT(*) as count
             FROM {$this->table_name} 
             WHERE event_type = 'feed_access' AND timestamp >= %s
             GROUP BY HOUR(timestamp)
             ORDER BY hour",
            $start_date
        ));
        
        // Fill in missing hours with 0
        $hours = array_fill(0, 24, 0);
        foreach ($hourly_data as $data) {
            $hours[$data->hour] = (int) $data->count;
        }
        
        return $hours;
    }
    
    /**
     * Log feed generation event
     */
    public function log_feed_generation($success, $product_count = 0, $generation_time = 0, $file_size = 0) {
        $this->log_event('feed_generation', array(
            'success' => $success,
            'product_count' => $product_count,
            'generation_time' => $generation_time,
            'file_size' => $file_size,
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Get feed generation statistics
     */
    public function get_generation_stats($days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $generations = $wpdb->get_results($wpdb->prepare(
            "SELECT event_data FROM {$this->table_name} 
             WHERE event_type = 'feed_generation' AND timestamp >= %s
             ORDER BY timestamp DESC",
            $start_date
        ));
        
        $stats = array(
            'total_generations' => 0,
            'successful_generations' => 0,
            'failed_generations' => 0,
            'avg_products' => 0,
            'avg_generation_time' => 0,
            'avg_file_size' => 0,
            'recent_generations' => array()
        );
        
        $total_products = 0;
        $total_time = 0;
        $total_size = 0;
        $successful_count = 0;
        
        foreach ($generations as $generation) {
            $data = json_decode($generation->event_data, true);
            if (!$data) continue;
            
            $stats['total_generations']++;
            
            if ($data['success']) {
                $stats['successful_generations']++;
                $successful_count++;
                $total_products += $data['product_count'];
                $total_time += $data['generation_time'];
                $total_size += $data['file_size'];
            } else {
                $stats['failed_generations']++;
            }
            
            if (count($stats['recent_generations']) < 10) {
                $stats['recent_generations'][] = $data;
            }
        }
        
        if ($successful_count > 0) {
            $stats['avg_products'] = round($total_products / $successful_count);
            $stats['avg_generation_time'] = round($total_time / $successful_count, 2);
            $stats['avg_file_size'] = round($total_size / $successful_count);
        }
        
        $stats['success_rate'] = $stats['total_generations'] > 0 ? 
            round(($stats['successful_generations'] / $stats['total_generations']) * 100, 2) : 0;
        
        return $stats;
    }
    
    /**
     * Get comprehensive analytics dashboard data
     */
    public function get_dashboard_data($days = 30) {
        return array(
            'access_stats' => $this->get_feed_access_stats($days),
            'generation_stats' => $this->get_generation_stats($days),
            'top_referrers' => $this->get_top_referrers($days, 5),
            'user_agent_stats' => $this->get_user_agent_stats($days),
            'hourly_pattern' => $this->get_hourly_pattern(7),
            'period_days' => $days
        );
    }
    
    /**
     * Export analytics data
     */
    public function export_analytics_data($days = 30, $format = 'json') {
        $data = $this->get_dashboard_data($days);
        
        switch ($format) {
            case 'csv':
                return $this->export_to_csv($data);
            case 'json':
            default:
                return wp_json_encode($data, JSON_PRETTY_PRINT);
        }
    }
    
    /**
     * Export analytics to CSV format
     */
    private function export_to_csv($data) {
        $csv_data = array();
        
        // Header
        $csv_data[] = array('Metric', 'Value', 'Period');
        
        // Access stats
        $csv_data[] = array('Total Accesses', $data['access_stats']['total_accesses'], $data['period_days'] . ' days');
        $csv_data[] = array('Failed Accesses', $data['access_stats']['failed_accesses'], $data['period_days'] . ' days');
        $csv_data[] = array('Unique Visitors', $data['access_stats']['unique_visitors'], $data['period_days'] . ' days');
        $csv_data[] = array('Success Rate', $data['access_stats']['success_rate'] . '%', $data['period_days'] . ' days');
        
        // Generation stats
        $csv_data[] = array('Total Generations', $data['generation_stats']['total_generations'], $data['period_days'] . ' days');
        $csv_data[] = array('Successful Generations', $data['generation_stats']['successful_generations'], $data['period_days'] . ' days');
        $csv_data[] = array('Average Products', $data['generation_stats']['avg_products'], $data['period_days'] . ' days');
        $csv_data[] = array('Average Generation Time', $data['generation_stats']['avg_generation_time'] . 's', $data['period_days'] . ' days');
        
        // Convert to CSV string
        $output = '';
        foreach ($csv_data as $row) {
            $output .= implode(',', array_map(function($field) {
                return '"' . str_replace('"', '""', $field) . '"';
            }, $row)) . "\n";
        }
        
        return $output;
    }
    
    /**
     * Clear all analytics data
     */
    public function clear_analytics_data() {
        global $wpdb;
        
        $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        if ($result !== false) {
            BD_Product_Feed_Core::log('info', 'Analytics data cleared successfully');
            return true;
        } else {
            BD_Product_Feed_Core::log('error', 'Failed to clear analytics data: ' . $wpdb->last_error);
            return false;
        }
    }
}