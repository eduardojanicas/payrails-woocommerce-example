<?php
/**
 * Shape-only view of a payload: keys kept, values replaced by their type.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Used for logging response *shapes* without leaking tokens or personal data.
 */
final class KeyTree {

	/**
	 * Builds the key tree.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Max depth.
	 * @return mixed
	 */
	public static function of( $value, int $depth = 6 ) {
		if ( is_array( $value ) ) {
			if ( $depth <= 0 ) {
				return '…';
			}
			if ( array_is_list( $value ) ) {
				return $value ? array( self::of( $value[0], $depth - 1 ), '×' . count( $value ) ) : array();
			}
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::of( $v, $depth - 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return 'string';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 'number';
		}
		if ( is_bool( $value ) ) {
			return 'bool';
		}
		return null === $value ? 'null' : gettype( $value );
	}
}
