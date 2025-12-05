<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Instaread auto-injecting player with partner configuration and full server-side rendering (no DOMDocument parsing, safer string injection)
 * Version: 4.2.0
 * Author: Instaread Team
 */

defined('ABSPATH') || exit;

$partner_config_file = __DIR__ . '/config.json';
$partner_css_file    = __DIR__ . '/styles.css';
$partner_config      = file_exists($partner_config_file)
    ? json_decode(file_get_contents($partner_config_file), true)
    : null;
$partner_css         = file_exists($partner_css_file)
    ? file_get_contents($partner_css_file)
    : null;

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class InstareadPlayer {
    private static $instance;
    private static $debug = true; // set false in production if you want

    private $settings;
    private $partner_config;
    private $plugin_version;

    // Enable debug mode via constant or filter
    private function is_debug_enabled() {
        return (defined('WP_DEBUG') && WP_DEBUG)
            || (defined('INSTAREAD_DEBUG') && INSTAREAD_DEBUG)
            || apply_filters('instaread_debug', false);
    }

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $partner_config;
        $this->partner_config = $partner_config;
        $this->settings       = $this->get_settings();
        $this->get_plugin_version();
        $this->init_update_checker();

        add_action('admin_init',       [$this, 'register_settings']);
        add_action('admin_menu',       [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Single the_content hook so injection only runs once per render
        add_filter('the_content',      [$this, 'inject_server_side_player'], 99, 1);

        // Footer fallback only logs; does not inject to avoid duplicates
        add_action('wp_footer',        [$this, 'maybe_inject_via_footer'], 999);

        add_filter('auto_update_plugin', [$this, 'enable_auto_updates'], 10, 2);

        $this->maybe_migrate_old_settings();
        $this->log('Instaread Player initialized.');
        $this->log([
            'settings'       => $this->settings,
            'partner_config' => $this->partner_config,
            'plugin_version' => $this->plugin_version,
        ]);
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
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data              = get_plugin_data(__FILE__);
        $this->plugin_version = $data['Version'] ?? '1.0.0';
        $this->log('Plugin version: ' . $this->plugin_version);
    }

    private function get_settings() {
        if ($this->partner_config) {
            $this->log('Loaded settings from partner config');
            return [
                'publication'      => $this->partner_config['publication'] ?? 'default',
                'injection_rules'  => $this->partner_config['injection_rules'] ?? [
                    ['target_selector' => '.entry-content', 'insert_position' => 'append'],
                ],
                'injection_context' => $this->partner_config['injection_context'] ?? 'singular',
            ];
        }

        $wp = get_option('instaread_settings', []);
        $this->log('Loaded settings from WP options');

        return [
            'publication'      => $wp['publication'] ?? 'default',
            'injection_rules'  => [[
                'target_selector' => $wp['target_selector'] ?? '.entry-content',
                'insert_position' => $wp['insert_position'] ?? 'append',
            ]],
            'injection_context' => $wp['injection_context'] ?? 'singular',
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
        $key   = $args['key'];
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
            <form method="post" action="options.php">
                <?php
                settings_fields('instaread_settings');
                do_settings_sections('instaread-settings');
                submit_button();
                ?>
            </form>
        </div>
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
        $debug_mode = $this->is_debug_enabled();

        if (is_front_page() || is_home()) {
            if ($debug_mode) $this->log('Skipping injection: homepage');
            return $content;
        }

        if (is_admin() || !is_main_query()) {
            return $content;
        }

        global $post;
        if (empty($post)) {
            return $content;
        }

        if (!is_string($content) || trim($content) === '') {
            return $content;
        }

        // Prevent double injection within this render
        if (strpos($content, 'instaread-player-slot') !== false || strpos($content, 'instaread-player') !== false) {
            if ($debug_mode) $this->log('Skipping: player already present in content');
            return $content;
        }

        // Collect exclude slugs
        $exclude_slugs = [];
        foreach ($this->settings['injection_rules'] as $rule) {
            if (!empty($rule['exclude_slugs']) && is_array($rule['exclude_slugs'])) {
                $exclude_slugs = array_merge($exclude_slugs, $rule['exclude_slugs']);
            }
        }

        // Current slug
        $current_slug = '';
        if (is_singular() && !empty($post->ID)) {
            $permalink = get_permalink($post->ID);
            if ($permalink) {
                $parsed = parse_url($permalink);
                $current_slug = $parsed['path'] ?? '';
            }
        }
        if (empty($current_slug) || $current_slug === '/') {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';
            if (!empty($request_uri) && $request_uri !== '/') {
                $current_slug = $request_uri;
            }
        }
        $current_slug = trim($current_slug);
        if ($current_slug === '' || $current_slug === '/') {
            $current_slug = '/';
        } else {
            if ($current_slug[0] !== '/') $current_slug = '/' . $current_slug;
            $current_slug = rtrim($current_slug, '/');
            if ($current_slug === '') $current_slug = '/';
        }

        $normalized_exclude_slugs = array_map(function($slug) {
            $slug = trim($slug);
            $slug = rtrim($slug, '/');
            return $slug === '' ? '/' : $slug;
        }, $exclude_slugs);

        if ($normalized_exclude_slugs && in_array($current_slug, $normalized_exclude_slugs, true)) {
            if ($debug_mode) $this->log('Skipping excluded slug: ' . $current_slug);
            return $content;
        }

        // Context
        $ctx = $this->settings['injection_context'];
        if ($ctx === 'singular' && !is_singular()) {
            if ($debug_mode) $this->log('Skipping: not singular');
            return $content;
        }

        $publication = $this->settings['publication'];
        if (!empty($this->partner_config['dynamic_publication_from_host'])) {
            $host_parts  = explode('.', parse_url(home_url(), PHP_URL_HOST));
            $publication = reset($host_parts) ?: $publication;
        }

        $is_playlist     = !empty($this->partner_config['isPlaylist']);
        $player_type     = $this->partner_config['playerType'] ?? '';
        $color           = $this->partner_config['color'] ?? '#59476b';
        $slot_css        = $this->partner_config['slot_css'] ?? 'min-height:144px;';
        $playlist_height = $this->partner_config['playlist_height'] ?? '80vh';

        foreach ($this->settings['injection_rules'] as $rule) {
            $pos             = $rule['insert_position'] ?? 'append';
            $target_selector = $rule['target_selector'] ?? null;
            if ($debug_mode) $this->log("Processing rule: pos={$pos}, selector={$target_selector}");

            $player_html = $is_playlist
                ? $this->render_playlist($publication, $playlist_height)
                : $this->render_single($publication, $player_type, $color, $slot_css);

            $content = $this->inject_with_safe_string_manipulation($content, $player_html, $target_selector, $pos);
        }

        return $content;
    }

    public function maybe_inject_via_footer() {
        if (is_front_page() || is_home() || !is_singular() || is_admin()) {
            return;
        }
        global $post;
        if (empty($post)) return;

        $content = get_the_content();
        if (strpos($content, 'instaread-player') === false) {
            $this->log('Footer fallback: player not found in content (no injection performed to avoid duplicates).');
        }
    }

    private function inject_with_safe_string_manipulation($content, $player_html, $target_selector, $insert_position) {
        $debug_mode = $this->is_debug_enabled();

        if (!is_string($content) || !is_string($player_html) || trim($content) === '') {
            return $content;
        }

        if (empty($target_selector)) {
            if (in_array($insert_position, ['prepend', 'inside_first_child', 'before_element'], true)) {
                return $player_html . $content;
            }
            return $content . $player_html;
        }

        $target_info = $this->find_target_element($content, $target_selector);

        if (!$target_info) {
            if ($debug_mode) $this->log("Target selector '{$target_selector}' not found, using safe fallback.");
            if (in_array($insert_position, ['inside_first_child', 'prepend', 'before_element'], true)) {
                return $player_html . $content;
            }
            return $content . $player_html;
        }

        if (!isset($target_info['open_pos']) || $target_info['open_pos'] < 0 || $target_info['open_pos'] >= strlen($content)) {
            return $content . $player_html;
        }

        try {
            return $this->inject_at_position($content, $player_html, $target_info, $insert_position);
        } catch (\Exception $e) {
            if ($debug_mode) $this->log('Error during injection: ' . $e->getMessage());
            return $content . $player_html;
        }
    }

    private function find_target_element($content, $selector) {
        if (!is_string($content) || !is_string($selector) || trim($content) === '' || trim($selector) === '') {
            return false;
        }

        $selector = trim($selector);

        // Child combinator: .parent > childTag
        if (preg_match('/^([^\s>]+)\s*>\s*([a-zA-Z0-9_-]+)(:.*)?$/', $selector, $child_matches)) {
            $parent_selector = trim($child_matches[1]);
            $child_tag       = $child_matches[2];

            $parent_info = $this->find_target_element($content, $parent_selector);
            if (!$parent_info) return false;

            $parent_after_open = $parent_info['after_open'];
            $parent_close_pos  = $parent_info['close_pos'] ?? strlen($content);
            $parent_content    = substr($content, $parent_after_open, $parent_close_pos - $parent_after_open);

            if (preg_match('/<' . preg_quote($child_tag, '/') . '\b[^>]*>/i', $parent_content, $child_match, PREG_OFFSET_CAPTURE)) {
                $child_open_pos  = $parent_after_open + $child_match[0][1];
                $child_open_tag  = $child_match[0][0];
                $child_tag_name  = strtolower($child_tag);
                $child_after_open = $child_open_pos + strlen($child_open_tag);

                // Simple closing search for child
                $remaining      = substr($content, $child_after_open);
                $depth          = 1;
                $search_pos     = 0;
                $child_close_pos = false;

                while ($depth > 0 && $search_pos < strlen($remaining)) {
                    if (!preg_match('/<\/?' . preg_quote($child_tag_name, '/') . '(\s[^>]*)?>/i', $remaining, $tag_match, PREG_OFFSET_CAPTURE, $search_pos)) break;
                    $match_str = $tag_match[0][0];
                    $match_pos = $tag_match[0][1];

                    if (preg_match('/\/\s*>$/', $match_str)) {
                        $search_pos = $match_pos + strlen($match_str);
                        continue;
                    }
                    if (strpos($match_str, '</') === 0) {
                        $depth--;
                    } else {
                        $depth++;
                    }
                    if ($depth === 0) {
                        $child_close_pos = $child_after_open + $match_pos + strlen($match_str);
                        break;
                    }
                    $search_pos = $match_pos + strlen($match_str);
                }

                return [
                    'tag_name'    => $child_tag_name,
                    'open_pos'    => $child_open_pos,
                    'open_tag'    => $child_open_tag,
                    'open_tag_len'=> strlen($child_open_tag),
                    'close_pos'   => $child_close_pos ?: null,
                    'after_open'  => $child_after_open,
                ];
            }
            return false;
        }

        // Simple selectors (.class, #id, tag)
        $is_class = preg_match('/^\.([a-zA-Z0-9_-]+)/', $selector, $class_matches);
        $is_id    = preg_match('/^#([a-zA-Z0-9_-]+)/', $selector, $id_matches);
        $is_tag   = preg_match('/^([a-zA-Z0-9_-]+)$/', $selector, $tag_matches);

        $pattern = null;
        $tag_name = null;

        if ($is_class) {
            $class_name = $class_matches[1];
            $pattern = '/<([a-zA-Z0-9]+)[^>]*\s+class\s*=\s*["\']([^"\']*\b' . preg_quote($class_name, '/') . '\b[^"\']*)["\'][^>]*>/i';
        } elseif ($is_id) {
            $id_name = $id_matches[1];
            $pattern = '/<([a-zA-Z0-9]+)[^>]*\s+id\s*=\s*["\']' . preg_quote($id_name, '/') . '["\'][^>]*>/i';
        } elseif ($is_tag) {
            $tag_name = $tag_matches[1];
            $pattern  = '/<' . preg_quote($tag_name, '/') . '\b[^>]*>/i';
        }

        if (!$pattern || !preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $open_pos   = $matches[0][1];
        $open_tag   = $matches[0][0];
        $tag_name   = $tag_name ?: strtolower($matches[1][0]);
        $after_open = $open_pos + strlen($open_tag);

        $remaining = substr($content, $after_open);
        $depth     = 1;
        $search_pos = 0;
        $close_pos = false;

        while ($depth > 0 && $search_pos < strlen($remaining)) {
            if (!preg_match('/<\/?' . preg_quote($tag_name, '/') . '(\s[^>]*)?>/i', $remaining, $tag_match, PREG_OFFSET_CAPTURE, $search_pos)) break;
            $match_str = $tag_match[0][0];
            $match_pos = $tag_match[0][1];
            if (preg_match('/\/\s*>$/', $match_str)) {
                $search_pos = $match_pos + strlen($match_str);
                continue;
            }
            if (strpos($match_str, '</') === 0) {
                $depth--;
            } else {
                $depth++;
            }
            if ($depth === 0) {
                $close_pos = $after_open + $match_pos + strlen($match_str);
                break;
            }
            $search_pos = $match_pos + strlen($match_str);
        }

        return [
            'tag_name'    => $tag_name,
            'open_pos'    => $open_pos,
            'open_tag'    => $open_tag,
            'open_tag_len'=> strlen($open_tag),
            'close_pos'   => $close_pos ?: null,
            'after_open'  => $after_open,
        ];
    }

    private function inject_at_position($content, $player_html, $target_info, $insert_position) {
        $open_pos   = (int) $target_info['open_pos'];
        $close_pos  = isset($target_info['close_pos']) ? (int) $target_info['close_pos'] : null;
        $after_open = (int) $target_info['after_open'];
        $content_len = strlen($content);

        switch ($insert_position) {
            case 'before_element':
                $this->log("Injecting before element.");
                return substr_replace($content, $player_html, $open_pos, 0);

            case 'after_element':
                if ($close_pos !== null && $close_pos <= $content_len) {
                    $this->log("Injecting after element closing tag.");
                    return substr_replace($content, $player_html, $close_pos, 0);
                }
                return substr_replace($content, $player_html, $after_open, 0);

            case 'inside_first_child':
            case 'prepend':
                $this->log("Injecting as first child (inside).");
                return substr_replace($content, $player_html, $after_open, 0);

            case 'inside_last_child':
            case 'inside_element':
            case 'append':
            default:
                if ($close_pos !== null && $close_pos <= $content_len) {
                    $end_chunk    = substr($content, 0, $close_pos);
                    $last_tag_pos = strrpos($end_chunk, '<');
                    $this->log("Injecting as last child (inside).");
                    return substr_replace($content, $player_html, $last_tag_pos, 0);
                }
                return substr_replace($content, $player_html, $after_open, 0);
        }
    }

    private function render_single($publication, $type, $color, $slot_css) {
        $ir_version = floor(time() / 60) * 60000;
        return sprintf(
            '<div class="instaread-player-slot" style="%s">
                <instaread-player publication="%s" playertype="%s" color="%s"></instaread-player>
                <script type="module" src="https://instaread.co/js/instaread.%s.js?version=%d"></script>
            </div>',
            esc_attr($slot_css),
            esc_html($publication),
            esc_html($type),
            esc_html($color),
            esc_html($publication),
            $ir_version
        );
    }

    private function render_playlist($publication, $height) {
        return sprintf(
            '<div class="instaread-player-slot" style="height:%s;min-height:%s;">
                <instaread-player publication="%s" p_type="playlist" height="%s"></instaread-player>
                <script type="module" src="https://instaread.co/js/v2/instaread.playlist.js" crossorigin="true"></script>
            </div>',
            esc_attr($height),
            esc_attr($height),
            esc_html($publication),
            esc_attr($height)
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
        }
    }
}

InstareadPlayer::init();
