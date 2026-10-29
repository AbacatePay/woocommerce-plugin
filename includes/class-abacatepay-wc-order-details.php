<?php
/**
 * Pix instructions on the store, in emails and in the admin.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the QR code to the customer and the charge details to the merchant.
 *
 * Hooks are registered here instead of in the gateway because WooCommerce does not always load the
 * gateways (e.g. when an email is sent from the admin).
 */
final class AbacatePay_WC_Order_Details {

	const STATUS_ENDPOINT  = 'abacatepay_pix_status';
	const QR_CODE_ENDPOINT = 'abacatepay_pix_qr_code';

	/**
	 * Minimum interval between two status checks against the API for the same order, in seconds.
	 */
	const STATUS_CHECK_INTERVAL = 10;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
		add_action( 'woocommerce_thankyou_' . AbacatePay_WC_Pix_Gateway::ID, array( self::class, 'thankyou_page' ) );
		add_action( 'woocommerce_view_order', array( self::class, 'view_order_page' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'email_instructions' ), 10, 3 );

		add_action( 'wc_ajax_' . self::STATUS_ENDPOINT, array( self::class, 'ajax_status' ) );
		add_action( 'wc_ajax_' . self::QR_CODE_ENDPOINT, array( self::class, 'ajax_qr_code' ) );

		add_action( 'woocommerce_admin_order_data_after_billing_address', array( self::class, 'admin_charge_details' ) );
		add_filter( 'woocommerce_order_actions', array( self::class, 'admin_order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_abacatepay_check_pix', array( self::class, 'admin_check_pix' ) );
		add_action( 'woocommerce_order_action_abacatepay_simulate_pix', array( self::class, 'admin_simulate_pix' ) );
	}

	/**
	 * Registers the front-end assets. The stylesheet is enqueued only on the order pages.
	 */
	public static function register_assets() {
		wp_register_style( 'abacatepay-wc-pix', ABACATEPAY_WC_URL . 'assets/css/pix.css', array(), ABACATEPAY_WC_VERSION );
		wp_register_script( 'abacatepay-wc-pix', ABACATEPAY_WC_URL . 'assets/js/pix.js', array(), ABACATEPAY_WC_VERSION, true );

		if ( is_order_received_page() || is_view_order_page() ) {
			wp_enqueue_style( 'abacatepay-wc-pix' );
		}
	}

	/**
	 * "Order received" page.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			self::render( $order );
		}
	}

	/**
	 * "My account > Orders > View" page. Shown only while the Pix can still be paid.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function view_order_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order && ! AbacatePay_WC_Pix_Gateway::is_order_paid( $order ) && ! self::is_expired( $order ) ) {
			self::render( $order );
		}
	}

	/**
	 * Renders the QR code, the copy and paste code and the payment status.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render( WC_Order $order ) {
		if ( AbacatePay_WC_Pix_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}

		$br_code = (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_BR_CODE );
		$paid    = AbacatePay_WC_Pix_Gateway::is_order_paid( $order );
		$expired = ! $paid && self::is_expired( $order );

		if ( ! $paid && '' === $br_code ) {
			return;
		}

		if ( ! $paid && ! $expired ) {
			wp_enqueue_style( 'abacatepay-wc-pix' );
			wp_enqueue_script( 'abacatepay-wc-pix' );
		}

		wc_get_template(
			'pix-instructions.php',
			array(
				'order'       => $order,
				'paid'        => $paid,
				'expired'     => $expired,
				'br_code'     => $br_code,
				'qr_code_url' => self::endpoint_url( self::QR_CODE_ENDPOINT, $order ),
				'status_url'  => self::endpoint_url( self::STATUS_ENDPOINT, $order ),
				'expires_at'  => (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_EXPIRES_AT ),
				'dev_mode'    => 'yes' === $order->get_meta( AbacatePay_WC_Pix_Gateway::META_DEV_MODE ),
				'receipt_url' => (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_RECEIPT_URL ),
			),
			'abacatepay-for-woocommerce/',
			ABACATEPAY_WC_PATH . 'templates/'
		);
	}

	/**
	 * Adds the QR code to customer emails while the order awaits payment.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the store admin.
	 * @param bool     $plain_text    Whether it is a plain text email.
	 */
	public static function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if (
			$sent_to_admin
			|| ! $order instanceof WC_Order
			|| AbacatePay_WC_Pix_Gateway::ID !== $order->get_payment_method()
			|| ! $order->has_status( array( 'on-hold', 'pending' ) )
			|| AbacatePay_WC_Pix_Gateway::is_order_paid( $order )
		) {
			return;
		}

