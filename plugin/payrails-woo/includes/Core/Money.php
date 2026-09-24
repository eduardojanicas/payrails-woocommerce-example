<?php
/**
 * Decimal strings <-> integer minor units, with string arithmetic only (no floats).
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Money helpers. Currency exponents come from this table, never from the
 * WooCommerce display setting (wc_get_price_decimals), which is cosmetic.
 */
final class Money {

	/**
	 * ISO 4217 minor-unit exponents. Anything not listed defaults to 2.
	 */
	public const EXPONENTS = array(
		'USD' => 2,
		'EUR' => 2,
		'GBP' => 2,
		'CHF' => 2,
		'SEK' => 2,
		'NOK' => 2,
		'DKK' => 2,
		'AED' => 2,
		'SAR' => 2,
		'JPY' => 0,
		'KRW' => 0,
		'VND' => 0,
		'CLP' => 0,
		'KWD' => 3,
		'BHD' => 3,
		'OMR' => 3,
		'JOD' => 3,
		'TND' => 3,
	);

	/**
	 * Exponent for a currency code.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function exponent( string $currency ): int {
		return self::EXPONENTS[ strtoupper( $currency ) ] ?? 2;
	}

	/**
	 * Converts a decimal string ("185", "185.5", "185.005", "-3.10") to minor units,
	 * rounding half up (away from zero) at the exponent.
	 *
	 * @param string|int|float $value    Decimal value. Floats are formatted first, never used in math.
	 * @param int              $exponent Minor-unit exponent.
	 * @throws \InvalidArgumentException When the value is not a plain decimal.
	 */
	public static function to_minor( $value, int $exponent ): int {
		if ( is_float( $value ) ) {
			$value = rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
		}
		$value = trim( (string) $value );
		if ( ! preg_match( '/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $m ) || ( '' === $m[2] && '' === ( $m[3] ?? '' ) ) ) {
			throw new \InvalidArgumentException( 'Not a decimal amount' );
		}
		$negative = '-' === $m[1];
		$int_part = '' === $m[2] ? '0' : $m[2];
		$frac     = $m[3] ?? '';

		$frac_padded = str_pad( $frac, $exponent + 1, '0' );
		$kept        = substr( $frac_padded, 0, $exponent );
		$next_digit  = (int) $frac_padded[ $exponent ];

		$minor = (int) ( ltrim( $int_part . $kept, '0' ) ?: '0' );
		if ( $next_digit >= 5 ) {
			++$minor;
		}
		return $negative ? -$minor : $minor;
	}

	/**
	 * Formats minor units as a decimal string with exactly $exponent decimals.
	 *
	 * @param int $minor    Minor units.
	 * @param int $exponent Exponent.
	 */
	public static function from_minor( int $minor, int $exponent ): string {
		$sign   = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( 0 === $exponent ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}
}
