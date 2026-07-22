<?php
/**
 * Internal link density (informational; no AI insert in Phase 1).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Internal_Link_Checker implements Checker_Interface {

	public function id(): string {
		return 'internal_link';
	}

	public function check( Object_Context $ctx ): array {
		if ( $ctx->word_count < 200 ) {
			return array();
		}

		$host     = strtolower( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' ) );
		$internal = 0;
		foreach ( $ctx->links as $url ) {
			$h = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?? '' ) );
			if ( $h === '' || $h === $host ) {
				++$internal;
			}
		}

		if ( $internal > 0 ) {
			return array();
		}

		return array(
			new Audit_Issue(
				Audit_Codes::INTERNAL_LINK_THIN,
				Audit_Codes::SEVERITY_LOW,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				'0',
				__( 'Thêm 1–3 internal link liên quan (Phase 4)', 'seoauto-seo-helper' ),
				__( 'Bài dài nhưng chưa có internal link.', 'seoauto-seo-helper' ),
				Audit_Codes::STATUS_OPEN,
				array( 'word_count' => $ctx->word_count, 'total_links' => count( $ctx->links ) )
			),
		);
	}
}
