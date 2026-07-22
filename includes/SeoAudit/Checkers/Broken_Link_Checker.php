<?php
/**
 * Broken link (404) checker — rate-limited HEAD, max links per object.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Broken_Link_Checker implements Checker_Interface {

	public const MAX_CHECKS = 5;
	public const TIMEOUT    = 4;

	public function id(): string {
		return 'broken_link';
	}

	public function check( Object_Context $ctx ): array {
		$broken = array();
		$checked = 0;
		foreach ( $ctx->links as $url ) {
			if ( $checked >= self::MAX_CHECKS ) {
				break;
			}
			$url = esc_url_raw( $url );
			if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			++$checked;
			$code = $this->probe( $url );
			if ( $code === 404 || $code === 410 ) {
				$broken[] = array(
					'url'  => $url,
					'code' => $code,
				);
			}
		}

		if ( $broken === array() ) {
			return array();
		}

		return array(
			new Audit_Issue(
				Audit_Codes::BROKEN_LINK,
				Audit_Codes::SEVERITY_HIGH,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				wp_json_encode( $broken ) ?: '',
				__( 'Sửa hoặc gỡ link 404', 'seoauto-seo-helper' ),
				sprintf(
					/* translators: %d: broken link count */
					__( 'Phát hiện %d link lỗi (404/410).', 'seoauto-seo-helper' ),
					count( $broken )
				),
				Audit_Codes::STATUS_OPEN,
				array(
					'broken'  => $broken,
					'checked' => $checked,
				)
			),
		);
	}

	private function probe( string $url ): int {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array( 'User-Agent' => 'SEOAuto-SEO-Helper-Audit/' . SEOAUTO_HELPER_VERSION ),
			)
		);

		$need_get = false;
		if ( is_wp_error( $response ) ) {
			$need_get = true;
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			// Many WAFs / CDNs block HEAD with 403/405/501 — fall back to GET.
			if ( in_array( $code, array( 0, 403, 405, 501 ), true ) ) {
				$need_get = true;
			} else {
				return $code;
			}
		}

		if ( $need_get ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 3,
					'sslverify'   => true,
					'headers'     => array(
						'User-Agent' => 'SEOAuto-SEO-Helper-Audit/' . SEOAUTO_HELPER_VERSION,
						'Range'      => 'bytes=0-0',
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return 0;
			}
			return (int) wp_remote_retrieve_response_code( $response );
		}

		return 0;
	}
}
