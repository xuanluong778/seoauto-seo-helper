<?php
/**
 * Robots / noindex checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Robots_Checker implements Checker_Interface {

	public function id(): string {
		return 'robots';
	}

	public function check( Object_Context $ctx ): array {
		$index = $ctx->seo['robots_index'] ?? null;
		if ( null === $index ) {
			return array();
		}
		if ( true === $index ) {
			return array();
		}
		if ( $ctx->post_status !== 'publish' ) {
			return array();
		}

		return array(
			new Audit_Issue(
				Audit_Codes::ROBOTS_NOINDEX,
				Audit_Codes::SEVERITY_CRITICAL,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				'noindex',
				'index',
				__( 'Nội dung publish đang noindex — xác nhận trước khi đổi.', 'seoauto-seo-helper' ),
				Audit_Codes::STATUS_OPEN,
				array( 'adapter' => $ctx->seo_adapter )
			),
		);
	}
}
