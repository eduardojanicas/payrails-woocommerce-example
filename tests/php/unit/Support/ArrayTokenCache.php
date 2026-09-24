<?php
/**
 * In-memory TokenCache for the unit tests.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Tests\Support;

use PayrailsWoo\Core\Api\TokenCache;

/**
 * Array cache; records TTLs so tests can assert them.
 */
final class ArrayTokenCache implements TokenCache {

	/**
	 * Values.
	 *
	 * @var array<string, string>
	 */
	public array $values = array();

	/**
	 * TTLs.
	 *
	 * @var array<string, int>
	 */
	public array $ttls = array();

	/**
	 * Get.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): ?string {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Set.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @param int    $ttl   TTL.
	 */
	public function set( string $key, string $value, int $ttl ): void {
		$this->values[ $key ] = $value;
		$this->ttls[ $key ]   = $ttl;
	}

	/**
	 * Delete.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		unset( $this->values[ $key ], $this->ttls[ $key ] );
	}
}
