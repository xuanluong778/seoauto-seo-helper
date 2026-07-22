<?php
/**
 * Thin content checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Thin_Content_Checker implements Checker_Interface {

	public const HIGH_THRESHOLD   = 150;
	public const MEDIUM_THRESHOLD = 300;

	public function id(): string {
		return 'thin_content';
	}

	public function check( Object_Context $ctx ): array {
		if ( $ctx->object_type === 'page' && $ctx->word_count >= 50 ) {
			// Landing pages can be shorter — only flag very thin.
			if ( $ctx->word_count >= self::HIGH_THRESHOLD ) {
				return array();
			}
		}

		if ( $ctx->word_count >= self::MEDIUM_THRESHOLD ) {
			return array();
		}

		$severity = $ctx->word_count < self::HIGH_THRESHOLD
			? Audit_Codes::SEVERITY_HIGH
			: Audit_Codes::SEVERITY_MEDIUM;

		return array(
			new Audit_Issue(
				Audit_Codes::THIN_CONTENT,
				$severity,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				(string) $ctx->word_count,
				(string) self::MEDIUM_THRESHOLD . '+',
				sprintf(
					/* translators: %d: word count */
					__( 'Nội dung mỏng (%d từ). Nên ≥ 300 từ.', 'seoauto-seo-helper' ),
					$ctx->word_count
				),
				Audit_Codes::STATUS_OPEN,
				array( 'word_count' => $ctx->word_count )
			),
		);
	}
}
