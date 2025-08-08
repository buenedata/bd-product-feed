<?php
/**
 * BD Admin Interface Class - Complete Version
 * Handles the WordPress admin interface using BD design system
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Admin_Interface {
    
    /**
     * Core instance
     */
    private $core;
    
    /**
     * Product filter instance
     */
    private $product_filter;
    
    /**
     * Currency converter instance
     */
    private $currency_converter;
    
    /**
     * Cron manager instance
     */
    private $cron_manager;
    
    /**
     * Feed validator instance
     */
    private $feed_validator;
    
    /**
     * Multilingual instance
     */
    private $multilingual;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->core = new BD_Product_Feed_Core();
        $this->product_filter = new BD_Product_Filter();
        $this->currency_converter = new BD_Currency_Converter();
        $this->cron_manager = new BD_Cron_Manager();
        $this->feed_validator = new BD_Feed_Validator();
        $this->multilingual = new BD_Multilingual();
        
        // Hook into WordPress admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
        add_action('admin_notices', array($this, 'display_admin_notices'));
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'bd-product-feed') === false) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'bd-product-feed-admin',
            BD_PRODUCT_FEED_URL . 'admin/css/admin.css',
            array(),
            BD_PRODUCT_FEED_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'bd-product-feed-admin',
            BD_PRODUCT_FEED_URL . 'admin/js/admin.js',
            array('jquery'),
            BD_PRODUCT_FEED_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('bd-product-feed-admin', 'bdProductFeed', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bd_product_feed_nonce'),
            'strings' => array(
                'generating' => __('Genererer feed...', 'bd-product-feed'),
                'testing' => __('Tester feed...', 'bd-product-feed'),
                'validating' => __('Validerer feed...', 'bd-product-feed'),
                'success' => __('Vellykket!', 'bd-product-feed'),
                'error' => __('Feil oppstod', 'bd-product-feed'),
                'confirm_regenerate' => __('Er du sikker på at du vil regenerere feed?', 'bd-product-feed'),
            )
        ));
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle settings save
        if (isset($_POST['bd_save_settings']) && wp_verify_nonce($_POST['bd_nonce'], 'bd_product_feed_settings')) {
            $this->save_settings();
        }
        
        // Handle manual feed generation
        if (isset($_POST['bd_generate_feed']) && wp_verify_nonce($_POST['bd_nonce'], 'bd_product_feed_generate')) {
            $this->generate_feed_manual();
        }
        
        // Handle test feed generation
        if (isset($_POST['bd_test_feed']) && wp_verify_nonce($_POST['bd_nonce'], 'bd_product_feed_test')) {
            $this->test_feed_manual();
        }
        
        // Handle multilingual feed generation
        if (isset($_POST['bd_generate_multilingual_feeds']) && wp_verify_nonce($_POST['bd_nonce'], 'bd_product_feed_generate')) {
            $this->generate_multilingual_feeds_manual();
        }
    }
    
    /**
     * Save plugin settings
     */
    private function save_settings() {
        $settings = array(
            'update_frequency' => sanitize_text_field($_POST['update_frequency']),
            'include_categories' => isset($_POST['include_categories']) ? array_map('intval', $_POST['include_categories']) : array(),
            'exclude_categories' => isset($_POST['exclude_categories']) ? array_map('intval', $_POST['exclude_categories']) : array(),
            'product_status' => isset($_POST['product_status']) ? array_map('sanitize_text_field', $_POST['product_status']) : array('publish'),
            'stock_status' => isset($_POST['stock_status']) ? array_map('sanitize_text_field', $_POST['stock_status']) : array('instock'),
            'currency_conversion' => isset($_POST['currency_conversion']),
            'target_currencies' => isset($_POST['target_currencies']) ? array_map('sanitize_text_field', $_POST['target_currencies']) : array(),
            'feed_title' => sanitize_text_field($_POST['feed_title']),
            'feed_description' => sanitize_textarea_field($_POST['feed_description']),
            'email_notifications' => isset($_POST['email_notifications']),
            'notification_email' => sanitize_email($_POST['notification_email']),
            'multilingual_enabled' => isset($_POST['multilingual_enabled']),
            'target_languages' => isset($_POST['target_languages']) ? array_map('sanitize_text_field', $_POST['target_languages']) : array(),
        );
        
        // Validate settings
        $validation_errors = $this->product_filter->validate_filter_options($settings);
        
        // Validate multilingual settings
        $multilingual_errors = $this->multilingual->validate_language_settings($settings);
        $validation_errors = array_merge($validation_errors, $multilingual_errors);
        
        if (!empty($validation_errors)) {
            add_settings_error('bd_product_feed', 'validation_error', implode('<br>', $validation_errors));
            return;
        }
        
        // Save settings
        foreach ($settings as $key => $value) {
            $this->core->update_option($key, $value);
        }
        
        // Update cron schedule if frequency changed
        $this->cron_manager->update_frequency($settings['update_frequency']);
        
        add_settings_error('bd_product_feed', 'settings_saved', __('Innstillinger lagret', 'bd-product-feed'), 'updated');
    }
    
    /**
     * Generate feed manually
     */
    private function generate_feed_manual() {
        $result = $this->core->generate_feed();
        
        if ($result['success']) {
            add_settings_error('bd_product_feed', 'feed_generated', $result['message'], 'updated');
        } else {
            add_settings_error('bd_product_feed', 'feed_error', $result['message']);
        }
    }
    
    /**
     * Test feed manually
     */
    private function test_feed_manual() {
        $result = $this->core->test_feed();
        
        if ($result['success']) {
            add_settings_error('bd_product_feed', 'feed_tested', $result['message'], 'updated');
        } else {
            add_settings_error('bd_product_feed', 'feed_test_error', $result['message']);
        }
    }
    
    /**
     * Generate multilingual feeds manually
     */
    private function generate_multilingual_feeds_manual() {
        $options = $this->core->get_options();
        
        if (!$options['multilingual_enabled'] || empty($options['target_languages'])) {
            add_settings_error('bd_product_feed', 'multilingual_not_configured', __('Flerspråklig støtte er ikke konfigurert', 'bd-product-feed'));
            return;
        }
        
        $results = $this->multilingual->generate_multilingual_feeds($options);
        $success_count = 0;
        $error_count = 0;
        
        foreach ($results as $locale => $result) {
            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        if ($success_count > 0 && $error_count === 0) {
            add_settings_error('bd_product_feed', 'multilingual_feeds_generated',
                sprintf(__('%d flerspråklige feeds generert vellykket', 'bd-product-feed'), $success_count), 'updated');
        } elseif ($success_count > 0 && $error_count > 0) {
            add_settings_error('bd_product_feed', 'multilingual_feeds_partial',
                sprintf(__('%d feeds generert, %d feilet', 'bd-product-feed'), $success_count, $error_count), 'updated');
        } else {
            add_settings_error('bd_product_feed', 'multilingual_feeds_failed',
                __('Kunne ikke generere flerspråklige feeds', 'bd-product-feed'));
        }
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        settings_errors('bd_product_feed');
    }
    
    /**
     * Display main admin page
     */
    public function display_admin_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
        
        ?>
        <div class="wrap bd-product-feed-admin">
            <!-- Header Section -->
            <div class="bd-admin-header">
                <div class="bd-branding">
                    <h2>🛒 <?php _e('Product Feed', 'bd-product-feed'); ?></h2>
                    <p><?php _e('Generer produktfeed for Google Merchant Center og prisportaler', 'bd-product-feed'); ?></p>
                </div>
                <div class="bd-actions">
                    <button type="button" class="button button-primary" id="bd-generate-feed">
                        <?php _e('Generer Feed', 'bd-product-feed'); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="bd-test-feed">
                        <?php _e('Test Feed', 'bd-product-feed'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Navigation Tabs -->
            <nav class="nav-tab-wrapper">
                <a href="?page=bd-product-feed&tab=dashboard" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Dashboard', 'bd-product-feed'); ?>
                </a>
                <a href="?page=bd-product-feed&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Innstillinger', 'bd-product-feed'); ?>
                </a>
                <a href="?page=bd-product-feed&tab=products" class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Produkter', 'bd-product-feed'); ?>
                </a>
                <a href="?page=bd-product-feed&tab=validation" class="nav-tab <?php echo $active_tab === 'validation' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Validering', 'bd-product-feed'); ?>
                </a>
                <a href="?page=bd-product-feed&tab=multilingual" class="nav-tab <?php echo $active_tab === 'multilingual' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Flerspråklig', 'bd-product-feed'); ?>
                </a>
                <a href="?page=bd-product-feed&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logger', 'bd-product-feed'); ?>
                </a>
            </nav>
            
            <!-- Tab Content -->
            <div class="tab-content active">
                <?php
                switch ($active_tab) {
                    case 'dashboard':
                        $this->display_dashboard_tab();
                        break;
                    case 'settings':
                        $this->display_settings_tab();
                        break;
                    case 'products':
                        $this->display_products_tab();
                        break;
                    case 'validation':
                        $this->display_validation_tab();
                        break;
                    case 'multilingual':
                        $this->display_multilingual_tab();
                        break;
                    case 'logs':
                        $this->display_logs_tab();
                        break;
                    default:
                        $this->display_dashboard_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display dashboard tab
     */
    private function display_dashboard_tab() {
        $feed_stats = $this->core->get_feed_stats();
        $cron_status = $this->cron_manager->get_cron_status();
        $product_stats = $this->product_filter->get_product_statistics();
        
        ?>
        <div class="bd-settings-section">
            <h3><?php _e('Feed Status', 'bd-product-feed'); ?></h3>
            
            <div class="bd-status-grid">
                <div class="bd-status-item">
                    <strong><?php _e('Feed Status', 'bd-product-feed'); ?></strong>
                    <span class="bd-label <?php echo $feed_stats['exists'] ? 'success' : 'error'; ?>">
                        <?php echo $feed_stats['exists'] ? __('Aktiv', 'bd-product-feed') : __('Ikke generert', 'bd-product-feed'); ?>
                    </span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Produkter i feed', 'bd-product-feed'); ?></strong>
                    <span><?php echo number_format($feed_stats['product_count']); ?></span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Sist oppdatert', 'bd-product-feed'); ?></strong>
                    <span>
                        <?php 
                        if ($feed_stats['last_modified']) {
                            echo human_time_diff($feed_stats['last_modified']) . ' ' . __('siden', 'bd-product-feed');
                        } else {
                            echo __('Aldri', 'bd-product-feed');
                        }
                        ?>
                    </span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Filstørrelse', 'bd-product-feed'); ?></strong>
                    <span><?php echo $feed_stats['file_size'] ? size_format($feed_stats['file_size']) : '0 B'; ?></span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Automatisk oppdatering', 'bd-product-feed'); ?></strong>
                    <span class="bd-label <?php echo $cron_status['is_scheduled'] ? 'success' : 'warning'; ?>">
                        <?php echo $cron_status['is_scheduled'] ? __('Aktiv', 'bd-product-feed') : __('Inaktiv', 'bd-product-feed'); ?>
                    </span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Neste oppdatering', 'bd-product-feed'); ?></strong>
                    <span><?php echo $this->cron_manager->get_next_run_human(); ?></span>
                </div>
            </div>
            
            <?php if ($feed_stats['exists'] && $feed_stats['feed_url']): ?>
            <div class="bd-info-box">
                <strong><?php _e('Feed URL:', 'bd-product-feed'); ?></strong><br>
                <code><?php echo esc_url($feed_stats['feed_url']); ?></code>
                <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($feed_stats['feed_url']); ?>')">
                    <?php _e('Kopier', 'bd-product-feed'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="bd-settings-section">
            <h3><?php _e('Produktstatistikk', 'bd-product-feed'); ?></h3>
            
            <div class="bd-status-grid">
                <div class="bd-status-item">
                    <strong><?php _e('Totalt produkter', 'bd-product-feed'); ?></strong>
                    <span><?php echo number_format($product_stats['total_products']); ?></span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Publiserte produkter', 'bd-product-feed'); ?></strong>
                    <span><?php echo number_format($product_stats['published_products']); ?></span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('På lager', 'bd-product-feed'); ?></strong>
                    <span><?php echo number_format($product_stats['in_stock_products']); ?></span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Med bilder', 'bd-product-feed'); ?></strong>
                    <span><?php echo number_format($product_stats['products_with_images']); ?></span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Med priser', 'bd-product-feed'); ?></strong>
                    <span><?php echo number_format($product_stats['products_with_prices']); ?></span>
                </div>
                
                <div class="bd-status-item">
                    <strong><?php _e('Kategorier', 'bd-product-feed'); ?></strong>
                    <span><?php echo number_format($product_stats['categories_count']); ?></span>
                </div>
            </div>
        </div>
        
        <div class="bd-settings-section">
            <h3><?php _e('Hurtighandlinger', 'bd-product-feed'); ?></h3>
            
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <?php wp_nonce_field('bd_product_feed_generate', 'bd_nonce'); ?>
                <input type="submit" name="bd_generate_feed" class="button button-primary" 
                       value="<?php _e('Generer Feed Nå', 'bd-product-feed'); ?>"
                       onclick="return confirm('<?php _e('Er du sikker på at du vil regenerere feed?', 'bd-product-feed'); ?>')">
            </form>
            
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <?php wp_nonce_field('bd_product_feed_test', 'bd_nonce'); ?>
                <input type="submit" name="bd_test_feed" class="button button-secondary" 
                       value="<?php _e('Test Feed (10 produkter)', 'bd-product-feed'); ?>">
            </form>
            
            <button type="button" class="button button-secondary" id="bd-validate-feed">
                <?php _e('Valider Feed', 'bd-product-feed'); ?>
            </button>
        </div>
        <?php
    }
    
    /**
     * Display settings tab
     */
    private function display_settings_tab() {
        $options = $this->core->get_options();
        $categories = $this->product_filter->get_product_categories();
        $frequencies = $this->cron_manager->get_available_frequencies();
        $currencies = $this->currency_converter->get_supported_currencies();
        
        ?>
        <form method="post">
            <?php wp_nonce_field('bd_product_feed_settings', 'bd_nonce'); ?>
            
            <div class="bd-settings-section">
                <h3><?php _e('Grunnleggende innstillinger', 'bd-product-feed'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Feed tittel', 'bd-product-feed'); ?></th>
                        <td>
                            <input type="text" name="feed_title" class="regular-text" 
                                   value="<?php echo esc_attr($options['feed_title']); ?>" />
                            <p class="description"><?php _e('Tittel som vises i feed', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Feed beskrivelse', 'bd-product-feed'); ?></th>
                        <td>
                            <textarea name="feed_description" class="large-text" rows="3"><?php echo esc_textarea($options['feed_description']); ?></textarea>
                            <p class="description"><?php _e('Beskrivelse av feed', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Oppdateringsfrekvens', 'bd-product-feed'); ?></th>
                        <td>
                            <select name="update_frequency">
                                <?php foreach ($frequencies as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($options['update_frequency'], $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Hvor ofte feed skal oppdateres automatisk', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="bd-settings-section">
                <h3><?php _e('Produktfiltrering', 'bd-product-feed'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Produktstatus', 'bd-product-feed'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="product_status[]" value="publish" 
                                           <?php checked(in_array('publish', $options['product_status'])); ?> />
                                    <?php _e('Publisert', 'bd-product-feed'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="product_status[]" value="private" 
                                           <?php checked(in_array('private', $options['product_status'])); ?> />
                                    <?php _e('Privat', 'bd-product-feed'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="product_status[]" value="draft" 
                                           <?php checked(in_array('draft', $options['product_status'])); ?> />
                                    <?php _e('Utkast', 'bd-product-feed'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Lagerstatus', 'bd-product-feed'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="stock_status[]" value="instock" 
                                           <?php checked(in_array('instock', $options['stock_status'])); ?> />
                                    <?php _e('På lager', 'bd-product-feed'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="stock_status[]" value="outofstock" 
                                           <?php checked(in_array('outofstock', $options['stock_status'])); ?> />
                                    <?php _e('Ikke på lager', 'bd-product-feed'); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="stock_status[]" value="onbackorder" 
                                           <?php checked(in_array('onbackorder', $options['stock_status'])); ?> />
                                    <?php _e('Restordre', 'bd-product-feed'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Inkluder kategorier', 'bd-product-feed'); ?></th>
                        <td>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                                <?php foreach ($categories as $category): ?>
                                <label style="display: block;">
                                    <input type="checkbox" name="include_categories[]" value="<?php echo $category->term_id; ?>" 
                                           <?php checked(in_array($category->term_id, $options['include_categories'])); ?> />
                                    <?php echo $category->indent . esc_html($category->name); ?>
                                    <small>(<?php echo $category->count; ?>)</small>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php _e('Velg spesifikke kategorier å inkludere. Tom = alle kategorier', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Ekskluder kategorier', 'bd-product-feed'); ?></th>
                        <td>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                                <?php foreach ($categories as $category): ?>
                                <label style="display: block;">
                                    <input type="checkbox" name="exclude_categories[]" value="<?php echo $category->term_id; ?>" 
                                           <?php checked(in_array($category->term_id, $options['exclude_categories'])); ?> />
                                    <?php echo $category->indent . esc_html($category->name); ?>
                                    <small>(<?php echo $category->count; ?>)</small>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php _e('Velg kategorier å ekskludere fra feed', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="bd-settings-section">
                <h3><?php _e('Valutakonvertering', 'bd-product-feed'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Aktiver valutakonvertering', 'bd-product-feed'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="currency_conversion" value="1" 
                                       <?php checked($options['currency_conversion']); ?> />
                                <?php _e('Generer feed for flere valutaer', 'bd-product-feed'); ?>
                            </label>
                            <p class="description"><?php _e('Krever API-nøkkel for valutakurser', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Målvalutaer', 'bd-product-feed'); ?></th>
                        <td>
                            <fieldset>
                                <?php foreach ($currencies as $code => $name): ?>
                                <label>
                                    <input type="checkbox" name="target_currencies[]" value="<?php echo esc_attr($code); ?>" 
                                           <?php checked(in_array($code, $options['target_currencies'])); ?> />
                                    <?php echo esc_html($name . ' (' . $code . ')'); ?>
                                </label><br>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="bd-settings-section">
                <h3><?php _e('E-postvarsler', 'bd-product-feed'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Aktiver e-postvarsler', 'bd-product-feed'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_notifications" value="1" 
                                       <?php checked($options['email_notifications']); ?> />
                                <?php _e('Send e-post ved vellykket/mislykket feed-generering', 'bd-product-feed'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('E-postadresse', 'bd-product-feed'); ?></th>
                        <td>
                            <input type="email" name="notification_email" class="regular-text" 
                                   value="<?php echo esc_attr($options['notification_email']); ?>" />
                            <p class="description"><?php _e('E-postadresse for varsler', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p class="submit">
                <input type="submit" name="bd_save_settings" class="button-primary" 
                       value="<?php _e('Lagre innstillinger', 'bd-product-feed'); ?>" />
            </p>
        </form>
        <?php
    }
    
    /**
     * Display products tab
     */
    private function display_products_tab() {
        $options = $this->core->get_options();
        $sample_products = $this->product_filter->get_sample_products($options, 10);
        $product_count = $this->product_filter->get_product_count($options);
        
        ?>
        <div class="bd-settings-section">
            <h3><?php _e('Produktforhåndsvisning', 'bd-product-feed'); ?></h3>
            
            <p>
                <?php printf(
                    __('Med gjeldende filtre vil %d produkter bli inkludert i feed.', 'bd-product-feed'),
                    number_format($product_count)
                ); ?>
            </p>
            
            <?php if (!empty($sample_products)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Bilde', 'bd-product-feed'); ?></th>
                        <th><?php _e('Navn', 'bd-product-feed'); ?></th>
                        <th><?php _e('Pris', 'bd-product-feed'); ?></th>
                        <th><?php _e('Lagerstatus', 'bd-product-feed'); ?></th>
                        <th><?php _e('Kategorier', 'bd-product-feed'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sample_products as $product): ?>
                    <tr>
                        <td>
                            <?php if ($product['image']): ?>
                            <img src="<?php echo esc_url($product['image']); ?>" alt="" style="width: 50px; height: 50px; object-fit: cover;">
                            <?php else: ?>
                            <div style="width: 50px; height: 50px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; font-size: 10px;">
                                <?php _e('Ingen bilde', 'bd-product-feed'); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($product['name']); ?></strong><br>
                            <small>ID: <?php echo $product['id']; ?></small>
                        </td>
                        <td><?php echo wc_price($product['price']); ?></td>
                        <td>
                            <span class="bd-label <?php echo $product['stock_status'] === 'instock' ? 'success' : 'warning'; ?>">
                                <?php
                                switch ($product['stock_status']) {
                                    case 'instock':
                                        _e('På lager', 'bd-product-feed');
                                        break;
                                    case 'outofstock':
                                        _e('Ikke på lager', 'bd-product-feed');
                                        break;
                                    case 'onbackorder':
                                        _e('Restordre', 'bd-product-feed');
                                        break;
                                    default:
                                        echo esc_html($product['stock_status']);
                                }
                                ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            if (!empty($product['categories'])) {
                                echo esc_html(implode(', ', $product['categories']));
                            } else {
                                _e('Ingen kategorier', 'bd-product-feed');
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p><?php _e('Ingen produkter funnet med gjeldende filtre.', 'bd-product-feed'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display validation tab
     */
    private function display_validation_tab() {
        $feed_stats = $this->core->get_feed_stats();
        
        ?>
        <div class="bd-settings-section">
            <h3><?php _e('Feed Validering', 'bd-product-feed'); ?></h3>
            
            <?php if ($feed_stats['exists']): ?>
            <div class="bd-validation-controls">
                <button type="button" class="button button-primary" id="bd-validate-feed-detailed">
                    <?php _e('Valider Feed', 'bd-product-feed'); ?>
                </button>
                <button type="button" class="button button-secondary" id="bd-check-feed-structure">
                    <?php _e('Sjekk XML Struktur', 'bd-product-feed'); ?>
                </button>
                <button type="button" class="button button-secondary" id="bd-test-google-merchant">
                    <?php _e('Test Google Merchant Center', 'bd-product-feed'); ?>
                </button>
            </div>
            
            <div id="bd-validation-results" class="bd-validation-results" style="display: none;">
                <!-- Results will be populated via AJAX -->
            </div>
            
            <div class="bd-info-box">
                <h4><?php _e('Validering inkluderer:', 'bd-product-feed'); ?></h4>
                <ul>
                    <li><?php _e('XML syntaks og struktur', 'bd-product-feed'); ?></li>
                    <li><?php _e('Google Merchant Center krav', 'bd-product-feed'); ?></li>
                    <li><?php _e('Påkrevde produktfelt', 'bd-product-feed'); ?></li>
                    <li><?php _e('Bildekvalitet og tilgjengelighet', 'bd-product-feed'); ?></li>
                    <li><?php _e('Priser og valutaformat', 'bd-product-feed'); ?></li>
                </ul>
            </div>
            
            <?php else: ?>
            <div class="bd-warning-box">
                <p><?php _e('Ingen feed funnet. Generer en feed først for å kunne validere den.', 'bd-product-feed'); ?></p>
                <form method="post" style="margin-top: 10px;">
                    <?php wp_nonce_field('bd_product_feed_generate', 'bd_nonce'); ?>
                    <input type="submit" name="bd_generate_feed" class="button button-primary"
                           value="<?php _e('Generer Feed Nå', 'bd-product-feed'); ?>">
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display multilingual tab
     */
    private function display_multilingual_tab() {
        $options = $this->core->get_options();
        $available_languages = $this->multilingual->get_available_languages_list();
        $supported_languages = $this->multilingual->get_supported_languages();
        $multilingual_plugin = $this->multilingual->get_multilingual_plugin();
        $is_multilingual_active = $this->multilingual->is_multilingual_active();
        
        ?>
        <div class="bd-settings-section">
            <h3><?php _e('Flerspråklig støtte', 'bd-product-feed'); ?></h3>
            
            <?php if (!$is_multilingual_active): ?>
            <div class="bd-warning-box">
                <h4><?php _e('Ingen flerspråklig plugin funnet', 'bd-product-feed'); ?></h4>
                <p><?php _e('For å bruke flerspråklig støtte må du installere og aktivere en av følgende plugins:', 'bd-product-feed'); ?></p>
                <ul>
                    <li><strong>WPML</strong> - WordPress Multilingual Plugin</li>
                    <li><strong>Polylang</strong> - Gratis flerspråklig plugin</li>
                    <li><strong>qTranslate-X</strong> - Enkel flerspråklig løsning</li>
                </ul>
                <p><?php _e('Når du har installert en flerspråklig plugin, vil du kunne generere feeds for flere språk automatisk.', 'bd-product-feed'); ?></p>
            </div>
            <?php else: ?>
            <div class="bd-info-box">
                <h4><?php printf(__('Flerspråklig plugin funnet: %s', 'bd-product-feed'), $multilingual_plugin); ?></h4>
                <p><?php _e('Du kan nå generere feeds for flere språk basert på dine språkinnstillinger.', 'bd-product-feed'); ?></p>
            </div>
            
            <form method="post">
                <?php wp_nonce_field('bd_product_feed_settings', 'bd_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Aktiver flerspråklig støtte', 'bd-product-feed'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="multilingual_enabled" value="1"
                                       <?php checked($options['multilingual_enabled']); ?> />
                                <?php _e('Generer separate feeds for hvert språk', 'bd-product-feed'); ?>
                            </label>
                            <p class="description"><?php _e('Når aktivert, vil det genereres en egen feed for hvert aktivt språk på nettstedet.', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                    
                    <?php if (!empty($available_languages)): ?>
                    <tr>
                        <th scope="row"><?php _e('Tilgjengelige språk', 'bd-product-feed'); ?></th>
                        <td>
                            <fieldset>
                                <?php foreach ($available_languages as $locale => $language_data): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" name="target_languages[]" value="<?php echo esc_attr($locale); ?>"
                                           <?php checked(in_array($locale, $options['target_languages'])); ?> />
                                    <?php echo esc_html($language_data['name']); ?>
                                    <small>(<?php echo esc_html($language_data['code']); ?>)</small>
                                    <?php if ($language_data['active']): ?>
                                    <span class="bd-label success"><?php _e('Aktiv', 'bd-product-feed'); ?></span>
                                    <?php endif; ?>
                                </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php _e('Velg hvilke språk du vil generere feeds for.', 'bd-product-feed'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <p class="submit">
                    <input type="submit" name="bd_save_settings" class="button-primary"
                           value="<?php _e('Lagre flerspråklige innstillinger', 'bd-product-feed'); ?>" />
                </p>
            </form>
            
            <?php if ($options['multilingual_enabled'] && !empty($options['target_languages'])): ?>
            <div class="bd-settings-section">
                <h4><?php _e('Flerspråklige feed-URLer', 'bd-product-feed'); ?></h4>
                
                <?php
                $multilingual_urls = $this->multilingual->get_multilingual_feed_urls();
                if (!empty($multilingual_urls)):
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Språk', 'bd-product-feed'); ?></th>
                            <th><?php _e('Språkkode', 'bd-product-feed'); ?></th>
                            <th><?php _e('Feed URL', 'bd-product-feed'); ?></th>
                            <th><?php _e('Status', 'bd-product-feed'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($multilingual_urls as $locale => $url_data): ?>
                        <?php if (in_array($locale, $options['target_languages'])): ?>
                        <tr>
                            <td><?php echo esc_html($url_data['language']); ?></td>
                            <td><code><?php echo esc_html($url_data['code']); ?></code></td>
                            <td>
                                <code><?php echo esc_url($url_data['url']); ?></code>
                                <button type="button" class="button button-small"
                                        onclick="navigator.clipboard.writeText('<?php echo esc_js($url_data['url']); ?>')">
                                    <?php _e('Kopier', 'bd-product-feed'); ?>
                                </button>
                            </td>
                            <td>
                                <span class="bd-label <?php echo $url_data['exists'] ? 'success' : 'warning'; ?>">
                                    <?php echo $url_data['exists'] ? __('Generert', 'bd-product-feed') : __('Ikke generert', 'bd-product-feed'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 15px;">
                    <form method="post" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field('bd_product_feed_generate', 'bd_nonce'); ?>
                        <input type="submit" name="bd_generate_multilingual_feeds" class="button button-primary"
                               value="<?php _e('Generer alle flerspråklige feeds', 'bd-product-feed'); ?>"
                               onclick="return confirm('<?php _e('Er du sikker på at du vil regenerere alle flerspråklige feeds?', 'bd-product-feed'); ?>')">
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display logs tab
     */
    private function display_logs_tab() {
        $logs = $this->core->get_recent_logs(50);
        
        ?>
        <div class="bd-settings-section">
            <h3><?php _e('Systemlogger', 'bd-product-feed'); ?></h3>
            
            <div class="bd-log-controls">
                <button type="button" class="button button-secondary" id="bd-clear-logs">
                    <?php _e('Tøm logger', 'bd-product-feed'); ?>
                </button>
                <button type="button" class="button button-secondary" id="bd-download-logs">
                    <?php _e('Last ned logger', 'bd-product-feed'); ?>
                </button>
                <button type="button" class="button button-secondary" id="bd-refresh-logs">
                    <?php _e('Oppdater', 'bd-product-feed'); ?>
                </button>
            </div>
            
            <?php if (!empty($logs)): ?>
            <div class="bd-log-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;"><?php _e('Tidspunkt', 'bd-product-feed'); ?></th>
                            <th style="width: 80px;"><?php _e('Nivå', 'bd-product-feed'); ?></th>
                            <th><?php _e('Melding', 'bd-product-feed'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr class="bd-log-entry bd-log-<?php echo esc_attr($log['level']); ?>">
                            <td><?php echo esc_html(date('Y-m-d H:i:s', $log['timestamp'])); ?></td>
                            <td>
                                <span class="bd-label <?php echo $this->get_log_level_class($log['level']); ?>">
                                    <?php echo esc_html(strtoupper($log['level'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo esc_html($log['message']); ?>
                                <?php if (!empty($log['context'])): ?>
                                <details style="margin-top: 5px;">
                                    <summary style="cursor: pointer; color: #666;"><?php _e('Detaljer', 'bd-product-feed'); ?></summary>
                                    <pre style="background: #f9f9f9; padding: 10px; margin-top: 5px; font-size: 11px; overflow-x: auto;"><?php echo esc_html(print_r($log['context'], true)); ?></pre>
                                </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="bd-info-box">
                <p><?php _e('Ingen logger funnet.', 'bd-product-feed'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Get CSS class for log level
     */
    private function get_log_level_class($level) {
        switch (strtolower($level)) {
            case 'error':
                return 'error';
            case 'warning':
                return 'warning';
            case 'info':
                return 'info';
            case 'success':
                return 'success';
            default:
                return 'default';
        }
    }
}