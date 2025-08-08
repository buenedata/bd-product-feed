<?php
/**
 * BD Feed Generator Class
 * Generates Google Merchant Center compatible XML feeds
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Feed_Generator {
    
    /**
     * Product filter instance
     */
    private $product_filter;
    
    /**
     * Currency converter instance
     */
    private $currency_converter;
    
    /**
     * Feed options
     */
    private $options;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->product_filter = new BD_Product_Filter();
        $this->currency_converter = new BD_Currency_Converter();
        $this->load_options();
    }
    
    /**
     * Load feed options
     */
    private function load_options() {
        $this->options = array(
            'feed_title' => get_option('bd_product_feed_feed_title', get_bloginfo('name') . ' Product Feed'),
            'feed_description' => get_option('bd_product_feed_feed_description', __('Produktfeed for Google Merchant Center', 'bd-product-feed')),
            'currency_conversion' => get_option('bd_product_feed_currency_conversion', false),
            'target_currencies' => get_option('bd_product_feed_target_currencies', array('EUR', 'USD')),
            'include_categories' => get_option('bd_product_feed_include_categories', array()),
            'exclude_categories' => get_option('bd_product_feed_exclude_categories', array()),
            'product_status' => get_option('bd_product_feed_product_status', array('publish')),
            'stock_status' => get_option('bd_product_feed_stock_status', array('instock')),
        );
    }
    
    /**
     * Generate full product feed
     */
    public function generate() {
        try {
            // Get filtered products
            $products = $this->product_filter->get_filtered_products($this->options);
            
            if (empty($products)) {
                return array(
                    'success' => false,
                    'message' => __('Ingen produkter funnet med gjeldende filtre', 'bd-product-feed')
                );
            }
            
            // Generate XML for each currency
            $currencies = $this->options['currency_conversion'] ? $this->options['target_currencies'] : array(get_woocommerce_currency());
            $generated_feeds = array();
            
            foreach ($currencies as $currency) {
                $xml_content = $this->generate_xml($products, $currency);
                $feed_file = $this->save_feed($xml_content, $currency);
                
                if ($feed_file) {
                    $generated_feeds[] = array(
                        'currency' => $currency,
                        'file' => $feed_file,
                        'url' => $this->get_feed_url($currency)
                    );
                }
            }
            
            // Update last generation time
            update_option('bd_product_feed_last_generated', current_time('timestamp'));
            
            return array(
                'success' => true,
                'message' => __('Feed generert', 'bd-product-feed'),
                'product_count' => count($products),
                'feeds' => $generated_feeds,
                'generated_at' => current_time('Y-m-d H:i:s')
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Generate test feed with limited products
     */
    public function generate_test($limit = 10) {
        try {
            // Get limited products for testing
            $products = $this->product_filter->get_filtered_products($this->options, $limit);
            
            if (empty($products)) {
                return array(
                    'success' => false,
                    'message' => __('Ingen produkter funnet for test', 'bd-product-feed')
                );
            }
            
            // Generate test XML
            $currency = get_woocommerce_currency();
            $xml_content = $this->generate_xml($products, $currency);
            
            // Save test feed
            $test_file = BD_PRODUCT_FEED_PATH . 'feeds/test-feed.xml';
            $saved = file_put_contents($test_file, $xml_content);
            
            if ($saved === false) {
                throw new Exception(__('Kunne ikke lagre test feed', 'bd-product-feed'));
            }
            
            return array(
                'success' => true,
                'message' => __('Test feed generert', 'bd-product-feed'),
                'product_count' => count($products),
                'test_file' => $test_file,
                'xml_preview' => substr($xml_content, 0, 1000) . '...'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Generate XML content
     */
    private function generate_xml($products, $currency = 'NOK') {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        // Create RSS root element
        $rss = $xml->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $xml->appendChild($rss);
        
        // Create channel element
        $channel = $xml->createElement('channel');
        $rss->appendChild($channel);
        
        // Add channel information
        $this->add_channel_info($xml, $channel, $currency);
        
        // Add products
        foreach ($products as $product) {
            $item = $this->create_product_item($xml, $product, $currency);
            if ($item) {
                $channel->appendChild($item);
            }
        }
        
        return $xml->saveXML();
    }
    
    /**
     * Add channel information
     */
    private function add_channel_info($xml, $channel, $currency) {
        // Title
        $title = $xml->createElement('title');
        $title_text = $this->options['feed_title'];
        if ($currency !== get_woocommerce_currency()) {
            $title_text .= ' (' . $currency . ')';
        }
        $title->appendChild($xml->createTextNode($title_text));
        $channel->appendChild($title);
        
        // Link
        $link = $xml->createElement('link');
        $link->appendChild($xml->createTextNode(home_url()));
        $channel->appendChild($link);
        
        // Description
        $description = $xml->createElement('description');
        $description->appendChild($xml->createTextNode($this->options['feed_description']));
        $channel->appendChild($description);
        
        // Language
        $language = $xml->createElement('language');
        $language->appendChild($xml->createTextNode(get_locale()));
        $channel->appendChild($language);
        
        // Last build date
        $lastBuildDate = $xml->createElement('lastBuildDate');
        $lastBuildDate->appendChild($xml->createTextNode(date('r')));
        $channel->appendChild($lastBuildDate);
        
        // Generator
        $generator = $xml->createElement('generator');
        $generator->appendChild($xml->createTextNode('BD Product Feed v' . BD_PRODUCT_FEED_VERSION));
        $channel->appendChild($generator);
    }
    
    /**
     * Create product item element
     */
    private function create_product_item($xml, $product, $currency) {
        try {
            $item = $xml->createElement('item');
            
            // Required fields
            $this->add_required_fields($xml, $item, $product, $currency);
            
            // Optional fields
            $this->add_optional_fields($xml, $item, $product, $currency);
            
            return $item;
            
        } catch (Exception $e) {
            error_log('BD Product Feed: Error creating item for product ' . $product->get_id() . ': ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Add required Google Merchant Center fields
     */
    private function add_required_fields($xml, $item, $product, $currency) {
        // ID (required)
        $id = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:id');
        $product_id = $product->get_sku() ? $product->get_sku() : $product->get_id();
        $id->appendChild($xml->createTextNode($product_id));
        $item->appendChild($id);
        
        // Title (required)
        $title = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:title');
        $title->appendChild($xml->createTextNode($this->clean_text($product->get_name())));
        $item->appendChild($title);
        
        // Description (required)
        $description = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:description');
        $desc_text = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
        $desc_text = $this->clean_text(wp_strip_all_tags($desc_text));
        $desc_text = substr($desc_text, 0, 5000); // Google limit
        $description->appendChild($xml->createTextNode($desc_text));
        $item->appendChild($description);
        
        // Link (required)
        $link = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:link');
        $link->appendChild($xml->createTextNode($product->get_permalink()));
        $item->appendChild($link);
        
        // Image link (required)
        $image_link = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:image_link');
        $image_url = wp_get_attachment_image_url($product->get_image_id(), 'full');
        if (!$image_url) {
            $image_url = wc_placeholder_img_src('full');
        }
        $image_link->appendChild($xml->createTextNode($image_url));
        $item->appendChild($image_link);
        
        // Availability (required)
        $availability = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:availability');
        $stock_status = $product->get_stock_status();
        $availability_text = $this->map_stock_status($stock_status);
        $availability->appendChild($xml->createTextNode($availability_text));
        $item->appendChild($availability);
        
        // Price (required)
        $price = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:price');
        $product_price = $this->get_product_price($product, $currency);
        $price->appendChild($xml->createTextNode($product_price . ' ' . $currency));
        $item->appendChild($price);
        
        // Condition (required)
        $condition = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:condition');
        $condition->appendChild($xml->createTextNode('new'));
        $item->appendChild($condition);
    }
    
    /**
     * Add optional Google Merchant Center fields
     */
    private function add_optional_fields($xml, $item, $product, $currency) {
        // Sale price
        if ($product->is_on_sale()) {
            $sale_price = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:sale_price');
            $sale_price_value = $this->get_product_sale_price($product, $currency);
            $sale_price->appendChild($xml->createTextNode($sale_price_value . ' ' . $currency));
            $item->appendChild($sale_price);
        }
        
        // Brand
        $brand = $this->get_product_brand($product);
        if ($brand) {
            $brand_element = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:brand');
            $brand_element->appendChild($xml->createTextNode($this->clean_text($brand)));
            $item->appendChild($brand_element);
        }
        
        // Product type (category)
        $product_type = $this->get_product_categories($product);
        if ($product_type) {
            $product_type_element = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:product_type');
            $product_type_element->appendChild($xml->createTextNode($this->clean_text($product_type)));
            $item->appendChild($product_type_element);
        }
        
        // GTIN (if available)
        $gtin = $this->get_product_gtin($product);
        if ($gtin) {
            $gtin_element = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:gtin');
            $gtin_element->appendChild($xml->createTextNode($gtin));
            $item->appendChild($gtin_element);
        }
        
        // MPN (if available)
        $mpn = $this->get_product_mpn($product);
        if ($mpn) {
            $mpn_element = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:mpn');
            $mpn_element->appendChild($xml->createTextNode($this->clean_text($mpn)));
            $item->appendChild($mpn_element);
        }
        
        // Additional images
        $this->add_additional_images($xml, $item, $product);
    }
    
    /**
     * Get product price in specified currency
     */
    private function get_product_price($product, $currency) {
        $price = $product->get_regular_price();
        
        if ($currency !== get_woocommerce_currency()) {
            $price = $this->currency_converter->convert($price, get_woocommerce_currency(), $currency);
        }
        
        return number_format((float)$price, 2, '.', '');
    }
    
    /**
     * Get product sale price in specified currency
     */
    private function get_product_sale_price($product, $currency) {
        $price = $product->get_sale_price();
        
        if ($currency !== get_woocommerce_currency()) {
            $price = $this->currency_converter->convert($price, get_woocommerce_currency(), $currency);
        }
        
        return number_format((float)$price, 2, '.', '');
    }
    
    /**
     * Map WooCommerce stock status to Google Merchant Center availability
     */
    private function map_stock_status($stock_status) {
        switch ($stock_status) {
            case 'instock':
                return 'in stock';
            case 'outofstock':
                return 'out of stock';
            case 'onbackorder':
                return 'preorder';
            default:
                return 'out of stock';
        }
    }
    
    /**
     * Get product brand
     */
    private function get_product_brand($product) {
        // Try to get brand from product attributes
        $attributes = $product->get_attributes();
        
        // Common brand attribute names
        $brand_attributes = array('brand', 'merk', 'merke', 'manufacturer', 'produsent');
        
        foreach ($brand_attributes as $brand_attr) {
            if (isset($attributes[$brand_attr])) {
                $attribute = $attributes[$brand_attr];
                if ($attribute->is_taxonomy()) {
                    $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                    if (!empty($terms)) {
                        return $terms[0]->name;
                    }
                } else {
                    return $attribute->get_options()[0];
                }
            }
        }
        
        // Fallback to custom field
        $brand = get_post_meta($product->get_id(), '_brand', true);
        if ($brand) {
            return $brand;
        }
        
        return '';
    }
    
    /**
     * Get product categories as hierarchy
     */
    private function get_product_categories($product) {
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        
        if (empty($categories)) {
            return '';
        }
        
        // Get the most specific category (deepest in hierarchy)
        $deepest_category = null;
        $max_depth = 0;
        
        foreach ($categories as $category) {
            $ancestors = get_ancestors($category->term_id, 'product_cat');
            $depth = count($ancestors);
            
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $deepest_category = $category;
            }
        }
        
        if (!$deepest_category) {
            $deepest_category = $categories[0];
        }
        
        // Build category hierarchy
        $hierarchy = array();
        $ancestors = get_ancestors($deepest_category->term_id, 'product_cat');
        $ancestors = array_reverse($ancestors);
        
        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term($ancestor_id, 'product_cat');
            $hierarchy[] = $ancestor->name;
        }
        
        $hierarchy[] = $deepest_category->name;
        
        return implode(' > ', $hierarchy);
    }
    
    /**
     * Get product GTIN
     */
    private function get_product_gtin($product) {
        return get_post_meta($product->get_id(), '_gtin', true);
    }
    
    /**
     * Get product MPN
     */
    private function get_product_mpn($product) {
        $mpn = get_post_meta($product->get_id(), '_mpn', true);
        if (!$mpn) {
            $mpn = $product->get_sku();
        }
        return $mpn;
    }
    
    /**
     * Add additional product images
     */
    private function add_additional_images($xml, $item, $product) {
        $gallery_ids = $product->get_gallery_image_ids();
        
        foreach ($gallery_ids as $index => $image_id) {
            if ($index >= 10) break; // Google limit
            
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($image_url) {
                $additional_image = $xml->createElementNS('http://base.google.com/ns/1.0', 'g:additional_image_link');
                $additional_image->appendChild($xml->createTextNode($image_url));
                $item->appendChild($additional_image);
            }
        }
    }
    
    /**
     * Clean text for XML
     */
    private function clean_text($text) {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = strip_tags($text);
        $text = trim($text);
        return $text;
    }
    
    /**
     * Save feed to file
     */
    private function save_feed($xml_content, $currency) {
        $feeds_dir = BD_PRODUCT_FEED_PATH . 'feeds';
        
        // Create feeds directory if it doesn't exist
        if (!file_exists($feeds_dir)) {
            wp_mkdir_p($feeds_dir);
        }
        
        $filename = $currency === get_woocommerce_currency() ? 'product-feed.xml' : 'product-feed-' . strtolower($currency) . '.xml';
        $feed_file = $feeds_dir . '/' . $filename;
        
        $saved = file_put_contents($feed_file, $xml_content);
        
        if ($saved === false) {
            throw new Exception(__('Kunne ikke lagre feed fil', 'bd-product-feed'));
        }
        
        return $feed_file;
    }
    
    /**
     * Get feed URL
     */
    private function get_feed_url($currency) {
        $feed_key = get_option('bd_product_feed_key', '');
        if (empty($feed_key)) {
            return false;
        }
        
        $filename = $currency === get_woocommerce_currency() ? 'product-feed.xml' : 'product-feed-' . strtolower($currency) . '.xml';
        return home_url('bd-product-feed/' . $feed_key . '/' . $filename);
    }
}