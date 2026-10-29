<?php
/**
 * Logging helper.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce > Status > Logs under the "abacatepay-for-woocommerce" source.
 */
final class AbacatePay_WC_Logger {

	const SOURCE = 'abacatepay-for-woocommerce';

	/**
	 * Logs only when debug mode is enabled in the gateway settings.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data, encoded as JSON.
	 */
	public static function debug( $message, array $context = array() ) {
		if ( self::is_debug_enabled() ) {
			self::log( 'debug', $message, $context );
		}
	}

	/**
	 * Always logs.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data, encoded as JSON.
	 */
	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Always logs.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data, encoded as JSON.
	 */
	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Sends the entry to the WooCommerce logger.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 */
	private static function log( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( $context ) {
			$message .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Whether the "Debug log" setting is on.
	 *
	 * @return bool
	 */
	private static function is_debug_enabled() {
		$settings = get_option( 'woocommerce_' . AbacatePay_WC_Pix_Gateway::ID . '_settings', array() );

		return is_array( $settings ) && isset( $settings['debug'] ) && 'yes' === $settings['debug'];
	}
}
