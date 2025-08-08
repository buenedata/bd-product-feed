<?php
/**
 * BD Currency Converter Class
 * Handles automatic currency conversion with exchange rate APIs
 */

if (!defined('ABSPATH')) {
    exit;
}

class BD_Currency_Converter {
    
    /**
     * Base currency (WooCommerce default)
     */
    private $base_currency;
    
    /**
     * Cache duration for exchange rates (24 hours)
     */
    private $cache_duration = 24 * HOUR_IN_SECONDS;
    
    /**
     * Exchange rate API settings
     */
    private $api_settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->base_currency = get_woocommerce_currency();
        $this->load_api_settings();
    }
    
    /**
     * Load API settings
     */
    private function load_api_settings() {
        $this->api_settings = array(
            'provider' => get_option('bd_product_feed_exchange_api_provider', 'exchangerate-api'),
            'api_key' => get_option('bd_product_feed_exchange_api_key', ''),
            'fallback_rates' => get_option('bd_product_feed_fallback_rates', array()),
        );
    }
    
    /**
     * Convert amount from one currency to another
     */
    public function convert($amount, $from_currency, $to_currency) {
        // If same currency, return original amount
        if ($from_currency === $to_currency) {
            return $amount;
        }
        
        // Get exchange rate
        $rate = $this->get_exchange_rate($from_currency, $to_currency);
        
        if ($rate === false) {
            // Log error and return original amount
            error_log("BD Product Feed: Failed to get exchange rate from {$from_currency} to {$to_currency}");
            return $amount;
        }
        
        return $amount * $rate;
    }
    
    /**
     * Get exchange rate between two currencies
     */
    public function get_exchange_rate($from_currency, $to_currency) {
        // Check cache first
        $cache_key = "bd_exchange_rate_{$from_currency}_{$to_currency}";
        $cached_rate = get_transient($cache_key);
        
        if ($cached_rate !== false) {
            return $cached_rate;
        }
        
        // Try to fetch from API
        $rate = $this->fetch_exchange_rate_from_api($from_currency, $to_currency);
        
        if ($rate !== false) {
            // Cache the rate
            set_transient($cache_key, $rate, $this->cache_duration);
            return $rate;
        }
        
        // Try fallback rates
        $fallback_rate = $this->get_fallback_rate($from_currency, $to_currency);
        if ($fallback_rate !== false) {
            return $fallback_rate;
        }
        
        return false;
    }
    
    /**
     * Fetch exchange rate from API
     */
    private function fetch_exchange_rate_from_api($from_currency, $to_currency) {
        $provider = $this->api_settings['provider'];
        
        switch ($provider) {
            case 'exchangerate-api':
                return $this->fetch_from_exchangerate_api($from_currency, $to_currency);
            case 'fixer':
                return $this->fetch_from_fixer_api($from_currency, $to_currency);
            case 'currencylayer':
                return $this->fetch_from_currencylayer_api($from_currency, $to_currency);
            default:
                return $this->fetch_from_exchangerate_api($from_currency, $to_currency);
        }
    }
    
    /**
     * Fetch from ExchangeRate-API (free tier available)
     */
    private function fetch_from_exchangerate_api($from_currency, $to_currency) {
        $api_key = $this->api_settings['api_key'];
        
        if (empty($api_key)) {
            // Use free tier without API key
            $url = "https://api.exchangerate-api.com/v4/latest/{$from_currency}";
        } else {
            // Use paid tier with API key
            $url = "https://v6.exchangerate-api.com/v6/{$api_key}/latest/{$from_currency}";
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'BD-Product-Feed/1.0'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('BD Product Feed: ExchangeRate-API error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('BD Product Feed: ExchangeRate-API HTTP error: ' . $response_code);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['rates'][$to_currency])) {
            error_log('BD Product Feed: ExchangeRate-API invalid response');
            return false;
        }
        
        return (float) $data['rates'][$to_currency];
    }
    
    /**
     * Fetch from Fixer.io API
     */
    private function fetch_from_fixer_api($from_currency, $to_currency) {
        $api_key = $this->api_settings['api_key'];
        
        if (empty($api_key)) {
            return false;
        }
        
        $url = "http://data.fixer.io/api/latest?access_key={$api_key}&base={$from_currency}&symbols={$to_currency}";
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'BD-Product-Feed/1.0'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('BD Product Feed: Fixer.io error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('BD Product Feed: Fixer.io HTTP error: ' . $response_code);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !$data['success'] || !isset($data['rates'][$to_currency])) {
            error_log('BD Product Feed: Fixer.io invalid response');
            return false;
        }
        
        return (float) $data['rates'][$to_currency];
    }
    
    /**
     * Fetch from CurrencyLayer API
     */
    private function fetch_from_currencylayer_api($from_currency, $to_currency) {
        $api_key = $this->api_settings['api_key'];
        
        if (empty($api_key)) {
            return false;
        }
        
        $url = "http://api.currencylayer.com/live?access_key={$api_key}&source={$from_currency}&currencies={$to_currency}";
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'BD-Product-Feed/1.0'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('BD Product Feed: CurrencyLayer error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('BD Product Feed: CurrencyLayer HTTP error: ' . $response_code);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $quote_key = $from_currency . $to_currency;
        if (!$data || !$data['success'] || !isset($data['quotes'][$quote_key])) {
            error_log('BD Product Feed: CurrencyLayer invalid response');
            return false;
        }
        
        return (float) $data['quotes'][$quote_key];
    }
    
    /**
     * Get fallback exchange rate
     */
    private function get_fallback_rate($from_currency, $to_currency) {
        $fallback_rates = $this->api_settings['fallback_rates'];
        
        // Check direct rate
        $rate_key = $from_currency . '_' . $to_currency;
        if (isset($fallback_rates[$rate_key])) {
            return (float) $fallback_rates[$rate_key];
        }
        
        // Check inverse rate
        $inverse_key = $to_currency . '_' . $from_currency;
        if (isset($fallback_rates[$inverse_key])) {
            return 1 / (float) $fallback_rates[$inverse_key];
        }
        
        // Try to calculate through base currency (usually USD or EUR)
        $base_currencies = array('USD', 'EUR');
        
        foreach ($base_currencies as $base) {
            if ($base === $from_currency || $base === $to_currency) {
                continue;
            }
            
            $from_to_base_key = $from_currency . '_' . $base;
            $base_to_to_key = $base . '_' . $to_currency;
            
            if (isset($fallback_rates[$from_to_base_key]) && isset($fallback_rates[$base_to_to_key])) {
                return (float) $fallback_rates[$from_to_base_key] * (float) $fallback_rates[$base_to_to_key];
            }
        }
        
        return false;
    }
    
    /**
     * Update fallback rates
     */
    public function update_fallback_rates($rates) {
        update_option('bd_product_feed_fallback_rates', $rates);
        $this->api_settings['fallback_rates'] = $rates;
    }
    
    /**
     * Get supported currencies
     */
    public function get_supported_currencies() {
        return array(
            'NOK' => __('Norske kroner', 'bd-product-feed'),
            'EUR' => __('Euro', 'bd-product-feed'),
            'USD' => __('US Dollar', 'bd-product-feed'),
            'SEK' => __('Svenske kroner', 'bd-product-feed'),
            'DKK' => __('Danske kroner', 'bd-product-feed'),
            'GBP' => __('Britiske pund', 'bd-product-feed'),
            'CHF' => __('Sveitsiske franc', 'bd-product-feed'),
            'JPY' => __('Japanske yen', 'bd-product-feed'),
            'CAD' => __('Kanadiske dollar', 'bd-product-feed'),
            'AUD' => __('Australske dollar', 'bd-product-feed'),
        );
    }
    
    /**
     * Test API connection
     */
    public function test_api_connection() {
        $test_rate = $this->fetch_exchange_rate_from_api('USD', 'EUR');
        
        if ($test_rate !== false) {
            return array(
                'success' => true,
                'message' => __('API-tilkobling vellykket', 'bd-product-feed'),
                'test_rate' => $test_rate
            );
        } else {
            return array(
                'success' => false,
                'message' => __('API-tilkobling feilet', 'bd-product-feed')
            );
        }
    }
    
    /**
     * Get current exchange rates for display
     */
    public function get_current_rates($target_currencies = null) {
        if ($target_currencies === null) {
            $target_currencies = get_option('bd_product_feed_target_currencies', array('EUR', 'USD'));
        }
        
        $rates = array();
        $base = $this->base_currency;
        
        foreach ($target_currencies as $currency) {
            if ($currency === $base) {
                $rates[$currency] = 1.0;
            } else {
                $rate = $this->get_exchange_rate($base, $currency);
                $rates[$currency] = $rate !== false ? $rate : __('Ikke tilgjengelig', 'bd-product-feed');
            }
        }
        
        return $rates;
    }
    
    /**
     * Clear exchange rate cache
     */
    public function clear_cache() {
        global $wpdb;
        
        // Delete all exchange rate transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bd_exchange_rate_%' 
             OR option_name LIKE '_transient_timeout_bd_exchange_rate_%'"
        );
        
        return true;
    }
    
    /**
     * Get cache status
     */
    public function get_cache_status() {
        global $wpdb;
        
        $cached_rates = $wpdb->get_results(
            "SELECT option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bd_exchange_rate_%'
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
        
        $status = array();
        
        foreach ($cached_rates as $rate) {
            $rate_key = str_replace('_transient_bd_exchange_rate_', '', $rate->option_name);
            $timeout_key = '_transient_timeout_bd_exchange_rate_' . $rate_key;
            
            $timeout = get_option($timeout_key);
            $expires_in = $timeout ? $timeout - time() : 0;
            
            $status[$rate_key] = array(
                'rate' => $rate->option_value,
                'expires_in' => $expires_in,
                'expires_at' => $timeout ? date('Y-m-d H:i:s', $timeout) : 'Never'
            );
        }
        
        return $status;
    }
    
    /**
     * Format price with currency
     */
    public function format_price($amount, $currency) {
        $currency_symbols = array(
            'NOK' => 'kr',
            'EUR' => '€',
            'USD' => '$',
            'SEK' => 'kr',
            'DKK' => 'kr',
            'GBP' => '£',
            'CHF' => 'CHF',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
        );
        
        $symbol = isset($currency_symbols[$currency]) ? $currency_symbols[$currency] : $currency;
        $formatted_amount = number_format($amount, 2, '.', '');
        
        // Different formatting for different currencies
        switch ($currency) {
            case 'NOK':
            case 'SEK':
            case 'DKK':
                return $formatted_amount . ' ' . $symbol;
            case 'EUR':
            case 'GBP':
                return $symbol . $formatted_amount;
            case 'USD':
            case 'CAD':
            case 'AUD':
                return $symbol . $formatted_amount;
            case 'JPY':
                return $symbol . number_format($amount, 0);
            default:
                return $formatted_amount . ' ' . $currency;
        }
    }
}