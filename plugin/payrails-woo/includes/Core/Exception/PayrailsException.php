<?php
/**
 * Base exception. Messages never contain secrets, tokens or raw response bodies.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Exception;

/**
 * Every Payrails failure maps to a short public code (for example PR-AUTH-401)
 * that is safe to show on the pay page and in order notes.
 */
abstract class PayrailsException extends \RuntimeException {

	/**
	 * Public, secret-free reference code.
	 */
	abstract public function public_code(): string;

	/**
	 * HTTP status the confirm endpoint answers with for this failure.
	 */
	public function confirm_status(): int {
		return 502;
	}
}
