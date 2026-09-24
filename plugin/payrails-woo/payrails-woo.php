<?php
/**
 * Plugin Name:       Payrails for WooCommerce (example)
 * Description:       Card payments (incl. 3D Secure) with the Payrails Web SDK v6 Drop-in on the WooCommerce pay-for-order page. Example integration for Payrails staging.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
 * Author:            Payrails
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       payrails-woo
 * WC requires at least: 9.0
 * WC tested up to:   11.1
 *
 * @package PayrailsWoo
 */

defined( 'ABSPATH' ) || exit;

const PAYRAILS_WOO_FILE    = __FILE__;
const PAYRAILS_WOO_VERSION = '0.1.0';

require __DIR__ . '/includes/autoload.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAYRAILS_WOO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PAYRAILS_WOO_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( \PayrailsWoo\Woo\Plugin::class, 'boot' ), 20 );
