<?php
/**
 * Plugin Name: Instaread Audio Player - raleighfoodandwine
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
    private static $debug = false;

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
        add_filter('the_content', [$this, 'inject_server_side_player'], 15, 1);
        add_filter('auto_update_plugin', [$this, 'enable_auto_updates'], 10, 2);

        $this->maybe_migrate_old_settings();
        $this->log('Instaread Player initialized.');
        $this->log(['settings' => $this->settings, 'partner_config' => $this->partner_config, 'plugin_version' => $this->plugin_version]);
    }

    private function log($msg) {
        if (self::$debug) {
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
    if (is_admin() || !is_main_query()) {
        return $content;
    }
    global $post;
    if (empty($post)) {
        return $content;
    }

    // Flatten exclusion slugs from settings
    $exclude_slugs = [];
    foreach ($this->settings['injection_rules'] as $rule) {
        if (!empty($rule['exclude_slugs']) && is_array($rule['exclude_slugs'])) {
            $exclude_slugs = array_merge($exclude_slugs, $rule['exclude_slugs']);
        }
    }
    $current_slug = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) ?? '';
    $this->log('Current slug for exclusion: ' . $current_slug);
    if ($exclude_slugs && in_array($current_slug, $exclude_slugs, true)) {
        $this->log('Skipping injection for excluded slug: ' . $current_slug);
        return $content;
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

        // Prepare player HTML markup
        $player_html = $is_playlist
            ? $this->render_playlist($publication, $playlist_height)
            : $this->render_single($publication, $player_type, $color, $slot_css);

        // Inject player markup based on position
        switch ($pos) {
            case 'prepend':
                $content = $player_html . $content;
                $this->log("Prepended player markup.");
                break;

            case 'append':
                $content .= $player_html;
                $this->log("Appended player markup.");
                break;

            case 'before_element':
                // Insert before first paragraph tag, fallback to prepend
                if (preg_match('/<p\b[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $pos_start = $matches[0][1];
                    $content = substr_replace($content, $player_html, $pos_start, 0);
                    $this->log("Inserted player markup before first <p> element.");
                } else {
                    $content = $player_html . $content;
                    $this->log("No <p> tag found; prepended player markup.");
                }
                break;

            case 'after_element':
                // Insert after first closing </p>, fallback to append
                if (preg_match('/<\/p>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $pos_end = $matches[0][1] + strlen($matches[0][0]);
                    $content = substr_replace($content, $player_html, $pos_end, 0);
                    $this->log("Inserted player markup after first </p> element.");
                } else {
                    $content .= $player_html;
                    $this->log("No </p> tag found; appended player markup.");
                }
                break;

            case 'inside_first_child':
                // Insert immediately inside the first block-level element opening tag
                if (preg_match('/<(div|section|article|p|blockquote)[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $pos_start = $matches[0][1] + strlen($matches[0][0]);
                    $content = substr_replace($content, $player_html, $pos_start, 0);
                    $this->log("Inserted player markup inside first child element.");
                } else {
                    $content = $player_html . $content;
                    $this->log("No suitable element found; prepended player markup.");
                }
                break;

            case 'inside_last_child':
            case 'inside_element':
                // Insert before last closing block-level element tag
                $pattern = '/<\/(div|section|article|p|blockquote)>/i';
                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $last = end($matches[0]);
                    $pos_start = $last[1]; // position of last closing tag
                    $content = substr_replace($content, $player_html, $pos_start, 0);
                    $this->log("Inserted player markup inside last child or element.");
                } else {
                    // fallback to append
                    $content .= $player_html;
                    $this->log("No closing container tag found; appended player markup.");
                }
                break;

            default:
                // Unknown position - fallback to append
                $content .= $player_html;
                $this->log("Appended player markup (default fallback).");
                break;
        }
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
