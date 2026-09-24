<?php
/**
 * Tiny PSR-4 autoloader: PayrailsWoo\X\Y -> includes/X/Y.php. No Composer.
 *
 * Deliberately has no ABSPATH guard: the unit tests load it without WordPress.
 * It only registers an autoloader and has no side effects.
 *
 * @package PayrailsWoo
 */

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'PayrailsWoo\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);
