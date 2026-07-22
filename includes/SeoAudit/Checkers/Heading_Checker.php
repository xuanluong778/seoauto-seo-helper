<?php
/**
 * H1 / heading hierarchy checker.
 *
 * Theme often renders the post title as H1 outside post_content — avoid false positives.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Heading_Checker implements Checker_Interface {

	public function id(): string {
		return 'heading';
	}

	public function check( Object_Context $ctx ): array {
		$issues = array();
		$h1s    = array_values(
			array_filter(
				$ctx->headings,
				static fn( array $h ): bool => ( $h['tag'] ?? '' ) === 'h1'
			)
		);

		$theme_likely_owns_h1 = $this->theme_likely_owns_h1( $ctx );

		if ( count( $h1s ) === 0 ) {
			if ( $theme_likely_owns_h1 ) {
				// Do not flag H1_MISSING — theme title is almost certainly the page H1.
			} elseif ( $ctx->word_count > 50 ) {
				$issues[] = new Audit_Issue(
					Audit_Codes::H1_MISSING,
					Audit_Codes::SEVERITY_MEDIUM,
					Audit_Codes::RISK_SENSITIVE,
					$ctx->object_type,
					$ctx->object_id,
					'0',
					$ctx->title !== '' ? $ctx->title : __( 'Thêm đúng 1 thẻ H1', 'seoauto-seo-helper' ),
					__( 'Không thấy H1 và thiếu tiêu đề — kiểm tra cấu trúc heading.', 'seoauto-seo-helper' ),
					Audit_Codes::STATUS_OPEN,
					array(
						'heading_count'        => count( $ctx->headings ),
						'theme_likely_owns_h1' => false,
					)
				);
			}
		} elseif ( count( $h1s ) > 1 ) {
			$issues[] = new Audit_Issue(
				Audit_Codes::H1_MULTIPLE,
				Audit_Codes::SEVERITY_MEDIUM,
				Audit_Codes::RISK_SENSITIVE,
				$ctx->object_type,
				$ctx->object_id,
				(string) count( $h1s ),
				'1',
				__( 'Có nhiều hơn một thẻ H1 trong nội dung.', 'seoauto-seo-helper' ),
				Audit_Codes::STATUS_OPEN,
				array( 'h1_count' => count( $h1s ) )
			);
		}

		// Hierarchy: if theme owns H1, treat content as starting after h1.
		$prev = ( count( $h1s ) === 0 && $theme_likely_owns_h1 ) ? 1 : 0;
		foreach ( $ctx->headings as $h ) {
			$level = (int) substr( (string) ( $h['tag'] ?? 'h0' ), 1 );
			if ( $prev > 0 && $level > $prev + 1 ) {
				$issues[] = new Audit_Issue(
					Audit_Codes::HEADING_SKIP,
					Audit_Codes::SEVERITY_LOW,
					Audit_Codes::RISK_SENSITIVE,
					$ctx->object_type,
					$ctx->object_id,
					'h' . $prev . ' → h' . $level,
					'h' . ( $prev + 1 ),
					__( 'Thứ bậc heading bị nhảy cấp.', 'seoauto-seo-helper' ),
					Audit_Codes::STATUS_OPEN,
					array(
						'from'                 => $prev,
						'to'                   => $level,
						'theme_likely_owns_h1' => $theme_likely_owns_h1,
					)
				);
				break;
			}
			$prev = $level;
		}

		return $issues;
	}

	/**
	 * Most themes output <h1><?php the_title(); ?></h1> outside the editor HTML.
	 */
	private function theme_likely_owns_h1( Object_Context $ctx ): bool {
		if ( trim( $ctx->title ) === '' ) {
			return false;
		}
		/**
		 * Allow themes/plugins to force H1-in-content expectation.
		 *
		 * @param bool           $theme_owns Default true when post title exists.
		 * @param Object_Context $ctx
		 */
		return (bool) apply_filters( 'seoauto_helper_theme_owns_h1', true, $ctx );
	}
}
