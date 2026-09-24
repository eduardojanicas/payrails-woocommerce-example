<?php
/**
 * Builds Config and PayrailsClient (per request, memoised).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use PayrailsWoo\Core\Api\PayrailsClient;
use PayrailsWoo\Core\Config;
use PayrailsWoo\Core\Exception\ConfigException;
use PayrailsWoo\Core\Http\CurlTransport;
use PayrailsWoo\Core\Http\HttpTransport;
use PayrailsWoo\Core\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Service locator (tiny, request-scoped).
 */
final class Services {

	/**
	 * Memoised config or exception.
	 *
	 * @var Config|ConfigException|null
	 */
	private static $config = null;

	/**
	 * Memoised client.
	 *
	 * @var PayrailsClient|null
	 */
	private static ?PayrailsClient $client = null;

	/**
	 * Secrets file path: PAYRAILS_SECRETS_FILE constant, then env.
	 */
	public static function secrets_file(): ?string {
		if ( defined( 'PAYRAILS_SECRETS_FILE' ) ) {
			return (string) constant( 'PAYRAILS_SECRETS_FILE' );
		}
		$env = getenv( 'PAYRAILS_SECRETS_FILE' );
		return is_string( $env ) && '' !== $env ? $env : null;
	}

	/**
	 * Payrails configuration (environment variables, else the PAYRAILS_SECRETS_FILE INI).
	 *
	 * @throws ConfigException When the configuration is incomplete.
	 */
	public static function config(): Config {
		if ( null === self::$config ) {
			try {
				self::$config = Config::load( self::secrets_file() );
			} catch ( ConfigException $e ) {
				self::$config = $e;
			}
		}
		if ( self::$config instanceof ConfigException ) {
			throw self::$config;
		}
		return self::$config;
	}

	/**
	 * True when the Payrails configuration is complete (the gateway can run).
	 */
	public static function is_configured(): bool {
		return ! self::config_problems();
	}

	/**
	 * Problems (key names only), or an empty list.
	 *
	 * @return string[]
	 */
	public static function config_problems(): array {
		try {
			self::config();
			return array();
		} catch ( ConfigException $e ) {
			return $e->problems;
		}
	}

	/**
	 * The Payrails API client.
	 *
	 * @throws ConfigException When the configuration is incomplete.
	 */
	public static function client(): PayrailsClient {
		if ( null === self::$client ) {
			$config       = self::config();
			self::$client = new PayrailsClient( $config, self::transport( $config ), new TransientTokenCache(), Logger::callable() );
		}
		return self::$client;
	}

	/**
	 * HTTP transport: cURL with the mTLS client certificate.
	 *
	 * Testing hook: the `payrails_woo_http_transport` filter may return any
	 * HttpTransport instead (for example a fake that answers like Payrails), so the
	 * whole order flow can be exercised without network access or credentials. See
	 * tests/php/integration/state-machine.php. Do not use it in production.
	 *
	 * @param Config $config Config.
	 */
	private static function transport( Config $config ): HttpTransport {
		$default   = new CurlTransport( $config->cert_path, $config->key_path );
		$transport = apply_filters( 'payrails_woo_http_transport', $default, $config );
		return $transport instanceof HttpTransport ? $transport : $default;
	}

	/**
	 * Workflow code: gateway setting override, then secrets, then the default.
	 */
	public static function workflow_code(): string {
		$settings = get_option( 'woocommerce_payrails_settings', array() );
		$override = is_array( $settings ) ? trim( (string) ( $settings['workflow_code'] ?? '' ) ) : '';
		if ( '' !== $override ) {
			return $override;
		}
		try {
			return self::config()->workflow_code;
		} catch ( ConfigException $e ) {
			return Config::DEFAULT_WORKFLOW;
		}
	}

	/**
	 * Random per-install id, mixed into idempotency seeds so a reset (which reuses
	 * order ids) never collides with a previous install's keys.
	 */
	public static function install_id(): string {
		$id = get_option( 'payrails_woo_install_id' );
		if ( ! is_string( $id ) || ! Uuid::is_valid( $id ) ) {
			$id = Uuid::v4();
			update_option( 'payrails_woo_install_id', $id, false );
		}
		return $id;
	}

	/**
	 * Resets memoised services (tests / CLI).
	 */
	public static function reset(): void {
		self::$config = null;
		self::$client = null;
	}
}
