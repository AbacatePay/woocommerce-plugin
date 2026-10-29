<?php
/**
 * Pix payment gateway.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates an AbacatePay transparent Pix charge when the order is placed and shows the QR code on the store.
 *
 * The payment is confirmed by the transparent.completed webhook (AbacatePay_WC_Webhook). As a fallback, the
 * "order received" page polls the charge status and a scheduled action checks it once the Pix expires.
 *
 * Its settings screen also holds the credentials shared by all AbacatePay methods.
 */
class AbacatePay_WC_Pix_Gateway extends AbacatePay_WC_Gateway {

	const ID = 'abacatepay_pix';

	const META_BR_CODE    = '_abacatepay_br_code';
	const META_QR_CODE    = '_abacatepay_qr_code';
	const META_EXPIRES_AT = '_abacatepay_expires_at';
	const META_CHECKS     = '_abacatepay_scheduled_checks';

	/**
	 * Action Scheduler hook that checks the charge after it expires.
	 */
	const CHECK_HOOK = 'abacatepay_wc_check_pix_charge';

	/**
	 * Action Scheduler group.
	 */
	const CHECK_GROUP = 'abacatepay-for-woocommerce';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'AbacatePay Pix', 'abacatepay-for-woocommerce' );
		$this->method_description = __( 'Accept Pix through AbacatePay. The QR code is shown right after checkout and the order is confirmed automatically once paid.', 'abacatepay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = apply_filters( 'abacatepay_wc_pix_icon', '' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Enable/Disable', 'abacatepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Pix', 'abacatepay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title', 'abacatepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to the customer at checkout.', 'abacatepay-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => __( 'Pix', 'abacatepay-for-woocommerce' ),
			),
			'description'          => array(
				'title'       => __( 'Description', 'abacatepay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Text shown to the customer at checkout.', 'abacatepay-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => __( 'Pay instantly with Pix. The QR code will be shown after you place the order.', 'abacatepay-for-woocommerce' ),
			),
			'api_section'          => array(
				'title'       => __( 'AbacatePay integration', 'abacatepay-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1: list of permissions, 2: link to the documentation */
					__( 'Used by all AbacatePay payment methods. Create an API key in the AbacatePay dashboard (Integration) with the %1$s permissions. A Dev mode key creates test charges; a production key creates real ones. %2$s', 'abacatepay-for-woocommerce' ),
					'<code>TRANSPARENT:CREATE</code>, <code>TRANSPARENT:READ</code>, <code>REFUND:CREATE</code>',
					'<a href="https://docs.abacatepay.com/pages/authentication" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn more', 'abacatepay-for-woocommerce' ) . '</a>'
				),
			),
			'api_key'              => array(
				'title'   => __( 'API key', 'abacatepay-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'webhook_section'      => array(
				'title'       => __( 'Webhook', 'abacatepay-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1: webhook URL, 2: list of events */
					__( 'In the AbacatePay dashboard, create a webhook with the URL %1$s, the secret below and the events %2$s. Without it, orders are only confirmed when the customer is on the order page or after the Pix expires.', 'abacatepay-for-woocommerce' ),
					'<code>' . esc_html( AbacatePay_WC_Webhook::url() ) . '</code>',
					'<code>transparent.completed</code>, <code>transparent.refunded</code>, <code>transparent.disputed</code>, <code>transparent.lost</code>'
				),
			),
			'webhook_secret'       => array(
				'title'       => __( 'Webhook secret', 'abacatepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Generated automatically. Use the same value when creating the webhook. Webhooks with a different secret are rejected.', 'abacatepay-for-woocommerce' ),
				'default'     => '',
			),
			'payment_section'      => array(
				'title' => __( 'Payment', 'abacatepay-for-woocommerce' ),
				'type'  => 'title',
			),
			'expiration_minutes'   => array(
				'title'             => __( 'Pix expiration (minutes)', 'abacatepay-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'How long the QR code can be paid. Use 0 for the AbacatePay default.', 'abacatepay-for-woocommerce' ),
				'default'           => '60',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			'require_customer'     => array(
				'title'       => __( 'Customer data', 'abacatepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Require name, email, CPF/CNPJ and phone to pay with Pix', 'abacatepay-for-woocommerce' ),
				'description' => __( 'AbacatePay only accepts the customer on a Pix charge with all four fields. When disabled, orders missing any of them are charged without customer data. CPF/CNPJ comes from the "Brazilian Market on WooCommerce" plugin.', 'abacatepay-for-woocommerce' ),
				'default'     => 'yes',
			),
			'status_when_waiting'  => array(
				'title'       => __( 'Status while awaiting payment', 'abacatepay-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( '"On hold" reserves stock and emails the QR code to the customer. "Pending payment" does neither.', 'abacatepay-for-woocommerce' ),
				'default'     => 'on-hold',
				'options'     => array(
					'on-hold' => _x( 'On hold', 'Order status', 'abacatepay-for-woocommerce' ),
					'pending' => _x( 'Pending payment', 'Order status', 'abacatepay-for-woocommerce' ),
				),
			),
			'status_when_paid'     => array(
				'title'   => __( 'Status after payment', 'abacatepay-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '',
				'options' => $this->get_paid_status_options(),
			),
			'cancel_on_expiration' => array(
				'title'   => __( 'Expired Pix', 'abacatepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Cancel the order (and restore stock) when the Pix expires unpaid', 'abacatepay-for-woocommerce' ),
				'default' => 'yes',
			),
			'debug'                => array(
				'title'       => __( 'Debug log', 'abacatepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API requests and responses', 'abacatepay-for-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: link to the WooCommerce logs screen */
					__( 'Applies to all AbacatePay payment methods. Logs include customer data. Enable only while troubleshooting. View them in %s.', 'abacatepay-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'WooCommerce > Status > Logs', 'abacatepay-for-woocommerce' ) . '</a>'
				),
			),
		);
	}

	/**
	 * Generates the webhook secret on first visit.
	 */
	public function admin_options() {
		if ( '' === $this->get_option( 'webhook_secret' ) ) {
			$this->update_option( 'webhook_secret', wp_generate_password( 32, false ) );
		}

		parent::admin_options();
	}

	/**
	 * Creates the Pix charge and sends the customer to the "order received" page, where the QR code is shown.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$missing = self::get_missing_customer_fields( $order );

		if ( $missing && 'yes' === $this->get_option( 'require_customer', 'yes' ) ) {
			/* translators: %s: comma-separated list of fields, e.g. "CPF/CNPJ, phone" */
			wc_add_notice( sprintf( __( 'To pay with Pix, please fill in: %s.', 'abacatepay-for-woocommerce' ), implode( ', ', $missing ) ), 'error' );

			return array( 'result' => 'failure' );
		}

		$charge = $this->create_charge( $order );

		if ( is_wp_error( $charge ) ) {
			wc_add_notice( $this->get_customer_error_message( $charge ), 'error' );

			return array( 'result' => 'failure' );
		}

		if ( isset( $charge['status'] ) && 'PAID' === $charge['status'] ) {
			$this->mark_order_paid( $order );
		} else {
			$note = __( 'AbacatePay Pix charge created. Awaiting payment.', 'abacatepay-for-woocommerce' );
			$wait = $this->get_option( 'status_when_waiting', 'on-hold' );

			if ( $order->has_status( $wait ) ) {
				if ( empty( $charge['reused'] ) ) {
					$order->add_order_note( $note );
				}
			} else {
				$order->update_status( $wait, $note );
			}
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Creates a Pix charge for the order, or reuses the current one when it is still payable.
	 *
	 * Reuse matters when the customer retries from the "Pay for order" page: a new QR code is only issued when
	 * the total changed or the previous one expired.
	 *
	 * @param WC_Order $order Order.
	 * @return array|WP_Error The charge.
	 */
	public function create_charge( WC_Order $order ) {
		$amount = self::to_cents( $order->get_total() );

		if ( $amount < self::MIN_AMOUNT ) {
			return new WP_Error( 'abacatepay_invalid_amount', __( 'Pix payments must be at least R$ 1.00.', 'abacatepay-for-woocommerce' ) );
		}

		$current = $this->get_reusable_charge( $order, $amount );

		if ( $current ) {
			return $current;
		}

		$data = array(
			'amount'      => $amount,
			/* translators: 1: store name, 2: order number */
			'description' => mb_substr( sprintf( __( '%1$s - Order #%2$s', 'abacatepay-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() ), 0, 500 ),
			'externalId'  => 'wc-' . $order->get_id() . '-' . wp_generate_password( 8, false ),
			'metadata'    => self::get_charge_metadata( $order ),
		);

		$expiration = absint( $this->get_option( 'expiration_minutes', 60 ) );

		if ( $expiration > 0 ) {
			$data['expiresIn'] = $expiration * MINUTE_IN_SECONDS;
		}

		$customer = self::get_customer_data( $order );

		if ( $customer ) {
			$data['customer'] = $customer;
		} else {
			AbacatePay_WC_Logger::warning( 'Pix charge for order ' . $order->get_id() . ' sent without customer. Missing: ' . implode( ', ', self::get_missing_customer_fields( $order ) ) );
		}

		$charge = $this->get_api()->create_pix_charge( apply_filters( 'abacatepay_wc_pix_charge_data', $data, $order ) );

		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		if ( empty( $charge['id'] ) || empty( $charge['brCode'] ) ) {
			AbacatePay_WC_Logger::error( 'Pix charge response without id/brCode for order ' . $order->get_id() );

			return new WP_Error( 'abacatepay_invalid_response', __( 'Invalid response from AbacatePay.', 'abacatepay-for-woocommerce' ) );
		}

		$this->store_charge( $order, $charge, $amount );
		$order->update_meta_data( self::META_BR_CODE, (string) $charge['brCode'] );
		$order->update_meta_data( self::META_QR_CODE, self::sanitize_qr_code( isset( $charge['brCodeBase64'] ) ? $charge['brCodeBase64'] : '' ) );
		$order->update_meta_data( self::META_EXPIRES_AT, isset( $charge['expiresAt'] ) ? sanitize_text_field( $charge['expiresAt'] ) : '' );
		$order->delete_meta_data( self::META_CHECKS );
		$order->save();

		$this->schedule_check( $order );

		return $charge;
	}

	/**
	 * The order's current charge, if it can still be paid for this amount.
	 *
	 * @param WC_Order $order  Order.
	 * @param int      $amount Amount in cents.
	 * @return array|null
	 */
	private function get_reusable_charge( WC_Order $order, $amount ) {
		$charge_id = (string) $order->get_meta( self::META_CHARGE_ID );

		if ( '' === $charge_id || (int) $order->get_meta( self::META_AMOUNT ) !== $amount || '' === (string) $order->get_meta( self::META_BR_CODE ) ) {
			return null;
		}

		// Leave the customer some time to pay before reusing it.
		$expires_at = strtotime( (string) $order->get_meta( self::META_EXPIRES_AT ) );

		if ( ! $expires_at || $expires_at < time() + 5 * MINUTE_IN_SECONDS ) {
			return null;
		}

		$charge = $this->get_api()->check_charge( $charge_id );

		if ( is_wp_error( $charge ) || ! isset( $charge['status'] ) || ! in_array( $charge['status'], array( 'PENDING', 'PAID' ), true ) ) {
			return null;
		}

		return array(
			'id'     => $charge_id,
			'status' => $charge['status'],
			'reused' => true,
		);
	}

	/**
	 * Order note added when the payment is confirmed.
	 *
	 * @return string
	 */
	protected function get_payment_confirmed_note() {
		return __( 'Pix payment confirmed by AbacatePay.', 'abacatepay-for-woocommerce' );
	}

	/**
	 * Confirms the payment and drops the pending expiration check.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $receipt_url AbacatePay receipt URL.
	 * @return bool Whether the order was updated.
	 */
	public function mark_order_paid( WC_Order $order, $receipt_url = '' ) {
		$updated = parent::mark_order_paid( $order, $receipt_url );

		if ( $updated ) {
			$this->unschedule_check( $order );
		}

		return $updated;
	}

	/**
	 * Cancels an unpaid order whose Pix expired, if enabled in the settings.
	 *
	 * @param WC_Order $order Order.
	 * @return bool Whether the order was cancelled.
	 */
	public function cancel_expired_order( WC_Order $order ) {
		if ( 'yes' !== $this->get_option( 'cancel_on_expiration', 'yes' ) ) {
			return false;
		}

		if ( self::is_order_paid( $order ) || ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return false;
		}

		$order->update_status( 'cancelled', __( 'Pix expired without payment.', 'abacatepay-for-woocommerce' ) );

		return true;
	}

	/**
	 * Asks AbacatePay for the current charge status and updates the order to match.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error Charge status (PENDING, PAID, EXPIRED...).
	 */
	public function sync_charge_status( WC_Order $order ) {
		$charge_id = (string) $order->get_meta( self::META_CHARGE_ID );

		if ( '' === $charge_id ) {
			return new WP_Error( 'abacatepay_no_charge', __( 'This order has no AbacatePay charge.', 'abacatepay-for-woocommerce' ) );
		}

		$charge = $this->get_api()->check_charge( $charge_id );

		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';

		if ( 'PAID' === $status ) {
			$this->mark_order_paid( $order );
		} elseif ( in_array( $status, array( 'EXPIRED', 'CANCELLED' ), true ) ) {
			$this->cancel_expired_order( $order );
		}

		return $status;
	}

	/**
	 * Scheduled check after the Pix expires: confirms payments whose webhook never arrived and cancels the rest.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run_scheduled_check( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || self::ID !== $order->get_payment_method() || self::is_order_paid( $order ) ) {
			return;
		}

		$checks = (int) $order->get_meta( self::META_CHECKS ) + 1;
		$order->update_meta_data( self::META_CHECKS, $checks );
		$order->save();

		$status = $this->sync_charge_status( $order );

		// Still pending (clock skew) or AbacatePay unreachable: try again a few times.
		if ( ( is_wp_error( $status ) || 'PENDING' === $status ) && $checks < 5 ) {
			$this->schedule_check( $order, time() + 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Schedules the status check shortly after the charge expires.
	 *
	 * @param WC_Order $order     Order.
	 * @param int|null $timestamp When to run. Defaults to two minutes after expiration.
	 */
	private function schedule_check( WC_Order $order, $timestamp = null ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( null === $timestamp ) {
			$expires_at = strtotime( (string) $order->get_meta( self::META_EXPIRES_AT ) );
			$timestamp  = ( $expires_at ? $expires_at : time() + DAY_IN_SECONDS ) + 2 * MINUTE_IN_SECONDS;
		}

		$this->unschedule_check( $order );
		as_schedule_single_action( $timestamp, self::CHECK_HOOK, array( $order->get_id() ), self::CHECK_GROUP );
	}

	/**
	 * Removes the pending status check.
	 *
	 * @param WC_Order $order Order.
	 */
	private function unschedule_check( WC_Order $order ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CHECK_HOOK, array( $order->get_id() ), self::CHECK_GROUP );
		}
	}

	/**
	 * Keeps only a PNG data URI, the format AbacatePay uses for brCodeBase64.
	 *
	 * @param string $value Data URI.
	 * @return string
	 */
	private static function sanitize_qr_code( $value ) {
		return 1 === preg_match( '#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#', (string) $value ) ? (string) $value : '';
	}
}
