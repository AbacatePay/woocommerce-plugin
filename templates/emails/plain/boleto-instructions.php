<?php
/**
 * Boleto payment instructions in plain text emails.
 *
 * Override by copying to yourtheme/abacatepay-for-woocommerce/emails/plain/boleto-instructions.php.
 *
 * @package AbacatePay_For_WooCommerce
 *
 * @var WC_Order $order
 * @var string   $bar_code    Linha digitável.
 * @var string   $boleto_url  Page to view and print the boleto.
 * @var string   $due_date    Formatted due date.
 * @var string   $pix_code    Alternative Pix copy and paste code.
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( wc_strtoupper( __( 'Pay with boleto', 'abacatepay-for-woocommerce' ) ) ) . "\n\n";

if ( '' !== $due_date ) {
	/* translators: %s: due date */
	echo esc_html( sprintf( __( 'Due date: %s', 'abacatepay-for-woocommerce' ), $due_date ) ) . "\n\n";
}

echo esc_html__( 'Boleto number (linha digitável)', 'abacatepay-for-woocommerce' ) . ":\n";
echo esc_html( $bar_code ) . "\n\n";

if ( '' !== $boleto_url ) {
	echo esc_html__( 'View and print boleto', 'abacatepay-for-woocommerce' ) . ': ' . esc_url_raw( $boleto_url ) . "\n\n";
}

if ( '' !== $pix_code ) {
	echo esc_html__( 'Paste this code in your bank app (Pix Copy and Paste):', 'abacatepay-for-woocommerce' ) . "\n\n";
	echo esc_html( $pix_code ) . "\n\n";
}

echo "==========\n\n";
