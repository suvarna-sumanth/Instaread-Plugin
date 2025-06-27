<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Auto-injecting audio player with partner configuration support
 * Version: 2.0.0
 * Author: Instaread Team
 */

defined('ABSPATH') || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Define constants - NO hardcoded version
define('INSTAREAD_PLUGIN_FILE', __FILE__);
define('INSTAREAD_PLUGIN_DIR', plugin_dir_path(__FILE__));

class InstareadPlayer {
    private static $instance;
    private $settings;
    private $partner_config;
    private $update_checker;
    private $puc_loaded = false;
    private $plugin_version; // Dynamic version property

    public static function init() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Get version dynamically FIRST
        $this->plugin_version = $this->get_plugin_version();
        
        $this->partner_config = $this->load_partner_config();
        $this->puc_loaded = $this->load_update_checker_library();
        $this->settings = $this->get_settings();
        $this->init_update_checker();

        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('wp_resource_hints', [$this, 'add_resource_hints'], 10, 2);

        $this->maybe_migrate_old_settings();
        $this->log('Plugin initialized - Version: ' . $this->plugin_version);
    }

    /**
     * Get plugin version dynamically from plugin header
     */
    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        try {
            $plugin_data = get_plugin_data(INSTAREAD_PLUGIN_FILE);
            return $plugin_data['Version'] ?? '1.0.0';
        } catch (Exception $e) {
            $this->log('Failed to get plugin version: ' . $e->getMessage());
            return '1.0.0'; // Fallback
        }
    }

    private function load_partner_config() {
        $file = INSTAREAD_PLUGIN_DIR . 'config.json';
        if (!file_exists($file)) {
            return null;
        }

        $json = file_get_contents($file);
        $config = json_decode($json, true);
        if (!is_array($config)) {
            $this->log('Malformed config.json');
            return null;
        }

        return $config;
    }

    private function load_update_checker_library() {
        $path = INSTAREAD_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
        if (!file_exists($path)) {
            $this->log('Update checker file not found');
            return false;
        }

        try {
            require_once $path;
            return class_exists(PucFactory::class);
        } catch (Exception $e) {
            $this->log('Update checker failed to load: ' . $e->getMessage());
            return false;
        }
    }

    private function init_update_checker() {
        if (!$this->puc_loaded) {
            $this->log('Skipping update checker – not loaded');
            return;
        }

        try {
            $url = $this->partner_config
                ? "https://raw.githubusercontent.com/suvarna-sumanth/Instaread-Plugin/main/partners/{$this->partner_config['partner_id']}/plugin.json"
                : 'https://suvarna-sumanth.github.io/Instaread-Plugin/plugin.json';

            $slug = $this->partner_config
                ? 'instaread-' . $this->partner_config['partner_id']
                : 'instaread-audio-player';

            $this->update_checker = PucFactory::buildUpdateChecker($url, INSTAREAD_PLUGIN_FILE, $slug);
            $this->log('Update checker initialized with URL: ' . $url);
        } catch (Exception $e) {
            $this->log('Update checker init error: ' . $e->getMessage());
        }
    }

    private function get_settings() {
        if ($this->partner_config) {
            return [
                'publication' => $this->partner_config['publication'] ?? 'default',
                'injection_rules' => $this->partner_config['injection_rules'] ?? [],
                'injection_context' => $this->partner_config['injection_context'] ?? 'singular'
            ];
        }

        $wp_settings = get_option('instaread_settings', $this->get_default_settings());
        return [
            'publication' => $wp_settings['publication'] ?? 'default',
            'injection_rules' => [[
                'target_selector' => $wp_settings['target_selector'] ?? '.entry-content',
                'insert_position' => $wp_settings['insert_position'] ?? 'append',
                'exclude_slugs' => $wp_settings['exclude_slugs'] ?? 'about,home'
            ]],
            'injection_context' => $wp_settings['injection_context'] ?? 'singular'
        ];
    }

    private function get_default_settings() {
        return [
            'publication' => 'default',
            'target_selector' => '.entry-content',
            'insert_position' => 'append',
            'exclude_slugs' => 'about,home',
            'injection_context' => 'singular'
        ];
    }

    public function register_settings() {
        register_setting('instaread_settings', 'instaread_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        add_settings_section('instaread_main', 'Player Configuration', null, 'instaread-settings');

        $fields = [
            'publication' => ['label' => 'Publication ID', 'type' => 'text'],
            'target_selector' => ['label' => 'Target Element', 'type' => 'text'],
            'insert_position' => [
                'label' => 'Insert Position', 
                'type' => 'select',
                'options' => ['prepend' => 'Prepend', 'append' => 'Append', 'inside' => 'Inside']
            ],
            'exclude_slugs' => ['label' => 'Excluded Slugs', 'type' => 'text'],
            'injection_context' => [
                'label' => 'Injection Context',
                'type' => 'select', 
                'options' => [
                    'singular' => 'Single Posts/Pages',
                    'all' => 'All Pages',
                    'archive' => 'Archives',
                    'front_page' => 'Front Page',
                    'posts_page' => 'Blog Index'
                ]
            ]
        ];

        foreach ($fields as $key => $args) {
            add_settings_field("instaread_{$key}", $args['label'], [$this, 'field_html'], 'instaread-settings', 'instaread_main', ['key' => $key, 'args' => $args]);
        }
    }

    public function field_html($args) {
        $key = $args['key'];
        $field = $args['args'];
        $value = ($key === 'injection_context') 
            ? $this->settings['injection_context'] ?? 'singular'
            : $this->settings['injection_rules'][0][$key] ?? '';

        switch ($field['type']) {
            case 'select':
                echo '<select name="instaread_settings[' . esc_attr($key) . ']">';
                foreach ($field['options'] as $val => $label) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($value, $val, false), esc_html($label));
                }
                echo '</select>';
                break;
            default:
                printf('<input type="text" name="instaread_settings[%s]" value="%s" class="regular-text">', esc_attr($key), esc_attr($value));
        }
    }

    public function add_settings_page() {
        add_options_page('Instaread Settings', 'Instaread Player', 'manage_options', 'instaread-settings', [$this, 'render_settings_page']);
    }

    public function render_settings_page() {
        if ($this->partner_config) {
            ?>
            <div class="wrap">
                <h1>Instaread Audio Player - Partner Configuration</h1>
                <div class="notice notice-info">
                    <p><strong>Partner Configuration Active</strong></p>
                    <p>Partner: <?php echo esc_html($this->partner_config['partner_id'] ?? 'Unknown'); ?></p>
                    <p>Publication: <?php echo esc_html($this->partner_config['publication'] ?? 'Unknown'); ?></p>
                    <p>Version: <?php echo esc_html($this->plugin_version); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        ?>
        <div class="wrap">
            <h1>Instaread Audio Player (v<?php echo esc_html($this->plugin_version); ?>)</h1>
            <form method="post" action="options.php">
                <?php settings_fields('instaread_settings'); do_settings_sections('instaread-settings'); submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets() {
        if (!is_admin() && $this->should_inject()) {
            wp_register_script('instaread-player', '', [], $this->plugin_version, true);
            wp_enqueue_script('instaread-player');
            $this->inject_player_script();
            $this->log('Assets enqueued for version: ' . $this->plugin_version);
        }
    }

    private function should_inject() {
        switch ($this->settings['injection_context']) {
            case 'all': return true;
            case 'archive': return is_archive();
            case 'front_page': return is_front_page();
            case 'posts_page': return is_home();
            case 'singular':
            default: return is_singular();
        }
    }

    private function inject_player_script() {
        global $post;
        $slug = $post->post_name ?? '';

        $script = "document.addEventListener('DOMContentLoaded',function() {";
        $script .= "console.log('Instaread: Player loading - Version {$this->plugin_version}');";
        
        foreach ($this->settings['injection_rules'] as $i => $rule) {
            $excluded = array_map('trim', explode(',', $rule['exclude_slugs'] ?? ''));
            if (in_array($slug, $excluded, true)) continue;

            $sel = esc_js($rule['target_selector']);
            $pos = esc_js($rule['insert_position']);
            $pub = esc_js($this->settings['publication']);
            $ver = esc_js($this->plugin_version); // Use dynamic version

            $script .= <<<JS
(function() {
    console.log('Instaread: Processing rule {$i} for selector "{$sel}"');
    var t = document.querySelector("{$sel}");
    if (!t) {
        console.error('Instaread: Target "{$sel}" not found');
        return;
    }
    console.log('Instaread: Target found:', t);
    
    var c = document.createElement("div");
    c.className = "instaread-content-wrapper";
    c.innerHTML = '<instaread-player publication="{$pub}"><div class="instaread-audio-player"><iframe name="instaread_playlist" width="100%" height="100%" frameborder="0" loading="lazy" allow="autoplay; encrypted-media" style="display:block;"></iframe></div></instaread-player>';
    
    var s = document.createElement("script");
    s.src = "https://instaread.co/js/instaread.{$pub}.js?version={$ver}";
    s.async = true;
    console.log('Instaread: Loading script:', s.src);
    
    if ("{$pos}" === "prepend") {
        t.insertBefore(s, t.firstChild);
        t.insertBefore(c, t.firstChild);
    } else {
        t.appendChild(c);
        t.appendChild(s);
    }
    console.log('Instaread: Player injected successfully');
})();
JS;
        }
        $script .= "});";
        wp_add_inline_script('instaread-player', $script);
    }

    public function add_resource_hints($urls, $relation_type) {
        if ($relation_type === 'preconnect' && (is_singular() || $this->settings['injection_context'] === 'all')) {
            $urls[] = 'https://instaread.co';
        }
        return array_unique($urls);
    }

    public function sanitize_settings($input) {
        return [
            'publication' => sanitize_text_field($input['publication'] ?? ''),
            'target_selector' => preg_replace('/[^a-zA-Z0-9\s\.\#\->+~=^$|*,:\[\]]/', '', $input['target_selector'] ?? ''),
            'insert_position' => in_array($input['insert_position'] ?? '', ['prepend', 'append', 'inside']) ? $input['insert_position'] : 'append',
            'exclude_slugs' => implode(',', array_filter(array_unique(array_map('sanitize_title', explode(',', $input['exclude_slugs'] ?? ''))))),
            'injection_context' => in_array($input['injection_context'] ?? '', ['singular', 'all', 'archive', 'front_page', 'posts_page']) ? $input['injection_context'] : 'singular'
        ];
    }

    private function maybe_migrate_old_settings() {
        $old_settings = get_option('instaread_legacy_settings', false);
        if ($old_settings) {
            update_option('instaread_settings', [
                'publication' => $old_settings['publication'] ?? 'default',
                'target_selector' => $old_settings['target_selector'] ?? '.entry-content',
                'insert_position' => $old_settings['insert_position'] ?? 'append',
                'exclude_slugs' => $old_settings['exclude_slugs'] ?? '',
                'injection_context' => $old_settings['injection_context'] ?? 'singular'
            ]);
            delete_option('instaread_legacy_settings');
        }
    }

    private function log($msg) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log('[Instaread] ' . $msg);
        }
    }
}

InstareadPlayer::init();
