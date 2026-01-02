<?php
/*
Plugin Name: DB ACF Extension
Description: Aangepaste ACF interface voor Digitale Bazen
Version: 1.3.3
Author: Digitale Bazen
Text Domain: db-acf-ui
Update URI: bitbucket.org/digitale-bazen/db-acf-extension

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
define( 'DB_ACF_UI_VERSION', '1.3.3' );
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
 * GitHub updater
 * ---------------------------
 */

add_filter('pre_set_site_transient_update_plugins', function ($transient) {

    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_basename = plugin_basename(DB_ACF_UI_FILE);

    $repo_owner = 'yanickvdkorst';
    $repo_name  = 'DB-ACF-Extension';

    $api_url = "https://api.github.com/repos/{$repo_owner}/{$repo_name}/releases/latest";

    $response = wp_remote_get($api_url, [
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress'
        ],
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        return $transient;
    }

    $release = json_decode(wp_remote_retrieve_body($response));

    if (empty($release->tag_name)) {
        return $transient;
    }

    $remote_version = ltrim($release->tag_name, 'v');

    if (version_compare($remote_version, DB_ACF_UI_VERSION, '>')) {

        $transient->response[$plugin_basename] = (object) [
            'slug'        => DB_ACF_UI_SLUG,
            'plugin'      => $plugin_basename,
            'new_version' => $remote_version,
            'url'         => $release->html_url,
            'package'     => $release->zipball_url,
        ];
    }

    return $transient;
});