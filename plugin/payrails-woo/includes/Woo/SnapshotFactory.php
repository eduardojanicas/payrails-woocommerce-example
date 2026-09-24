<?php
/**
 * WC_Order → Core\OrderSnapshot, plus the client context for client-init.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use PayrailsWoo\Core\HolderReference;
use PayrailsWoo\Core\Money;
use PayrailsWoo\Core\OrderSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Factory.
 */
final class SnapshotFactory {

	/**
	 * Builds a snapshot of the order as it is now.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function from( \WC_Order $order ): OrderSnapshot {
		$currency = $order->get_currency();
		$exp      = Money::exponent( $currency );
		$minor    = static function ( $v ) use ( $exp ): int {
			return Money::to_minor( wc_format_decimal( (string) $v ), $exp );
		};

		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			/** Line item. @var \WC_Order_Item_Product $item */
			$lines[] = array(
				'id'          => 'item-' . $item_id,
				'name'        => wp_strip_all_tags( $item->get_name() ),
				'quantity'    => (int) $item->get_quantity(),
				'total_minor' => $minor( $item->get_total() ) + $minor( $item->get_total_tax() ),
			);
		}
		$shipping = $minor( $order->get_shipping_total() ) + $minor( $order->get_shipping_tax() );
		if ( $shipping > 0 ) {
			$lines[] = array(
				'id'          => 'shipping',
				'name'        => __( 'Shipping', 'payrails-woo' ),
				'quantity'    => 1,
				'total_minor' => $shipping,
			);
		}
		foreach ( $order->get_fees() as $fee_id => $fee ) {
			$lines[] = array(
				'id'          => 'fee-' . $fee_id,
				'name'        => wp_strip_all_tags( $fee->get_name() ),
				'quantity'    => 1,
				'total_minor' => $minor( $fee->get_total() ) + $minor( $fee->get_total_tax() ),
			);
		}

		$email  = (string) $order->get_billing_email();
		$holder = self::holder_reference( $order );

		return new OrderSnapshot(
			(int) $order->get_id(),
			(string) $order->get_order_number(),
			$minor( $order->get_total() ),
			$currency,
			$lines,
			$holder,
			$email,
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			(string) $order->get_billing_country(),
			/**
			 * Filters the order description sent to Payrails in client-init.
			 *
			 * @param string    $description Default "Order #{number}".
			 * @param \WC_Order $order       Order.
			 */
			(string) apply_filters( 'payrails_woo_order_description', sprintf( /* translators: %s: order number */ __( 'Order #%s', 'payrails-woo' ), $order->get_order_number() ), $order )
		);
	}

	/**
	 * Payrails holder for this order. Both kinds are random and never derived from
	 * personal data or internal ids:
	 * - logged-in customer: one reference per WordPress user, kept in user meta, so the
	 *   holder is stable across that customer's orders;
	 * - guest: one reference per order, kept in order meta, never derived from the
	 *   (unverified) e-mail, so a returning guest is a new holder with no stored cards.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function holder_reference( \WC_Order $order ): string {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			$ref = (string) get_user_meta( $user_id, '_payrails_holder_reference', true );
			if ( ! HolderReference::is_customer( $ref ) ) {
				$ref = HolderReference::customer();
				// add_user_meta( unique ) so two concurrent first orders cannot create two holders.
				if ( ! add_user_meta( $user_id, '_payrails_holder_reference', $ref, true ) ) {
					$stored = (string) get_user_meta( $user_id, '_payrails_holder_reference', true );
					if ( HolderReference::is_customer( $stored ) ) {
						$ref = $stored;
					} else {
						update_user_meta( $user_id, '_payrails_holder_reference', $ref );
					}
				}
			}
			return $ref;
		}
		$ref = (string) $order->get_meta( '_payrails_holder_reference' );
		if ( ! HolderReference::is_guest( $ref ) ) {
			$ref = HolderReference::guest();
			$order->update_meta_data( '_payrails_holder_reference', $ref );
			$order->save_meta_data();
		}
		return $ref;
	}

	/**
	 * True when the order has no WordPress account behind it.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function is_guest( \WC_Order $order ): bool {
		return (int) $order->get_customer_id() <= 0;
	}

	/**
	 * Client context for client-init. Origin comes from home_url(), never the request.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, string>
	 */
	public static function client_context( \WC_Order $order ): array {
		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		// REMOTE_ADDR only: X-Forwarded-For / X-Real-IP are client-controlled here.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return array(
			'ipAddress' => false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
			'origin'    => $origin,
			'userAgent' => $ua,
			'returnUrl' => $order->get_checkout_payment_url( true ),
		);
	}
}
