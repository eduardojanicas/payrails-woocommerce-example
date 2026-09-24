<?php
/**
 * Everything the Payrails calls need from an order, with no WooCommerce types.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Immutable DTO. Built by Woo\SnapshotFactory, consumed by ClientInitBuilder and ExecutionVerifier.
 */
final class OrderSnapshot {

	/**
	 * Order id.
	 *
	 * @var int
	 */
	public int $order_id;

	/**
	 * Order number (merchantReference).
	 *
	 * @var string
	 */
	public string $order_number;

	/**
	 * Order total in minor units.
	 *
	 * @var int
	 */
	public int $amount_minor;

	/**
	 * ISO currency.
	 *
	 * @var string
	 */
	public string $currency;

	/**
	 * Raw lines: list of {id, name, quantity, total_minor}. total_minor is the line
	 * total incl. tax after discounts.
	 *
	 * @var array<int, array{id:string, name:string, quantity:int, total_minor:int}>
	 */
	public array $lines;

	/**
	 * Holder reference.
	 *
	 * @var string
	 */
	public string $holder_reference;

	/**
	 * Billing email.
	 *
	 * @var string
	 */
	public string $email;

	/**
	 * Billing full name.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Billing country (ISO alpha-2).
	 *
	 * @var string
	 */
	public string $country;

	/**
	 * Human description.
	 *
	 * @var string
	 */
	public string $description;

	/**
	 * Constructor.
	 *
	 * @param int                                                                    $order_id         Id.
	 * @param string                                                                 $order_number     Number.
	 * @param int                                                                    $amount_minor     Amount.
	 * @param string                                                                 $currency         Currency.
	 * @param array<int, array{id:string, name:string, quantity:int, total_minor:int}> $lines            Lines.
	 * @param string                                                                 $holder_reference Holder ref.
	 * @param string                                                                 $email            Email.
	 * @param string                                                                 $name             Name.
	 * @param string                                                                 $country          Country.
	 * @param string                                                                 $description      Description.
	 */
	public function __construct( int $order_id, string $order_number, int $amount_minor, string $currency, array $lines, string $holder_reference, string $email = '', string $name = '', string $country = '', string $description = '' ) {
		$this->order_id         = $order_id;
		$this->order_number     = $order_number;
		$this->amount_minor     = $amount_minor;
		$this->currency         = strtoupper( $currency );
		$this->lines            = array_values( $lines );
		$this->holder_reference = $holder_reference;
		$this->email            = $email;
		$this->name             = $name;
		$this->country          = strtoupper( $country );
		$this->description      = $description;
	}

	/**
	 * Currency exponent.
	 */
	public function exponent(): int {
		return Money::exponent( $this->currency );
	}

	/**
	 * Fingerprint of what the execution was created for. A change forces a new init.
	 *
	 * @param string $workflow_code Workflow code.
	 */
	public function fingerprint( string $workflow_code ): string {
		return sha1( $this->amount_minor . '|' . $this->currency . '|' . $workflow_code );
	}
}
