<?php
/**
 * WC-AJAX ?wc-ajax=payrails_confirm: the only way an order becomes paid from the browser.
 *
 * The browser only names ids. The server reads the execution from Payrails and
 * decides; it never trusts an outcome sent by the browser.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use PayrailsWoo\Core\ExecutionVerifier;
use PayrailsWoo\Core\Exception\ApiException;
use PayrailsWoo\Core\Exception\PayrailsException;

defined( 'ABSPATH' ) || exit;

/**
 * Controller.
 */
final class ConfirmController {

	/**
	 * Payrails integration — step 6: server-side confirm. The browser POSTs only ids
	 * (order, key, execution); the server reads the execution from Payrails, verifies
	 * it against the order and completes it (ExecutionSession::settle()).
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below with check_ajax_referer before any use.
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			self::send( 405, self::err( 'method_not_allowed' ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$key      = isset( $_POST['order_key'] ) ? wc_clean( wp_unslash( $_POST['order_key'] ) ) : '';
		$exec_raw = isset( $_POST['execution_id'] ) ? sanitize_text_field( wp_unslash( $_POST['execution_id'] ) ) : '';
		// phpcs:enable
		$exec = preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $exec_raw ) ? $exec_raw : null;

		if ( ! check_ajax_referer( 'payrails_confirm_' . $order_id, 'nonce', false ) ) {
			self::send( 403, self::err( 'stale_session', __( 'Please reload the page.', 'payrails-woo' ) ) );
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! is_string( $key ) || ! hash_equals( $order->get_order_key(), $key ) ) {
			self::send( 404, self::err( 'not_found' ) );
		}
		if ( 'payrails' !== $order->get_payment_method() ) {
			self::send( 400, self::err( 'wrong_gateway' ) );
		}
		$exec = $exec ?? ExecutionSession::current_id( $order );
		if ( null === $exec || ! ExecutionVerifier::is_known( $exec, ExecutionSession::known_ids( $order ) ) ) {
			self::send( 400, self::err( 'unknown_execution' ) );
		}
		$paid_by = $order->is_paid() ? (string) $order->get_transaction_id() : null;
		if ( null !== $paid_by && $paid_by === $exec ) {
			self::send(
				200,
				array(
					'state'    => 'authorized',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		// Read Payrails OUTSIDE the lock; settle() then re-reads the order under it.
		$status = 200;
		try {
			$result = ExecutionSession::read( $order, $exec, true );
			$body   = ExecutionSession::settle( $order_id, $exec, $result );
			unset( $body['locked'] );
			if ( 'review' === $body['state'] ) {
				$body['message'] = __( "We couldn't verify this payment. Our team will review it. Do not pay again.", 'payrails-woo' );
			}
		} catch ( PayrailsException $e ) {
			if ( null !== $paid_by ) {
				// Paid already; the extra execution could not be read. Nothing to change.
				$body = array(
					'state'    => 'authorized',
					'redirect' => $order->get_checkout_order_received_url(),
				);
			} else {
				Logger::log(
					'error',
					'confirm read failed',
					array(
						'order' => $order_id,
						'code'  => $e->public_code(),
					)
				);
				$status = $e->confirm_status();
				$body   = array(
					'state' => 'unknown',
					'code'  => $e->public_code(),
				);
				if ( $e instanceof ApiException && 404 === $e->status ) {
					$body['state'] = 'error';
				}
			}
		}
		self::send( $status, $body );
	}

	/**
	 * Error body.
	 *
	 * @param string      $code    Code.
	 * @param string|null $message Message.
	 * @return array<string, string>
	 */
	private static function err( string $code, ?string $message = null ): array {
		$b = array(
			'state' => 'error',
			'code'  => $code,
		);
		if ( null !== $message ) {
			$b['message'] = $message;
		}
		return $b;
	}

	/**
	 * Sends JSON and exits.
	 *
	 * @param int                  $status Status.
	 * @param array<string, mixed> $body   Body.
	 */
	private static function send( int $status, array $body ): void {
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		wp_send_json( $body, $status );
	}
}
