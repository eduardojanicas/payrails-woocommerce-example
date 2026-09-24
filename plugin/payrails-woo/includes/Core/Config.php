<?php
/**
 * Payrails configuration from environment variables or an INI secrets file.
 *
 * Secrets never live in the database. This object redacts itself in var_dump,
 * print_r, serialize and json_encode so a stray log line cannot leak them.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

use PayrailsWoo\Core\Exception\ConfigException;

/**
 * Immutable config value object.
 */
final class Config implements \JsonSerializable {

	public const DEFAULT_WORKFLOW = 'payment-acceptance';

	/**
	 * All keys we read, without the PAYRAILS_ prefix.
	 */
	public const KEYS     = array( 'API_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'WORKSPACE_ID', 'WORKFLOW_CODE', 'CERT_PATH', 'KEY_PATH' );
	public const REQUIRED = array( 'API_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'WORKSPACE_ID', 'CERT_PATH', 'KEY_PATH' );

	/**
	 * API base URL without trailing slash.
	 *
	 * @var string
	 */
	public string $api_url;

	/**
	 * Client id (an identifier, not a secret, but still never logged).
	 *
	 * @var string
	 */
	public string $client_id;

	/**
	 * Client secret.
	 *
	 * @var string
	 */
	private string $client_secret;

	/**
	 * Workspace id or null.
	 *
	 * @var string|null
	 */
	public ?string $workspace_id;

	/**
	 * Workflow code.
	 *
	 * @var string
	 */
	public string $workflow_code;

	/**
	 * Absolute path to the mTLS client certificate.
	 *
	 * @var string
	 */
	public string $cert_path;

	/**
	 * Absolute path to the mTLS private key.
	 *
	 * @var string
	 */
	public string $key_path;

	/**
	 * Where the values came from: "env" or "file".
	 *
	 * @var string
	 */
	public string $source;

	/**
	 * Constructor.
	 *
	 * @param string      $api_url       API URL.
	 * @param string      $client_id     Client id.
	 * @param string      $client_secret Client secret.
	 * @param string|null $workspace_id  Workspace id.
	 * @param string      $workflow_code Workflow code.
	 * @param string      $cert_path     Cert path.
	 * @param string      $key_path      Key path.
	 * @param string      $source        Source label.
	 */
	public function __construct(
		string $api_url,
		string $client_id,
		#[\SensitiveParameter] string $client_secret,
		?string $workspace_id,
		string $workflow_code,
		string $cert_path,
		string $key_path,
		string $source
	) {
		$this->api_url       = rtrim( $api_url, '/' );
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->workspace_id  = ( null === $workspace_id || '' === $workspace_id ) ? null : $workspace_id;
		$this->workflow_code = '' === $workflow_code ? self::DEFAULT_WORKFLOW : $workflow_code;
		$this->cert_path     = $cert_path;
		$this->key_path      = $key_path;
		$this->source        = $source;
	}

	/**
	 * The client secret. Only PayrailsClient should call this.
	 */
	public function client_secret(): string {
		return $this->client_secret;
	}

	/**
	 * Loads config. Precedence: environment (all required keys present) → INI file.
	 *
	 * @param string|null                $file Secrets file path (PAYRAILS_SECRETS_FILE).
	 * @param array<string, string>|null $env  Environment map; defaults to getenv().
	 * @throws ConfigException When required keys are missing or cert/key are unreadable.
	 */
	public static function load( ?string $file, ?array $env = null ): self {
		$raw      = self::raw_values( $file, $env );
		$problems = array();
		foreach ( self::REQUIRED as $k ) {
			if ( '' === ( $raw['values'][ $k ] ?? '' ) ) {
				$problems[] = 'PAYRAILS_' . $k;
			}
		}
		if ( null !== $raw['error'] ) {
			$problems[] = $raw['error'];
		}
		$api_url = (string) ( $raw['values']['API_URL'] ?? '' );
		if ( '' !== $api_url && ! self::is_payrails_url( $api_url ) ) {
			// The client secret is sent to this host, so only Payrails hosts are accepted.
			$problems[] = 'PAYRAILS_API_URL (not an https://*.payrails.io URL)';
		}
		if ( ! $problems ) {
			if ( ! is_readable( $raw['values']['CERT_PATH'] ) ) {
				$problems[] = 'PAYRAILS_CERT_PATH (not readable)';
			}
			if ( ! is_readable( $raw['values']['KEY_PATH'] ) ) {
				$problems[] = 'PAYRAILS_KEY_PATH (not readable)';
			}
		}
		if ( $problems ) {
			throw new ConfigException( $problems );
		}
		$v = $raw['values'];
		return new self(
			$api_url,
			$v['CLIENT_ID'],
			$v['CLIENT_SECRET'],
			$v['WORKSPACE_ID'] ?? null,
			$v['WORKFLOW_CODE'] ?? '',
			$v['CERT_PATH'],
			$v['KEY_PATH'],
			$raw['source']
		);
	}

	/**
	 * True for https URLs on payrails.io or a subdomain. No userinfo, no other scheme.
	 *
	 * Test tooling only: when the PAYRAILS_WOO_TESTING constant is true (defined by the
	 * repository's test scripts, never by the plugin), hosts under the reserved
	 * .payrails.invalid name (RFC 6761: never resolves) are also accepted, so failure
	 * paths can be tested without network traffic.
	 *
	 * @param string    $url              URL.
	 * @param bool|null $allow_test_hosts Override for the constant (unit tests).
	 */
	public static function is_payrails_url( string $url, ?bool $allow_test_hosts = null ): bool {
		$allow_test_hosts = $allow_test_hosts ?? ( defined( 'PAYRAILS_WOO_TESTING' ) && true === constant( 'PAYRAILS_WOO_TESTING' ) );
		$p = parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		$host = strtolower( rtrim( (string) ( $p['host'] ?? '' ), '.' ) );
		return 'payrails.io' === $host || str_ends_with( $host, '.payrails.io' ) || ( $allow_test_hosts && str_ends_with( $host, '.payrails.invalid' ) );
	}

	/**
	 * Presence report for the settings page: which keys are set (booleans only),
	 * whether cert/key are readable, and non-secret facts (API host, source).
	 *
	 * @param string|null                $file Secrets file.
	 * @param array<string, string>|null $env  Environment.
	 * @return array{source:string, file:?string, file_readable:bool, keys:array<string,bool>, cert_readable:bool, key_readable:bool, api_host:string, workflow_code:string, error:?string}
	 */
	public static function inspect( ?string $file, ?array $env = null ): array {
		$raw  = self::raw_values( $file, $env );
		$keys = array();
		foreach ( self::KEYS as $k ) {
			$keys[ 'PAYRAILS_' . $k ] = '' !== ( $raw['values'][ $k ] ?? '' );
		}
		$api = (string) ( $raw['values']['API_URL'] ?? '' );
		return array(
			'source'        => $raw['source'],
			'file'          => $file,
			'file_readable' => null !== $file && is_readable( $file ),
			'keys'          => $keys,
			'cert_readable' => '' !== ( $raw['values']['CERT_PATH'] ?? '' ) && is_readable( $raw['values']['CERT_PATH'] ),
			'key_readable'  => '' !== ( $raw['values']['KEY_PATH'] ?? '' ) && is_readable( $raw['values']['KEY_PATH'] ),
			'api_host'      => (string) parse_url( $api, PHP_URL_HOST ),
			'workflow_code' => '' !== ( $raw['values']['WORKFLOW_CODE'] ?? '' ) ? $raw['values']['WORKFLOW_CODE'] : self::DEFAULT_WORKFLOW,
			'error'         => $raw['error'] ?? ( self::is_payrails_url( $api ) ? null : 'PAYRAILS_API_URL (not an https://*.payrails.io URL)' ),
		);
	}

	/**
	 * Resolves raw values from env or file. Never throws.
	 *
	 * @param string|null                $file File.
	 * @param array<string, string>|null $env  Env.
	 * @return array{values:array<string,string>, source:string, error:?string}
	 */
	private static function raw_values( ?string $file, ?array $env ): array {
		$env      = $env ?? ( getenv() ?: array() );
		$from_env = array();
		foreach ( self::KEYS as $k ) {
			$val = $env[ 'PAYRAILS_' . $k ] ?? '';
			if ( is_string( $val ) && '' !== trim( $val ) ) {
				$from_env[ $k ] = trim( $val );
			}
		}
		$env_complete = true;
		foreach ( self::REQUIRED as $k ) {
			if ( ! isset( $from_env[ $k ] ) ) {
				$env_complete = false;
			}
		}
		if ( $env_complete ) {
			return array(
				'values' => $from_env,
				'source' => 'env',
				'error'  => null,
			);
		}

		if ( null === $file || '' === $file ) {
			return array(
				'values' => $from_env,
				'source' => 'none',
				'error'  => 'PAYRAILS_SECRETS_FILE (not set)',
			);
		}
		if ( ! is_readable( $file ) ) {
			return array(
				'values' => $from_env,
				'source' => 'file',
				'error'  => 'PAYRAILS_SECRETS_FILE (not readable)',
			);
		}
		// RAW keeps values containing "$" or other INI metacharacters intact.
		$ini = @parse_ini_file( $file, false, INI_SCANNER_RAW ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $ini ) ) {
			return array(
				'values' => $from_env,
				'source' => 'file',
				'error'  => 'PAYRAILS_SECRETS_FILE (not valid INI)',
			);
		}
		$values = array();
		foreach ( self::KEYS as $k ) {
			$val = $ini[ 'PAYRAILS_' . $k ] ?? '';
			if ( ! is_string( $val ) ) {
				continue;
			}
			$val = self::unquote( trim( $val ) );
			if ( '' !== $val ) {
				$values[ $k ] = $val;
			}
		}
		// Explicit env values override single file values (for ad-hoc testing).
		$values = array_merge( $values, $from_env );
		foreach ( array( 'CERT_PATH', 'KEY_PATH' ) as $k ) {
			if ( isset( $values[ $k ] ) && ! self::is_absolute( $values[ $k ] ) ) {
				$values[ $k ] = dirname( $file ) . '/' . $values[ $k ];
			}
		}
		return array(
			'values' => $values,
			'source' => 'file',
			'error'  => null,
		);
	}

	/**
	 * Strips one pair of surrounding quotes.
	 *
	 * @param string $v Value.
	 */
	private static function unquote( string $v ): string {
		$len = strlen( $v );
		if ( $len >= 2 && ( ( '"' === $v[0] && '"' === $v[ $len - 1 ] ) || ( "'" === $v[0] && "'" === $v[ $len - 1 ] ) ) ) {
			return substr( $v, 1, -1 );
		}
		return $v;
	}

	/**
	 * Absolute path check (POSIX or Windows drive).
	 *
	 * @param string $p Path.
	 */
	private static function is_absolute( string $p ): bool {
		return '' !== $p && ( '/' === $p[0] || 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $p ) );
	}

	/**
	 * Redacted view for var_dump / print_r.
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return $this->redacted();
	}

	/**
	 * Redacted serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function __serialize(): array {
		return $this->redacted();
	}

	/**
	 * Refuses to be rebuilt from a (redacted) serialization.
	 *
	 * @param array<string, mixed> $data Data.
	 * @throws \LogicException Always.
	 */
	public function __unserialize( array $data ): void {
		throw new \LogicException( 'Config cannot be unserialized' );
	}

	/**
	 * Redacted JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->redacted();
	}

	/**
	 * Redacted array.
	 *
	 * @return array<string, mixed>
	 */
	private function redacted(): array {
		return array(
			'api_url'       => $this->api_url,
			'client_id'     => '[redacted]',
			'client_secret' => '[redacted]',
			'workspace_id'  => null === $this->workspace_id ? null : '[set]',
			'workflow_code' => $this->workflow_code,
			'cert_path'     => '[set]',
			'key_path'      => '[set]',
			'source'        => $this->source,
		);
	}
}
