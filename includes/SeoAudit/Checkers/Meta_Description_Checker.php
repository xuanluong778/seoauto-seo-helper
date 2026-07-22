<?php
/**
 * Meta description checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Meta_Description_Checker implements Checker_Interface {

	public const MIN_LEN = 70;
	public const MAX_LEN = 160;

	public function id(): string {
		return 'meta_description';
	}

	public function check( Object_Context $ctx ): array {
		$desc = trim( (string) ( $ctx->seo['description'] ?? '' ) );
		$len  = mb_strlen( $desc );

		if ( $desc === '' ) {
			$suggest = mb_substr( $ctx->content_text, 0, self::MAX_LEN );
			return array(
				new Audit_Issue(
					Audit_Codes::DESC_MISSING,
					Audit_Codes::SEVERITY_HIGH,
					Audit_Codes::RISK_SAFE,
					$ctx->object_type,
					$ctx->object_id,
					'',
					$suggest,
					__( 'Thiếu meta description.', 'seoauto-seo-helper' ),
					Audit_Codes::STATUS_OPEN,
					array( 'adapter' => $ctx->seo_adapter )
				),
			);
		}

		if ( $len < self::MIN_LEN ) {
			return array(
				new Audit_Issue(
					Audit_Codes::DESC_TOO_SHORT,
					Audit_Codes::SEVERITY_MEDIUM,
					Audit_Codes::RISK_SAFE,
					$ctx->object_type,
					$ctx->object_id,
					$desc,
					mb_substr( $desc . ' ' . $ctx->content_text, 0, self::MAX_LEN ),
					sprintf(
						/* translators: %d: character count */
						__( 'Meta description quá ngắn (%d). Nên 70–160.', 'seoauto-seo-helper' ),
						$len
					),
					Audit_Codes::STATUS_OPEN,
					array( 'length' => $len, 'adapter' => $ctx->seo_adapter )
				),
			);
		}

		if ( $len > self::MAX_LEN ) {
			return array(
				new Audit_Issue(
					Audit_Codes::DESC_TOO_LONG,
					Audit_Codes::SEVERITY_LOW,
					Audit_Codes::RISK_SAFE,
					$ctx->object_type,
					$ctx->object_id,
					$desc,
					mb_substr( $desc, 0, self::MAX_LEN ),
					sprintf(
						/* translators: %d: character count */
						__( 'Meta description quá dài (%d). Nên ≤ 160.', 'seoauto-seo-helper' ),
						$len
					),
					Audit_Codes::STATUS_OPEN,
					array( 'length' => $len, 'adapter' => $ctx->seo_adapter )
				),
			);
		}

		return array();
	}
}
