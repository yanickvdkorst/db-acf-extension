<?php
/*
Plugin Name: DB ACF Extension
Description: Aangepaste ACF interface voor Digitale Bazen
Version: 1.9.3
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
define( 'DB_ACF_UI_VERSION', '1.9.3' );
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
 * Instellingen
 * ---------------------------
 * Buiten de ACF-check gehouden: de pagina moet ook bereikbaar zijn als ACF
 * (tijdelijk) niet actief is, al was het maar om onderdelen uit te zetten.
 */
( new \DB_ACF_UI\Settings() )->register();

/**
 * ---------------------------
 * CF7 healthcheck
 * ---------------------------
 * Buiten de ACF-check gehouden: het testen van formulieren heeft niets met ACF
 * te maken. Op after_setup_theme, want pas dan is de functions.php van het thema
 * geladen en kunnen we zien of dáár de oude healthcheck nog staat. Zo ja, dan
 * houdt de plugin zich stil en blijft die versie draaien — anders zouden er twee
 * healthchecks naast elkaar lopen en krijg je dubbele mails.
 */
add_action( 'after_setup_theme', function () {
    if ( ! \DB_ACF_UI\Settings::enabled( 'cf7_healthcheck' ) ) {
        return; // uitgezet onder Instellingen → DB ACF Extensie
    }

    if ( function_exists( 'db_run_cf7_test' ) ) {
        return; // het thema doet het nog; daar eerst weghalen
    }

    ( new \DB_ACF_UI\CF7_Healthcheck() )->register();
}, 20 );

register_deactivation_hook( __FILE__, [ '\DB_ACF_UI\CF7_Healthcheck', 'unschedule' ] );

/**
 * ---------------------------
 * GitHub updater
 * ---------------------------
 * Buiten de ACF-check gehouden: updates moeten ook werken als ACF (tijdelijk)
 * niet actief is. Zie classes/updater.php.
 */
\DB_ACF_UI\Updater::register();
