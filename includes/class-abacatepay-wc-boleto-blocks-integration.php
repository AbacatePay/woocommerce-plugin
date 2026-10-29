<?php
/**
 * Cart and Checkout blocks support for boleto.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the boleto gateway in the block-based checkout. Payment is processed by the same
 * AbacatePay_WC_Boleto_Gateway::process_payment() used by the classic checkout.
 */
final class AbacatePay_WC_Boleto_Blocks_Integration extends AbstractPaymentMethodType {

	/**
	 * Payment method name, same as the gateway ID.
	 *
	 * @var string
	 */
	protected $name = AbacatePay_WC_Boleto_Gateway::ID;

	/**
	 * Loads the gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . AbacatePay_WC_Boleto_Gateway::ID . '_settings', array() );
	}

	/**
	 * Whether the method should be offered. Currency and availability are checked again by the gateway.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled' ) && '' !== trim( AbacatePay_WC_Gateway::get_shared_setting( 'api_key' ) );
	}

	/**
	 * Script that registers the method in the checkout block.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'abacatepay-wc-boleto-blocks',
			ABACATEPAY_WC_URL . 'assets/js/boleto-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			ABACATEPAY_WC_VERSION,
			true
		);

		return array( 'abacatepay-wc-boleto-blocks' );
	}

	/**
	 * Data available to the script as wc.wcSettings.getSetting( 'abacatepay_boleto_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Boleto', 'abacatepay-for-woocommerce' ) ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array( 'products' ),
		);
	}
}
