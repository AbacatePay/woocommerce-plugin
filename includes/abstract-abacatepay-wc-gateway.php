<?php
/**
 * Base class for the AbacatePay payment methods.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared behavior for the transparent checkout methods (Pix, card, boleto): credentials, charge bookkeeping,
 * customer data, payment confirmation, refunds and webhook events.
 *
 * The API key, webhook secret and debug flag are configured once, in the Pix settings screen, and shared by
 * every method because AbacatePay uses a single key and a single webhook for all of them.
 */
abstract class AbacatePay_WC_Gateway extends WC_Payment_Gateway {

	const META_CHARGE_ID   = '_abacatepay_charge_id';
	const META_CHARGE_IDS  = '_abacatepay_charge_ids';
	const META_AMOUNT      = '_abacatepay_amount';
	const META_DEV_MODE    = '_abacatepay_dev_mode';
	const META_RECEIPT_URL = '_abacatepay_receipt_url';

	/**
	 * Gateway whose settings hold the shared credentials.
	 */
	const CREDENTIALS_GATEWAY_ID = 'abacatepay_pix';

	/**
	 * Smallest amount AbacatePay accepts, in cents.
	 */
	const MIN_AMOUNT = 100;

	/**
	 * Reads a shared setting (api_key, webhook_secret, debug).
	 *
	 * @param string $key     Setting key.
	 * @param string $default Value when missing.
	 * @return string
	 */
	public static function get_shared_setting( $key, $default = '' ) {
		$settings = get_option( 'woocommerce_' . self::CREDENTIALS_GATEWAY_ID . '_settings', array() );

		return is_array( $settings ) && isset( $settings[ $key ] ) ? (string) $settings[ $key ] : $default;
	}

