<?php
/**
 * HTTPS client for private update check (HMAC with existing connection).
 *
 * Never logs site_secret or signed package URLs.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Updater;

use SEOAuto\SEOHelper\Auth\Hmac_Signer;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use WP_Error;

final class Update_Client {

	public const CHECK_PATH = '/api/wordpress-plugin/updates/check';

	public function __construct( private Connection_Manager $connection ) {}

	/**
	 * @return Update_Response|WP_Error
	 */
	public function check( string $channel = 'stable' ): Update_Response|WP_Error {
		if ( ! $this->connection->has_credentials() ) {
			return new WP_Error(
				'seoauto_update_not_paired',
				__( 'Chưa ghép nối SEOAuto — không kiểm tra được cập nhật riêng tư.', 'seoauto-seo-helper' )
			);
		}

		$secret = $this->connection->site_secret();
		if ( $secret === '' ) {
			return new WP_Error( 'seoauto_update_secret', __( 'Không giải mã được site_secret.', 'seoauto-seo-helper' ) );
		}

		$base = $this->connection->assert_https_api_base( $this->connection->api_base() );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$body_arr = array(
			'plugin'           => 'seoauto-seo-helper',
			'current_version'  => SEOAUTO_HELPER_VERSION,
			'channel'          => sanitize_key( $channel ),
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => PHP_VERSION,
			'site_url'         => home_url( '/' ),
		);
		$body = wp_json_encode( $body_arr );
		if ( ! is_string( $body ) ) {
			$body = '{}';
		}

		$path    = Hmac_Signer::normalize_path( self::CHECK_PATH );
		$headers = Hmac_Signer::build_headers(
			$this->connection->site_id(),
			(int) $this->connection->option( 'connection_id', 0 ),
			$secret,
			'POST',
			$path,
			$body
		);
		$secret = '';

		$url      = untrailingslashit( (string) $base ) . self::CHECK_PATH;
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => $headers,
				'body'      => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'seoauto_update_network',
				__( 'Không kết nối được máy chủ cập nhật SEOAuto.', 'seoauto-seo-helper' ),
				array( 'status' => 503 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'seoauto_update_invalid', __( 'Phản hồi cập nhật không hợp lệ.', 'seoauto-seo-helper' ) );
		}

		if ( $code >= 400 ) {
			$msg = (string) ( $data['detail']['message'] ?? $data['message'] ?? __( 'Kiểm tra cập nhật thất bại.', 'seoauto-seo-helper' ) );
			return new WP_Error( 'seoauto_update_http', $msg, array( 'status' => $code ) );
		}

		return Update_Response::from_array( $data );
	}
}
