<?php
/**
 * Featured image checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Featured_Image_Checker implements Checker_Interface {

	public function id(): string {
		return 'featured_image';
	}

	public function check( Object_Context $ctx ): array {
		if ( $ctx->has_featured_image ) {
			return array();
		}
		if ( ! in_array( $ctx->post_status, array( 'publish', 'future', 'private' ), true ) ) {
			return array();
		}

		return array(
			new Audit_Issue(
				Audit_Codes::FEATURED_MISSING,
				Audit_Codes::SEVERITY_HIGH,
				Audit_Codes::RISK_SAFE,
				$ctx->object_type,
				$ctx->object_id,
				'none',
				__( 'Gán ảnh đại diện phù hợp chủ đề', 'seoauto-seo-helper' ),
				__( 'Thiếu ảnh đại diện (featured image).', 'seoauto-seo-helper' )
			),
		);
	}
}
