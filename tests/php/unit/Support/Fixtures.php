<?php
/**
 * Shared fixtures. The secret below is fake; tests assert it never leaks.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests\Support;

use PayrailsWoo\Core\Config;
use PayrailsWoo\Core\Http\HttpResponse;
use PayrailsWoo\Core\OrderSnapshot;

final class Fixtures {

	public const SECRET    = 'FAKE-s3cr3t-$abc-DO-NOT-LEAK';
	public const CLIENT_ID = 'client-123';

	public static function config(): Config {
		return new Config( 'https://api.example.test', self::CLIENT_ID, self::SECRET, 'ws-1', 'payment-acceptance', '/tmp/c.crt', '/tmp/c.key', 'test' );
	}

	public static function token( string $t = 'tok-1', int $expires = 3600 ): HttpResponse {
		return HttpResponse::json_response( 200, array( 'access_token' => $t, 'expires_in' => $expires ) );
	}

	public static function init_ok( string $exec_id = 'exec-1' ): HttpResponse {
		$data = base64_encode( json_encode( array( 'execution' => array( 'id' => $exec_id ), 'other' => array( 'x' => 1 ) ) ) );
		return HttpResponse::json_response( 200, array( 'version' => '1.0.0', 'data' => $data ) );
	}

	public static function execution( array $codes, string $id = 'exec-1', string $value = '230.00', string $currency = 'USD', string $ref = '1042' ): array {
		return array(
			'id'                => $id,
			'status'            => array_map( static fn( $c, $i ) => array( 'code' => $c, 'time' => sprintf( '2026-09-23T12:00:%02d.%09dZ', $i, 1000 * $i ) ), $codes, array_keys( $codes ) ),
			'amount'            => array( 'value' => $value, 'currency' => $currency ),
			'merchantReference' => $ref,
		);
	}

	/**
	 * @param array<int, array{id:string,name:string,quantity:int,total_minor:int}>|null $lines
	 */
	public static function snapshot( int $amount_minor = 23000, ?array $lines = null, string $currency = 'USD' ): OrderSnapshot {
		$lines = $lines ?? array(
			array( 'id' => 'item-17', 'name' => 'Merino Wool Sweater', 'quantity' => 1, 'total_minor' => 18500 ),
			array( 'id' => 'item-18', 'name' => 'Organic Cotton Tee', 'quantity' => 1, 'total_minor' => 4500 ),
		);
		return new OrderSnapshot( 1042, '1042', $amount_minor, $currency, $lines, 'wc-guest-0123456789abcdef0123456789abcdef', 'jane@example.test', 'Jane Doe', 'US', 'Atelier order #1042' );
	}
}
