<?php
/**
 * Per-order mutex around the order transition (ExecutionSession::settle()).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * A row in wp_options inserted with INSERT IGNORE: option_name is UNIQUE, so
 * exactly one request wins (add_option() is not atomic: it reads, then upserts).
 *
 * The value is "{expires}|{owner token}". release() deletes only the row holding
 * the caller's own token, so a request whose lock was taken over after expiry can
 * never release the new holder's lock.
 *
 * TTL: no Payrails call is made while the lock is held. settle() reads the
 * execution BEFORE locking, and the locked section only re-reads the order and
 * writes it (normally well under a second). The TTL is 120 s anyway, which is also
 * above the worst-case upstream budget of a read (token + GET + 401 re-token + GET,
 * with one 5xx retry: 8 × 20 s cURL timeouts ≈ 160 s is the theoretical maximum,
 * but those calls are all outside the lock).
 */
final class OrderLock {

	public const TTL = 120;

	/**
	 * Acquires the lock. Returns the owner token, or null when another request holds it.
	 * A lock older than its TTL is taken over.
	 *
	 * @param int $order_id Order id.
	 * @param int $ttl      Seconds.
	 */
	public static function acquire( int $order_id, int $ttl = self::TTL ): ?string {
		global $wpdb;
		$name  = self::name( $order_id );
		$token = wp_generate_password( 32, false, false );
		$value = ( time() + $ttl ) . '|' . $token;
		for ( $i = 0; $i < 2; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
			if ( 1 === (int) $inserted ) {
				return $token;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$seen    = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$expires = (int) strtok( $seen, '|' );
			if ( '' !== $seen && $expires >= time() ) {
				return null;
			}
			// Expired (or vanished): delete exactly the row we saw, then retry once.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $seen ) );
		}
		return null;
	}

	/**
	 * Releases the lock, only if it is still ours.
	 *
	 * @param int    $order_id Order id.
	 * @param string $token    Token returned by acquire().
	 */
	public static function release( int $order_id, string $token ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::name( $order_id ), '%|' . $wpdb->esc_like( $token ) ) );
		wp_cache_delete( self::name( $order_id ), 'options' );
	}

	/**
	 * Option name.
	 *
	 * @param int $order_id Order id.
	 */
	private static function name( int $order_id ): string {
		return 'payrails_woo_lock_' . $order_id;
	}
}
