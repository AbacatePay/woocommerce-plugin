<?php
/**
 * Cart and Checkout blocks support.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the Pix gateway in the block-based checkout. Payment is processed by the same
 * AbacatePay_WC_Pix_Gateway::process_payment() used by the classic checkout.
 */
final class AbacatePay_WC_Pix_Blocks_Integration extends AbstractPaymentMethodType {

	/**
	 * Payment method name, same as the gateway ID.
	 *
	 * @var string
	 */
	protected $name = AbacatePay_WC_Pix_Gateway::ID;

	/**
	 * Loads the gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . AbacatePay_WC_Pix_Gateway::ID . '_settings', array() );
	}

	/**
	 * Whether the method should be offered. Currency and availability are checked again by the gateway.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled' ) && '' !== trim( (string) $this->get_setting( 'api_key' ) );
	}

	/**
	 * Script that registers the method in the checkout block.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'abacatepay-wc-pix-blocks',
			ABACATEPAY_WC_URL . 'assets/js/pix-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			ABACATEPAY_WC_VERSION,
			true
		);

		return array( 'abacatepay-wc-pix-blocks' );
	}

	/**
	 * Data available to the script as wc.wcSettings.getSetting( 'abacatepay_pix_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Pix', 'abacatepay-for-woocommerce' ) ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array( 'products' ),
		);
	}
}
