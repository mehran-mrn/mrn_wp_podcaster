<?php
/**
 * Lightweight project autoloader.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve classes in the plugin namespace to include files.
 */
final class Autoloader {
	/**
	 * Register the namespace loader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load an MRN Podcaster class.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	private static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$filename = 'class-' . strtolower( str_replace( array( '\\', '_' ), '-', $relative ) ) . '.php';
		$path     = MRNP_PATH . 'includes/' . $filename;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
