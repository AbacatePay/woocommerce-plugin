<?php
/**
 * Pix payment instructions in HTML emails.
 *
 * Override by copying to yourtheme/abacatepay-for-woocommerce/emails/pix-instructions.php.
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
?>
<div style="margin-bottom: 40px;">
	<h2><?php esc_html_e( 'Pay with Pix', 'abacatepay-for-woocommerce' ); ?></h2>

	<p><?php esc_html_e( 'Scan the QR code with your bank app or use the Pix Copy and Paste code below.', 'abacatepay-for-woocommerce' ); ?></p>

	<p style="text-align: center;">
		<img src="<?php echo esc_url( $qr_code_url ); ?>" width="220" height="220" alt="<?php esc_attr_e( 'Pix QR code', 'abacatepay-for-woocommerce' ); ?>" style="display: inline-block; margin: 0 auto;" />
	</p>

	<p><strong><?php esc_html_e( 'Pix Copy and Paste', 'abacatepay-for-woocommerce' ); ?></strong></p>
	<p style="word-break: break-all; font-family: monospace; font-size: 13px; padding: 12px; background: #f6f6f6; border-radius: 4px;"><?php echo esc_html( $br_code ); ?></p>

	<?php if ( '' !== $expires_at ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: expiration date and time */
				esc_html__( 'Pay by %s.', 'abacatepay-for-woocommerce' ),
				'<strong>' . esc_html( AbacatePay_WC_Order_Details::format_datetime( $expires_at ) ) . '</strong>'
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $order->get_customer_id() ) : ?>
		<p><a href="<?php echo esc_url( $order_url ); ?>"><?php esc_html_e( 'View your order', 'abacatepay-for-woocommerce' ); ?></a></p>
	<?php endif; ?>
</div>
