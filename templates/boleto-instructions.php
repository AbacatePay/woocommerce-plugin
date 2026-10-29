<?php
/**
 * Boleto payment instructions on the "order received" and "view order" pages.
 *
 * Override by copying to yourtheme/abacatepay-for-woocommerce/boleto-instructions.php.
 *
 * @package AbacatePay_For_WooCommerce
 *
 * @var WC_Order $order
 * @var bool     $paid
 * @var bool     $expired
 * @var string   $bar_code    Linha digitável.
 * @var string   $boleto_url  Page to view and print the boleto.
 * @var string   $due_date    Formatted due date.
 * @var string   $pix_code    Alternative Pix copy and paste code.
 * @var string   $qr_code_url Alternative Pix QR code image.
 * @var string   $status_url
 * @var bool     $late_fees   Whether interest or a fine applies after the due date.
 * @var bool     $dev_mode
 * @var string   $receipt_url
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="abacatepay-boleto">
	<?php if ( $paid ) : ?>

		<div class="abacatepay-boleto__result abacatepay-boleto__result--paid">
			<h2><?php esc_html_e( 'Payment confirmed', 'abacatepay-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Thank you! We received your payment and your order is being processed.', 'abacatepay-for-woocommerce' ); ?></p>
			<?php if ( '' !== $receipt_url ) : ?>
				<p><a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View receipt', 'abacatepay-for-woocommerce' ); ?></a></p>
			<?php endif; ?>
		</div>

	<?php elseif ( $expired ) : ?>

		<div class="abacatepay-boleto__result abacatepay-boleto__result--expired">
			<h2><?php esc_html_e( 'This boleto has expired', 'abacatepay-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'The payment time is over. Please place a new order or contact the store.', 'abacatepay-for-woocommerce' ); ?></p>
		</div>

	<?php else : ?>

		<div
			class="abacatepay-boleto__pending"
			data-status-url="<?php echo esc_url( $status_url ); ?>"
			data-paid-message="<?php esc_attr_e( 'Payment confirmed! Updating the page…', 'abacatepay-for-woocommerce' ); ?>"
		>
			<h2><?php esc_html_e( 'Pay with boleto', 'abacatepay-for-woocommerce' ); ?></h2>

			<?php if ( $dev_mode ) : ?>
				<p class="abacatepay-boleto__dev-mode"><?php esc_html_e( 'Test charge (AbacatePay Dev mode): no real money will be charged.', 'abacatepay-for-woocommerce' ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $due_date ) : ?>
				<p class="abacatepay-boleto__due">
					<?php
					printf(
						/* translators: %s: due date */
						esc_html__( 'Due date: %s', 'abacatepay-for-woocommerce' ),
						'<strong>' . esc_html( $due_date ) . '</strong>'
					);
					?>
					<?php if ( $late_fees ) : ?>
						<br /><small><?php esc_html_e( 'Interest and/or a fine apply after the due date.', 'abacatepay-for-woocommerce' ); ?></small>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<div class="abacatepay-boleto__copy">
				<label for="abacatepay-boleto-code"><?php esc_html_e( 'Boleto number (linha digitável)', 'abacatepay-for-woocommerce' ); ?></label>
				<textarea id="abacatepay-boleto-code" class="abacatepay-boleto__code" rows="2" readonly><?php echo esc_textarea( $bar_code ); ?></textarea>
				<p class="abacatepay-boleto__actions">
					<button
						type="button"
						class="button abacatepay-boleto__copy-button"
						data-target="abacatepay-boleto-code"
						data-copied-label="<?php esc_attr_e( 'Copied!', 'abacatepay-for-woocommerce' ); ?>"
					><?php esc_html_e( 'Copy boleto number', 'abacatepay-for-woocommerce' ); ?></button>
					<?php if ( '' !== $boleto_url ) : ?>
						<a class="button alt" href="<?php echo esc_url( $boleto_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View and print boleto', 'abacatepay-for-woocommerce' ); ?></a>
					<?php endif; ?>
				</p>
				<p class="abacatepay-boleto__note"><?php esc_html_e( 'Boleto payments take up to 3 business days to be confirmed.', 'abacatepay-for-woocommerce' ); ?></p>
			</div>

			<?php if ( '' !== $pix_code ) : ?>
				<div class="abacatepay-boleto__pix">
					<h3><?php esc_html_e( 'Prefer Pix? Pay now and get instant confirmation', 'abacatepay-for-woocommerce' ); ?></h3>
					<div class="abacatepay-boleto__pix-body">
						<?php if ( '' !== $qr_code_url ) : ?>
							<img
								class="abacatepay-boleto__qr-code"
								src="<?php echo esc_url( $qr_code_url ); ?>"
								width="200"
								height="200"
								alt="<?php esc_attr_e( 'Pix QR code', 'abacatepay-for-woocommerce' ); ?>"
							/>
						<?php endif; ?>
						<div class="abacatepay-boleto__copy">
							<label for="abacatepay-boleto-pix-code"><?php esc_html_e( 'Pix Copy and Paste', 'abacatepay-for-woocommerce' ); ?></label>
							<textarea id="abacatepay-boleto-pix-code" class="abacatepay-boleto__code" rows="3" readonly><?php echo esc_textarea( $pix_code ); ?></textarea>
							<button
								type="button"
								class="button abacatepay-boleto__copy-button"
								data-target="abacatepay-boleto-pix-code"
								data-copied-label="<?php esc_attr_e( 'Copied!', 'abacatepay-for-woocommerce' ); ?>"
							><?php esc_html_e( 'Copy Pix code', 'abacatepay-for-woocommerce' ); ?></button>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<p class="abacatepay-boleto__status" role="status" aria-live="polite"><?php esc_html_e( 'Waiting for payment…', 'abacatepay-for-woocommerce' ); ?></p>
		</div>

	<?php endif; ?>
</section>
