<?php
/**
 * Payrails merchant API client: token, client-init, execution read.
 *
 * Token caching, retry-once-on-401 and idempotency keys over an HttpTransport
 * (cURL with mTLS in production, a fake in tests).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Api;

use PayrailsWoo\Core\Config;
use PayrailsWoo\Core\Exception\ApiException;
use PayrailsWoo\Core\Exception\AuthException;
use PayrailsWoo\Core\Exception\PayrailsException;
use PayrailsWoo\Core\Exception\ResponseException;
use PayrailsWoo\Core\Exception\TransportException;
use PayrailsWoo\Core\Exception\UpstreamException;
use PayrailsWoo\Core\Http\HttpRequest;
use PayrailsWoo\Core\Http\HttpResponse;
use PayrailsWoo\Core\Http\HttpTransport;
use PayrailsWoo\Core\KeyTree;
use PayrailsWoo\Core\Uuid;

/**
 * Client.
 */
final class PayrailsClient {

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Transport.
	 *
	 * @var HttpTransport
	 */
	private HttpTransport $transport;

	/**
	 * Token cache.
	 *
	 * @var TokenCache
	 */
	private TokenCache $cache;

	/**
	 * Sleep function (milliseconds), injectable for tests.
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Logger: fn(string $level, string $message, array $context).
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Config        $config    Config.
	 * @param HttpTransport $transport Transport.
	 * @param TokenCache    $cache     Token cache.
	 * @param callable|null $log       Logger.
	 * @param callable|null $sleep     Sleep(ms).
	 */
	public function __construct( Config $config, HttpTransport $transport, TokenCache $cache, ?callable $log = null, ?callable $sleep = null ) {
		$this->config    = $config;
		$this->transport = $transport;
		$this->cache     = $cache;
		$this->log       = $log;
		$this->sleep     = $sleep ?? static function ( int $ms ): void {
			usleep( $ms * 1000 );
		};
	}

	/**
	 * Cache key for the access token (per API host + client id, never containing either verbatim).
	 */
	public function token_cache_key(): string {
		return 'payrails_woo_tok_' . md5( $this->config->api_url . '|' . $this->config->client_id );
	}

	/**
	 * Payrails integration — step 3: server auth. POST /auth/token/{clientId} with the
	 * client secret in x-api-key, over mTLS (see CurlTransport); cached until 60 s before expiry.
	 *
	 * Returns a bearer token, from cache unless $force.
	 *
	 * @param bool $force Drop the cache first.
	 * @throws AuthException|TransportException|ResponseException On failure.
	 */
	public function token( bool $force = false ): string {
		$key = $this->token_cache_key();
		if ( $force ) {
			$this->cache->delete( $key );
		} else {
			$cached = $this->cache->get( $key );
			if ( null !== $cached && '' !== $cached ) {
				return $cached;
			}
		}
		$res = $this->transport->send(
			new HttpRequest(
				'POST',
				$this->config->api_url . '/auth/token/' . rawurlencode( $this->config->client_id ),
				array(
					'Accept'    => 'application/json',
					'x-api-key' => $this->config->client_secret(),
				)
			)
		);
		if ( 200 !== $res->status ) {
			$this->log( 'error', 'token request failed', array( 'status' => $res->status ) + $this->shape( $res ) );
			throw new AuthException( $res->status );
		}
		$json  = $res->json();
		$token = is_array( $json ) ? ( $json['access_token'] ?? null ) : null;
		if ( ! is_string( $token ) || '' === $token ) {
			$this->log( 'error', 'token response unusable', $this->shape( $res ) );
			throw new ResponseException( 'no_access_token' );
		}
		$expires = isset( $json['expires_in'] ) && is_numeric( $json['expires_in'] ) ? (int) $json['expires_in'] : 3600;
		$this->cache->set( $key, $token, max( 60, $expires - 60 ) );
		return $token;
	}

	/**
	 * Payrails integration — step 4: client-init. POST /merchant/client/init with an
	 * x-idempotency-key; the response {version, data} goes to the browser as-is.
	 *
	 * POST /merchant/client/init.
	 *
	 * @param array<string, mixed> $body             Request body (see ClientInitBuilder).
	 * @param string               $idempotency_seed Deterministic seed; the key is UUIDv5(seed), "-r1" on the 401 retry.
	 * @return array{response: array<string, mixed>, execution_id: string, data_shape: mixed}
	 * @throws PayrailsException On any failure.
	 */
	public function client_init( array $body, string $idempotency_seed ): array {
		$payload = (string) json_encode( $body, JSON_UNESCAPED_SLASHES );
		$res     = $this->authed(
			function ( string $token, int $attempt ) use ( $payload, $idempotency_seed ): HttpRequest {
				return new HttpRequest(
					'POST',
					$this->config->api_url . '/merchant/client/init',
					array(
						'Accept'            => 'application/json',
						'Content-Type'      => 'application/json',
						'Authorization'     => 'Bearer ' . $token,
						'x-idempotency-key' => self::idempotency_key( $idempotency_seed, $attempt ),
					),
					$payload
				);
			}
		);
		if ( 200 !== $res->status && 201 !== $res->status ) {
			throw $this->error_for( $res, 'client-init' );
		}
		$json = $res->json();
		if ( ! is_array( $json ) || ! isset( $json['data'] ) || ! is_string( $json['data'] ) ) {
			$this->log( 'error', 'client-init response has no data', $this->shape( $res ) );
			throw new ResponseException( 'no_data' );
		}
		$decoded = json_decode( (string) base64_decode( $json['data'], true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $decoded ) ) {
			$this->log( 'error', 'client-init data is not base64 JSON', array( 'response_keys' => array_keys( $json ) ) );
			throw new ResponseException( 'data_not_json' );
		}
		$shape = KeyTree::of( $decoded );
		$this->log(
			'info',
			'client-init ok',
			array(
				'status'        => $res->status,
				'response_keys' => array_keys( $json ),
				'data_shape'    => $shape,
			)
		);
		$execution_id = self::extract_execution_id( $decoded );
		if ( null === $execution_id ) {
			throw new ResponseException( 'no_execution_id' );
		}
		return array(
			'response'     => $json,
			'execution_id' => $execution_id,
			'data_shape'   => $shape,
		);
	}

