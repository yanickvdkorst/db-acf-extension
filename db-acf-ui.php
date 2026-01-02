<?php
/*
Plugin Name: DB ACF Extension
Description: Aangepaste ACF interface voor Digitale Bazen
Version: 1.2.1
Author: Digitale Bazen
Text Domain: db-acf-ui

Icon: assets/images/icon-128x128.png
Banner: assets/images/banner-772x250.png
Banner-HighRes: assets/images/banner-1544x500.png
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ---------------------------
 * Constants
 * ---------------------------
 */
define( 'DB_ACF_UI_VERSION', '1.2.1' );
define( 'DB_ACF_UI_MIN_PHP_VERSION', '8.0' );

define( 'DB_ACF_UI_FILE', __FILE__ );
define( 'DB_ACF_UI_DIR', plugin_dir_path( __FILE__ ) );
define( 'DB_ACF_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'DB_ACF_UI_SLUG', 'db-acf-extension' );

// GitHub repo info
define( 'DB_ACF_UI_GITHUB_REPO', 'yanickvdkorst/DB-ACF-Extension' );

/**
 * ---------------------------
 * PHP version check
 * ---------------------------
 */
if ( version_compare( PHP_VERSION, DB_ACF_UI_MIN_PHP_VERSION, '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>DB ACF Extension vereist PHP 8.0 of hoger.</p></div>';
    } );
    return;
}

/**
 * ---------------------------
 * Autoload
 * ---------------------------
 */
require_once DB_ACF_UI_DIR . 'autoload.php';

/**
 * ---------------------------
 * Init plugin
 * ---------------------------
 */
add_action( 'plugins_loaded', function () {
    if ( ! function_exists( 'acf' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>Advanced Custom Fields is vereist voor DB ACF Extension.</p></div>';
        } );
        return;
    }

    \DB_ACF_UI\Main::get_instance();
} );

/**
 * ---------------------------
 * Bitbucket updater
 * ---------------------------
 */

add_filter('pre_set_site_transient_update_plugins', function($transient) {

    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_slug = plugin_basename(DB_ACF_UI_FILE);
    $url = 'https://api.bitbucket.org/2.0/repositories/digitale-bazen/db-acf-extension/refs/tags?sort=-name&pagelen=1';

    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'WordPress'],
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        add_action('admin_notices', function() use ($response) {
            echo '<div class="notice notice-error"><p>[DB ACF] Bitbucket API error: ' . esc_html($response->get_error_message()) . '</p></div>';
        });
        return $transient;
    }

    $data = json_decode(wp_remote_retrieve_body($response));
    if (empty($data->values)) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>[DB ACF] Geen tags gevonden op Bitbucket.</p></div>';
        });
        return $transient;
    }

    // Vind de hoogste versie tag
    $latest_tag = '';
    foreach ($data->values as $tag) {
        $ver = ltrim($tag->name, 'v');
        if (!$latest_tag || version_compare($ver, ltrim($latest_tag, 'v'), '>')) {
            $latest_tag = $tag->name;
        }
    }

    $remote_version = ltrim($latest_tag, 'v');

    // Als remote hoger is, bied update aan
    if (version_compare($remote_version, DB_ACF_UI_VERSION, '>')) {
        $transient->response[$plugin_slug] = (object)[
            'slug'        => DB_ACF_UI_SLUG,
            'plugin'      => $plugin_slug,
            'new_version' => $remote_version,
            'url'         => 'https://bitbucket.org/digitale-bazen/db-acf-extension',
            'package'     => 'https://bitbucket.org/digitale-bazen/db-acf-extension/get/' . $latest_tag . '.zip',
        ];
    }

    return $transient;
});