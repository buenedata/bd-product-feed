<?php
/**
 * BD Multilingual Support Class
 * Handles multilingual feed generation based on WooCommerce settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Multilingual {
    
    /**
     * Supported languages
     */
    private $supported_languages = array(
        'nb_NO' => array('name' => 'Norsk (Bokmål)', 'code' => 'no', 'currency' => 'NOK'),
        'nn_NO' => array('name' => 'Norsk (Nynorsk)', 'code' => 'no', 'currency' => 'NOK'),
        'en_US' => array('name' => 'English (US)', 'code' => 'en', 'currency' => 'USD'),
        'en_GB' => array('name' => 'English (UK)', 'code' => 'en', 'currency' => 'GBP'),
        'da_DK' => array('name' => 'Dansk', 'code' => 'da', 'currency' => 'DKK'),
        'sv_SE' => array('name' => 'Svenska', 'code' => 'sv', 'currency' => 'SEK'),
        'de_DE' => array('name' => 'Deutsch', 'code' => 'de', 'currency' => 'EUR'),
        'fr_FR' => array('name' => 'Français', 'code' => 'fr', 'currency' => 'EUR'),
        'es_ES' => array('name' => 'Español', 'code' => 'es', 'currency' => 'EUR'),
        'it_IT' => array('name' => 'Italiano', 'code' => 'it', 'currency' => 'EUR'),
        'nl_NL' => array('name' => 'Nederlands', 'code' => 'nl', 'currency' => 'EUR'),
        'fi_FI' => array('name' => 'Suomi', 'code' => 'fi', 'currency' => 'EUR'),
    );
    
    /**
     * Current language
     */
    private $current_language;
    
    /**
     * Available languages on site
     */
    private $available_languages;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->current_language = $this->get_current_language();
        $this->available_languages = $this->get_available_languages();
    }
    
    /**
     * Get current WordPress language
     */
    public function get_current_language() {
        return get_locale();
    }
    
    /**
     * Get available languages on the site
     */
    public function get_available_languages() {
        $languages = array();
        
        // Check for WPML
        if (function_exists('icl_get_languages')) {
            $wpml_languages = icl_get_languages('skip_missing=0');
            foreach ($wpml_languages as $lang) {
                if (isset($this->supported_languages[$lang['default_locale']])) {
                    $languages[$lang['default_locale']] = array_merge(
                        $this->supported_languages[$lang['default_locale']],
                        array(
                            'active' => $lang['active'],
                            'url' => $lang['url'],
                            'flag' => $lang['country_flag_url']
                        )
                    );
                }
            }
        }
        
        // Check for Polylang
        elseif (function_exists('pll_the_languages')) {
            $pll_languages = pll_the_languages(array('raw' => 1));
            foreach ($pll_languages as $lang) {
                $locale = $lang['locale'];
                if (isset($this->supported_languages[$locale])) {
                    $languages[$locale] = array_merge(
                        $this->supported_languages[$locale],
                        array(
                            'active' => $lang['current_lang'],
                            'url' => $lang['url'],
                            'flag' => $lang['flag']
                        )
                    );
                }
            }
        }
        
        // Check for qTranslate-X
        elseif (function_exists('qtranxf_getLanguage')) {
            global $q_config;
            if (isset($q_config['enabled_languages'])) {
                foreach ($q_config['enabled_languages'] as $lang_code) {
                    $locale = $this->convert_language_code_to_locale($lang_code);
                    if (isset($this->supported_languages[$locale])) {
                        $languages[$locale] = array_merge(
                            $this->supported_languages[$locale],
                            array(
                                'active' => ($lang_code === qtranxf_getLanguage()),
                                'url' => qtranxf_convertURL(home_url(), $lang_code),
                                'flag' => ''
                            )
                        );
                    }
                }
            }
        }
        
        // Fallback to WordPress default language
        else {
            $current_locale = get_locale();
            if (isset($this->supported_languages[$current_locale])) {
                $languages[$current_locale] = array_merge(
                    $this->supported_languages[$current_locale],
                    array(
                        'active' => true,
                        'url' => home_url(),
                        'flag' => ''
                    )
                );
            }
        }
        
        return $languages;
    }
    
    /**
     * Convert language code to locale
     */
    private function convert_language_code_to_locale($lang_code) {
        $code_to_locale = array(
            'no' => 'nb_NO',
            'en' => 'en_US',
            'da' => 'da_DK',
            'sv' => 'sv_SE',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'nl' => 'nl_NL',
            'fi' => 'fi_FI',
        );
        
        return isset($code_to_locale[$lang_code]) ? $code_to_locale[$lang_code] : 'en_US';
    }
    
    /**
     * Get translated product data
     */
    public function get_translated_product_data($product_id, $language = null) {
        if (!$language) {
            $language = $this->current_language;
        }
        
        $original_language = $this->current_language;
        
        // Switch to target language
        $this->switch_language($language);
        
        // Get product in target language
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->switch_language($original_language);
            return false;
        }
        
        $translated_data = array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'categories' => $this->get_translated_categories($product),
            'tags' => $this->get_translated_tags($product),
            'attributes' => $this->get_translated_attributes($product),
            'language' => $language,
            'language_code' => $this->get_language_code($language),
        );
        
        // Switch back to original language
        $this->switch_language($original_language);
        
        return $translated_data;
    }
    
    /**
     * Switch language context
     */
    private function switch_language($language) {
        // WPML
        if (function_exists('icl_get_languages')) {
            global $sitepress;
            if ($sitepress) {
                $lang_code = $this->get_language_code($language);
                $sitepress->switch_lang($lang_code);
            }
        }
        
        // Polylang
        elseif (function_exists('pll_set_language')) {
            $lang_code = $this->get_language_code($language);
            pll_set_language($lang_code);
        }
        
        // qTranslate-X
        elseif (function_exists('qtranxf_getLanguage')) {
            global $q_config;
            $lang_code = $this->get_language_code($language);
            $q_config['language'] = $lang_code;
        }
        
        // Update current language
        $this->current_language = $language;
    }
    
    /**
     * Get language code from locale
     */
    public function get_language_code($locale) {
        if (isset($this->supported_languages[$locale])) {
            return $this->supported_languages[$locale]['code'];
        }
        
        // Extract language code from locale (e.g., 'nb' from 'nb_NO')
        return substr($locale, 0, 2);
    }
    
    /**
     * Get translated categories
     */
    private function get_translated_categories($product) {
        $categories = array();
        $product_categories = $product->get_category_ids();
        
        foreach ($product_categories as $cat_id) {
            $category = get_term($cat_id, 'product_cat');
            if ($category && !is_wp_error($category)) {
                $categories[] = $category->name;
            }
        }
        
        return $categories;
    }
    
    /**
     * Get translated tags
     */
    private function get_translated_tags($product) {
        $tags = array();
        $product_tags = $product->get_tag_ids();
        
        foreach ($product_tags as $tag_id) {
            $tag = get_term($tag_id, 'product_tag');
            if ($tag && !is_wp_error($tag)) {
                $tags[] = $tag->name;
            }
        }
        
        return $tags;
    }
    
    /**
     * Get translated attributes
     */
    private function get_translated_attributes($product) {
        $attributes = array();
        $product_attributes = $product->get_attributes();
        
        foreach ($product_attributes as $attribute) {
            if ($attribute->is_taxonomy()) {
                $taxonomy = $attribute->get_taxonomy_object();
                $attribute_name = $taxonomy ? $taxonomy->attribute_label : $attribute->get_name();
            } else {
                $attribute_name = $attribute->get_name();
            }
            
            $values = array();
            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                foreach ($terms as $term) {
                    $values[] = $term->name;
                }
            } else {
                $values = $attribute->get_options();
            }
            
            $attributes[$attribute_name] = implode(', ', $values);
        }
        
        return $attributes;
    }
    
    /**
     * Generate multilingual feeds
     */
    public function generate_multilingual_feeds($options = array()) {
        $results = array();
        
        foreach ($this->available_languages as $locale => $language_data) {
            if (!$language_data['active']) {
                continue;
            }
            
            // Generate feed for this language
            $feed_generator = new BD_Feed_Generator();
            $language_options = array_merge($options, array(
                'language' => $locale,
                'language_code' => $language_data['code'],
                'currency' => $language_data['currency']
            ));
            
            $result = $feed_generator->generate_feed($language_options);
            $results[$locale] = $result;
            
            // Log result
            if ($result['success']) {
                BD_Product_Feed_Core::log('info', sprintf(
                    'Multilingual feed generated successfully for %s (%s)',
                    $language_data['name'],
                    $locale
                ));
            } else {
                BD_Product_Feed_Core::log('error', sprintf(
                    'Failed to generate multilingual feed for %s (%s): %s',
                    $language_data['name'],
                    $locale,
                    $result['message']
                ));
            }
        }
        
        return $results;
    }
    
    /**
     * Get feed URLs for all languages
     */
    public function get_multilingual_feed_urls() {
        $urls = array();
        
        foreach ($this->available_languages as $locale => $language_data) {
            $language_code = $language_data['code'];
            $feed_url = home_url("/product-feed-{$language_code}.xml");
            
            $urls[$locale] = array(
                'url' => $feed_url,
                'language' => $language_data['name'],
                'code' => $language_code,
                'exists' => file_exists(ABSPATH . "product-feed-{$language_code}.xml")
            );
        }
        
        return $urls;
    }
    
    /**
     * Get supported languages list
     */
    public function get_supported_languages() {
        return $this->supported_languages;
    }
    
    /**
     * Get available languages list
     */
    public function get_available_languages_list() {
        return $this->available_languages;
    }
    
    /**
     * Check if multilingual plugin is active
     */
    public function is_multilingual_active() {
        return (
            function_exists('icl_get_languages') ||      // WPML
            function_exists('pll_the_languages') ||      // Polylang
            function_exists('qtranxf_getLanguage')       // qTranslate-X
        );
    }
    
    /**
     * Get multilingual plugin name
     */
    public function get_multilingual_plugin() {
        if (function_exists('icl_get_languages')) {
            return 'WPML';
        } elseif (function_exists('pll_the_languages')) {
            return 'Polylang';
        } elseif (function_exists('qtranxf_getLanguage')) {
            return 'qTranslate-X';
        }
        
        return 'None';
    }
    
    /**
     * Validate language settings
     */
    public function validate_language_settings($settings) {
        $errors = array();
        
        if (isset($settings['multilingual_enabled']) && $settings['multilingual_enabled']) {
            if (!$this->is_multilingual_active()) {
                $errors[] = __('Flerspråklig støtte krever en flerspråklig plugin (WPML, Polylang, eller qTranslate-X)', 'bd-product-feed');
            }
            
            if (empty($settings['target_languages'])) {
                $errors[] = __('Velg minst ett målspråk for flerspråklige feeds', 'bd-product-feed');
            }
            
            foreach ($settings['target_languages'] as $language) {
                if (!isset($this->supported_languages[$language])) {
                    $errors[] = sprintf(__('Språk %s er ikke støttet', 'bd-product-feed'), $language);
                }
            }
        }
        
        return $errors;
    }
}