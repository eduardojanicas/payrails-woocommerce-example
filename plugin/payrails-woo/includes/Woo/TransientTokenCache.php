<?php
/**
 * TokenCache on transients (wp_options without an object cache; the DB is local and gitignored).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use PayrailsWoo\Core\Api\TokenCache;

defined( 'ABSPATH' ) || exit;

/**
 * Transient cache.
 */
final class TransientTokenCache implements TokenCache {

	/**
	 * Get.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): ?string {
		$v = get_transient( $key );
		return is_string( $v ) && '' !== $v ? $v : null;
	}

	/**
	 * Set.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @param int    $ttl   TTL.
	 */
	public function set( string $key, string $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}

	/**
	 * Delete.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		delete_transient( $key );
	}
}
