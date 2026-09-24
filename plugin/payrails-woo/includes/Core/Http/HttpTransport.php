<?php
/**
 * Transport abstraction so the client is testable without the network.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Http;

use PayrailsWoo\Core\Exception\TransportException;

/**
 * Sends one request.
 */
interface HttpTransport {

	/**
	 * Sends the request.
	 *
	 * @param HttpRequest $request Request.
	 * @throws TransportException On network failure.
	 */
	public function send( HttpRequest $request ): HttpResponse;
}
