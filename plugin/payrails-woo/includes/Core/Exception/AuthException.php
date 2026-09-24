<?php
/**
 * Token request failed, or an authed call answered 401 twice.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Exception;

/**
 * Auth failure.
 */
final class AuthException extends PayrailsException {

	/**
	 * HTTP status seen.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Constructor.
	 *
	 * @param int $status HTTP status.
	 */
	public function __construct( int $status ) {
		$this->status = $status;
		parent::__construct( 'Payrails authentication failed (' . $status . ')' );
	}

	/**
	 * Public code.
	 */
	public function public_code(): string {
		return 'PR-AUTH-' . $this->status;
	}
}
