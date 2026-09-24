<?php
/**
 * Pay-for-order page: precheck on template_redirect, and the receipt markup
 * (pay panel + order summary) printed from woocommerce_receipt_payrails.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use PayrailsWoo\Core\ExecutionResult;
use PayrailsWoo\Core\Exception\PayrailsException;

defined( 'ABSPATH' ) || exit;

/**
 * Pay page.
 */
final class PayPage {

	public const SDK_VERSION = '6.0.3';

	/**
	 * Resolves reloads after authorize and redirect-mode 3DS returns before any output.
	 */
	public static function precheck(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key in the URL is the authority here.
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() || isset( $_GET['pay_for_order'] ) ) {
			return;
		}
		global $wp;
		$order_id = absint( $wp->query_vars['order-pay'] ?? 0 );
		$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! is_string( $key ) || ! hash_equals( $order->get_order_key(), $key ) || 'payrails' !== $order->get_payment_method() ) {
			return;
		}
		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		$exec = ExecutionSession::current_id( $order );
		if ( null === $exec || ! $order->needs_payment() ) {
			return;
		}
		try {
			$res = ExecutionSession::read( $order, $exec );
			if ( ! $res->is_authorized() && ExecutionResult::FAILED !== $res->state ) {
				return;
			}
			$out = ExecutionSession::settle( $order_id, $exec, $res );
			if ( 'authorized' === $out['state'] ) {
				wp_safe_redirect( $out['redirect'] );
				exit;
			}
		} catch ( PayrailsException $e ) {
			Logger::log( 'warning', 'precheck read failed; rendering normally', array( 'code' => $e->public_code() ) );
		}
	}

	/**
	 * Receipt hook: prints the pay panel and enqueues the controller script.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render_receipt( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'payrails' !== $order->get_payment_method() ) {
			return;
		}
		// pay.js reloads with ?payrails_reinit=1 when the SDK reports sessionExpired: drop
		// the cached client-init so this render starts a fresh session. The order key in the
		// URL (checked by WooCommerce before this hook runs) is the authority, as for any render.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag, no state change beyond a cache drop.
		if ( isset( $_GET['payrails_reinit'] ) ) {
			ExecutionSession::drop_cache( $order );
		}
		$view = ExecutionSession::prepare( $order );
		self::enqueue( $order, $view );
		self::markup( $order, $view );
	}

	/**
	 * Registers + enqueues assets and prints the config. Done here (not on
	 * wp_enqueue_scripts) because block themes render the template before wp_head.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $view  View from ExecutionSession::prepare().
	 */
	private static function enqueue( \WC_Order $order, array $view ): void {
		$ver = PAYRAILS_WOO_VERSION;
		wp_register_style( 'payrails-woo', plugins_url( 'assets/css/payrails-woo.css', PAYRAILS_WOO_FILE ), array(), $ver );
		wp_register_script(
			'woo-payrails-pay',
			plugins_url( 'assets/js/pay.js', PAYRAILS_WOO_FILE ),
			array(),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_style( 'payrails-woo' );
		wp_enqueue_script( 'woo-payrails-pay' );

		$sdk_url = (string) apply_filters( 'payrails_woo_sdk_url', plugins_url( 'assets/vendor/payrails-web-sdk/' . self::SDK_VERSION . '/index.mjs', PAYRAILS_WOO_FILE ) );
		$config  = array(
			'confirmUrl'  => \WC_AJAX::get_endpoint( 'payrails_confirm' ),
			'nonce'       => wp_create_nonce( 'payrails_confirm_' . $order->get_id() ),
			'orderId'     => $order->get_id(),
			'orderKey'    => $order->get_order_key(),
			'sdkUrl'      => $sdk_url,
			'mode'        => (string) $view['mode'],
			'executionId' => (string) ( $view['execution_id'] ?? '' ),
			'errorCode'   => (string) ( $view['code'] ?? '' ),
			'redirect'    => (string) ( $view['redirect'] ?? '' ),
			'returnUrl'   => $order->get_checkout_payment_url( true ),
			'cardsOnly'   => self::cards_only(),
			// Guests never store or see stored cards: their identity is not verified.
			'guest'       => SnapshotFactory::is_guest( $order ),
			'appearance'  => self::appearance(),
			'poll'        => array(
				'initialMs'   => 1500,
				'factor'      => 1.5,
				'maxMs'       => 6000,
				'ceilingMs'   => 90000,
				'slowAfterMs' => 15000,
			),
			'i18n'        => self::strings(),
		);
		wp_add_inline_script( 'woo-payrails-pay', 'window.PayrailsWooPay = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	}

	/**
	 * Cards-only presentation (filter payrails_woo_cards_only, default true).
	 *
	 * A workflow may also return wallet methods (Google Pay, Apple Pay). This example
	 * offers cards only, so when this option is on the wallet rows are hidden and the
	 * card method is opened automatically. That relies on the Drop-in's markup (element
	 * ids), so it is kept to this one switch: when the Payrails workflow itself only
	 * enables cards, return false from the filter (or delete the option) and nothing
	 * depends on Drop-in internals.
	 */
	private static function cards_only(): bool {
		return (bool) apply_filters( 'payrails_woo_cards_only', true );
	}

	/**
	 * Shows the "test cards only" notice when the configured API host is a staging host.
	 */
	private static function is_staging(): bool {
		try {
			$host = (string) wp_parse_url( Services::config()->api_url, PHP_URL_HOST );
		} catch ( \PayrailsWoo\Core\Exception\PayrailsException $e ) {
			return false;
		}
		return 1 === preg_match( '/(^|\.)staging\./i', $host );
	}

	/**
	 * Drop-in appearance from the active theme (assets/payrails-appearance.json), if any.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function appearance(): ?array {
		$file = get_theme_file_path( 'assets/payrails-appearance.json' );
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
		return is_array( $data ) ? $data : null;
	}

	/**
	 * UI copy for the pay-panel states.
	 *
	 * @return array<string, array{0:string, 1:string}|string>
	 */
	private static function strings(): array {
		return array(
			'loading'     => __( 'Loading secure payment form', 'payrails-woo' ),
			'processing'  => array( __( 'Confirming your payment', 'payrails-woo' ), __( "This only takes a moment. Please don't close or refresh this page.", 'payrails-woo' ) ),
			// Most pending phases are just the authorization settling (no 3DS), so the
			// default copy does not claim the bank is asking for anything.
			'pending'     => array( __( 'Confirming your payment', 'payrails-woo' ), __( "This usually takes a few seconds. If your bank asks you to verify, follow its steps. We'll update this page automatically.", 'payrails-woo' ) ),
			'pendingBank' => array( __( 'Waiting for your bank', 'payrails-woo' ), __( "Your bank wants to verify this payment. Continue to the verification; we'll update this page automatically.", 'payrails-woo' ) ),
			'slow'        => __( "Still checking. Please don't pay again; this page keeps checking with Payrails.", 'payrails-woo' ),
			'challenge'   => array( __( 'Verify with your bank', 'payrails-woo' ), __( 'Complete the 3D Secure check your bank shows you.', 'payrails-woo' ) ),
			'success'     => array( __( 'Payment confirmed', 'payrails-woo' ), __( 'Thank you. Taking you to your order…', 'payrails-woo' ) ),
			'failed'      => array( __( 'Payment declined', 'payrails-woo' ), __( "Your card wasn't charged. Choose Try again to check the details or use a different card.", 'payrails-woo' ) ),
			'error'       => array( __( 'Payment is unavailable right now', 'payrails-woo' ), __( "We couldn't connect to our payment provider. Your order is saved and you haven't been charged.", 'payrails-woo' ) ),
			'review'      => array( __( "We couldn't verify this payment", 'payrails-woo' ), __( 'Our team will review it. Do not pay again.', 'payrails-woo' ) ),
			'stale'       => array( __( 'This page has expired', 'payrails-woo' ), __( 'Please reload the page to continue. You have not been charged twice.', 'payrails-woo' ) ),
			'unresolved'  => array( __( "We couldn't confirm this payment yet", 'payrails-woo' ), __( 'Payrails has not reported an outcome. Do not pay again. You can check again, or come back to this page later.', 'payrails-woo' ) ),
			'checkAgain'  => __( 'Check again', 'payrails-woo' ),
			'verify'      => __( 'Continue to bank verification', 'payrails-woo' ),
			'expired'     => array( __( 'This payment session has expired', 'payrails-woo' ), __( 'Please reload the page to start a new one. You have not been charged.', 'payrails-woo' ) ),
			// Passed to the Drop-in's own translations.
			'dropinFail'  => __( 'Payment declined. Please try another card.', 'payrails-woo' ),
		);
	}

	/**
	 * Formats money as plain text (wc_price returns HTML).
	 *
	 * @param float|string $amount   Amount.
	 * @param string       $currency Currency.
	 */
	private static function money( $amount, string $currency ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Prints the pay panel (status, Drop-in mount point, actions) and the order summary.
	 *
	 * @param \WC_Order            $order Order.
	 * @param array<string, mixed> $view  View.
	 */
	private static function markup( \WC_Order $order, array $view ): void {
		$cur      = $order->get_currency();
		$mode     = (string) $view['mode'];
		$state    = in_array( $mode, array( 'error', 'paid' ), true ) ? ( 'paid' === $mode ? 'success' : 'error' ) : ( 'pending' === $mode ? 'pending' : 'loading' );
		$strings  = self::strings();
		$shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		$tax      = (float) $order->get_total_tax() - (float) $order->get_shipping_tax();
		$discount = (float) $order->get_total_discount();
		$address  = $order->get_formatted_shipping_address();
		if ( ! $address ) {
			$address = $order->get_formatted_billing_address();
		}
		$lock_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
		?>
		<section class="woo-payrails-pay<?php echo self::cards_only() ? ' woo-payrails-cards-only' : ''; ?>" id="woo-payrails-pay" data-state="<?php echo esc_attr( $state ); ?>" data-mode="<?php echo esc_attr( $mode ); ?>" aria-labelledby="woo-payrails-pay-title">
			<header class="woo-payrails-pay__head">
				<p class="woo-payrails-pay__eyebrow"><?php esc_html_e( 'Secure payment', 'payrails-woo' ); ?></p>
				<h1 id="woo-payrails-pay-title" class="woo-payrails-pay__title"><?php esc_html_e( 'Complete your order', 'payrails-woo' ); ?></h1>
				<p class="woo-payrails-pay__lede">
				<?php
				/* translators: 1: order number, 2: formatted total */
				echo esc_html( sprintf( __( 'Order #%1$s · %2$s', 'payrails-woo' ), $order->get_order_number(), self::money( $order->get_total(), $cur ) ) );
				?>
				</p>
			</header>
			<div class="woo-payrails-pay__grid">
				<div class="woo-payrails-pay__main">
					<div class="woo-payrails-pay__panel">
						<h2 class="woo-payrails-pay__panel-title"><?php esc_html_e( 'Payment', 'payrails-woo' ); ?></h2>
						<div class="woo-payrails-pay__status" id="woo-payrails-pay-status" role="<?php echo 'error' === $state ? 'alert' : 'status'; ?>" aria-live="polite" tabindex="-1"><?php if ( 'error' === $state ) : ?><div><strong><?php echo esc_html( $strings['error'][0] ); ?></strong><p><?php echo esc_html( $strings['error'][1] ); ?> <code><?php echo esc_html( (string) ( $view['code'] ?? '' ) ); ?></code></p></div><?php elseif ( 'loading' === $state ) : ?><span class="screen-reader-text"><?php echo esc_html( $strings['loading'] ); ?></span><?php endif; ?></div>
						<div class="woo-payrails-pay__skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
						<?php // Payrails integration — step 5 (container): pay.js mounts the Drop-in into #woo-payrails-dropin. ?>
						<div id="woo-payrails-dropin" class="woo-payrails-pay__dropin"></div>
						<div class="woo-payrails-pay__actions">
							<button type="button" class="is-primary" data-action="retry"><?php esc_html_e( 'Try again', 'payrails-woo' ); ?></button>
							<a class="is-secondary" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Return to cart', 'payrails-woo' ); ?></a>
						</div>
						<noscript><p><?php esc_html_e( 'JavaScript is required to load the secure payment form.', 'payrails-woo' ); ?></p></noscript>
					</div>
					<div class="woo-payrails-pay__trust">
						<span class="woo-payrails-pay__trust-icon" aria-hidden="true"><?php echo $lock_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literal. ?></span>
						<div>
							<p class="woo-payrails-pay__trust-title"><?php esc_html_e( 'Card payment by Payrails', 'payrails-woo' ); ?></p>
							<p class="woo-payrails-pay__trust-text"><?php esc_html_e( 'Card details are entered in fields hosted by Payrails. This store does not receive or store them.', 'payrails-woo' ); ?></p>
						</div>
					</div>
				</div>
				<aside class="woo-payrails-pay__summary" aria-labelledby="woo-payrails-pay-summary-title">
					<h2 id="woo-payrails-pay-summary-title" class="woo-payrails-pay__summary-title"><?php esc_html_e( 'Order Summary', 'payrails-woo' ); ?></h2>
					<ul class="woo-payrails-pay__items">
						<?php
						foreach ( $order->get_items( 'line_item' ) as $item ) :
							/** Line item. @var \WC_Order_Item_Product $item */
							$product = $item->get_product();
							$thumb   = $product ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' ) : '';
							?>
							<li class="woo-payrails-pay__item">
								<?php if ( $thumb ) : ?>
									<img class="woo-payrails-pay__thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" width="64" height="80" loading="lazy">
								<?php else : ?>
									<span class="woo-payrails-pay__thumb" aria-hidden="true"></span>
								<?php endif; ?>
								<div>
									<p class="woo-payrails-pay__item-name"><?php echo esc_html( $item->get_name() ); ?></p>
									<p class="woo-payrails-pay__item-meta">
									<?php
									/* translators: %d: quantity */
									echo esc_html( sprintf( __( 'Qty %d', 'payrails-woo' ), $item->get_quantity() ) );
									?>
									</p>
								</div>
								<p class="woo-payrails-pay__item-total"><?php echo esc_html( self::money( $order->get_line_subtotal( $item, false, false ), $cur ) ); ?></p>
							</li>
						<?php endforeach; ?>
					</ul>
					<dl class="woo-payrails-pay__totals">
						<div class="woo-payrails-pay__row"><dt><?php esc_html_e( 'Subtotal', 'payrails-woo' ); ?></dt><dd><?php echo esc_html( self::money( $order->get_subtotal(), $cur ) ); ?></dd></div>
						<?php if ( $discount > 0 ) : ?>
							<div class="woo-payrails-pay__row"><dt><?php esc_html_e( 'Discount', 'payrails-woo' ); ?></dt><dd>−<?php echo esc_html( self::money( $discount, $cur ) ); ?></dd></div>
						<?php endif; ?>
						<div class="woo-payrails-pay__row"><dt><?php esc_html_e( 'Shipping', 'payrails-woo' ); ?></dt><dd><?php echo esc_html( $shipping > 0 ? self::money( $shipping, $cur ) : ( '' !== $order->get_shipping_method() ? $order->get_shipping_method() : self::money( 0, $cur ) ) ); ?></dd></div>
						<?php if ( $tax > 0 ) : ?>
							<div class="woo-payrails-pay__row"><dt><?php esc_html_e( 'Tax', 'payrails-woo' ); ?></dt><dd><?php echo esc_html( self::money( $tax, $cur ) ); ?></dd></div>
						<?php endif; ?>
						<div class="woo-payrails-pay__row woo-payrails-pay__row--total"><dt><?php esc_html_e( 'Total', 'payrails-woo' ); ?></dt><dd><?php echo esc_html( self::money( $order->get_total(), $cur ) ); ?></dd></div>
					</dl>
					<?php if ( $address ) : ?>
						<address class="woo-payrails-pay__address"><strong><?php esc_html_e( 'Shipping to', 'payrails-woo' ); ?></strong><?php echo wp_kses( $address, array( 'br' => array() ) ); ?></address>
					<?php endif; ?>
				</aside>
			</div>
			<?php if ( self::is_staging() ) : ?>
				<p class="woo-payrails-pay__demo-note"><?php esc_html_e( 'Payrails staging: use test cards only.', 'payrails-woo' ); ?></p>
			<?php endif; ?>
			<?php if ( 'mount' === $mode && ! empty( $view['client_init'] ) ) : ?>
				<?php // Payrails integration — step 5 (data): the server's client-init response, read by pay.js for Payrails.init(). ?>
				<script type="application/json" id="woo-payrails-client-init"><?php echo wp_json_encode( $view['client_init'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES ); ?></script>
			<?php endif; ?>
		</section>
		<?php
	}
}
