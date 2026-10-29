<?php
/**
 * AbacatePay webhook receiver.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the transparent.* events sent to /wc-api/abacatepay_wc_webhook.
 *
 * Each delivery is checked twice: the webhookSecret query parameter proves it came from AbacatePay and the
 * X-Webhook-Signature HMAC proves the body was not changed. Handlers are idempotent because AbacatePay
 * retries deliveries with the same event.
 *
 * @see https://docs.abacatepay.com/pages/webhooks/security
 */
final class AbacatePay_WC_Webhook {

	const ENDPOINT = 'abacatepay_wc_webhook';

	/**
	 * Public key AbacatePay uses to sign webhook bodies with HMAC-SHA256.
	 */
	const SIGNATURE_KEY = 't9dXRhHHo3yDEj5pVDYz0frf7q6bMKyMRmxxCPIPp3RCplBfXRxqlC6ZpiWmOqj4L63qEaeUOtrCI8P0VMUgo6iIga2ri9ogaHFs0WIIywSMg0q7RmBfybe1E5XJcfC4IW3alNqym0tXoAKkzvfEjZxV6bE0oG2zJrNNYmUCKZyV0KZ3JS8Votf9EAWWYdiDkMkpbMdPggfh1EqHlVkMiTady6jOR3hyzGEHrIz2Ret0xHKMbiqkr9HS1JhNHDX9';

	/**
	 * Registers the endpoint.
	 */
	public static function init() {
		add_action( 'woocommerce_api_' . self::ENDPOINT, array( self::class, 'handle' ) );
	}

	/**
	 * URL to register in the AbacatePay dashboard.
	 *
	 * @return string
	 */
	public static function url() {
		return WC()->api_request_url( self::ENDPOINT );
	}

	/**
	 * Entry point.
	 */
	public static function handle() {
		$gateway = AbacatePay_WC::pix_gateway();
		$body    = (string) file_get_contents( 'php://input' );

		if ( ! $gateway ) {
			self::respond( 503, 'gateway not loaded' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by the webhook secret.
		$secret = isset( $_GET['webhookSecret'] ) ? (string) wp_unslash( $_GET['webhookSecret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$stored = (string) $gateway->get_option( 'webhook_secret' );

		if ( '' === $stored || ! hash_equals( $stored, $secret ) ) {
			AbacatePay_WC_Logger::warning( 'Webhook rejected: invalid webhookSecret.' );
			self::respond( 401, 'invalid secret' );
		}

		if ( ! self::is_valid_signature( $body ) ) {
			AbacatePay_WC_Logger::warning( 'Webhook rejected: invalid X-Webhook-Signature.' );
			self::respond( 401, 'invalid signature' );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) || empty( $payload['event'] ) ) {
			self::respond( 400, 'invalid payload' );
		}

		$event  = (string) $payload['event'];
		$charge = isset( $payload['data']['transparent'] ) && is_array( $payload['data']['transparent'] ) ? $payload['data']['transparent'] : array();
		$reason = isset( $payload['data']['reason'] ) && is_string( $payload['data']['reason'] ) ? $payload['data']['reason'] : '';

		AbacatePay_WC_Logger::debug(
			'Webhook received',
			array(
				'id'     => isset( $payload['id'] ) ? $payload['id'] : null,
				'event'  => $event,
				'charge' => isset( $charge['id'] ) ? $charge['id'] : null,
				'status' => isset( $charge['status'] ) ? $charge['status'] : null,
			)
		);

		if ( 0 !== strpos( $event, 'transparent.' ) ) {
			self::respond( 200, 'ignored' );
		}

		$order = self::find_order( $charge );

		if ( ! $order ) {
			// Not ours (another integration on the same AbacatePay account, or a deleted order). 2xx stops retries.
			self::respond( 200, 'order not found' );
		}

		switch ( $event ) {
			case 'transparent.completed':
				self::handle_completed( $gateway, $order, $charge );
				break;

			case 'transparent.refunded':
				self::handle_refunded( $order, $reason );
				break;

			case 'transparent.lost':
				self::handle_refunded( $order, __( 'Dispute lost.', 'abacatepay-for-woocommerce' ) );
				break;

			case 'transparent.disputed':
				self::handle_disputed( $order, $charge, $reason );
				break;
		}

		self::respond( 200, 'ok' );
	}

	/**
	 * Payment confirmed.
	 *
	 * @param AbacatePay_WC_Pix_Gateway $gateway Gateway.
	 * @param WC_Order                  $order   Order.
	 * @param array                     $charge  data.transparent.
	 */
	private static function handle_completed( $gateway, WC_Order $order, array $charge ) {
		if ( ! isset( $charge['status'] ) || 'PAID' !== $charge['status'] || AbacatePay_WC_Pix_Gateway::is_order_paid( $order ) ) {
			return;
		}

		$paid  = isset( $charge['amount'] ) ? (int) $charge['amount'] : 0;
		$total = AbacatePay_WC_Pix_Gateway::to_cents( $order->get_total() );

		// Happens when an old QR code is paid after the order total changed.
		if ( $paid !== $total ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: amount paid, 2: order total */
					__( 'Pix of %1$s received, but the order total is %2$s. Check the payment before shipping.', 'abacatepay-for-woocommerce' ),
					wp_strip_all_tags( wc_price( $paid / 100, array( 'currency' => $order->get_currency() ) ) ),
					wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
				)
			);

			return;
		}

		$gateway->mark_order_paid( $order, isset( $charge['receiptUrl'] ) && is_string( $charge['receiptUrl'] ) ? $charge['receiptUrl'] : '' );
	}

	/**
	 * Refund made outside WooCommerce (AbacatePay dashboard, customer portal or lost dispute).
	 * Refunds made from the order screen are already recorded, so there is nothing left to refund.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Refund reason.
	 */
	private static function handle_refunded( WC_Order $order, $reason ) {
		if ( ! AbacatePay_WC_Pix_Gateway::is_order_paid( $order ) ) {
			return;
		}

		$remaining = (float) $order->get_remaining_refund_amount();

		if ( $remaining <= 0 ) {
			return;
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $remaining,
				'reason'         => '' !== $reason ? $reason : __( 'Refunded through AbacatePay.', 'abacatepay-for-woocommerce' ),
				'refund_payment' => false,
				'restock_items'  => true,
			)
		);

		if ( is_wp_error( $refund ) ) {
			AbacatePay_WC_Logger::error( 'Could not record refund for order ' . $order->get_id() . ': ' . $refund->get_error_message() );

			return;
		}

		$order->add_order_note( __( 'Pix refunded through AbacatePay.', 'abacatepay-for-woocommerce' ) );
	}