	/**
	 * URL of the settings screen that holds the shared credentials.
	 *
	 * @return string
	 */
	public static function get_credentials_settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::CREDENTIALS_GATEWAY_ID );
	}

	/**
	 * Configured API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		return trim( self::get_shared_setting( 'api_key' ) );
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
	 * Only available with an API key and the store in BRL.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && '' !== $this->get_api_key() && 'BRL' === get_woocommerce_currency();
	}

	/**
	 * Warns about missing configuration above the settings form.
	 */
	public function admin_options() {
		if ( 'BRL' !== get_woocommerce_currency() ) {
			echo '<div class="notice notice-error inline"><p>';
			esc_html_e( 'AbacatePay only works when the store currency is Brazilian real (BRL).', 'abacatepay-for-woocommerce' );
			echo '</p></div>';
		}

		if ( 'yes' === $this->enabled && '' === $this->get_api_key() ) {
			echo '<div class="notice notice-warning inline"><p>';

			if ( self::CREDENTIALS_GATEWAY_ID === $this->id ) {
				esc_html_e( 'This payment method is enabled but will not be offered at checkout until you set an API key.', 'abacatepay-for-woocommerce' );
			} else {
				printf(
					/* translators: %s: link to the settings screen with the API key */
					esc_html__( 'This payment method is enabled but will not be offered at checkout until you set the API key in %s.', 'abacatepay-for-woocommerce' ),
					'<a href="' . esc_url( self::get_credentials_settings_url() ) . '">' . esc_html__( 'AbacatePay Pix settings', 'abacatepay-for-woocommerce' ) . '</a>'
				);
			}

			echo '</p></div>';
		}

		parent::admin_options();
	}

	/**
	 * Statuses that make sense after a confirmed payment, for the "Status after payment" setting.
	 *
	 * @return array<string, string>
	 */
	protected function get_paid_status_options() {
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
	 * Error shown at checkout. Problems the customer can't fix (credentials, permissions, outages) stay in the log.
	 *
	 * @param WP_Error $error Error from the API client.
	 * @return string
	 */
	protected function get_customer_error_message( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 'abacatepay_invalid_amount' === $error->get_error_code() ) {
			return $error->get_error_message();
		}

		if ( 'abacatepay_api_error' === $error->get_error_code() && in_array( $status, array( 400, 422 ), true ) ) {
			/* translators: %s: error message returned by AbacatePay */
			return sprintf( __( 'Could not process the payment: %s', 'abacatepay-for-woocommerce' ), $error->get_error_message() );
		}

		return __( 'This payment method is temporarily unavailable. Please try again or choose another payment method.', 'abacatepay-for-woocommerce' );
	}

	/**
	 * Stores the charge fields every method has and makes it the order's current charge. Does not save the order.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $charge Charge returned by POST /transparents/create.
	 * @param int      $amount Amount in cents.
	 */
	protected function store_charge( WC_Order $order, array $charge, $amount ) {
		$charge_ids   = (array) $order->get_meta( self::META_CHARGE_IDS );
		$charge_ids[] = (string) $charge['id'];

		$order->update_meta_data( self::META_CHARGE_ID, (string) $charge['id'] );
		$order->update_meta_data( self::META_CHARGE_IDS, array_values( array_unique( array_filter( $charge_ids ) ) ) );
		$order->update_meta_data( self::META_AMOUNT, $amount );
		$order->update_meta_data( self::META_DEV_MODE, empty( $charge['devMode'] ) ? 'no' : 'yes' );

		if ( ! empty( $charge['receiptUrl'] ) && is_string( $charge['receiptUrl'] ) ) {
			$order->update_meta_data( self::META_RECEIPT_URL, esc_url_raw( $charge['receiptUrl'] ) );
		}

		$order->set_transaction_id( (string) $charge['id'] );
	}

	/**
	 * Base metadata sent with every charge, used by the webhook to find the order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	protected static function get_charge_metadata( WC_Order $order ) {
		return array(
			'wcOrderId'     => (string) $order->get_id(),
			'wcOrderNumber' => (string) $order->get_order_number(),
		);
	}

	/**
	 * Order note added when the payment is confirmed.
	 *
	 * @return string
	 */
	protected function get_payment_confirmed_note() {
		return __( 'Payment confirmed by AbacatePay.', 'abacatepay-for-woocommerce' );
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

		$order->add_order_note( $this->get_payment_confirmed_note() );
		$order->payment_complete();

		$status = $this->get_option( 'status_when_paid' );

		if ( $status ) {
			$status = 'wc-' === substr( $status, 0, 3 ) ? substr( $status, 3 ) : $status;

			if ( ! $order->has_status( $status ) ) {
				$order->update_status( $status );
			}
		}

		return true;
	}

	/**
	 * Only orders with an AbacatePay charge can be refunded through AbacatePay.
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
			return new WP_Error( 'abacatepay_refund_error', __( 'This order has no AbacatePay charge.', 'abacatepay-for-woocommerce' ) );
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

		$order->add_order_note( __( 'Refunded through AbacatePay.', 'abacatepay-for-woocommerce' ) );

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
	 * Charge statuses that mean "paid" in transparent.completed. Card charges arrive as APPROVED.
	 *
	 * @return string[]
	 */
	protected function get_paid_charge_statuses() {
		return array( 'PAID' );
	}

	/**
	 * Applies a transparent.* webhook event to an order paid with this method.
	 *
	 * Handlers are idempotent because AbacatePay retries deliveries.
	 *
	 * @param string   $event  Event name, e.g. transparent.completed.
	 * @param array    $charge data.transparent.
	 * @param string   $reason data.reason, sent with refunds and disputes.
	 * @param WC_Order $order  Order.
	 */
	public function handle_webhook_event( $event, array $charge, $reason, WC_Order $order ) {
		switch ( $event ) {
			case 'transparent.completed':
				$this->handle_charge_completed( $order, $charge );
				break;

			case 'transparent.refunded':
				$this->handle_charge_refunded( $order, $reason );
				break;

			case 'transparent.lost':
				$this->handle_charge_refunded( $order, __( 'Dispute lost.', 'abacatepay-for-woocommerce' ) );
				break;

			case 'transparent.disputed':
				$this->handle_charge_disputed( $order, $charge, $reason );
				break;
		}
	}

	/**
	 * Payment confirmed.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $charge data.transparent.
	 */
	protected function handle_charge_completed( WC_Order $order, array $charge ) {
		$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';

		if ( ! in_array( $status, $this->get_paid_charge_statuses(), true ) || self::is_order_paid( $order ) ) {
			return;
		}

		$paid  = isset( $charge['amount'] ) ? (int) $charge['amount'] : 0;
		$total = self::to_cents( $order->get_total() );

		// Happens when an old charge is paid after the order total changed.
		if ( $paid !== $total ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: amount paid, 2: order total */
					__( 'Payment of %1$s received, but the order total is %2$s. Check the payment before shipping.', 'abacatepay-for-woocommerce' ),
					wp_strip_all_tags( wc_price( $paid / 100, array( 'currency' => $order->get_currency() ) ) ),
					wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
				)
			);

			return;
		}

		$this->mark_order_paid( $order, isset( $charge['receiptUrl'] ) && is_string( $charge['receiptUrl'] ) ? $charge['receiptUrl'] : '' );
	}

	/**
	 * Refund made outside WooCommerce (AbacatePay dashboard, customer portal or lost dispute).
	 * Refunds made from the order screen are already recorded, so there is nothing left to refund.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Refund reason.
	 */
	protected function handle_charge_refunded( WC_Order $order, $reason ) {
		if ( ! self::is_order_paid( $order ) ) {
			return;
		}

		$remaining = (float) $order->get_remaining_refund_amount();

		if ( $remaining <= 0 ) {
			return;
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $remaining,
				'reason'         => '' !== $reason ? $reason : __( 'Refunded through AbacatePay.', 'abacatepay-for-woocommerce' ),
				'refund_payment' => false,
				'restock_items'  => true,
			)
		);

		if ( is_wp_error( $refund ) ) {
			AbacatePay_WC_Logger::error( 'Could not record refund for order ' . $order->get_id() . ': ' . $refund->get_error_message() );

			return;
		}

		$order->add_order_note( __( 'Refunded through AbacatePay.', 'abacatepay-for-woocommerce' ) );
	}

	/**
	 * Dispute opened (UNDER_DISPUTE), won (paid status) or lost (REFUNDED).
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $charge data.transparent.
	 * @param string   $reason Dispute reason.
	 */
	protected function handle_charge_disputed( WC_Order $order, array $charge, $reason ) {
		$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';

		if ( 'UNDER_DISPUTE' === $status ) {
			$order->update_status(
				'on-hold',
				/* translators: %s: dispute reason */
				sprintf( __( 'Payment disputed by the payer: %s', 'abacatepay-for-woocommerce' ), '' !== $reason ? $reason : '-' )
			);
		} elseif ( in_array( $status, $this->get_paid_charge_statuses(), true ) ) {
			$note = __( 'Dispute closed in favor of the store.', 'abacatepay-for-woocommerce' );

			// Back to where it was before the dispute put it on hold.
			if ( $order->has_status( 'on-hold' ) && self::is_order_paid( $order ) ) {
				$order->update_status( $order->needs_processing() ? 'processing' : 'completed', $note );
			} else {
				$order->add_order_note( $note );
			}
		} elseif ( 'REFUNDED' === $status ) {
			$this->handle_charge_refunded( $order, __( 'Dispute lost.', 'abacatepay-for-woocommerce' ) );
		}
	}

	/**
	 * Customer data with the four fields AbacatePay requires for Pix and card (name, email, taxId, cellphone).
	 *
	 * @param WC_Order $order Order.
	 * @return array|null Null when any of them is missing.
	 */
	protected static function get_customer_data( WC_Order $order ) {
		$customer = self::build_customer( $order );

		return in_array( '', $customer, true ) ? null : $customer;
	}

	/**
	 * Names of the customer fields missing from the order, to tell the customer what to fill in.
	 *
	 * @param WC_Order $order Order.
	 * @return string[] Translated field names; empty when the customer data is complete.
	 */
	protected static function get_missing_customer_fields( WC_Order $order ) {
		$labels = array(
			'name'      => __( 'name', 'abacatepay-for-woocommerce' ),
			'email'     => __( 'email', 'abacatepay-for-woocommerce' ),
			'taxId'     => __( 'CPF/CNPJ', 'abacatepay-for-woocommerce' ),
			'cellphone' => __( 'phone', 'abacatepay-for-woocommerce' ),
		);

		$missing = array_filter(
			self::build_customer( $order ),
			function ( $value ) {
				return '' === $value;
			}
		);

		return array_values( array_intersect_key( $labels, $missing ) );
	}

	/**
	 * Customer fields from the order, each one empty when missing or malformed.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, string>
	 */
	private static function build_customer( WC_Order $order ) {
		$phone = (string) $order->get_meta( '_billing_cellphone' );

		return array(
			'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'     => is_email( $order->get_billing_email() ) ? $order->get_billing_email() : '',
			'taxId'     => self::get_tax_id( $order ),
			'cellphone' => self::format_cellphone( '' !== $phone ? $phone : $order->get_billing_phone() ),
		);
	}

	/**
	 * Formatted CPF or CNPJ from the "Brazilian Market on WooCommerce" checkout fields.
	 *
	 * @param WC_Order $order Order.
	 * @return string Empty when missing or malformed.
	 */
	protected static function get_tax_id( WC_Order $order ) {
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
	protected static function format_cellphone( $phone ) {
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
		$has_charge   = '' !== (string) $charge_id && in_array( (string) $charge_id, $charge_ids, true );

		// Lets a method accept a charge it could not record yet (e.g. card charge whose response timed out).
		return (bool) apply_filters( 'abacatepay_wc_order_has_charge', $has_charge, $order, (string) $charge_id );
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
