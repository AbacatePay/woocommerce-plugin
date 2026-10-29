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
 */
class AbacatePay_WC_Pix_Gateway extends WC_Payment_Gateway {

	const ID = 'abacatepay_pix';

	const META_CHARGE_ID   = '_abacatepay_charge_id';
	const META_CHARGE_IDS  = '_abacatepay_charge_ids';
	const META_AMOUNT      = '_abacatepay_amount';
	const META_BR_CODE     = '_abacatepay_br_code';
	const META_QR_CODE     = '_abacatepay_qr_code';
	const META_EXPIRES_AT  = '_abacatepay_expires_at';
	const META_DEV_MODE    = '_abacatepay_dev_mode';
	const META_RECEIPT_URL = '_abacatepay_receipt_url';
	const META_CHECKS      = '_abacatepay_scheduled_checks';

	/**
	 * Action Scheduler hook that checks the charge after it expires.
	 */
	const CHECK_HOOK = 'abacatepay_wc_check_pix_charge';

	/**
	 * Action Scheduler group.
	 */
	const CHECK_GROUP = 'abacatepay-for-woocommerce';

	/**
	 * Smallest amount AbacatePay accepts, in cents.
	 */
	const MIN_AMOUNT = 100;

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
					__( 'Create an API key in the AbacatePay dashboard (Integration) with the %1$s permissions. A Dev mode key creates test charges; a production key creates real ones. %2$s', 'abacatepay-for-woocommerce' ),
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
					__( 'Logs include customer data. Enable only while troubleshooting. View them in %s.', 'abacatepay-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'WooCommerce > Status > Logs', 'abacatepay-for-woocommerce' ) . '</a>'
				),
			),
		);
	}

	/**
	 * Statuses that make sense after a confirmed payment.
	 *
	 * @return array<string, string>
	 */
	private function get_paid_status_options() {
		$options = array(
			'' => __( 'WooCommerce default (Processing, or Completed for virtual/downloadable orders)', 'abacatepay-for-woocommerce' ),
		);

		$excluded = array( 'wc-pending', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft' );

		foreach ( wc_get_order_statuses() as $status => $label ) {
			if ( ! in_array( $status, $excluded, true ) ) {
				$options[ $status ] = $label;
			}
		}

		return $options;
	}

	/**
	 * Generates the webhook secret on first visit and warns about missing configuration.
	 */
	public function admin_options() {
		if ( '' === $this->get_option( 'webhook_secret' ) ) {
			$this->update_option( 'webhook_secret', wp_generate_password( 32, false ) );
		}

		if ( 'BRL' !== get_woocommerce_currency() ) {
			echo '<div class="notice notice-error inline"><p>';
			esc_html_e( 'Pix only works when the store currency is Brazilian real (BRL).', 'abacatepay-for-woocommerce' );
			echo '</p></div>';
		}

		if ( 'yes' === $this->enabled && '' === $this->get_api_key() ) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e( 'Pix is enabled but will not be offered at checkout until you set an API key.', 'abacatepay-for-woocommerce' );
			echo '</p></div>';
		}

		parent::admin_options();
	}

	/**
	 * Only available with an API key and the store in BRL.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && '' !== $this->get_api_key() && 'BRL' === get_woocommerce_currency();
	}

	/**
	 * Configured API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		return trim( (string) $this->get_option( 'api_key' ) );
	}

	/**
	 * API client.
	 *
	 * @return AbacatePay_WC_Api
	 */
	public function get_api() {
		return new AbacatePay_WC_Api( $this->get_api_key() );
	}

	/**
	 * Creates the Pix charge and sends the customer to the "order received" page, where the QR code is shown.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order  = wc_get_order( $order_id );
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
	 * Error shown at checkout. Problems the customer can't fix (credentials, permissions, outages) stay in the log.
	 *
	 * @param WP_Error $error Error from the API client.
	 * @return string
	 */
	private function get_customer_error_message( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 'abacatepay_invalid_amount' === $error->get_error_code() ) {
			return $error->get_error_message();
		}

		if ( 'abacatepay_api_error' === $error->get_error_code() && in_array( $status, array( 400, 422 ), true ) ) {
			/* translators: %s: error message returned by AbacatePay */
			return sprintf( __( 'Could not create the Pix: %s', 'abacatepay-for-woocommerce' ), $error->get_error_message() );
		}

		return __( 'Pix is temporarily unavailable. Please try again or choose another payment method.', 'abacatepay-for-woocommerce' );
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
			'metadata'    => array(
				'wcOrderId'     => (string) $order->get_id(),
				'wcOrderNumber' => (string) $order->get_order_number(),
			),
		);

		$expiration = absint( $this->get_option( 'expiration_minutes', 60 ) );

		if ( $expiration > 0 ) {
			$data['expiresIn'] = $expiration * MINUTE_IN_SECONDS;
		}

		$customer = self::get_customer_data( $order );

		if ( $customer ) {
			$data['customer'] = $customer;
		}

		$charge = $this->get_api()->create_pix_charge( apply_filters( 'abacatepay_wc_pix_charge_data', $data, $order ) );

		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		if ( empty( $charge['id'] ) || empty( $charge['brCode'] ) ) {
			AbacatePay_WC_Logger::error( 'Pix charge response without id/brCode for order ' . $order->get_id() );

			return new WP_Error( 'abacatepay_invalid_response', __( 'Invalid response from AbacatePay.', 'abacatepay-for-woocommerce' ) );
		}

		$charge_ids   = (array) $order->get_meta( self::META_CHARGE_IDS );
		$charge_ids[] = (string) $charge['id'];

		$order->update_meta_data( self::META_CHARGE_ID, (string) $charge['id'] );
		$order->update_meta_data( self::META_CHARGE_IDS, array_values( array_unique( array_filter( $charge_ids ) ) ) );
		$order->update_meta_data( self::META_AMOUNT, $amount );
		$order->update_meta_data( self::META_BR_CODE, (string) $charge['brCode'] );
		$order->update_meta_data( self::META_QR_CODE, self::sanitize_qr_code( isset( $charge['brCodeBase64'] ) ? $charge['brCodeBase64'] : '' ) );
		$order->update_meta_data( self::META_EXPIRES_AT, isset( $charge['expiresAt'] ) ? sanitize_text_field( $charge['expiresAt'] ) : '' );
		$order->update_meta_data( self::META_DEV_MODE, empty( $charge['devMode'] ) ? 'no' : 'yes' );
		$order->delete_meta_data( self::META_CHECKS );
		$order->set_transaction_id( (string) $charge['id'] );
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
	 * Customer data for the charge. AbacatePay requires all four fields when a customer is sent for Pix,
	 * so the charge goes without a customer when any of them is missing.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	private static function get_customer_data( WC_Order $order ) {
		$phone = (string) $order->get_meta( '_billing_cellphone' );

		$customer = array(
			'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'     => is_email( $order->get_billing_email() ) ? $order->get_billing_email() : '',
			'taxId'     => self::get_tax_id( $order ),
			'cellphone' => self::format_cellphone( '' !== $phone ? $phone : $order->get_billing_phone() ),
		);

		return in_array( '', $customer, true ) ? null : $customer;
	}

	/**
	 * Formatted CPF or CNPJ from the "Brazilian Market on WooCommerce" checkout fields.
	 *
	 * @param WC_Order $order Order.
	 * @return string Empty when missing or malformed.
	 */
	private static function get_tax_id( WC_Order $order ) {
		$cpf  = preg_replace( '/\D+/', '', (string) $order->get_meta( '_billing_cpf' ) );
		$cnpj = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '', (string) $order->get_meta( '_billing_cnpj' ) ) );

		// Person type: 1 = individual (CPF), 2 = company (CNPJ).
		$candidates = '2' === (string) $order->get_meta( '_billing_persontype' ) ? array( $cnpj, $cpf ) : array( $cpf, $cnpj );

		foreach ( $candidates as $tax_id ) {
			if ( 1 === preg_match( '/^(\d{3})(\d{3})(\d{3})(\d{2})$/', $tax_id, $m ) ) {
				return "{$m[1]}.{$m[2]}.{$m[3]}-{$m[4]}";
			}

			// Since July 2026 a CNPJ may have letters in its first 12 characters.
			if ( 1 === preg_match( '/^([A-Z0-9]{2})([A-Z0-9]{3})([A-Z0-9]{3})([A-Z0-9]{4})(\d{2})$/', $tax_id, $m ) ) {
				return "{$m[1]}.{$m[2]}.{$m[3]}/{$m[4]}-{$m[5]}";
			}
		}

		return '';
	}

	/**
	 * Formats a Brazilian phone as "(11) 91234-5678".
	 *
	 * @param string $phone Phone as typed by the customer.
	 * @return string Empty when it doesn't look like a Brazilian number.
	 */
	private static function format_cellphone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );

		if ( strlen( $digits ) > 11 && 0 === strpos( $digits, '55' ) ) {
			$digits = substr( $digits, 2 );
		}

		if ( 1 === preg_match( '/^(\d{2})(\d{4,5})(\d{4})$/', $digits, $m ) ) {
			return "({$m[1]}) {$m[2]}-{$m[3]}";
		}

		return '';
	}

	/**
	 * Confirms the payment. Safe to call more than once.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $receipt_url AbacatePay receipt URL.
	 * @return bool Whether the order was updated.
	 */
	public function mark_order_paid( WC_Order $order, $receipt_url = '' ) {
		if ( self::is_order_paid( $order ) ) {
			return false;
		}

		if ( '' !== $receipt_url ) {
			$order->update_meta_data( self::META_RECEIPT_URL, esc_url_raw( $receipt_url ) );
		}

		$order->add_order_note( __( 'Pix payment confirmed by AbacatePay.', 'abacatepay-for-woocommerce' ) );
		$order->payment_complete();

		$status = $this->get_option( 'status_when_paid' );

		if ( $status ) {
			$status = 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;

			if ( ! $order->has_status( $status ) ) {
				$order->update_status( $status );
			}
		}

		$this->unschedule_check( $order );

		return true;
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
			return new WP_Error( 'abacatepay_no_charge', __( 'This order has no Pix charge.', 'abacatepay-for-woocommerce' ) );
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
	 * Only orders with a Pix charge can be refunded through AbacatePay.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		return parent::can_refund_order( $order ) && '' !== (string) $order->get_meta( self::META_CHARGE_ID );
	}

	/**
	 * Refunds through AbacatePay, which only supports refunding the full amount paid.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount to refund.
	 * @param string     $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order     = wc_get_order( $order_id );
		$charge_id = $order ? (string) $order->get_meta( self::META_CHARGE_ID ) : '';
		$paid      = $order ? (int) $order->get_meta( self::META_AMOUNT ) : 0;

		if ( '' === $charge_id ) {
			return new WP_Error( 'abacatepay_refund_error', __( 'This order has no Pix charge.', 'abacatepay-for-woocommerce' ) );
		}

		if ( self::to_cents( $amount ) !== $paid ) {
			return new WP_Error(
				'abacatepay_refund_error',
				sprintf(
					/* translators: %s: amount paid */
					__( 'AbacatePay only supports refunding the full amount paid (%s).', 'abacatepay-for-woocommerce' ),
					// Shown in a JS alert(), so it must be plain text.
					html_entity_decode( wp_strip_all_tags( wc_price( $paid / 100, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' )
				)
			);
		}

		$response = $this->get_api()->refund_charge( $charge_id, mb_substr( (string) $reason, 0, 255 ) );

		if ( is_wp_error( $response ) && ! self::is_already_refunded_error( $response ) ) {
			return $response;
		}

		$order->add_order_note( __( 'Pix refunded through AbacatePay.', 'abacatepay-for-woocommerce' ) );

		return true;
	}

	/**
	 * AbacatePay answers HTTP 400 "Esta cobrança já foi reembolsada." when the charge was already refunded.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private static function is_already_refunded_error( WP_Error $error ) {
		return false !== mb_stripos( $error->get_error_message(), 'já foi reembolsada' );
	}

	/**
	 * Whether the order has been paid, including when the merchant moved it to a custom status.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_order_paid( WC_Order $order ) {
		return $order->is_paid() || null !== $order->get_date_paid();
	}

	/**
	 * Whether the charge belongs to the order (current or a previous attempt).
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $charge_id Charge ID.
	 * @return bool
	 */
	public static function order_has_charge( WC_Order $order, $charge_id ) {
		$charge_ids   = (array) $order->get_meta( self::META_CHARGE_IDS );
		$charge_ids[] = (string) $order->get_meta( self::META_CHARGE_ID );

		return '' !== (string) $charge_id && in_array( (string) $charge_id, $charge_ids, true );
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

	/**
	 * Converts a decimal amount to cents.
	 *
	 * @param float|string|null $amount Amount.
	 * @return int
	 */
	public static function to_cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}
}
