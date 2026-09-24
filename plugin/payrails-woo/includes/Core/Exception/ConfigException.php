<?php
/**
 * Missing or unreadable configuration.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Exception;

/**
 * Names the missing keys (never their values).
 */
final class ConfigException extends PayrailsException {

	/**
	 * Key names or problems, for example ["PAYRAILS_CLIENT_ID", "cert not readable"].
	 *
	 * @var string[]
	 */
	public array $problems;

	/**
	 * Constructor.
	 *
	 * @param string[] $problems Key names / problem labels (no values).
	 */
	public function __construct( array $problems ) {
		$this->problems = array_values( $problems );
		parent::__construct( 'Payrails configuration incomplete: ' . implode( ', ', $this->problems ) );
	}

	/**
	 * Public code.
	 */
	public function public_code(): string {
		return 'PR-CONFIG';
	}

	/**
	 * Confirm status.
	 */
	public function confirm_status(): int {
		return 503;
	}
}
