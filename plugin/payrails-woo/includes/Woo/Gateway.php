<?php
/**
 * The "payrails" WooCommerce payment gateway.
 *
 * @package PayrailsWoo
 */

namespace PayrailsWoo\Woo;

use PayrailsWoo\Core\Config;
use PayrailsWoo\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway. Place order → pending → pay-for-order page, where the Drop-in mounts.
 */
class Gateway extends \WC_Payment_Gateway {

	/**
	 * Currencies this example accepts.
	 */
	public const CURRENCIES = array( 'USD' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'payrails';
		$this->method_title       = __( 'Payrails', 'payrails-woo' );
		$this->method_description = __( 'Card payments (including 3D Secure) with the Payrails Web SDK v6 Drop-in on the pay-for-order page.', 'payrails-woo' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->order_button_text  = __( 'Continue to payment', 'payrails-woo' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title', __( 'Card', 'payrails-woo' ) );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings. No secret fields: secrets come from the environment or the secrets file.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Enable', 'payrails-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Payrails card payments', 'payrails-woo' ),
				'default' => 'no',
			),
			'title'         => array(
				'title'   => __( 'Title', 'payrails-woo' ),
				'type'    => 'text',
				'default' => __( 'Card', 'payrails-woo' ),
			),
			'description'   => array(
				'title'   => __( 'Description', 'payrails-woo' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely by card on the next step. 3D Secure supported. Powered by Payrails.', 'payrails-woo' ),
			),
			'workflow_code' => array(
				'title'       => __( 'Workflow code override', 'payrails-woo' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Leave empty to use PAYRAILS_WORKFLOW_CODE from the secrets file (default payment-acceptance).', 'payrails-woo' ),
			),
			'debug'         => array(
				'title'   => __( 'Debug log', 'payrails-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log request outcomes and response shapes (never values) to WooCommerce → Status → Logs (source payrails-woo)', 'payrails-woo' ),
				'default' => 'no',
			),
			'status'        => array(
				'title' => __( 'Connection status', 'payrails-woo' ),
				'type'  => 'payrails_status',
			),
		);
	}

	/**
	 * Read-only status panel: which secrets are present (never their values).
	 *
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $data Field data.
	 */
	public function generate_payrails_status_html( $key, $data ) {
		$file   = Services::secrets_file();
		$report = Config::inspect( $file );
		$yes    = '<span style="color:#1E7A34">&#10003; ' . esc_html__( 'present', 'payrails-woo' ) . '</span>';
		$no     = '<span style="color:#C52020">&#10007; ' . esc_html__( 'missing', 'payrails-woo' ) . '</span>';

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<p><strong><?php esc_html_e( 'Status:', 'payrails-woo' ); ?></strong> <?php echo Services::is_configured() ? esc_html__( 'Configured', 'payrails-woo' ) : esc_html__( 'Incomplete (see below); the method is hidden at checkout', 'payrails-woo' ); ?></p>
				<p><strong><?php esc_html_e( 'API host:', 'payrails-woo' ); ?></strong> <code><?php echo esc_html( $report['api_host'] ); ?></code>
					&nbsp; <strong><?php esc_html_e( 'Workflow:', 'payrails-woo' ); ?></strong> <code><?php echo esc_html( Services::workflow_code() ); ?></code></p>
				<p><strong><?php esc_html_e( 'Secrets source:', 'payrails-woo' ); ?></strong> <?php echo esc_html( $report['source'] ); ?>
					<?php if ( $file ) : ?>
						&nbsp; <code><?php echo esc_html( $file ); ?></code> <?php echo $report['file_readable'] ? $yes : $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup built above. ?>
					<?php endif; ?>
				</p>
				<table class="widefat striped" style="max-width:32rem">
					<tbody>
					<?php foreach ( $report['keys'] as $name => $present ) : ?>
						<tr><td><code><?php echo esc_html( $name ); ?></code></td><td><?php echo $present ? $yes : $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></td></tr>
					<?php endforeach; ?>
						<tr><td><?php esc_html_e( 'Client certificate readable', 'payrails-woo' ); ?></td><td><?php echo $report['cert_readable'] ? $yes : $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
						<tr><td><?php esc_html_e( 'Client key readable', 'payrails-woo' ); ?></td><td><?php echo $report['key_readable'] ? $yes : $no; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					</tbody>
				</table>
				<?php if ( $report['error'] ) : ?>
					<p style="color:#C52020"><?php echo esc_html( $report['error'] ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Secrets are never stored in the database and cannot be edited here. Set PAYRAILS_* environment variables or the file named by PAYRAILS_SECRETS_FILE.', 'payrails-woo' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The status pseudo-field stores nothing.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_payrails_status_field( $key, $value ) {
		return '';
	}

	/**
	 * Hidden unless configured, and only for supported currencies.
	 */
	public function is_available() {
		if ( ! parent::is_available() || ! Services::is_configured() ) {
			return false;
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		return in_array( $currency, self::CURRENCIES, true ) && isset( Money::EXPONENTS[ $currency ] );
	}

	/**
	 * Payrails integration — step 2: Place order → the order stays "pending" and the
	 * shopper is sent to WooCommerce's pay-for-order page, where the Drop-in mounts.
	 * get_checkout_payment_url( true ) gives the receipt variant of that page (no
	 * pay_for_order parameter), which fires woocommerce_receipt_payrails.
	 *
	 * @param int $order_id Order id.
	 * @return array{result:string, redirect:string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wc_add_notice( __( 'Order not found.', 'payrails-woo' ), 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => '',
			);
		}
		if ( $order->has_status( 'pending' ) ) {
			$order->add_order_note( __( 'Awaiting Payrails payment.', 'payrails-woo' ) );
		} else {
			$order->update_status( 'pending', __( 'Awaiting Payrails payment.', 'payrails-woo' ) );
		}
		$order->save();
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}
}
