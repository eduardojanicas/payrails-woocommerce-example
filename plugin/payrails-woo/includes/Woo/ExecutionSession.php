<?php
/**
 * Order ↔ Payrails execution bookkeeping, the pay-page decision and the
 * server-verified order transitions.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use PayrailsWoo\Core\ClientInitBuilder;
use PayrailsWoo\Core\ConfirmPolicy;
use PayrailsWoo\Core\ExecutionMapper;
use PayrailsWoo\Core\ExecutionResult;
use PayrailsWoo\Core\ExecutionVerifier;
use PayrailsWoo\Core\Exception\ApiException;
use PayrailsWoo\Core\Exception\ConfigException;
use PayrailsWoo\Core\Exception\PayrailsException;
use PayrailsWoo\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Session.
 */
final class ExecutionSession {

	public const META_CURRENT     = '_payrails_execution_id';
	public const META_ALL         = '_payrails_execution_ids';
	public const META_WORKFLOW    = '_payrails_workflow_code';
	public const META_FINGERPRINT = '_payrails_fingerprint';
	public const META_ATTEMPT     = '_payrails_attempt';
	public const META_LAST_ERROR  = '_payrails_last_error';

	public const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Per-request memo of execution reads.
	 *
	 * @var array<string, ExecutionResult>
	 */
	private static array $reads = array();

	/**
	 * Every execution id we created for this order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	public static function known_ids( \WC_Order $order ): array {
		$ids = json_decode( (string) $order->get_meta( self::META_ALL ), true );
		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * Current execution id.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function current_id( \WC_Order $order ): ?string {
		$id = (string) $order->get_meta( self::META_CURRENT );
		return '' === $id ? null : $id;
	}

	/**
	 * Workflow code recorded at init.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function workflow_code( \WC_Order $order ): string {
		$wf = (string) $order->get_meta( self::META_WORKFLOW );
		return '' === $wf ? Services::workflow_code() : $wf;
	}

	/**
	 * Reads and maps an execution (memoised per request).
	 *
	 * @param \WC_Order $order        Order.
	 * @param string    $execution_id Execution id.
	 * @param bool      $fresh        Bypass the memo.
	 * @throws PayrailsException On API failure.
	 */
	public static function read( \WC_Order $order, string $execution_id, bool $fresh = false ): ExecutionResult {
		if ( ! $fresh && isset( self::$reads[ $execution_id ] ) ) {
			return self::$reads[ $execution_id ];
		}
		$raw    = Services::client()->get_execution( self::workflow_code( $order ), $execution_id );
		$result = ExecutionMapper::map( $raw );
		Logger::log(
			'info',
			'execution read',
			array(
				'order'    => $order->get_id(),
				'state'    => $result->state,
				'codes'    => $result->codes,
				'captured' => $result->captured,
			)
		);
		self::$reads[ $execution_id ] = $result;
		return $result;
	}

