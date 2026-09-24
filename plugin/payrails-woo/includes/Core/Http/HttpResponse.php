<?php
/**
 * HTTP response value object.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Http;

/**
 * Response.
 */
final class HttpResponse {

	/**
	 * Status code.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Headers (lower-case name => value).
	 *
	 * @var array<string, string>
	 */
	public array $headers;

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	public string $body;

	/**
	 * Constructor.
	 *
	 * @param int                   $status  Status.
	 * @param string                $body    Body.
	 * @param array<string, string> $headers Headers.
	 */
	public function __construct( int $status, string $body = '', array $headers = array() ) {
		$this->status  = $status;
		$this->body    = $body;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * Decoded JSON object, or null when the body is not a JSON object/array.
	 *
	 * @return array<mixed>|null
	 */
	public function json(): ?array {
		if ( '' === $this->body ) {
			return null;
		}
		$d = json_decode( $this->body, true );
		return is_array( $d ) ? $d : null;
	}

	/**
	 * JSON response helper (used by tests).
	 *
	 * @param int   $status Status.
	 * @param mixed $data   Data.
	 */
	public static function json_response( int $status, $data ): self {
		return new self( $status, (string) json_encode( $data ), array( 'content-type' => 'application/json' ) );
	}

	/**
	 * Redacted view (bodies may carry tokens).
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'status' => $this->status,
			'body'   => '[' . strlen( $this->body ) . ' bytes]',
		);
	}
}
