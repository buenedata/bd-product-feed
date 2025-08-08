<?php
/*
Plugin Name: BD Product Feed
Description: Genererer raskt og enkelt en produktfeed som er kompatibel med tjenester som prisjakt.no og Google Merchant Center. Gjør produktene dine synlige i prisportaler for økt synlighet og salg.
Version: 1.0.1
Author: Buene Data
Author URI: https://buenedata.no
Plugin URI: https://github.com/buenedata/bd-product-feed
Update URI: https://github.com/buenedata/bd-product-feed
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Network: false
Text Domain: bd-product-feed
Domain Path: /languages
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BD_PRODUCT_FEED_VERSION', '1.0.1');
define('BD_PRODUCT_FEED_FILE', __FILE__);
define('BD_PRODUCT_FEED_PATH', plugin_dir_path(__FILE__));
define('BD_PRODUCT_FEED_URL', plugin_dir_url(__FILE__));
define('BD_PRODUCT_FEED_BASENAME', plugin_basename(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'bd_product_feed_woocommerce_missing_notice');
    return;
}

/**
 * Display notice if WooCommerce is not active
 */
function bd_product_feed_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>BD Product Feed:</strong> ';
    echo __('Dette pluginet krever WooCommerce for å fungere. Vennligst installer og aktiver WooCommerce først.', 'bd-product-feed');
    echo '</p></div>';
}

// Initialize updater
if (is_admin()) {
    require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-updater.php';
    new BD_Product_Feed_Updater(BD_PRODUCT_FEED_FILE, 'buenedata', 'bd-product-feed');
}

// Load menu helper
require_once BD_PRODUCT_FEED_PATH . 'bd-menu-helper.php';

// Load core classes
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-product-feed-core.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-feed-generator.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-currency-converter.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-product-filter.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-cron-manager.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-feed-validator.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-multilingual.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-settings-manager.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-analytics.php';
require_once BD_PRODUCT_FEED_PATH . 'includes/class-bd-admin-interface.php';

/**
 * Main plugin class
 */
class BD_Product_Feed {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Core functionality
     */
    public $core;
    
    /**
     * Admin interface
     */
    public $admin;
    
    /**
     * Feed generator
     */
    public $feed_generator;
    
    /**
     * Currency converter
     */
    public $currency_converter;
    
    /**
     * Product filter
     */
    public $product_filter;
    
    /**
     * Cron manager
     */
    public $cron_manager;
    
    /**
     * Feed validator
     */
    public $feed_validator;
    
    /**
     * Multilingual support
     */
    public $multilingual;
    
    /**
     * Settings manager
     */
    public $settings_manager;
    
    /**
     * Analytics
     */
    public $analytics;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize plugin
     */
    private function init() {
        // Initialize core components
        $this->core = new BD_Product_Feed_Core();
        $this->feed_generator = new BD_Feed_Generator();
        $this->currency_converter = new BD_Currency_Converter();
        $this->product_filter = new BD_Product_Filter();
        $this->cron_manager = new BD_Cron_Manager();
        $this->feed_validator = new BD_Feed_Validator();
        $this->multilingual = new BD_Multilingual();
        $this->settings_manager = new BD_Settings_Manager();
        $this->analytics = new BD_Analytics();
        
        // Initialize admin interface
        if (is_admin()) {
            $this->admin = new BD_Admin_Interface();
        }
        
        // Hook into WordPress
        add_action('init', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_feed_endpoint'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_scripts'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Add BD menu integration
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('bd-product-feed', false, dirname(BD_PRODUCT_FEED_BASENAME) . '/languages');
    }
    
    /**
     * Initialize feed endpoint
     */
    public function init_feed_endpoint() {
        // Add rewrite rule for feed endpoint
        add_rewrite_rule(
            '^bd-product-feed/([^/]+)/?$',
            'index.php?bd_feed_key=$matches[1]',
            'top'
        );
        
        // Add query var
        add_filter('query_vars', function($vars) {
            $vars[] = 'bd_feed_key';
            return $vars;
        });
        
        // Handle feed requests
        add_action('template_redirect', array($this, 'handle_feed_request'));
    }
    
    /**
     * Handle feed requests
     */
    public function handle_feed_request() {
        $feed_key = get_query_var('bd_feed_key');
        
        if (!empty($feed_key)) {
            // Validate feed key
            $stored_key = get_option('bd_product_feed_key', '');
            
            if ($feed_key === $stored_key) {
                // Serve the feed
                $this->serve_feed();
            } else {
                // Invalid key
                wp_die(__('Ugyldig feed-nøkkel.', 'bd-product-feed'), 403);
            }
        }
    }
    
    /**
     * Serve the XML feed
     */
    private function serve_feed() {
        $feed_file = BD_PRODUCT_FEED_PATH . 'feeds/product-feed.xml';
        
        if (file_exists($feed_file)) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            readfile($feed_file);
            exit;
        } else {
            wp_die(__('Feed ikke funnet. Vennligst generer feed først.', 'bd-product-feed'), 404);
        }
    }
    
