<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Auto-injecting audio player with partner configuration support
 * Version: 1.0.0
 * Author: Instaread Team
 */

defined('ABSPATH') || exit;

// Load partner configuration
$partner_config_file = __DIR__ . '/config.json';
$partner_config = file_exists($partner_config_file) 
    ? json_decode(file_get_contents($partner_config_file), true) 
    : null;

// Auto-update integration with partner-specific endpoint
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class InstareadPlayer {
    private static $instance;
    private $settings;
    private $partner_config;
    private $update_checker;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $partner_config;
        $this->partner_config = $partner_config;
        
        // Initialize settings
        $this->settings = $this->get_settings();
        
        // Initialize update checker
        $this->init_update_checker();
        
        // Register hooks
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('wp_resource_hints', [$this, 'add_resource_hints'], 10, 2);
        
        // Migration from older versions
        $this->maybe_migrate_old_settings();
    }

    private function init_update_checker() {
        $update_url = $this->partner_config 
            ? "https://suvarna-sumanth.github.io/Instaread-Plugin/partners/{$this->partner_config['partner_id']}/plugin.json"
            : 'https://suvarna-sumanth.github.io/Instaread-Plugin/plugin.json';

        $this->update_checker = PucFactory::buildUpdateChecker(
            $update_url,
            __FILE__,
            $this->partner_config ? 'instaread-' . $this->partner_config['partner_id'] : 'instaread-audio-player'
        );
    }

    private function get_settings() {
        if ($this->partner_config) {
            return [
                'publication' => $this->partner_config['publication'] ?? 'default',
                'target_selector' => $this->partner_config['injection_rules'][0]['target_selector'] ?? '.entry-content',
                'insert_position' => $this->partner_config['injection_rules'][0]['insert_position'] ?? 'append',
                'exclude_slugs' => implode(',', $this->partner_config['injection_rules'][0]['exclude_slugs'] ?? [])
            ];
        }
        
        return wp_parse_args(
            get_option('instaread_settings', []),
            $this->get_default_settings()
        );
    }

    private function get_default_settings() {
        return [
            'publication' => 'default',
            'target_selector' => '.entry-content',
            'insert_position' => 'append',
            'exclude_slugs' => 'about,home'
        ];
    }

    public function register_settings() {
        register_setting('instaread_settings', 'instaread_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        add_settings_section(
            'instaread_main',
            'Player Configuration',
            null,
            'instaread-settings'
        );

        $fields = [
            'publication' => [
                'label' => 'Publication ID',
                'type' => 'text',
                'description' => 'The publication identifier from Instaread'
            ],
            'target_selector' => [
                'label' => 'Target Element',
                'type' => 'text',
                'description' => 'CSS selector for injection point (e.g., .entry-content)',
                'sanitize' => 'sanitize_css_selector'
            ],
            'insert_position' => [
                'label' => 'Insert Position',
                'type' => 'select',
                'options' => [
                    'prepend' => 'Prepend',
                    'append' => 'Append',
                    'inside' => 'Inside'
                ],
                'description' => 'Where to insert the player relative to the target element'
            ],
            'exclude_slugs' => [
                'label' => 'Excluded Slugs',
                'type' => 'text',
                'description' => 'Comma-separated list of slugs to exclude',
                'sanitize' => 'sanitize_slug_list'
            ]
        ];

        foreach ($fields as $key => $args) {
            add_settings_field(
                "instaread_{$key}",
                $args['label'],
                [$this, 'field_html'],
                'instaread-settings',
                'instaread_main',
                ['key' => $key, 'args' => $args]
            );
        }
    }

    public function field_html($args) {
        $key = $args['key'];
        $field = $args['args'];
        $value = $this->settings[$key] ?? '';

        switch ($field['type']) {
            case 'select':
                echo '<select name="instaread_settings[' . esc_attr($key) . ']">';
                foreach ($field['options'] as $val => $label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($val),
                        selected($value, $val, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                break;

            case 'text':
            default:
                printf(
                    '<input type="text" name="instaread_settings[%s]" value="%s" class="regular-text">',
                    esc_attr($key),
                    esc_attr($value)
                );
        }

        if (!empty($field['description'])) {
            printf('<p class="description">%s</p>', esc_html($field['description']));
        }
    }

    public function add_settings_page() {
        add_options_page(
            'Instaread Settings',
            'Instaread Player',
            'manage_options',
            'instaread-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Instaread Audio Player Configuration</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('instaread_settings');
                do_settings_sections('instaread-settings');
                submit_button('Save Settings', 'primary');
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets() {
        if (!$this->should_inject_player()) return;

        wp_enqueue_script(
            'instaread-player',
            'https://instaread.co/js/player.v3.js',
            [],
            null,
            true
        );

        $this->inject_player_script();
    }

    private function inject_player_script() {
        $config = [
            'publication' => esc_js($this->settings['publication']),
            'target' => esc_js($this->settings['target_selector']),
            'position' => esc_js($this->settings['insert_position'])
        ];

        $script = <<<JS
        document.addEventListener('DOMContentLoaded', function() {
            const config = {
                publication: "{$config['publication']}",
                targetSelector: "{$config['target']}",
                insertPosition: "{$config['position']}"
            };
            
            const loadPlayer = () => {
                const target = document.querySelector(config.targetSelector);
                if (!target) return false;
                
                if (typeof InstareadPlayer === 'function') {
                    new InstareadPlayer(config);
                    return true;
                }
                return false;
            };
            
            // Initial attempt
            if (loadPlayer()) return;
            
            // Watch for dynamic content changes
            const observer = new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    if (mutation.addedNodes.length > 0 && loadPlayer()) {
                        observer.disconnect();
                        break;
                    }
                }
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: false,
                characterData: false
            });
        });
        JS;

        wp_add_inline_script('instaread-player', $script);
    }

    private function should_inject_player() {
        if (!is_singular() || !is_main_query()) return false;
        
        global $post;
        $excluded = array_map('trim', explode(',', $this->settings['exclude_slugs']));
        
        return !in_array($post->post_name, $excluded, true);
    }

    public function add_resource_hints($urls, $relation_type) {
        if ($relation_type === 'preconnect' && $this->should_inject_player()) {
            $urls[] = 'https://instaread.co';
        }
        return array_unique($urls);
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        
        $sanitized['publication'] = sanitize_text_field($input['publication'] ?? '');
        $sanitized['target_selector'] = $this->sanitize_css_selector($input['target_selector'] ?? '');
        $sanitized['insert_position'] = $this->sanitize_insert_position($input['insert_position'] ?? '');
        $sanitized['exclude_slugs'] = $this->sanitize_slug_list($input['exclude_slugs'] ?? '');
        
        return $sanitized;
    }

    private function sanitize_css_selector($input) {
        return preg_replace('/[^a-zA-Z0-9\s\.\#\->+~=^$|*,:]/', '', $input);
    }

    private function sanitize_insert_position($input) {
        return in_array($input, ['prepend', 'append', 'inside']) ? $input : 'append';
    }

    private function sanitize_slug_list($input) {
        $slugs = array_map('sanitize_title', explode(',', $input));
        return implode(',', array_unique($slugs));
    }

    private function maybe_migrate_old_settings() {
        $old_settings = get_option('instaread_legacy_settings', false);
        if ($old_settings) {
            $new_settings = [
                'publication' => $old_settings['publication'] ?? 'default',
                'target_selector' => $old_settings['target_selector'] ?? '.entry-content',
                'insert_position' => $old_settings['insert_position'] ?? 'append',
                'exclude_slugs' => $old_settings['exclude_slugs'] ?? ''
            ];
            
            update_option('instaread_settings', $new_settings);
            delete_option('instaread_legacy_settings');
        }
    }
}

InstareadPlayer::init();
