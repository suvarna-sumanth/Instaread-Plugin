<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Auto-injecting audio player with partner configuration support
 * Version: 2.0.0
 * Author: Instaread Team
 */

defined('ABSPATH') || exit;

// Load partner configuration if available
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
    private $plugin_version = '1.0.0';

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $partner_config;
        $this->partner_config = $partner_config;
        $this->settings = $this->get_settings();
        $this->init_update_checker();
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('wp_resource_hints', [$this, 'add_resource_hints'], 10, 2);
        $this->maybe_migrate_old_settings();
        $this->get_plugin_version();
    }

    private function init_update_checker() {
         $update_url = $this->partner_config 
            ? "https://raw.githubusercontent.com/suvarna-sumanth/Instaread-Plugin/main/partners/{$this->partner_config['partner_id']}/plugin.json"
            : 'https://suvarna-sumanth.github.io/Instaread-Plugin/plugin.json';

        $this->update_checker = PucFactory::buildUpdateChecker(
            $update_url,
            __FILE__,
            $this->partner_config ? 'instaread-' . $this->partner_config['partner_id'] : 'instaread-audio-player'
        );
    }

    private function get_settings() {
        // For partners: use config.json with multiple rules and context
        if ($this->partner_config) {
            return [
                'publication' => $this->partner_config['publication'] ?? 'default',
                'injection_rules' => $this->partner_config['injection_rules'] ?? [],
                'injection_context' => $this->partner_config['injection_context'] ?? 'singular'
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
            ],
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

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data(__FILE__);
        $this->plugin_version = $plugin_data['Version'] ?? '1.0.0';
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
            ],
            'injection_context' => [
                'label' => 'Injection Context',
                'type' => 'select',
                'options' => [
                    'singular' => 'Single Posts/Pages Only',
                    'all' => 'All Pages',
                    'archive' => 'Archives Only',
                    'front_page' => 'Front Page Only',
                    'posts_page' => 'Blog Index Only'
                ],
                'description' => 'Where to inject the player'
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
        if ($key === 'injection_context') {
            $value = $this->settings['injection_context'] ?? 'singular';
        }
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
        // Get injection context from config or settings
        $injection_context = $this->settings['injection_context'] ?? 'singular';
        $should_inject = false;
        switch ($injection_context) {
            case 'all':
                $should_inject = is_main_query();
                break;
            case 'archive':
                $should_inject = is_archive() && is_main_query();
                break;
            case 'front_page':
                $should_inject = is_front_page() && is_main_query();
                break;
            case 'posts_page':
                $should_inject = is_home() && is_main_query();
                break;
            case 'singular':
            default:
                $should_inject = is_singular() && is_main_query();
                break;
        }
        if (!$should_inject || empty($this->settings['injection_rules'])) {
            return;
        }
        // Register dummy script for inline script attachment
        wp_register_script('instaread-player', '', [], null, true);
        wp_enqueue_script('instaread-player');
        $this->inject_player_script();
    }

    private function inject_player_script() {
        global $post;
        $current_slug = isset($post->post_name) ? $post->post_name : '';
        $script = "document.addEventListener('DOMContentLoaded', function() {";
        foreach ($this->settings['injection_rules'] as $rule) {
            $excluded_slugs = array_map('trim', explode(',', $rule['exclude_slugs'] ?? ''));
            if ($current_slug && in_array($current_slug, $excluded_slugs)) continue;
            $target_selector = esc_js($rule['target_selector']);
            $insert_position = esc_js($rule['insert_position']);
            $publication = esc_js($this->settings['publication']);
            $version = esc_js($this->plugin_version);
            $script .= <<<JS
            // Rule for {$target_selector}
            (function() {
                var target = document.querySelector('{$target_selector}');
                if (!target) return;
                var playerContainer = document.createElement('div');
                playerContainer.className = 'playerContainer instaread-content-wrapper';
                playerContainer.innerHTML = `
<instaread-player publication="{$publication}" class="instaread-player">
  <div class="instaread-audio-player" style="margin: 0px; box-sizing: border-box;">
    <iframe id="instaread_iframe" name="instaread_playlist" width="100%" height="100%"
            scrolling="no" frameborder="0" loading="lazy" title="Audio Article"
            allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
            style="display: block;" data-pin-nopin="true">
    </iframe>
  </div>
</instaread-player>
                `;
                var script = document.createElement('script');
                script.src = "https://instaread.co/js/instaread.{$publication}.js?version={$version}";
                script.async = true;
                switch ('{$insert_position}') {
                    case 'prepend':
                        target.insertBefore(playerContainer, target.firstChild);
                        target.insertBefore(script, target.firstChild);
                        break;
                    case 'append':
                        target.appendChild(playerContainer);
                        target.appendChild(script);
                        break;
                    case 'inside':
                        target.appendChild(playerContainer);
                        target.appendChild(script);
                        break;
                }
            })();
JS;
        }
        $script .= "});";
        wp_add_inline_script('instaread-player', $script);
    }

    public function add_resource_hints($urls, $relation_type) {
        if ($relation_type === 'preconnect') {
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
        $sanitized['injection_context'] = in_array($input['injection_context'] ?? '', ['singular', 'all', 'archive', 'front_page', 'posts_page'])
            ? $input['injection_context'] : 'singular';
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
                'exclude_slugs' => $old_settings['exclude_slugs'] ?? '',
                'injection_context' => $old_settings['injection_context'] ?? 'singular'
            ];
            update_option('instaread_settings', $new_settings);
            delete_option('instaread_legacy_settings');
        }
    }
}

InstareadPlayer::init();
