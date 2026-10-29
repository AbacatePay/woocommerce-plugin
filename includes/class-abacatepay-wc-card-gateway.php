<?php
/**
 * Credit card payment gateway.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Charges credit cards through the AbacatePay transparent checkout.
 *
 * The card is typed in the abacatepay.js secure form (an iframe), which handles 3DS and returns a single-use
 * ctok_ token valid for 60 seconds. Only that token reaches the store, and it is charged right away with
 * POST /transparents/create (method CARD).
 *
 * A timeout is not a decline: the card may have been charged. In that case the order is put on hold and a
 * scheduled action looks the charge up later (by the order ID sent in the metadata) instead of retrying.
 *
 * @see https://docs.abacatepay.com/pages/transparents/card
 */
class AbacatePay_WC_Card_Gateway extends AbacatePay_WC_Gateway {

	const ID = 'abacatepay_card';

	const META_BRAND        = '_abacatepay_card_brand';
	const META_LAST_FOUR    = '_abacatepay_card_last_four';
	const META_INSTALLMENTS = '_abacatepay_card_installments';
	const META_CHECKS       = '_abacatepay_card_checks';

	/**
	 * Set while the outcome of a charge request is unknown (no response yet, or the response was lost).
	 */
	const META_LOOKUP = '_abacatepay_card_lookup';

	/**
	 * Action Scheduler hook that checks pending and unknown charges.
	 */
	const CHECK_HOOK = 'abacatepay_wc_check_card_charge';

	/**
	 * Action Scheduler group.
	 */
	const CHECK_GROUP = 'abacatepay-for-woocommerce';

	/**
	 * How many times the scheduled check runs before giving up.
	 */
	const MAX_CHECKS = 6;

	/**
	 * AbacatePay requires at least R$ 10,00 per installment.
	 */
	const MIN_INSTALLMENT_AMOUNT = 1000;

