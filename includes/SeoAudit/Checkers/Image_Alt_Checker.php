<?php
/**
 * Image ALT checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Image_Alt_Checker implements Checker_Interface {

	public function id(): string {
		return 'image_alt';
	}

	public function check( Object_Context $ctx ): array {
		$missing = array();
		foreach ( $ctx->images as $img ) {
			$alt = trim( (string) ( $img['alt'] ?? '' ) );
			if ( $alt === '' ) {
				$missing[] = (string) ( $img['src'] ?? '' );
			}
		}
		if ( $missing === array() ) {
			return array();
		}

		$suggest = $ctx->title !== '' ? $ctx->title : __( 'Mô tả ngắn cho ảnh', 'seoauto-seo-helper' );
		return array(
			new Audit_Issue(
				Audit_Codes::IMAGE_ALT_MISSING,
				Audit_Codes::SEVERITY_MEDIUM,
				Audit_Codes::RISK_SAFE,
				$ctx->object_type,
				$ctx->object_id,
				(string) count( $missing ) . ' images',
				$suggest,
				sprintf(
					/* translators: %d: number of images */
					__( '%d ảnh thiếu thuộc tính ALT.', 'seoauto-seo-helper' ),
					count( $missing )
				),
				Audit_Codes::STATUS_OPEN,
				array(
					'count'   => count( $missing ),
					'samples' => array_slice( $missing, 0, 5 ),
				)
			),
		);
	}
}
