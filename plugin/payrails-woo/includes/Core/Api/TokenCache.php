<?php
/**
 * Access-token cache abstraction.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core\Api;

/**
 * Cache.
 */
interface TokenCache {

	/**
	 * Reads a token, or null.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): ?string;

	/**
	 * Stores a token.
	 *
	 * @param string $key   Key.
	 * @param string $value Token.
	 * @param int    $ttl   Seconds.
	 */
	public function set( string $key, string $value, int $ttl ): void;

	/**
	 * Deletes a token.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void;
}
