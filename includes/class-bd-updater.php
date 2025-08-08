<?php
/**
 * BD Product Feed Updater
 * Håndterer automatisk oppdatering via GitHub
 * Versjon 1.2 - Med kritiske rettelser
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('BD_Product_Feed_Updater')) {
class BD_Product_Feed_Updater {
    private $plugin_file;
    private $github_username;
    private $github_repo;
    private $version;
    private $plugin_slug;
    private $plugin_basename;

    public function __construct($plugin_file, $github_username, $github_repo) {
        $this->plugin_file = $plugin_file;
        $this->github_username = $github_username;
        $this->github_repo = $github_repo;
        $this->plugin_basename = plugin_basename($plugin_file);
        
        // KRITISK RETTELSE: Bruk repository name som slug
        $this->plugin_slug = $github_repo;
        
        // Get version from plugin header
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data($plugin_file);
        $this->version = $plugin_data['Version'];

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'download_package'], 10, 3);
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BD_Product_Feed_Updater initialized for {$this->plugin_basename} (slug: {$this->plugin_slug}, version: {$this->version})");
        }
    }

    /**
     * Check for plugin updates - OPPDATERT VERSJON
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // KRITISK: Sjekk at plugin er i checked list
        if (!isset($transient->checked[$this->plugin_basename])) {
            return $transient;
        }

        $remote_version = $this->get_remote_version();
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BD Update Check: Current version: {$this->version}, Remote version: {$remote_version}");
        }
        
        if (version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_basename] = (object) [
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => $remote_version,
                'url' => "https://github.com/{$this->github_username}/{$this->github_repo}",
                'package' => $this->get_download_url($remote_version),
                'tested' => '6.4',
                'requires_php' => '7.4',
                'compatibility' => new stdClass(),
                'id' => $this->plugin_basename, // KRITISK: ID felt
            ];
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BD Update Available: {$this->plugin_basename} can be updated from {$this->version} to {$remote_version}");
            }
        } else {
            // KRITISK: Fjern fra response hvis ingen oppdatering
            unset($transient->response[$this->plugin_basename]);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BD Update Check: No update needed for {$this->plugin_basename}");
            }
        }

        return $transient;
    }

    /**
     * Get remote version from GitHub - MED CACHING
     */
    private function get_remote_version() {
        // Cache i 12 timer
        $cache_key = 'bd_update_' . md5($this->plugin_basename);
        $cached_version = get_transient($cache_key);
        
        if ($cached_version !== false) {
            return $cached_version;
        }
        
        $request = wp_remote_get("https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest", [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'BD-Plugin-Updater/1.0'
            ]
        ]);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BD GitHub API Request: " . wp_remote_retrieve_response_code($request));
        }
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            if (isset($data['tag_name'])) {
                $version = ltrim($data['tag_name'], 'v');
                set_transient($cache_key, $version, 12 * HOUR_IN_SECONDS);
                return $version;
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $error_message = is_wp_error($request) ? $request->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($request);
                error_log("BD GitHub API Error: " . $error_message);
            }
        }

        return $this->version;
    }

    /**
     * Get download URL for specific version
     */
    private function get_download_url($version) {
        return "https://github.com/{$this->github_username}/{$this->github_repo}/releases/download/v{$version}/{$this->github_repo}.zip";
    }

    /**
     * Provide plugin information for update screen - FORBEDRET
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $request = wp_remote_get("https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest", [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'BD-Plugin-Updater/1.0'
            ]
        ]);
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            $result = (object) [
                'name' => $data['name'] ?? 'BD Product Feed',
                'slug' => $this->plugin_slug,
                'version' => ltrim($data['tag_name'] ?? $this->version, 'v'),
                'author' => '<a href="https://buenedata.no">Buene Data</a>',
                'author_profile' => 'https://buenedata.no',
                'homepage' => "https://github.com/{$this->github_username}/{$this->github_repo}",
                'short_description' => 'BD Product Feed fra Buene Data',
                'sections' => [
                    'description' => wpautop(wp_kses_post($data['body'] ?? 'Ingen beskrivelse tilgjengelig.')),
                    'changelog' => wpautop(wp_kses_post($data['body'] ?? 'Se GitHub for endringer.')),
                    'installation' => 'Last ned ZIP-filen og installer via WordPress Admin → Plugins → Legg til ny → Last opp plugin.',
                ],
                'download_link' => $this->get_download_url(ltrim($data['tag_name'] ?? $this->version, 'v')),
                'requires' => '5.0',
                'tested' => '6.4',
                'requires_php' => '7.4',
                'last_updated' => $data['published_at'] ?? date('Y-m-d H:i:s'),
                'active_installs' => false,
                'downloaded' => false,
            ];
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("BD Plugin Info: Returning info for {$this->plugin_slug}");
            }
        }

        return $result;
    }

    /**
     * Download package from GitHub
     */
    public function download_package($result, $package, $upgrader) {
        if (strpos($package, 'github.com') === false) {
            return $result;
        }

        return $result;
    }
}
}