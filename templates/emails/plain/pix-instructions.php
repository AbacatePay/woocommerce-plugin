<?php
/**
 * Pix payment instructions in plain text emails.
 *
 * Override by copying to yourtheme/abacatepay-for-woocommerce/emails/plain/pix-instructions.php.
 *
 * @package AbacatePay_For_WooCommerce
 *
 * @var WC_Order $order
 * @var string   $br_code
 * @var string   $qr_code_url
 * @var string   $expires_at  ISO 8601.
 * @var string   $order_url
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wc_strtoupper( __( 'Pay with Pix', 'abacatepay-for-woocommerce' ) ) ) . "\n\n";

echo esc_html__( 'Paste this code in your bank app (Pix Copy and Paste):', 'abacatepay-for-woocommerce' ) . "\n\n";

echo esc_html( $br_code ) . "\n\n";

if ( '' !== $expires_at ) {
	/* translators: %s: expiration date and time */
	echo esc_html( sprintf( __( 'Pay by %s.', 'abacatepay-for-woocommerce' ), AbacatePay_WC_Order_Details::format_datetime( $expires_at ) ) ) . "\n\n";
}

echo "==========\n\n";