	/**
	 * Always loaded from AbacatePay: the file must not be copied to the store.
	 */
	const SCRIPT_URL = 'https://abacatepay.com/abacatepay.js';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'AbacatePay Credit Card', 'abacatepay-for-woocommerce' );
		$this->method_description = __( 'Accept credit cards through AbacatePay, in up to 12 installments. Card details are typed in a secure AbacatePay form and never reach the store.', 'abacatepay-for-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = apply_filters( 'abacatepay_wc_card_icon', '' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings fields. The API key, webhook and debug log are shared and live in the Pix settings.
	 */
	public function init_form_fields() {
		$installments = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			/* translators: %d: number of installments */
			$installments[ $i ] = 1 === $i ? __( 'Single payment only', 'abacatepay-for-woocommerce' ) : sprintf( __( 'Up to %dx', 'abacatepay-for-woocommerce' ), $i );
		}

		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'abacatepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable credit card', 'abacatepay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'abacatepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to the customer at checkout.', 'abacatepay-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => __( 'Credit card', 'abacatepay-for-woocommerce' ),
			),
			'description'      => array(
				'title'       => __( 'Description', 'abacatepay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Text shown to the customer at checkout.', 'abacatepay-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => __( 'Pay with your credit card.', 'abacatepay-for-woocommerce' ),
			),
			'card_section'     => array(
				'title'       => __( 'Card form', 'abacatepay-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: link to the settings screen with the API key */
					__( 'The API key, webhook and debug log are shared with Pix: configure them in %s. The public key and the API key must be from the same environment (both Dev mode or both production), or every charge fails with "Invalid or expired card token".', 'abacatepay-for-woocommerce' ),
					'<a href="' . esc_url( self::get_credentials_settings_url() ) . '">' . esc_html__( 'AbacatePay Pix settings', 'abacatepay-for-woocommerce' ) . '</a>'
				),
			),
			'public_key'       => array(
				'title'       => __( 'Public key', 'abacatepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Starts with pk_. Find it in the AbacatePay dashboard, under Integration > Own checkout. It is safe to show in the browser.', 'abacatepay-for-woocommerce' ),
				'default'     => '',
				'placeholder' => 'pk_...',
			),
			'max_installments' => array(
				'title'       => __( 'Installments', 'abacatepay-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Interest-free for the customer. Each installment must be at least R$ 10.00, so fewer options are offered for small orders.', 'abacatepay-for-woocommerce' ),
				'default'     => '1',
				'options'     => $installments,
			),
			'status_when_paid' => array(
				'title'   => __( 'Status after payment', 'abacatepay-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '',
				'options' => $this->get_paid_status_options(),
			),
		);
	}

	/**
	 * Warns about a missing public key above the settings form.
	 */
	public function admin_options() {
		if ( 'yes' === $this->enabled && '' === $this->get_public_key() ) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e( 'Credit card is enabled but will not be offered at checkout until you set the public key.', 'abacatepay-for-woocommerce' );
			echo '</p></div>';
		}

		parent::admin_options();
	}

	/**
	 * Also needs the public key.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && '' !== $this->get_public_key();
	}

	/**
	 * Configured public key (pk_...).
	 *
	 * @return string
	 */
	public function get_public_key() {
		return trim( (string) $this->get_option( 'public_key' ) );
	}

	/**
	 * Card form on the classic checkout and on the "Pay for order" page. The card fields themselves are
	 * drawn by abacatepay.js inside .abacatepay-card__form.
	 */
	public function payment_fields() {
		if ( $this->get_description() ) {
			echo wp_kses_post( wpautop( wptexturize( $this->get_description() ) ) );
		}

		$this->enqueue_scripts();

		$order  = $this->get_pay_page_order();
		$amount = $order ? self::to_cents( $order->get_total() ) : ( WC()->cart ? self::to_cents( WC()->cart->get_total( 'edit' ) ) : 0 );
		$payer  = '';

		// The "Pay for order" page has no billing fields, so the payer comes from the order.
		if ( $order ) {
			$customer = self::get_customer_data( $order );
			$payer    = wp_json_encode(
				array(
					'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'email'     => $order->get_billing_email(),
					'taxId'     => self::get_tax_id( $order ),
					'cellphone' => $customer ? $customer['cellphone'] : '',
				)
			);
		}

		$options = self::get_installment_options( $amount, (int) $this->get_option( 'max_installments', 1 ) );
		?>
		<div class="abacatepay-card" data-amount="<?php echo esc_attr( $amount ); ?>"<?php echo '' !== $payer ? ' data-payer="' . esc_attr( $payer ) . '"' : ''; ?>>
			<div class="abacatepay-card__form"></div>

			<?php if ( self::needs_tax_id_field() && ! ( $order && '' !== self::get_tax_id( $order ) ) ) : ?>
				<p class="form-row form-row-wide">
					<label for="abacatepay_card_tax_id"><?php esc_html_e( 'Cardholder CPF or CNPJ', 'abacatepay-for-woocommerce' ); ?>&nbsp;<abbr class="required">*</abbr></label>
					<input type="text" class="input-text" id="abacatepay_card_tax_id" name="abacatepay_card_tax_id" inputmode="numeric" autocomplete="off" />
				</p>
			<?php endif; ?>

			<?php if ( count( $options ) > 1 ) : ?>
				<p class="form-row form-row-wide">
					<label for="abacatepay_card_installments"><?php esc_html_e( 'Installments', 'abacatepay-for-woocommerce' ); ?></label>
					<select id="abacatepay_card_installments" name="abacatepay_card_installments">
						<?php foreach ( $options as $count => $label ) : ?>
							<option value="<?php echo esc_attr( $count ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>

			<input type="hidden" id="abacatepay_card_token" name="abacatepay_card_token" value="" />
			<p class="abacatepay-card__error" role="alert" hidden></p>
		</div>
		<?php
	}

	/**
	 * Loads abacatepay.js and the form script. Called while rendering the payment fields.
	 */
	private function enqueue_scripts() {
		wp_enqueue_style( 'abacatepay-wc-card', ABACATEPAY_WC_URL . 'assets/css/card.css', array(), ABACATEPAY_WC_VERSION );
		wp_register_script( 'abacatepay-js', self::SCRIPT_URL, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Versioned by AbacatePay.
		wp_enqueue_script( 'abacatepay-wc-card', ABACATEPAY_WC_URL . 'assets/js/card.js', array( 'jquery', 'abacatepay-js' ), ABACATEPAY_WC_VERSION, true );
		wp_localize_script(
			'abacatepay-wc-card',
			'abacatepayCardParams',
			array(
				'publicKey' => $this->get_public_key(),
				'i18n'      => self::get_script_messages(),
			)
		);
	}

	/**
	 * Messages used by the card scripts (classic and blocks).
	 *
	 * @return array<string, string>
	 */
	public static function get_script_messages() {
		return array(
			'incomplete'    => __( 'Please fill in all the card fields.', 'abacatepay-for-woocommerce' ),
			'invalid'       => __( 'Please check the card details.', 'abacatepay-for-woocommerce' ),
			'threeDsFailed' => __( 'The card authentication was not completed. Try again or use another card.', 'abacatepay-for-woocommerce' ),
			'payerInvalid'  => __( 'Please check your name, email and CPF/CNPJ.', 'abacatepay-for-woocommerce' ),
			'taxIdRequired' => __( 'Please fill in the cardholder CPF or CNPJ.', 'abacatepay-for-woocommerce' ),
			'notLoaded'     => __( 'The card form did not load. Reload the page and try again.', 'abacatepay-for-woocommerce' ),
			'network'       => __( 'Connection failed. Please try again.', 'abacatepay-for-woocommerce' ),
			'declined'      => __( 'Your card was declined. Please try another card.', 'abacatepay-for-woocommerce' ),
			'generic'       => __( 'Could not process the card. Please try again.', 'abacatepay-for-woocommerce' ),
			'cardholderTax' => __( 'Cardholder CPF or CNPJ', 'abacatepay-for-woocommerce' ),
			'installments'  => __( 'Installments', 'abacatepay-for-woocommerce' ),
		);
	}

	/**
	 * Installment choices for an amount, as "count => label".
	 *
	 * @param int $amount Amount in cents.
	 * @param int $max    Highest count allowed in the settings.
	 * @return array<int, string>
	 */
	public static function get_installment_options( $amount, $max ) {
		$max     = max( 1, min( 12, (int) $max, intdiv( max( 0, (int) $amount ), self::MIN_INSTALLMENT_AMOUNT ) ) );
		$options = array();

		for ( $count = 1; $count <= $max; $count++ ) {
			$value = html_entity_decode( wp_strip_all_tags( wc_price( $amount / 100 / $count ) ), ENT_QUOTES, 'UTF-8' );

			$options[ $count ] = 1 === $count
				/* translators: %s: order total */
				? sprintf( __( '1x of %s', 'abacatepay-for-woocommerce' ), $value )
				/* translators: 1: number of installments, 2: installment amount */
				: sprintf( __( '%1$dx of %2$s interest-free', 'abacatepay-for-woocommerce' ), $count, $value );
		}

		return $options;
	}

	/**
	 * Whether the card form must ask for CPF/CNPJ, which the classic checkout only has with the
	 * "Brazilian Market on WooCommerce" plugin.
	 *
	 * @return bool
	 */
	public static function needs_tax_id_field() {
		if ( ! class_exists( 'Extra_Checkout_Fields_For_Brazil' ) ) {
			return true;
		}

		$settings = get_option( 'wcbcf_settings', array() );

		return ! is_array( $settings ) || empty( $settings['person_type'] );
	}

	/**
	 * Order being paid on the "Pay for order" page, if any.
	 *
	 * @return WC_Order|null
	 */
	private function get_pay_page_order() {
		if ( ! is_checkout_pay_page() ) {
			return null;
		}

		$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Charges the card token sent by the form.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$this->save_posted_tax_id( $order );

		$missing = self::get_missing_customer_fields( $order );

		if ( $missing ) {
			/* translators: %s: comma-separated list of fields, e.g. "CPF/CNPJ, phone" */
			wc_add_notice( sprintf( __( 'To pay by card, please fill in: %s.', 'abacatepay-for-woocommerce' ), implode( ', ', $missing ) ), 'error' );

			return array( 'result' => 'failure' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before process_payment().
		$token        = isset( $_POST['abacatepay_card_token'] ) ? sanitize_text_field( wp_unslash( $_POST['abacatepay_card_token'] ) ) : '';
		$installments = isset( $_POST['abacatepay_card_installments'] ) ? absint( $_POST['abacatepay_card_installments'] ) : 1;
		// phpcs:enable

		if ( 1 !== preg_match( '/^ctok_[A-Za-z0-9_-]+$/', $token ) ) {
			wc_add_notice( __( 'Could not read the card. Please check the card details and try again.', 'abacatepay-for-woocommerce' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$amount = self::to_cents( $order->get_total() );

		if ( $amount < self::MIN_AMOUNT ) {
			wc_add_notice( __( 'Card payments must be at least R$ 1.00.', 'abacatepay-for-woocommerce' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$allowed = self::get_installment_options( $amount, (int) $this->get_option( 'max_installments', 1 ) );

		if ( ! isset( $allowed[ $installments ] ) ) {
			wc_add_notice( __( 'This number of installments is not available for this order.', 'abacatepay-for-woocommerce' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$data = array(
			'amount'   => $amount,
			'token'    => $token,
			'customer' => self::get_customer_data( $order ),
			'metadata' => self::get_charge_metadata( $order ),
		);

		if ( $installments > 1 ) {
			$data['installmentCount'] = $installments;
		}

		// Set before the request so the webhook and the scheduled check can still match a charge whose response is lost.
		$order->update_meta_data( self::META_LOOKUP, 'yes' );
		$order->save();

		$charge = $this->get_api()->create_card_charge( apply_filters( 'abacatepay_wc_card_charge_data', $data, $order ) );

		// 4xx means the charge was refused. A 5xx, like a timeout, says nothing about the card: handled below.
		if ( is_wp_error( $charge ) && 'abacatepay_api_error' === $charge->get_error_code() && self::get_error_status( $charge ) < 500 ) {
			$order->delete_meta_data( self::META_LOOKUP );
			$order->add_order_note(
				/* translators: %s: error message returned by AbacatePay */
				sprintf( __( 'AbacatePay refused the card charge: %s', 'abacatepay-for-woocommerce' ), $charge->get_error_message() )
			);
			$order->save();

			wc_add_notice( $this->get_decline_message( $charge ), 'error' );

			return array( 'result' => 'failure' );
		}

		if ( is_wp_error( $charge ) || empty( $charge['id'] ) ) {
			return $this->handle_unknown_result( $order );
		}

		$this->record_charge( $order, $charge );

		$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';

		if ( in_array( $status, $this->get_paid_charge_statuses(), true ) ) {
			$this->mark_order_paid( $order, isset( $charge['receiptUrl'] ) && is_string( $charge['receiptUrl'] ) ? $charge['receiptUrl'] : '' );
		} elseif ( 'PENDING' === $status ) {
			$order->update_status( 'on-hold', __( 'Card payment awaiting the bank response. The order will be updated by AbacatePay.', 'abacatepay-for-woocommerce' ) );
			$this->schedule_check( $order, time() + 10 * MINUTE_IN_SECONDS );
		} else {
			$order->update_status( 'failed', __( 'Card payment declined.', 'abacatepay-for-woocommerce' ) );
			wc_add_notice( self::get_script_messages()['declined'], 'error' );

			return array( 'result' => 'failure' );
		}

		return $this->success( $order );
	}

	/**
	 * No answer from AbacatePay (timeout, connection error, invalid body). The card may have been charged,
	 * so charging again could bill the customer twice: hold the order and look the charge up later.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function handle_unknown_result( WC_Order $order ) {
		$order->update_status( 'on-hold', __( 'AbacatePay did not answer the card charge request, so the card may have been charged. The order will be updated automatically once the charge is found. Do not charge the customer again.', 'abacatepay-for-woocommerce' ) );
		$this->schedule_check( $order, time() + 2 * MINUTE_IN_SECONDS );

		wc_add_notice( __( 'We are confirming your card payment. You will receive an email once it is approved.', 'abacatepay-for-woocommerce' ), 'notice' );

		return $this->success( $order );
	}

	/**
	 * Empties the cart and sends the customer to the "order received" page.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function success( WC_Order $order ) {
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * HTTP status of an API error, 0 when unknown.
	 *
	 * @param WP_Error $error API error.
	 * @return int
	 */
	private static function get_error_status( WP_Error $error ) {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Customer-facing message for a charge refused by AbacatePay.
	 *
	 * @param WP_Error $error API error.
	 * @return string
	 */
	private function get_decline_message( WP_Error $error ) {
		$message = mb_strtolower( $error->get_error_message() );

		if ( false !== strpos( $message, 'não foi possível processar o pagamento' ) ) {
			return self::get_script_messages()['declined'];
		}

		if ( false !== strpos( $message, 'card token' ) || false !== strpos( $message, 'tokenized amount' ) ) {
			return __( 'Could not confirm the card. Please try again.', 'abacatepay-for-woocommerce' );
		}

		if ( false !== strpos( $message, 'taxid' ) ) {
			return __( 'Invalid CPF/CNPJ. Please check it and try again.', 'abacatepay-for-woocommerce' );
		}

		if ( false !== strpos( $message, 'installments' ) ) {
			return __( 'This number of installments is not available for this order.', 'abacatepay-for-woocommerce' );
		}

		return $this->get_customer_error_message( $error );
	}

	/**
	 * Stores the CPF/CNPJ typed in the card form when the checkout has no such field.
	 *
	 * @param WC_Order $order Order.
	 */
	private function save_posted_tax_id( WC_Order $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce before process_payment().
		$posted = isset( $_POST['abacatepay_card_tax_id'] ) ? sanitize_text_field( wp_unslash( $_POST['abacatepay_card_tax_id'] ) ) : '';
		$tax_id = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '', $posted ) );

		if ( '' === $tax_id || '' !== self::get_tax_id( $order ) ) {
			return;
		}

		$is_cpf = 11 === strlen( $tax_id );

		$order->update_meta_data( $is_cpf ? '_billing_cpf' : '_billing_cnpj', $tax_id );
		$order->update_meta_data( '_billing_persontype', $is_cpf ? '1' : '2' );
		$order->save();
	}

	/**
	 * Stores a charge returned by the API (or by the webhook) and shows the card on the order.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $charge Charge.
	 */
	private function record_charge( WC_Order $order, array $charge ) {
		$amount       = isset( $charge['amount'] ) ? (int) $charge['amount'] : self::to_cents( $order->get_total() );
		$brand        = isset( $charge['brand'] ) ? sanitize_text_field( (string) $charge['brand'] ) : '';
		$last_four    = isset( $charge['lastFour'] ) ? preg_replace( '/\D+/', '', (string) $charge['lastFour'] ) : '';
		$installments = isset( $charge['installmentCount'] ) ? max( 1, (int) $charge['installmentCount'] ) : 1;

		$this->store_charge( $order, $charge, $amount );
		$order->delete_meta_data( self::META_LOOKUP );

		if ( '' !== $brand || '' !== $last_four ) {
			$order->update_meta_data( self::META_BRAND, $brand );
			$order->update_meta_data( self::META_LAST_FOUR, $last_four );
			$order->update_meta_data( self::META_INSTALLMENTS, $installments );
			$order->set_payment_method_title( self::describe_card( $this->get_title(), $brand, $last_four, $installments ) );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: charge ID, 2: charge status, 3: card description */
				__( 'AbacatePay card charge %1$s (%2$s): %3$s.', 'abacatepay-for-woocommerce' ),
				(string) $charge['id'],
				isset( $charge['status'] ) ? (string) $charge['status'] : '-',
				self::describe_card( __( 'card', 'abacatepay-for-woocommerce' ), $brand, $last_four, $installments )
			)
		);
		$order->save();
	}

	/**
	 * "Credit card (VISA •••• 4242, 3x)".
	 *
	 * @param string $label        Prefix.
	 * @param string $brand        Card brand.
	 * @param string $last_four    Last four digits.
	 * @param int    $installments Number of installments.
	 * @return string
	 */
	private static function describe_card( $label, $brand, $last_four, $installments ) {
		$details = trim( $brand . ( '' !== $last_four ? ' •••• ' . $last_four : '' ) );

		if ( $installments > 1 ) {
			$details .= ( '' !== $details ? ', ' : '' ) . $installments . 'x';
		}

		return '' !== $details ? "{$label} ({$details})" : $label;
	}

	/**
	 * Card charges arrive as APPROVED, and later move to PAID when the acquirer settles them.
	 *
	 * @return string[]
	 */
	protected function get_paid_charge_statuses() {
		return array( 'APPROVED', 'PAID' );
	}

	/**
	 * Order note added when the payment is confirmed.
	 *
	 * @return string
	 */
	protected function get_payment_confirmed_note() {
		return __( 'Card payment approved by AbacatePay.', 'abacatepay-for-woocommerce' );
	}

	/**
	 * Confirms the payment and drops the pending check.
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
	 * Records the charge first when its creation response was lost, then applies the event.
	 *
	 * @param string   $event  Event name.
	 * @param array    $charge data.transparent.
	 * @param string   $reason data.reason.
	 * @param WC_Order $order  Order.
	 */
	public function handle_webhook_event( $event, array $charge, $reason, WC_Order $order ) {
		if ( 'yes' === $order->get_meta( self::META_LOOKUP ) && ! empty( $charge['id'] ) ) {
			// The event has no brand/last four digits in data.transparent; the full charge does.
			$full = $this->get_api()->get_charge( (string) $charge['id'] );

			$this->record_charge( $order, is_array( $full ) && ! is_wp_error( $full ) ? array_merge( $charge, $full ) : $charge );
		}

		parent::handle_webhook_event( $event, $charge, $reason, $order );
	}

	/**
	 * Filter for AbacatePay_WC_Gateway::order_has_charge(): while a charge result is unknown, accept the card
	 * charge AbacatePay reports for this order. The webhook already matched the order by its metadata and
	 * checked the secret and signature.
	 *
	 * @param bool     $has_charge Whether the charge is already recorded.
	 * @param WC_Order $order      Order.
	 * @param string   $charge_id  Charge ID.
	 * @return bool
	 */
	public static function accept_unrecorded_charge( $has_charge, $order, $charge_id ) {
		if ( $has_charge || ! $order instanceof WC_Order ) {
			return (bool) $has_charge;
		}

		return self::ID === $order->get_payment_method()
			&& 'yes' === $order->get_meta( self::META_LOOKUP )
			&& 0 === strpos( (string) $charge_id, 'card_' );
	}

	/**
	 * Asks AbacatePay for the charge and updates the order. When the charge ID is unknown (lost response),
	 * finds it among the day's card charges by the order ID in its metadata.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error Charge status, or an empty string when no charge exists for the order.
	 */
	public function sync_charge_status( WC_Order $order ) {
		$charge_id = (string) $order->get_meta( self::META_CHARGE_ID );

		if ( '' !== $charge_id ) {
			$charge = $this->get_api()->get_charge( $charge_id );
		} elseif ( 'yes' === $order->get_meta( self::META_LOOKUP ) ) {
			$charge = $this->find_charge( $order );

			if ( is_array( $charge ) ) {
				$this->record_charge( $order, $charge );
			}
		} else {
			return new WP_Error( 'abacatepay_no_charge', __( 'This order has no AbacatePay charge.', 'abacatepay-for-woocommerce' ) );
		}

		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		if ( null === $charge ) {
			return '';
		}

		$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';

		if ( in_array( $status, $this->get_paid_charge_statuses(), true ) ) {
			$this->mark_order_paid( $order, isset( $charge['receiptUrl'] ) && is_string( $charge['receiptUrl'] ) ? $charge['receiptUrl'] : '' );
		} elseif ( 'FAILED' === $status && ! self::is_order_paid( $order ) ) {
			$order->update_status( 'failed', __( 'Card payment declined.', 'abacatepay-for-woocommerce' ) );
		}

		return $status;
	}

	/**
	 * Card charge created for the order, looked up by the metadata sent with it.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null|WP_Error
	 */
	private function find_charge( WC_Order $order ) {
		$created = $order->get_date_created();
		$day     = $created ? $created->setTimezone( new DateTimeZone( 'America/Sao_Paulo' ) )->format( 'Y-m-d' ) : gmdate( 'Y-m-d' );

		// AbacatePay reads the dates in the São Paulo timezone.
		$charges = $this->get_api()->list_charges(
			array(
				'method'    => 'CARD',
				'startDate' => $day,
				'limit'     => 100,
			)
		);

		if ( is_wp_error( $charges ) ) {
			return $charges;
		}

		foreach ( $charges as $charge ) {
			if ( is_array( $charge ) && isset( $charge['metadata']['wcOrderId'] ) && (string) $order->get_id() === (string) $charge['metadata']['wcOrderId'] ) {
				return $charge;
			}
		}

		return null;
	}

	/**
	 * Scheduled check for pending charges and charges whose result is unknown.
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

		if ( ! is_wp_error( $status ) && '' !== $status && 'PENDING' !== $status ) {
			return;
		}

		if ( $checks < self::MAX_CHECKS ) {
			$this->schedule_check( $order, time() + $checks * 10 * MINUTE_IN_SECONDS );

			return;
		}

		// Nothing was charged: let the customer pay again.
		if ( '' === $status && 'yes' === $order->get_meta( self::META_LOOKUP ) ) {
			$order->delete_meta_data( self::META_LOOKUP );
			$order->update_status( 'failed', __( 'No card charge was found on AbacatePay for this order, so the customer was not charged.', 'abacatepay-for-woocommerce' ) );
		}
	}

	/**
	 * Schedules the next status check.
	 *
	 * @param WC_Order $order     Order.
	 * @param int      $timestamp When to run.
	 */
	private function schedule_check( WC_Order $order, $timestamp ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
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
}
