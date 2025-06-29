<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Auto-injecting audio player with partner configuration support
 * Version: 2.2.0
 * Author: Instaread Team
 */

defined('ABSPATH') || exit;

// Load partner configuration if available
$partner_config_file = __DIR__ . '/config.json';
$partner_config = file_exists($partner_config_file)
    ? json_decode(file_get_contents($partner_config_file), true)
    : null;

// Auto-update integration
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class InstareadPlayer {
    private static $instance;
    private $settings;
    private $partner_config;
    private $plugin_version;
    private $debugger_mode = false;

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
        $this->get_plugin_version();
        $this->init_update_checker();
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('wp_resource_hints', [$this, 'add_resource_hints'], 10, 2);
        $this->maybe_migrate_old_settings();
        $this->log('InstareadPlayer initialized.');
    }

    private function log($msg) {
        if ($this->debugger_mode) {
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
        $this->log('Update checker URL: ' . $update_url);
    }

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data(__FILE__);
        $this->plugin_version = $data['Version'] ?? '1.0.0';
        $this->log('Plugin version: ' . $this->plugin_version);
    }

    private function get_settings() {
        if ($this->partner_config) {
            return [
                'publication' => $this->partner_config['publication'] ?? 'default',
                'injection_rules' => array_map(function($r) {
                    if (isset($r['exclude_slugs']) && is_array($r['exclude_slugs'])) {
                        $r['exclude_slugs'] = implode(',', $r['exclude_slugs']);
                    }
                    return $r;
                }, $this->partner_config['injection_rules'] ?? []),
                'injection_context' => $this->partner_config['injection_context'] ?? 'singular',
                'injection_strategy' => $this->partner_config['injection_strategy'] ?? 'first',
            ];
        }
        $wp = get_option('instaread_settings', []);
        return [
            'publication' => $wp['publication'] ?? 'default',
            'injection_rules' => [
                [
                    'target_selector' => $wp['target_selector'] ?? '.entry-content',
                    'insert_position' => $wp['insert_position'] ?? 'append',
                    'exclude_slugs' => $wp['exclude_slugs'] ?? '',
                ]
            ],
            'injection_context' => $wp['injection_context'] ?? 'singular',
            'injection_strategy' => $wp['injection_strategy'] ?? 'first',
        ];
    }
    

    public function register_settings() {
        register_setting('instaread_settings', 'instaread_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
        add_settings_section('instaread_main', 'Player Configuration', null, 'instaread-settings');

        $fields = [
            'publication' => ['label'=>'Publication','type'=>'text','description'=>'Instaread publication identifier'],
            'target_selector'=>['label'=>'Target Selector','type'=>'text','description'=>'CSS selector for injection'],
            'insert_position'=>['label'=>'Insert Position','type'=>'select','options'=>['prepend'=>'Prepend','append'=>'Append','inside'=>'Inside'],'description'=>'Where to insert'],
            'exclude_slugs'=>['label'=>'Exclude Slugs','type'=>'text','description'=>'Comma-separated slugs to skip'],
            'injection_context'=>['label'=>'Context','type'=>'select','options'=>['singular'=>'Single Posts/Pages','all'=>'All Pages','archive'=>'Archive','front_page'=>'Front Page','posts_page'=>'Blog Index'],'description'=>'Where to inject'],
        ];
        foreach ($fields as $key => $fs) {
            add_settings_field(
                "instaread_$key",
                $fs['label'],
                [$this, 'field_html'],
                'instaread-settings',
                'instaread_main',
                ['key'=>$key,'args'=>$fs]
            );
        }
        add_settings_field(
            "instaread_injection_strategy",
            'Injection Strategy',
            function() {
                $v = $this->settings['injection_strategy'];
                echo '<select name="instaread_settings[injection_strategy]">';
                foreach (['first'=>'First','all'=>'All','none'=>'None','custom'=>'Custom'] as $val=>$label) {
                    printf('<option value="%s"%s>%s</option>',
                        esc_attr($val),
                        selected($v, $val, false),
                        $label
                    );
                }
                echo '</select><p class="description">Choose injection strategy</p>';
            },
            'instaread-settings','instaread_main'
        );
    }

    public function field_html($args) {
        $k = $args['key']; $f=$args['args'];
        $val = $this->settings['injection_rules'][0][$k] ?? '';
        if ($k==='injection_context') $val = $this->settings['injection_context'];
        switch ($f['type']) {
            case 'select':
                echo "<select name='instaread_settings[$k]'>";
                foreach ($f['options'] as $opt=>$lab) {
                    printf("<option value='%s'%s>%s</option>", esc_attr($opt), selected($val,$opt,false), $lab);
                }
                echo "</select>";
                break;
            default:
                printf("<input type='text' name='instaread_settings[%s]' value='%s' class='regular-text'>", esc_attr($k), esc_attr($val));
        }
        if (!empty($f['description'])) {
            echo "<p class='description'>{$f['description']}</p>";
        }
    }

    public function add_settings_page() {
        add_options_page('Instaread Settings','Instaread Player','manage_options','instaread-settings',[$this,'render_settings_page']);
    }

    public function render_settings_page() {
        if ($this->partner_config) {
            echo '<div class="notice notice-info"><p>Configured via config.json – settings disabled here.</p></div>';
            return;
        }
        $sw = get_option('instaread_settings',[]);
        ?>
        <div class="wrap"><h1>Instaread Audio Player Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('instaread_settings'); do_settings_sections('instaread-settings'); submit_button(); ?>
        </form></div>
        <?php
    }

    public function enqueue_assets() {
        global $post;
        $ctx=$this->settings['injection_context']; $slug=$post->post_name ?? '';
        $ok = ($ctx==='all') || ($ctx==='singular' && is_singular());
        if (!$ok || !is_main_query()) return;
        if ($this->settings['injection_strategy']==='none') return;

        wp_register_script('instaread-player','',[],null,true);
        wp_enqueue_script('instaread-player');

        if (
    isset($this->settings['injection_strategy']) &&
    $this->settings['injection_strategy'] === 'custom' &&
    file_exists(plugin_dir_path(__FILE__) . 'partner.js')
) {
    wp_enqueue_script(
        'instaread-partner',
        plugin_dir_url(__FILE__) . 'partner.js',
        [],
        null,
        true
    );
    $this->log('Loaded partner.js via custom injection strategy.');
    return;
} else {
    $this->log('No partner.js found or not using custom strategy. Falling back to default injection.');
    $this->inject_player_script(); // default fallback
}

    }

    private function inject_player_script() {
        global $post;
        $slug = $post->post_name ?? '';
        $pub = esc_js($this->settings['publication']);
        $ver = time();
        $strat = $this->settings['injection_strategy'];
        $script = "document.addEventListener('DOMContentLoaded',function(){\n";
    
        foreach ($this->settings['injection_rules'] as $r) {
            // ✅ Fix: Handle exclude_slugs as array or string
            $exclude_raw = $r['exclude_slugs'] ?? '';
            $exclude_str = is_array($exclude_raw) ? implode(',', $exclude_raw) : $exclude_raw;
            $ex = array_map('trim', explode(',', $exclude_str));
            if ($slug && in_array($slug, $ex)) continue;
    
            $sel = esc_js($r['target_selector']);
            $pos = esc_js($r['insert_position']);
            $inject_all = ($strat === 'all') ? 'true' : 'false';
    
            $script .= <<<JS
    (function(){
      const els = document.querySelectorAll("{$sel}");
      if (!els.length) return;
      const list = ({$inject_all}) ? Array.from(els) : [els[0]];
      list.forEach(function(t){
        const w = document.createElement("div");
        w.className = "playerContainer instaread-content-wrapper";
        w.innerHTML = `
    <instaread-player publication="{$pub}" class="instaread-player">
      <div class="instaread-audio-player" style="box-sizing:border-box;margin:0">
        <iframe id="instaread_iframe" width="100%" height="100%" scrolling="no" frameborder="0" loading="lazy" title="Audio Article" style="display:block" data-pin-nopin="true"></iframe>
      </div>
    </instaread-player>`;
        const s = document.createElement("script");
        s.src = "https://instaread.co/js/instaread.{$pub}.js?version={$ver}";
        s.async = true;
        switch("{$pos}") {
          case "prepend": case "before_element":
            t.parentNode.insertBefore(w, t);
            t.parentNode.insertBefore(s, t);
            break;
          case "append": case "after_element":
            t.parentNode.insertBefore(w, t.nextSibling);
            t.parentNode.insertBefore(s, t.nextSibling);
            break;
          case "inside": case "inside_element":
            t.appendChild(w);
            t.appendChild(s);
            break;
        }
      });
    })();
    JS;
        }
    
        $script .= "});";
        wp_add_inline_script('instaread-player', $script);
        $this->log('Injected script strategy=' . $strat);
    }
    
    public function add_resource_hints($urls,$rel) {
        if ($rel==='preconnect') $urls[]='https://instaread.co';
        return array_unique($urls);
    }

    public function sanitize_settings($in) {
        return [
            'publication'=>sanitize_text_field($in['publication'] ?? ''),
            'target_selector'=>sanitize_text_field($in['target_selector'] ?? ''),
            'insert_position'=>sanitize_text_field($in['insert_position'] ?? 'append'),
            'exclude_slugs'=>sanitize_text_field($in['exclude_slugs'] ?? ''),
            'injection_context'=>in_array($in['injection_context'] ?? '','all','singular','archive','front_page','posts_page') ? $in['injection_context'] : 'singular',
            'injection_strategy'=>in_array($in['injection_strategy'] ?? 'first',['first','all','none','custom']) ? $in['injection_strategy'] : 'first',
        ];
    }

    private function maybe_migrate_old_settings() {
        $old = get_option('instaread_legacy_settings',false);
        if ($old) {
            $new = [
                'publication'=>$old['publication'] ?? '',
                'target_selector'=>$old['target_selector'] ?? '',
                'insert_position'=>$old['insert_position'] ?? '',
                'exclude_slugs'=>$old['exclude_slugs'] ?? '',
                'injection_context'=>$old['injection_context'] ?? '',
                'injection_strategy'=>'first',
            ];
            update_option('instaread_settings',$new);
            delete_option('instaread_legacy_settings');
            $this->log('Migrated legacy settings');
        }
    }
}

InstareadPlayer::init();