	/**
	 * Decides what the pay page shows: mount the Drop-in (a new or cached client-init),
	 * keep polling (an authorization in flight), paid (redirect) or a safe error.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{mode:string, client_init?:array<string,mixed>, execution_id?:string, code?:string, redirect?:string}
	 */
	public static function prepare( \WC_Order $order ): array {
		if ( ! Services::is_configured() ) {
			return array(
				'mode' => 'error',
				'code' => 'PR-CONFIG',
			);
		}
		try {
			$config = Services::config();
			$client = Services::client();
		} catch ( PayrailsException $e ) {
			self::record_error( $order, $e, 'configuration' );
			return array(
				'mode' => 'error',
				'code' => $e->public_code(),
			);
		}

		$snap = SnapshotFactory::from( $order );
		$wf   = Services::workflow_code();
		$fp   = $snap->fingerprint( $wf );
		$cur  = self::current_id( $order );

		if ( null !== $cur ) {
			try {
				$res = self::read( $order, $cur );
				if ( $res->is_authorized() ) {
					$out = self::settle( $order->get_id(), $cur, $res );
					if ( ! empty( $out['locked'] ) ) {
						// Another request (a confirm, another tab) is completing this order
						// right now: show "pending" and let pay.js poll the confirm endpoint.
						return array(
							'mode'         => 'pending',
							'execution_id' => $cur,
						);
					}
					if ( 'authorized' === $out['state'] ) {
						return array(
							'mode'     => 'paid',
							'redirect' => (string) $out['redirect'],
						);
					}
					return array(
						'mode' => 'error',
						'code' => (string) ( $out['code'] ?? 'PR-VERIFY' ),
					);
				}
				if ( ExecutionResult::PENDING === $res->state && $res->attempted ) {
					return array(
						'mode'         => 'pending',
						'execution_id' => $cur,
					);
				}
				if ( ExecutionResult::FAILED === $res->state ) {
					self::settle( $order->get_id(), $cur, $res );
					delete_transient( self::cache_key( $order ) );
					$order = self::fresh_order( $order->get_id() ) ?? $order;
				}
			} catch ( PayrailsException $e ) {
				Logger::log( 'warning', 'execution read failed on pay page; continuing', array( 'code' => $e->public_code() ) );
			}
		}

		$cache = get_transient( self::cache_key( $order ) );
		if ( is_array( $cache ) && ( $cache['fingerprint'] ?? '' ) === $fp && ( $cache['execution_id'] ?? '' ) === $cur
			&& ( time() - (int) ( $cache['created_at'] ?? 0 ) ) < self::CACHE_TTL && is_array( $cache['client_init'] ?? null ) ) {
			return array(
				'mode'         => 'mount',
				'client_init'  => $cache['client_init'],
				'execution_id' => (string) $cur,
			);
		}

		// A new client-init (no usable cached session) always gets a new attempt number,
		// hence a new idempotency key and a new execution. Reusing the old key after the
		// cache expired would make Payrails replay the ORIGINAL response, including its
		// SDK session token, which may have expired by then (the Drop-in would then fail
		// with sessionExpired and loop). Within one attempt the key stays deterministic,
		// so concurrent page loads still share one execution. Failed executions and
		// amount changes also lead here (the cache is dropped / the fingerprint differs).
		$attempt = (int) $order->get_meta( self::META_ATTEMPT ) + 1;
		$builder = new ClientInitBuilder();
		$body    = $builder->build( $snap, $wf, $config->workspace_id, SnapshotFactory::client_context( $order ) );
		if ( $builder->warnings ) {
			Logger::log(
				'warning',
				'client-init built with warnings',
				array(
					'order'    => $order->get_id(),
					'warnings' => $builder->warnings,
				)
			);
		}
		$seed = 'wc-' . Services::install_id() . '-' . $order->get_id() . '-' . $fp . '-' . $attempt;
		try {
			// Payrails integration — step 4 (per order): one execution per order and
			// attempt; the deterministic seed makes a double page load reuse it.
			$ci = $client->client_init( $body, $seed );
		} catch ( PayrailsException $e ) {
			self::record_error( $order, $e, 'client-init' );
			return array(
				'mode' => 'error',
				'code' => $e->public_code(),
			);
		}
		$eid   = $ci['execution_id'];
		$known = self::known_ids( $order );
		if ( ! in_array( $eid, $known, true ) ) {
			$known[] = $eid;
		}
		$order->update_meta_data( self::META_CURRENT, $eid );
		$order->update_meta_data( self::META_ALL, wp_json_encode( $known ) );
		$order->update_meta_data( self::META_WORKFLOW, $wf );
		$order->update_meta_data( self::META_FINGERPRINT, $fp );
		$order->update_meta_data( self::META_ATTEMPT, $attempt );
		$order->delete_meta_data( self::META_LAST_ERROR );
		$order->save();

		set_transient(
			self::cache_key( $order ),
			array(
				'fingerprint'  => $fp,
				'execution_id' => $eid,
				'client_init'  => $ci['response'],
				'created_at'   => time(),
			),
			self::CACHE_TTL
		);
		return array(
			'mode'         => 'mount',
			'client_init'  => $ci['response'],
			'execution_id' => $eid,
		);
	}

