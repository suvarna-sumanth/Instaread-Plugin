<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Basic audio player with auto-updates
 * Version: 1.0.0
 * Author: Your Name
 * Update URI: https://your-username.github.io/Instaread-Plugin/plugin.json
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Auto-update setup
require_once 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://suvarna-sumanth.github.io/Instaread-Plugin/plugin.json',
    __FILE__,
    'instaread-audio-player'
);
