<?php
/*
Plugin Name: DB ACF Extension
Description: Aangepaste ACF interface voor Digitale Bazen
Version: 1.1.8
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
define( 'DB_ACF_UI_VERSION', '1.1.8' );
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

add_filter( 'pre_set_site_transient_update_plugins', function( $transient ) {

    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_slug = plugin_basename( DB_ACF_UI_FILE );

    // Laatste tag hardcoded via API check
    $url = 'https://api.bitbucket.org/2.0/repositories/digitale-bazen/db-acf-extension/refs/tags?sort=-name&pagelen=1';

    $response = wp_remote_get( $url, [
        'headers' => [ 'User-Agent' => 'WordPress' ],
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) {
        return $transient;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ) );

    if ( empty( $data->values[0]->name ) ) {
        return $transient;
    }

    $latest_tag = $data->values[0]->name;
    $remote_version = ltrim( $latest_tag, 'v' );

    if ( version_compare( $remote_version, DB_ACF_UI_VERSION, '>' ) ) {

        $transient->response[ $plugin_slug ] = (object) [
            'slug'        => DB_ACF_UI_SLUG,
            'plugin'      => $plugin_slug,
            'new_version' => $remote_version,
            'url'         => 'https://bitbucket.org/digitale-bazen/db-acf-extension',
            'package'     => 'https://bitbucket.org/digitale-bazen/db-acf-extension/get/' . $latest_tag . '.zip',
        ];
    }

    return $transient;
} );


// Plugin info popup
add_filter( 'plugins_api', function( $res, $action, $args ) {

    if ( $action !== 'plugin_information' || $args->slug !== DB_ACF_UI_SLUG ) {
        return $res;
    }

    $latest_tag = 'v1.1.6'; // fallback, kan ook dynamisch via API

    return (object) [
        'name'          => 'DB ACF Extension',
        'slug'          => DB_ACF_UI_SLUG,
        'version'       => ltrim( $latest_tag, 'v' ),
        'author'        => 'Digitale Bazen',
        'homepage'      => 'https://bitbucket.org/digitale-bazen/db-acf-extension',
        'download_link' => 'https://bitbucket.org/digitale-bazen/db-acf-extension/get/' . $latest_tag . '.zip',
        'sections'      => [
            'description' => 'Aangepaste ACF interface voor Digitale Bazen.',
            'changelog'   => '', // kan je eventueel hier vullen met changelog uit file
        ],
    ];

}, 20, 3 );