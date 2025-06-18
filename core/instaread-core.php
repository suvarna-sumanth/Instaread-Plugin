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
        // For partners: use config.json with multiple rules
        if ($this->partner_config) {
            return [
                'publication' => $this->partner_config['publication'] ?? 'default',
                'injection_rules' => $this->partner_config['injection_rules'] ?? []
            ];
        }
        
        // For default: use WordPress settings with single rule
        $wp_settings = get_option('instaread_settings', $this->get_default_settings());
        
        return [
            'publication' => $wp_settings['publication'] ?? 'default',
            'injection_rules' => [
                [
                    'target_selector' => $wp_settings['target_selector'] ?? '.entry-content',
                    'insert_position' => $wp_settings['insert_position'] ?? 'append',
                    'exclude_slugs' => $wp_settings['exclude_slugs'] ?? 'about,home'
                ]
            ]
        ];
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
        $value = $this->settings['injection_rules'][0][$key] ?? '';

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
        // For partners, show a notice that settings are managed via config.json
        if ($this->partner_config) {
            echo '<div class="notice notice-info">';
            echo '<p>This plugin is configured via partner configuration. Settings are managed through config.json.</p>';
            echo '</div>';
            return;
        }
        
        $this->settings = get_option('instaread_settings', $this->get_default_settings());
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
        // Only inject on single posts/pages
        if (!is_singular() || !is_main_query()) return;
        
        // Don't inject if no rules defined
        if (empty($this->settings['injection_rules'])) return;

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
        global $post;
        $current_slug = $post->post_name;
        $script = "document.addEventListener('DOMContentLoaded', function() {";
        
        foreach ($this->settings['injection_rules'] as $rule) {
            // Skip if current slug is excluded
            $excluded_slugs = array_map('trim', explode(',', $rule['exclude_slugs'] ?? ''));
            if (in_array($current_slug, $excluded_slugs)) continue;
            
            $config = [
                'publication' => esc_js($this->settings['publication']),
                'target' => esc_js($rule['target_selector']),
                'position' => esc_js($rule['insert_position'])
            ];

            $script .= <<<JS
            // Rule for {$config['target']}
            (function() {
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
            })();
            JS;
        }

        $script .= "});";
        wp_add_inline_script('instaread-player', $script);
    }

    public function add_resource_hints($urls, $relation_type) {
        if ($relation_type === 'preconnect' && is_singular()) {
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
