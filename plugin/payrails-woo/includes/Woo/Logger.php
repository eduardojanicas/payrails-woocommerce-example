<?php
/**
 * WooCommerce logger wrapper (source "payrails-woo"). Only shapes and codes are logged.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
final class Logger {

	/**
	 * Logs. Info/debug only when the gateway "debug" setting is on; warnings/errors always.
	 *
	 * @param string               $level   PSR-3 level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context (never secrets or tokens).
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( in_array( $level, array( 'debug', 'info', 'notice' ), true ) ) {
			$settings = get_option( 'woocommerce_payrails_settings', array() );
			if ( ! is_array( $settings ) || 'yes' !== ( $settings['debug'] ?? 'no' ) ) {
				return;
			}
		}
		$line = $message . ( $context ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '' );
		wc_get_logger()->log( $level, $line, array( 'source' => 'payrails-woo' ) );
	}

	/**
	 * Callable for Core\Api\PayrailsClient.
	 */
	public static function callable(): callable {
		return static function ( string $level, string $message, array $context ): void {
			self::log( $level, $message, $context );
		};
	}
}
