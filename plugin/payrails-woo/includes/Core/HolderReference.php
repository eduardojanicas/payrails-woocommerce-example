<?php
/**
 * Stable, non-reversible holder references.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Holder references for customers and guests.
 */
final class HolderReference {

	/**
	 * Logged-in customer: a RANDOM reference, generated once per WordPress user and
	 * stored in user meta, so the Payrails holder is stable across that customer's
	 * orders (stored cards) without exposing the internal user id.
	 */
	public static function customer(): string {
		return 'wc-customer-' . str_replace( '-', '', Uuid::v4() );
	}

	/**
	 * True for a reference produced by customer().
	 *
	 * @param string $ref Reference.
	 */
	public static function is_customer( string $ref ): bool {
		return 1 === preg_match( '/^wc-customer-[0-9a-f]{32}$/', $ref );
	}

	/**
	 * Guest: a RANDOM reference, generated once per order and stored on it.
	 *
	 * Never derived from the e-mail: a guest's e-mail is unverified, so an
	 * e-mail-derived holder would let anyone who knows a victim's address become
	 * the same Payrails holder and see or use their stored cards.
	 */
	public static function guest(): string {
		return 'wc-guest-' . str_replace( '-', '', Uuid::v4() );
	}

	/**
	 * True for a reference produced by guest().
	 *
	 * @param string $ref Reference.
	 */
	public static function is_guest( string $ref ): bool {
		return 1 === preg_match( '/^wc-guest-[0-9a-f]{32}$/', $ref );
	}
}