		$br_code = (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_BR_CODE );

		if ( '' === $br_code || self::is_expired( $order ) ) {
			return;
		}

		wc_get_template(
			$plain_text ? 'emails/plain/pix-instructions.php' : 'emails/pix-instructions.php',
			array(
				'order'       => $order,
				'br_code'     => $br_code,
				'qr_code_url' => self::endpoint_url( self::QR_CODE_ENDPOINT, $order ),
				'expires_at'  => (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_EXPIRES_AT ),
				'order_url'   => $order->get_view_order_url(),
			),
			'abacatepay-for-woocommerce/',
			ABACATEPAY_WC_PATH . 'templates/'
		);
	}

	/**
	 * Polled by the order page. Returns whether the order is paid, checking AbacatePay directly
	 * (throttled) so it works even when the webhook is not configured.
	 */
	public static function ajax_status() {
		$order = self::get_order_from_request();

		if ( ! $order ) {
			wp_send_json_error( null, 403 );
		}

		nocache_headers();

		$paid         = AbacatePay_WC_Pix_Gateway::is_order_paid( $order );
		$throttle_key = 'abacatepay_pix_checked_' . $order->get_id();

		if ( ! $paid && ! self::is_expired( $order ) && false === get_transient( $throttle_key ) ) {
			set_transient( $throttle_key, 1, self::STATUS_CHECK_INTERVAL );

			$gateway = AbacatePay_WC::pix_gateway();

			if ( $gateway ) {
				$gateway->sync_charge_status( $order );
				$order = wc_get_order( $order->get_id() );
				$paid  = AbacatePay_WC_Pix_Gateway::is_order_paid( $order );
			}
		}

		wp_send_json_success(
			array(
				'paid'    => $paid,
				'expired' => ! $paid && self::is_expired( $order ),
			)
		);
	}

	/**
	 * Serves the QR code PNG. Used in emails, where most clients block data: URIs.
	 */
	public static function ajax_qr_code() {
		$order   = self::get_order_from_request();
		$qr_code = $order ? (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_QR_CODE ) : '';
		$png     = '' !== $qr_code ? base64_decode( substr( $qr_code, strpos( $qr_code, ',' ) + 1 ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( ! $png ) {
			status_header( 404 );
			exit;
		}

		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . strlen( $png ) );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image.
		exit;
	}

	/**
	 * Charge details below the billing address on the order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_charge_details( $order ) {
		$charge_id = (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_CHARGE_ID );

		if ( AbacatePay_WC_Pix_Gateway::ID !== $order->get_payment_method() || '' === $charge_id ) {
			return;
		}

		$receipt_url = (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_RECEIPT_URL );
		$expires_at  = (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_EXPIRES_AT );
		?>
		<div class="abacatepay-charge-details">
			<h3><?php esc_html_e( 'AbacatePay Pix', 'abacatepay-for-woocommerce' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'Charge ID:', 'abacatepay-for-woocommerce' ); ?></strong>
				<code><?php echo esc_html( $charge_id ); ?></code>
				<?php if ( 'yes' === $order->get_meta( AbacatePay_WC_Pix_Gateway::META_DEV_MODE ) ) : ?>
					<mark class="order-status status-on-hold"><span><?php esc_html_e( 'Dev mode', 'abacatepay-for-woocommerce' ); ?></span></mark>
				<?php endif; ?>
				<?php if ( '' !== $expires_at && ! AbacatePay_WC_Pix_Gateway::is_order_paid( $order ) ) : ?>
					<br /><strong><?php esc_html_e( 'Expires:', 'abacatepay-for-woocommerce' ); ?></strong>
					<?php echo esc_html( self::format_datetime( $expires_at ) ); ?>
				<?php endif; ?>
				<?php if ( '' !== $receipt_url ) : ?>
					<br /><a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View receipt', 'abacatepay-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Adds "Check Pix status" and, for Dev mode charges, "Simulate Pix payment" to the order actions.
	 *
	 * @param array         $actions Order actions.
	 * @param WC_Order|null $order   Order (WooCommerce 8.1+).
	 * @return array
	 */
	public static function admin_order_actions( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			global $theorder;
			$order = $theorder;
		}

		if (
			! $order instanceof WC_Order
			|| AbacatePay_WC_Pix_Gateway::ID !== $order->get_payment_method()
			|| '' === (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_CHARGE_ID )
			|| AbacatePay_WC_Pix_Gateway::is_order_paid( $order )
		) {
			return $actions;
		}

		$actions['abacatepay_check_pix'] = __( 'Check Pix payment status on AbacatePay', 'abacatepay-for-woocommerce' );

		if ( 'yes' === $order->get_meta( AbacatePay_WC_Pix_Gateway::META_DEV_MODE ) ) {
			$actions['abacatepay_simulate_pix'] = __( 'Simulate Pix payment (Dev mode)', 'abacatepay-for-woocommerce' );
		}

		return $actions;
	}

	/**
	 * Order action: sync the status with AbacatePay.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_check_pix( $order ) {
		$gateway = AbacatePay_WC::pix_gateway();
		$status  = $gateway ? $gateway->sync_charge_status( $order ) : null;

		if ( is_wp_error( $status ) ) {
			/* translators: %s: error message */
			WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Could not check the Pix status: %s', 'abacatepay-for-woocommerce' ), $status->get_error_message() ) );
		} elseif ( $status ) {
			/* translators: %s: AbacatePay charge status, e.g. PENDING or PAID */
			$order->add_order_note( sprintf( __( 'Pix status checked on AbacatePay: %s', 'abacatepay-for-woocommerce' ), $status ) );
		}
	}

	/**
	 * Order action: pay a Dev mode charge.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_simulate_pix( $order ) {
		$gateway   = AbacatePay_WC::pix_gateway();
		$charge_id = (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_CHARGE_ID );

		if ( ! $gateway || 'yes' !== $order->get_meta( AbacatePay_WC_Pix_Gateway::META_DEV_MODE ) ) {
			return;
		}

		$result = $gateway->get_api()->simulate_payment( $charge_id );

		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message */
			WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Could not simulate the payment: %s', 'abacatepay-for-woocommerce' ), $result->get_error_message() ) );

			return;
		}

		$order->add_order_note( __( 'Pix payment simulated in AbacatePay Dev mode.', 'abacatepay-for-woocommerce' ) );

		// Don't wait for the webhook.
		$gateway->sync_charge_status( $order );
	}

	/**
	 * Whether the Pix expiration date has passed (or the order was cancelled).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_expired( WC_Order $order ) {
		$expires_at = strtotime( (string) $order->get_meta( AbacatePay_WC_Pix_Gateway::META_EXPIRES_AT ) );

		return $order->has_status( 'cancelled' ) || ( $expires_at && $expires_at < time() );
	}

	/**
	 * Formats an ISO 8601 date in the store timezone and format.
	 *
	 * @param string $iso_date Date.
	 * @return string
	 */
	public static function format_datetime( $iso_date ) {
		$date = wc_string_to_datetime( $iso_date );

		return wc_format_datetime( $date, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Public endpoint URL for an order, authenticated by the order key.
	 *
	 * @param string   $endpoint WC AJAX endpoint.
	 * @param WC_Order $order    Order.
	 * @return string
	 */
	private static function endpoint_url( $endpoint, WC_Order $order ) {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC_AJAX::get_endpoint( $endpoint )
		);
	}

	/**
	 * Order from the order_id/key query args, if the key matches.
	 *
	 * @return WC_Order|null
	 */
	private static function get_order_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Authenticated by the order key.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		return AbacatePay_WC_Pix_Gateway::ID === $order->get_payment_method() ? $order : null;
	}
}
