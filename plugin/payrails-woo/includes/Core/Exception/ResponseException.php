<?php
/**
 * A 2xx whose body we cannot use (non-JSON, no data, no execution id).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Exception;

/**
 * Unusable response.
 */
final class ResponseException extends PayrailsException {

	/**
	 * Short reason, for example "no_execution_id".
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason Reason label.
	 */
	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( 'Payrails response unusable: ' . $reason );
	}

	/**
	 * Public code.
	 */
	public function public_code(): string {
		return 'PR-RESPONSE';
	}
}
