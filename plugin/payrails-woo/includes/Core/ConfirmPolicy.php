<?php
/**
 * What to do with an order given a mapped + verified execution.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Pure decision table: authorized + verified → complete; authorized + mismatch → review; failed → fail; else pending.
 */
final class ConfirmPolicy {

	public const COMPLETE = 'complete';
	public const FAIL     = 'fail';
	public const REVIEW   = 'review';
	public const PENDING  = 'pending';

	/**
	 * Decides.
	 *
	 * @param ExecutionResult $r        Mapped execution.
	 * @param string|null     $mismatch Verifier reason, or null when verified.
	 */
	public static function decide( ExecutionResult $r, ?string $mismatch ): string {
		if ( $r->is_authorized() ) {
			return null === $mismatch ? self::COMPLETE : self::REVIEW;
		}
		if ( in_array( $mismatch, array( 'unknown_execution', 'id_mismatch' ), true ) ) {
			// Not provably ours: never change the order on it.
			return self::REVIEW;
		}
		return ExecutionResult::FAILED === $r->state ? self::FAIL : self::PENDING;
	}
}
