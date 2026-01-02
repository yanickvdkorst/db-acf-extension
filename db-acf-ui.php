<?php
/*
Plugin Name: DB ACF Extension
Description: Aangepaste ACF interface voor Digitale Bazen
Version: 1.2.4
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
define( 'DB_ACF_UI_VERSION', '1.2.4' );
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




/**
 * Forceer vaste pluginmapnaam na uitpakken van Bitbucket ZIP (rename binnen $remote_source).
 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra) {

    // Alleen ingrijpen bij plugin install/update
    if (!isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return $source;
    }

    // Optioneel: grijp alleen in voor deze specifieke plugin/update-call
    // - bij bulk updates staat 'plugins' (array) in $hook_extra
    // - bij single update staat 'plugin' (string) in $hook_extra
    $target_basename = plugin_basename(DB_ACF_UI_FILE); // bijv. 'db-acf-extension/db-acf-ui.php'
    $targets = [];
    if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
        $targets = $hook_extra['plugins'];
    } elseif (!empty($hook_extra['plugin'])) {
        $targets = [$hook_extra['plugin']];
    }
    if ($targets && !in_array($target_basename, $targets, true)) {
        // Niet onze plugin → niet ingrijpen
        return $source;
    }

    // Gewenste vaste directorynaam voor de root van je ZIP
    $desired_folder_name = 'db-acf-extension';

    global $wp_filesystem;
    if (!$wp_filesystem) {
        return $source;
    }

    // Als de huidige bronmap al de gewenste naam heeft → niets doen
    if (basename($source) === $desired_folder_name) {
        return $source;
    }

    // Hernoem binnen de tijdelijke unpack-locatie
    // Voorbeeld: $remote_source = '/.../wp-content/upgrade/db-acf-extension-<hash>/'
    //            $source        = '/.../wp-content/upgrade/db-acf-extension-<hash>/digitale-bazen-db-acf-extension-<hash>/'
    $new_source = trailingslashit($remote_source) . $desired_folder_name . '/';

    // Bestaat er al een map met de gewenste naam in $remote_source? → verwijder om ruimte te maken
    if ($wp_filesystem->is_dir($new_source)) {
        $wp_filesystem->delete($new_source, true);
    }

    // Verplaats/rename de uitgepakte bronmap naar de vaste naam binnen $remote_source
    $moved = $wp_filesystem->move($source, $new_source, true);

    if ($moved) {
        // Heel belangrijk: retourneer de NIEUWE bronlocatie binnen $remote_source
        return $new_source;
    }

    // Fallback: doe niets als verplaatsen faalt
    return $source;

}, 20, 4);
