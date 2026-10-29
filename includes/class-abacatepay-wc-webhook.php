<?php
/**
 * AbacatePay webhook receiver.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives the transparent.* events sent to /wc-api/abacatepay_wc_webhook and hands them to the gateway
 * the order was paid with (see AbacatePay_WC_Gateway::handle_webhook_event()).
 *
 * Each delivery is checked twice: the webhookSecret query parameter proves it came from AbacatePay and the
 * X-Webhook-Signature HMAC proves the body was not changed.
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
		$body = (string) file_get_contents( 'php://input' );

		$secret = isset( $_GET['webhookSecret'] ) ? sanitize_text_field( wp_unslash( $_GET['webhookSecret'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-to-server request, authenticated by the webhook secret.
		$stored = AbacatePay_WC_Gateway::get_shared_setting( 'webhook_secret' );

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

		$order   = self::find_order( $charge );
		$gateway = $order ? AbacatePay_WC::gateway( $order->get_payment_method() ) : null;

		if ( ! $order || ! $gateway ) {
			// Not ours (another integration on the same AbacatePay account, or a deleted order). 2xx stops retries.
			self::respond( 200, 'order not found' );
		}

		$gateway->handle_webhook_event( $event, $charge, $reason, $order );

		self::respond( 200, 'ok' );
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

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		return AbacatePay_WC_Gateway::order_has_charge( $order, $charge_id ) ? $order : null;
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
