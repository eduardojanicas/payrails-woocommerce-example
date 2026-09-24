<?php
/**
 * PayrailsClient: token caching, 401 retry, idempotency keys, error taxonomy, redaction.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests;

use PayrailsWoo\Tests\Support\ArrayTokenCache;
use PayrailsWoo\Core\Api\PayrailsClient;
use PayrailsWoo\Core\Exception\ApiException;
use PayrailsWoo\Core\Exception\AuthException;
use PayrailsWoo\Core\Exception\ResponseException;
use PayrailsWoo\Core\Exception\TransportException;
use PayrailsWoo\Core\Exception\UpstreamException;
use PayrailsWoo\Core\Http\HttpResponse;
use PayrailsWoo\Core\Uuid;
use PayrailsWoo\Tests\Support\FakeTransport;
use PayrailsWoo\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PayrailsClientTest extends TestCase {

	private FakeTransport $t;
	private ArrayTokenCache $cache;
	private array $logs = array();
	private array $sleeps = array();

	protected function setUp(): void {
		$this->t     = new FakeTransport();
		$this->cache = new ArrayTokenCache();
	}

	private function client(): PayrailsClient {
		return new PayrailsClient(
			Fixtures::config(),
			$this->t,
			$this->cache,
			function ( $level, $msg, $ctx ) {
				$this->logs[] = array( $level, $msg, $ctx );
			},
			function ( int $ms ) {
				$this->sleeps[] = $ms;
			}
		);
	}

	public function test_token_request_shape_and_cache_hit(): void {
		$this->t->push( Fixtures::token( 'tok-1', 3600 ) );
		$c = $this->client();
		$this->assertSame( 'tok-1', $c->token() );
		$this->assertSame( 'tok-1', $c->token() ); // Second call: cache hit, no request.
		$this->assertCount( 1, $this->t->requests );
		$r = $this->t->requests[0];
		$this->assertSame( 'POST', $r->method );
		$this->assertSame( 'https://api.example.test/auth/token/client-123', $r->url );
		$this->assertSame( Fixtures::SECRET, $r->header( 'x-api-key' ) );
		$this->assertNull( $r->header( 'Authorization' ) );
	}

	public function test_token_ttl_is_expires_in_minus_60_with_floor(): void {
		$this->t->push( Fixtures::token( 'a', 3600 ) );
		$c = $this->client();
		$c->token();
		$this->assertSame( 3540, $this->cache->ttls[ $c->token_cache_key() ] );

		$this->t->push( Fixtures::token( 'b', 30 ) );
		$c->token( true );
		$this->assertSame( 60, $this->cache->ttls[ $c->token_cache_key() ] );
	}

	public function test_token_cache_key_does_not_contain_client_id(): void {
		$this->assertStringNotContainsString( Fixtures::CLIENT_ID, $this->client()->token_cache_key() );
	}

	public function test_token_non_200_is_auth_exception(): void {
		$this->t->push( HttpResponse::json_response( 401, array( 'error' => 'nope' ) ) );
		try {
			$this->client()->token();
			$this->fail( 'expected AuthException' );
		} catch ( AuthException $e ) {
			$this->assertSame( 'PR-AUTH-401', $e->public_code() );
		}
	}

	public function test_client_init_401_refreshes_token_once_and_retries_with_a_new_idempotency_key(): void {
		$this->t->push(
			Fixtures::token( 'old' ),
			HttpResponse::json_response( 401, array() ),
			Fixtures::token( 'new' ),
			Fixtures::init_ok( 'exec-9' )
		);
		$out = $this->client()->client_init( array( 'a' => 1 ), 'seed-1' );
		$this->assertSame( 'exec-9', $out['execution_id'] );
		$this->assertCount( 2, $this->t->to( '/auth/token/' ) );
		$inits = $this->t->to( '/merchant/client/init' );
		$this->assertCount( 2, $inits );
		$this->assertSame( 'Bearer old', $inits[0]->header( 'Authorization' ) );
		$this->assertSame( 'Bearer new', $inits[1]->header( 'Authorization' ) );
		$k1 = $inits[0]->header( 'x-idempotency-key' );
		$k2 = $inits[1]->header( 'x-idempotency-key' );
		$this->assertTrue( Uuid::is_valid( $k1 ) );
		$this->assertTrue( Uuid::is_valid( $k2 ) );
		$this->assertNotSame( $k1, $k2 );
		$this->assertSame( PayrailsClient::idempotency_key( 'seed-1' ), $k1 );
		$this->assertSame( 0, $this->t->remaining() );
	}

	public function test_client_init_401_twice_is_auth_exception_after_exactly_two_token_calls(): void {
		$this->t->push(
			Fixtures::token( 'a' ),
			HttpResponse::json_response( 401, array() ),
			Fixtures::token( 'b' ),
			HttpResponse::json_response( 401, array() )
		);
		try {
			$this->client()->client_init( array(), 'seed' );
			$this->fail( 'expected AuthException' );
		} catch ( AuthException $e ) {
			$this->assertSame( 401, $e->status );
		}
		$this->assertCount( 2, $this->t->to( '/auth/token/' ) );
		$this->assertCount( 2, $this->t->to( '/merchant/client/init' ) );
	}

	public function test_idempotency_key_is_deterministic_per_seed(): void {
		$this->assertSame( PayrailsClient::idempotency_key( 's' ), PayrailsClient::idempotency_key( 's' ) );
		$this->assertNotSame( PayrailsClient::idempotency_key( 's' ), PayrailsClient::idempotency_key( 't' ) );
		$this->assertNotSame( PayrailsClient::idempotency_key( 's', 0 ), PayrailsClient::idempotency_key( 's', 1 ) );
	}

	public function test_idempotency_key_present_on_post_and_absent_on_get(): void {
		$this->t->push(
			Fixtures::token(),
			Fixtures::init_ok(),
			HttpResponse::json_response( 200, Fixtures::execution( array( 'created' ) ) )
		);
		$c = $this->client();
		$c->client_init( array( 'x' => 1 ), 'seed' );
		$c->get_execution( 'payment-acceptance', 'exec-1' );
		$post = $this->t->to( '/merchant/client/init' )[0];
		$get  = $this->t->to( '/executions/' )[0];
		$this->assertNotNull( $post->header( 'x-idempotency-key' ) );
		$this->assertSame( 'application/json', $post->header( 'Content-Type' ) );
		$this->assertSame( '{"x":1}', $post->body );
		$this->assertSame( 'GET', $get->method );
		$this->assertNull( $get->header( 'x-idempotency-key' ) );
		$this->assertNull( $get->body );
		$this->assertSame( 'https://api.example.test/merchant/workflows/payment-acceptance/executions/exec-1', $get->url );
	}

	public function test_execution_id_is_escaped_in_the_url(): void {
		$this->t->push( Fixtures::token(), HttpResponse::json_response( 200, Fixtures::execution( array() ) ) );
		$this->client()->get_execution( 'wf', 'a/b?c' );
		$this->assertStringEndsWith( '/executions/a%2Fb%3Fc', $this->t->requests[1]->url );
	}

	public function test_get_5xx_retries_exactly_once(): void {
		$this->t->push(
			Fixtures::token(),
			HttpResponse::json_response( 503, array() ),
			HttpResponse::json_response( 200, Fixtures::execution( array( 'created' ) ) )
		);
		$ex = $this->client()->get_execution( 'wf', 'exec-1' );
		$this->assertSame( 'exec-1', $ex['id'] );
		$this->assertCount( 2, $this->t->to( '/executions/' ) );
		$this->assertSame( array( 300 ), $this->sleeps );
	}

	public function test_get_5xx_twice_is_upstream_exception(): void {
		$this->t->push(
			Fixtures::token(),
			HttpResponse::json_response( 502, array() ),
			HttpResponse::json_response( 502, array() )
		);
		$this->expectException( UpstreamException::class );
		$this->client()->get_execution( 'wf', 'exec-1' );
	}

	public function test_get_transport_error_retries_once_then_throws(): void {
		$this->t->push( Fixtures::token(), new TransportException( 28, 'timeout' ), new TransportException( 28, 'timeout' ) );
		try {
			$this->client()->get_execution( 'wf', 'exec-1' );
			$this->fail( 'expected TransportException' );
		} catch ( TransportException $e ) {
			$this->assertSame( 'PR-NET-28', $e->public_code() );
		}
		$this->assertCount( 2, $this->t->to( '/executions/' ) );
	}

	public function test_curl_error_on_client_init_is_transport_exception(): void {
		$this->t->push( Fixtures::token(), new TransportException( 35, 'ssl' ) );
		$this->expectException( TransportException::class );
		$this->client()->client_init( array(), 'seed' );
	}

	public function test_non_json_is_response_exception(): void {
		$this->t->push( Fixtures::token(), new HttpResponse( 200, '<html>oops</html>' ) );
		$this->expectException( ResponseException::class );
		$this->client()->client_init( array(), 'seed' );
	}

	public function test_missing_execution_id_is_response_exception(): void {
		$data = base64_encode( json_encode( array( 'something' => array( 'else' => 1 ) ) ) );
		$this->t->push( Fixtures::token(), HttpResponse::json_response( 200, array( 'version' => '1', 'data' => $data ) ) );
		try {
			$this->client()->client_init( array(), 'seed' );
			$this->fail( 'expected ResponseException' );
		} catch ( ResponseException $e ) {
			$this->assertSame( 'no_execution_id', $e->reason );
		}
	}

	public function test_4xx_is_api_exception_with_payrails_code(): void {
		$this->t->push( Fixtures::token(), HttpResponse::json_response( 422, array( 'errors' => array( array( 'code' => 'request.header.missing', 'detail' => 'x' ) ) ) ) );
		try {
			$this->client()->client_init( array(), 'seed' );
			$this->fail( 'expected ApiException' );
		} catch ( ApiException $e ) {
			$this->assertSame( 422, $e->status );
			$this->assertSame( 'request.header.missing', $e->payrails_code );
			$this->assertSame( 'PR-API-422', $e->public_code() );
		}
	}

	public function test_429_is_upstream(): void {
		$this->t->push( Fixtures::token(), HttpResponse::json_response( 429, array() ) );
		$this->expectException( UpstreamException::class );
		$this->client()->client_init( array(), 'seed' );
	}

	public function test_no_secret_or_token_in_exceptions_logs_or_debug_output(): void {
		$this->t->push(
			Fixtures::token( 'TOKEN-VALUE-XYZ' ),
			HttpResponse::json_response( 400, array( 'errors' => array( array( 'code' => 'bad' ) ), 'echo' => Fixtures::SECRET ) )
		);
		$c = $this->client();
		try {
			$c->client_init( array(), 'seed' );
		} catch ( ApiException $e ) {
			$this->assertStringNotContainsString( Fixtures::SECRET, $e->getMessage() );
			$this->assertStringNotContainsString( 'TOKEN-VALUE-XYZ', $e->getMessage() );
		}
		$dump = print_r( Fixtures::config(), true ) . var_export( json_encode( Fixtures::config() ), true ) . serialize( Fixtures::config() ) . json_encode( $this->logs ) . print_r( $this->t->requests, true );
		$this->assertStringNotContainsString( Fixtures::SECRET, $dump );
		$this->assertStringNotContainsString( 'TOKEN-VALUE-XYZ', $dump );
		$this->assertStringNotContainsString( Fixtures::CLIENT_ID, print_r( Fixtures::config(), true ) );
	}

	public function test_client_init_logs_data_shape_not_values(): void {
		$this->t->push( Fixtures::token(), Fixtures::init_ok( 'exec-secret-looking' ) );
		$this->client()->client_init( array(), 'seed' );
		$info = array_values( array_filter( $this->logs, static fn( $l ) => 'client-init ok' === $l[1] ) );
		$this->assertCount( 1, $info );
		$this->assertSame( array( 'execution' => array( 'id' => 'string' ), 'other' => array( 'x' => 'number' ) ), $info[0][2]['data_shape'] );
		$this->assertStringNotContainsString( 'exec-secret-looking', json_encode( $this->logs ) );
	}
}
