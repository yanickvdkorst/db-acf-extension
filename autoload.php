<?php

namespace DB_ACF_UI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Autoloader {
	protected $prefixes = array();

	public function register() {
		spl_autoload_register( array( $this, 'loadClass' ) );
	}

	public function addNamespace( $prefix, $base_dir, $prepend = false ) {
		$prefix = trim( $prefix, '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, DIRECTORY_SEPARATOR ) . '/';

		if ( ! isset( $this->prefixes[ $prefix ] ) ) {
			$this->prefixes[ $prefix ] = array();
		}

		if ( $prepend ) {
			array_unshift( $this->prefixes[ $prefix ], $base_dir );
		} else {
			array_push( $this->prefixes[ $prefix ], $base_dir );
		}
	}

	public function loadClass( $class ) {
		$prefix = $class;
		while ( false !== $pos = strrpos( $prefix, '\\' ) ) {
			$prefix = substr( $class, 0, $pos + 1 );
			$relative_class = substr( $class, $pos + 1 );

			if ( $mapped_file = $this->loadMappedFile( $prefix, $relative_class ) ) {
				return $mapped_file;
			}

			$prefix = rtrim( $prefix, '\\' );
		}
		return false;
	}

	protected function loadMappedFile( $prefix, $relative_class ) {
		if ( ! isset( $this->prefixes[ $prefix ] ) ) {
			return false;
		}

		foreach ( $this->prefixes[ $prefix ] as $base_dir ) {
			$file = $base_dir . strtolower( str_replace( array( '\\', '_' ), array( '/', '-' ), $relative_class ) ) . '.php';
			if ( $this->requireFile( $file ) ) {
				return $file;
			}
		}

		return false;
	}

	protected function requireFile( $file ) {
		if ( file_exists( $file ) ) {
			require $file;
			return true;
		}
		return false;
	}
}

// Instantiate en register de loader
$loader = new \DB_ACF_UI\Autoloader;
$loader->register();

// Gebruik de **globale constant** met backslash
$loader->addNamespace( 'DB_ACF_UI', __DIR__ . '/classes' );