	/**
	 * Payrails integration — step 6 (completion): THE ONLY path from a Payrails result to an order transition (payment_complete,
	 * failed, on-hold). Used by the confirm endpoint, the pay-page precheck and
	 * prepare(). Without a single locked writer, a pay-page reload during a confirm
	 * could complete the order again on its own stale in-memory copy (duplicate
	 * payment_complete(), notes and e-mails).
	 *
	 * The execution is read by the caller BEFORE this call: no Payrails request is
	 * ever made while the lock is held. Under the lock the order is re-read from the
	 * database (object caches dropped), and a paid order is never touched again.
	 *
	 * @param int             $order_id     Order id.
	 * @param string          $execution_id Execution id (already on the allow-list).
	 * @param ExecutionResult $res          Mapped execution.
	 * @return array{state:string, redirect?:string, message?:string, code?:string, locked?:bool, retryAfterMs?:int, action?:array<string,string>}
	 */
	public static function settle( int $order_id, string $execution_id, ExecutionResult $res ): array {
		$token = OrderLock::acquire( $order_id );
		if ( null === $token ) {
			return array(
				'state'        => 'pending',
				'retryAfterMs' => 1000,
				'locked'       => true,
			);
		}
		try {
			$order = self::fresh_order( $order_id );
			if ( null === $order ) {
				return array(
					'state' => 'error',
					'code'  => 'not_found',
				);
			}
			if ( $order->is_paid() || '' !== (string) $order->get_transaction_id() ) {
				self::note_second_authorization( $order, $execution_id, $res );
				return array(
					'state'    => 'authorized',
					'redirect' => $order->get_checkout_order_received_url(),
				);
			}
			return self::apply( $order, $execution_id, $res );
		} finally {
			OrderLock::release( $order_id, $token );
		}
	}

