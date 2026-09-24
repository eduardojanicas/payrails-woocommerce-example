<?php
/**
 * Registers every hook.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin.
 */
final class Plugin {

	/**
	 * Boots after WooCommerce has loaded.
	 */
	public static function boot(): void {
		if ( ! class_exists( '\WC_Payment_Gateway' ) ) {
			return;
		}
		// Payrails integration — step 1: register the gateway (classic) and the Checkout-block payment method.
		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = Gateway::class;
				return $gateways;
			}
		);
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( PaymentMethodRegistry $registry ) {
				$registry->register( new BlocksIntegration() );
			}
		);
		add_action( 'woocommerce_receipt_payrails', array( PayPage::class, 'render_receipt' ) );
		add_action( 'template_redirect', array( PayPage::class, 'precheck' ), 5 );
		add_action( 'wc_ajax_payrails_confirm', array( ConfirmController::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PAYRAILS_WOO_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Admin notice when the configuration is incomplete: key names only, never values.
	 * The gateway is then hidden at checkout and the pay page shows a safe error.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$problems = Services::config_problems();
		if ( $problems ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Payrails is not configured:', 'payrails-woo' ) . '</strong> ' . esc_html( implode( ', ', $problems ) ) . '. ' . esc_html__( 'Card payments are hidden at checkout.', 'payrails-woo' ) . '</p></div>';
		}
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ): array {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payrails' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payrails-woo' ) . '</a>' );
		return $links;
	}
}
