<?php
/**
 * Boleto payment gateway.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues an AbacatePay transparent boleto when the order is placed. Each boleto also carries an alternative Pix
 * for the same charge, shown next to it.
 *
 * The payment is confirmed by the transparent.completed webhook. AbacatePay has no expiration webhook, so a
 * scheduled action checks the charge after the due date: it confirms payments whose webhook never arrived and,
 * once AbacatePay reports the boleto as expired, cancels the order.
 *
 * Boletos can't be refunded through the API, so refunds must be made manually.
 */
class AbacatePay_WC_Boleto_Gateway extends AbacatePay_WC_Gateway {

	const ID = 'abacatepay_boleto';

	const META_BAR_CODE    = '_abacatepay_boleto_bar_code';
	const META_BOLETO_URL  = '_abacatepay_boleto_url';
	const META_PIX_BR_CODE = '_abacatepay_boleto_pix_br_code';
	const META_PIX_QR_CODE = '_abacatepay_boleto_pix_qr_code';
	const META_LATE_FEES   = '_abacatepay_boleto_late_fees';
	const META_CHECKS      = '_abacatepay_boleto_checks';

	/**
	 * Same key as the Pix expiration, so the admin charge box shows it for both.
	 */
	const META_EXPIRES_AT = '_abacatepay_expires_at';

	/**
	 * Action Scheduler hook that checks the charge after the due date.
	 */
	const CHECK_HOOK = 'abacatepay_wc_check_boleto_charge';

	/**
	 * Action Scheduler group.
	 */
	const CHECK_GROUP = 'abacatepay-for-woocommerce';

	/**
	 * Furthest due date AbacatePay accepts, in days.
	 */
	const MAX_DUE_DAYS = 365;

