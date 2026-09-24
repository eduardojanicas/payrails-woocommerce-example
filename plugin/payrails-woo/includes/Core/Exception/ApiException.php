<?php
/**
 * A 4xx (other than 401) from the Payrails API.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Exception;

/**
 * Request rejected by Payrails. Carries Payrails' own error code (not the body).
 */
final class ApiException extends PayrailsException {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Payrails error code from the body, if any (for example "request.header.missing").
	 *
	 * @var string|null
	 */
	public ?string $payrails_code;

	/**
	 * Constructor.
	 *
	 * @param int         $status        HTTP status.
	 * @param string|null $payrails_code Payrails error code.
	 * @param string      $operation     Operation label.
	 */
	public function __construct( int $status, ?string $payrails_code, string $operation = 'request' ) {
		$this->status        = $status;
		$this->payrails_code = $payrails_code;
		parent::__construct( 'Payrails ' . $operation . ' rejected (' . $status . ( $payrails_code ? ', ' . $payrails_code : '' ) . ')' );
	}

	/**
	 * Public code.
	 */
	public function public_code(): string {
		return 'PR-API-' . $this->status;
	}
}
