<?php
/**
 * Instaread Auto-Update Enabler (Must-Use Plugin)
 *
 * This MU-plugin ensures automatic updates are enabled for the Instaread plugin.
 * It's automatically loaded by WordPress and doesn't require manual activation.
 *
 * Place in: wp-content/mu-plugins/instaread-auto-updater.php
 */

defined('ABSPATH') || exit;

// Enable automatic updates for ALL plugins (including Instaread)
add_filter('auto_update_plugin', function($update, $item) {
    // Always allow auto-updates for Instaread plugins
    if (isset($item->slug) && strpos($item->slug, 'instaread') !== false) {
        return true;
    }
    // Also allow for our plugin basename
    if (isset($item->plugin) && strpos($item->plugin, 'instaread-core.php') !== false) {
        return true;
    }
    return $update;
}, 10, 2);

// Ensure background updates are enabled
if (!defined('AUTOMATIC_UPDATER_DISABLED')) {
    define('AUTOMATIC_UPDATER_DISABLED', false);
}

// Log auto-update events for debugging
add_action('upgrader_process_complete', function($upgrader, $hook_extra) {
    if (isset($hook_extra['plugin']) && strpos($hook_extra['plugin'], 'instaread') !== false) {
        error_log('[Instaread MU-Plugin] Auto-update completed for: ' . $hook_extra['plugin']);
    }
}, 10, 2);
