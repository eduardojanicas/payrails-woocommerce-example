<?php
/**
 * Registers "payrails" as a Checkout block payment method (no fields).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks integration. Place order goes through the Store API to process_payment().
 */
final class BlocksIntegration extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = 'payrails';

	/**
	 * Loads settings.
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_payrails_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Active when the gateway is available.
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways['payrails'] ?? null;
		return $gateway && $gateway->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'payrails-woo-blocks',
			plugins_url( 'assets/js/blocks.js', PAYRAILS_WOO_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			PAYRAILS_WOO_VERSION,
			true
		);
		return array( 'payrails-woo-blocks' );
	}

	/**
	 * Data exposed as wc.wcSettings.getPaymentMethodData('payrails').
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Card', 'payrails-woo' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'buttonLabel' => __( 'Continue to payment', 'payrails-woo' ),
			'supports'    => array( 'products' ),
		);
	}
}
