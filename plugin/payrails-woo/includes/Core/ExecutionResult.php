<?php
/**
 * Mapped execution state.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Result of ExecutionMapper::map().
 */
final class ExecutionResult {

	public const AUTHORIZED = 'authorized';
	public const FAILED     = 'failed';
	public const PENDING    = 'pending';

	/**
	 * State.
	 *
	 * @var string
	 */
	public string $state;

	/**
	 * Code that decided the state (last success code, or the last code).
	 *
	 * @var string|null
	 */
	public ?string $last_code;

	/**
	 * All status codes in order.
	 *
	 * @var string[]
	 */
	public array $codes;

	/**
	 * True when anything beyond "created" happened.
	 *
	 * @var bool
	 */
	public bool $attempted;

	/**
	 * Execution id as returned.
	 *
	 * @var string|null
	 */
	public ?string $id;

	/**
	 * Merchant reference as returned.
	 *
	 * @var string|null
	 */
	public ?string $merchant_reference;

	/**
	 * Amount value as returned (decimal string).
	 *
	 * @var string|null
	 */
	public ?string $amount_value;

	/**
	 * Amount currency as returned.
	 *
	 * @var string|null
	 */
	public ?string $amount_currency;

	/**
	 * True when a capture succeeded (auto-capture workflow).
	 *
	 * @var bool
	 */
	public bool $captured;

	/**
	 * Pending customer action (3DS) URL, validated to be https on *.payrails.io, or null.
	 *
	 * @var string|null
	 */
	public ?string $action_url = null;

	/**
	 * Provider/Payrails reason for a failure (for example "GenericRejection"), or null.
	 *
	 * @var string|null
	 */
	public ?string $failure_reason = null;

	/**
	 * Constructor.
	 *
	 * @param string      $state              State.
	 * @param string|null $last_code          Code.
	 * @param string[]    $codes              Codes.
	 * @param bool        $attempted          Attempted.
	 * @param string|null $id                 Id.
	 * @param string|null $merchant_reference Ref.
	 * @param string|null $amount_value       Value.
	 * @param string|null $amount_currency    Currency.
	 * @param bool        $captured           Captured.
	 */
	public function __construct( string $state, ?string $last_code, array $codes, bool $attempted, ?string $id, ?string $merchant_reference, ?string $amount_value, ?string $amount_currency, bool $captured = false ) {
		$this->state              = $state;
		$this->last_code          = $last_code;
		$this->codes              = $codes;
		$this->attempted          = $attempted;
		$this->id                 = $id;
		$this->merchant_reference = $merchant_reference;
		$this->amount_value       = $amount_value;
		$this->amount_currency    = $amount_currency;
		$this->captured           = $captured;
	}

	/**
	 * Authorized?
	 */
	public function is_authorized(): bool {
		return self::AUTHORIZED === $this->state;
	}
}
