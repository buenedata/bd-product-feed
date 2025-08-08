<?php
/**
 * BD Feed Validator Class
 * Validates XML feeds for Google Merchant Center compliance
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Feed_Validator {
    
    /**
     * Required Google Merchant Center fields
     */
    private $required_fields = array(
        'g:id',
        'g:title',
        'g:description',
        'g:link',
        'g:image_link',
        'g:availability',
        'g:price',
        'g:condition'
    );
    
    /**
     * Optional but recommended fields
     */
    private $recommended_fields = array(
        'g:brand',
        'g:product_type',
        'g:google_product_category',
        'g:gtin',
        'g:mpn'
    );
    
    /**
     * Validation errors
     */
    private $errors = array();
    
    /**
     * Validation warnings
     */
    private $warnings = array();
    
    /**
     * Validate feed file
     */
    public function validate($feed_file = null) {
        $this->errors = array();
        $this->warnings = array();
        
        if ($feed_file === null) {
            $feed_file = BD_PRODUCT_FEED_PATH . 'feeds/product-feed.xml';
        }
        
        // Check if feed file exists
        if (!file_exists($feed_file)) {
            $this->errors[] = __('Feed-fil ikke funnet', 'bd-product-feed');
            return $this->get_validation_result();
        }
        
        // Check if file is readable
        if (!is_readable($feed_file)) {
            $this->errors[] = __('Feed-fil kan ikke leses', 'bd-product-feed');
            return $this->get_validation_result();
        }
        
        // Load XML
        $xml = $this->load_xml($feed_file);
        if (!$xml) {
            return $this->get_validation_result();
        }
        
        // Validate XML structure
        $this->validate_xml_structure($xml);
        
        // Validate channel information
        $this->validate_channel($xml);
        
        // Validate products
        $this->validate_products($xml);
        
        // Check file size and performance
        $this->validate_file_performance($feed_file);
        
        return $this->get_validation_result();
    }
    
    /**
     * Load and parse XML file
     */
    private function load_xml($feed_file) {
        // Disable libxml errors to handle them manually
        libxml_use_internal_errors(true);
        
        try {
            $xml = simplexml_load_file($feed_file);
            
            if ($xml === false) {
                $xml_errors = libxml_get_errors();
                foreach ($xml_errors as $error) {
                    $this->errors[] = sprintf(
                        __('XML-feil på linje %d: %s', 'bd-product-feed'),
                        $error->line,
                        trim($error->message)
                    );
                }
                return false;
            }
            
            return $xml;
            
        } catch (Exception $e) {
            $this->errors[] = sprintf(
                __('Kunne ikke laste XML: %s', 'bd-product-feed'),
                $e->getMessage()
            );
            return false;
        }
    }
    
    /**
     * Validate XML structure
     */
    private function validate_xml_structure($xml) {
        // Check RSS version
        if (!isset($xml['version']) || $xml['version'] != '2.0') {
            $this->errors[] = __('RSS-versjon må være 2.0', 'bd-product-feed');
        }
        
        // Check Google namespace
        $namespaces = $xml->getNamespaces(true);
        if (!isset($namespaces['g']) || $namespaces['g'] !== 'http://base.google.com/ns/1.0') {
            $this->errors[] = __('Google Merchant Center namespace mangler eller er feil', 'bd-product-feed');
        }
        
        // Check channel element
        if (!isset($xml->channel)) {
            $this->errors[] = __('Channel-element mangler', 'bd-product-feed');
        }
    }
    
    /**
     * Validate channel information
     */
    private function validate_channel($xml) {
        $channel = $xml->channel;
        
        // Required channel elements
        $required_channel_elements = array('title', 'link', 'description');
        
        foreach ($required_channel_elements as $element) {
            if (!isset($channel->$element) || empty((string)$channel->$element)) {
                $this->errors[] = sprintf(
                    __('Channel-element mangler: %s', 'bd-product-feed'),
                    $element
                );
            }
        }
        
        // Validate link format
        if (isset($channel->link)) {
            $link = (string)$channel->link;
            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                $this->errors[] = __('Channel link er ikke en gyldig URL', 'bd-product-feed');
            }
        }
        
        // Check for recommended elements
        $recommended_channel_elements = array('language', 'lastBuildDate');
        
        foreach ($recommended_channel_elements as $element) {
            if (!isset($channel->$element)) {
                $this->warnings[] = sprintf(
                    __('Anbefalt channel-element mangler: %s', 'bd-product-feed'),
                    $element
                );
            }
        }
    }
    
    /**
     * Validate products
     */
    private function validate_products($xml) {
        $items = $xml->channel->item;
        
        if (empty($items)) {
            $this->errors[] = __('Ingen produkter funnet i feed', 'bd-product-feed');
            return;
        }
        
        $product_count = count($items);
        $errors_per_product = array();
        $warnings_per_product = array();
        
        foreach ($items as $index => $item) {
            $product_errors = array();
            $product_warnings = array();
            
            // Validate required fields
            foreach ($this->required_fields as $field) {
                if (!$this->has_field($item, $field)) {
                    $product_errors[] = sprintf(
                        __('Påkrevd felt mangler: %s', 'bd-product-feed'),
                        $field
                    );
                }
            }
            
            // Validate field content
            $this->validate_product_fields($item, $product_errors, $product_warnings);
            
            // Store errors and warnings for this product
            if (!empty($product_errors)) {
                $errors_per_product[$index] = $product_errors;
            }
            
            if (!empty($product_warnings)) {
                $warnings_per_product[$index] = $product_warnings;
            }
        }
        
        // Add product-specific errors to main errors array
        foreach ($errors_per_product as $product_index => $product_errors) {
            foreach ($product_errors as $error) {
                $this->errors[] = sprintf(
                    __('Produkt %d: %s', 'bd-product-feed'),
                    $product_index + 1,
                    $error
                );
            }
        }
        
        // Add product-specific warnings to main warnings array
        foreach ($warnings_per_product as $product_index => $product_warnings) {
            foreach ($product_warnings as $warning) {
                $this->warnings[] = sprintf(
                    __('Produkt %d: %s', 'bd-product-feed'),
                    $product_index + 1,
                    $warning
                );
            }
        }
        
        // Check for recommended fields across all products
        $this->check_recommended_fields($items);
        
        // Add summary information
        $error_count = count($errors_per_product);
        $warning_count = count($warnings_per_product);
        
        if ($error_count > 0) {
            $this->errors[] = sprintf(
                __('%d av %d produkter har feil', 'bd-product-feed'),
                $error_count,
                $product_count
            );
        }
        
        if ($warning_count > 0) {
            $this->warnings[] = sprintf(
                __('%d av %d produkter har advarsler', 'bd-product-feed'),
                $warning_count,
                $product_count
            );
        }
    }
    
    /**
     * Check if item has specific field
     */
    private function has_field($item, $field) {
        $namespaces = $item->getNamespaces(true);
        
        if (strpos($field, 'g:') === 0) {
            $field_name = substr($field, 2);
            return isset($item->children($namespaces['g'])->$field_name);
        } else {
            return isset($item->$field);
        }
    }
    
    /**
     * Get field value
     */
    private function get_field_value($item, $field) {
        $namespaces = $item->getNamespaces(true);
        
        if (strpos($field, 'g:') === 0) {
            $field_name = substr($field, 2);
            return (string)$item->children($namespaces['g'])->$field_name;
        } else {
            return (string)$item->$field;
        }
    }
    
    /**
     * Validate individual product fields
     */
    private function validate_product_fields($item, &$errors, &$warnings) {
        // Validate ID
        if ($this->has_field($item, 'g:id')) {
            $id = $this->get_field_value($item, 'g:id');
            if (empty($id)) {
                $errors[] = __('ID kan ikke være tom', 'bd-product-feed');
            } elseif (strlen($id) > 50) {
                $warnings[] = __('ID er lengre enn anbefalt (50 tegn)', 'bd-product-feed');
            }
        }
        
        // Validate title
        if ($this->has_field($item, 'g:title')) {
            $title = $this->get_field_value($item, 'g:title');
            if (empty($title)) {
                $errors[] = __('Tittel kan ikke være tom', 'bd-product-feed');
            } elseif (strlen($title) > 150) {
                $warnings[] = __('Tittel er lengre enn anbefalt (150 tegn)', 'bd-product-feed');
            }
        }
        
        // Validate description
        if ($this->has_field($item, 'g:description')) {
            $description = $this->get_field_value($item, 'g:description');
            if (empty($description)) {
                $errors[] = __('Beskrivelse kan ikke være tom', 'bd-product-feed');
            } elseif (strlen($description) > 5000) {
                $errors[] = __('Beskrivelse er for lang (maks 5000 tegn)', 'bd-product-feed');
            } elseif (strlen($description) < 10) {
                $warnings[] = __('Beskrivelse er veldig kort', 'bd-product-feed');
            }
        }
        
        // Validate link
        if ($this->has_field($item, 'g:link')) {
            $link = $this->get_field_value($item, 'g:link');
            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                $errors[] = __('Link er ikke en gyldig URL', 'bd-product-feed');
            }
        }
        
        // Validate image link
        if ($this->has_field($item, 'g:image_link')) {
            $image_link = $this->get_field_value($item, 'g:image_link');
            if (!filter_var($image_link, FILTER_VALIDATE_URL)) {
                $errors[] = __('Bildelink er ikke en gyldig URL', 'bd-product-feed');
            }
        }
        
        // Validate availability
        if ($this->has_field($item, 'g:availability')) {
            $availability = $this->get_field_value($item, 'g:availability');
            $valid_availability = array('in stock', 'out of stock', 'preorder');
            if (!in_array($availability, $valid_availability)) {
                $errors[] = sprintf(
                    __('Ugyldig tilgjengelighet: %s', 'bd-product-feed'),
                    $availability
                );
            }
        }
        
        // Validate price
        if ($this->has_field($item, 'g:price')) {
            $price = $this->get_field_value($item, 'g:price');
            if (!$this->validate_price_format($price)) {
                $errors[] = sprintf(
                    __('Ugyldig prisformat: %s', 'bd-product-feed'),
                    $price
                );
            }
        }
        
        // Validate condition
        if ($this->has_field($item, 'g:condition')) {
            $condition = $this->get_field_value($item, 'g:condition');
            $valid_conditions = array('new', 'refurbished', 'used');
            if (!in_array($condition, $valid_conditions)) {
                $errors[] = sprintf(
                    __('Ugyldig tilstand: %s', 'bd-product-feed'),
                    $condition
                );
            }
        }
        
        // Validate GTIN if present
        if ($this->has_field($item, 'g:gtin')) {
            $gtin = $this->get_field_value($item, 'g:gtin');
            if (!$this->validate_gtin($gtin)) {
                $warnings[] = sprintf(
                    __('Ugyldig GTIN format: %s', 'bd-product-feed'),
                    $gtin
                );
            }
        }
    }
    
    /**
     * Validate price format
     */
    private function validate_price_format($price) {
        // Price should be in format "amount currency" (e.g., "29.99 NOK")
        $pattern = '/^\d+(\.\d{1,2})?\s+[A-Z]{3}$/';
        return preg_match($pattern, $price);
    }
    
    /**
     * Validate GTIN format
     */
    private function validate_gtin($gtin) {
        // GTIN should be 8, 12, 13, or 14 digits
        return preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $gtin);
    }
    
    /**
     * Check recommended fields across all products
     */
    private function check_recommended_fields($items) {
        $total_products = count($items);
        $field_counts = array();
        
        foreach ($this->recommended_fields as $field) {
            $field_counts[$field] = 0;
            
            foreach ($items as $item) {
                if ($this->has_field($item, $field)) {
                    $field_counts[$field]++;
                }
            }
            
            $percentage = ($field_counts[$field] / $total_products) * 100;
            
            if ($percentage < 50) {
                $this->warnings[] = sprintf(
                    __('Anbefalt felt %s mangler i %d%% av produktene', 'bd-product-feed'),
                    $field,
                    100 - round($percentage)
                );
            }
        }
    }
    
    /**
     * Validate file performance
     */
    private function validate_file_performance($feed_file) {
        $file_size = filesize($feed_file);
        
        // Check file size (warn if over 100MB)
        if ($file_size > 100 * 1024 * 1024) {
            $this->warnings[] = sprintf(
                __('Feed-fil er stor (%s). Dette kan påvirke ytelsen.', 'bd-product-feed'),
                size_format($file_size)
            );
        }
        
        // Check modification time
        $last_modified = filemtime($feed_file);
        $age_hours = (time() - $last_modified) / 3600;
        
        if ($age_hours > 24) {
            $this->warnings[] = sprintf(
                __('Feed-fil er %d timer gammel', 'bd-product-feed'),
                round($age_hours)
            );
        }
    }
    
    /**
     * Get validation result
     */
    private function get_validation_result() {
        return array(
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings)
        );
    }
    
    /**
     * Validate feed URL accessibility
     */
    public function validate_feed_url($feed_url = null) {
        if ($feed_url === null) {
            $feed_url = bd_get_product_feed_url();
        }
        
        if (!$feed_url) {
            return array(
                'accessible' => false,
                'error' => __('Feed URL ikke konfigurert', 'bd-product-feed')
            );
        }
        
        $response = wp_remote_get($feed_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'BD-Product-Feed-Validator/1.0'
            )
        ));
        
        if (is_wp_error($response)) {
            return array(
                'accessible' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        if ($response_code !== 200) {
            return array(
                'accessible' => false,
                'error' => sprintf(__('HTTP feil: %d', 'bd-product-feed'), $response_code)
            );
        }
        
        if (strpos($content_type, 'xml') === false) {
            return array(
                'accessible' => false,
                'error' => sprintf(__('Feil innholdstype: %s', 'bd-product-feed'), $content_type)
            );
        }
        
        return array(
            'accessible' => true,
            'response_code' => $response_code,
            'content_type' => $content_type,
            'content_length' => wp_remote_retrieve_header($response, 'content-length')
        );
    }
    
    /**
     * Get validation summary
     */
    public function get_validation_summary($feed_file = null) {
        $validation_result = $this->validate($feed_file);
        $url_result = $this->validate_feed_url();
        
        return array(
            'feed_validation' => $validation_result,
            'url_accessibility' => $url_result,
            'overall_status' => $validation_result['valid'] && $url_result['accessible'] ? 'valid' : 'invalid'
        );
    }
}