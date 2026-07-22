<?php
/**
 * Pull signed entitlement from SEOAuto API (HMAC).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Entitlement;

use SEOAuto\SEOHelper\Auth\Hmac_Signer;
use SEOAuto\SEOHelper\Connection\Connection_Manager;

class Entitlement_Client {

	public const API_PATH = '/api/wordpress-plugin/entitlement';

	/** Reasons that must lock immediately — never network grace. */
	private const HARD_DENY_REASONS = array(
		'expired',
		'canceled',
		'cancelled',
		'suspended',
		'paused',
		'revoked',
	);

	private const HARD_DENY_STATUSES = array(
		'expired',
		'canceled',
		'cancelled',
		'suspended',
		'paused',
		'revoked',
	);

	public function __construct(
		private Connection_Manager $connection
	) {}

	/**
	 * @return array{
	 *   ok:bool,
	 *   skipped:bool,
	 *   network_error:bool,
	 *   hard_deny:bool,
	 *   http_code:int,
	 *   message:string,
	 *   entitlement?:array<string,mixed>
	 * }
	 */
	public function fetch(): array {
		$empty = array(
			'ok'            => false,
			'skipped'       => false,
			'network_error' => false,
			'hard_deny'     => false,
			'http_code'     => 0,
			'message'       => '',
		);

		if ( ! $this->connection->has_credentials() ) {
			return array_merge( $empty, array( 'skipped' => true ) );
		}

		$secret = $this->connection->site_secret();
		if ( $secret === '' ) {
			return array_merge( $empty, array( 'message' => 'missing_secret' ) );
		}

		$path    = Hmac_Signer::normalize_path( self::API_PATH );
		$url     = $this->connection->api_base() . $path;
		$headers = Hmac_Signer::build_headers(
			$this->connection->site_id(),
			(int) $this->connection->option( 'connection_id', 0 ),
			$secret,
			'GET',
			$path,
			''
		);
		$secret = '';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array_merge(
				$empty,
				array(
					'network_error' => self::is_wp_error_network( $response ),
					'message'       => $response->get_error_message(),
				)
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw       = (string) wp_remote_retrieve_body( $response );

		if ( in_array( $http_code, array( 502, 503, 504 ), true ) ) {
			return array_merge(
				$empty,
				array(
					'network_error' => true,
					'http_code'       => $http_code,
					'message'         => sprintf(
						/* translators: %d: HTTP status */
						__( 'SEOAuto API trả HTTP %d.', 'seoauto-seo-helper' ),
						$http_code
					),
				)
			);
		}

		if ( 404 === $http_code ) {
			return array_merge(
				$empty,
				array(
					'skipped'   => true,
					'http_code' => $http_code,
					'message'   => 'endpoint_not_found',
				)
			);
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array_merge(
				$empty,
				array(
					'http_code' => $http_code,
					'message'   => __( 'Phản hồi entitlement không hợp lệ.', 'seoauto-seo-helper' ),
				)
			);
		}

		$ent = isset( $data['entitlement'] ) && is_array( $data['entitlement'] ) ? $data['entitlement'] : $data;
		if ( ! is_array( $ent ) || ! array_key_exists( 'allowed', $ent ) ) {
			return array_merge(
				$empty,
				array(
					'http_code' => $http_code,
					'message'   => __( 'Thiếu trường allowed trong entitlement.', 'seoauto-seo-helper' ),
				)
			);
		}

		$hard_deny = self::is_hard_deny_payload( $ent );

		if ( $http_code >= 400 && ! $hard_deny ) {
			$msg = (string) ( $data['message'] ?? $data['detail']['message'] ?? __( 'Lỗi SEOAuto API.', 'seoauto-seo-helper' ) );
			return array_merge(
				$empty,
				array(
					'http_code' => $http_code,
					'message'   => $msg,
				)
			);
		}

		return array(
			'ok'            => ! empty( $ent['allowed'] ) || $hard_deny,
			'skipped'       => false,
			'network_error' => false,
			'hard_deny'     => $hard_deny,
			'http_code'     => $http_code,
			'message'       => $hard_deny
				? (string) ( $ent['reason'] ?? 'denied' )
				: 'ok',
			'entitlement'   => $ent,
		);
	}

	/**
	 * @param array<string,mixed> $ent
	 */
	public static function is_hard_deny_payload( array $ent ): bool {
		if ( ! empty( $ent['allowed'] ) ) {
			return false;
		}
		$reason = strtolower( (string) ( $ent['reason'] ?? '' ) );
		if ( in_array( $reason, self::HARD_DENY_REASONS, true ) ) {
			return true;
		}
		$status = strtolower( (string) ( $ent['subscription_status'] ?? '' ) );
		return in_array( $status, self::HARD_DENY_STATUSES, true );
	}

	public static function is_wp_error_network( \WP_Error $error ): bool {
		$code    = strtolower( (string) $error->get_error_code() );
		$message = strtolower( (string) $error->get_error_message() );

		if ( str_contains( $code, 'timeout' ) || str_contains( $code, 'http_request_failed' ) ) {
			return true;
		}

		$needles = array(
			'timed out',
			'timeout',
			'could not resolve',
			'couldn\'t resolve',
			'name or service not known',
			'failed to resolve',
			'dns',
			'connection refused',
			'connection reset',
			'network is unreachable',
			'curl error 6',
			'curl error 7',
			'curl error 28',
			'curl error 35',
			'curl error 52',
			'curl error 56',
		);

		foreach ( $needles as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
