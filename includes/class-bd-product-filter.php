<?php
/**
 * BD Product Filter Class
 * Handles filtering of WooCommerce products for feed generation
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Product_Filter {
    
    /**
     * Get filtered products based on options
     */
    public function get_filtered_products($options, $limit = null) {
        $args = $this->build_query_args($options, $limit);
        
        // Use WooCommerce product query for better performance
        $query = new WC_Product_Query($args);
        $products = $query->get_products();
        
        // Additional filtering that can't be done in WP_Query
        $filtered_products = array();
        
        foreach ($products as $product) {
            if ($this->should_include_product($product, $options)) {
                $filtered_products[] = $product;
            }
        }
        
        return $filtered_products;
    }
    
    /**
     * Build WP_Query arguments
     */
    private function build_query_args($options, $limit = null) {
        $args = array(
            'status' => $options['product_status'],
            'limit' => $limit ? $limit : -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(),
            'tax_query' => array(),
        );
        
        // Stock status filter
        if (!empty($options['stock_status'])) {
            $args['stock_status'] = $options['stock_status'];
        }
        
        // Category filters
        if (!empty($options['include_categories']) || !empty($options['exclude_categories'])) {
            $this->add_category_filters($args, $options);
        }
        
        // Exclude variable products parent (include variations only)
        $args['meta_query'][] = array(
            'relation' => 'OR',
            array(
                'key' => '_product_type',
                'value' => 'variable',
                'compare' => '!='
            ),
            array(
                'key' => '_product_type',
                'compare' => 'NOT EXISTS'
            )
        );
        
        return $args;
    }
    
    /**
     * Add category filters to query args
     */
    private function add_category_filters(&$args, $options) {
        $tax_query = array();
        
        // Include specific categories
        if (!empty($options['include_categories'])) {
            $tax_query[] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $options['include_categories'],
                'operator' => 'IN'
            );
        }
        
        // Exclude specific categories
        if (!empty($options['exclude_categories'])) {
            $tax_query[] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $options['exclude_categories'],
                'operator' => 'NOT IN'
            );
        }
        
        // If both include and exclude are set, use AND relation
        if (!empty($options['include_categories']) && !empty($options['exclude_categories'])) {
            $tax_query['relation'] = 'AND';
        }
        
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }
    }
    
    /**
     * Check if product should be included
     */
    private function should_include_product($product, $options) {
        // Skip if product is not visible
        if (!$product->is_visible()) {
            return false;
        }
        
        // Skip if product has no price
        if (empty($product->get_price())) {
            return false;
        }
        
        // Skip if product has no image
        if (!$product->get_image_id()) {
            return false;
        }
        
        // Skip if product name is empty
        if (empty($product->get_name())) {
            return false;
        }
        
        // Skip if product has no description
        $description = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
        if (empty($description)) {
            return false;
        }
        
        // Additional custom filters can be added here
        return apply_filters('bd_product_feed_include_product', true, $product, $options);
    }
    
    /**
     * Get all product categories for admin interface
     */
    public function get_product_categories() {
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        if (is_wp_error($categories)) {
            return array();
        }
        
        return $this->build_category_tree($categories);
    }
    
    /**
     * Build hierarchical category tree
     */
    private function build_category_tree($categories, $parent_id = 0, $level = 0) {
        $tree = array();
        
        foreach ($categories as $category) {
            if ($category->parent == $parent_id) {
                $category->level = $level;
                $category->indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
                $tree[] = $category;
                
                // Add children
                $children = $this->build_category_tree($categories, $category->term_id, $level + 1);
                $tree = array_merge($tree, $children);
            }
        }
        
        return $tree;
    }
    
    /**
     * Get product count for given filters
     */
    public function get_product_count($options) {
        $args = $this->build_query_args($options);
        $args['limit'] = -1;
        $args['return'] = 'ids';
        
        $query = new WC_Product_Query($args);
        $product_ids = $query->get_products();
        
        // Count products that pass additional filters
        $count = 0;
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $this->should_include_product($product, $options)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get product statistics
     */
    public function get_product_statistics() {
        $stats = array(
            'total_products' => 0,
            'published_products' => 0,
            'in_stock_products' => 0,
            'products_with_images' => 0,
            'products_with_prices' => 0,
            'variable_products' => 0,
            'simple_products' => 0,
            'categories_count' => 0
        );
        
        // Total products
        $stats['total_products'] = wp_count_posts('product')->publish;
        
        // Published products
        $published_args = array(
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids'
        );
        $query = new WC_Product_Query($published_args);
        $published_ids = $query->get_products();
        $stats['published_products'] = count($published_ids);
        
        // Detailed stats for published products
        foreach ($published_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
            
            // Stock status
            if ($product->is_in_stock()) {
                $stats['in_stock_products']++;
            }
            
            // Has image
            if ($product->get_image_id()) {
                $stats['products_with_images']++;
            }
            
            // Has price
            if (!empty($product->get_price())) {
                $stats['products_with_prices']++;
            }
            
            // Product type
            if ($product->is_type('variable')) {
                $stats['variable_products']++;
            } elseif ($product->is_type('simple')) {
                $stats['simple_products']++;
            }
        }
        
        // Categories count
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true
        ));
        $stats['categories_count'] = is_wp_error($categories) ? 0 : count($categories);
        
        return $stats;
    }
    
    /**
     * Validate filter options
     */
    public function validate_filter_options($options) {
        $errors = array();
        
        // Validate product status
        if (empty($options['product_status'])) {
            $errors[] = __('Minst én produktstatus må velges', 'bd-product-feed');
        }
        
        $valid_statuses = array('publish', 'private', 'draft');
        foreach ($options['product_status'] as $status) {
            if (!in_array($status, $valid_statuses)) {
                $errors[] = sprintf(__('Ugyldig produktstatus: %s', 'bd-product-feed'), $status);
            }
        }
        
        // Validate stock status
        if (!empty($options['stock_status'])) {
            $valid_stock_statuses = array('instock', 'outofstock', 'onbackorder');
            foreach ($options['stock_status'] as $status) {
                if (!in_array($status, $valid_stock_statuses)) {
                    $errors[] = sprintf(__('Ugyldig lagerstatus: %s', 'bd-product-feed'), $status);
                }
            }
        }
        
        // Validate categories
        if (!empty($options['include_categories'])) {
            foreach ($options['include_categories'] as $cat_id) {
                if (!term_exists($cat_id, 'product_cat')) {
                    $errors[] = sprintf(__('Kategori ikke funnet: %d', 'bd-product-feed'), $cat_id);
                }
            }
        }
        
        if (!empty($options['exclude_categories'])) {
            foreach ($options['exclude_categories'] as $cat_id) {
                if (!term_exists($cat_id, 'product_cat')) {
                    $errors[] = sprintf(__('Kategori ikke funnet: %d', 'bd-product-feed'), $cat_id);
                }
            }
        }
        
        // Check for conflicting include/exclude categories
        if (!empty($options['include_categories']) && !empty($options['exclude_categories'])) {
            $conflicts = array_intersect($options['include_categories'], $options['exclude_categories']);
            if (!empty($conflicts)) {
                $errors[] = __('Samme kategori kan ikke både inkluderes og ekskluderes', 'bd-product-feed');
            }
        }
        
        return $errors;
    }
    
    /**
     * Get sample products for preview
     */
    public function get_sample_products($options, $limit = 5) {
        $products = $this->get_filtered_products($options, $limit);
        $sample_data = array();
        
        foreach ($products as $product) {
            $categories = wp_get_post_terms($product->get_id(), 'product_cat');
            $category_names = array();
            foreach ($categories as $category) {
                $category_names[] = $category->name;
            }
            
            $sample_data[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'stock_status' => $product->get_stock_status(),
                'categories' => implode(', ', $category_names),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
                'url' => $product->get_permalink()
            );
        }
        
        return $sample_data;
    }
}