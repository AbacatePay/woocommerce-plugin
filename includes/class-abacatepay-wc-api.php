<?php
/**
 * AbacatePay API client.
 *
 * @package AbacatePay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal client for the AbacatePay v2 transparent checkout endpoints.
 *
 * The same URL serves Dev mode and production: the API key decides which one is used.
 *
 * @see https://docs.abacatepay.com/pages/transparents/reference
 */
class AbacatePay_WC_Api {

	const BASE_URL = 'https://api.abacatepay.com/v2';

	/**
	 * API key, sent as a Bearer token.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key AbacatePay API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Creates a Pix charge. Requires the TRANSPARENT:CREATE permission.
	 *
	 * @param array $data Charge data (amount in cents, description, expiresIn, customer, externalId, metadata).
	 * @return array|WP_Error The charge (id, status, brCode, brCodeBase64, expiresAt, devMode...).
	 */
	public function create_pix_charge( array $data ) {
		return $this->request(
			'POST',
			'/transparents/create',
			array(
				'method' => 'PIX',
				'data'   => $data,
			)
		);
	}

	/**
	 * Charges a credit card. Requires the TRANSPARENT:CREATE permission.
	 *
	 * @param array $data Charge data (amount in cents, token, installmentCount, customer, metadata).
	 * @return array|WP_Error The charge (id, status, brand, lastFour, installmentCount...).
	 */
	public function create_card_charge( array $data ) {
		return $this->request(
			'POST',
			'/transparents/create',
			array(
				'method' => 'CARD',
				'data'   => $data,
			)
		);
	}

	/**
	 * Issues a boleto (with an alternative Pix for the same charge). Requires the TRANSPARENT:CREATE permission.
	 *
	 * @param array $data Charge data (amount in cents, customer with name and taxId, dueDate, interest, fine...).
	 * @return array|WP_Error The charge (id, status, barCode, url, brCode, brCodeBase64, expiresAt, devMode...).
	 */
	public function create_boleto_charge( array $data ) {
		return $this->request(
			'POST',
			'/transparents/create',
			array(
				'method' => 'BOLETO',
				'data'   => $data,
			)
		);
	}

	/**
	 * Full charge, for any method. Card charges must use this instead of check_charge(). Requires TRANSPARENT:READ.
	 *
	 * @param string $charge_id Charge ID (bole_..., card_..., pix_char_...).
	 * @return array|WP_Error
	 */
	public function get_charge( $charge_id ) {
		return $this->request( 'GET', '/transparents/get?id=' . rawurlencode( $charge_id ) );
	}

	/**
	 * Lists charges, newest first. Requires TRANSPARENT:READ.
	 *
	 * @param array $query Filters: method, startDate, endDate (YYYY-MM-DD), limit, after.
	 * @return array|WP_Error List of charges.
	 */
	public function list_charges( array $query = array() ) {
		return $this->request( 'GET', '/transparents/list?' . http_build_query( $query ) );
	}

	/**
	 * Current status of a Pix charge. Requires the TRANSPARENT:READ permission.
	 *
	 * @param string $charge_id Charge ID (pix_char_...).
	 * @return array|WP_Error Array with id, status and expiresAt.
	 */
	public function check_charge( $charge_id ) {
		return $this->request( 'GET', '/transparents/check?id=' . rawurlencode( $charge_id ) );
	}

	/**
	 * Refunds the full amount of a paid charge. Requires the REFUND:CREATE permission.
	 *
	 * @param string $charge_id Charge ID (pix_char_...).
	 * @param string $reason    Optional reason.
	 * @return array|WP_Error
	 */
	public function refund_charge( $charge_id, $reason = '' ) {
		$body = array( 'id' => $charge_id );

		if ( '' !== $reason ) {
			$body['reason'] = $reason;
		}

		return $this->request( 'POST', '/transparents/refund', $body );
	}

	/**
	 * Marks a Dev mode Pix charge as paid. Fails with production keys.
	 *
	 * @param string $charge_id Charge ID (pix_char_...).
	 * @return array|WP_Error
	 */
	public function simulate_payment( $charge_id ) {
		return $this->request( 'POST', '/transparents/simulate-payment?id=' . rawurlencode( $charge_id ), array() );
	}

	/**
	 * Sends a request and unwraps the { data, success, error } envelope.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to the base URL.
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error The "data" field.
	 */
	private function request( $method, $path, $body = null ) {
		$url = untrailingslashit( apply_filters( 'abacatepay_wc_api_url', self::BASE_URL ) ) . $path;

		$response = wp_safe_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'abacatepay-for-woocommerce/' . ABACATEPAY_WC_VERSION,
				),
				// An empty array must go out as "{}", not "[]".
				'body'    => null === $body ? null : wp_json_encode( (object) $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			AbacatePay_WC_Logger::error( "{$method} {$path} failed: " . $response->get_error_message() );

			return new WP_Error( 'abacatepay_http_error', __( 'Could not connect to AbacatePay.', 'abacatepay-for-woocommerce' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		AbacatePay_WC_Logger::debug(
			"{$method} {$path} returned HTTP {$code}",
			array(
				'request'  => $body,
				'response' => self::without_qr_code( $json ),
			)
		);

		$success = is_array( $json ) && ! empty( $json['success'] );

		if ( $code < 200 || $code >= 300 || ! $success ) {
			$message = is_array( $json ) && ! empty( $json['error'] ) && is_string( $json['error'] ) ? $json['error'] : '';

			if ( '' === $message ) {
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'Unexpected response from AbacatePay (HTTP %d).', 'abacatepay-for-woocommerce' ), $code );
			}

			AbacatePay_WC_Logger::error( "{$method} {$path} returned HTTP {$code}: {$message}" );

			return new WP_Error( 'abacatepay_api_error', $message, array( 'status' => $code ) );
		}

		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
	}

	/**
	 * Drops the QR code image from a response so it does not flood the log.
	 *
	 * @param mixed $json Decoded response.
	 * @return mixed
	 */
	private static function without_qr_code( $json ) {
		if ( isset( $json['data']['brCodeBase64'] ) ) {
			$json['data']['brCodeBase64'] = '[omitted]';
		}

		return $json;
	}
}