    /**
     * Enqueue public scripts
     */
    public function enqueue_public_scripts() {
        // No public scripts needed for this plugin
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        bd_add_buene_data_menu(
            __('Product Feed', 'bd-product-feed'),
            'bd-product-feed',
            array($this->admin, 'display_admin_page'),
            '🛒'
        );
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create feeds directory
        $feeds_dir = BD_PRODUCT_FEED_PATH . 'feeds';
        if (!file_exists($feeds_dir)) {
            wp_mkdir_p($feeds_dir);
        }
        
        // Create .htaccess to protect feeds directory
        $htaccess_file = $feeds_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Order deny,allow\nDeny from all\n");
        }
        
        // Generate unique feed key
        if (!get_option('bd_product_feed_key')) {
            update_option('bd_product_feed_key', wp_generate_password(32, false));
        }
        
        // Set default options
        $default_options = array(
            'update_frequency' => 'daily',
            'include_categories' => array(),
            'exclude_categories' => array(),
            'product_status' => array('publish'),
            'stock_status' => array('instock'),
            'currency_conversion' => false,
            'target_currencies' => array('EUR', 'USD'),
            'feed_title' => get_bloginfo('name') . ' Product Feed',
            'feed_description' => __('Produktfeed for Google Merchant Center', 'bd-product-feed'),
            'email_notifications' => true,
            'notification_email' => get_option('admin_email'),
            'multilingual_enabled' => false,
            'target_languages' => array(),
        );
        
        foreach ($default_options as $key => $value) {
            if (!get_option('bd_product_feed_' . $key)) {
                update_option('bd_product_feed_' . $key, $value);
            }
        }
        
        // Schedule cron job
        $this->cron_manager->schedule_feed_update();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log activation
        error_log('BD Product Feed: Plugin activated successfully');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron jobs
        $this->cron_manager->clear_scheduled_updates();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        error_log('BD Product Feed: Plugin deactivated');
    }
}

/**
 * Initialize the plugin
 */
function bd_product_feed() {
    return BD_Product_Feed::get_instance();
}

// Start the plugin
bd_product_feed();

/**
 * Helper function to get feed URL
 */
function bd_get_product_feed_url() {
    $feed_key = get_option('bd_product_feed_key', '');
    if (empty($feed_key)) {
        return false;
    }
    
    return home_url('bd-product-feed/' . $feed_key);
}

/**
 * Helper function to check if feed exists
 */
function bd_product_feed_exists() {
    $feed_file = BD_PRODUCT_FEED_PATH . 'feeds/product-feed.xml';
    return file_exists($feed_file);
}

/**
 * Helper function to get feed last modified time
 */
function bd_get_product_feed_last_modified() {
    $feed_file = BD_PRODUCT_FEED_PATH . 'feeds/product-feed.xml';
    if (file_exists($feed_file)) {
        return filemtime($feed_file);
    }
    return false;
}