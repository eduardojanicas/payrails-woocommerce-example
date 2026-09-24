<?php
/**
 * ExecutionVerifier + ConfirmPolicy: an execution must be ours, for this order, for this amount.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests;

use PayrailsWoo\Core\ConfirmPolicy;
use PayrailsWoo\Core\ExecutionMapper;
use PayrailsWoo\Core\ExecutionVerifier;
use PayrailsWoo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ExecutionVerifierTest extends TestCase {

	private const KNOWN = array( 'exec-0', 'exec-1' );

	private function verify( array $ex, string $requested = 'exec-1', array $known = self::KNOWN, int $amount = 23000 ): ?string {
		return ExecutionVerifier::verify( ExecutionMapper::map( $ex ), $requested, $known, Fixtures::snapshot( $amount ) );
	}

	public function test_valid_execution_passes(): void {
		$this->assertNull( $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ) ) ) );
	}

	public function test_decimal_representation_differences_are_accepted(): void {
		$this->assertNull( $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-1', '230.0' ) ) );
		$this->assertNull( $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-1', '230' ) ) );
	}

	public function test_unknown_execution_is_rejected(): void {
		$this->assertSame( 'unknown_execution', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-x' ), 'exec-x' ) );
		$this->assertSame( 'unknown_execution', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ) ), 'exec-1', array() ) );
		$this->assertFalse( ExecutionVerifier::is_known( '', array( '' ) ) );
	}

	public function test_returned_id_must_equal_requested_id(): void {
		$this->assertSame( 'id_mismatch', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-0' ), 'exec-1' ) );
	}

	public function test_merchant_reference_mismatch_is_rejected(): void {
		$this->assertSame( 'reference_mismatch', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-1', '230.00', 'USD', '1043' ) ) );
		$ex = Fixtures::execution( array( 'authorizeSuccessful' ) );
		unset( $ex['merchantReference'] );
		$this->assertSame( 'reference_mismatch', $this->verify( $ex ) );
	}

	public function test_amount_mismatch_is_rejected(): void {
		$this->assertSame( 'amount_mismatch', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-1', '229.99' ) ) );
		// Order total changed after init (amount now 231.00): must not be paid.
		$this->assertSame( 'amount_mismatch', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ) ), 'exec-1', self::KNOWN, 23100 ) );
		$this->assertSame( 'amount_invalid', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-1', 'abc' ) ) );
		$ex = Fixtures::execution( array( 'authorizeSuccessful' ) );
		unset( $ex['amount']['value'] );
		$this->assertSame( 'amount_mismatch', $this->verify( $ex ) );
	}

	public function test_currency_mismatch_is_rejected_and_case_is_normalised(): void {
		$this->assertSame( 'currency_mismatch', $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-1', '230.00', 'EUR' ) ) );
		// ISO 4217 codes are case-insensitive: "usd" is the same currency.
		$this->assertNull( $this->verify( Fixtures::execution( array( 'authorizeSuccessful' ), 'exec-1', '230.00', 'usd' ) ) );
	}

	public function test_policy(): void {
		$auth = ExecutionMapper::map( Fixtures::execution( array( 'authorizeSuccessful' ) ) );
		$fail = ExecutionMapper::map( Fixtures::execution( array( 'authorizeFailed' ) ) );
		$pend = ExecutionMapper::map( Fixtures::execution( array( 'authorizeRequested' ) ) );
		$this->assertSame( ConfirmPolicy::COMPLETE, ConfirmPolicy::decide( $auth, null ) );
		$this->assertSame( ConfirmPolicy::REVIEW, ConfirmPolicy::decide( $auth, 'amount_mismatch' ) );
		$this->assertSame( ConfirmPolicy::FAIL, ConfirmPolicy::decide( $fail, null ) );
		$this->assertSame( ConfirmPolicy::REVIEW, ConfirmPolicy::decide( $fail, 'unknown_execution' ) );
		$this->assertSame( ConfirmPolicy::PENDING, ConfirmPolicy::decide( $pend, null ) );
		$this->assertSame( ConfirmPolicy::PENDING, ConfirmPolicy::decide( $pend, 'amount_mismatch' ) );
	}
}
