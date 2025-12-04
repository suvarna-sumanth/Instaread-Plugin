<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Instaread auto-injecting player with partner configuration and full server-side rendering (no DOMDocument parsing, safer string injection)
 * Version: 4.1.0
 * Author: Instaread Team
 */

defined('ABSPATH') || exit;

$partner_config_file = __DIR__ . '/config.json';
$partner_css_file = __DIR__ . '/styles.css';
$partner_config = file_exists($partner_config_file)
    ? json_decode(file_get_contents($partner_config_file), true)
    : null;
$partner_css = file_exists($partner_css_file)
    ? file_get_contents($partner_css_file)
    : null;

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class InstareadPlayer {
    private static $instance;
    private static $debug = true;
    
    // Enable debug mode via constant or filter
    private function is_debug_enabled() {
        return (defined('WP_DEBUG') && WP_DEBUG) || 
               (defined('INSTAREAD_DEBUG') && INSTAREAD_DEBUG) ||
               apply_filters('instaread_debug', false);
    }

    private $settings;
    private $partner_config;
    private $plugin_version;

    public static function init() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $partner_config;
        $this->partner_config = $partner_config;
        $this->settings = $this->get_settings();
        $this->get_plugin_version();
        $this->init_update_checker();

        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Use multiple hooks to ensure injection works (some themes/plugins modify content)
        add_filter('the_content', [$this, 'inject_server_side_player'], 99, 1); // Higher priority
        add_filter('the_content', [$this, 'inject_server_side_player'], 999, 1); // Very high priority as backup
        
        // Also try wp_footer as fallback for themes that bypass the_content
        add_action('wp_footer', [$this, 'maybe_inject_via_footer'], 999);
        
        add_filter('auto_update_plugin', [$this, 'enable_auto_updates'], 10, 2);

        $this->maybe_migrate_old_settings();
        $this->log('Instaread Player initialized.');
        $this->log(['settings' => $this->settings, 'partner_config' => $this->partner_config, 'plugin_version' => $this->plugin_version]);
    }

    private function log($msg) {
        if (self::$debug || $this->is_debug_enabled()) {
            error_log('[InstareadPlayer] ' . print_r($msg, true));
        }
    }

    private function init_update_checker() {
        $update_url = $this->partner_config
            ? "https://raw.githubusercontent.com/suvarna-sumanth/Instaread-Plugin/main/partners/{$this->partner_config['partner_id']}/plugin.json"
            : 'https://suvarna-sumanth.github.io/Instaread-Plugin/plugin.json';
        PucFactory::buildUpdateChecker(
            $update_url,
            __FILE__,
            $this->partner_config ? 'instaread-' . $this->partner_config['partner_id'] : 'instaread-audio-player'
        );
        $this->log("Update checker initialized: $update_url");
    }

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $data = get_plugin_data(__FILE__);
        $this->plugin_version = $data['Version'] ?? '1.0.0';
        $this->log('Plugin version: ' . $this->plugin_version);
    }

    private function get_settings() {
        if ($this->partner_config) {
            $this->log('Loaded settings from partner config');
            return [
                'publication' => $this->partner_config['publication'] ?? 'default',
                'injection_rules' => $this->partner_config['injection_rules'] ?? [['target_selector'=>'.entry-content','insert_position'=>'append']],
                'injection_context' => $this->partner_config['injection_context'] ?? 'singular',
            ];
        }
        $wp = get_option('instaread_settings', []);
        $this->log('Loaded settings from WP options');
        return [
            'publication' => $wp['publication'] ?? 'default',
            'injection_rules' => [['target_selector' => $wp['target_selector'] ?? '.entry-content', 'insert_position' => $wp['insert_position'] ?? 'append']],
            'injection_context' => $wp['injection_context'] ?? 'singular'
        ];
    }

    public function enable_auto_updates($update, $item) {
        $plugin_basename = plugin_basename(__FILE__);
        $result = (isset($item->plugin) && $item->plugin === $plugin_basename) ? true : $update;
        $this->log('Auto-update checked for plugin: ' . $plugin_basename . ' Result: ' . ($result ? 'Enabled' : 'Disabled'));
        return $result;
    }

    public function register_settings() {
        register_setting('instaread_settings', 'instaread_settings');
        add_settings_section('instaread_main', 'Instaread Config', null, 'instaread-settings');
        add_settings_field('instaread_publication', 'Publication', [$this,'field_text'], 'instaread-settings', 'instaread_main', ['key'=>'publication']);
        $this->log('Registered WP admin settings');
    }

    public function field_text($args) {
        $key = $args['key'];
        $value = esc_attr($this->settings[$key] ?? '');
        echo "<input type='text' name='instaread_settings[$key]' value='$value'>";
        $this->log("Rendered field for key: $key, value: $value");
    }

    public function add_settings_page() {
        add_options_page('Instaread Settings','Instaread Player','manage_options','instaread-settings',[$this,'render_admin']);
        $this->log('Added WP admin settings page');
    }

    public function render_admin() {
        ?>
        <div class="wrap"><h1>Instaread Player Settings</h1>
        <form method="post" action="options.php"><?php
            settings_fields('instaread_settings');
            do_settings_sections('instaread-settings');
            submit_button();
        ?></form></div>
        <?php
        $this->log('Rendered admin page');
    }

    public function enqueue_assets() {
        global $partner_css, $partner_css_file;
        if ($partner_css && file_exists($partner_css_file)) {
            $local_css_handle = 'instaread-local-style';
            wp_register_style($local_css_handle, false);
            wp_enqueue_style($local_css_handle);
            wp_add_inline_style($local_css_handle, $partner_css);
            $this->log('Enqueued local styles.css');
        }
    }

   public function inject_server_side_player($content) {
    // Enable debug logging for troubleshooting
    $debug_mode = $this->is_debug_enabled();
    
    if ($debug_mode) {
        $this->log('=== INJECTION ATTEMPT START ===');
        $this->log('is_admin: ' . (is_admin() ? 'yes' : 'no'));
        $this->log('is_main_query: ' . (is_main_query() ? 'yes' : 'no'));
        $this->log('Content type: ' . gettype($content));
        $this->log('Content length: ' . (is_string($content) ? strlen($content) : 'N/A'));
        $this->log('Post ID: ' . (isset($GLOBALS['post']) ? $GLOBALS['post']->ID : 'N/A'));
    }
    
    // WordPress safety checks
    if (is_admin() || !is_main_query()) {
        if ($debug_mode) {
            $this->log('Skipping: admin or not main query');
        }
        return $content;
    }
    global $post;
    if (empty($post)) {
        if ($debug_mode) {
            $this->log('Skipping: no post object');
        }
        return $content;
    }

    // Ensure content is a string and not empty
    if (!is_string($content)) {
        if ($debug_mode) {
            $this->log('Skipping: content is not a string');
        }
        return $content;
    }
    
    if (trim($content) === '') {
        if ($debug_mode) {
            $this->log('Skipping: content is empty');
        }
        return $content;
    }

    // Prevent double injection - check if player already exists
    if (strpos($content, 'instaread-player-slot') !== false || strpos($content, 'instaread-player') !== false) {
        if ($debug_mode) {
            $this->log('Skipping: player already exists in content');
        }
        return $content;
    }
    
    if ($debug_mode) {
        $this->log('Passed initial checks, proceeding with injection');
    }

    // Flatten exclusion slugs from settings
    $exclude_slugs = [];
    foreach ($this->settings['injection_rules'] as $rule) {
        if (!empty($rule['exclude_slugs']) && is_array($rule['exclude_slugs'])) {
            $exclude_slugs = array_merge($exclude_slugs, $rule['exclude_slugs']);
        }
    }
    
    // Get current URL path - try multiple methods for reliability
    $current_slug = '';
    
    // Method 1: Use WordPress get_permalink() for singular posts (most reliable)
    // This works even when REQUEST_URI is wrong
    if (is_singular() && isset($GLOBALS['post']) && !empty($GLOBALS['post']->ID)) {
        $permalink = get_permalink($GLOBALS['post']->ID);
        if ($permalink) {
            $parsed = parse_url($permalink);
            $current_slug = isset($parsed['path']) ? $parsed['path'] : '';
            if ($debug_mode) {
                $this->log('Method 1 - get_permalink() result: ' . $permalink);
                $this->log('Method 1 - Extracted path: ' . $current_slug);
            }
        }
    }
    
    // Method 2: Use WordPress $wp->request if available
    if (empty($current_slug) || $current_slug === '/') {
        global $wp;
        if (isset($wp) && isset($wp->request) && !empty($wp->request)) {
            $current_slug = '/' . trim($wp->request, '/');
            if ($debug_mode) {
                $this->log('Method 2 - Got slug from $wp->request: ' . $current_slug);
            }
        }
    }
    
    // Method 3: Parse REQUEST_URI directly (fallback)
    if (empty($current_slug) || $current_slug === '/') {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($debug_mode) {
            $this->log('Method 3 - Raw REQUEST_URI: ' . $request_uri);
        }
        // Remove query string
        $request_uri = strtok($request_uri, '?');
        if (!empty($request_uri) && $request_uri !== '/') {
            $current_slug = $request_uri;
            if ($debug_mode) {
                $this->log('Method 3 - Got slug from REQUEST_URI: ' . $current_slug);
            }
        }
    }
    
    // Normalize the slug (remove trailing slash for comparison)
    $current_slug = trim($current_slug);
    if (empty($current_slug) || $current_slug === '/') {
        $current_slug = '/';
    } else {
        // Ensure it starts with /
        if (substr($current_slug, 0, 1) !== '/') {
            $current_slug = '/' . $current_slug;
        }
        // Remove trailing slash for comparison
        $current_slug = rtrim($current_slug, '/');
        if (empty($current_slug)) {
            $current_slug = '/';
        }
    }
    
    if ($debug_mode) {
        $this->log('Final normalized slug: ' . $current_slug);
    }
    
    if ($debug_mode) {
        $this->log('Current slug for exclusion: ' . $current_slug);
        $this->log('Exclude slugs list: ' . print_r($exclude_slugs, true));
    }
    
    // Normalize exclude slugs for comparison (remove trailing slashes)
    $normalized_exclude_slugs = array_map(function($slug) {
        $slug = trim($slug);
        $slug = rtrim($slug, '/');
        return empty($slug) ? '/' : $slug;
    }, $exclude_slugs);
    
    // Check if current slug matches any exclude slug
    if ($normalized_exclude_slugs && in_array($current_slug, $normalized_exclude_slugs, true)) {
        if ($debug_mode) {
            $this->log('Skipping injection for excluded slug: ' . $current_slug);
        }
        return $content;
    }
    
    if ($debug_mode) {
        $this->log('Slug not in exclude list, continuing with injection. Current: "' . $current_slug . '"');
    }

    // Injection context check
    $ctx = $this->settings['injection_context'];
    if ($ctx === 'singular' && !is_singular()) {
        $this->log('Skipping injection: not singular context');
        return $content;
    }

    $publication = $this->settings['publication'];
    if (!empty($this->partner_config['dynamic_publication_from_host'])) {
        $host_parts = explode('.', parse_url(home_url(), PHP_URL_HOST));
        $publication = reset($host_parts) ?: $publication;
    }

    $is_playlist = !empty($this->partner_config['isPlaylist']);
    $player_type = $this->partner_config['playerType'] ?? '';
    $color = $this->partner_config['color'] ?? '#59476b';
    $slot_css = $this->partner_config['slot_css'] ?? 'min-height:144px;';
    $playlist_height = $this->partner_config['playlist_height'] ?? '80vh';

    foreach ($this->settings['injection_rules'] as $rule) {
        $pos = $rule['insert_position'] ?? 'append';
        $target_selector = $rule['target_selector'] ?? null;
        
        $this->log("Processing injection rule: position={$pos}, selector={$target_selector}");

        // Prepare player HTML markup
        $player_html = $is_playlist
            ? $this->render_playlist($publication, $playlist_height)
            : $this->render_single($publication, $player_type, $color, $slot_css);

        // Use safe string-based injection that respects target_selector
        // Avoids DOMDocument to prevent conflicts with WordPress content filters
        $content = $this->inject_with_safe_string_manipulation($content, $player_html, $target_selector, $pos);
        
        if ($debug_mode) {
            $this->log("After injection, content length: " . strlen($content));
            $this->log("Player HTML injected: " . (strpos($content, 'instaread-player') !== false ? 'YES' : 'NO'));
            $this->log("Player HTML preview: " . substr($player_html, 0, 100));
        }
    }

    if ($debug_mode) {
        $this->log('=== INJECTION ATTEMPT END ===');
        $this->log('Final content contains player: ' . (strpos($content, 'instaread-player') !== false ? 'YES' : 'NO'));
    }

    return $content;
}

    /**
     * Fallback injection method via footer (for themes that bypass the_content)
     */
    public function maybe_inject_via_footer() {
        // Only use this if the_content didn't work (check if player exists)
        if (!is_singular() || is_admin()) {
            return;
        }
        
        // Check if player was already injected
        global $post;
        if (empty($post)) {
            return;
        }
        
        // This is a last resort - we'll inject via JavaScript if needed
        // But first, let's check if the_content filter worked
        $content = get_the_content();
        if (strpos($content, 'instaread-player') === false) {
            $this->log('Player not found in content, footer fallback could be used if needed');
        }
    }

    /**
     * Safely inject player HTML using string manipulation (WordPress-compatible)
     * Respects target_selector and preserves HTML structure without DOMDocument
     */
    private function inject_with_safe_string_manipulation($content, $player_html, $target_selector, $insert_position) {
        // Validate inputs
        if (!is_string($content) || !is_string($player_html)) {
            $this->log('Invalid content or player HTML, skipping injection.');
            return $content;
        }

        // Ensure content is not empty
        if (trim($content) === '') {
            return $content . $player_html;
        }

        // If no target selector, use simple prepend/append
        if (empty($target_selector)) {
            if ($insert_position === 'prepend') {
                return $player_html . $content;
            }
            return $content . $player_html;
        }

        // Find target element using safe string matching
        $target_info = $this->find_target_element($content, $target_selector);
        
        if (!$target_info) {
            $this->log("Target selector '{$target_selector}' not found, appending to content.");
            return $content . $player_html;
        }
        
        // Validate target info before injection
        if (!isset($target_info['open_pos']) || $target_info['open_pos'] < 0 || $target_info['open_pos'] >= strlen($content)) {
            $this->log('Invalid target position, appending to content.');
            return $content . $player_html;
        }
        
        // Inject based on position relative to target element
        try {
            return $this->inject_at_position($content, $player_html, $target_info, $insert_position);
        } catch (Exception $e) {
            $this->log('Error during injection: ' . $e->getMessage() . ' - Appending to content.');
            return $content . $player_html;
        }
    }

    /**
     * Find target element in content using safe string matching
     * Returns array with element info or false if not found
     * Supports: .class, #id, tag, .parent > child, .parent > child:pseudo
     */
    private function find_target_element($content, $selector) {
        // Validate inputs
        if (!is_string($content) || !is_string($selector) || trim($content) === '' || trim($selector) === '') {
            return false;
        }

        $selector = trim($selector);
        
        // Handle child combinator: .parent > child or .parent > child:pseudo
        if (preg_match('/^([^\s>]+)\s*>\s*([a-zA-Z0-9_-]+)(:.*)?$/', $selector, $child_matches)) {
            $parent_selector = trim($child_matches[1]);
            $child_tag = $child_matches[2];
            $pseudo = isset($child_matches[3]) ? $child_matches[3] : '';
            
            // Find parent element first
            $parent_info = $this->find_target_element($content, $parent_selector);
            if (!$parent_info) {
                return false;
            }
            
            // Look for first child of specified tag within parent
            $parent_after_open = $parent_info['after_open'];
            $parent_close_pos = $parent_info['close_pos'] ?? strlen($content);
            
            // Search for first child tag within parent
            $parent_content = substr($content, $parent_after_open, ($parent_close_pos - $parent_after_open));
            
            // Find first occurrence of child tag (for :first-of-type)
            if (preg_match('/<' . preg_quote($child_tag, '/') . '\b[^>]*>/i', $parent_content, $child_match, PREG_OFFSET_CAPTURE)) {
                $child_open_pos = $parent_after_open + $child_match[0][1];
                $child_open_tag = $child_match[0][0];
                $child_tag_name = strtolower($child_tag);
                
                // Find matching closing tag for child
                $child_after_open = $child_open_pos + strlen($child_open_tag);
                $remaining = substr($content, $child_after_open);
                
                $depth = 1;
                $search_pos = 0;
                $child_close_pos = false;
                $max_iterations = 1000; // Safety limit to prevent infinite loops
                $iteration = 0;
                
                while ($depth > 0 && $iteration < $max_iterations) {
                    $iteration++;
                    if (!preg_match('/<\/?' . preg_quote($child_tag_name, '/') . '(\s[^>]*)?>/i', $remaining, $tag_match, PREG_OFFSET_CAPTURE, $search_pos)) {
                        break; // No more matches
                    }
                    
                    $match_str = $tag_match[0][0];
                    $match_pos = $tag_match[0][1];
                    
                    // Skip self-closing tags (they don't affect depth)
                    if (preg_match('/\/\s*>$/', $match_str)) {
                        $search_pos = $match_pos + strlen($match_str);
                        continue;
                    }
                    
                    if (strpos($match_str, '</') === 0) {
                        $depth--;
                        if ($depth === 0) {
                            $child_close_pos = $child_after_open + $match_pos + strlen($match_str);
                            break;
                        }
                    } else {
                        $depth++;
                    }
                    $search_pos = $match_pos + strlen($match_str);
                }
                
                if ($iteration >= $max_iterations) {
                    $this->log("Warning: Max iterations reached while finding closing tag for child '{$child_tag_name}'");
                }
                
                return [
                    'tag_name' => $child_tag_name,
                    'open_pos' => $child_open_pos,
                    'open_tag' => $child_open_tag,
                    'open_tag_len' => strlen($child_open_tag),
                    'close_pos' => $child_close_pos !== false ? $child_close_pos : null,
                    'after_open' => $child_after_open
                ];
            }
            
            return false;
        }
        
        // Simple selector: class, ID, or tag
        $is_class = preg_match('/^\.([a-zA-Z0-9_-]+)/', $selector, $class_matches);
        $is_id = preg_match('/^#([a-zA-Z0-9_-]+)/', $selector, $id_matches);
        $is_tag = preg_match('/^([a-zA-Z0-9_-]+)$/', $selector, $tag_matches);
        
        $pattern = null;
        $tag_name = null;
        
        if ($is_class) {
            $class_name = $class_matches[1];
            // Match opening tag with class attribute (handles various quote styles and spacing)
            $pattern = '/<([a-zA-Z][a-zA-Z0-9]*)[^>]*\s+class\s*=\s*["\']([^"\']*\b' . preg_quote($class_name, '/') . '\b[^"\']*)["\'][^>]*>/i';
        } elseif ($is_id) {
            $id_name = $id_matches[1];
            // Match opening tag with id attribute
            $pattern = '/<([a-zA-Z][a-zA-Z0-9]*)[^>]*\s+id\s*=\s*["\']' . preg_quote($id_name, '/') . '["\'][^>]*>/i';
        } elseif ($is_tag) {
            $tag_name = $tag_matches[1];
            $pattern = '/<' . preg_quote($tag_name, '/') . '\b[^>]*>/i';
        } else {
            // Complex selector - try to extract tag name
            if (preg_match('/([a-zA-Z][a-zA-Z0-9]*)\s*$/', $selector, $tag_extract)) {
                $tag_name = $tag_extract[1];
                $pattern = '/<' . preg_quote($tag_name, '/') . '\b[^>]*>/i';
            }
        }
        
        if (!$pattern || !preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        
        $open_pos = $matches[0][1];
        $open_tag = $matches[0][0];
        $tag_name = $tag_name ?: strtolower($matches[1][0]);
        
        // Find matching closing tag
        $after_open = $open_pos + strlen($open_tag);
        $remaining = substr($content, $after_open);
        
        // Count depth to find matching closing tag
        // This handles nested elements of the same type correctly
        $depth = 1;
        $search_pos = 0;
        $close_pos = false;
        $max_iterations = 1000; // Safety limit to prevent infinite loops
        $iteration = 0;
        
        while ($depth > 0 && $iteration < $max_iterations) {
            $iteration++;
            if (!preg_match('/<\/?' . preg_quote($tag_name, '/') . '(\s[^>]*)?>/i', $remaining, $tag_match, PREG_OFFSET_CAPTURE, $search_pos)) {
                break; // No more matches
            }
            
            $match_str = $tag_match[0][0];
            $match_pos = $tag_match[0][1];
            
            // Skip self-closing tags (they don't affect depth)
            if (preg_match('/\/\s*>$/', $match_str)) {
                $search_pos = $match_pos + strlen($match_str);
                continue;
            }
            
            if (strpos($match_str, '</') === 0) {
                // Closing tag
                $depth--;
                if ($depth === 0) {
                    $close_pos = $after_open + $match_pos + strlen($match_str);
                    break;
                }
            } else {
                // Opening tag (nested)
                $depth++;
            }
            $search_pos = $match_pos + strlen($match_str);
        }
        
        if ($iteration >= $max_iterations) {
            $this->log("Warning: Max iterations reached while finding closing tag for '{$tag_name}'");
        }
        
        return [
            'tag_name' => $tag_name,
            'open_pos' => $open_pos,
            'open_tag' => $open_tag,
            'open_tag_len' => strlen($open_tag),
            'close_pos' => $close_pos !== false ? $close_pos : null,
            'after_open' => $after_open
        ];
    }

    /**
     * Inject player HTML at specified position relative to target element
     * Preserves HTML structure and ensures safe string manipulation
     */
    private function inject_at_position($content, $player_html, $target_info, $insert_position) {
        // Validate target info
        if (!is_array($target_info) || !isset($target_info['open_pos'])) {
            $this->log('Invalid target info, appending to content.');
            return $content . $player_html;
        }

        $tag_name = $target_info['tag_name'] ?? '';
        $open_pos = (int) $target_info['open_pos'];
        $open_tag_len = (int) ($target_info['open_tag_len'] ?? 0);
        $close_pos = isset($target_info['close_pos']) ? (int) $target_info['close_pos'] : null;
        $after_open = (int) ($target_info['after_open'] ?? $open_pos + $open_tag_len);
        
        // Validate positions are within content bounds
        $content_len = strlen($content);
        if ($open_pos < 0 || $open_pos >= $content_len) {
            $this->log('Invalid open position, appending to content.');
            return $content . $player_html;
        }
        
        if ($close_pos !== null && ($close_pos < 0 || $close_pos > $content_len)) {
            $this->log('Invalid close position, using after_open position.');
            $close_pos = null;
        }
        
        if ($after_open < 0 || $after_open > $content_len) {
            $this->log('Invalid after_open position, appending to content.');
            return $content . $player_html;
        }
        
        // Ensure positions are in correct order
        if ($close_pos !== null && $close_pos <= $after_open) {
            $this->log('Close position before after_open, adjusting.');
            $close_pos = null;
        }
        
        switch ($insert_position) {
            case 'before_element':
                // Insert before target element opening tag - preserves all HTML structure
                $content = substr_replace($content, $player_html, $open_pos, 0);
                $this->log("Injected player before target element (preserving HTML structure).");
                break;
                
            case 'after_element':
                // Insert after target element closing tag (or after opening if no closing)
                // This ensures the target element remains intact
                if ($close_pos !== null && $close_pos <= $content_len) {
                    $content = substr_replace($content, $player_html, $close_pos, 0);
                    $this->log("Injected player after target element closing tag (preserving HTML structure).");
                } else {
                    // Self-closing or no closing tag found - insert after opening tag
                    $content = substr_replace($content, $player_html, $after_open, 0);
                    $this->log("Injected player after target element opening tag (no closing tag found, preserving structure).");
                }
                break;
                
            case 'inside_first_child':
            case 'prepend':
                // Insert as first child - right after opening tag
                // This preserves the target element's structure and all its children
                $content = substr_replace($content, $player_html, $after_open, 0);
                $this->log("Injected player as first child inside target element (preserving HTML structure).");
                break;
                
            case 'inside_last_child':
            case 'inside_element':
            case 'append':
                // Insert as last child - before closing tag (or after opening if no closing)
                // This ensures all existing children remain intact
                if ($close_pos !== null && $close_pos <= $content_len) {
                    $content = substr_replace($content, $player_html, $close_pos, 0);
                    $this->log("Injected player as last child inside target element (preserving HTML structure).");
                } else {
                    // No closing tag - insert after opening tag
                    $content = substr_replace($content, $player_html, $after_open, 0);
                    $this->log("Injected player after opening tag (no closing tag found, preserving structure).");
                }
                break;
                
            default:
                // Fallback: append to content (safest option)
                $content .= $player_html;
                $this->log("Injected player using default (append) to preserve content.");
                break;
        }
        
        return $content;
    }


 private function render_single($publication, $type, $color, $slot_css) { 
    $ir_version = floor(time() / 60) * 60000;
    $this->log("Rendering single player: publication=$publication, type=$type");

    return sprintf(
        '<div class="instaread-player-slot" style="%s">
            <instaread-player publication="%s" playertype="%s" color="%s"></instaread-player>
            <script type="module" src="https://instaread.co/js/instaread.%s.js?version=%d"></script>
        </div>',
        esc_attr($slot_css),
        esc_html($publication),
        esc_html($type),
        esc_html($color),
        esc_html($publication), // use esc_html for attributes, not esc_js
        $ir_version
    );
}

    private function render_playlist($publication, $height) {
        $this->log("Rendering playlist player: publication=$publication, height=$height");
        return sprintf(
            '<div class="instaread-player-slot" style="height:%s;min-height:%s;">
                <instaread-player publication="%s" p_type="playlist" height="%s"></instaread-player>
                <script type="module" src="https://instaread.co/js/v2/instaread.playlist.js" crossorigin="true"></script>
            </div>',
            esc_attr($height), esc_attr($height), esc_html($publication), esc_attr($height)
        );
    }

    public function sanitize_settings($in) {
        return ['publication' => sanitize_text_field($in['publication'] ?? 'default')];
    }

    private function maybe_migrate_old_settings() {
        $old = get_option('instaread_legacy_settings');
        if ($old) {
            update_option('instaread_settings', ['publication' => $old['publication'] ?? '']);
            delete_option('instaread_legacy_settings');
            $this->log('Migrated old settings to new option.');
        }
    }
}

InstareadPlayer::init();
