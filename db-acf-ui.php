<?php
/*
Plugin Name: DB ACF Extension
Description: Aangepaste ACF interface voor Digitale Bazen
Version: 1.1.2
Author: Digitale Bazen
Text Domain: db-acf-ui
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants
 */
define( 'DB_ACF_UI_VERSION', '1.1.2' );
define( 'DB_ACF_UI_MIN_PHP_VERSION', '8.0' );

define( 'DB_ACF_UI_FILE', __FILE__ );
define( 'DB_ACF_UI_DIR', plugin_dir_path( __FILE__ ) );
define( 'DB_ACF_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'DB_ACF_UI_SLUG', 'db-acf-extension' );

define( 'DB_ACF_UI_GITHUB_REPO', 'yanickvdkorst/DB-ACF-Extension' );

/**
 * PHP version check
 */
if ( version_compare( PHP_VERSION, DB_ACF_UI_MIN_PHP_VERSION, '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>DB ACF Extension vereist PHP 8.0 of hoger.</p></div>';
	});
	return;
}

/**
 * Autoload
 */
require_once DB_ACF_UI_DIR . 'autoload.php';

/**
 * Init plugin
 */
add_action( 'plugins_loaded', function () {

	if ( ! function_exists( 'acf' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>Advanced Custom Fields is vereist voor DB ACF Extension.</p></div>';
		});
		return;
	}

	\DB_ACF_UI\Main::get_instance();
});

/**
 * ---------------------------
 * GitHub updater
 * ---------------------------
 */

/**
 * Inject update info
 */
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {

	if ( empty( $transient->checked ) ) {
		return $transient;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . DB_ACF_UI_GITHUB_REPO . '/releases/latest',
		[
			'headers' => [
				'User-Agent' => 'WordPress'
			]
		]
	);

	if ( is_wp_error( $response ) ) {
		return $transient;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ) );

	if ( empty( $data->tag_name ) ) {
		return $transient;
	}

	$remote_version = ltrim( $data->tag_name, 'v' );

	if ( version_compare( $remote_version, DB_ACF_UI_VERSION, '>' ) ) {

		$transient->response[ plugin_basename( DB_ACF_UI_FILE ) ] = (object) [
			'slug'        => DB_ACF_UI_SLUG,
			'plugin'      => plugin_basename( DB_ACF_UI_FILE ),
			'new_version' => $remote_version,
			'url'         => $data->html_url,
			'package'     => sprintf(
				'https://github.com/%s/archive/refs/tags/%s.zip',
				DB_ACF_UI_GITHUB_REPO,
				$data->tag_name
			),
		];
	}

	return $transient;
});

/**
 * Plugin info popup
 */
add_filter( 'plugins_api', function ( $res, $action, $args ) {

	if ( $action !== 'plugin_information' ) {
		return $res;
	}

	if ( $args->slug !== DB_ACF_UI_SLUG ) {
		return $res;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . DB_ACF_UI_GITHUB_REPO . '/releases/latest',
		[
			'headers' => [
				'User-Agent' => 'WordPress'
			]
		]
	);

	if ( is_wp_error( $response ) ) {
		return $res;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ) );

	return (object) [
		'name'        => 'DB ACF Extension',
		'slug'        => DB_ACF_UI_SLUG,
		'version'     => ltrim( $data->tag_name, 'v' ),
		'author'      => 'Digitale Bazen',
		'homepage'    => $data->html_url,
		'download_link' => sprintf(
			'https://github.com/%s/archive/refs/tags/%s.zip',
			DB_ACF_UI_GITHUB_REPO,
			$data->tag_name
		),
		'sections' => [
			'description' => 'Aangepaste ACF interface voor Digitale Bazen.',
			'changelog'   => $data->body ?: 'Geen changelog opgegeven.',
		],
	];
}, 20, 3 );