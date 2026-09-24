<?php
/**
 * Uninstall: removes the plugin's options, transients and lock rows.
 *
 * Order data (Payrails execution ids and notes on orders) is kept: it is part of the
 * store's payment records. Credentials were never stored in the database.
 *
 * @package PayrailsWoo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'woocommerce_payrails_settings' );
delete_option( 'payrails_woo_install_id' );

// Transients (client-init cache, access token) and per-order lock rows.
foreach ( array( '_transient_payrails_woo_%', '_transient_timeout_payrails_woo_%', 'payrails_woo_lock_%' ) as $pattern ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", str_replace( '_', '\_', substr( $pattern, 0, -1 ) ) . '%' ) );
}
wp_cache_flush();
