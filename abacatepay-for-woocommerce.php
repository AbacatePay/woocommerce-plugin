<?php
/**
 * Plugin Name: AbacatePay for WooCommerce
 * Plugin URI: https://docs.abacatepay.com
 * Description: Accept Pix payments with AbacatePay: QR Code and Pix Copy and Paste right on your store, with automatic order confirmation.
 * Version: 1.0.0
 * Author: AbacatePay
 * Author URI: https://www.abacatepay.com
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.2
 * Text Domain: abacatepay-for-woocommerce
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'ABACATEPAY_WC_VERSION', '1.0.0' );
define( 'ABACATEPAY_WC_FILE', __FILE__ );
define( 'ABACATEPAY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ABACATEPAY_WC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc.php';

add_action( 'plugins_loaded', array( 'AbacatePay_WC', 'instance' ) );
