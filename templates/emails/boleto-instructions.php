<?php
/**
 * Boleto payment instructions in HTML emails.
 *
 * Override by copying to yourtheme/abacatepay-for-woocommerce/emails/boleto-instructions.php.
 *
 * @package AbacatePay_For_WooCommerce
 *
 * @var WC_Order $order
 * @var string   $bar_code    Linha digitável.
 * @var string   $boleto_url  Page to view and print the boleto.
 * @var string   $due_date    Formatted due date.
 * @var string   $pix_code    Alternative Pix copy and paste code.
 * @var string   $qr_code_url Alternative Pix QR code image.
 * @var bool     $late_fees
 */

defined( 'ABSPATH' ) || exit;
?>
<div style="margin-bottom: 40px;">
	<h2><?php esc_html_e( 'Pay with boleto', 'abacatepay-for-woocommerce' ); ?></h2>

	<?php if ( '' !== $due_date ) : ?>
		<p>
			<?php
			printf(
				/* translators: %s: due date */
				esc_html__( 'Due date: %s', 'abacatepay-for-woocommerce' ),
				'<strong>' . esc_html( $due_date ) . '</strong>'
			);
			?>
		</p>
	<?php endif; ?>

	<p><strong><?php esc_html_e( 'Boleto number (linha digitável)', 'abacatepay-for-woocommerce' ); ?></strong></p>
	<p style="word-break: break-all; font-family: monospace; font-size: 14px; padding: 12px; background: #f6f6f6; border-radius: 4px;"><?php echo esc_html( $bar_code ); ?></p>

	<?php if ( '' !== $boleto_url ) : ?>
		<p><a href="<?php echo esc_url( $boleto_url ); ?>"><?php esc_html_e( 'View and print boleto', 'abacatepay-for-woocommerce' ); ?></a></p>
	<?php endif; ?>

	<?php if ( '' !== $pix_code ) : ?>
		<h3><?php esc_html_e( 'Prefer Pix? Pay now and get instant confirmation', 'abacatepay-for-woocommerce' ); ?></h3>

		<?php if ( '' !== $qr_code_url ) : ?>
			<p style="text-align: center;">
				<img src="<?php echo esc_url( $qr_code_url ); ?>" width="200" height="200" alt="<?php esc_attr_e( 'Pix QR code', 'abacatepay-for-woocommerce' ); ?>" style="display: inline-block; margin: 0 auto;" />
			</p>
		<?php endif; ?>

		<p><strong><?php esc_html_e( 'Pix Copy and Paste', 'abacatepay-for-woocommerce' ); ?></strong></p>
		<p style="word-break: break-all; font-family: monospace; font-size: 13px; padding: 12px; background: #f6f6f6; border-radius: 4px;"><?php echo esc_html( $pix_code ); ?></p>
	<?php endif; ?>
</div>
