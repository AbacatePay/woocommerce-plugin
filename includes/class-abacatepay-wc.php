<?php
/**
 * Plugin bootstrap.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
final class AbacatePay_WC {

	/**
	 * Single instance.
	 *
	 * @var AbacatePay_WC|null
	 */
	private static $instance = null;

	/**
	 * Returns the plugin instance.
	 *
	 * @return AbacatePay_WC
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks. Does nothing when WooCommerce is not loaded.
	 */
	private function __construct() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		$this->includes();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateways' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_block_integrations' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ABACATEPAY_WC_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( AbacatePay_WC_Pix_Gateway::CHECK_HOOK, array( $this, 'run_scheduled_pix_check' ) );
		add_action( AbacatePay_WC_Card_Gateway::CHECK_HOOK, array( $this, 'run_scheduled_card_check' ) );
		add_filter( 'abacatepay_wc_order_has_charge', array( 'AbacatePay_WC_Card_Gateway', 'accept_unrecorded_charge' ), 10, 3 );

		AbacatePay_WC_Webhook::init();
		AbacatePay_WC_Order_Details::init();
	}

	/**
	 * Loads the plugin classes.
	 */
	private function includes() {
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-logger.php';
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-api.php';
		require_once ABACATEPAY_WC_PATH . 'includes/abstract-abacatepay-wc-gateway.php';
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-pix-gateway.php';
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-card-gateway.php';
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-webhook.php';
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-order-details.php';
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'abacatepay-for-woocommerce', false, dirname( plugin_basename( ABACATEPAY_WC_FILE ) ) . '/languages' );
	}

	/**
	 * Adds the gateways to WooCommerce.
	 *
	 * @param string[] $gateways Gateway class names.
	 * @return string[]
	 */
	public function register_gateways( $gateways ) {
		$gateways[] = 'AbacatePay_WC_Pix_Gateway';
		$gateways[] = 'AbacatePay_WC_Card_Gateway';

		return $gateways;
	}

	/**
	 * Registers the gateways in the Cart and Checkout blocks.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Payment method registry.
	 */
	public function register_block_integrations( $registry ) {
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-pix-blocks-integration.php';
		require_once ABACATEPAY_WC_PATH . 'includes/class-abacatepay-wc-card-blocks-integration.php';

		$registry->register( new AbacatePay_WC_Pix_Blocks_Integration() );
		$registry->register( new AbacatePay_WC_Card_Blocks_Integration() );
	}

	/**
	 * Returns a loaded AbacatePay gateway by ID.
	 *
	 * @param string $id Gateway ID.
	 * @return AbacatePay_WC_Gateway|null Null for unknown IDs and gateways from other plugins.
	 */
	public static function gateway( $id ) {
		$gateways = WC()->payment_gateways()->payment_gateways();

		return isset( $gateways[ $id ] ) && $gateways[ $id ] instanceof AbacatePay_WC_Gateway ? $gateways[ $id ] : null;
	}

	/**
	 * Returns the loaded Pix gateway instance.
	 *
	 * @return AbacatePay_WC_Pix_Gateway|null
	 */
	public static function pix_gateway() {
		return self::gateway( AbacatePay_WC_Pix_Gateway::ID );
	}

	/**
	 * Action Scheduler callback, runs after a Pix charge expires.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run_scheduled_pix_check( $order_id ) {
		$gateway = self::pix_gateway();

		if ( $gateway ) {
			$gateway->run_scheduled_check( (int) $order_id );
		}
	}

	/**
	 * Action Scheduler callback for pending card charges and charges whose result is unknown.
	 *
	 * @param int $order_id Order ID.
	 */
	public function run_scheduled_card_check( $order_id ) {
		$gateway = self::gateway( AbacatePay_WC_Card_Gateway::ID );

		if ( $gateway ) {
			$gateway->run_scheduled_check( (int) $order_id );
		}
	}

	/**
	 * Adds a "Settings" link in the plugins list.
	 *
	 * @param string[] $links Default links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . AbacatePay_WC_Pix_Gateway::ID );

		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'abacatepay-for-woocommerce' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Shown when the plugin is active without WooCommerce.
	 */
	public function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'AbacatePay for WooCommerce requires WooCommerce to be installed and active.', 'abacatepay-for-woocommerce' );
		echo '</p></div>';
	}
}
