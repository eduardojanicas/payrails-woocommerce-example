<?php
/**
 * Checks that an execution really belongs to this order before we act on it.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Verifier. Returns null when every rule passes, else a short reason code.
 */
final class ExecutionVerifier {

	/**
	 * Is this an execution id we created and stored for this order? (Checked
	 * BEFORE any read: a browser can only name an id our server created.)
	 *
	 * @param string   $requested_id Id from the request.
	 * @param string[] $known_ids    Ids stored on the order.
	 */
	public static function is_known( string $requested_id, array $known_ids ): bool {
		return '' !== $requested_id && in_array( $requested_id, $known_ids, true );
	}

	/**
	 * Verifies a mapped execution against the order as it is now.
	 *
	 * @param ExecutionResult $r            Mapped execution.
	 * @param string          $requested_id Id we asked for.
	 * @param string[]        $known_ids    Ids stored on the order.
	 * @param OrderSnapshot   $s            Order snapshot (current total).
	 */
	public static function verify( ExecutionResult $r, string $requested_id, array $known_ids, OrderSnapshot $s ): ?string {
		if ( ! self::is_known( $requested_id, $known_ids ) ) {
			return 'unknown_execution';
		}
		if ( null === $r->id || $r->id !== $requested_id ) {
			return 'id_mismatch';
		}
		if ( null === $r->merchant_reference || $r->merchant_reference !== $s->order_number ) {
			return 'reference_mismatch';
		}
		if ( null === $r->amount_currency || strtoupper( $r->amount_currency ) !== $s->currency ) {
			return 'currency_mismatch';
		}
		if ( null === $r->amount_value ) {
			return 'amount_mismatch';
		}
		try {
			$minor = Money::to_minor( $r->amount_value, $s->exponent() );
		} catch ( \InvalidArgumentException $e ) {
			return 'amount_invalid';
		}
		if ( $minor !== $s->amount_minor ) {
			return 'amount_mismatch';
		}
		return null;
	}
}