	/**
	 * Dispute opened (UNDER_DISPUTE), won (PAID) or lost (REFUNDED).
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $charge data.transparent.
	 * @param string   $reason Dispute reason.
	 */
	private static function handle_disputed( WC_Order $order, array $charge, $reason ) {
		$status = isset( $charge['status'] ) ? (string) $charge['status'] : '';

		if ( 'UNDER_DISPUTE' === $status ) {
			$order->update_status(
				'on-hold',
				/* translators: %s: dispute reason */
				sprintf( __( 'Pix payment disputed by the payer: %s', 'abacatepay-for-woocommerce' ), '' !== $reason ? $reason : '-' )
			);
		} elseif ( 'PAID' === $status ) {
			$note = __( 'Pix dispute closed in favor of the store.', 'abacatepay-for-woocommerce' );

			// Back to where it was before the dispute put it on hold.
			if ( $order->has_status( 'on-hold' ) && AbacatePay_WC_Pix_Gateway::is_order_paid( $order ) ) {
				$order->update_status( $order->needs_processing() ? 'processing' : 'completed', $note );
			} else {
				$order->add_order_note( $note );
			}
		} elseif ( 'REFUNDED' === $status ) {
			self::handle_refunded( $order, __( 'Dispute lost.', 'abacatepay-for-woocommerce' ) );
		}
	}

	/**
	 * Finds the order from the metadata sent at charge creation, and checks the charge really belongs to it.
	 *
	 * @param array $charge data.transparent.
	 * @return WC_Order|null
	 */
	private static function find_order( array $charge ) {
		$charge_id = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		$order_id  = isset( $charge['metadata']['wcOrderId'] ) ? absint( $charge['metadata']['wcOrderId'] ) : 0;

		if ( ! $order_id && isset( $charge['externalId'] ) && preg_match( '/^wc-(\d+)-/', (string) $charge['externalId'], $matches ) ) {
			$order_id = (int) $matches[1];
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order || AbacatePay_WC_Pix_Gateway::ID !== $order->get_payment_method() ) {
			return null;
		}

		return AbacatePay_WC_Pix_Gateway::order_has_charge( $order, $charge_id ) ? $order : null;
	}

	/**
	 * Checks X-Webhook-Signature: base64 HMAC-SHA256 of the raw body.
	 *
	 * @param string $body Raw request body.
	 * @return bool
	 */
	private static function is_valid_signature( $body ) {
		$signature = isset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$key       = (string) apply_filters( 'abacatepay_wc_webhook_signature_key', self::SIGNATURE_KEY );
		$expected  = base64_encode( hash_hmac( 'sha256', $body, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return '' !== $signature && hash_equals( $expected, $signature );
	}

	/**
	 * Sends the response and stops.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Short message, only useful in the AbacatePay webhook logs.
	 */
	private static function respond( $status, $message ) {
		wp_send_json( array( 'message' => $message ), $status );
	}
}
