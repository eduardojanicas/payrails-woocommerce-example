<?php
/**
 * Outgoing HTTP request value object.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Http;

/**
 * Request. Headers are a name => value map.
 */
final class HttpRequest {

	/**
	 * Method.
	 *
	 * @var string
	 */
	public string $method;

	/**
	 * Absolute URL.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * Headers (name => value).
	 *
	 * @var array<string, string>
	 */
	public array $headers;

	/**
	 * Body or null.
	 *
	 * @var string|null
	 */
	public ?string $body;

	/**
	 * Constructor.
	 *
	 * @param string                $method  Method.
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 */
	public function __construct( string $method, string $url, array $headers = array(), ?string $body = null ) {
		$this->method  = strtoupper( $method );
		$this->url     = $url;
		$this->headers = $headers;
		$this->body    = $body;
	}

	/**
	 * Case-insensitive header lookup.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): ?string {
		foreach ( $this->headers as $k => $v ) {
			if ( 0 === strcasecmp( $k, $name ) ) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Redacted view: header names only, never values (they carry the secret/token).
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'method'  => $this->method,
			'url'     => $this->url,
			'headers' => array_keys( $this->headers ),
			'body'    => null === $this->body ? null : '[' . strlen( $this->body ) . ' bytes]',
		);
	}
}
