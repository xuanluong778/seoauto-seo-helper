<?php
/**
 * Stores and manages SaaS pairing credentials.
 *
 * Pairing uses one-time SA-XXXX codes only — never WP admin password,
 * Application Passwords, wp-admin cookies, or 2FA codes.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Connection;

use SEOAuto\SEOHelper\Auth\Hmac_Signer;
use SEOAuto\SEOHelper\Security\Secret_Store;

final class Connection_Manager {

	public const STATUS_DISCONNECTED = 'disconnected';
	public const STATUS_CONNECTED    = 'connected';
	public const STATUS_LOCKED       = 'locked';
	public const STATUS_ERROR        = 'error';

	public function option( string $key, mixed $default = '' ): mixed {
		return get_option( SEOAUTO_HELPER_PREFIX . $key, $default );
	}

	public function update_option( string $key, mixed $value ): bool {
		$name = SEOAUTO_HELPER_PREFIX . $key;

		if ( ! $this->option_exists( $name ) ) {
			$ok = add_option( $name, $value, '', false );
			$this->force_autoload_no( $name );
			return (bool) $ok;
		}

		$ok = update_option( $name, $value, false );
		$this->force_autoload_no( $name );
		return (bool) $ok;
	}

	private function option_exists( string $name ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false !== get_option( $name, false );
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);
		return null !== $found && '' !== (string) $found;
	}

	private function force_autoload_no( string $name ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => $name ),
			array( '%s' ),
			array( '%s' )
		);
	}

	public function api_base(): string {
		$base = (string) $this->option( 'api_base', 'https://seoauto.vn' );
		$base = untrailingslashit( trim( $base ) );
		return $base !== '' ? $base : 'https://seoauto.vn';
	}

	/**
	 * Require HTTPS for SEOAuto API (localhost http allowed for local dev).
	 */
	public function assert_https_api_base( string $base ): string|\WP_Error {
		$base = untrailingslashit( esc_url_raw( trim( $base ) ) );
		if ( $base === '' ) {
			return new \WP_Error( 'seoauto_api_base', __( 'API base trống.', 'seoauto-seo-helper' ) );
		}
		$parts  = wp_parse_url( $base );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( $scheme === 'https' ) {
			return $base;
		}
		if ( $scheme === 'http' && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return $base;
		}
		return new \WP_Error(
			'seoauto_https_required',
			__( 'SEOAuto API phải dùng HTTPS.', 'seoauto-seo-helper' )
		);
	}

	public function is_connected(): bool {
		return self::STATUS_CONNECTED === (string) $this->option( 'status', self::STATUS_DISCONNECTED )
			&& $this->site_id() !== ''
			&& $this->site_secret() !== '';
	}

	/**
	 * Paired credentials exist (connected or locked). Used for HMAC on read-only routes.
	 */
	public function has_credentials(): bool {
		$status = (string) $this->option( 'status', self::STATUS_DISCONNECTED );
		if ( ! in_array( $status, array( self::STATUS_CONNECTED, self::STATUS_LOCKED ), true ) ) {
			return false;
		}
		return $this->site_id() !== '' && $this->site_secret() !== '';
	}

	public function is_locked(): bool {
		return self::STATUS_LOCKED === (string) $this->option( 'status', self::STATUS_DISCONNECTED )
			&& $this->has_credentials();
	}

	/**
	 * Public snapshot — never includes site_secret.
	 *
	 * @return array<string,mixed>
	 */
	public function get_snapshot(): array {
		return array(
			'connection_id'       => (int) $this->option( 'connection_id', 0 ),
			'site_id'             => $this->site_id(),
			'organization_id'     => (int) $this->option( 'organization_id', 0 ),
			'domain'              => (string) $this->option( 'domain', '' ),
			'status'              => (string) $this->option( 'status', self::STATUS_DISCONNECTED ),
			'paired_at'           => (string) $this->option( 'paired_at', '' ),
			'api_base'            => $this->api_base(),
			'last_sync_at'        => (string) $this->option( 'last_sync_at', '' ),
			'last_error'          => (string) $this->option( 'last_error', '' ),
			'last_check_at'       => (string) $this->option( 'last_check_at', '' ),
			'last_check_ok'       => (bool) $this->option( 'last_check_ok', false ),
			'last_check_message'  => (string) $this->option( 'last_check_message', '' ),
			'last_entitlement_check_at' => (string) $this->option( 'last_entitlement_check_at', '' ),
			'lock_reason'         => (string) $this->option( 'lock_reason', '' ),
			'network_grace_until' => (string) $this->option( 'network_grace_until', '' ),
			'connectivity_state'  => (string) $this->option( 'connectivity_state', '' ),
			'last_api_error'      => (string) $this->option( 'last_api_error', '' ),
			'has_secret'          => $this->site_secret() !== '',
			'secret_encrypted'    => Secret_Store::is_encrypted( (string) $this->option( 'site_secret', '' ) ),
		);
	}

	/**
	 * Persist pairing response. Encrypts site_secret; never stores plaintext.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function store_pairing( array $payload ): void {
		$plain_secret = (string) ( $payload['site_secret'] ?? '' );
		$encrypted    = Secret_Store::encrypt( $plain_secret );
		// Drop plaintext from memory reference ASAP.
		unset( $payload['site_secret'], $plain_secret );

		$this->update_option( 'connection_id', (int) ( $payload['connection_id'] ?? 0 ) );
		$this->update_option( 'site_id', sanitize_text_field( (string) ( $payload['site_id'] ?? '' ) ) );
		$this->update_option( 'site_secret', $encrypted );
		$this->update_option( 'organization_id', (int) ( $payload['organization_id'] ?? 0 ) );
		$this->update_option( 'domain', sanitize_text_field( (string) ( $payload['domain'] ?? '' ) ) );
		$this->update_option( 'paired_at', gmdate( 'c' ) );
		$this->update_option( 'status', self::STATUS_CONNECTED );
		$this->update_option( 'last_error', '' );

		if ( isset( $payload['entitlement'] ) && is_array( $payload['entitlement'] ) ) {
			$ent = $payload['entitlement'];
			if ( \SEOAuto\SEOHelper\Entitlement\Entitlement_Verifier::verify( $ent, $this->site_secret() ) ) {
				$sig = (string) ( $ent['signature'] ?? '' );
				unset( $ent['signature'] );
				$this->update_option( 'entitlement_json', wp_json_encode( $ent ) );
				$this->update_option( 'entitlement_sig', $sig );
			} else {
				$this->update_option( 'last_error', __( 'Entitlement signature không hợp lệ.', 'seoauto-seo-helper' ) );
				$this->update_option( 'status', self::STATUS_LOCKED );
			}
		}
	}

	public function disconnect(): void {
		$this->update_option( 'connection_id', 0 );
		$this->update_option( 'site_id', '' );
		$this->update_option( 'site_secret', '' );
		$this->update_option( 'organization_id', 0 );
		$this->update_option( 'domain', '' );
		$this->update_option( 'paired_at', '' );
		$this->update_option( 'status', self::STATUS_DISCONNECTED );
		$this->update_option( 'entitlement_json', '' );
		$this->update_option( 'entitlement_sig', '' );
		$this->update_option( 'last_error', '' );
		$this->update_option( 'last_check_at', '' );
		$this->update_option( 'last_check_ok', false );
		$this->update_option( 'last_check_message', '' );
		$this->update_option( 'last_entitlement_check_at', '' );
		$this->update_option( 'last_entitlement_check_source', '' );
		$this->update_option( 'lock_reason', '' );
		$this->update_option( 'network_grace_until', '' );
		$this->update_option( 'connectivity_state', '' );
		$this->update_option( 'last_entitlement_was_active', '' );
		$this->update_option( 'last_api_error', '' );
	}

	/** Decrypted secret for outbound auth — never echo or log. */
	public function site_secret(): string {
		$stored = (string) $this->option( 'site_secret', '' );
		$plain  = Secret_Store::decrypt( $stored );
		// Migrate legacy plaintext silently.
		if ( $plain !== '' && ! Secret_Store::is_encrypted( $stored ) ) {
			$this->update_option( 'site_secret', Secret_Store::encrypt( $plain ) );
		}
		return $plain;
	}

	public function site_id(): string {
		return (string) $this->option( 'site_id', '' );
	}

	/**
	 * Pair with SEOAuto using one-time SA-XXXX-XXXX code only.
	 *
	 * @return array{ok:bool,message:string,data?:array<string,mixed>}
	 */
	public function pair_with_code( string $code, string $api_base = '' ): array {
		$code = strtoupper( preg_replace( '/\s+/', '', trim( $code ) ) ?? '' );
		if ( ! preg_match( '/^SA-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Mã ghép nối không đúng định dạng SA-XXXX-XXXX.', 'seoauto-seo-helper' ),
			);
		}

		$base_in = $api_base !== '' ? $api_base : $this->api_base();
		$base    = $this->assert_https_api_base( $base_in );
		if ( is_wp_error( $base ) ) {
			return array( 'ok' => false, 'message' => $base->get_error_message() );
		}
		$this->update_option( 'api_base', $base );

		$home = home_url( '/' );
		// Prefer HTTPS site URL when available.
		if ( is_ssl() || str_starts_with( $home, 'https://' ) ) {
			// ok
		} elseif ( ! in_array( wp_parse_url( $home, PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
			$home = set_url_scheme( $home, 'https' );
		}

		$body = array(
			'code'           => $code,
			'domain'         => $home,
			'site_url'       => $home,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'seo_plugin'     => $this->detect_seo_plugin(),
			'plugin_version' => SEOAUTO_HELPER_VERSION,
		);

		$url      = $base . '/api/wordpress-plugin/pair';
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
			)
		);

		// Never log pairing code or response secrets.
		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			$this->update_option( 'last_error', $msg );
			$this->update_option( 'status', self::STATUS_ERROR );
			return array( 'ok' => false, 'message' => $msg );
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$raw       = (string) wp_remote_retrieve_body( $response );
		$data      = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$msg = __( 'Phản hồi SEOAuto không hợp lệ.', 'seoauto-seo-helper' );
			$this->update_option( 'last_error', $msg );
			return array( 'ok' => false, 'message' => $msg );
		}

		if ( $code_http >= 400 ) {
			$detail = is_array( $data['detail'] ?? null ) ? $data['detail'] : array();
			if ( is_string( $data['detail'] ?? null ) && $data['detail'] !== '' ) {
				$msg = (string) $data['detail'];
			} else {
				$msg = (string) ( $detail['message'] ?? $data['message'] ?? '' );
			}
			if ( $msg === '' ) {
				if ( 404 === $code_http ) {
					$msg = sprintf(
						/* translators: 1: API URL */
						__( 'SEOAuto chưa có API ghép nối (HTTP 404) tại %s. Cần deploy endpoint /api/wordpress-plugin/pair lên máy chủ SEOAuto.', 'seoauto-seo-helper' ),
						esc_url_raw( $url )
					);
				} else {
					$msg = sprintf(
						/* translators: 1: HTTP status code */
						__( 'Ghép nối thất bại (HTTP %d).', 'seoauto-seo-helper' ),
						$code_http
					);
				}
			}
			$this->update_option( 'last_error', $msg );
			$this->update_option( 'status', self::STATUS_ERROR );
			return array( 'ok' => false, 'message' => $msg );
		}

		if ( empty( $data['site_secret'] ) || empty( $data['site_id'] ) || empty( $data['connection_id'] ) ) {
			$msg = __( 'Thiếu connection_id / site_id / site_secret từ SEOAuto.', 'seoauto-seo-helper' );
			$this->update_option( 'last_error', $msg );
			return array( 'ok' => false, 'message' => $msg );
		}

		$this->store_pairing( $data );
		// Ensure secret never remains in $data for accidental logging.
		unset( $data['site_secret'] );

		return array(
			'ok'      => true,
			'message' => __( 'Đã kết nối SEOAuto thành công.', 'seoauto-seo-helper' ),
			'data'    => array(
				'connection_id' => (int) ( $data['connection_id'] ?? 0 ),
				'site_id'       => (string) ( $data['site_id'] ?? '' ),
			),
		);
	}

	/**
	 * Verify local credentials + optional SaaS reachability. Never returns secret.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test_connection(): array {
		if ( ! $this->has_credentials() ) {
			$msg = __( 'Chưa kết nối SEOAuto.', 'seoauto-seo-helper' );
			$this->update_option( 'last_check_ok', false );
			$this->update_option( 'last_check_message', $msg );
			$this->update_option( 'last_check_at', gmdate( 'c' ) );
			return array( 'ok' => false, 'message' => $msg );
		}

		$secret = $this->site_secret();
		if ( $secret === '' ) {
			$msg = __( 'Không giải mã được site_secret. Hãy ghép nối lại.', 'seoauto-seo-helper' );
			$this->update_option( 'status', self::STATUS_ERROR );
			$this->update_option( 'last_check_ok', false );
			$this->update_option( 'last_check_message', $msg );
			$this->update_option( 'last_check_at', gmdate( 'c' ) );
			return array( 'ok' => false, 'message' => $msg );
		}

		// Authenticated health-check via HMAC (no public routes).
		$path    = Hmac_Signer::normalize_path( (string) ( wp_parse_url( rest_url( 'seoauto/v1/health-check' ), PHP_URL_PATH ) ?? '/wp-json/seoauto/v1/health-check' ) );
		$headers = Hmac_Signer::build_headers(
			$this->site_id(),
			(int) $this->option( 'connection_id', 0 ),
			$secret,
			'POST',
			$path,
			''
		);
		$secret = '';

		// Self-request on local HTTP installs.
		add_filter( 'seoauto_helper_allow_insecure_local', '__return_true' );

		$check = wp_remote_post(
			rest_url( 'seoauto/v1/health-check' ),
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => '',
			)
		);

		remove_filter( 'seoauto_helper_allow_insecure_local', '__return_true' );

		if ( is_wp_error( $check ) ) {
			$msg = $check->get_error_message();
			$this->update_option( 'last_check_ok', false );
			$this->update_option( 'last_check_message', $msg );
			$this->update_option( 'last_check_at', gmdate( 'c' ) );
			return array( 'ok' => false, 'message' => $msg );
		}

		$check_code = (int) wp_remote_retrieve_response_code( $check );
		if ( $check_code >= 400 ) {
			$body    = (string) wp_remote_retrieve_body( $check );
			$headers = wp_remote_retrieve_headers( $check );
			$headers = is_array( $headers ) ? $headers : (array) $headers;

			$block = \SEOAuto\SEOHelper\Security\Firewall_Guidance::detect_block(
				$check_code,
				$body,
				$headers,
				'POST',
				$path
			);

			if ( null !== $block ) {
				\SEOAuto\SEOHelper\Security\Firewall_Guidance::record_block( $block );
				$msg = \SEOAuto\SEOHelper\Security\Firewall_Guidance::blocked_message( $block );
				$this->update_option( 'last_check_ok', false );
				$this->update_option( 'last_check_message', $msg );
				$this->update_option( 'last_check_at', gmdate( 'c' ) );
				$this->update_option( 'last_error', $msg );
				return array(
					'ok'               => false,
					'message'          => $msg,
					'firewall_blocked' => true,
					'endpoint'         => $block['endpoint'],
					'method'           => $block['method'],
					'http_code'        => $block['http_code'],
					'error_code'       => \SEOAuto\SEOHelper\Security\Firewall_Guidance::ERROR_CODE,
				);
			}

			$msg = sprintf(
				/* translators: 1: HTTP status 2: REST path */
				__( 'Xác thực HMAC thất bại — HTTP %1$d trên %2$s.', 'seoauto-seo-helper' ),
				$check_code,
				$path
			);
			$this->update_option( 'last_check_ok', false );
			$this->update_option( 'last_check_message', $msg );
			$this->update_option( 'last_check_at', gmdate( 'c' ) );
			return array( 'ok' => false, 'message' => $msg );
		}

		\SEOAuto\SEOHelper\Security\Firewall_Guidance::clear_recorded_block();

		// Reachability of SEOAuto API base over HTTPS.
		$base = $this->assert_https_api_base( $this->api_base() );
		if ( is_wp_error( $base ) ) {
			$msg = $base->get_error_message();
			$this->update_option( 'last_check_ok', false );
			$this->update_option( 'last_check_message', $msg );
			$this->update_option( 'last_check_at', gmdate( 'c' ) );
			return array( 'ok' => false, 'message' => $msg );
		}

		$msg = sprintf(
			/* translators: 1: site_id 2: connection_id */
			__( 'Kết nối OK — site_id %1$s, connection_id %2$d.', 'seoauto-seo-helper' ),
			$this->site_id(),
			(int) $this->option( 'connection_id', 0 )
		);
		$this->update_option( 'last_check_ok', true );
		$this->update_option( 'last_check_message', $msg );
		$this->update_option( 'last_check_at', gmdate( 'c' ) );
		$this->update_option( 'last_error', '' );
		if ( ! $this->is_locked() ) {
			$this->update_option( 'status', self::STATUS_CONNECTED );
		}

		return array( 'ok' => true, 'message' => $msg );
	}

	public function detect_seo_plugin(): string {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\\WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) || class_exists( '\\AIOSEO\\Plugin\\Common\\Models\\Post' ) ) {
			return 'aioseo';
		}
		return 'native';
	}
}
