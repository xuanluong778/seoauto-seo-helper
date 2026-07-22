<?php
/**
 * Sitemap presence checker (site-level, once per run).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Sitemap_Checker implements Checker_Interface {

	public function id(): string {
		return 'sitemap';
	}

	/**
	 * Unused for per-object scans — use check_site().
	 */
	public function check( Object_Context $ctx ): array {
		return array();
	}

	/**
	 * @return list<Audit_Issue>
	 */
	public function check_site(): array {
		$candidates = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemap.xml' ),
		);

		foreach ( $candidates as $url ) {
			$code = $this->probe( $url );
			if ( $code >= 200 && $code < 400 ) {
				return array();
			}
		}

		return array(
			new Audit_Issue(
				Audit_Codes::SITEMAP_MISSING,
				Audit_Codes::SEVERITY_LOW,
				Audit_Codes::RISK_SENSITIVE,
				'site',
				0,
				'not_found',
				home_url( '/wp-sitemap.xml' ),
				__( 'Không tìm thấy sitemap XML công khai.', 'seoauto-seo-helper' ),
				Audit_Codes::STATUS_OPEN,
				array( 'checked' => $candidates )
			),
		);
	}

	private function probe( string $url ): int {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array( 'User-Agent' => 'SEOAuto-SEO-Helper-Audit/' . SEOAUTO_HELPER_VERSION ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return (int) wp_remote_retrieve_response_code( $response );
	}
}