	/**
	 * The order as stored now: drops WooCommerce's in-request order cache first, so a
	 * completion made by another request is seen.
	 *
	 * @param int $order_id Order id.
	 */
	public static function fresh_order( int $order_id ): ?\WC_Order {
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( 'order-' . $order_id, 'orders' );
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * A different execution of an already-paid order is authorized too (two tabs, or
	 * a tab kept open past the cache): add ONE note so the merchant can void it.
	 *
	 * @param \WC_Order       $order        Paid order (fresh, under the lock).
	 * @param string          $execution_id Execution being confirmed.
	 * @param ExecutionResult $res          Its mapped state.
	 */
	private static function note_second_authorization( \WC_Order $order, string $execution_id, ExecutionResult $res ): void {
		$txn = (string) $order->get_transaction_id();
		if ( ! $res->is_authorized() || '' === $txn || $txn === $execution_id ) {
			return;
		}
		$noted = (array) json_decode( (string) $order->get_meta( '_payrails_extra_authorizations' ), true );
		if ( in_array( $execution_id, $noted, true ) ) {
			return;
		}
		$noted[] = $execution_id;
		$order->update_meta_data( '_payrails_extra_authorizations', wp_json_encode( array_values( $noted ) ) );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: second execution id, 2: paid execution id */
				__( 'Payrails: a second authorization %1$s exists for this order, which is already paid by %2$s. Void or cancel %1$s in the Payrails dashboard.', 'payrails-woo' ),
				$execution_id,
				$txn
			)
		);
		Logger::log( 'warning', 'second authorization on a paid order', array( 'order' => $order->get_id() ) );
	}

	/**
	 * Verifies a mapped execution against the order and applies the transition:
	 * verified success → payment_complete; unverified success → on-hold; failure →
	 * failed (the pay page can retry); anything else → unchanged (keep polling).
	 * Only settle() calls this, with the lock held and a freshly read order.
	 *
	 * @param \WC_Order       $order        Order.
	 * @param string          $execution_id Execution id.
	 * @param ExecutionResult $res          Mapped execution.
	 * @return array{state:string, redirect?:string, message?:string, code?:string}
	 */
	private static function apply( \WC_Order $order, string $execution_id, ExecutionResult $res ): array {
		$snap     = SnapshotFactory::from( $order );
		$mismatch = ExecutionVerifier::verify( $res, $execution_id, self::known_ids( $order ), $snap );
		$decision = ConfirmPolicy::decide( $res, $mismatch );

		switch ( $decision ) {
			case ConfirmPolicy::COMPLETE:
				if ( ! $order->is_paid() && '' === (string) $order->get_transaction_id() ) {
					$order->save();
					$order->payment_complete( $execution_id );
					$order->add_order_note(
						sprintf(
							/* translators: 1: execution id, 2: status code, 3: capture note */
							__( 'Payrails authorized %1$s (%2$s)%3$s.', 'payrails-woo' ),
							$execution_id,
							(string) $res->last_code,
							$res->captured ? ', captured' : ''
						)
					);
				}
				return array(
					'state'    => 'authorized',
					'redirect' => $order->get_checkout_order_received_url(),
				);

			case ConfirmPolicy::REVIEW:
				if ( $res->is_authorized() && ! $order->has_status( 'on-hold' ) && ! $order->is_paid() ) {
					$order->update_status(
						'on-hold',
						sprintf(
							'PR-VERIFY (%s): expected {ref %s, %s %s}, got {ref %s, %s %s} for execution %s.',
							(string) $mismatch,
							$snap->order_number,
							Money::from_minor( $snap->amount_minor, $snap->exponent() ),
							$snap->currency,
							(string) $res->merchant_reference,
							(string) $res->amount_value,
							(string) $res->amount_currency,
							$execution_id
						)
					);
				}
				return array(
					'state' => 'review',
					'code'  => 'PR-VERIFY',
				);

			case ConfirmPolicy::FAIL:
				// One note per declined execution: the first moves the order to failed; a
				// later decline on an already-failed order (a retry) is noted as well.
				$note = sprintf( 'Payrails: %s%s (execution %s).', (string) $res->last_code, $res->failure_reason ? ', ' . $res->failure_reason : '', $execution_id );
				$seen = (array) json_decode( (string) $order->get_meta( '_payrails_failed_executions' ), true );
				if ( ! in_array( $execution_id, $seen, true ) && $order->has_status( array( 'pending', 'failed' ) ) ) {
					$seen[] = $execution_id;
					$order->update_meta_data( '_payrails_failed_executions', wp_json_encode( array_values( $seen ) ) );
					if ( $order->has_status( 'pending' ) ) {
						$order->update_status( 'failed', $note );
					} else {
						$order->save();
						$order->add_order_note( $note );
					}
				}
				delete_transient( self::cache_key( $order ) );
				return array(
					'state'   => 'failed',
					'code'    => (string) $res->last_code,
					'message' => __( "Your card wasn't charged. Choose Try again to check the details or use a different card.", 'payrails-woo' ),
				);

			default:
				$out = array( 'state' => 'pending' );
				if ( null !== $res->action_url && 'authorizePending' === $res->last_code ) {
					// Payrails integration — step 7 (server side): a 3DS step is waiting.
					// The SDK may emit `pending` before the challenge is shown; returning the
					// execution's 3DS link lets the page offer the bank verification as a
					// full-page redirect as well.
					$out['action'] = array(
						'type' => 'redirect',
						'url'  => $res->action_url,
					);
				}
				return $out;
		}
	}

	/**
	 * Adds ONE order note per distinct error code and logs it.
	 *
	 * @param \WC_Order         $order Order.
	 * @param PayrailsException $e     Exception.
	 * @param string            $stage Stage label.
	 */
	public static function record_error( \WC_Order $order, PayrailsException $e, string $stage ): void {
		$code = $e->public_code();
		// Structured, value-free context only (no exception messages): the public code,
		// plus Payrails' own error code for API errors and key names for config errors.
		$context = array(
			'order' => $order->get_id(),
			'code'  => $code,
		);
		if ( $e instanceof ApiException && null !== $e->payrails_code ) {
			$context['payrails_code'] = $e->payrails_code;
		}
		if ( $e instanceof ConfigException ) {
			$context['problems'] = $e->problems;
		}
		Logger::log( 'error', $stage . ' failed', $context );
		if ( (string) $order->get_meta( self::META_LAST_ERROR ) !== $code ) {
			$order->update_meta_data( self::META_LAST_ERROR, $code );
			$order->save();
			/* translators: 1: stage, 2: error code */
			$order->add_order_note( sprintf( __( 'Payrails: %1$s failed (%2$s).', 'payrails-woo' ), $stage, $code ) );
		}
	}

	/**
	 * Drops the cached client-init, so the next pay-page render creates a fresh
	 * session (used when the SDK reports sessionExpired).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function drop_cache( \WC_Order $order ): void {
		delete_transient( self::cache_key( $order ) );
	}

	/**
	 * Client-init cache transient key.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function cache_key( \WC_Order $order ): string {
		return 'payrails_woo_ci_' . $order->get_id();
	}

	/**
	 * Clears the per-request read memo.
	 */
	public static function forget_reads(): void {
		self::$reads = array();
	}
}
