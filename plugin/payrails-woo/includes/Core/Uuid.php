<?php
/**
 * RFC 4122 UUIDs: v4 (random) and v5 (SHA-1, name-based).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * UUID helpers. Payrails requires x-idempotency-key to be a UUID.
 */
final class Uuid {

	/**
	 * Namespace for this plugin's v5 idempotency keys (a fixed random v4).
	 */
	public const NS_IDEMPOTENCY = '7b0e2f4c-5a51-4c7e-9b1e-3c1f0a6d2e90';

	/**
	 * Random v4 UUID.
	 */
	public static function v4(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		return self::format( $b );
	}

	/**
	 * Deterministic v5 UUID for a name inside a namespace UUID.
	 *
	 * @param string $namespace_uuid Namespace UUID (string form).
	 * @param string $name           Name.
	 * @throws \InvalidArgumentException When the namespace is not a UUID.
	 */
	public static function v5( string $namespace_uuid, string $name ): string {
		if ( ! self::is_valid( $namespace_uuid ) ) {
			throw new \InvalidArgumentException( 'Invalid namespace UUID' );
		}
		$ns_bytes = hex2bin( str_replace( '-', '', $namespace_uuid ) );
		$hash     = substr( sha1( $ns_bytes . $name, true ), 0, 16 );
		$hash[6]  = chr( ( ord( $hash[6] ) & 0x0f ) | 0x50 );
		$hash[8]  = chr( ( ord( $hash[8] ) & 0x3f ) | 0x80 );
		return self::format( $hash );
	}

	/**
	 * True for any RFC 4122 UUID string (versions 1-8, variant 10xx).
	 *
	 * @param string $uuid Candidate.
	 */
	public static function is_valid( string $uuid ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
	}

	/**
	 * 16 raw bytes to 8-4-4-4-12.
	 *
	 * @param string $bytes Raw bytes.
	 */
	private static function format( string $bytes ): string {
		$h = bin2hex( $bytes );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20 );
	}
}
