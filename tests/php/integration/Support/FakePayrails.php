<?php
/**
 * A tiny in-memory stand-in for the Payrails merchant API, for the integration test.
 * It answers the three calls the plugin makes (token, client-init, execution read)
 * and lets the test choose each execution's outcome. Test code only.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests\Support;

use PayrailsWoo\Core\Http\HttpRequest;
use PayrailsWoo\Core\Http\HttpResponse;
use PayrailsWoo\Core\Money;
use PayrailsWoo\Core\Uuid;

final class FakePayrails {

	/** @var array<string, array<string, mixed>> execution id => state */
	private array $executions = array();

	/** @var array<string, string> idempotency key => execution id */
	private array $by_key = array();

	/** Sets the outcome of an execution: created | success | decline | pending | mismatch | cancelled_after_success. */
	public function set_outcome( string $execution_id, string $outcome ): void {
		$this->executions[ $execution_id ]['outcome'] = $outcome;
	}

	/** The responder for FakeTransport::respond_with(). */
	public function __invoke( HttpRequest $r ): HttpResponse {
		$path = (string) parse_url( $r->url, PHP_URL_PATH );
		if ( 'POST' === $r->method && 0 === strpos( $path, '/auth/token/' ) ) {
			return HttpResponse::json_response( 200, array( 'access_token' => 'test-token', 'expires_in' => 3600 ) );
		}
		if ( 'POST' === $r->method && '/merchant/client/init' === $path ) {
			$key = (string) $r->header( 'x-idempotency-key' );
			if ( ! Uuid::is_valid( $key ) ) {
				return HttpResponse::json_response( 422, array( 'errors' => array( array( 'code' => 'request.header.missing' ) ) ) );
			}
			$body = json_decode( (string) $r->body, true );
			if ( ! isset( $this->by_key[ $key ] ) ) {
				$id                   = Uuid::v4();
				$this->by_key[ $key ] = $id;
				$this->executions[ $id ] = array(
					'outcome'           => 'created',
					'amount'            => $body['amount'],
					'merchantReference' => (string) $body['merchantReference'],
				);
			}
			$data = array( 'execution' => array( 'id' => $this->by_key[ $key ] ) );
			return HttpResponse::json_response( 200, array( 'version' => 'test', 'data' => base64_encode( (string) json_encode( $data ) ) ) );
		}
		if ( 'GET' === $r->method && preg_match( '#/executions/([^/]+)$#', $path, $m ) && isset( $this->executions[ rawurldecode( $m[1] ) ] ) ) {
			$id    = rawurldecode( $m[1] );
			$ex    = $this->executions[ $id ];
			$codes = array(
				'success'  => array( 'created', 'authorizeRequested', 'authorizeSuccessful' ),
				'mismatch' => array( 'created', 'authorizeRequested', 'authorizeSuccessful' ),
				'decline'  => array( 'created', 'authorizeRequested', 'authorizeFailed' ),
				'pending'  => array( 'created', 'authorizeRequested', 'authorizePending' ),
				'cancelled_after_success' => array( 'created', 'authorizeRequested', 'authorizeSuccessful', 'authorizeCancelled' ),
			)[ $ex['outcome'] ] ?? array( 'created' );
			$amount = $ex['amount'];
			if ( 'mismatch' === $ex['outcome'] ) {
				$exp             = Money::exponent( (string) $amount['currency'] );
				$amount['value'] = Money::from_minor( Money::to_minor( (string) $amount['value'], $exp ) + 100, $exp );
			}
			$status = array();
			foreach ( $codes as $i => $c ) {
				$status[] = array( 'code' => $c, 'time' => gmdate( 'Y-m-d\TH:i:s', 1790000000 + $i ) . 'Z' );
			}
			return HttpResponse::json_response( 200, array( 'id' => $id, 'status' => $status, 'amount' => $amount, 'merchantReference' => $ex['merchantReference'] ) );
		}
		return HttpResponse::json_response( 404, array( 'errors' => array( array( 'code' => 'not_found' ) ) ) );
	}
}
