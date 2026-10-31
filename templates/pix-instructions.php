<?php
/**
 * Pix payment instructions on the "order received" and "view order" pages.
 *
 * Override by copying to yourtheme/abacatepay-for-woocommerce/pix-instructions.php.
 *
 * @package AbacatePay_For_WooCommerce
 *
 * @var WC_Order $order
 * @var bool     $paid
 * @var bool     $expired
 * @var string   $br_code
 * @var string   $qr_code_url
 * @var string   $expires_at  ISO 8601.
 * @var bool     $dev_mode
 * @var string   $receipt_url
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="abacatepay-pix">
	<?php if ( $paid ) : ?>

		<div class="abacatepay-pix__result abacatepay-pix__result--paid">
			<h2><?php esc_html_e( 'Pix payment confirmed', 'abacatepay-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Thank you! We received your payment and your order is being processed.', 'abacatepay-for-woocommerce' ); ?></p>
			<?php if ( '' !== $receipt_url ) : ?>
				<p><a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View receipt', 'abacatepay-for-woocommerce' ); ?></a></p>
			<?php endif; ?>
		</div>

	<?php elseif ( $expired ) : ?>

		<div class="abacatepay-pix__result abacatepay-pix__result--expired">
			<h2><?php esc_html_e( 'This Pix has expired', 'abacatepay-for-woocommerce' ); ?></h2>
			<?php if ( $order->needs_payment() ) : ?>
				<p><a class="button" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Generate a new Pix', 'abacatepay-for-woocommerce' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'The payment time is over. Please place a new order or contact the store.', 'abacatepay-for-woocommerce' ); ?></p>
			<?php endif; ?>
		</div>

	<?php else : ?>

		<div
			class="abacatepay-pix__pending"
			data-expires-at="<?php echo esc_attr( $expires_at ); ?>"
			data-expired-message="<?php esc_attr_e( 'This Pix has expired. Reload the page to see your options.', 'abacatepay-for-woocommerce' ); ?>"
		>
			<h2><?php esc_html_e( 'Pay with Pix', 'abacatepay-for-woocommerce' ); ?></h2>

			<?php if ( $dev_mode ) : ?>
				<p class="abacatepay-pix__dev-mode"><?php esc_html_e( 'Test charge (AbacatePay Dev mode): no real money will be charged.', 'abacatepay-for-woocommerce' ); ?></p>
			<?php endif; ?>

			<ol class="abacatepay-pix__steps">
				<li><?php esc_html_e( 'Open your bank app and choose to pay with Pix.', 'abacatepay-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'Scan the QR code or paste the Pix code below.', 'abacatepay-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'Confirm the payment. We will email you as soon as it is confirmed.', 'abacatepay-for-woocommerce' ); ?></li>
			</ol>

			<div class="abacatepay-pix__body">
				<img
					class="abacatepay-pix__qr-code"
					src="<?php echo esc_url( $qr_code_url ); ?>"
					width="240"
					height="240"
					alt="<?php esc_attr_e( 'Pix QR code', 'abacatepay-for-woocommerce' ); ?>"
				/>

				<div class="abacatepay-pix__copy">
					<label for="abacatepay-pix-code"><?php esc_html_e( 'Pix Copy and Paste', 'abacatepay-for-woocommerce' ); ?></label>
					<textarea id="abacatepay-pix-code" class="abacatepay-pix__code" rows="4" readonly><?php echo esc_textarea( $br_code ); ?></textarea>
					<button
						type="button"
						class="button abacatepay-pix__copy-button"
						data-copied-label="<?php esc_attr_e( 'Copied!', 'abacatepay-for-woocommerce' ); ?>"
					><?php esc_html_e( 'Copy Pix code', 'abacatepay-for-woocommerce' ); ?></button>

					<?php if ( '' !== $expires_at ) : ?>
						<p class="abacatepay-pix__expires">
							<?php
							printf(
								/* translators: %s: expiration date and time */
								esc_html__( 'Pay by %s.', 'abacatepay-for-woocommerce' ),
								'<strong>' . esc_html( AbacatePay_WC_Order_Details::format_datetime( $expires_at ) ) . '</strong>'
							);
							?>
							<span class="abacatepay-pix__countdown" aria-hidden="true"></span>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<p class="abacatepay-pix__status" role="status" aria-live="polite"><?php esc_html_e( 'Waiting for payment…', 'abacatepay-for-woocommerce' ); ?></p>
		</div>

	<?php endif; ?>
</section>
