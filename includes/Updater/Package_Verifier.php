<?php
/**
 * Verify private update packages (HTTPS, host, version, SHA-256, signature).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Updater;

use WP_Error;

final class Package_Verifier {

	/** @var list<string> */
	private const ALLOWED_HOSTS = array(
		'seoauto.vn',
		'www.seoauto.vn',
		'cdn.seoauto.vn',
		'downloads.seoauto.vn',
		'staging.seoauto.vn',
		'seoauto-api-staging.siteauto.vn',
	);

	/**
	 * @return true|WP_Error
	 */
	public function assert_safe_package_url( string $url ): bool|WP_Error {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return new WP_Error( 'seoauto_update_url', __( 'URL gói cập nhật trống.', 'seoauto-seo-helper' ) );
		}
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( $scheme !== 'https' ) {
			return new WP_Error( 'seoauto_update_https', __( 'URL gói cập nhật phải dùng HTTPS.', 'seoauto-seo-helper' ) );
		}
		$allowed = apply_filters( 'seoauto_helper_update_allowed_hosts', self::ALLOWED_HOSTS );
		if ( ! is_array( $allowed ) || ! in_array( $host, array_map( 'strtolower', $allowed ), true ) ) {
			return new WP_Error( 'seoauto_update_host', __( 'Hostname gói cập nhật không được phép.', 'seoauto-seo-helper' ) );
		}
		return true;
	}

	/**
	 * Anti-downgrade / replay: only accept strictly newer versions.
	 *
	 * @return true|WP_Error
	 */
	public function assert_newer_version( string $current, string $incoming ): bool|WP_Error {
		$current  = $this->normalize_version( $current );
		$incoming = $this->normalize_version( $incoming );
		if ( $incoming === '' ) {
			return new WP_Error( 'seoauto_update_version', __( 'Thiếu version cập nhật.', 'seoauto-seo-helper' ) );
		}
		if ( version_compare( $incoming, $current, '<=' ) ) {
			return new WP_Error(
				'seoauto_update_downgrade',
				__( 'Từ chối downgrade / replay version.', 'seoauto-seo-helper' )
			);
		}
		return true;
	}

	public function normalize_version( string $version ): string {
		$version = trim( $version );
		// Strip leading "v".
		if ( str_starts_with( strtolower( $version ), 'v' ) && preg_match( '/^v\d/i', $version ) ) {
			$version = substr( $version, 1 );
		}
		return $version;
	}

	/**
	 * @return true|WP_Error
	 */
	public function assert_sha256_file( string $file_path, string $expected_hex ): bool|WP_Error {
		$expected_hex = strtolower( trim( $expected_hex ) );
		if ( $expected_hex === '' || ! preg_match( '/^[a-f0-9]{64}$/', $expected_hex ) ) {
			return new WP_Error( 'seoauto_update_sha', __( 'Thiếu hoặc sai định dạng SHA-256.', 'seoauto-seo-helper' ) );
		}
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'seoauto_update_file', __( 'Không đọc được file gói cập nhật.', 'seoauto-seo-helper' ) );
		}
		$actual = hash_file( 'sha256', $file_path );
		if ( ! is_string( $actual ) || ! hash_equals( $expected_hex, strtolower( $actual ) ) ) {
			return new WP_Error( 'seoauto_update_checksum', __( 'Checksum SHA-256 không khớp.', 'seoauto-seo-helper' ) );
		}
		return true;
	}

	/**
	 * Verify release signature: HMAC-SHA256(site_secret, version|sha256|expires_at).
	 *
	 * @return true|WP_Error
	 */
	public function assert_release_signature(
		string $signature,
		string $site_secret,
		string $version,
		string $sha256,
		string $expires_at = ''
	): bool|WP_Error {
		$signature = strtolower( trim( $signature ) );
		if ( $signature === '' ) {
			return new WP_Error( 'seoauto_update_sig_missing', __( 'Thiếu chữ ký release.', 'seoauto-seo-helper' ) );
		}
		if ( $site_secret === '' ) {
			return new WP_Error( 'seoauto_update_secret', __( 'Thiếu site_secret để xác minh chữ ký.', 'seoauto-seo-helper' ) );
		}
		$payload  = $version . '|' . strtolower( $sha256 ) . '|' . $expires_at;
		$expected = hash_hmac( 'sha256', $payload, $site_secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'seoauto_update_sig', __( 'Chữ ký release không hợp lệ.', 'seoauto-seo-helper' ) );
		}
		if ( $expires_at !== '' ) {
			$ts = strtotime( $expires_at );
			if ( false !== $ts && time() > $ts ) {
				return new WP_Error( 'seoauto_update_expired', __( 'URL tải đã hết hạn.', 'seoauto-seo-helper' ) );
			}
		}
		return true;
	}

	/**
	 * Ensure ZIP contains single root folder seoauto-seo-helper/.
	 *
	 * @return true|WP_Error
	 */
	public function assert_zip_structure( string $file_path ): bool|WP_Error {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return true; // Skip if ZipArchive unavailable; WP will still extract.
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'seoauto_update_zip', __( 'Không mở được file ZIP.', 'seoauto-seo-helper' ) );
		}
		$ok      = false;
		$bad     = false;
		$roots   = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( $name === 'seoauto-seo-helper/seoauto-seo-helper.php' ) {
				$ok = true;
			}
			if ( str_starts_with( $name, 'seoauto-seo-helper/seoauto-seo-helper/' ) ) {
				$bad = true;
			}
			$parts = explode( '/', $name );
			if ( isset( $parts[0] ) && $parts[0] !== '' ) {
				$roots[ $parts[0] ] = true;
			}
		}
		$zip->close();
		if ( $bad || count( $roots ) !== 1 || ! isset( $roots['seoauto-seo-helper'] ) ) {
			return new WP_Error( 'seoauto_update_zip_structure', __( 'Cấu trúc ZIP không hợp lệ (cần thư mục gốc seoauto-seo-helper/).', 'seoauto-seo-helper' ) );
		}
		if ( ! $ok ) {
			return new WP_Error( 'seoauto_update_zip_main', __( 'ZIP thiếu seoauto-seo-helper.php.', 'seoauto-seo-helper' ) );
		}
		return true;
	}
}