	/**
	 * Daily checks after the due date. Boletos take up to 3 business days to clear; with interest or a fine
	 * they may also be paid late, so those are followed for longer.
	 */
	const MAX_CHECKS           = 7;
	const MAX_CHECKS_LATE_FEES = 60;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'AbacatePay Boleto', 'abacatepay-for-woocommerce' );
		$this->method_description = __( 'Issue boletos through AbacatePay. The customer can also pay the same charge with Pix, and the order is confirmed automatically once paid.', 'abacatepay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = apply_filters( 'abacatepay_wc_boleto_icon', '' );

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
				'title'       => __( 'Enable/Disable', 'abacatepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable boleto', 'abacatepay-for-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: link to the settings screen with the API key */
					__( 'The API key and the webhook are configured in %s.', 'abacatepay-for-woocommerce' ),
					'<a href="' . esc_url( self::get_credentials_settings_url() ) . '">' . esc_html__( 'AbacatePay Pix settings', 'abacatepay-for-woocommerce' ) . '</a>'
				),
			),
			'title'                => array(
				'title'       => __( 'Title', 'abacatepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to the customer at checkout.', 'abacatepay-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => __( 'Boleto', 'abacatepay-for-woocommerce' ),
			),
			'description'          => array(
				'title'       => __( 'Description', 'abacatepay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Text shown to the customer at checkout.', 'abacatepay-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => __( 'Pay with a boleto or with Pix. They will be shown after you place the order.', 'abacatepay-for-woocommerce' ),
			),
			'payment_section'      => array(
				'title' => __( 'Payment', 'abacatepay-for-woocommerce' ),
				'type'  => 'title',
			),
			'due_days'             => array(
				'title'             => __( 'Days until due date', 'abacatepay-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Calendar days after the order. Use 0 for the AbacatePay default (3 business days). Maximum 365.', 'abacatepay-for-woocommerce' ),
				'default'           => '3',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => (string) self::MAX_DUE_DAYS,
					'step' => '1',
				),
			),
			'interest_percent'     => array(
				'title'             => __( 'Late interest (% per month)', 'abacatepay-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Charged pro rata per day after the due date. Use 0 for no interest.', 'abacatepay-for-woocommerce' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			),
			'fine_type'            => array(
				'title'   => __( 'Late fine', 'abacatepay-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '',
				'options' => array(
					''           => __( 'No fine', 'abacatepay-for-woocommerce' ),
					'PERCENTAGE' => __( 'Percentage of the boleto amount', 'abacatepay-for-woocommerce' ),
					'FIXED'      => __( 'Fixed amount (R$)', 'abacatepay-for-woocommerce' ),
				),
			),
			'fine_value'           => array(
				'title'             => __( 'Late fine value', 'abacatepay-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Percentage (e.g. 2 for 2%) or amount in reais (e.g. 10 for R$ 10.00), charged once after the due date.', 'abacatepay-for-woocommerce' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			),
			'status_when_waiting'  => array(
				'title'       => __( 'Status while awaiting payment', 'abacatepay-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( '"On hold" reserves stock and emails the boleto to the customer. "Pending payment" does neither.', 'abacatepay-for-woocommerce' ),
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
				'title'       => __( 'Expired boleto', 'abacatepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Cancel the order (and restore stock) when AbacatePay reports the boleto as expired', 'abacatepay-for-woocommerce' ),
				'description' => __( 'Checked daily after the due date, since boletos take up to 3 business days to clear. With interest or a fine the boleto may stay payable after the due date.', 'abacatepay-for-woocommerce' ),
				'default'     => 'yes',
			),
		);
	}

	/**
	 * Issues the boleto and sends the customer to the "order received" page, where it is shown.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$missing = self::get_missing_boleto_customer_fields( $order );

		if ( $missing ) {
			/* translators: %s: comma-separated list of fields, e.g. "name, CPF/CNPJ" */
			wc_add_notice( sprintf( __( 'To pay with boleto, please fill in: %s.', 'abacatepay-for-woocommerce' ), implode( ', ', $missing ) ), 'error' );

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
			$note = __( 'AbacatePay boleto issued. Awaiting payment.', 'abacatepay-for-woocommerce' );
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
	 * Issues a boleto for the order, or reuses the current one when it is still payable for the same amount.
	 *
	 * @param WC_Order $order Order.
	 * @return array|WP_Error The charge.
	 */
	public function create_charge( WC_Order $order ) {
		$amount = self::to_cents( $order->get_total() );

		if ( $amount < self::MIN_AMOUNT ) {
			return new WP_Error( 'abacatepay_invalid_amount', __( 'Boleto payments must be at least R$ 1.00.', 'abacatepay-for-woocommerce' ) );
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
			'customer'    => self::get_boleto_customer_data( $order ),
		);

		$due_date = $this->get_due_date();

		if ( '' !== $due_date ) {
			$data['dueDate'] = $due_date;
		}

		$data = array_merge( $data, $this->get_late_fees() );

		$charge = $this->get_api()->create_boleto_charge( apply_filters( 'abacatepay_wc_boleto_charge_data', $data, $order ) );

		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		if ( empty( $charge['id'] ) || empty( $charge['barCode'] ) ) {
			AbacatePay_WC_Logger::error( 'Boleto charge response without id/barCode for order ' . $order->get_id() );

			return new WP_Error( 'abacatepay_invalid_response', __( 'Invalid response from AbacatePay.', 'abacatepay-for-woocommerce' ) );
		}

		$this->store_charge( $order, $charge, $amount );
		$order->update_meta_data( self::META_BAR_CODE, sanitize_text_field( (string) $charge['barCode'] ) );
		$order->update_meta_data( self::META_BOLETO_URL, isset( $charge['url'] ) ? esc_url_raw( $charge['url'] ) : '' );
		$order->update_meta_data( self::META_PIX_BR_CODE, isset( $charge['brCode'] ) ? (string) $charge['brCode'] : '' );
		$order->update_meta_data( self::META_PIX_QR_CODE, self::sanitize_qr_code( isset( $charge['brCodeBase64'] ) ? $charge['brCodeBase64'] : '' ) );
		$order->update_meta_data( self::META_EXPIRES_AT, isset( $charge['expiresAt'] ) ? sanitize_text_field( $charge['expiresAt'] ) : '' );
		$order->update_meta_data( self::META_LATE_FEES, isset( $data['interest'] ) || isset( $data['fine'] ) ? 'yes' : 'no' );
		$order->delete_meta_data( self::META_CHECKS );
		$order->save();

		$this->schedule_check( $order );

		return $charge;
	}

	/**
	 * The order's current boleto, if it can still be paid for this amount.
	 *
	 * @param WC_Order $order  Order.
	 * @param int      $amount Amount in cents.
	 * @return array|null
	 */
	private function get_reusable_charge( WC_Order $order, $amount ) {
		$charge_id = (string) $order->get_meta( self::META_CHARGE_ID );

		if ( '' === $charge_id || (int) $order->get_meta( self::META_AMOUNT ) !== $amount || '' === (string) $order->get_meta( self::META_BAR_CODE ) ) {
			return null;
		}

		$expires_at = strtotime( (string) $order->get_meta( self::META_EXPIRES_AT ) );

		if ( ! $expires_at || $expires_at < time() ) {
			return null;
		}

		$charge = $this->get_api()->get_charge( $charge_id );

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
	 * Due date sent to AbacatePay, in the store timezone.
	 *
	 * @return string YYYY-MM-DD, or empty for the AbacatePay default (3 business days).
	 */
	private function get_due_date() {
		$days = min( absint( $this->get_option( 'due_days', 3 ) ), self::MAX_DUE_DAYS );

		if ( 0 === $days ) {
			return '';
		}

		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( "+{$days} days" )->format( 'Y-m-d' );
	}

	/**
	 * Interest and fine objects, in the units AbacatePay uses (hundredths of a percent, or cents).
	 *
	 * @return array Possibly empty, with "interest" and/or "fine" keys.
	 */
	private function get_late_fees() {
		$fees     = array();
		$interest = (int) round( (float) $this->get_option( 'interest_percent', 0 ) * 100 );
		$type     = $this->get_option( 'fine_type' );
		$fine     = (int) round( (float) $this->get_option( 'fine_value', 0 ) * 100 );

		if ( $interest > 0 ) {
			$fees['interest'] = array( 'value' => $interest );
		}

		if ( $fine > 0 && in_array( $type, array( 'PERCENTAGE', 'FIXED' ), true ) ) {
			$fees['fine'] = array(
				'value' => $fine,
				'type'  => $type,
			);
		}

		return $fees;
	}

	/**
	 * Payer for the boleto: name and taxId are required, email and cellphone are sent when available.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function get_boleto_customer_data( WC_Order $order ) {
		$phone = (string) $order->get_meta( '_billing_cellphone' );

		$customer = array(
			'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'taxId'     => self::get_tax_id( $order ),
			'email'     => is_email( $order->get_billing_email() ) ? $order->get_billing_email() : '',
			'cellphone' => self::format_cellphone( '' !== $phone ? $phone : $order->get_billing_phone() ),
		);

		return array_filter( $customer, 'strlen' );
	}

	/**
	 * Names of the required payer fields missing from the order.
	 *
	 * @param WC_Order $order Order.
	 * @return string[] Translated field names.
	 */
	private static function get_missing_boleto_customer_fields( WC_Order $order ) {
		$customer = self::get_boleto_customer_data( $order );
		$missing  = array();

		if ( empty( $customer['name'] ) ) {
			$missing[] = __( 'name', 'abacatepay-for-woocommerce' );
		}

		if ( empty( $customer['taxId'] ) ) {
			$missing[] = __( 'CPF/CNPJ', 'abacatepay-for-woocommerce' );
		}

		return $missing;
	}

	/**
	 * Order note added when the payment is confirmed.
	 *
	 * @return string
	 */
	protected function get_payment_confirmed_note() {
		return __( 'Boleto payment confirmed by AbacatePay.', 'abacatepay-for-woocommerce' );
	}

	/**
	 * Confirms the payment and drops the pending status check.
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
	 * AbacatePay can't refund boletos through the API.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		return false;
	}

	/**
	 * AbacatePay can't refund boletos through the API.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount to refund.
	 * @param string     $reason   Refund reason.
	 * @return WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return new WP_Error( 'abacatepay_refund_error', __( 'Boletos can\'t be refunded through AbacatePay. Refund the customer manually.', 'abacatepay-for-woocommerce' ) );
	}

	/**
	 * Cancels an unpaid order whose boleto expired, if enabled in the settings.
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

		$order->update_status( 'cancelled', __( 'Boleto expired without payment.', 'abacatepay-for-woocommerce' ) );

		return true;
	}

	/**
	 * Asks AbacatePay for the current charge and updates the order to match.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error Charge status (PENDING, PAID, EXPIRED...).
	 */
	public function sync_charge_status( WC_Order $order ) {
		$charge_id = (string) $order->get_meta( self::META_CHARGE_ID );

		if ( '' === $charge_id ) {
			return new WP_Error( 'abacatepay_no_charge', __( 'This order has no AbacatePay charge.', 'abacatepay-for-woocommerce' ) );
		}

		$charge = $this->get_api()->get_charge( $charge_id );

		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';

		if ( 'PAID' === $status ) {
			$this->mark_order_paid( $order, isset( $charge['receiptUrl'] ) && is_string( $charge['receiptUrl'] ) ? $charge['receiptUrl'] : '' );
		} elseif ( in_array( $status, array( 'EXPIRED', 'CANCELLED' ), true ) ) {
			$this->cancel_expired_order( $order );
		}

		return $status;
	}

	/**
	 * Daily check after the due date: confirms payments whose webhook never arrived and cancels expired boletos.
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
		$limit  = 'yes' === $order->get_meta( self::META_LATE_FEES ) ? self::MAX_CHECKS_LATE_FEES : self::MAX_CHECKS;

		// Not cleared yet, payable late, or AbacatePay unreachable: look again tomorrow.
		if ( ( is_wp_error( $status ) || 'PENDING' === $status ) && $checks < $limit ) {
			$this->schedule_check( $order, time() + DAY_IN_SECONDS );
		}
	}

	/**
	 * Schedules the status check one day after the due date.
	 *
	 * @param WC_Order $order     Order.
	 * @param int|null $timestamp When to run. Defaults to one day after expiration.
	 */
	private function schedule_check( WC_Order $order, $timestamp = null ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( null === $timestamp ) {
			$expires_at = strtotime( (string) $order->get_meta( self::META_EXPIRES_AT ) );
			$timestamp  = ( $expires_at ? $expires_at : time() + 3 * DAY_IN_SECONDS ) + DAY_IN_SECONDS;
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
