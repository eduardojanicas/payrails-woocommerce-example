<?php
/**
 * Test transport, recording every request. Either scripted (a queue of responses or
 * exceptions, used by the unit tests) or driven by a responder callable (used by the
 * WordPress integration test, injected through the payrails_woo_http_transport filter).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests\Support;

use PayrailsWoo\Core\Http\HttpRequest;
use PayrailsWoo\Core\Http\HttpResponse;
use PayrailsWoo\Core\Http\HttpTransport;

final class FakeTransport implements HttpTransport {

	/** @var array<int, HttpResponse|\Throwable> */
	private array $queue = array();

	/** @var HttpRequest[] */
	public array $requests = array();

	/** @var callable|null fn(HttpRequest): HttpResponse */
	private $responder = null;

	/**
	 * Answer every request with $responder instead of the queue.
	 *
	 * @param callable $responder fn(HttpRequest): HttpResponse.
	 */
	public function respond_with( callable $responder ): self {
		$this->responder = $responder;
		return $this;
	}

	/**
	 * @param HttpResponse|\Throwable ...$items Responses in order.
	 */
	public function push( ...$items ): self {
		foreach ( $items as $i ) {
			$this->queue[] = $i;
		}
		return $this;
	}

	public function send( HttpRequest $request ): HttpResponse {
		$this->requests[] = $request;
		if ( null !== $this->responder ) {
			return ( $this->responder )( $request );
		}
		if ( ! $this->queue ) {
			throw new \LogicException( 'FakeTransport: no scripted response for ' . $request->method . ' ' . $request->url );
		}
		$next = array_shift( $this->queue );
		if ( $next instanceof \Throwable ) {
			throw $next;
		}
		return $next;
	}

	/** Requests whose URL contains $needle. */
	public function to( string $needle ): array {
		return array_values( array_filter( $this->requests, static fn( HttpRequest $r ) => str_contains( $r->url, $needle ) ) );
	}

	public function remaining(): int {
		return count( $this->queue );
	}
}
