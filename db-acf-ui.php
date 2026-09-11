<?php
/*
Plugin Name: DB ACF Extension
Description: Aangepaste ACF interface voor Digitale Bazen
Version: 1.7.3
Author: Digitale Bazen
Text Domain: db-acf-ui
Update URI: https://github.com/yanickvdkorst/DB-ACF-Extension

Icon: assets/icon-128x128.png
Banner: assets/banner-772x250.png
Banner-HighRes: assets/banner-1544x500.png
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ---------------------------
 * Constants
 * ---------------------------
 */
define( 'DB_ACF_UI_VERSION', '1.7.3' );
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
 * Buiten de ACF-check gehouden: updates moeten ook werken als ACF (tijdelijk)
 * niet actief is. Zie classes/updater.php.
 */
\DB_ACF_UI\Updater::register();
