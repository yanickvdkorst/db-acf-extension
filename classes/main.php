<?php

namespace DB_ACF_UI;

trait Singleton {
    private static $instance;

    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    final private function __construct() {
        $this->init();
    }
}

class Main {
    use Singleton;

    protected function init() {
        // Admin assets
        add_action( 'acf/input/admin_enqueue_scripts', [ $this, 'register_assets' ], 1 );
        add_action( 'acf/input/admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Output layout images in admin footer
        add_action( 'acf/input/admin_footer', [ $this, 'layouts_images_style' ], 20 );

        // Vet-knop op tekstvelden (opt-in per veld)
        ( new Bold_Toolbar() )->register();
    }

    public function register_assets() {
        wp_register_style(
            'db-acf-ui-admin',
            DB_ACF_UI_URL . 'assets/css/db-acf-ui.css',
            [],
            DB_ACF_UI_VERSION
        );

        wp_register_script(
            'db-acf-ui-admin-js',
            DB_ACF_UI_URL . 'assets/js/db-acf-ui.js',
            [ 'jquery' ],
            DB_ACF_UI_VERSION,
            true
        );

        wp_register_style(
            'db-acf-ui-bold',
            DB_ACF_UI_URL . 'assets/css/db-acf-bold.css',
            [],
            DB_ACF_UI_VERSION
        );

        wp_register_script(
            'db-acf-ui-bold-js',
            DB_ACF_UI_URL . 'assets/js/db-acf-bold.js',
            [ 'jquery' ],
            DB_ACF_UI_VERSION,
            true
        );
    }

    public function enqueue_assets() {
        wp_enqueue_style( 'db-acf-ui-admin' );
        wp_enqueue_script( 'db-acf-ui-admin-js' );

        wp_enqueue_style( 'db-acf-ui-bold' );
        wp_enqueue_script( 'db-acf-ui-bold-js' );
    }

    public function layouts_images_style() {
        $images = $this->get_layouts_images();
        if ( empty( $images ) ) return;

        echo "<style>\n";
        foreach ( $images as $layout_key => $image_url ) {
            if ( $image_url ) {
                echo sprintf(
                    ".acf-fc-popup ul li a[data-layout=\"%s\"] .acf-fc-popup-image { background-image: url('%s'); }\n",
                    esc_attr($layout_key),
                    esc_url($image_url)
                );
            }
        }
        echo "</style>\n";
    }

    public function get_layouts_images() {
        $flexibles = $this->retrieve_flexible_keys();
        $layouts_images = [];

        if ( empty( $flexibles ) ) return [];

        foreach ( $flexibles as $flexible ) {
            $layouts_images[ $flexible ] = $this->locate_image( $flexible );
        }

        return apply_filters( 'acf-flexible-content-extended.images', $layouts_images );
    }

    public function locate_image( $layout ) {
        if ( empty( $layout ) ) return false;

        $path = apply_filters( 'acf-flexible-content-extended.images_path', 'lib/admin/images/acf-flexible-content-extended' );
        $layout = str_replace('_', '-', $layout);

        $extensions = ['jpg','png'];
        foreach ($extensions as $ext) {
            $image_path = get_stylesheet_directory() . '/' . $path . '/' . $layout . '.' . $ext;
            $image_uri  = get_stylesheet_directory_uri() . '/' . $path . '/' . $layout . '.' . $ext;

            if ( is_file( $image_path ) ) {
                error_log("FCE Image found for $layout: $image_uri");
                return $image_uri;
            } else {
                error_log("FCE Image NOT found for $layout: $image_path");
            }
        }

        return false;
    }

    public function retrieve_flexible_keys() {
        $keys   = [];
        $groups = acf_get_field_groups();

        if ( empty( $groups ) ) return $keys;

        foreach ( $groups as $group ) {
            $fields = (array) acf_get_fields( $group );
            if ( ! empty( $fields ) ) {
                $this->retrieve_flexible_keys_from_fields( $fields, $keys );
            }
        }

        return $keys;
    }

    protected function retrieve_flexible_keys_from_fields( $fields, &$keys ) {
        foreach ( $fields as $field ) {
            if ( in_array( $field['type'], ['repeater','group'], true ) ) {
                $subFields = acf_get_fields( $field );
                $this->retrieve_flexible_keys_from_fields( $subFields, $keys );
            } elseif ( 'flexible_content' === $field['type'] ) {
                foreach ( $field['layouts'] as $layout_field ) {
                    $field_identifier = $field['key'] . '-' . $layout_field['key'];
                    if ( ! empty( $keys[ $field_identifier ] ) ) continue;

                    $keys[ $field_identifier ] = $layout_field['name'];

                    if ( ! empty( $layout_field['sub_fields'] ) ) {
                        $this->retrieve_flexible_keys_from_fields( $layout_field['sub_fields'], $keys );
                    }
                }
            }
        }
    }
}