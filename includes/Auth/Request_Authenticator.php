<?php
/**
 * Authenticate inbound SEOAuto → plugin requests (HMAC headers only).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Auth;

use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use WP_Error;
use WP_REST_Request;

final class Request_Authenticator {

	private const DEFAULT_MAX_BODY_BYTES = 2097152; // 2 MiB JSON body cap.

	private Nonce_Store $nonces;
	private Rate_Limiter $rate_limiter;

	public function __construct(
		private Connection_Manager $connection,
		private ?Entitlement_Manager $entitlement = null,
		?Nonce_Store $nonces = null,
		?Rate_Limiter $rate_limiter = null
	) {
		$this->nonces       = $nonces ?? new Nonce_Store();
		$this->rate_limiter = $rate_limiter ?? new Rate_Limiter( 60, 60 );
	}

	/**
	 * @param array{require_connected?:bool,require_entitlement?:bool,feature?:string|null} $options
	 * @return true|WP_Error
	 */
	public function authenticate( WP_REST_Request $request, array $options = array() ): bool|WP_Error {
		$require_connected   = (bool) ( $options['require_connected'] ?? true );
		$require_entitlement = (bool) ( $options['require_entitlement'] ?? true );
		$required_feature    = isset( $options['feature'] ) ? (string) $options['feature'] : null;
		if ( $required_feature === '' ) {
			$required_feature = null;
		}

		$https = $this->assert_https( $request );
		if ( $https instanceof WP_Error ) {
			return $https;
		}

		if ( $require_connected && ! $this->connection->has_credentials() ) {
			return $this->error( 'seoauto_not_connected', __( 'Plugin chưa kết nối SEOAuto.', 'seoauto-seo-helper' ), 401 );
		}

		$body = (string) $request->get_body();
		$max_body = (int) apply_filters( 'seoauto_helper_max_request_body_bytes', self::DEFAULT_MAX_BODY_BYTES );
		if ( $max_body > 0 && strlen( $body ) > $max_body ) {
			return $this->error(
				'seoauto_body_too_large',
				__( 'Payload vượt quá giới hạn cho phép.', 'seoauto-seo-helper' ),
				413
			);
		}

		$site_id = (string) $request->get_header( 'x-seoauto-site-id' );
		$conn_id = (string) $request->get_header( 'x-seoauto-connection-id' );
		$ts      = (string) $request->get_header( 'x-seoauto-timestamp' );
		$nonce   = (string) $request->get_header( 'x-seoauto-nonce' );
		$req_id  = (string) $request->get_header( 'x-seoauto-request-id' );
		$sig     = (string) $request->get_header( 'x-seoauto-signature' );

		foreach (
			array(
				'X-SEOAuto-Site-ID'       => $site_id,
				'X-SEOAuto-Connection-ID' => $conn_id,
				'X-SEOAuto-Timestamp'     => $ts,
				'X-SEOAuto-Nonce'         => $nonce,
				'X-SEOAuto-Request-ID'    => $req_id,
				'X-SEOAuto-Signature'     => $sig,
			) as $header => $value
		) {
			if ( trim( $value ) === '' ) {
				return $this->error(
					'seoauto_missing_header',
					sprintf(
						/* translators: %s: header name */
						__( 'Thiếu header bắt buộc: %s', 'seoauto-seo-helper' ),
						$header
					),
					401
				);
			}
		}

		if ( $this->connection->has_credentials() ) {
			$expected_site = $this->connection->site_id();
			$expected_conn = (string) (int) $this->connection->option( 'connection_id', 0 );
			if ( ! hash_equals( $expected_site, $site_id ) ) {
				return $this->error( 'seoauto_site_mismatch', __( 'X-SEOAuto-Site-ID không khớp.', 'seoauto-seo-helper' ), 403 );
			}
			if ( ! hash_equals( $expected_conn, (string) (int) $conn_id ) ) {
				return $this->error( 'seoauto_connection_mismatch', __( 'X-SEOAuto-Connection-ID không khớp.', 'seoauto-seo-helper' ), 403 );
			}
			$stored_org = (int) $this->connection->option( 'organization_id', 0 );
			$header_org = (int) $request->get_header( 'x-seoauto-organization-id' );
			if ( $stored_org > 0 && $header_org > 0 && $stored_org !== $header_org ) {
				return $this->error( 'seoauto_organization_mismatch', __( 'X-SEOAuto-Organization-ID không khớp.', 'seoauto-seo-helper' ), 403 );
			}
		}

		if ( ! preg_match( '/^\d+$/', $ts ) ) {
			return $this->error( 'seoauto_invalid_timestamp', __( 'Timestamp không hợp lệ.', 'seoauto-seo-helper' ), 401 );
		}
		if ( abs( time() - (int) $ts ) > Hmac_Signer::MAX_SKEW_SECONDS ) {
			return $this->error( 'seoauto_timestamp_expired', __( 'Timestamp lệch quá 5 phút.', 'seoauto-seo-helper' ), 401 );
		}

		$rate = $this->rate_limiter->attempt( 'site:' . ( $site_id !== '' ? $site_id : 'unknown' ) );
		if ( $rate instanceof WP_Error ) {
			return $rate;
		}

		$secret = $this->connection->site_secret();
		if ( $secret === '' ) {
			return $this->error( 'seoauto_missing_secret', __( 'Thiếu site_secret.', 'seoauto-seo-helper' ), 401 );
		}

		$method = strtoupper( (string) $request->get_method() );
		$path   = $this->request_path( $request );

		$ok     = Hmac_Signer::verify( $secret, $sig, $method, $path, $ts, $nonce, $req_id, $body );
		$secret = '';
		if ( ! $ok ) {
			return $this->error( 'seoauto_bad_signature', __( 'Chữ ký HMAC không hợp lệ.', 'seoauto-seo-helper' ), 401 );
		}

		if ( ! $this->nonces->claim( $nonce ) ) {
			return $this->error( 'seoauto_nonce_replay', __( 'Nonce đã được sử dụng.', 'seoauto-seo-helper' ), 401 );
		}

		if ( $require_entitlement && null !== $this->entitlement ) {
			if ( $this->entitlement->is_locked() || ! $this->entitlement->can_mutate() ) {
				$eval = $this->entitlement->evaluate();
				return $this->error(
					'seoauto_plugin_locked',
					(string) ( $eval['message'] ?? __( 'Plugin LOCKED — gói không hợp lệ.', 'seoauto-seo-helper' ) ),
					403,
					array(
						'lock_reason' => (string) ( $eval['reason'] ?? 'entitlement_denied' ),
					)
				);
			}
			if ( null !== $required_feature ) {
				if ( ! $this->feature_allowed( $required_feature ) ) {
					return $this->error(
						'seoauto_feature_denied',
						sprintf(
							/* translators: %s: feature key */
							__( 'Thiếu feature: %s', 'seoauto-seo-helper' ),
							$required_feature
						),
						403
					);
				}
			}
		}

		return true;
	}

	/**
	 * Build a permission_callback closure with fixed options (no __return_true).
	 *
	 * @param array{require_connected?:bool,require_entitlement?:bool,feature?:string|null} $options
	 * @return callable(WP_REST_Request): (bool|WP_Error)
	 */
	public function permission( array $options = array() ): callable {
		return function ( WP_REST_Request $request ) use ( $options ): bool|WP_Error {
			$result = $this->authenticate( $request, $options );
			return $result instanceof WP_Error ? $result : true;
		};
	}

	/** @deprecated Use permission() */
	public function permission_callback( WP_REST_Request $request ): bool|WP_Error {
		return ( $this->permission( array( 'feature' => 'seo_helper' ) ) )( $request );
	}

	private function feature_allowed( string $feature ): bool {
		if ( null === $this->entitlement ) {
			return false;
		}
		// Exact feature from SaaS entitlement snapshot (production source of truth).
		if ( $this->entitlement->has_feature( $feature ) ) {
			return true;
		}

		// content_ops: SaaS explicit only — never implied by seo_helper / WP_DEBUG.
		if ( $feature === 'content_ops' ) {
			$caps    = $this->entitlement->capabilities();
			$cap_map = is_array( $caps['capabilities'] ?? null ) ? $caps['capabilities'] : array();
			return ! empty( $cap_map['content_ops'] );
		}

		// seo_audit must be explicitly granted — never implied by seo_helper in production.
		if ( $feature === 'seo_audit' ) {
			if ( $this->dev_entitlement_fallback()
				&& $this->entitlement->is_allowed()
				&& $this->entitlement->has_feature( 'seo_helper' )
			) {
				return true;
			}
			$caps    = $this->entitlement->capabilities();
			$cap_map = is_array( $caps['capabilities'] ?? null ) ? $caps['capabilities'] : array();
			return ! empty( $cap_map['seo_audit'] );
		}

		if ( $this->entitlement->has_feature( 'seo_helper' ) ) {
			return true;
		}
		// Active entitlement covers classic publish/SEO-sync features only.
		if ( in_array( $feature, array( 'seo_helper', 'yoast_sync', 'rankmath_sync' ), true )
			&& $this->entitlement->is_allowed()
		) {
			return true;
		}
		$caps    = $this->entitlement->capabilities();
		$cap_map = is_array( $caps['capabilities'] ?? null ) ? $caps['capabilities'] : array();
		return ! empty( $cap_map[ $feature ] );
	}

	/**
	 * Dev-only fallback (WP_DEBUG / SEOAUTO_HELPER_DEV). Must stay false on production.
	 */
	private function dev_entitlement_fallback(): bool {
		if ( defined( 'SEOAUTO_HELPER_DEV' ) && SEOAUTO_HELPER_DEV ) {
			return true;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}
		return (bool) apply_filters( 'seoauto_helper_dev_entitlement_fallback', false );
	}

	private function error( string $code, string $message, int $status, array $extra = array() ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => $status,
					'code'   => $code,
				),
				$extra
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	private function assert_https( WP_REST_Request $request ): bool|WP_Error {
		$allow_local = (bool) apply_filters( 'seoauto_helper_allow_insecure_local', false );
		$host        = strtolower( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' ) );
		$is_local    = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );

		if ( is_ssl() ) {
			return true;
		}
		$forwarded = strtolower( (string) $request->get_header( 'x-forwarded-proto' ) );
		if ( $forwarded === 'https' && (bool) apply_filters( 'seoauto_helper_trust_forwarded_proto', false ) ) {
			return true;
		}
		if ( $allow_local && $is_local ) {
			return true;
		}
		return $this->error( 'seoauto_https_required', __( 'Request tới plugin phải qua HTTPS.', 'seoauto-seo-helper' ), 403 );
	}

	private function request_path( WP_REST_Request $request ): string {
		$uri = $request->get_header( 'x-seoauto-path' );
		if ( is_string( $uri ) && $uri !== '' ) {
			return Hmac_Signer::normalize_path( $uri );
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$raw  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$path = (string) ( wp_parse_url( $raw, PHP_URL_PATH ) ?? '' );
			if ( $path !== '' ) {
				return Hmac_Signer::normalize_path( $path );
			}
		}
		$route = (string) $request->get_route();
		if ( $route !== '' && ! str_starts_with( $route, '/wp-json' ) ) {
			$route = '/wp-json' . $route;
		}
		return Hmac_Signer::normalize_path( $route );
	}
}
