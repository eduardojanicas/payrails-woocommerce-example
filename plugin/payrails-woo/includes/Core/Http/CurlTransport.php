<?php
/**
 * cURL transport with mTLS (client certificate + key by path).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Http;

use PayrailsWoo\Core\Exception\TransportException;

/**
 * Raw cURL, because WP's HTTP API has no first-class client-certificate option.
 * PHP never reads the PEM contents: cURL is handed the paths.
 */
final class CurlTransport implements HttpTransport {

	/**
	 * Cert path.
	 *
	 * @var string
	 */
	private string $cert_path;

	/**
	 * Key path.
	 *
	 * @var string
	 */
	private string $key_path;

	/**
	 * Timeouts in seconds.
	 *
	 * @var int
	 */
	private int $connect_timeout;

	/**
	 * Total timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $cert_path       Client certificate (PEM).
	 * @param string $key_path        Client key (PEM).
	 * @param int    $connect_timeout Connect timeout.
	 * @param int    $timeout         Total timeout.
	 */
	public function __construct( string $cert_path, string $key_path, int $connect_timeout = 5, int $timeout = 20 ) {
		$this->cert_path       = $cert_path;
		$this->key_path        = $key_path;
		$this->connect_timeout = $connect_timeout;
		$this->timeout         = $timeout;
	}

	/**
	 * Payrails integration — step 3 (transport): every merchant-API call presents the
	 * client certificate and key (CURLOPT_SSLCERT / CURLOPT_SSLKEY), with TLS verification on.
	 *
	 * Sends the request.
	 *
	 * @param HttpRequest $request Request.
	 * @throws TransportException On cURL error.
	 */
	public function send( HttpRequest $request ): HttpResponse {
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close
		$ch      = curl_init( $request->url );
		$headers = array();
		foreach ( $request->headers as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}
		$opts = array(
			CURLOPT_CUSTOMREQUEST  => $request->method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_SSLCERT        => $this->cert_path,
			CURLOPT_SSLCERTTYPE    => 'PEM',
			CURLOPT_SSLKEY         => $this->key_path,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_USERAGENT      => 'payrails-woo/0.1',
		);
		if ( null !== $request->body ) {
			$opts[ CURLOPT_POSTFIELDS ] = $request->body;
		} elseif ( 'POST' === $request->method ) {
			$opts[ CURLOPT_POSTFIELDS ] = '';
		}
		curl_setopt_array( $ch, $opts );
		$raw   = curl_exec( $ch );
		$errno = curl_errno( $ch );
		if ( 0 !== $errno || false === $raw ) {
			// Only the errno travels on: curl_error() text can contain local file paths.
			curl_close( $ch );
			throw new TransportException( $errno ?: 1 );
		}
		$status      = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );
		// phpcs:enable

		$head = substr( (string) $raw, 0, $header_size );
		$body = (string) substr( (string) $raw, $header_size );
		return new HttpResponse( $status, $body, self::parse_headers( $head ) );
	}

	/**
	 * Parses the last header block (after any 100-continue / redirects).
	 *
	 * @param string $head Raw header text.
	 * @return array<string, string>
	 */
	private static function parse_headers( string $head ): array {
		$blocks = preg_split( "/\r?\n\r?\n/", trim( $head ) );
		$last   = (string) end( $blocks );
		$out    = array();
		foreach ( preg_split( "/\r?\n/", $last ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( false !== $pos ) {
				$out[ strtolower( trim( substr( $line, 0, $pos ) ) ) ] = trim( substr( $line, $pos + 1 ) );
			}
		}
		return $out;
	}
}
