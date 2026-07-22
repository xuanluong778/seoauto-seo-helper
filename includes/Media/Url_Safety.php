<?php
/**
 * SSRF-safe URL validation and download (redirect + timeout aware).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Media;

use WP_Error;

final class Url_Safety {

	public const MAX_REDIRECTS = 3;
	public const TIMEOUT       = 15;

	/**
	 * Validate URL scheme/host and resolved IPs (no fetch yet).
	 *
	 * @return true|WP_Error
	 */
	public function assert_safe_url( string $url ): bool|WP_Error {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return $this->err( 'seoauto_invalid_media_url', __( 'URL media trống.', 'seoauto-seo-helper' ), 400 );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $this->err( 'seoauto_invalid_media_url', __( 'URL media không hợp lệ.', 'seoauto-seo-helper' ), 400 );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );

		$allow_http_local = (bool) apply_filters( 'seoauto_helper_allow_insecure_local', false );
		if ( $scheme === 'https' ) {
			// ok
		} elseif ( $scheme === 'http' && $allow_http_local && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			// local dev only
		} else {
			return $this->err( 'seoauto_media_https_required', __( 'URL ảnh phải là HTTPS.', 'seoauto-seo-helper' ), 400 );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $this->err( 'seoauto_media_ssrf', __( 'URL có userinfo bị chặn.', 'seoauto-seo-helper' ), 400 );
		}

		if ( $this->is_blocked_hostname( $host ) ) {
			return $this->err( 'seoauto_media_ssrf', __( 'Host bị chặn (SSRF).', 'seoauto-seo-helper' ), 400 );
		}

		$ips = $this->resolve_ips( $host );
		if ( $ips === array() ) {
			return $this->err( 'seoauto_media_dns', __( 'Không resolve được host ảnh.', 'seoauto-seo-helper' ), 400 );
		}

		foreach ( $ips as $ip ) {
			if ( $this->is_blocked_ip( $ip ) ) {
				return $this->err(
					'seoauto_media_ssrf',
					sprintf(
						/* translators: %s: IP address */
						__( 'IP đích bị chặn (SSRF): %s', 'seoauto-seo-helper' ),
						$ip
					),
					400
				);
			}
		}

		return true;
	}

	/**
	 * Download URL to a temp file with redirect re-validation and size limit.
	 *
	 * @return string|WP_Error Absolute temp path.
	 */
	public function download_to_temp( string $url, int $max_bytes ): string|WP_Error {
		$current = esc_url_raw( trim( $url ) );
		$hops    = 0;

		while ( $hops <= self::MAX_REDIRECTS ) {
			$safe = $this->assert_safe_url( $current );
			if ( $safe instanceof WP_Error ) {
				return $safe;
			}

			$parts  = wp_parse_url( $current );
			$host   = strtolower( (string) ( is_array( $parts ) ? ( $parts['host'] ?? '' ) : '' ) );
			$ips    = $host !== '' ? $this->resolve_ips( $host ) : array();
			$pin_ip = $ips[0] ?? '';

			$request_args = array(
				'timeout'               => self::TIMEOUT,
				'redirection'           => 0,
				'limit_response_size'   => $max_bytes + 1,
				'sslverify'             => true,
				'headers'               => array(
					'Accept'     => 'image/*,*/*;q=0.8',
					'User-Agent' => 'SEOAuto-SEO-Helper/' . SEOAUTO_HELPER_VERSION,
				),
			);

			$response = $this->remote_get_pinned( $current, $host, $pin_ip, $request_args );

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'seoauto_media_fetch_failed',
					$response->get_error_message(),
					array( 'status' => 502, 'code' => 'seoauto_media_fetch_failed' )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( $location === '' ) {
					return $this->err( 'seoauto_media_redirect', __( 'Redirect thiếu Location.', 'seoauto-seo-helper' ), 400 );
				}
				$location = $this->absolutize_redirect( $current, $location );
				++$hops;
				$current = $location;
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				return $this->err(
					'seoauto_media_fetch_failed',
					sprintf(
						/* translators: %d: HTTP status */
						__( 'Tải ảnh thất bại (HTTP %d).', 'seoauto-seo-helper' ),
						$code
					),
					502
				);
			}

			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || $body === '' ) {
				return $this->err( 'seoauto_media_empty', __( 'Nội dung ảnh trống.', 'seoauto-seo-helper' ), 400 );
			}
			if ( strlen( $body ) > $max_bytes ) {
				return $this->err( 'seoauto_media_too_large', __( 'Ảnh vượt giới hạn dung lượng.', 'seoauto-seo-helper' ), 413 );
			}

			$tmp = wp_tempnam( 'seoauto-media-' );
			if ( ! is_string( $tmp ) || $tmp === '' ) {
				return $this->err( 'seoauto_media_temp', __( 'Không tạo được file tạm.', 'seoauto-seo-helper' ), 500 );
			}
			// wp_tempnam creates an empty file; overwrite with body.
			$written = file_put_contents( $tmp, $body );
			if ( false === $written ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $this->err( 'seoauto_media_temp', __( 'Không ghi được file tạm.', 'seoauto-seo-helper' ), 500 );
			}

			return $tmp;
		}

		return $this->err( 'seoauto_media_redirect', __( 'Quá nhiều redirect khi tải ảnh.', 'seoauto-seo-helper' ), 400 );
	}

	public function is_blocked_hostname( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );
		$blocked = array(
			'localhost',
			'metadata.google.internal',
			'metadata.google',
			'kubernetes.default',
			'kubernetes.default.svc',
		);
		if ( in_array( $host, $blocked, true ) ) {
			return true;
		}
		if ( str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return true;
		}
		return false;
	}

	public function is_blocked_ip( string $ip ): bool {
		$ip = strtolower( trim( $ip ) );
		if ( $ip === '' ) {
			return true;
		}

		// IPv4 mapped IPv6.
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$ip = substr( $ip, 7 );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			if ( false === $long ) {
				return true;
			}
			$long = sprintf( '%u', $long );

			$ranges = array(
				array( '0.0.0.0', '0.255.255.255' ),           // "this" network
				array( '10.0.0.0', '10.255.255.255' ),         // private
				array( '100.64.0.0', '100.127.255.255' ),      // CGNAT
				array( '127.0.0.0', '127.255.255.255' ),       // loopback
				array( '169.254.0.0', '169.254.255.255' ),     // link-local + cloud metadata
				array( '172.16.0.0', '172.31.255.255' ),       // private
				array( '192.0.0.0', '192.0.0.255' ),           // IETF protocol
				array( '192.168.0.0', '192.168.255.255' ),     // private
				array( '198.18.0.0', '198.19.255.255' ),       // benchmark
				array( '224.0.0.0', '255.255.255.255' ),       // multicast / reserved
			);

			$ip_long = (int) sprintf( '%u', ip2long( $ip ) );
			foreach ( $ranges as $range ) {
				$start = (int) sprintf( '%u', ip2long( $range[0] ) );
				$end   = (int) sprintf( '%u', ip2long( $range[1] ) );
				if ( $ip_long >= $start && $ip_long <= $end ) {
					return true;
				}
			}
			return false;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// ::1 loopback
			if ( $ip === '::1' ) {
				return true;
			}
			// Unique local fc00::/7, link-local fe80::/10, multicast ff00::/8
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $bin || strlen( $bin ) !== 16 ) {
				return true;
			}
			$first = ord( $bin[0] );
			$second = ord( $bin[1] );
			if ( ( $first & 0xfe ) === 0xfc ) { // fc00::/7
				return true;
			}
			if ( $first === 0xfe && ( $second & 0xc0 ) === 0x80 ) { // fe80::/10
				return true;
			}
			if ( $first === 0xff ) { // multicast
				return true;
			}
			// Unspecified ::
			if ( $bin === str_repeat( "\0", 16 ) ) {
				return true;
			}
			return false;
		}

		return true;
	}

	/**
	 * @return list<string>
	 */
	private function resolve_ips( string $host ): array {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips = array();
		$a   = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $a ) ) {
			foreach ( $a as $ip ) {
				$ips[] = (string) $ip;
			}
		}

		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $row ) {
					if ( ! empty( $row['ipv6'] ) ) {
						$ips[] = (string) $row['ipv6'];
					}
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	private function absolutize_redirect( string $from, string $location ): string {
		$location = trim( $location );
		if ( preg_match( '#^https?://#i', $location ) ) {
			return esc_url_raw( $location );
		}
		$base = wp_parse_url( $from );
		if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return esc_url_raw( $location );
		}
		if ( str_starts_with( $location, '//' ) ) {
			return esc_url_raw( $base['scheme'] . ':' . $location );
		}
		$origin = $base['scheme'] . '://' . $base['host'];
		if ( ! empty( $base['port'] ) ) {
			$origin .= ':' . $base['port'];
		}
		if ( str_starts_with( $location, '/' ) ) {
			return esc_url_raw( $origin . $location );
		}
		$path = (string) ( $base['path'] ?? '/' );
		$dir  = rtrim( str_replace( '\\', '/', dirname( $path ) ), '/' );
		return esc_url_raw( $origin . $dir . '/' . $location );
	}

	private function remote_get_pinned( string $url, string $host, string $pin_ip, array $args ) {
		if ( $host === '' || $pin_ip === '' || ! function_exists( 'curl_init' ) ) {
			return wp_remote_get( $url, $args );
		}

		$parts = wp_parse_url( $url );
		$port  = ( is_array( $parts ) && strtolower( (string) ( $parts['scheme'] ?? '' ) ) === 'http' ) ? 80 : 443;
		$cb    = static function ( $handle ) use ( $host, $pin_ip, $port ): void {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( "{$host}:{$port}:{$pin_ip}" ) );
		};

		add_action( 'http_api_curl', $cb, 10, 1 );
		$response = wp_remote_get( $url, $args );
		remove_action( 'http_api_curl', $cb, 10 );

		return $response;
	}

	private function err( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status, 'code' => $code ) );
	}
}
