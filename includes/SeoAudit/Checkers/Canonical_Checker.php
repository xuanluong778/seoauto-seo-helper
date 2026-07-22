<?php
/**
 * Canonical URL checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Canonical_Checker implements Checker_Interface {

	public function id(): string {
		return 'canonical';
	}

	public function check( Object_Context $ctx ): array {
		$canonical = trim( (string) ( $ctx->seo['canonical'] ?? '' ) );
		$permalink = untrailingslashit( $ctx->permalink );
		$issues    = array();

		if ( $canonical === '' ) {
			$issues[] = new Audit_Issue(
				Audit_Codes::CANONICAL_MISSING,
				Audit_Codes::SEVERITY_MEDIUM,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				'',
				$ctx->permalink,
				__( 'Thiếu canonical URL rõ ràng trong SEO meta.', 'seoauto-seo-helper' ),
				Audit_Codes::STATUS_OPEN,
				array( 'adapter' => $ctx->seo_adapter )
			);
			return $issues;
		}

		$canon_norm = untrailingslashit( esc_url_raw( $canonical ) );
		if ( $canon_norm !== '' && $permalink !== '' && strcasecmp( $canon_norm, $permalink ) !== 0 ) {
			// Cross-domain or alternate URL may be intentional — flag medium/sensitive.
			$issues[] = new Audit_Issue(
				Audit_Codes::CANONICAL_MISMATCH,
				Audit_Codes::SEVERITY_MEDIUM,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				$canonical,
				$ctx->permalink,
				__( 'Canonical khác permalink — xác nhận trước khi sửa.', 'seoauto-seo-helper' ),
				Audit_Codes::STATUS_OPEN,
				array(
					'adapter'   => $ctx->seo_adapter,
					'permalink' => $ctx->permalink,
				)
			);
		}

		return $issues;
	}
}
