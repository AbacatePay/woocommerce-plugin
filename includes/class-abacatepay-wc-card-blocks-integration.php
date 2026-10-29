<?php
/**
 * Credit card in the Cart and Checkout blocks.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the card gateway in the block-based checkout. The block tokenizes the card with abacatepay.js
 * and sends the token as payment data to AbacatePay_WC_Card_Gateway::process_payment().
 */
final class AbacatePay_WC_Card_Blocks_Integration extends AbstractPaymentMethodType {

	/**
	 * Payment method name, same as the gateway ID.
	 *
	 * @var string
	 */
	protected $name = AbacatePay_WC_Card_Gateway::ID;

	/**
	 * Loads the gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . AbacatePay_WC_Card_Gateway::ID . '_settings', array() );
	}

	/**
	 * Whether the method should be offered. Currency and the API key are checked again by the gateway.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled' ) && '' !== trim( (string) $this->get_setting( 'public_key' ) );
	}

	/**
	 * Scripts: abacatepay.js and the block payment method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_enqueue_style( 'abacatepay-wc-card', ABACATEPAY_WC_URL . 'assets/css/card.css', array(), ABACATEPAY_WC_VERSION );
		wp_register_script( 'abacatepay-js', AbacatePay_WC_Card_Gateway::SCRIPT_URL, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Versioned by AbacatePay.
		wp_register_script(
			'abacatepay-wc-card-blocks',
			ABACATEPAY_WC_URL . 'assets/js/card-blocks.js',
			array( 'abacatepay-js', 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			ABACATEPAY_WC_VERSION,
			true
		);

		return array( 'abacatepay-wc-card-blocks' );
	}

	/**
	 * Data available to the script as wc.wcSettings.getSetting( 'abacatepay_card_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'                => $this->get_setting( 'title', __( 'Credit card', 'abacatepay-for-woocommerce' ) ),
			'description'          => $this->get_setting( 'description' ),
			'publicKey'            => trim( (string) $this->get_setting( 'public_key' ) ),
			'maxInstallments'      => max( 1, min( 12, (int) $this->get_setting( 'max_installments', 1 ) ) ),
			'minInstallmentAmount' => AbacatePay_WC_Card_Gateway::MIN_INSTALLMENT_AMOUNT,
			'i18n'                 => array_merge(
				AbacatePay_WC_Card_Gateway::get_script_messages(),
				array(
					/* translators: %s: order total */
					'singleInstallment' => __( '1x of %s', 'abacatepay-for-woocommerce' ),
					/* translators: 1: number of installments, 2: installment amount */
					'installment'       => __( '%1$dx of %2$s interest-free', 'abacatepay-for-woocommerce' ),
				)
			),
			'supports'             => array( 'products' ),
		);
	}
}
