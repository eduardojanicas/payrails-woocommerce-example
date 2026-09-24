<?php
/**
 * ClientInitBuilder: exact body, amount formatting, Σ lines, omitted optional fields.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests;

use PayrailsWoo\Core\ClientInitBuilder;
use PayrailsWoo\Core\HolderReference;
use PayrailsWoo\Core\OrderSnapshot;
use PayrailsWoo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ClientInitBuilderTest extends TestCase {

	private const CTX = array(
		'ipAddress' => '127.0.0.1',
		'origin'    => 'http://localhost:8080',
		'userAgent' => 'Mozilla/5.0',
		'returnUrl' => 'http://localhost:8080/checkout/order-pay/1042/?key=wc_order_x',
	);

	public function test_exact_body_snapshot(): void {
		$body = ( new ClientInitBuilder() )->build( Fixtures::snapshot(), 'payment-acceptance', 'ws-1', self::CTX );
		$this->assertSame(
			array(
				'type'              => 'dropIn',
				'holderReference'   => 'wc-guest-0123456789abcdef0123456789abcdef',
				'merchantReference' => '1042',
				'workflowCode'      => 'payment-acceptance',
				'amount'            => array( 'value' => '230.00', 'currency' => 'USD' ),
				'workspaceId'       => 'ws-1',
				'meta'              => array(
					'CIT'           => true,
					'customer'      => array(
						'reference' => 'wc-guest-0123456789abcdef0123456789abcdef',
						'email'     => 'jane@example.test',
						'name'      => 'Jane Doe',
						'country'   => array( 'code' => 'US' ),
					),
					'order'         => array(
						'reference'   => '1042',
						'description' => 'Atelier order #1042',
						'lines'       => array(
							array( 'id' => 'item-17', 'name' => 'Merino Wool Sweater', 'quantity' => 1, 'unitPrice' => array( 'value' => '185.00', 'currency' => 'USD' ) ),
							array( 'id' => 'item-18', 'name' => 'Organic Cotton Tee', 'quantity' => 1, 'unitPrice' => array( 'value' => '45.00', 'currency' => 'USD' ) ),
						),
					),
					'clientContext' => array(
						'ipAddress' => '127.0.0.1',
						'origin'    => 'http://localhost:8080',
						'osType'    => 'web',
						'userAgent' => 'Mozilla/5.0',
						'returnUrl' => 'http://localhost:8080/checkout/order-pay/1042/?key=wc_order_x',
					),
				),
			),
			$body
		);
	}

	public function test_amounts_are_strings_with_currency_exponent(): void {
		$lines = array( array( 'id' => 'a', 'name' => 'Pin', 'quantity' => 1, 'total_minor' => 10 ) );
		$b     = ( new ClientInitBuilder() )->build( Fixtures::snapshot( 10, $lines ), 'wf', null, array() );
		$this->assertSame( '0.10', $b['amount']['value'] );
		$this->assertSame( '0.10', $b['meta']['order']['lines'][0]['unitPrice']['value'] );

		$jpy = ( new ClientInitBuilder() )->build( Fixtures::snapshot( 1500, array( array( 'id' => 'a', 'name' => 'X', 'quantity' => 1, 'total_minor' => 1500 ) ), 'JPY' ), 'wf', null, array() );
		$this->assertSame( '1500', $jpy['amount']['value'] );
		$this->assertSame( 'JPY', $jpy['amount']['currency'] );
	}

	public function test_lines_sum_exactly_to_amount(): void {
		$s     = Fixtures::snapshot(
			27000,
			array(
				array( 'id' => 'a', 'name' => 'Tee', 'quantity' => 3, 'total_minor' => 13500 ),
				array( 'id' => 'b', 'name' => 'Scarf', 'quantity' => 1, 'total_minor' => 9500 ),
				array( 'id' => 'shipping', 'name' => 'Shipping', 'quantity' => 1, 'total_minor' => 4000 ),
			)
		);
		$lines = ClientInitBuilder::lines( $s );
		$sum   = array_sum( array_map( static fn( $l ) => $l['quantity'] * $l['unit_minor'], $lines ) );
		$this->assertSame( 27000, $sum );
		$this->assertSame( 3, $lines[0]['quantity'] );
		$this->assertSame( 4500, $lines[0]['unit_minor'] );
	}

	public function test_indivisible_discounted_line_falls_back_to_quantity_one(): void {
		// 3 × $10.01 = $30.03, minus a $1.00 discount = $29.03, which is not divisible by 3.
		$s     = Fixtures::snapshot( 2903, array( array( 'id' => 'a', 'name' => 'Sock', 'quantity' => 3, 'total_minor' => 2903 ) ) );
		$lines = ClientInitBuilder::lines( $s );
		$this->assertSame( 1, $lines[0]['quantity'] );
		$this->assertSame( 2903, $lines[0]['unit_minor'] );
		$this->assertSame( 'Sock × 3', $lines[0]['name'] );
	}

	public function test_mismatch_omits_lines_and_warns(): void {
		$builder = new ClientInitBuilder();
		$b       = $builder->build( Fixtures::snapshot( 23001 ), 'wf', null, array() );
		$this->assertArrayNotHasKey( 'lines', $b['meta']['order'] );
		$this->assertSame( array( 'lines_omitted' ), $builder->warnings );
		$this->assertSame( '230.01', $b['amount']['value'] );
	}

	public function test_non_positive_line_omits_lines(): void {
		$s = Fixtures::snapshot(
			18400,
			array(
				array( 'id' => 'a', 'name' => 'Sweater', 'quantity' => 1, 'total_minor' => 18500 ),
				array( 'id' => 'fee', 'name' => 'Discount', 'quantity' => 1, 'total_minor' => -100 ),
			)
		);
		$this->assertNull( ClientInitBuilder::lines( $s ) );
	}

	public function test_absent_optional_fields_are_omitted_exact_body(): void {
		// No e-mail, a blank name, no country, no description, an empty workspace id and an
		// empty IP: each is left out entirely (never sent as ""), and nothing else is added.
		$s    = new OrderSnapshot( 7, '7', 4500, 'USD', array( array( 'id' => 'a', 'name' => 'Tee', 'quantity' => 1, 'total_minor' => 4500 ) ), 'wc-customer-00000000000000000000000000000003', '', '  ', '', '' );
		$body = ( new ClientInitBuilder() )->build( $s, 'wf', '', array( 'ipAddress' => '', 'origin' => 'https://shop.example' ) );
		$this->assertSame(
			array(
				'type'              => 'dropIn',
				'holderReference'   => 'wc-customer-00000000000000000000000000000003',
				'merchantReference' => '7',
				'workflowCode'      => 'wf',
				'amount'            => array( 'value' => '45.00', 'currency' => 'USD' ),
				'meta'              => array(
					'CIT'           => true,
					'customer'      => array( 'reference' => 'wc-customer-00000000000000000000000000000003' ),
					'order'         => array(
						'reference' => '7',
						'lines'     => array(
							array( 'id' => 'a', 'name' => 'Tee', 'quantity' => 1, 'unitPrice' => array( 'value' => '45.00', 'currency' => 'USD' ) ),
						),
					),
					'clientContext' => array(
						'origin' => 'https://shop.example',
						'osType' => 'web',
					),
				),
			),
			$body
		);
	}

		public function test_ipv4_literal_origin_and_return_url_are_omitted(): void {
		// A client-init with an IPv4-literal URL in clientContext is refused (403).
		$builder = new ClientInitBuilder();
		$ctx     = $builder->build( Fixtures::snapshot(), 'wf', null, array( 'origin' => 'http://127.0.0.1:8080', 'returnUrl' => 'http://10.0.0.5/x', 'ipAddress' => '127.0.0.1' ) )['meta']['clientContext'];
		$this->assertArrayNotHasKey( 'origin', $ctx );
		$this->assertArrayNotHasKey( 'returnUrl', $ctx );
		$this->assertSame( '127.0.0.1', $ctx['ipAddress'] );
		$this->assertSame( array( 'origin_ip_literal_omitted', 'returnUrl_ip_literal_omitted' ), $builder->warnings );
		$this->assertFalse( ClientInitBuilder::has_ipv4_host( 'http://localhost:8080' ) );
		$this->assertFalse( ClientInitBuilder::has_ipv4_host( 'http://[::1]:8080' ) );
		$this->assertTrue( ClientInitBuilder::has_ipv4_host( 'https://192.168.1.2/' ) );
	}

	public function test_user_agent_is_truncated_to_255(): void {
		$b = ( new ClientInitBuilder() )->build( Fixtures::snapshot(), 'wf', null, array( 'userAgent' => str_repeat( 'a', 400 ) ) );
		$this->assertSame( 255, strlen( $b['meta']['clientContext']['userAgent'] ) );
	}

	public function test_holder_references_are_random_and_not_derived_from_personal_data(): void {
		// An e-mail-derived holder would let anyone who knows a victim's e-mail become the
		// same Payrails holder. Guests now get a random reference per order.
		$a = HolderReference::guest();
		$b = HolderReference::guest();
		$this->assertNotSame( $a, $b );
		$this->assertMatchesRegularExpression( '/^wc-guest-[0-9a-f]{32}$/', $a );
		$this->assertTrue( HolderReference::is_guest( $a ) );
		$this->assertFalse( HolderReference::is_guest( 'wc-guest-3f1a2b' ), 'old HMAC-style refs are not reused' );
		$this->assertFalse( HolderReference::is_guest( 'wc-customer-5' ) );
		// Logged-in customers: random too (stored once per user), never the user id.
		$c1 = HolderReference::customer();
		$this->assertMatchesRegularExpression( '/^wc-customer-[0-9a-f]{32}$/', $c1 );
		$this->assertNotSame( $c1, HolderReference::customer() );
		$this->assertTrue( HolderReference::is_customer( $c1 ) );
		$this->assertFalse( HolderReference::is_customer( 'wc-customer-5' ), 'an id-based reference is not accepted' );
		$this->assertSame( 0, ( new \ReflectionMethod( HolderReference::class, 'customer' ) )->getNumberOfParameters() );
		$m = new \ReflectionMethod( HolderReference::class, 'guest' );
		$this->assertSame( 0, $m->getNumberOfParameters(), 'guest() must not take the e-mail (or anything else) as input' );
	}

	public function test_fingerprint_changes_with_amount_currency_and_workflow(): void {
		$s = Fixtures::snapshot();
		$this->assertSame( $s->fingerprint( 'wf' ), Fixtures::snapshot()->fingerprint( 'wf' ) );
		$this->assertNotSame( $s->fingerprint( 'wf' ), Fixtures::snapshot( 23100 )->fingerprint( 'wf' ) );
		$this->assertNotSame( $s->fingerprint( 'wf' ), $s->fingerprint( 'other' ) );
	}
}
