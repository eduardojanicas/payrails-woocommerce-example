<?php
/**
 * 5xx or 429 from Payrails.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Exception;

/**
 * Upstream unavailable or throttling.
 */
final class UpstreamException extends PayrailsException {

	/**
	 * HTTP status.
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
		parent::__construct( 'Payrails upstream error (' . $status . ')' );
	}

	/**
	 * Public code.
	 */
	public function public_code(): string {
		return 'PR-UPSTREAM-' . $this->status;
	}
}
