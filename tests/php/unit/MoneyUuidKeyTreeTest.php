<?php
/**
 * Money, Uuid and KeyTree.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests;

use PayrailsWoo\Core\KeyTree;
use PayrailsWoo\Core\Money;
use PayrailsWoo\Core\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyUuidKeyTreeTest extends TestCase {

	public static function to_minor_cases(): array {
		return array(
			array( '185.00', 2, 18500 ),
			array( '185', 2, 18500 ),
			array( '185.5', 2, 18550 ),
			array( '0.10', 2, 10 ),
			array( '.5', 2, 50 ),
			array( '230.0', 2, 23000 ),
			array( '0.005', 2, 1 ),   // Half up.
			array( '0.0049', 2, 0 ),
			array( '1.995', 2, 200 ),
			array( '-3.10', 2, -310 ),
			array( '-0.005', 2, -1 ),  // Away from zero.
			array( '1500', 0, 1500 ),
			array( '1500.5', 0, 1501 ),
			array( '1.2345', 3, 1235 ),
			array( ' 42.00 ', 2, 4200 ),
			array( '+7', 2, 700 ),
			array( '00012.30', 2, 1230 ),
			array( '999999999.99', 2, 99999999999 ),
		);
	}

	#[DataProvider( 'to_minor_cases' )]
	public function test_to_minor( string $in, int $exp, int $out ): void {
		$this->assertSame( $out, Money::to_minor( $in, $exp ) );
	}

	public function test_to_minor_accepts_ints_and_floats_without_float_math(): void {
		$this->assertSame( 4500, Money::to_minor( 45, 2 ) );
		$this->assertSame( 30, Money::to_minor( 0.1 + 0.2, 2 ) ); // 0.30000000000000004 → "0.3000000000".
		$this->assertSame( 1001, Money::to_minor( 10.01, 2 ) );
	}

	public static function bad_amounts(): array {
		return array( array( '' ), array( 'abc' ), array( '1,000.00' ), array( '1e3' ), array( '.' ), array( '1.2.3' ), array( '--1' ) );
	}

	#[DataProvider( 'bad_amounts' )]
	public function test_to_minor_rejects_non_decimals( string $in ): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::to_minor( $in, 2 );
	}

	public function test_from_minor(): void {
		$this->assertSame( '185.00', Money::from_minor( 18500, 2 ) );
		$this->assertSame( '0.10', Money::from_minor( 10, 2 ) );
		$this->assertSame( '0.01', Money::from_minor( 1, 2 ) );
		$this->assertSame( '0.00', Money::from_minor( 0, 2 ) );
		$this->assertSame( '-3.10', Money::from_minor( -310, 2 ) );
		$this->assertSame( '1500', Money::from_minor( 1500, 0 ) );
		$this->assertSame( '1.235', Money::from_minor( 1235, 3 ) );
	}

	public function test_exponents(): void {
		$this->assertSame( 2, Money::exponent( 'usd' ) );
		$this->assertSame( 0, Money::exponent( 'JPY' ) );
		$this->assertSame( 3, Money::exponent( 'KWD' ) );
		$this->assertSame( 2, Money::exponent( 'XXX' ) );
	}

	public function test_uuid_v5_known_vectors(): void {
		$this->assertSame( '886313e1-3b8a-5372-9b90-0c9aee199e5d', Uuid::v5( '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'python.org' ) );
	}

	public function test_uuid_v4_and_v5_version_and_variant_bits(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$v4 = Uuid::v4();
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $v4 );
		}
		$v5 = Uuid::v5( Uuid::NS_IDEMPOTENCY, 'wc-1-abc-1' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $v5 );
		$this->assertTrue( Uuid::is_valid( $v5 ) );
		$this->assertFalse( Uuid::is_valid( 'not-a-uuid' ) );
		$this->assertNotSame( Uuid::v4(), Uuid::v4() );
	}

	public function test_uuid_v5_rejects_bad_namespace(): void {
		$this->expectException( \InvalidArgumentException::class );
		Uuid::v5( 'nope', 'x' );
	}

	public function test_key_tree_keeps_keys_and_drops_values(): void {
		$tree = KeyTree::of(
			array(
				'token'     => 'eyJhbGciOi...',
				'execution' => array( 'id' => 'abc', 'links' => array( array( 'href' => 'https://x' ), array( 'href' => 'y' ) ) ),
				'n'         => 3,
				'ok'        => true,
				'none'      => null,
				'empty'     => array(),
			)
		);
		$this->assertSame(
			array(
				'token'     => 'string',
				'execution' => array( 'id' => 'string', 'links' => array( array( 'href' => 'string' ), '×2' ) ),
				'n'         => 'number',
				'ok'        => 'bool',
				'none'      => 'null',
				'empty'     => array(),
			),
			$tree
		);
		$this->assertStringNotContainsString( 'eyJ', json_encode( $tree ) );
	}
}
