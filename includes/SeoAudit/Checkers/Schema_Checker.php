<?php
/**
 * Schema / rich snippet presence checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Schema_Checker implements Checker_Interface {

	public function id(): string {
		return 'schema';
	}

	public function check( Object_Context $ctx ): array {
		$schema = trim( (string) ( $ctx->seo['schema_type'] ?? '' ) );
		if ( $schema !== '' && strtolower( $schema ) !== 'none' && strtolower( $schema ) !== 'off' ) {
			return array();
		}

		$suggest = match ( $ctx->object_type ) {
			'product' => 'Product',
			'page'    => 'WebPage',
			default   => 'Article',
		};

		return array(
			new Audit_Issue(
				Audit_Codes::SCHEMA_MISSING,
				Audit_Codes::SEVERITY_LOW,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				$schema === '' ? 'none' : $schema,
				$suggest,
				__( 'Chưa thấy schema type trong SEO meta (plugin SEO có thể tự sinh).', 'seoauto-seo-helper' ),
				Audit_Codes::STATUS_OPEN,
				array( 'adapter' => $ctx->seo_adapter )
			),
		);
	}
}