	/**
	 * GET /merchant/workflows/{wf}/executions/{id}. One retry on 5xx/transport.
	 *
	 * @param string $workflow_code Workflow code recorded at init.
	 * @param string $execution_id  Execution id.
	 * @return array<string, mixed>
	 * @throws PayrailsException On failure.
	 */
	public function get_execution( string $workflow_code, string $execution_id ): array {
		$url  = $this->config->api_url . '/merchant/workflows/' . rawurlencode( $workflow_code ) . '/executions/' . rawurlencode( $execution_id );
		$make = static function ( string $token ) use ( $url ): HttpRequest {
			return new HttpRequest(
				'GET',
				$url,
				array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				)
			);
		};
		try {
			$res = $this->authed( $make );
			if ( $res->status >= 500 ) {
				( $this->sleep )( 300 );
				$res = $this->authed( $make );
			}
		} catch ( TransportException $e ) {
			( $this->sleep )( 300 );
			$res = $this->authed( $make );
		}
		if ( 200 !== $res->status ) {
			throw $this->error_for( $res, 'execution read' );
		}
		$json = $res->json();
		if ( ! is_array( $json ) ) {
			throw new ResponseException( 'execution_not_json' );
		}
		return $json;
	}

	/**
	 * Execution id inside the decoded client-init data.
	 * The client-init response is {version, data}; data is base64 JSON and the
	 * execution id is at data.execution.id.
	 *
	 * @param array<string, mixed> $decoded Decoded data.
	 */
	public static function extract_execution_id( array $decoded ): ?string {
		$id = $decoded['execution']['id'] ?? null;
		return is_string( $id ) && '' !== $id ? $id : null;
	}

	/**
	 * Idempotency key for a seed and attempt (attempt 0 = first send, 1 = the 401 retry).
	 *
	 * The 401 retry deliberately uses a DIFFERENT key. A 401 is rejected at
	 * authentication, before the request is processed, so no execution exists for
	 * the first key and a fresh key cannot create a duplicate. Reusing the first key
	 * would risk an idempotency layer answering the retry with the cached 401. The
	 * retry key is still deterministic (seed + "-r1"), so concurrent retries of the
	 * same request collapse onto one execution.
	 *
	 * @param string $seed    Seed.
	 * @param int    $attempt Attempt.
	 */
	public static function idempotency_key( string $seed, int $attempt = 0 ): string {
		return Uuid::v5( Uuid::NS_IDEMPOTENCY, 0 === $attempt ? $seed : $seed . '-r' . $attempt );
	}

	/**
	 * Sends with a bearer token; on 401 only, refreshes the token and retries once.
	 *
	 * @param callable $make fn(string $token, int $attempt): HttpRequest.
	 * @throws AuthException On a second 401.
	 */
	private function authed( callable $make ): HttpResponse {
		$res = $this->transport->send( $make( $this->token(), 0 ) );
		if ( 401 !== $res->status ) {
			return $res;
		}
		$this->log( 'warning', 'got 401, refreshing token and retrying once', array() );
		$res = $this->transport->send( $make( $this->token( true ), 1 ) );
		if ( 401 === $res->status ) {
			throw new AuthException( 401 );
		}
		return $res;
	}

	/**
	 * Maps a non-2xx response to an exception. Logs only status + Payrails code + key names.
	 *
	 * @param HttpResponse $res       Response.
	 * @param string       $operation Label.
	 */
	private function error_for( HttpResponse $res, string $operation ): PayrailsException {
		$json = $res->json();
		$code = null;
		if ( is_array( $json ) ) {
			$cand = $json['errors'][0]['code'] ?? ( $json['code'] ?? null );
			$code = is_string( $cand ) ? substr( $cand, 0, 80 ) : null;
		}
		$this->log(
			'error',
			$operation . ' failed',
			array(
				'status'        => $res->status,
				'payrails_code' => $code,
			) + $this->shape( $res )
		);
		if ( 401 === $res->status ) {
			return new AuthException( $res->status );
		}
		if ( 429 === $res->status || $res->status >= 500 ) {
			return new UpstreamException( $res->status );
		}
		return new ApiException( $res->status, $code, $operation );
	}

	/**
	 * Shape of a response body for logs.
	 *
	 * @param HttpResponse $res Response.
	 * @return array<string, mixed>
	 */
	private function shape( HttpResponse $res ): array {
		$json = $res->json();
		return array( 'body_shape' => null === $json ? ( '' === $res->body ? 'empty' : 'non-json' ) : KeyTree::of( $json, 3 ) );
	}

	/**
	 * Logs if a logger was provided.
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context (shapes/codes only).
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( null !== $this->log ) {
			( $this->log )( $level, $message, $context );
		}
	}
}
