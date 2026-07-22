<?php
/**
 * SEO title length / presence checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Title_Checker implements Checker_Interface {

	public const MIN_LEN = 30;
	public const MAX_LEN = 60;

	public function id(): string {
		return 'title';
	}

	public function check( Object_Context $ctx ): array {
		$title = trim( (string) ( $ctx->seo['title'] ?? '' ) );
		if ( $title === '' ) {
			$title = trim( $ctx->title );
		}
		$len = mb_strlen( $title );

		if ( $title === '' || $len === 0 ) {
			return array(
				new Audit_Issue(
					Audit_Codes::TITLE_MISSING,
					Audit_Codes::SEVERITY_HIGH,
					Audit_Codes::RISK_SAFE,
					$ctx->object_type,
					$ctx->object_id,
					'',
					$ctx->title !== '' ? $ctx->title : __( 'Thêm SEO title 30–60 ký tự', 'seoauto-seo-helper' ),
					__( 'Thiếu SEO title.', 'seoauto-seo-helper' ),
					Audit_Codes::STATUS_OPEN,
					array( 'adapter' => $ctx->seo_adapter )
				),
			);
		}

		if ( $len < self::MIN_LEN ) {
			return array(
				new Audit_Issue(
					Audit_Codes::TITLE_TOO_SHORT,
					Audit_Codes::SEVERITY_MEDIUM,
					Audit_Codes::RISK_SAFE,
					$ctx->object_type,
					$ctx->object_id,
					$title,
					mb_substr( $title . ' — ' . get_bloginfo( 'name' ), 0, self::MAX_LEN ),
					sprintf(
						/* translators: %d: character count */
						__( 'SEO title quá ngắn (%d ký tự). Nên 30–60.', 'seoauto-seo-helper' ),
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
					Audit_Codes::TITLE_TOO_LONG,
					Audit_Codes::SEVERITY_LOW,
					Audit_Codes::RISK_SAFE,
					$ctx->object_type,
					$ctx->object_id,
					$title,
					mb_substr( $title, 0, self::MAX_LEN ),
					sprintf(
						/* translators: %d: character count */
						__( 'SEO title quá dài (%d ký tự). Nên ≤ 60.', 'seoauto-seo-helper' ),
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
