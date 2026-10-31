<?php
/**
 * Boleto instructions on the store, in emails and in the admin.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the boleto (linha digitável, link to print it) and its alternative Pix to the customer.
 *
 * Hooks are registered here instead of in the gateway because WooCommerce does not always load the
 * gateways (e.g. when an email is sent from the admin).
 */
final class AbacatePay_WC_Boleto_Details {

	const QR_CODE_ENDPOINT = 'abacatepay_boleto_pix_qr_code';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
		add_action( 'woocommerce_thankyou_' . AbacatePay_WC_Boleto_Gateway::ID, array( self::class, 'thankyou_page' ) );
		add_action( 'woocommerce_view_order', array( self::class, 'view_order_page' ), 5 );
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'email_instructions' ), 10, 3 );

		add_action( 'wc_ajax_' . self::QR_CODE_ENDPOINT, array( self::class, 'ajax_qr_code' ) );

		add_action( AbacatePay_WC_Boleto_Gateway::CHECK_HOOK, array( self::class, 'run_scheduled_check' ) );

		add_filter( 'woocommerce_order_actions', array( self::class, 'admin_order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_abacatepay_check_boleto', array( self::class, 'admin_check_boleto' ) );
		add_action( 'woocommerce_order_action_abacatepay_simulate_boleto', array( self::class, 'admin_simulate_boleto' ) );
	}

	/**
	 * Registers the front-end assets. The stylesheet is enqueued only on the order pages.
	 */
	public static function register_assets() {
		wp_register_style( 'abacatepay-wc-boleto', ABACATEPAY_WC_URL . 'assets/css/boleto.css', array(), ABACATEPAY_WC_VERSION );
		wp_register_script( 'abacatepay-wc-boleto', ABACATEPAY_WC_URL . 'assets/js/boleto.js', array(), ABACATEPAY_WC_VERSION, true );

		if ( is_order_received_page() || is_view_order_page() ) {
			wp_enqueue_style( 'abacatepay-wc-boleto' );
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
	 * "My account > Orders > View" page. Shown only while the boleto can still be paid.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function view_order_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order && ! AbacatePay_WC_Gateway::is_order_paid( $order ) && ! $order->has_status( 'cancelled' ) ) {
			self::render( $order );
		}
	}

	/**
	 * Renders the boleto, its alternative Pix and the payment status.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render( WC_Order $order ) {
		if ( AbacatePay_WC_Boleto_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}

		$bar_code = (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_BAR_CODE );
		$paid     = AbacatePay_WC_Gateway::is_order_paid( $order );
		$expired  = ! $paid && $order->has_status( 'cancelled' );

		if ( ! $paid && '' === $bar_code ) {
			return;
		}

		if ( ! $paid && ! $expired ) {
			wp_enqueue_style( 'abacatepay-wc-boleto' );
			wp_enqueue_script( 'abacatepay-wc-boleto' );
		}

		wc_get_template( 'boleto-instructions.php', self::get_template_args( $order, $paid, $expired ), 'abacatepay-for-woocommerce/', ABACATEPAY_WC_PATH . 'templates/' );
	}

	/**
	 * Adds the boleto to customer emails while the order awaits payment.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the store admin.
	 * @param bool     $plain_text    Whether it is a plain text email.
	 */
	public static function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if (
			$sent_to_admin
			|| ! $order instanceof WC_Order
			|| AbacatePay_WC_Boleto_Gateway::ID !== $order->get_payment_method()
			|| ! $order->has_status( array( 'on-hold', 'pending' ) )
			|| AbacatePay_WC_Gateway::is_order_paid( $order )
			|| '' === (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_BAR_CODE )
		) {
			return;
		}

		wc_get_template(
			$plain_text ? 'emails/plain/boleto-instructions.php' : 'emails/boleto-instructions.php',
			self::get_template_args( $order, false, false ),
			'abacatepay-for-woocommerce/',
			ABACATEPAY_WC_PATH . 'templates/'
		);
	}

	/**
	 * Variables for the templates.
	 *
	 * @param WC_Order $order   Order.
	 * @param bool     $paid    Whether the order is paid.
	 * @param bool     $expired Whether the boleto expired.
	 * @return array
	 */
	private static function get_template_args( WC_Order $order, $paid, $expired ) {
		$qr_code = (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_PIX_QR_CODE );

		return array(
			'order'       => $order,
			'paid'        => $paid,
			'expired'     => $expired,
			'bar_code'    => (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_BAR_CODE ),
			'boleto_url'  => (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_BOLETO_URL ),
			'due_date'    => self::format_due_date( (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_EXPIRES_AT ) ),
			'pix_code'    => (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_PIX_BR_CODE ),
			'qr_code_url' => '' !== $qr_code ? self::endpoint_url( self::QR_CODE_ENDPOINT, $order ) : '',
			'late_fees'   => 'yes' === $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_LATE_FEES ),
			'dev_mode'    => 'yes' === $order->get_meta( AbacatePay_WC_Gateway::META_DEV_MODE ),
			'receipt_url' => (string) $order->get_meta( AbacatePay_WC_Gateway::META_RECEIPT_URL ),
		);
	}

	/**
	 * Serves the alternative Pix QR code PNG. Used in emails, where most clients block data: URIs.
	 */
	public static function ajax_qr_code() {
		$order   = self::get_order_from_request();
		$qr_code = $order ? (string) $order->get_meta( AbacatePay_WC_Boleto_Gateway::META_PIX_QR_CODE ) : '';
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
	 * Action Scheduler callback, runs after the due date.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function run_scheduled_check( $order_id ) {
		$gateway = AbacatePay_WC::gateway( AbacatePay_WC_Boleto_Gateway::ID );

		if ( $gateway ) {
			$gateway->run_scheduled_check( (int) $order_id );
		}
	}

	/**
	 * Adds "Check boleto status" and, for Dev mode charges, "Simulate payment" to the order actions.
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
			|| AbacatePay_WC_Boleto_Gateway::ID !== $order->get_payment_method()
			|| '' === (string) $order->get_meta( AbacatePay_WC_Gateway::META_CHARGE_ID )
			|| AbacatePay_WC_Gateway::is_order_paid( $order )
		) {
			return $actions;
		}

		$actions['abacatepay_check_boleto'] = __( 'Check boleto payment status on AbacatePay', 'abacatepay-for-woocommerce' );

		if ( 'yes' === $order->get_meta( AbacatePay_WC_Gateway::META_DEV_MODE ) ) {
			$actions['abacatepay_simulate_boleto'] = __( 'Simulate boleto payment (Dev mode)', 'abacatepay-for-woocommerce' );
		}

		return $actions;
	}

	/**
	 * Order action: sync the status with AbacatePay.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_check_boleto( $order ) {
		$gateway = AbacatePay_WC::gateway( AbacatePay_WC_Boleto_Gateway::ID );
		$status  = $gateway ? $gateway->sync_charge_status( $order ) : null;

		if ( is_wp_error( $status ) ) {
			/* translators: %s: error message */
			WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Could not check the boleto status: %s', 'abacatepay-for-woocommerce' ), $status->get_error_message() ) );
		} elseif ( $status ) {
			/* translators: %s: AbacatePay charge status, e.g. PENDING or PAID */
			$order->add_order_note( sprintf( __( 'Boleto status checked on AbacatePay: %s', 'abacatepay-for-woocommerce' ), $status ) );
		}
	}

	/**
	 * Order action: pay a Dev mode boleto through its alternative Pix.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_simulate_boleto( $order ) {
		$gateway = AbacatePay_WC::gateway( AbacatePay_WC_Boleto_Gateway::ID );

		if ( ! $gateway || 'yes' !== $order->get_meta( AbacatePay_WC_Gateway::META_DEV_MODE ) ) {
			return;
		}

		$result = $gateway->get_api()->simulate_payment( (string) $order->get_meta( AbacatePay_WC_Gateway::META_CHARGE_ID ) );

		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message */
			WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Could not simulate the payment: %s', 'abacatepay-for-woocommerce' ), $result->get_error_message() ) );

			return;
		}

		$order->add_order_note( __( 'Boleto payment simulated in AbacatePay Dev mode.', 'abacatepay-for-woocommerce' ) );

		// Don't wait for the webhook.
		$gateway->sync_charge_status( $order );
	}

	/**
	 * Formats the due date. AbacatePay sends the end of the due day in UTC, so it is shown in the store timezone.
	 *
	 * @param string $iso_date expiresAt.
	 * @return string Empty when missing.
	 */
	private static function format_due_date( $iso_date ) {
		if ( '' === $iso_date ) {
			return '';
		}

		return wc_format_datetime( wc_string_to_datetime( $iso_date ), get_option( 'date_format' ) );
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
	 * Boleto order from the order_id/key query args, if the key matches.
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

		return AbacatePay_WC_Boleto_Gateway::ID === $order->get_payment_method() ? $order : null;
	}
}
