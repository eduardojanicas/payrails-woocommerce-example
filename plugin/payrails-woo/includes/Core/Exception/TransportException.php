<?php
/**
 * Network-level failure (DNS, TLS, timeout).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Exception;

/**
 * cURL error.
 */
final class TransportException extends PayrailsException {

	/**
	 * cURL errno.
	 *
	 * @var int
	 */
	public int $errno;

	/**
	 * Constructor.
	 *
	 * The cURL error text is deliberately NOT kept: it can contain local file paths
	 * (for example of the client certificate and key). Only the errno is recorded.
	 *
	 * @param int    $errno  cURL errno.
	 * @param string $detail Ignored (kept for call-site compatibility).
	 */
	public function __construct( int $errno, string $detail = '' ) {
		$this->errno = $errno;
		unset( $detail );
		parent::__construct( 'curl:' . $errno );
	}

	/**
	 * Public code.
	 */
	public function public_code(): string {
		return 'PR-NET-' . $this->errno;
	}
}
