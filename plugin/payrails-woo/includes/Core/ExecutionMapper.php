<?php
/**
 * Execution JSON → authorized | failed | pending.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Core;

/**
 * Maps a Payrails execution's status[] history to authorized | failed | pending.
 *
 * status[] is sorted by timestamp before mapping (the integration does not rely on
 * array order). The decision is then made from the LATEST TERMINAL code within the
 * latest authorization attempt (the entries since the last authorizeRequested):
 * - authorized: the latest terminal code is exactly authorizeSuccessful or
 *   captureSuccessful (anchored, so e.g. preAuthorizeSuccessful never counts);
 * - failed: the latest terminal code is a failure, cancel, void, reversal, refund or
 *   expiry, so a success that was later cancelled or voided is NOT authorized;
 * - pending: no terminal code yet, or an UNKNOWN code after the latest terminal one
 *   (conservative: an unrecognised later event never lets an order be paid).
 * Known in-progress codes (created, authorizeRequested, authorizePending,
 * captureRequested, capturePending) do not change a terminal outcome: a decline can be
 * followed by a trailing authorizePending, and a success by a capture request.
 */
final class ExecutionMapper {

	public const SUCCESS_RE  = '/^(authorize|capture)Successful$/i';
	public const FAILURE_RE  = '/^[a-z0-9]*(failed|declined|cancelled|canceled|rejected|expired|voided|reversed|refunded)$/i';
	public const UNDO_RE     = '/^(void|cancel|cancellation|reversal|reverse|refund)[a-z0-9]*successful$/i';
	public const PROGRESS_RE = '/^(created|authorizeRequested|authorizePending|captureRequested|capturePending)$/i';
	public const ATTEMPT_RE  = '/^authorizeRequested$/i';

	/**
	 * Sortable key for an RFC 3339 timestamp with up to nanosecond precision
	 * ("2026-09-23T22:25:38.410334201Z"). Unparseable or missing times sort first,
	 * so a timeless entry can never be treated as the latest one.
	 *
	 * @param string $time Timestamp.
	 */
	public static function time_key( string $time ): string {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9})\d*)?(Z|[+-]\d{2}:?\d{2})$/i', trim( $time ), $m ) ) {
			return '';
		}
		try {
			$dt = new \DateTimeImmutable( $m[1] . ( 'z' === strtolower( $m[3] ) ? '+00:00' : $m[3] ) );
		} catch ( \Exception $e ) {
			return '';
		}
		return sprintf( '%012d.%s', $dt->getTimestamp(), str_pad( $m[2] ?? '', 9, '0' ) );
	}

	/**
	 * Maps an execution response.
	 *
	 * @param array<string, mixed> $ex Execution JSON.
	 */
	public static function map( array $ex ): ExecutionResult {
		// status[] is sorted by timestamp before mapping; array order is not relied on.
		$entries = array();
		foreach ( ( is_array( $ex['status'] ?? null ) ? $ex['status'] : array() ) as $i => $entry ) {
			if ( is_array( $entry ) && isset( $entry['code'] ) && is_string( $entry['code'] ) ) {
				$reason    = $entry['errors'][0]['reason']['result'] ?? ( $entry['errors'][0]['code'] ?? null );
				$entries[] = array(
					'reason' => is_string( $reason ) ? substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $reason ), 0, 60 ) : null,
					'code'   => $entry['code'],
					'key'    => self::time_key( isset( $entry['time'] ) && is_string( $entry['time'] ) ? $entry['time'] : '' ),
					'i'      => (int) $i,
				);
			}
		}
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				// strcmp, not <=>: numeric strings would be compared as floats and lose the nanoseconds.
				$c = strcmp( $a['key'], $b['key'] );
				return 0 !== $c ? $c : $a['i'] <=> $b['i'];
			}
		);
		$codes     = array_column( $entries, 'code' );
		$attempted = (bool) array_filter(
			$codes,
			static function ( string $c ): bool {
				return 'created' !== $c;
			}
		);

		$id  = isset( $ex['id'] ) && is_scalar( $ex['id'] ) ? (string) $ex['id'] : null;
		$ref = isset( $ex['merchantReference'] ) && is_scalar( $ex['merchantReference'] ) ? (string) $ex['merchantReference'] : null;
		$val = isset( $ex['amount']['value'] ) && is_scalar( $ex['amount']['value'] ) ? (string) $ex['amount']['value'] : null;
		$cur = isset( $ex['amount']['currency'] ) && is_string( $ex['amount']['currency'] ) ? $ex['amount']['currency'] : null;

		// The latest attempt: a retry after a decline starts with a new authorizeRequested.
		$start = 0;
		foreach ( $codes as $i => $c ) {
			if ( preg_match( self::ATTEMPT_RE, $c ) ) {
				$start = $i;
			}
		}
		$state    = ExecutionResult::PENDING;
		$terminal = null;
		$reason   = null;
		$captured = false;
		$unknown  = null;
		foreach ( array_slice( $entries, $start ) as $e ) {
			$c = $e['code'];
			if ( preg_match( self::SUCCESS_RE, $c ) ) {
				$state    = ExecutionResult::AUTHORIZED;
				$terminal = $c;
				$reason   = null;
				$unknown  = null;
				$captured = $captured || 0 === strcasecmp( $c, 'captureSuccessful' );
			} elseif ( preg_match( self::FAILURE_RE, $c ) || preg_match( self::UNDO_RE, $c ) ) {
				$state    = ExecutionResult::FAILED;
				$terminal = $c;
				$reason   = $e['reason'];
				$unknown  = null;
				$captured = false;
			} elseif ( ! preg_match( self::PROGRESS_RE, $c ) ) {
				$unknown = $c; // Unrecognised: decided conservatively below.
			}
		}
		if ( null !== $unknown ) {
			$state = ExecutionResult::PENDING;
		}

		if ( ExecutionResult::AUTHORIZED === $state ) {
			return new ExecutionResult( ExecutionResult::AUTHORIZED, $terminal, $codes, true, $id, $ref, $val, $cur, $captured );
		}
		if ( ExecutionResult::FAILED === $state ) {
			$r                 = new ExecutionResult( ExecutionResult::FAILED, $terminal, $codes, true, $id, $ref, $val, $cur );
			$r->failure_reason = $reason;
			return $r;
		}
		$last          = $codes ? (string) end( $codes ) : null;
		$r             = new ExecutionResult( ExecutionResult::PENDING, $last, $codes, $attempted, $id, $ref, $val, $cur );
		$r->action_url = self::action_url( $ex );
		return $r;
	}

	/**
	 * The 3DS redirect for an authorizePending execution: requiredAction.href
	 * (type "redirect") or links["3ds"]. Only https URLs on payrails.io are accepted,
	 * because the browser will be sent there.
	 *
	 * @param array<string, mixed> $ex Execution JSON.
	 */
	public static function action_url( array $ex ): ?string {
		$candidates = array();
		if ( isset( $ex['requiredAction']['href'] ) && 'redirect' === ( $ex['requiredAction']['type'] ?? 'redirect' ) ) {
			$candidates[] = $ex['requiredAction']['href'];
		}
		if ( isset( $ex['links']['3ds'] ) ) {
			$candidates[] = is_array( $ex['links']['3ds'] ) ? ( $ex['links']['3ds']['href'] ?? null ) : $ex['links']['3ds'];
		}
		foreach ( $candidates as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$parts = parse_url( $url );
			$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
			if ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && ( 'payrails.io' === $host || str_ends_with( $host, '.payrails.io' ) ) && ! isset( $parts['user'] ) ) {
				return $url;
			}
		}
		return null;
	}
}
